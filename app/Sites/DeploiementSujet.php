<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Noyau\ErreurSaisie;
use Portail\Travaux\FileTravaux;

/**
 * Côté web du déploiement du site modèle d'examen dans les /WP2 (V2, lot 4, paquet 2 ;
 * décisions des 13 et 14/11/2026). Rien n'est fait sur le serveur ici : le travail commence
 * par une « photo » du modèle (étape « sujet »), puis copie cette photo chez chaque étudiant coché.
 */
final class DeploiementSujet
{
    public const DOSSIER = '/WP2';
    public const TYPE = 'deployer_sujet_wp';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id: int, annee: int}> Sessions actives */
    public function sessions(): array
    {
        $liste = [];
        foreach (Lecture::lignes($this->pdo->query('SELECT id, annee FROM sessions WHERE active = 1 ORDER BY annee')) as $ligne) {
            $liste[] = ['id' => (int) Lecture::entier($ligne['id']), 'annee' => (int) Lecture::entier($ligne['annee'])];
        }

        return $liste;
    }

    /**
     * Étudiants de la session : empêchement (null : possible) et présence d'un /WP2, qui sera mis à l'écart 8 jours.
     *
     * Épreuve : deploye (/WP2 déployé par le portail, le …), cloture_le, lecture_seule, epreuve_prevue
     * (clôture ou gel en attente pour cet étudiant).
     *
     * @return list<array{identifiant: string, nom: string, regime: string, empechement: ?string, remplace: bool,
     *                    deploye_le: ?string, cloture_le: ?string, lecture_seule: bool, epreuve_prevue: bool}>
     */
    public function etudiants(int $sessionId): array
    {
        $requete = $this->pdo->prepare(
            "SELECT e.identifiant, e.nom_complet, r.nom AS regime,
                    w.cree_le AS deploye_le, w.cloture_le, coalesce(w.lecture_seule, 0) AS lecture_seule,
                    EXISTS (SELECT 1 FROM travaux_etapes t JOIN travaux x ON x.id = t.travail_id
                            WHERE t.cible = e.identifiant AND x.type IN (:clore, :gel) AND t.etat IN ('en_attente', 'en_cours')) AS epreuve_prevue,
                    EXISTS (SELECT 1 FROM sous_domaines d WHERE d.identifiant = e.identifiant) AS sous_domaine,
                    (EXISTS (SELECT 1 FROM releve_sites s WHERE s.identifiant = e.identifiant AND s.dossier = '/WP2')
                     OR EXISTS (SELECT 1 FROM sites s WHERE s.identifiant = e.identifiant AND s.dossier = '/WP2')) AS present,
                    EXISTS (SELECT 1 FROM travaux_etapes t JOIN travaux w ON w.id = t.travail_id
                            WHERE t.cible = e.identifiant AND w.type = :type AND t.etat IN ('en_attente', 'en_cours')) AS prevu
             FROM etudiants e JOIN regimes r ON r.id = e.regime_id
                  LEFT JOIN sites w ON w.identifiant = e.identifiant AND w.dossier = '/WP2'
             WHERE e.session_id = :s ORDER BY e.nom_complet COLLATE NOCASE"
        );
        $requete->execute([':s' => $sessionId, ':type' => self::TYPE, ':clore' => EpreuveSujet::CLOTURER, ':gel' => EpreuveSujet::GEL]);
        $proteges = Lecture::tableau(json_decode((string) $this->parametre('sites_proteges'), true)) ?? [];
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $identifiant = (string) Lecture::texte($ligne['identifiant']);
            $empechement = null;
            if (Lecture::entier($ligne['sous_domaine']) !== 1) {
                $empechement = 'sous-domaine absent du dernier relevé';
            }
            if (in_array($identifiant, $proteges, true)) {
                $empechement = 'sous-domaine protégé';
            }
            if (Lecture::entier($ligne['prevu']) === 1) {
                $empechement = 'déploiement déjà prévu';
            }
            $epreuvePrevue = Lecture::entier($ligne['epreuve_prevue']) === 1;
            if ($epreuvePrevue) {
                $empechement = 'clôture ou gel prévu';
            }
            $liste[] = [
                'identifiant' => $identifiant,
                'nom' => (string) Lecture::texte($ligne['nom_complet']),
                'regime' => (string) Lecture::texte($ligne['regime']),
                'empechement' => $empechement,
                'remplace' => Lecture::entier($ligne['present']) === 1,
                'deploye_le' => Lecture::texte($ligne['deploye_le']),
                'cloture_le' => Lecture::texte($ligne['cloture_le']),
                'lecture_seule' => Lecture::entier($ligne['lecture_seule']) === 1,
                'epreuve_prevue' => $epreuvePrevue,
            ];
        }

        return $liste;
    }

    /**
     * Inscrit le déploiement : photo du modèle, puis un /WP2 par étudiant coché (ordre alphabétique).
     *
     * @param list<string> $coches Identifiants
     */
    public function lancer(int $sessionId, array $coches, bool $confirme, Compte $auteur, ?string $adresseIp): int
    {
        $etat = (new SiteModele($this->pdo))->etat();
        if (!$etat['installe']) {
            throw new ErreurSaisie('Aucun sujet n\'est installé sur le site modèle : rien à déployer.');
        }
        if ($etat['travail'] !== null) {
            throw new ErreurSaisie('Un travail sur le site modèle est déjà prévu ou en cours (travail n° ' . $etat['travail'] . ') : attendez qu\'il se termine.');
        }
        if (!in_array($sessionId, array_column($this->sessions(), 'id'), true)) {
            throw new ErreurSaisie('Session inconnue ou archivée.');
        }
        $possibles = [];
        $remplaces = 0;
        foreach ($this->etudiants($sessionId) as $etudiant) {
            if ($etudiant['empechement'] === null) {
                $possibles[$etudiant['identifiant']] = $etudiant;
            }
        }
        foreach ($coches as $identifiant) {
            if (!isset($possibles[$identifiant])) {
                throw new ErreurSaisie('Un étudiant coché ne peut pas recevoir le sujet (déploiement prévu, sous-domaine absent, ou hors de cette session). Rechargez la page.');
            }
        }
        $etapes = [['cible' => SiteModele::IDENTIFIANT, 'libelle' => 'Photo du site modèle']];
        foreach ($possibles as $identifiant => $etudiant) {
            if (in_array($identifiant, $coches, true)) {
                $etapes[] = ['cible' => $identifiant, 'libelle' => $etudiant['nom']];
                $remplaces += $etudiant['remplace'] ? 1 : 0;
            }
        }
        if (count($etapes) === 1) {
            throw new ErreurSaisie('Cochez au moins un étudiant.');
        }
        if ($remplaces > 0 && !$confirme) {
            throw new ErreurSaisie('Cochez la case de confirmation : ' . $remplaces . ' étudiant(s) coché(s) ont déjà un /WP2, qui sera remplacé (gardé 8 jours à l\'écart).');
        }

        return (new FileTravaux($this->pdo))->demander(self::TYPE, [
            'photo' => 'photo-' . gmdate('Ymd-His') . '-' . OutilsWordPress::suffixe(),
            'demande_par_nom' => $auteur->nomAffiche,
        ], $etapes, $auteur, $adresseIp);
    }

    private function parametre(string $cle): ?string
    {
        $requete = $this->pdo->prepare('SELECT valeur FROM parametres WHERE cle = :c');
        $requete->execute([':c' => $cle]);

        return Lecture::texte($requete->fetchColumn());
    }
}
