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
        // Clés étrangères vérifiées, attente en cas d'écriture simultanée,
        // données effacées réellement écrasées sur le disque.
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA secure_delete = ON');

        return $pdo;
    }
}
