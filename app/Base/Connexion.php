<?php

declare(strict_types=1);

namespace Portail\Base;

use PDO;
use Portail\Noyau\ErreurConfiguration;

/**
 * Ouverture de la base SQLite du portail (~/portail/data/portail.sqlite).
 */
final class Connexion
{
    /** Attente maximale d'une base occupée, en millisecondes. */
    public const ATTENTE_MS = 15000;

    /**
     * Passe la base en mode WAL (lectures possibles pendant une écriture). Réglage gardé dans le
     * fichier : à faire une fois, par l'installateur. Rend le mode obtenu ('wal' si réussi).
     */
    public static function passerEnWal(PDO $pdo): string
    {
        $resultat = $pdo->query('PRAGMA journal_mode = WAL');
        $mode = $resultat === false ? '' : strtolower((string) $resultat->fetchColumn());
        $resultat = null;

        return $mode;
    }

    /** Vrai si l'erreur vient d'une base SQLite occupée par un autre programme (codes 5 et 6). */
    public static function estOccupee(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $info = $e->errorInfo ?? [];
        $code = isset($info[1]) && is_int($info[1]) ? $info[1] : 0;

        return $code === 5 || $code === 6 || str_contains($e->getMessage(), 'database is locked');
    }

    public static function ouvrir(string $chemin): PDO
    {
        $dossier = dirname($chemin);
        if (!is_dir($dossier)) {
            throw new ErreurConfiguration('Dossier de la base introuvable.');
        }
        // SQLite ne crée le fichier qu'à la première écriture : on le crée
        // nous-mêmes, déjà protégé, pour qu'il ne soit jamais lisible par d'autres.
        if (!is_file($chemin)) {
            $ancienMasque = umask(0077);
            $cree = touch($chemin);
            umask($ancienMasque);
            if (!$cree || !chmod($chemin, 0600)) {
                throw new ErreurConfiguration('Impossible de créer la base en droits 600.');
            }
        }

        $pdo = new PDO('sqlite:' . $chemin, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Clés étrangères vérifiées, attente en cas d'écriture simultanée (15 s : pendant un
        // déploiement, le disque du compte peut être saturé plusieurs secondes, constaté le 14/11/2026),
        // données effacées réellement écrasées sur le disque.
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 15000'); // = self::ATTENTE_MS (texte fixe exigé par les règles)
        $pdo->exec('PRAGMA secure_delete = ON');

        return $pdo;
    }
}
