<?php

declare(strict_types=1);

// Lancé dans un processus PHP séparé par ReglagesPhpTest : applique les
// réglages du portail sur un faux dossier ~/portail, puis rend compte en JSON.
require __DIR__ . '/../../app/autoload.php';

$racine = $argv[1] ?? '';
$dehors = $argv[2] ?? '';
\Portail\Noyau\ReglagesPhp::appliquer($racine);

$valeurs = [];
foreach (array_keys(\Portail\Noyau\ReglagesPhp::reglages($racine)) as $nom) {
    $valeurs[$nom] = ini_get($nom);
}
echo json_encode([
    'valeurs' => $valeurs,
    'lecture_dedans' => @file_get_contents($racine . '/data/temoin.txt'),
    'lecture_dehors' => @file_get_contents($dehors),
    'ecriture_dehors' => @file_put_contents(dirname($dehors) . '/intrus.txt', 'x'),
]);
