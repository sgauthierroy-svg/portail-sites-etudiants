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
 * Demande du 18/11/2026 : « Faire une demande au service informatique » (formulaire de la page « Demandes »), liée à un
 * étudiant ou à une classe, pour une suppression ou un problème (SAV). Une suppression pour une classe devient une demande
 * par étudiant, traitées ensemble (un seul travail). Un problème est marqué « traité » par l'administrateur, avec sa réponse.
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
        'probleme' => 'Problème signalé (SAV)',
    ];
    /** Étiquette affichée sur la ligne de l'étudiant dans « Voir tout » et sur sa fiche. */
    public const ETIQUETTES = [
        'supprimer_site' => 'Suppression demandée',
        'supprimer_etudiant' => 'Suppression demandée',
        'reinitialiser_wordpress' => 'Réinitialisation demandée',
        'reinitialiser_prestashop' => 'Réinitialisation demandée',
        'probleme' => 'Problème signalé',
    ];
    public const ETATS = ['en_attente' => 'En attente', 'validee' => 'Validée', 'refusee' => 'Refusée', 'annulee' => 'Annulée'];
    /** Mot à taper pour valider des suppressions par lot. */
    public const MOT_CONFIRMATION = 'SUPPRIMER';
    /** Longueur d'une description de problème (SAV). */
    public const DESCRIPTION_MAX = 1000;

    public function __construct(private readonly PDO $pdo, private readonly ?Alertes $alertes = null)
    {
    }

    /** Choix proposé sur la fiche : « supprimer_site:/WP », « supprimer_etudiant », « reinitialiser_wordpress »… @return int Numéro de la demande */
    public function creer(string $choix, string $identifiant, string $motif, Compte $auteur, ?string $adresseIp, bool $prevenir = true): int
    {
        [$type, $dossier] = str_contains($choix, ':') ? explode(':', $choix, 2) + [1 => null] : [$choix, null];
        if (!isset(self::LIBELLES[$type]) || $type === 'probleme') {
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
        if ($prevenir) {
            $this->alertes?->nouvelleDemande(self::LIBELLES[$type], $auteur->nomAffiche, $this->nombreEnAttente());
        }

        return $id;
    }

    /**
     * Formulaire « Faire une demande au service informatique » (demande du 18/11/2026).
     *
     * @param array{objet: string, cible: string, etudiant: ?int, classe: string, site: string, texte: string} $saisie
     * @return string Compte rendu pour la page
     */
    public function demander(array $saisie, Compte $auteur, ?string $adresseIp): string
    {
        if (!in_array($saisie['objet'], ['suppression', 'probleme'], true)) {
            throw new ErreurSaisie('Choisissez l\'objet de la demande : une suppression ou un problème.');
        }
        if ($saisie['site'] !== '' && !in_array($saisie['site'], Suppressions::DOSSIERS, true)) {
            throw new ErreurSaisie('Site inconnu.');
        }
        $etudiant = null;
        $classe = null;
        if ($saisie['cible'] === 'etudiant') {
            $requete = $this->pdo->prepare('SELECT identifiant FROM etudiants WHERE id = :id');
            $requete->execute([':id' => $saisie['etudiant'] ?? 0]);
            $etudiant = Lecture::texte($requete->fetchColumn());
            if ($etudiant === null) {
                throw new ErreurSaisie('Choisissez l\'étudiant concerné.');
            }
        } elseif ($saisie['cible'] === 'classe') {
            $classe = self::lireClasse($saisie['classe']);
            if ($classe === null || !$this->classeExiste($classe[0], $classe[1])) {
                throw new ErreurSaisie('Choisissez la classe concernée.');
            }
        } elseif ($saisie['cible'] !== 'aucune' || $saisie['objet'] === 'suppression') {
            throw new ErreurSaisie('Une suppression concerne un étudiant ou une classe : choisissez-le.');
        }

        if ($saisie['objet'] === 'probleme') {
            return $this->signalerProbleme($etudiant, $classe, $saisie['site'] === '' ? null : $saisie['site'], $saisie['texte'], $auteur, $adresseIp);
        }
        $choix = $saisie['site'] === '' ? 'supprimer_etudiant' : 'supprimer_site:' . $saisie['site'];
        if ($etudiant !== null) {
            $this->creer($choix, $etudiant, $saisie['texte'], $auteur, $adresseIp);

            return 'Demande envoyée au service informatique. Vous êtes prévenu par e-mail de sa réponse.';
        }
        // Classe : une demande par étudiant, traitées ensemble par l'administrateur.
        $requete = $this->pdo->prepare('SELECT identifiant FROM etudiants WHERE regime_id = :r AND session_id = :s ORDER BY nom_complet');
        $requete->execute([':r' => $classe[0] ?? 0, ':s' => $classe[1] ?? 0]);
        $creees = 0;
        $passes = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            try {
                $this->creer($choix, (string) Lecture::texte($ligne['identifiant']), $saisie['texte'], $auteur, $adresseIp, false);
                $creees++;
            } catch (ErreurSaisie $e) {
                $passes[$e->getMessage()] = ($passes[$e->getMessage()] ?? 0) + 1;
            }
        }
        if ($creees === 0) {
            throw new ErreurSaisie('Aucune demande créée pour cette classe' . ($passes === [] ? ' : elle n\'a pas d\'étudiant.' : ' : '
                . implode(' ; ', array_map(static fn (string $m, int $n): string => $n . ' × ' . $m, array_keys($passes), array_values($passes)))));
        }
        $this->alertes?->nouvelleDemande(self::LIBELLES[$saisie['site'] === '' ? 'supprimer_etudiant' : 'supprimer_site'] . ' (classe, ' . $creees . ')',
            $auteur->nomAffiche, $this->nombreEnAttente());

        return $creees . ' demande(s) envoyée(s) pour la classe, une par étudiant'
            . ($passes === [] ? '' : ' ; passés : ' . implode(' ; ', array_map(static fn (string $m, int $n): string => $n . ' × ' . $m, array_keys($passes), array_values($passes))))
            . '. Vous êtes prévenu par e-mail de la réponse.';
    }

    /**
     * Classes qui ont au moins un étudiant (régime + session), pour le formulaire.
     *
     * @return list<array{valeur: string, libelle: string, nombre: int}>
     */
    public function classes(): array
    {
        $requete = $this->pdo->prepare("SELECT r.id AS regime, s.id AS session, r.nom || ' – session ' || s.annee AS libelle, count(*) AS nombre
            FROM etudiants e JOIN regimes r ON r.id = e.regime_id JOIN sessions s ON s.id = e.session_id
            GROUP BY r.id, s.id ORDER BY s.annee, r.nom");
        $requete->execute();
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $liste[] = ['valeur' => (int) Lecture::entier($ligne['regime']) . '-' . (int) Lecture::entier($ligne['session']),
                'libelle' => (string) Lecture::texte($ligne['libelle']), 'nombre' => (int) Lecture::entier($ligne['nombre'])];
        }

        return $liste;
    }

    /** @return array{int, int}|null Régime et session d'une valeur « 3-2 » */
    private static function lireClasse(string $valeur): ?array
    {
        return preg_match('/^([1-9][0-9]{0,9})-([1-9][0-9]{0,9})$/D', $valeur, $parties) === 1 ? [(int) $parties[1], (int) $parties[2]] : null;
    }

    private function classeExiste(int $regime, int $session): bool
    {
        $requete = $this->pdo->prepare('SELECT 1 FROM regimes r JOIN sessions s ON s.id = :s WHERE r.id = :r');
        $requete->execute([':r' => $regime, ':s' => $session]);

        return $requete->fetchColumn() !== false;
    }

    /** @param array{int, int}|null $classe */
    private function signalerProbleme(?string $etudiant, ?array $classe, ?string $dossier, string $texte, Compte $auteur, ?string $adresseIp): string
    {
        $texte = trim((string) preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $texte)));
        if (mb_strlen($texte, 'UTF-8') < 10 || mb_strlen($texte, 'UTF-8') > self::DESCRIPTION_MAX) {
            throw new ErreurSaisie('Décrivez le problème (10 à ' . self::DESCRIPTION_MAX . ' caractères) : ce qui se passe, sur quelle page, depuis quand.');
        }
        $this->pdo->prepare("INSERT INTO demandes (type, identifiant, regime_id, session_id, dossier, motif, demande_par, demande_par_nom)
            VALUES ('probleme', :i, :r, :s, :d, :m, :c, :n)")
            ->execute([':i' => $etudiant, ':r' => $classe[0] ?? null, ':s' => $classe[1] ?? null, ':d' => $dossier, ':m' => $texte,
                ':c' => $auteur->id, ':n' => $auteur->nomAffiche]);
        (new Journal($this->pdo))->ecrire('site', 'problème signalé au service informatique', 'succes', $auteur->id, $auteur->nomAffiche, $adresseIp,
            $etudiant ?? ($classe === null ? null : 'classe'), $dossier ?? '');
        $this->alertes?->nouvelleDemande(self::LIBELLES['probleme'], $auteur->nomAffiche, $this->nombreEnAttente());

        return 'Problème signalé au service informatique. Vous êtes prévenu par e-mail de sa réponse.';
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
    public function valider(array $ids, string $confirmation, Compte $auteur, ?string $adresseIp, int $maintenant, string $commentaire = ''): array
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
        // Problème (SAV) : l'administrateur le marque « traité », avec sa réponse facultative, transmise au demandeur.
        $reponse = trim((string) preg_replace('/\s+/u', ' ', $commentaire));
        $reponse = $reponse === '' ? null : mb_substr($reponse, 0, 300, 'UTF-8');
        foreach ($demandes as $rang => $demande) {
            if ($demande['type'] === 'probleme') {
                $this->marquer($demande['id'], 'validee', $auteur, $reponse, null);
                $resultat['validees']++;
                $decisions[$demande['email'] ?? ''][] = 'traitee';
                unset($demandes[$rang]);
            }
        }
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
        $this->prevenir($decisions, null, $reponse);

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
            FROM demandes d LEFT JOIN etudiants e ON e.identifiant = d.identifiant
            LEFT JOIN regimes r ON r.id = coalesce(e.regime_id, d.regime_id) LEFT JOIN sessions s ON s.id = coalesce(e.session_id, d.session_id)
            LEFT JOIN comptes c ON c.id = d.demande_par
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
        $requete = $this->pdo->prepare("SELECT d.*, e.nom_complet AS nom, r.nom || ' – session ' || s.annee AS classe
            FROM demandes d LEFT JOIN etudiants e ON e.identifiant = d.identifiant
            LEFT JOIN regimes r ON r.id = coalesce(e.regime_id, d.regime_id) LEFT JOIN sessions s ON s.id = coalesce(e.session_id, d.session_id)
            WHERE d.demande_par = :c ORDER BY d.id DESC LIMIT 100");
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
            if ($identifiant !== '' && $etiquette !== null && !in_array($etiquette, $liste[$identifiant] ?? [], true)) {
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
    private function prevenir(array $decisions, ?string $commentaire, ?string $reponse = null): void
    {
        foreach ($decisions as $email => $liste) {
            if ($email !== '') {
                $this->alertes?->decisionDemandes($email, count(array_filter($liste, static fn (string $d): bool => $d === 'validee')),
                    count(array_filter($liste, static fn (string $d): bool => $d === 'refusee')), $commentaire,
                    count(array_filter($liste, static fn (string $d): bool => $d === 'traitee')), $reponse);
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
