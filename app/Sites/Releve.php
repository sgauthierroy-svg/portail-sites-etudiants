<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Lecture;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;

/**
 * Relevé de ce qui existe réellement sur le serveur : sous-domaines de
 * bts-sup-sallanches.com, et sites WordPress ou PrestaShop dans chacun.
 *
 * Lecture seule : rien n'est modifié sur le serveur. Le relevé remplace en
 * entier le précédent. Il sert à l'import (un identifiant hors règle n'est
 * accepté que si son sous-domaine existe) et, plus tard, au contrôle hebdomadaire.
 */
final class Releve
{
    public const DOMAINE = 'bts-sup-sallanches.com';

    /**
     * Sous-domaines du domaine des étudiants, lus dans la réponse JSON de
     * « uapi DomainInfo domains_data » (CPanel).
     *
     * @return array<string, string> identifiant => dossier racine
     */
    public static function sousDomaines(string $json): array
    {
        $donnees = Lecture::tableau(json_decode($json, true));
        $reponse = Lecture::tableau($donnees['result'] ?? null);
        $data = Lecture::tableau($reponse['data'] ?? null);
        $liste = Lecture::tableau($data['sub_domains'] ?? null);
        if ($liste === null) {
            throw new ErreurSaisie('Réponse de CPanel illisible : relevé abandonné, rien n\'a été modifié.');
        }
        $resultat = [];
        $suffixe = '.' . self::DOMAINE;
        foreach (array_keys($liste) as $rang) {
            $sousDomaine = Lecture::tableau($liste[$rang]);
            $domaine = Lecture::texte($sousDomaine['domain'] ?? null);
            $racine = Lecture::texte($sousDomaine['documentroot'] ?? null);
            if ($domaine === null || $racine === null) {
                continue;
            }
            $nom = strtolower($domaine);
            if (!str_ends_with($nom, $suffixe)) {
                continue;
            }
            $identifiant = substr($nom, 0, -strlen($suffixe));
            if (preg_match('/^[a-z][a-z0-9]{1,62}$/D', $identifiant) === 1) {
                $resultat[$identifiant] = $racine;
            }
        }
        ksort($resultat);

        return $resultat;
    }

    /**
     * Sites trouvés dans le dossier racine d'un sous-domaine (un niveau).
     *
     * @return list<array{dossier: string, logiciel: string, version: ?string, module_mise_a_jour?: bool}>
     */
    public static function sites(string $racine): array
    {
        $sites = [];
        $entrees = is_dir($racine) ? scandir($racine) : false;
        foreach ($entrees === false ? [] : $entrees as $entree) {
            // Même contrainte que la base : lettres et chiffres seulement.
            if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $entree) !== 1 || !is_dir($racine . '/' . $entree)) {
                continue;
            }
            $chemin = $racine . '/' . $entree;
            if (is_file($chemin . '/wp-config.php')) {
                $sites[] = ['dossier' => '/' . $entree, 'logiciel' => 'wordpress', 'version' => self::versionWordPress($chemin)];
            } elseif (is_file($chemin . '/app/config/parameters.php') || is_file($chemin . '/config/settings.inc.php')) {
                $sites[] = ['dossier' => '/' . $entree, 'logiciel' => 'prestashop', 'version' => self::versionPrestaShop($chemin),
                    'module_mise_a_jour' => is_dir($chemin . '/modules/autoupgrade')];
            }
        }

        return $sites;
    }

    /**
     * Remplace le relevé précédent, en une seule opération.
     *
     * @param array<string, string> $sousDomaines identifiant => dossier racine
     * @param array<string, list<array{dossier: string, logiciel: string, version: ?string, module_mise_a_jour?: bool}>> $sites
     * @return array{sous_domaines: int, sites: int}
     */
    public static function enregistrer(PDO $pdo, array $sousDomaines, array $sites, string $date, string $auteur = 'commande de relevé (SSH)'): array
    {
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM sous_domaines');
            $pdo->exec('DELETE FROM releve_sites');
            $ajoutSousDomaine = $pdo->prepare('INSERT INTO sous_domaines (identifiant, releve_le) VALUES (:i, :d)');
            $ajoutSite = $pdo->prepare('INSERT INTO releve_sites (identifiant, dossier, logiciel, version, module_mise_a_jour, releve_le) VALUES (:i, :dossier, :l, :v, :m, :d)');
            $nombreSites = 0;
            foreach (array_keys($sousDomaines) as $identifiant) {
                $ajoutSousDomaine->execute([':i' => $identifiant, ':d' => $date]);
                foreach ($sites[$identifiant] ?? [] as $site) {
                    $ajoutSite->execute([':i' => $identifiant, ':dossier' => $site['dossier'], ':l' => $site['logiciel'], ':v' => $site['version'],
                        ':m' => isset($site['module_mise_a_jour']) ? (int) $site['module_mise_a_jour'] : null, ':d' => $date]);
                    $nombreSites++;
                }
            }
            (new Journal($pdo))->ecrire('site', 'relevé des sous-domaines', 'succes', null, $auteur, null, null,
                count($sousDomaines) . ' sous-domaines, ' . $nombreSites . ' sites');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['sous_domaines' => count($sousDomaines), 'sites' => $nombreSites];
    }

    /**
     * Relevé complet : demande la liste à CPanel, puis lit chaque dossier.
     * Commande fixe, sans aucune donnée venue de l'extérieur.
     *
     * @param (callable(): string)|null $lireCpanel Remplaçable dans les tests
     * @return array{sous_domaines: int, sites: int}
     */
    public static function lancer(PDO $pdo, string $auteur, ?callable $lireCpanel = null): array
    {
        $lireCpanel ??= static function (): string {
            $resultat = \Portail\Systeme\Commande::lancer(['uapi', '--output=json', 'DomainInfo', 'domains_data'], 120);
            if ($resultat['code'] !== 0) {
                throw new ErreurSaisie('CPanel n\'a pas répondu (uapi, code ' . $resultat['code'] . ') : relevé abandonné, rien n\'a été modifié.');
            }

            return $resultat['sortie'];
        };
        $sousDomaines = self::sousDomaines($lireCpanel());
        $sites = [];
        foreach ($sousDomaines as $identifiant => $dossier) {
            $sites[$identifiant] = self::sites($dossier);
        }

        return self::enregistrer($pdo, $sousDomaines, $sites, gmdate('Y-m-d\TH:i:s\Z'), $auteur);
    }

    /**
     * Sites relevés, par identifiant.
     *
     * @return array<string, array<string, array{logiciel: ?string, version: ?string}>> identifiant => dossier => site
     */
    public static function parIdentifiant(PDO $pdo): array
    {
        $resultat = $pdo->query('SELECT identifiant, dossier, logiciel, version FROM releve_sites ORDER BY identifiant, dossier');
        $sites = [];
        foreach (Lecture::lignes($resultat) as $ligne) {
            $sites[(string) Lecture::texte($ligne['identifiant'])][(string) Lecture::texte($ligne['dossier'])] = [
                'logiciel' => Lecture::texte($ligne['logiciel']),
                'version' => Lecture::texte($ligne['version']),
            ];
        }

        return $sites;
    }

    /**
     * Sites d'un étudiant pour sa fiche : ceux du dernier relevé et ceux créés par le portail.
     *
     * @return list<array{dossier: string, logiciel: string, version: ?string, dossier_admin: ?string, cree_par_portail: bool, date: string}>
     */
    public static function sitesEtudiant(PDO $pdo, string $identifiant): array
    {
        $sites = [];
        $releve = $pdo->prepare('SELECT dossier, logiciel, version, releve_le FROM releve_sites WHERE identifiant = :i');
        $releve->execute([':i' => $identifiant]);
        foreach (Lecture::lignes($releve) as $ligne) {
            $dossier = (string) Lecture::texte($ligne['dossier']);
            $sites[$dossier] = ['dossier' => $dossier, 'logiciel' => (string) Lecture::texte($ligne['logiciel']), 'version' => Lecture::texte($ligne['version']),
                'dossier_admin' => null, 'cree_par_portail' => false, 'date' => (string) Lecture::texte($ligne['releve_le'])];
        }
        $crees = $pdo->prepare('SELECT dossier, logiciel, version, dossier_admin, cree_le FROM sites WHERE identifiant = :i');
        $crees->execute([':i' => $identifiant]);
        foreach (Lecture::lignes($crees) as $ligne) {
            $dossier = (string) Lecture::texte($ligne['dossier']);
            $sites[$dossier] = ['dossier' => $dossier, 'logiciel' => (string) Lecture::texte($ligne['logiciel']),
                'version' => Lecture::texte($ligne['version']) ?? ($sites[$dossier]['version'] ?? null),
                'dossier_admin' => Lecture::texte($ligne['dossier_admin']), 'cree_par_portail' => true, 'date' => (string) Lecture::texte($ligne['cree_le'])];
        }
        ksort($sites);

        return array_values($sites);
    }

    /** @return list<string> Identifiants des sous-domaines relevés */
    public static function identifiants(PDO $pdo): array
    {
        $resultat = $pdo->query('SELECT identifiant FROM sous_domaines ORDER BY identifiant');

        return array_values(array_filter(array_map(static fn (array $l): ?string => Lecture::texte($l['identifiant']), Lecture::lignes($resultat))));
    }

    /** Date du dernier relevé, ou null s'il n'y en a jamais eu. */
    public static function dernier(PDO $pdo): ?string
    {
        $resultat = $pdo->query('SELECT max(releve_le) FROM sous_domaines');

        return $resultat === false ? null : Lecture::texte($resultat->fetchColumn());
    }

    /**
     * Boutiques hors gel (décision du 08/11/2026) : version différente de celle de la
     * session, version illisible, ou module de mise à jour présent.
     *
     * @return array<string, string> identifiant => raison
     */
    public static function horsGel(PDO $pdo): array
    {
        $resultat = $pdo->query(
            "SELECT r.identifiant, r.version, r.module_mise_a_jour, p.version AS attendue
             FROM releve_sites r
             JOIN etudiants e ON e.identifiant = r.identifiant
             JOIN sessions s ON s.id = e.session_id
             LEFT JOIN paquets p ON p.id = s.paquet_prestashop_id
             WHERE r.dossier = '/Presta' AND r.logiciel = 'prestashop' AND p.version IS NOT NULL"
        );
        $ecarts = [];
        foreach (Lecture::lignes($resultat) as $ligne) {
            $version = Lecture::texte($ligne['version']);
            $attendue = (string) Lecture::texte($ligne['attendue']);
            $raison = null;
            if (Lecture::entier($ligne['module_mise_a_jour']) === 1) {
                $raison = 'module de mise à jour installé';
            } elseif ($version === null) {
                $raison = 'version illisible';
            } elseif ($version !== $attendue) {
                $raison = 'version ' . $version . ' au lieu de ' . $attendue;
            }
            if ($raison !== null) {
                $ecarts[(string) Lecture::texte($ligne['identifiant'])] = $raison;
            }
        }

        return $ecarts;
    }

    /**
     * Version d'une boutique, lue dans ses fichiers (jamais exécutés) :
     * - PrestaShop 9 : src/Core/Version.php (constaté sur une 9.1.5 le 11/11/2026) ;
     * - à défaut, les trois nombres MAJOR/MINOR/RELEASE de app/AppKernel.php ;
     * - PrestaShop 8 et avant : const VERSION = '…' dans app/AppKernel.php.
     */
    private static function versionPrestaShop(string $chemin): ?string
    {
        $lire = static function (string $fichier): string {
            $contenu = is_file($fichier) ? file_get_contents($fichier, false, null, 0, 65536) : false;

            return is_string($contenu) ? $contenu : '';
        };
        $litterale = '/const\s+VERSION\s*=\s*[\'"]([0-9]{1,3}(?:\.[0-9]{1,3}){1,3})[\'"]/';
        $noyau = $lire($chemin . '/app/AppKernel.php');
        foreach ([$lire($chemin . '/src/Core/Version.php'), $noyau] as $contenu) {
            if (preg_match($litterale, $contenu, $trouve) === 1) {
                return $trouve[1];
            }
        }
        $nombres = [];
        foreach (['MAJOR_VERSION', 'MINOR_VERSION', 'RELEASE_VERSION'] as $nom) {
            if (preg_match('/const\s+' . $nom . '\s*=\s*([0-9]{1,3})\s*;/', $noyau, $trouve) !== 1) {
                return null;
            }
            $nombres[] = $trouve[1];
        }

        return implode('.', $nombres);
    }

    private static function versionWordPress(string $chemin): ?string
    {
        $fichier = $chemin . '/wp-includes/version.php';
        $contenu = is_file($fichier) ? file_get_contents($fichier, false, null, 0, 4096) : false;
        if (is_string($contenu) && preg_match('/\$wp_version\s*=\s*\'([0-9.]{1,12})\'/', $contenu, $trouve) === 1) {
            return $trouve[1];
        }

        return null;
    }
}
