<?php

declare(strict_types=1);

/*
 * Installation ou mise à jour du portail, à lancer en SSH :
 *     php ~/portail/bin/installer.php
 * Crée les dossiers privés, vérifie la configuration, crée ou met à jour
 * la base. Peut être relancé sans risque.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$racine = dirname(__DIR__);
require $racine . '/app/autoload.php';

\Portail\Noyau\Erreurs::installer(false);
umask(0077);

foreach (\Portail\Base\Installation::installer($racine) as $ligne) {
    fwrite(STDOUT, '  ' . $ligne . PHP_EOL);
}
fwrite(STDOUT, '  Installation terminée.' . PHP_EOL);
