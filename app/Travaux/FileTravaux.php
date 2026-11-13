<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;

/**
 * File d'attente des travaux (V2, lot 1, cahier des charges « Architecture technique »).
 *
 * Côté web, le portail inscrit seulement une demande : une ligne par travail,
 * une étape par étudiant (ou une seule étape « serveur »). Le travailleur
 * (bin/travaux.php) les exécute ensuite une à une. Rien ici ne touche le serveur.
 */
final class FileTravaux
{
    /** Types de travaux et libellés affichés. Liste fermée, identique à celle de la base. */
    public const LIBELLES = [
        'releve' => 'Relevé des sous-domaines et des sites',
        'creer_prestashop' => 'Créer une boutique PrestaShop',
        'deployer_sujet_wp' => 'Déployer un sujet WordPress',
        'creer_wordpress' => 'Créer un WordPress',
        'reinitialiser_wordpress' => 'Réinitialiser un WordPress',
        'reinitialiser_prestashop' => 'Réinitialiser une boutique PrestaShop',
        'supprimer_site' => 'Supprimer un site',
        'supprimer_etudiant' => 'Supprimer un étudiant',
        'restaurer' => 'Restaurer une sauvegarde',
        'deployer_sujet_prestashop' => 'Déployer un sujet PrestaShop',
        'installer_sujet_modele' => 'Installer un sujet sur le site modèle',
        'geler_sujet_modele' => 'Geler ou dégeler le site modèle',
        'cloturer_epreuve' => 'Clôturer une épreuve',
        'lever_gel_epreuve' => 'Lever le gel d\'une épreuve',
    ];

    public const ETATS = [
        'en_attente' => 'En attente', 'en_cours' => 'En cours', 'termine' => 'Terminé', 'annule' => 'Annulé',
        'reussi' => 'Réussi', 'echec' => 'Échec', 'ignore' => 'Ignoré',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Inscrit un travail. Les étapes sont traitées dans l'ordre donné.
     *
     * @param array<string, string|int|bool|null> $parametres
     * @param list<array{cible: string, libelle: string}> $etapes
     */
    public function demander(string $type, array $parametres, array $etapes, Compte $auteur, ?string $adresseIp): int
    {
        if (!isset(self::LIBELLES[$type])) {
            throw new ErreurSaisie('Type de travail inconnu.');
        }
        if ($etapes === []) {
            throw new ErreurSaisie('Aucun étudiant choisi : rien à faire.');
        }
        $json = json_encode($parametres, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO travaux (type, parametres, demande_par, demande_par_nom) VALUES (:t, :p, :c, :n)')
                ->execute([':t' => $type, ':p' => $json, ':c' => $auteur->id, ':n' => $auteur->nomAffiche]);
            $id = (int) $this->pdo->lastInsertId();
            $ajout = $this->pdo->prepare('INSERT INTO travaux_etapes (travail_id, rang, cible, libelle) VALUES (:t, :r, :c, :l)');
            foreach ($etapes as $rang => $etape) {
                $ajout->execute([':t' => $id, ':r' => $rang + 1, ':c' => $etape['cible'], ':l' => $etape['libelle']]);
            }
            (new Journal($this->pdo))->ecrire('site', 'travail demandé', 'succes', $auteur->id, $auteur->nomAffiche, $adresseIp,
                'travail n° ' . $id, self::LIBELLES[$type] . ' : ' . count($etapes) . ' étape(s)');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    /** Numéro d'un travail du même type qui n'est pas encore terminé, ou null. */
    public function dejaPrevu(string $type): ?int
    {
        $requete = $this->pdo->prepare("SELECT id FROM travaux WHERE type = :t AND etat IN ('en_attente', 'en_cours') ORDER BY id LIMIT 1");
        $requete->execute([':t' => $type]);

        return Lecture::entier($requete->fetchColumn());
    }

    /**
     * Derniers travaux, les plus récents d'abord, avec leur avancement.
     *
     * @return list<array{id: int, type: string, etat: string, demande_le: string, demande_par_nom: string, total: int, faits: int, echecs: int}>
     */
    public function lister(int $limite = 50): array
    {
        $requete = $this->pdo->prepare(
            "SELECT t.id, t.type, t.etat, t.demande_le, t.demande_par_nom,
                    count(e.id) AS total,
                    sum(e.etat IN ('reussi', 'echec', 'ignore', 'annule')) AS faits,
                    sum(e.etat = 'echec') AS echecs
             FROM travaux t LEFT JOIN travaux_etapes e ON e.travail_id = t.id
             GROUP BY t.id ORDER BY t.id DESC LIMIT :l"
        );
        $requete->bindValue(':l', $limite, PDO::PARAM_INT);
        $requete->execute();
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $liste[] = [
                'id' => (int) Lecture::entier($ligne['id']),
                'type' => (string) Lecture::texte($ligne['type']),
                'etat' => (string) Lecture::texte($ligne['etat']),
                'demande_le' => (string) Lecture::texte($ligne['demande_le']),
                'demande_par_nom' => (string) Lecture::texte($ligne['demande_par_nom']),
                'total' => (int) Lecture::entier($ligne['total']),
                'faits' => (int) Lecture::entier($ligne['faits']),
                'echecs' => (int) Lecture::entier($ligne['echecs']),
            ];
        }

        return $liste;
    }

    /**
     * @return array{id: int, type: string, etat: string, demande_le: string, demande_par: ?int, demande_par_nom: string,
     *               commence_le: ?string, termine_le: ?string,
     *               etapes: list<array{rang: int, libelle: string, etat: string, message: ?string, termine_le: ?string}>}|null
     */
    public function detail(int $id): ?array
    {
        $requete = $this->pdo->prepare('SELECT * FROM travaux WHERE id = :id');
        $requete->execute([':id' => $id]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            return null;
        }
        $etapes = $this->pdo->prepare('SELECT rang, libelle, etat, message, termine_le FROM travaux_etapes WHERE travail_id = :id ORDER BY rang');
        $etapes->execute([':id' => $id]);
        $liste = [];
        foreach (Lecture::lignes($etapes) as $etape) {
            $liste[] = [
                'rang' => (int) Lecture::entier($etape['rang']),
                'libelle' => (string) Lecture::texte($etape['libelle']),
                'etat' => (string) Lecture::texte($etape['etat']),
                'message' => Lecture::texte($etape['message']),
                'termine_le' => Lecture::texte($etape['termine_le']),
            ];
        }

        return [
            'id' => $id,
            'type' => (string) Lecture::texte($ligne['type']),
            'etat' => (string) Lecture::texte($ligne['etat']),
            'demande_le' => (string) Lecture::texte($ligne['demande_le']),
            'demande_par' => Lecture::entier($ligne['demande_par']),
            'demande_par_nom' => (string) Lecture::texte($ligne['demande_par_nom']),
            'commence_le' => Lecture::texte($ligne['commence_le']),
            'termine_le' => Lecture::texte($ligne['termine_le']),
            'etapes' => $liste,
        ];
    }

    /**
     * Le demandeur ou un administrateur peut arrêter un travail : les étapes pas encore commencées sont annulées.
     *
     */
    public function peutAnnuler(string $etat, ?int $demandePar, Compte $moi): bool
    {
        return in_array($etat, ['en_attente', 'en_cours'], true)
            && ($demandePar === $moi->id || Droits::peut($moi->role, Droits::TRAITER_DEMANDES));
    }

    /** @return int Nombre d'étapes annulées */
    public function annuler(int $id, Compte $moi, ?string $adresseIp): int
    {
        $travail = $this->detail($id);
        if ($travail === null || !$this->peutAnnuler($travail['etat'], $travail['demande_par'], $moi)) {
            throw new ErreurSaisie('Ce travail ne peut plus être arrêté.');
        }
        $this->pdo->beginTransaction();
        try {
            $maintenant = gmdate('Y-m-d\TH:i:s\Z');
            $requete = $this->pdo->prepare("UPDATE travaux_etapes SET etat = 'annule', termine_le = :m WHERE travail_id = :id AND etat = 'en_attente'");
            $requete->execute([':m' => $maintenant, ':id' => $id]);
            $annulees = $requete->rowCount();
            // Pas encore commencé : arrêté tout de suite. En cours : le travailleur finit l'étape commencée, puis s'arrête.
            $this->pdo->prepare("UPDATE travaux SET etat = 'annule', termine_le = :m WHERE id = :id AND etat = 'en_attente'")
                ->execute([':m' => $maintenant, ':id' => $id]);
            (new Journal($this->pdo))->ecrire('site', 'travail arrêté', 'succes', $moi->id, $moi->nomAffiche, $adresseIp,
                'travail n° ' . $id, $annulees . ' étape(s) annulée(s)');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $annulees;
    }

    /** Travaux pas encore terminés (pour rafraîchir la page). */
    public function actifs(): int
    {
        $resultat = $this->pdo->query("SELECT count(*) FROM travaux WHERE etat IN ('en_attente', 'en_cours')");

        return $resultat === false ? 0 : (int) Lecture::entier($resultat->fetchColumn());
    }
}
