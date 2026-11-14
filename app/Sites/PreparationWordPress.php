<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Noyau\ErreurSaisie;
use Portail\Travaux\FileTravaux;

/**
 * Côté web de la création des WordPress /WP (V2, lot 5 ; demande du 14/11/2026 : un nouvel
 * étudiant de 1re année). Rien n'est fait sur le serveur ici. Le sous-domaine est créé s'il
 * manque ; la version de WordPress est celle de la session si elle est indiquée (gelée), sinon
 * la dernière (mises à jour automatiques, tant que l'Éducation nationale n'a pas fixé la version).
 */
final class PreparationWordPress
{
    public const TYPE = 'creer_wordpress';
    public const DOSSIER = '/WP';
    public const TITRE = 'Sup';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id: int, annee: int, version: ?string}> Sessions actives et leur version WordPress (null : la dernière) */
    public function sessions(): array
    {
        $liste = [];
        foreach (Lecture::lignes($this->pdo->query('SELECT id, annee, version_wp_gelee FROM sessions WHERE active = 1 ORDER BY annee')) as $ligne) {
            $liste[] = [
                'id' => (int) Lecture::entier($ligne['id']),
                'annee' => (int) Lecture::entier($ligne['annee']),
                'version' => Lecture::texte($ligne['version_wp_gelee']),
            ];
        }

        return $liste;
    }

    /**
     * Étudiants de la session : empêchement (null : possible) et sous-domaine à créer.
     *
     * @return list<array{identifiant: string, nom: string, identifiant_wp: string, email: string, empechement: ?string, sous_domaine: bool}>
     */
    public function etudiants(int $sessionId): array
    {
        $requete = $this->pdo->prepare(
            "SELECT e.identifiant, e.nom_complet, e.identifiant_wp, e.email,
                    EXISTS (SELECT 1 FROM sous_domaines d WHERE d.identifiant = e.identifiant) AS sous_domaine,
                    (EXISTS (SELECT 1 FROM releve_sites r WHERE r.identifiant = e.identifiant AND r.dossier = '/WP')
                     OR EXISTS (SELECT 1 FROM sites s WHERE s.identifiant = e.identifiant AND s.dossier = '/WP')) AS present,
                    EXISTS (SELECT 1 FROM travaux_etapes t JOIN travaux w ON w.id = t.travail_id
                            WHERE t.cible = e.identifiant AND w.type = :type AND t.etat IN ('en_attente', 'en_cours')) AS prevu
             FROM etudiants e WHERE e.session_id = :s ORDER BY e.nom_complet COLLATE NOCASE"
        );
        $requete->execute([':s' => $sessionId, ':type' => self::TYPE]);
        $proteges = Lecture::tableau(json_decode((string) $this->parametre('sites_proteges'), true)) ?? [];
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $identifiant = (string) Lecture::texte($ligne['identifiant']);
            $empechement = null;
            if (Lecture::entier($ligne['present']) === 1) {
                $empechement = 'WordPress /WP déjà présent';
            }
            if (in_array($identifiant, $proteges, true)) {
                $empechement = 'sous-domaine protégé';
            }
            if (Lecture::entier($ligne['prevu']) === 1) {
                $empechement = 'création déjà prévue';
            }
            $liste[] = [
                'identifiant' => $identifiant,
                'nom' => (string) Lecture::texte($ligne['nom_complet']),
                'identifiant_wp' => (string) Lecture::texte($ligne['identifiant_wp']),
                'email' => (string) Lecture::texte($ligne['email']),
                'empechement' => $empechement,
                'sous_domaine' => Lecture::entier($ligne['sous_domaine']) === 1,
            ];
        }

        return $liste;
    }

    /**
     * Inscrit la création des WordPress des étudiants cochés (ordre alphabétique : celui du récapitulatif).
     *
     * @param list<string> $coches Identifiants
     */
    public function lancer(int $sessionId, array $coches, Compte $auteur, ?string $adresseIp): int
    {
        $session = null;
        foreach ($this->sessions() as $candidate) {
            $session = $candidate['id'] === $sessionId ? $candidate : $session;
        }
        if ($session === null) {
            throw new ErreurSaisie('Session inconnue ou archivée.');
        }
        $possibles = [];
        foreach ($this->etudiants($sessionId) as $etudiant) {
            if ($etudiant['empechement'] === null) {
                $possibles[$etudiant['identifiant']] = $etudiant['nom'];
            }
        }
        foreach ($coches as $identifiant) {
            if (!isset($possibles[$identifiant])) {
                throw new ErreurSaisie('Un étudiant coché ne peut pas recevoir de WordPress (déjà présent, prévu, protégé, ou hors de cette session). Rechargez la page.');
            }
        }
        $etapes = [];
        foreach ($possibles as $identifiant => $nom) {
            if (in_array($identifiant, $coches, true)) {
                $etapes[] = ['cible' => $identifiant, 'libelle' => $nom];
            }
        }
        if ($etapes === []) {
            throw new ErreurSaisie('Cochez au moins un étudiant.');
        }

        return (new FileTravaux($this->pdo))->demander(self::TYPE, ['version' => $session['version'], 'titre' => self::TITRE], $etapes, $auteur, $adresseIp);
    }

    private function parametre(string $cle): ?string
    {
        $requete = $this->pdo->prepare('SELECT valeur FROM parametres WHERE cle = :c');
        $requete->execute([':c' => $cle]);

        return Lecture::texte($requete->fetchColumn());
    }
}
