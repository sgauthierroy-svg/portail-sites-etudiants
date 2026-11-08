<?php

declare(strict_types=1);

/*
 * Relevé des sous-domaines et des sites, à lancer en SSH (lecture seule) :
 *     php ~/portail/bin/releve.php
 * Demande à CPanel la liste réelle des sous-domaines de bts-sup-sallanches.com,
 * repère les sites WordPress et PrestaShop de chacun, et remplace le relevé
 * précédent dans la base du portail. Ne modifie rien sur le serveur.
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
    $bilan = \Portail\Sites\Releve::lancer($pdo, 'commande de relevé (SSH)');
} catch (\Portail\Noyau\ErreurSaisie $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, '  Sous-domaines relevés : ' . $bilan['sous_domaines'] . ' ✓' . PHP_EOL);
fwrite(STDOUT, '  Sites WordPress ou PrestaShop trouvés : ' . $bilan['sites'] . ' ✓' . PHP_EOL);
fwrite(STDOUT, '  Relevé enregistré (le précédent est remplacé).' . PHP_EOL);
