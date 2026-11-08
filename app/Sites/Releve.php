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
     * @return list<array{dossier: string, logiciel: string, version: ?string}>
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
                $sites[] = ['dossier' => '/' . $entree, 'logiciel' => 'prestashop', 'version' => null];
            }
        }

        return $sites;
    }

    /**
     * Remplace le relevé précédent, en une seule opération.
     *
     * @param array<string, string> $sousDomaines identifiant => dossier racine
     * @param array<string, list<array{dossier: string, logiciel: string, version: ?string}>> $sites
     * @return array{sous_domaines: int, sites: int}
     */
    public static function enregistrer(PDO $pdo, array $sousDomaines, array $sites, string $date, string $auteur = 'commande de relevé (SSH)'): array
    {
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM sous_domaines');
            $pdo->exec('DELETE FROM releve_sites');
            $ajoutSousDomaine = $pdo->prepare('INSERT INTO sous_domaines (identifiant, releve_le) VALUES (:i, :d)');
            $ajoutSite = $pdo->prepare('INSERT INTO releve_sites (identifiant, dossier, logiciel, version, releve_le) VALUES (:i, :dossier, :l, :v, :d)');
            $nombreSites = 0;
            foreach (array_keys($sousDomaines) as $identifiant) {
                $ajoutSousDomaine->execute([':i' => $identifiant, ':d' => $date]);
                foreach ($sites[$identifiant] ?? [] as $site) {
                    $ajoutSite->execute([':i' => $identifiant, ':dossier' => $site['dossier'], ':l' => $site['logiciel'], ':v' => $site['version'], ':d' => $date]);
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
