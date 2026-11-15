<?php

declare(strict_types=1);

/*
 * Travailleur du portail : exécute les travaux demandés dans le portail.
 * Tâche planifiée de CPanel, chaque minute :
 *     * * * * *  /usr/local/bin/php /home/jafl3067/portail/bin/travaux.php > /dev/null 2>&1
 * Un seul travailleur à la fois (verrou data/tmp/travaux.lock) : si le précédent
 * n'a pas fini, celui-ci s'arrête aussitôt sans rien faire.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$racine = dirname(__DIR__);
require $racine . '/app/autoload.php';

\Portail\Noyau\Erreurs::installer(false);
umask(0077);

$verrou = fopen($racine . '/data/tmp/travaux.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$config = \Portail\Noyau\Config::charger($racine . '/data/config.php');
$pdo = \Portail\Noyau\Demarrage::base($config);
$alertes = \Portail\Noyau\Demarrage::alertes($config, $pdo);

$expediteur = new \Portail\Courriel\ExpediteurSmtp($config->smtpHote, $config->smtpPort, $config->smtpUtilisateur, $config->smtpMotDePasse,
    'Sup\'Sallanches – BTS NDRC');
$cpanel = new \Portail\Sites\Cpanel();
$bibliotheque = new \Portail\Bibliotheque\Bibliotheque($pdo, $config->dossierDonnees);
$personnel = dirname($racine);
$temporaire = $config->dossierDonnees . '/tmp';
$ecart = new \Portail\Sites\MiseALecart($pdo, $personnel);
$travailleur = new \Portail\Travaux\Travailleur($pdo, $alertes, [
    'releve' => new \Portail\Travaux\ExecutantReleve($pdo, null, $alertes),
    'creer_prestashop' => new \Portail\Travaux\ExecutantCreerPrestaShop($pdo, $cpanel, $bibliotheque, new \Portail\Sites\InstallateurPrestaShop(),
        new \Portail\Securite\Chiffrement($config->cleBinaire()), $expediteur, $personnel, $temporaire),
    'reinitialiser_prestashop' => new \Portail\Travaux\ExecutantCreerPrestaShop($pdo, $cpanel, $bibliotheque, new \Portail\Sites\InstallateurPrestaShop(),
        new \Portail\Securite\Chiffrement($config->cleBinaire()), $expediteur, $personnel, $temporaire, $ecart, true),
    'installer_sujet_modele' => new \Portail\Travaux\ExecutantInstallerSujetModele($pdo, $cpanel, $bibliotheque, new \Portail\Sites\WpCli(),
        $ecart, $expediteur, $personnel, $temporaire),
    'geler_sujet_modele' => new \Portail\Travaux\ExecutantGelerSujetModele($pdo, $cpanel, $personnel),
    'deployer_sujet_wp' => new \Portail\Travaux\ExecutantDeployerSujet($pdo, $cpanel, new \Portail\Sites\WpCli(), $ecart, $expediteur, $personnel, $temporaire),
    'cloturer_epreuve' => new \Portail\Travaux\ExecutantCloturerEpreuve($pdo, $cpanel, new \Portail\Sites\WpCli(), $ecart, $expediteur, $personnel, $temporaire),
    'lever_gel_epreuve' => new \Portail\Travaux\ExecutantGelEpreuve($pdo, $cpanel, $personnel),
    'creer_wordpress' => new \Portail\Travaux\ExecutantCreerWordPress($pdo, $cpanel, new \Portail\Sites\WpCli(), new \Portail\Sites\AcmeSh($personnel),
        new \Portail\Securite\Chiffrement($config->cleBinaire()), $expediteur, $personnel, $temporaire),
    'reinitialiser_wordpress' => new \Portail\Travaux\ExecutantCreerWordPress($pdo, $cpanel, new \Portail\Sites\WpCli(), new \Portail\Sites\AcmeSh($personnel),
        new \Portail\Securite\Chiffrement($config->cleBinaire()), $expediteur, $personnel, $temporaire, $ecart, true),
]);
// 50 secondes de nouvelles étapes au plus : la tâche suivante (dans une minute) reprend la suite.
$faites = $travailleur->tourner(50);
// Mises à l'écart de plus de 8 jours : une seule effacée par passage, quand la file est vide.
if ($faites === 0) {
    $lecture = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'bases_protegees'");
    $lecture->execute();
    $bases = \Portail\Base\Lecture::tableau(json_decode((string) \Portail\Base\Lecture::texte($lecture->fetchColumn()), true)) ?? [];
    $ecart->purgerUne($cpanel, array_values(array_filter($bases, 'is_string')));
    // Certificats des sous-domaines créés par le portail, pas encore posés : un essai à la fois, toutes les 20 minutes.
    (new \Portail\Sites\RelanceCertificats($pdo))->relancerUn(new \Portail\Sites\AcmeSh($personnel), $cpanel, $personnel, $alertes);
    // Vérification des certificats comme un navigateur : une fois par jour, ou à la demande de l'administrateur, par lots.
    (new \Portail\Sites\SuiviCertificats($pdo))->verifierLot(new \Portail\Sites\SondeOpenssl());
    $alertes->expedier();
}
if (in_array('--bavard', $argv, true)) {
    fwrite(STDOUT, '  Étapes traitées : ' . $faites . PHP_EOL);
}
flock($verrou, LOCK_UN);
fclose($verrou);
