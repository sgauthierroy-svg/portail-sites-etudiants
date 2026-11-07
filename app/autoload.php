<?php

declare(strict_types=1);

/*
 * Chargement automatique des classes du portail, sans Composer :
 * la classe Portail\Base\Connexion se trouve dans app/Base/Connexion.php.
 * Seul l'espace de noms Portail\ est chargé, et seulement depuis app/.
 */
spl_autoload_register(static function (string $classe): void {
    $prefixe = 'Portail\\';
    if (!str_starts_with($classe, $prefixe)) {
        return;
    }
    $relatif = substr($classe, strlen($prefixe));
    if (preg_match('/^[A-Za-z0-9\\\\]+$/', $relatif) !== 1) {
        return;
    }
    $fichier = __DIR__ . '/' . str_replace('\\', '/', $relatif) . '.php';
    if (is_file($fichier)) {
        require $fichier;
    }
});
