<?php

declare(strict_types=1);

/*
 * MODÈLE DE CONFIGURATION DU PORTAIL
 * ----------------------------------
 * Ce fichier est un modèle : il ne doit JAMAIS contenir de vraie valeur
 * secrète (un test automatique le vérifie).
 *
 * Sur le serveur, une copie remplie vit dans :
 *     /home/jafl3067/portail/data/config.php   (droits 600)
 * Elle n'est jamais envoyée dans le dépôt GitHub.
 */

return [
    // Adresse publique du portail
    'url_portail' => 'https://portail.bts-sup-sallanches.com',

    // Dossier privé des données (base SQLite, sessions, fichiers temporaires)
    'dossier_donnees' => '/home/jafl3067/portail/data',

    // Dossier du journal des erreurs PHP
    'dossier_journaux' => '/home/jafl3067/portail/logs',

    // Adresse qui reçoit les alertes du portail
    'adresse_alertes' => 'informatique@ecs-sallanches.fr',

    // Envoi des messages : boîte dédiée portail@bts-sup-sallanches.com
    'smtp' => [
        'hote' => '',
        'port' => 465,
        'utilisateur' => 'portail@bts-sup-sallanches.com',
        'mot_de_passe' => '',
    ],

    // Clé de chiffrement des secrets de double authentification.
    // Générée SUR LE SERVEUR à l'installation, jamais transmise ni copiée ailleurs.
    'cle_chiffrement' => '',
];
