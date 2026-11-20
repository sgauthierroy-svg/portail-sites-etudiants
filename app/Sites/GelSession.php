<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Noyau\ErreurSaisie;
use Portail\Travaux\FileTravaux;

/**
 * Côté web du gel des WordPress /WP déjà en place d'une session (constat du 20/11/2026 : le gel de la session ne
 * s'appliquait qu'aux sites construits par le portail). Page « Régimes et sessions », administrateur seulement.
 * Rien n'est fait sur le serveur ici : la demande devient un travail, un étudiant par étape.
 */
final class GelSession
{
    public const TYPE = 'geler_wordpress';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * État de gel des /WP de chaque session, d'après le dernier relevé.
     *
     * @return array<int, array{total: int, geles: int}> identifiant de session => nombres de /WP
     */
    public function etat(): array
    {
        $resultat = $this->pdo->query(
            "SELECT e.session_id, count(*) AS total, sum(r.gel_wordpress IN ('total', 'correctifs')) AS geles
             FROM releve_sites r JOIN etudiants e ON e.identifiant = r.identifiant
             WHERE r.dossier = '/WP' AND r.logiciel = 'wordpress'
             GROUP BY e.session_id"
        );
        $etat = [];
        foreach (Lecture::lignes($resultat) as $ligne) {
            $session = Lecture::entier($ligne['session_id']);
            if ($session !== null) {
                $etat[$session] = ['total' => Lecture::entier($ligne['total']) ?? 0,
                    'geles' => Lecture::entier($ligne['geles']) ?? 0];
            }
        }

        return $etat;
    }

    /** Inscrit le gel (geler = true) ou le dégel des /WP relevés de la session. */
    public function demander(int $sessionId, bool $geler, Compte $auteur, ?string $adresseIp): int
    {
        $session = $this->pdo->prepare('SELECT annee, version_wp_gelee, active FROM sessions WHERE id = :id');
        $session->execute([':id' => $sessionId]);
        $ligne = $session->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ligne) || Lecture::entier($ligne['active']) !== 1) {
            throw new ErreurSaisie('Session inconnue ou archivée.');
        }
        $annee = (string) Lecture::entier($ligne['annee']);
        if ($geler && Lecture::texte($ligne['version_wp_gelee']) === null) {
            throw new ErreurSaisie('Inscrivez d\'abord la version WordPress de la session ' . $annee
                . ', puis gelez ses sites.');
        }
        $file = new FileTravaux($this->pdo);
        $prevu = $file->dejaPrevu(self::TYPE);
        if ($prevu !== null) {
            throw new ErreurSaisie('Un gel ou un dégel est déjà prévu ou en cours (travail n° ' . $prevu
                . ') : attendez qu\'il se termine.');
        }
        $requete = $this->pdo->prepare(
            "SELECT DISTINCT e.identifiant, e.nom_complet FROM etudiants e
             JOIN releve_sites r ON r.identifiant = e.identifiant AND r.dossier = '/WP' AND r.logiciel = 'wordpress'
             WHERE e.session_id = :s ORDER BY e.nom_complet"
        );
        $requete->execute([':s' => $sessionId]);
        $etapes = [];
        foreach (Lecture::lignes($requete) as $etudiant) {
            $etapes[] = ['cible' => (string) Lecture::texte($etudiant['identifiant']),
                'libelle' => mb_substr((string) Lecture::texte($etudiant['nom_complet']), 0, 150, 'UTF-8')];
        }
        if ($etapes === []) {
            throw new ErreurSaisie('Aucun WordPress /WP relevé pour la session ' . $annee
                . ' : actualisez la liste des sites.');
        }

        $parametres = ['geler' => $geler, 'session' => $annee, 'demande_par_nom' => $auteur->nomAffiche];

        return $file->demander(self::TYPE, $parametres, $etapes, $auteur, $adresseIp);
    }
}
