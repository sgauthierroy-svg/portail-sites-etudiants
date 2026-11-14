<?php

declare(strict_types=1);

namespace Portail\Sites;

use RuntimeException;

/**
 * Clôture de l'épreuve dans un /WP2 (V2, lot 4, paquet 3 ; décisions des 13 et 14/11/2026) :
 * site en lecture seule complète après l'épreuve, déconnexion après 1 h d'inactivité, gel levé
 * par un enseignant si besoin.
 *
 * Le portail écrit dans le site un module « à chargement obligatoire » (wp-content/mu-plugins :
 * WordPress le charge toujours, il ne se désactive pas depuis l'administration). Il n'agit que si
 * wp-config.php définit PORTAIL_LECTURE_SEULE (bloc des réglages du portail) : lever le gel, c'est
 * retirer cette constante. Il refuse les enregistrements à trois niveaux :
 *  1. formulaires et actions de l'administration, requêtes d'écriture de l'API REST (message clair) ;
 *  2. envoi de fichiers (médias) ;
 *  3. en dernier recours, toute requête d'écriture vers la base du site (filtre « query » de $wpdb),
 *     sauf ce qui sert à se connecter, se déconnecter et mesurer l'inactivité (session_tokens,
 *     portail_activite, réglages d'affichage de l'utilisateur), et les données temporaires de
 *     WordPress (transients, tâches planifiées).
 * DISALLOW_FILE_MODS (même bloc) interdit d'installer, modifier ou mettre à jour extensions et thèmes.
 * Les commandes WP-CLI du portail ne sont pas concernées.
 *
 * Références : documentation WordPress « Must Use Plugins », « wp-config.php » (DISALLOW_FILE_MODS),
 * filtres query, rest_pre_dispatch, wp_handle_upload_prefilter, xmlrpc_enabled ; actions admin_init, init, wp_login.
 */
final class EpreuveWordPress
{
    /** Chemin du module, relatif au dossier du site. */
    public const MODULE = 'wp-content/mu-plugins/portail-epreuve.php';

    /** Inactivité au-delà de laquelle un compte est déconnecté (décision du 13/11/2026 : 1 h). */
    public const INACTIVITE = 3600;

    public static function module(): string
    {
        return <<<'PHP'
<?php
/*
 * Plugin Name: Portail des sites étudiants – épreuve close
 * Description: Écrit par le portail des sites étudiants (Sup'Sallanches – BTS NDRC). Actif seulement après la clôture de l'épreuve : site en lecture seule, déconnexion après 1 h d'inactivité. Le gel se lève depuis le portail. Ne pas modifier.
 */
if (!defined('ABSPATH') || !defined('PORTAIL_LECTURE_SEULE') || PORTAIL_LECTURE_SEULE !== true) {
    return;
}
if (defined('WP_CLI') && WP_CLI) {
    return;
}

function portail_epreuve_message()
{
    return 'Site figé après l\'épreuve : rien ne peut être enregistré. Un enseignant peut lever le gel depuis le portail des sites étudiants.';
}

/* 3. Base de données : aucune écriture, sauf connexion, inactivité et données temporaires. */
add_filter('query', function ($requete) {
    global $wpdb;
    if (!is_string($requete) || preg_match('/^\s*(INSERT|REPLACE|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|RENAME)\b/i', $requete, $verbe) !== 1) {
        return $requete;
    }
    $verbe = strtoupper($verbe[1]);
    if (!in_array($verbe, array('INSERT', 'REPLACE', 'UPDATE', 'DELETE'), true)) {
        return '';
    }
    if ($verbe === 'UPDATE') {
        $trouve = preg_match('/^\s*UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)*`?(\w+)`?/i', $requete, $table);
    } elseif ($verbe === 'DELETE') {
        $trouve = preg_match('/\bFROM\s+`?(\w+)`?/i', $requete, $table);
    } else {
        $trouve = preg_match('/\bINTO\s+`?(\w+)`?/i', $requete, $table);
    }
    if ($trouve !== 1) {
        return '';
    }
    $table = $table[1];
    if ($table === $wpdb->usermeta) {
        // Rôles et droits des comptes : jamais.
        return (stripos($requete, $wpdb->prefix . 'capabilities') !== false || stripos($requete, $wpdb->prefix . 'user_level') !== false) ? '' : $requete;
    }
    if ($table === $wpdb->options) {
        if ($verbe === 'INSERT' || $verbe === 'REPLACE') {
            $noms = preg_match('/VALUES\s*\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/i', $requete, $nom) === 1 ? array($nom[1]) : array();
        } else {
            preg_match_all('/option_name`?\s*(?:=|LIKE)\s*\'((?:[^\'\\\\]|\\\\.)*)\'/i', $requete, $noms);
            $noms = $noms[1];
        }
        if ($noms === array()) {
            return '';
        }
        foreach ($noms as $nom) {
            $nom = str_replace('\\_', '_', $nom);
            if ($nom !== 'cron' && preg_match('/^_(site_)?transient_/', $nom) !== 1) {
                return '';
            }
        }
        return $requete;
    }
    // Tables du site (articles, pages, médias, commentaires, menus, comptes, extensions…) : jamais.
    return strpos($table, $wpdb->prefix) === 0 ? '' : $requete;
}, 0);

/* 1. Administration : aucun formulaire envoyé, aucune action par lien (activer, mettre à la corbeille…). */
add_action('admin_init', function () {
    if (wp_doing_ajax()) {
        return;
    }
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    $action2 = isset($_REQUEST['action2']) ? $_REQUEST['action2'] : '';
    $parLien = isset($_REQUEST['_wpnonce']) && (!in_array($action, array('', '-1', 'edit'), true) || !in_array($action2, array('', '-1'), true));
    if ((isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') || $parLien) {
        wp_die(esc_html(portail_epreuve_message()), 'Site figé', array('response' => 403, 'back_link' => true));
    }
}, 0);

/* 1. API REST (éditeur de blocs, médias) : lecture seulement. */
add_filter('rest_pre_dispatch', function ($resultat, $serveur, $demande) {
    if (!in_array($demande->get_method(), array('GET', 'HEAD', 'OPTIONS'), true)) {
        return new WP_Error('portail_site_fige', portail_epreuve_message(), array('status' => 403));
    }
    return $resultat;
}, 0, 3);

/* 2. Envoi de fichiers : refusé avant d'écrire sur le disque. */
add_filter('wp_handle_upload_prefilter', function ($fichier) {
    $fichier['error'] = portail_epreuve_message();
    return $fichier;
});
add_filter('xmlrpc_enabled', '__return_false');

/* Bandeau dans l'administration. */
add_action('admin_notices', function () {
    echo '<div class="notice notice-warning"><p><strong>Site figé après l\'épreuve (lecture seule).</strong> '
        . 'Vous pouvez tout consulter ; rien ne peut être enregistré. Déconnexion après 1 h d\'inactivité.</p></div>';
});

/* Déconnexion après 1 h d'inactivité (les appels automatiques de la page ne comptent pas comme activité). */
add_action('wp_login', function ($identifiant, $compte) {
    update_user_meta($compte->ID, 'portail_activite', time());
}, 10, 2);
add_action('init', function () {
    if (!is_user_logged_in()) {
        return;
    }
    $compte = get_current_user_id();
    $derniere = (int) get_user_meta($compte, 'portail_activite', true);
    if ($derniere > 0 && time() - $derniere > %INACTIVITE%) {
        wp_logout();
        if (wp_doing_ajax()) {
            wp_die('0', '', array('response' => 401));
        }
        wp_safe_redirect(wp_login_url());
        exit;
    }
    if (!wp_doing_ajax()) {
        update_user_meta($compte, 'portail_activite', time());
    }
}, 1);

PHP;
    }

    /** Contenu du module, avec la durée d'inactivité. */
    public static function contenuModule(): string
    {
        return str_replace('%INACTIVITE%', (string) self::INACTIVITE, self::module());
    }

    /** Dossier du sous-domaine, d'après CPanel, et seulement s'il est exactement à l'endroit attendu. */
    public static function racine(ServeurCpanel $cpanel, string $dossierPersonnel, string $identifiant): string
    {
        $racine = $cpanel->sousDomaines()[$identifiant] ?? null;
        $attendu = $dossierPersonnel . '/' . $identifiant . '.' . Releve::DOMAINE;
        if ($racine === null) {
            throw new RuntimeException('Sous-domaine introuvable dans CPanel : rien n\'a été fait.');
        }
        if (rtrim($racine, '/') !== $attendu || !is_dir($attendu) || is_link($attendu) || realpath($attendu) !== $attendu) {
            throw new RuntimeException('Dossier du sous-domaine inattendu (' . $racine . ') : refusé par prudence.');
        }

        return $attendu;
    }

    /** Préfixe des tables d'après wp-config.php (« $table_prefix = 'wp_'; »). */
    public static function prefixe(string $config): string
    {
        if (preg_match('/^\s*\$table_prefix\s*=\s*[\'"]([A-Za-z0-9_]{1,20})[\'"]\s*;/m', $config, $trouve) !== 1) {
            throw new RuntimeException('Préfixe des tables introuvable dans wp-config.php : rien n\'a été fait.');
        }

        return $trouve[1];
    }

    /**
     * Réécrit le bloc des réglages du portail dans wp-config.php, sans toucher au reste
     * (écriture dans un fichier voisin puis remplacement : jamais de fichier à moitié écrit).
     *
     * @param array{gele: bool, lecture_seule?: bool} $reglages
     */
    public static function ecrireReglages(string $fichier, array $reglages): void
    {
        if (!is_file($fichier) || is_link($fichier)) {
            throw new RuntimeException('wp-config.php introuvable : rien n\'a été changé.');
        }
        $contenu = file_get_contents($fichier);
        if ($contenu === false) {
            throw new RuntimeException('wp-config.php illisible : rien n\'a été changé.');
        }
        self::ecrireAtomique($fichier, ConfigWordPress::remplacerBloc($contenu, $reglages), 0600);
    }

    /** Écrit (ou réécrit) le module dans le site. */
    public static function poserModule(string $site): void
    {
        $dossier = $site . '/' . dirname(self::MODULE);
        if (is_link($dossier) || (!is_dir($dossier) && !mkdir($dossier, 0755, true))) {
            throw new RuntimeException('Dossier wp-content/mu-plugins impossible à créer : rien n\'a été changé.');
        }
        $fichier = $site . '/' . self::MODULE;
        if (is_link($fichier)) {
            throw new RuntimeException('Module du portail remplacé par un lien : refusé par prudence.');
        }
        self::ecrireAtomique($fichier, self::contenuModule(), 0644);
    }

    private static function ecrireAtomique(string $fichier, string $contenu, int $droits): void
    {
        $provisoire = $fichier . '.portail-' . bin2hex(random_bytes(4));
        if (file_put_contents($provisoire, $contenu, LOCK_EX) === false || !chmod($provisoire, $droits) || !rename($provisoire, $fichier)) {
            if (is_file($provisoire)) {
                unlink($provisoire); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
            throw new RuntimeException('Écriture de ' . basename($fichier) . ' impossible : rien n\'a été changé.');
        }
    }
}
