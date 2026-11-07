<?php

declare(strict_types=1);

// Seule page exposée au web : tout le reste est dans ~/portail/app, hors d'atteinte.
$racine = dirname(__DIR__);
require $racine . '/app/autoload.php';

\Portail\Noyau\Demarrage::web($racine);
