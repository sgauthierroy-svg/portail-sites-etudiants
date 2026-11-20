<?php

declare(strict_types=1);

namespace Portail\Sites;

/**
 * Module de gel de version des WordPress gérés par le portail (constat du 20/11/2026 : le gel du portail arrêtait
 * les mises à jour automatiques, mais laissait le bouton « Mettre à jour WordPress » à l'administrateur du site,
 * par exemple au candidat préconnecté sur son /WP2), et lecture de l'état de gel d'un site pour le relevé.
 *
 * Le module est posé dans tous les WordPress que le portail installe ; il n'agit que si le bloc du portail de
 * wp-config.php définit PORTAIL_GEL (« total » ou « correctifs », voir ConfigWordPress). Il ne fait que ce que
 * WordPress prévoit par ses filtres (code de WordPress : wp-admin/includes/class-core-upgrader.php,
 * should_update_to_version ; class-wp-automatic-updater.php, is_disabled, send_email,
 * send_core_update_notification_email) ; il ne touche ni aux extensions installées, ni à leur mise à jour
 * à la main (décision du 07/11/2026 : les étudiants installent et mettent à jour leurs extensions).
 */
final class GelWordPress
{
    /** Chemin du module, relatif au dossier du site. */
    public const MODULE = 'wp-content/mu-plugins/portail-gel.php';

    /** Gels posés par les anciens scripts (gel_wordpress.sh, 07/11/2026 ; gel_wp_session.sh, 20/11/2026). */
    public const MODULES_SCRIPTS = [
        'wp-content/mu-plugins/gel-version-examen.php',
        'wp-content/mu-plugins/gel-version-session.php',
    ];

    /** États lus par le relevé (colonne gel_wordpress de releve_sites). */
    public const ETATS = [
        'total' => 'aucune mise à jour',
        'correctifs' => 'correctifs seulement',
        'incomplet' => 'bouton de mise à jour encore visible',
        'aucun' => 'version non gelée',
    ];

    public static function module(): string
    {
        return <<<'PHP'
<?php
/*
 * Plugin Name: Portail des sites étudiants – version gelée
 * Description: Écrit par le portail des sites étudiants (Sup'Sallanches – BTS NDRC). Actif seulement si la version est gelée par le portail : pas de version supérieure, pas de bouton « Mettre à jour WordPress », extensions et thèmes sans mise à jour automatique. Ne pas modifier.
 */
if (!defined('ABSPATH') || !defined('PORTAIL_GEL')) {
    return;
}

/* Personne ne lance la mise à jour de WordPress depuis le tableau de bord (bouton et avis retirés). */
add_filter('map_meta_cap', function ($droits, $droit) {
    return $droit === 'update_core' ? array('do_not_allow') : $droits;
}, 10, 2);

/* Jamais de version supérieure ; correctifs de la même version seulement en gel « correctifs ». */
add_filter('allow_major_auto_core_updates', '__return_false');
add_filter('allow_minor_auto_core_updates', function () {
    return PORTAIL_GEL === 'correctifs';
});

/* Extensions et thèmes : plus de mise à jour automatique (la mise à jour à la main reste possible). */
add_filter('auto_update_plugin', '__return_false');
add_filter('auto_update_theme', '__return_false');

/* Pas d'e-mail « nouvelle version disponible » ni « site mis à jour » ; les échecs restent signalés. */
add_filter('send_core_update_notification_email', '__return_false');
add_filter('auto_core_update_send_email', function ($envoyer, $type) {
    return $type === 'success' ? false : $envoyer;
}, 10, 2);

PHP;
    }

    /** Écrit (ou réécrit) le module dans le site ; sans PORTAIL_GEL dans wp-config.php, il ne fait rien. */
    public static function poserModule(string $site): void
    {
        OutilsWordPress::poserModule($site, self::MODULE, self::module());
    }

    /**
     * État de gel d'un WordPress, lu dans ses fichiers (jamais exécutés) : une clé de ETATS.
     * Gel du portail sans le module : « incomplet ». Ancien script : son module suffit.
     */
    public static function etat(string $site): string
    {
        $fichier = $site . '/wp-config.php';
        $config = is_file($fichier) && !is_link($fichier) ? file_get_contents($fichier, false, null, 0, 262144) : false;
        $mode = is_string($config) ? ConfigWordPress::modeGel($config) : null;
        if ($mode !== null) {
            $module = $site . '/' . self::MODULE;

            return is_file($module) && !is_link($module) ? $mode : 'incomplet';
        }
        foreach (self::MODULES_SCRIPTS as $ancien) {
            $chemin = $site . '/' . $ancien;
            if (is_file($chemin) && !is_link($chemin)) {
                $contenu = file_get_contents($chemin, false, null, 0, 65536);
                $total = is_string($contenu) && str_contains($contenu, 'automatic_updater_disabled');

                return $total ? 'total' : 'correctifs';
            }
        }

        return 'aucun';
    }
}
