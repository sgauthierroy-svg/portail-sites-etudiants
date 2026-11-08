<?php

declare(strict_types=1);

/*
 * Alertes par e-mail, à lancer en SSH ou par une tâche planifiée de CPanel :
 *     php ~/portail/bin/alertes.php test     envoie un e-mail de test à l'adresse d'alerte
 *     php ~/portail/bin/alertes.php hebdo    rappel des comptes à revoir (tâche du lundi)
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
$alertes = \Portail\Noyau\Demarrage::alertes($config, $pdo);

/** @var list<string> $argv Arguments de la ligne de commande, toujours des textes. */
switch ($argv[1] ?? '') {
    case 'test':
        $alertes->test();
        break;
    case 'hebdo':
        $alertes->revueHebdomadaire((new \Portail\Comptes\GestionComptes($pdo, null, null))->aRevoir(time()));
        break;
    default:
        fwrite(STDERR, 'Utilisation : php ~/portail/bin/alertes.php test | hebdo' . PHP_EOL);
        exit(1);
}
$prevues = $alertes->nombreEnAttente();
$envoyees = $alertes->expedier();
if ($prevues === 0) {
    fwrite(STDOUT, '  Rien à envoyer.' . PHP_EOL);
} elseif ($envoyees === $prevues) {
    fwrite(STDOUT, '  E-mail envoyé ✓ (inscrit au journal)' . PHP_EOL);
} else {
    fwrite(STDERR, '  Échec de l\'envoi : voir la raison dans le journal du portail (catégorie Actions).' . PHP_EOL);
    exit(1);
}
