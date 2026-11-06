<?php

// Fichier de vérification des règles Semgrep (jamais déployé).
// "ruleid: X" = la ligne suivante DOIT être détectée par la règle X.
// "ok: X"     = la ligne suivante ne doit PAS l'être.

// ruleid: execution-de-code-dynamique
eval($code);
// ruleid: commande-systeme-hors-bin
exec('ls ' . $dossier);
// ruleid: commande-systeme-hors-bin
$sortie = shell_exec($commande);
// ruleid: deserialisation-dangereuse
$objet = unserialize($donnees);
// ok: deserialisation-dangereuse
$tableau = json_decode($donnees, true, 512, JSON_THROW_ON_ERROR);
// ruleid: hachage-faible
$empreinte = md5($motDePasse);
// ok: hachage-faible
$empreinte = password_hash($motDePasse, PASSWORD_ARGON2ID);
// ruleid: hasard-previsible
$jeton = mt_rand();
// ok: hasard-previsible
$jeton = bin2hex(random_bytes(32));
// ruleid: superglobale-request-ou-extract
$valeur = $_REQUEST['x'];
// ruleid: superglobale-request-ou-extract
extract($_POST);
// ruleid: affichage-direct-de-donnees-visiteur
echo 'Bonjour ' . $_GET['nom'];
// ok: affichage-direct-de-donnees-visiteur
echo 'Bonjour ' . htmlspecialchars($nom, ENT_QUOTES, 'UTF-8');
// ruleid: requete-sql-construite-par-concatenation
$pdo->query("SELECT * FROM etudiants WHERE identifiant = '" . $identifiant . "'");
// ok: requete-sql-construite-par-concatenation
$pdo->prepare('SELECT * FROM etudiants WHERE identifiant = :identifiant');
// ruleid: inclusion-de-fichier-variable
include $_GET['page'];
// ruleid: redirection-ouverte
header('Location: ' . $_GET['retour']);
// ruleid: verification-tls-desactivee
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// ruleid: diagnostic-expose
phpinfo();
// ruleid: diagnostic-expose
ini_set('display_errors', '1');
// ok: diagnostic-expose
ini_set('display_errors', '0');
// ruleid: comparaison-de-secret-non-constante
if ($jetonSession === $_POST['jeton']) {
}
// ok: comparaison-de-secret-non-constante
if (hash_equals($jetonSession, (string) $_POST['jeton'])) {
}
