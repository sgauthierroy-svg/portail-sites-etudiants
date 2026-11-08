<?php

// Faux serveur SMTP pour les tests : écoute sur le port donné, accepte une
// seule session, écrit la conversation dans le fichier donné.
[$script, $port, $trace, $refuserAuth] = $argv + [3 => '0'];
$serveur = stream_socket_server('tcp://127.0.0.1:' . $port, $code, $erreur);
if ($serveur === false) {
    exit(1);
}
$client = stream_socket_accept($serveur, 10);
$journal = '';
$ecrire = static function (string $ligne) use (&$client): void { fwrite($client, $ligne . "\r\n"); };
$ecrire('220 faux.smtp ESMTP');
$enDonnees = false;
$etapeAuth = 0;
while (($ligne = fgets($client)) !== false) {
    $journal .= $ligne;
    $l = rtrim($ligne, "\r\n");
    if ($enDonnees) {
        if ($l === '.') {
            $enDonnees = false;
            $ecrire('250 OK file d\'attente');
        }
        continue;
    }
    if ($etapeAuth === 1) { $etapeAuth = 2; $ecrire('334 UGFzc3dvcmQ6'); continue; }
    if ($etapeAuth === 2) { $etapeAuth = 0; $ecrire($refuserAuth === '1' ? '535 Authentication failed' : '235 Authentication succeeded'); continue; }
    $commande = strtoupper(substr($l, 0, 4));
    match (true) {
        str_starts_with(strtoupper($l), 'EHLO') => (function () use ($ecrire) { $ecrire('250-faux.smtp'); $ecrire('250 AUTH LOGIN PLAIN'); })(),
        $l === 'AUTH LOGIN' => (function () use ($ecrire, &$etapeAuth) { $etapeAuth = 1; $ecrire('334 VXNlcm5hbWU6'); })(),
        $commande === 'MAIL', $commande === 'RCPT' => $ecrire('250 OK'),
        $commande === 'DATA' => (function () use ($ecrire, &$enDonnees) { $enDonnees = true; $ecrire('354 Go ahead'); })(),
        $commande === 'QUIT' => (function () use ($ecrire) { $ecrire('221 Bye'); })(),
        default => $ecrire('500 ?'),
    };
    if ($commande === 'QUIT') {
        break;
    }
}
file_put_contents($trace, $journal);
