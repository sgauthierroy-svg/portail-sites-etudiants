<?php

declare(strict_types=1);

/*
 * Commande de secours des comptes du portail, à lancer en SSH.
 * Aide : php ~/portail/bin/comptes.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$racine = dirname(__DIR__);
require $racine . '/app/autoload.php';

\Portail\Noyau\Erreurs::installer(false);
umask(0077);

$config = \Portail\Noyau\Config::charger($racine . '/data/config.php');
$pdo = \Portail\Noyau\Demarrage::base($config);

try {
    /** @var list<string> $argv Arguments de la ligne de commande, toujours des textes. */
    $alertes = \Portail\Noyau\Demarrage::alertes($config, $pdo);
    $lignes = (new \Portail\Comptes\CommandeComptes($pdo, $alertes))->executer(array_slice($argv, 1));
    $alertes->expedier();
} catch (\Portail\Noyau\ErreurSaisie $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
foreach ($lignes as $ligne) {
    fwrite(STDOUT, $ligne . PHP_EOL);
}
