<?php

declare(strict_types=1);

namespace Portail\Sites;

use Portail\Systeme\Fichiers;
use RuntimeException;

/**
 * Outils communs à l'installation d'un sujet sur le site modèle et à son déploiement dans les /WP2 :
 * base exportée (lecture, filtrage, préfixe des tables), version de WordPress, fichiers de
 * Duplicator à retirer, suffixe au hasard des bases et utilisateurs MySQL.
 */
final class OutilsWordPress
{

    /** 6 caractères au hasard (minuscules et chiffres) : base et utilisateur propres à cette installation. */
    public static function suffixe(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $suffixe = '';
        for ($i = 0; $i < 6; $i++) {
            $suffixe .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffixe;
    }

    /** Préfixe des tables d'après la base exportée : celui qui a à la fois …options et …users. */
    public static function prefixeTables(string $sql): string
    {
        $flux = fopen($sql, 'rb');
        if ($flux === false) {
            throw new RuntimeException('Base du sujet illisible.');
        }
        $tables = [];
        $lu = 0;
        while (($ligne = fgets($flux, 1_000_000)) !== false && $lu < 200_000_000) {
            $lu += strlen($ligne);
            if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)? `([A-Za-z0-9_]{1,64})`/', $ligne, $trouve) === 1) {
                $tables[$trouve[1]] = true;
            }
        }
        fclose($flux);
        $candidats = [];
        foreach (array_keys($tables) as $table) {
            if (str_ends_with($table, 'options')) {
                $prefixe = substr($table, 0, -7);
                if (isset($tables[$prefixe . 'users']) && preg_match('/^[A-Za-z0-9_]{1,20}$/D', $prefixe) === 1) {
                    $candidats[] = $prefixe;
                }
            }
        }
        if ($candidats === []) {
            throw new RuntimeException('Tables WordPress introuvables dans la base du sujet.');
        }
        usort($candidats, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        return $candidats[0];
    }

    /**
     * Base du paquet en un seul fichier SQL à importer, quel que soit le rangement de Duplicator :
     * - un fichier dup-installer/dup-database__….sql (paquets d'octobre 2026) ;
     * - une table par fichier dup-installer/dup_descriptors_…/db_dumps/<table>-h<repère>.sql.gz, et
     *   schema.sql.gz qui crée les tables et règle la session (relevé le 14/11/2026) : schema d'abord,
     *   puis les données, dans un seul import pour que les réglages de session valent pour tout.
     */
    public static function preparerSql(string $preparation, string $cible): void
    {
        $unique = glob($preparation . '/dup-installer/dup-database__*.sql');
        if ($unique !== false && $unique !== []) {
            self::filtrerSql([$unique[0]], $cible);

            return;
        }
        $schema = glob($preparation . '/dup-installer/dup_descriptors_*/db_dumps/schema.sql.gz');
        if ($schema === false || count($schema) !== 1) {
            throw new RuntimeException('Base du sujet introuvable dans le paquet (ni dup-database__….sql, ni db_dumps/schema.sql.gz).');
        }
        $tables = glob(dirname($schema[0]) . '/*-h*.sql.gz');
        $donnees = [];
        foreach ($tables === false ? [] : $tables as $fichier) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}-h[A-Za-z0-9]{1,40}\.sql\.gz$/D', basename($fichier)) === 1) {
                $donnees[] = $fichier;
            }
        }
        sort($donnees);
        self::filtrerSql(array_merge($schema, $donnees), $cible);
    }

    /**
     * Copie des fichiers de la base (compressés ou non) dans un seul fichier, sans les messages
     * d'avertissement que l'export laisse parfois au début (« mysqldump: … »).
     *
     * @param list<string> $sources
     */
    public static function filtrerSql(array $sources, string $cible): void
    {
        $sortie = fopen($cible, 'xb');
        if ($sortie === false) {
            throw new RuntimeException('Préparation de la base du sujet impossible.');
        }
        chmod($cible, 0600);
        try {
            foreach ($sources as $source) {
                $compresse = str_ends_with($source, '.gz');
                if ($compresse && !function_exists('gzopen')) {
                    throw new RuntimeException('Base compressée du sujet illisible : extension zlib de PHP absente.');
                }
                $entree = $compresse ? gzopen($source, 'rb') : fopen($source, 'rb');
                if ($entree === false) {
                    throw new RuntimeException('Fichier de la base du sujet illisible : ' . basename($source) . '.');
                }
                while (($ligne = $compresse ? gzgets($entree) : fgets($entree)) !== false) {
                    if (!str_starts_with($ligne, 'mysqldump:') && !str_starts_with($ligne, 'mariadb-dump:')) {
                        fwrite($sortie, $ligne);
                    }
                }
                $compresse ? gzclose($entree) : fclose($entree);
                fwrite($sortie, "\n");
            }
        } finally {
            fclose($sortie);
        }
    }
    public static function versionWordPress(string $chemin): ?string
    {
        $contenu = is_file($chemin . '/wp-includes/version.php') ? file_get_contents($chemin . '/wp-includes/version.php', false, null, 0, 8192) : false;

        return is_string($contenu) && preg_match('/\$wp_version\s*=\s*\'([0-9.]{1,20})\'/', $contenu, $trouve) === 1 ? $trouve[1] : null;
    }

    /** Fichiers de Duplicator et paquets Duplicator rangés dans le site : jamais copiés vers les étudiants. */
    public static function nettoyer(string $preparation): void
    {
        foreach (['dup-installer', 'wp-content/backups-dup-lite', 'wp-content/backups-dup-pro', 'wp-snapshots'] as $dossier) {
            if (is_dir($preparation . '/' . $dossier) && !is_link($preparation . '/' . $dossier)) {
                Fichiers::effacerDossier($preparation . '/' . $dossier, $preparation);
            }
        }
        $fichiers = glob($preparation . '/{installer.php,installer-backup.php,dup-*.txt,dup-*.php,*_archive.zip,*_archive.daf}', GLOB_BRACE);
        foreach ($fichiers === false ? [] : $fichiers as $fichier) {
            if (is_file($fichier)) {
                unlink($fichier); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
        }
    }

    /**
     * Base et utilisateur MySQL d'un site existant, pour la purge après sa mise à l'écart : ceux du portail
     * (table sites), sinon ceux de wp-config.php seulement s'ils sont propres à l'étudiant
     * (jafl3067_identifiant ou jafl3067_identifiant_…). L'utilisateur partagé jafl3067_peda des scripts
     * n'est jamais retenu : il sert à tous les sites créés avant le portail.
     *
     * @return array{0: ?string, 1: ?string} Base, utilisateur
     */
    public static function ancienneBase(\PDO $pdo, string $identifiant, string $dossier, string $site): array
    {
        $requete = $pdo->prepare('SELECT base, utilisateur_bdd FROM sites WHERE identifiant = :i AND dossier = :d');
        $requete->execute([':i' => $identifiant, ':d' => $dossier]);
        $ligne = \Portail\Base\Lecture::ligne($requete);
        if ($ligne !== null) {
            return [\Portail\Base\Lecture::texte($ligne['base']), \Portail\Base\Lecture::texte($ligne['utilisateur_bdd'])];
        }
        $config = is_file($site . '/wp-config.php') && !is_link($site . '/wp-config.php')
            ? (string) file_get_contents($site . '/wp-config.php', false, null, 0, 65536) : '';
        $propre = '/^jafl3067_' . preg_quote($identifiant, '/') . '(_[A-Za-z0-9_]{1,40})?$/D';
        $lire = static function (string $nom) use ($config, $propre): ?string {
            if (preg_match('/define\(\s*[\'"]' . $nom . '[\'"]\s*,\s*[\'"]([A-Za-z0-9_]{1,64})[\'"]\s*\)/', $config, $trouve) !== 1) {
                return null;
            }

            return preg_match($propre, $trouve[1]) === 1 ? $trouve[1] : null;
        };
        $utilisateur = $lire('DB_USER');

        return [$lire('DB_NAME'), $utilisateur !== null && strtolower($utilisateur) === $utilisateur ? $utilisateur : null];
    }
}
