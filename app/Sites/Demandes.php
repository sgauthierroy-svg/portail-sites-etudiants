<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use PDOException;
use Portail\Alertes\Alertes;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;

/**
 * Demandes à l'administrateur (décision du 05/11/2026, lot 5, paquet B du 15/11/2026).
 *
 * L'enseignant et l'administratif demandent une suppression (un site ou l'étudiant) ou une réinitialisation, avec un motif
 * obligatoire. L'administrateur valide (une par une ou par lot : le travail habituel est alors inscrit) ou refuse avec un
 * commentaire. E-mails : à l'adresse d'alerte pour une nouvelle demande, au demandeur pour la décision — sans nom d'étudiant.
 *
 * @phpstan-type Demande array{id: int, type: string, identifiant: string, dossier: ?string, motif: string, demande_le: string, demande_par_nom: string, etat: string, traitee_le: ?string, traitee_par_nom: ?string, commentaire: ?string, travail_id: ?int, nom: ?string, classe: ?string, etudiant_id: ?int, email: ?string}
 * @psalm-type Demande = array{id: int, type: string, identifiant: string, dossier: ?string, motif: string, demande_le: string, demande_par_nom: string, etat: string, traitee_le: ?string, traitee_par_nom: ?string, commentaire: ?string, travail_id: ?int, nom: ?string, classe: ?string, etudiant_id: ?int, email: ?string}
 */
final class Demandes
{
    public const LIBELLES = [
        'supprimer_site' => 'Supprimer un site',
        'supprimer_etudiant' => 'Supprimer l\'étudiant',
        'reinitialiser_wordpress' => 'Réinitialiser le WordPress',
        'reinitialiser_prestashop' => 'Réinitialiser la boutique',
    ];
    /** Étiquette affichée sur la ligne de l'étudiant dans « Voir tout » et sur sa fiche. */
    public const ETIQUETTES = [
        'supprimer_site' => 'Suppression demandée',
        'supprimer_etudiant' => 'Suppression demandée',
        'reinitialiser_wordpress' => 'Réinitialisation demandée',
        'reinitialiser_prestashop' => 'Réinitialisation demandée',
    ];
    public const ETATS = ['en_attente' => 'En attente', 'validee' => 'Validée', 'refusee' => 'Refusée', 'annulee' => 'Annulée'];
    /** Mot à taper pour valider des suppressions par lot. */
    public const MOT_CONFIRMATION = 'SUPPRIMER';

    public function __construct(private readonly PDO $pdo, private readonly ?Alertes $alertes = null)
    {
    }

    /** Choix proposé sur la fiche : « supprimer_site:/WP », « supprimer_etudiant », « reinitialiser_wordpress »… @return int Numéro de la demande */
    public function creer(string $choix, string $identifiant, string $motif, Compte $auteur, ?string $adresseIp): int
    {
        [$type, $dossier] = str_contains($choix, ':') ? explode(':', $choix, 2) + [1 => null] : [$choix, null];
        if (!isset(self::LIBELLES[$type])) {
            throw new ErreurSaisie('Choisissez ce que vous demandez.');
        }
        $motif = trim((string) preg_replace('/\s+/u', ' ', $motif));
        if (mb_strlen($motif, 'UTF-8') < 3 || mb_strlen($motif, 'UTF-8') > 300) {
            throw new ErreurSaisie('Le motif est obligatoire (3 à 300 caractères) : départ, abandon, fin de formation, redoublement…');
        }
        $requete = $this->pdo->prepare('SELECT nom_complet FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);
        if (Lecture::texte($requete->fetchColumn()) === null) {
            throw new ErreurSaisie('Étudiant inconnu.');
        }
        $sites = array_column(Releve::sitesEtudiant($this->pdo, $identifiant), 'dossier');
        if ($type === 'supprimer_site') {
            if (!in_array($dossier, Suppressions::DOSSIERS, true) || !in_array($dossier, $sites, true)) {
                throw new ErreurSaisie('Ce site n\'existe pas chez cet étudiant.');
            }
        } else {
            $dossier = null;
            if (($type === 'reinitialiser_wordpress' && !in_array('/WP', $sites, true)) || ($type === 'reinitialiser_prestashop' && !in_array('/Presta', $sites, true))) {
                throw new ErreurSaisie('Ce site n\'existe pas chez cet étudiant : rien à réinitialiser.');
            }
        }
        try {
            $this->pdo->prepare('INSERT INTO demandes (type, identifiant, dossier, motif, demande_par, demande_par_nom) VALUES (:t, :i, :d, :m, :c, :n)')
                ->execute([':t' => $type, ':i' => $identifiant, ':d' => $dossier, ':m' => $motif, ':c' => $auteur->id, ':n' => $auteur->nomAffiche]);
        } catch (PDOException) {
            throw new ErreurSaisie('Une demande identique est déjà en attente pour cet étudiant.');
        }
        $id = (int) $this->pdo->lastInsertId();
        (new Journal($this->pdo))->ecrire('site', 'demande envoyée à l\'administrateur', 'succes', $auteur->id, $auteur->nomAffiche, $adresseIp, $identifiant,
            self::LIBELLES[$type] . ($dossier === null ? '' : ' ' . $dossier) . ' – motif : ' . $motif);
        $this->alertes?->nouvelleDemande(self::LIBELLES[$type], $auteur->nomAffiche, $this->nombreEnAttente());

        return $id;
    }

    /** Le demandeur retire sa demande tant qu'elle n'est pas traitée. */
    public function annuler(int $id, Compte $auteur, ?string $adresseIp): void
    {
        $requete = $this->pdo->prepare("UPDATE demandes SET etat = 'annulee', traitee_le = :m, traitee_par_nom = :n
            WHERE id = :id AND etat = 'en_attente' AND demande_par = :c");
        $requete->execute([':m' => gmdate('Y-m-d\TH:i:s\Z'), ':n' => $auteur->nomAffiche, ':id' => $id, ':c' => $auteur->id]);
        if ($requete->rowCount() !== 1) {
            throw new ErreurSaisie('Cette demande ne peut plus être annulée.');
        }
        (new Journal($this->pdo))->ecrire('site', 'demande annulée par son auteur', 'succes', $auteur->id, $auteur->nomAffiche, $adresseIp, 'demande n° ' . $id);
    }

    /**
     * Valide des demandes en attente : chacune inscrit le travail habituel. Une demande qui ne peut pas être lancée reste
     * en attente, avec la raison dans le compte rendu.
     *
     * @param list<int> $ids
     * @return array{validees: int, travaux: list<int>, erreurs: list<string>}
     */
    public function valider(array $ids, string $confirmation, Compte $auteur, ?string $adresseIp, int $maintenant): array
    {
        $demandes = $this->enAttenteParId($ids);
        if ($demandes === []) {
            throw new ErreurSaisie('Cochez au moins une demande en attente.');
        }
        $suppressions = array_filter($demandes, static fn (array $d): bool => str_starts_with($d['type'], 'supprimer_'));
        if ($suppressions !== [] && trim($confirmation) !== self::MOT_CONFIRMATION) {
            throw new ErreurSaisie('La sélection contient ' . count($suppressions) . ' suppression(s) : tapez ' . self::MOT_CONFIRMATION
                . ' en majuscules pour confirmer. Rien n\'a été validé.');
        }
        $resultat = ['validees' => 0, 'travaux' => [], 'erreurs' => []];
        $decisions = [];
        // Suppressions d'étudiants : un seul travail pour tout le lot (ex. une session entière en fin de 2e année).
        $lots = [];
        foreach ($demandes as $demande) {
            if ($demande['type'] === 'supprimer_etudiant') {
                $lots['etudiants'][] = $demande;
            } elseif ($demande['type'] === 'supprimer_site') {
                $lots['site' . ($demande['dossier'] ?? '')][] = $demande;
            } else {
                $lots['seule' . $demande['id']][] = $demande;
            }
        }
        $suppression = new Suppressions($this->pdo);
        $reinitialisation = new Reinitialisations($this->pdo);
        foreach ($lots as $lot) {
            $retenues = [];
            foreach ($lot as $demande) {
                try {
                    if (str_starts_with($demande['type'], 'reinitialiser_')) {
                        $travail = $reinitialisation->lancer($demande['type'], $demande['identifiant'], true, $auteur, $adresseIp, $maintenant);
                        $this->marquer($demande['id'], 'validee', $auteur, null, $travail);
                        $resultat['travaux'][] = $travail;
                        $resultat['validees']++;
                        $decisions[$demande['email'] ?? ''][] = 'validee';
                        continue;
                    }
                    $suppression->verifier($demande['type'] === 'supprimer_site' ? Suppressions::SITE : Suppressions::ETUDIANT,
                        $demande['identifiant'], $demande['dossier']);
                    $retenues[] = $demande;
                } catch (ErreurSaisie $e) {
                    $resultat['erreurs'][] = ($demande['nom'] ?? $demande['identifiant']) . ' : ' . $e->getMessage();
                }
            }
            if ($retenues === []) {
                continue;
            }
            $premiere = $retenues[0];
            $cibles = array_map(static fn (array $d): array => [$d['identifiant'], $d['dossier']], $retenues);
            $motifs = implode(' ; ', array_unique(array_map(static fn (array $d): string => $d['motif'], $retenues)));
            $travail = $suppression->lancer($premiere['type'] === 'supprimer_site' ? Suppressions::SITE : Suppressions::ETUDIANT, $cibles, $auteur, $adresseIp,
                'demande de ' . implode(', ', array_unique(array_map(static fn (array $d): string => $d['demande_par_nom'], $retenues))) . ' – ' . $motifs)[0];
            foreach ($retenues as $demande) {
                $this->marquer($demande['id'], 'validee', $auteur, null, $travail);
                $resultat['validees']++;
                $decisions[$demande['email'] ?? ''][] = 'validee';
            }
            $resultat['travaux'][] = $travail;
        }
        (new Journal($this->pdo))->ecrire('site', 'demandes validées', 'succes', $auteur->id, $auteur->nomAffiche, $adresseIp, null,
            $resultat['validees'] . ' validée(s), ' . count($resultat['erreurs']) . ' restée(s) en attente');
        $this->prevenir($decisions, null);

        return $resultat;
    }

    /** @param list<int> $ids @return int Nombre de demandes refusées */
    public function refuser(array $ids, string $commentaire, Compte $auteur, ?string $adresseIp): int
    {
        $commentaire = trim((string) preg_replace('/\s+/u', ' ', $commentaire));
        if (mb_strlen($commentaire, 'UTF-8') < 3 || mb_strlen($commentaire, 'UTF-8') > 300) {
            throw new ErreurSaisie('Un refus demande un commentaire (3 à 300 caractères), transmis au demandeur.');
        }
        $demandes = $this->enAttenteParId($ids);
        if ($demandes === []) {
            throw new ErreurSaisie('Cochez au moins une demande en attente.');
        }
        $decisions = [];
        foreach ($demandes as $demande) {
            $this->marquer($demande['id'], 'refusee', $auteur, $commentaire, null);
            $decisions[$demande['email'] ?? ''][] = 'refusee';
        }
        (new Journal($this->pdo))->ecrire('site', 'demandes refusées', 'succes', $auteur->id, $auteur->nomAffiche, $adresseIp, null,
            count($demandes) . ' refusée(s) – ' . $commentaire);
        $this->prevenir($decisions, $commentaire);

        return count($demandes);
    }

    public function nombreEnAttente(): int
    {
        $resultat = $this->pdo->query("SELECT count(*) FROM demandes WHERE etat = 'en_attente'");

        return $resultat === false ? 0 : (int) Lecture::entier($resultat->fetchColumn());
    }

    /**
     * Demandes en attente, groupées par type puis par date.
     *
     * @return list<Demande>
     */
    public function enAttente(): array
    {
        $requete = $this->pdo->prepare("SELECT d.*, e.id AS etudiant_id, e.nom_complet AS nom, r.nom || ' – session ' || s.annee AS classe, c.email
            FROM demandes d LEFT JOIN etudiants e ON e.identifiant = d.identifiant LEFT JOIN regimes r ON r.id = e.regime_id
            LEFT JOIN sessions s ON s.id = e.session_id LEFT JOIN comptes c ON c.id = d.demande_par
            WHERE d.etat = 'en_attente' ORDER BY d.type, d.demande_le, d.id");
        $requete->execute();

        return self::lire($requete);
    }

    /**
     * Demandes d'un compte (les 100 dernières).
     *
     * @return list<Demande>
     */
    public function de(int $compteId): array
    {
        $requete = $this->pdo->prepare('SELECT d.*, e.nom_complet AS nom FROM demandes d LEFT JOIN etudiants e ON e.identifiant = d.identifiant
            WHERE d.demande_par = :c ORDER BY d.id DESC LIMIT 100');
        $requete->execute([':c' => $compteId]);

        return self::lire($requete);
    }

    /** @return array<string, list<string>> identifiant => étiquettes des demandes en attente (« Suppression demandée »…) */
    public function etiquettes(): array
    {
        $liste = [];
        $resultat = $this->pdo->query("SELECT DISTINCT identifiant, type FROM demandes WHERE etat = 'en_attente'");
        foreach (Lecture::lignes($resultat) as $ligne) {
            $etiquette = self::ETIQUETTES[(string) Lecture::texte($ligne['type'])] ?? null;
            $identifiant = (string) Lecture::texte($ligne['identifiant']);
            if ($etiquette !== null && !in_array($etiquette, $liste[$identifiant] ?? [], true)) {
                $liste[$identifiant][] = $etiquette;
            }
        }

        return $liste;
    }

    /** @return list<Demande> */
    public function enAttentePour(string $identifiant): array
    {
        $requete = $this->pdo->prepare("SELECT * FROM demandes WHERE etat = 'en_attente' AND identifiant = :i ORDER BY id");
        $requete->execute([':i' => $identifiant]);

        return self::lire($requete);
    }

    /**
     * @param list<int> $ids
     * @return list<Demande>
     */
    private function enAttenteParId(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
        $liste = [];
        foreach ($this->enAttente() as $demande) {
            if (in_array($demande['id'], $ids, true)) {
                $liste[] = $demande;
            }
        }

        return $liste;
    }

    private function marquer(int $id, string $etat, Compte $auteur, ?string $commentaire, ?int $travail): void
    {
        $this->pdo->prepare('UPDATE demandes SET etat = :e, traitee_le = :m, traitee_par_nom = :n, commentaire = :c, travail_id = :t WHERE id = :id')
            ->execute([':e' => $etat, ':m' => gmdate('Y-m-d\TH:i:s\Z'), ':n' => $auteur->nomAffiche, ':c' => $commentaire, ':t' => $travail, ':id' => $id]);
    }

    /** @param array<string, list<string>> $decisions e-mail du demandeur => décisions */
    private function prevenir(array $decisions, ?string $commentaire): void
    {
        foreach ($decisions as $email => $liste) {
            if ($email !== '') {
                $this->alertes?->decisionDemandes($email, count(array_filter($liste, static fn (string $d): bool => $d === 'validee')),
                    count(array_filter($liste, static fn (string $d): bool => $d === 'refusee')), $commentaire);
            }
        }
    }

    /** @return list<Demande> */
    private static function lire(\PDOStatement $requete): array
    {
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $liste[] = [
                'id' => (int) Lecture::entier($ligne['id'] ?? null), 'type' => (string) Lecture::texte($ligne['type'] ?? null),
                'identifiant' => (string) Lecture::texte($ligne['identifiant'] ?? null), 'dossier' => Lecture::texte($ligne['dossier'] ?? null),
                'motif' => (string) Lecture::texte($ligne['motif'] ?? null), 'demande_le' => (string) Lecture::texte($ligne['demande_le'] ?? null),
                'demande_par_nom' => (string) Lecture::texte($ligne['demande_par_nom'] ?? null), 'etat' => (string) Lecture::texte($ligne['etat'] ?? null),
                'traitee_le' => Lecture::texte($ligne['traitee_le'] ?? null), 'traitee_par_nom' => Lecture::texte($ligne['traitee_par_nom'] ?? null),
                'commentaire' => Lecture::texte($ligne['commentaire'] ?? null), 'travail_id' => Lecture::entier($ligne['travail_id'] ?? null),
                'nom' => Lecture::texte($ligne['nom'] ?? null), 'classe' => Lecture::texte($ligne['classe'] ?? null),
                'etudiant_id' => Lecture::entier($ligne['etudiant_id'] ?? null), 'email' => Lecture::texte($ligne['email'] ?? null),
            ];
        }

        return $liste;
    }
}
