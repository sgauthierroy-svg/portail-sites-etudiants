<?php

declare(strict_types=1);

namespace Portail\Sites;

use RuntimeException;

/**
 * Fichier wp-config.php des sites WordPress installés par le portail (V2, lot 4).
 *
 * Écrit par le portail, sans passer par WP-CLI : le mot de passe de la base n'apparaît
 * ainsi dans aucune ligne de commande. Les réglages que le portail change ensuite (gel des
 * mises à jour, lecture seule d'une épreuve close) sont rangés entre deux repères : le portail
 * ne réécrit que ce bloc, sans relire ni recopier le mot de passe.
 *
 * Constantes : documentation WordPress, « Editing wp-config.php »
 * (developer.wordpress.org/advanced-administration/wordpress/wp-config/).
 */
final class ConfigWordPress
{
    public const DEBUT_BLOC = '// === Réglages gérés par le portail des sites étudiants (ne pas modifier à la main) ===';
    public const FIN_BLOC = '// === Fin des réglages gérés par le portail ===';

    /**
     * @param array{base: string, utilisateur: string, mot_de_passe: string, prefixe: string} $base
     * @param array{gele: bool} $reglages
     */
    public static function contenu(#[\SensitiveParameter] array $base, array $reglages): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,20}$/D', $base['prefixe']) !== 1) {
            throw new RuntimeException('Préfixe des tables WordPress refusé.');
        }
        $cles = '';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $nom) {
            $cles .= "define('" . $nom . "', '" . bin2hex(random_bytes(32)) . "');\n";
        }

        return "<?php\n"
            . "// Écrit par le portail des sites étudiants (Sup'Sallanches – BTS NDRC).\n"
            . "define('DB_NAME', " . var_export($base['base'], true) . ");\n"
            . "define('DB_USER', " . var_export($base['utilisateur'], true) . ");\n"
            . "define('DB_PASSWORD', " . var_export($base['mot_de_passe'], true) . ");\n"
            . "define('DB_HOST', 'localhost');\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "define('DB_COLLATE', '');\n"
            . $cles
            . '$table_prefix = ' . var_export($base['prefixe'], true) . ";\n"
            . "define('WP_DEBUG', false);\n"
            . "define('FORCE_SSL_ADMIN', true);\n\n"
            . self::bloc($reglages) . "\n\n"
            . "if (!defined('ABSPATH')) {\n    define('ABSPATH', __DIR__ . '/');\n}\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";
    }

    /**
     * Remplace le bloc des réglages gérés ; l'ajoute avant le chargement de WordPress s'il manque
     * (wp-config.php d'un site copié à la main). Le reste du fichier n'est pas touché.
     *
     * @param array{gele: bool} $reglages
     */
    public static function remplacerBloc(string $contenu, array $reglages): string
    {
        $debut = strpos($contenu, self::DEBUT_BLOC);
        $fin = strpos($contenu, self::FIN_BLOC);
        if ($debut !== false && $fin !== false && $fin > $debut) {
            return substr($contenu, 0, $debut) . self::bloc($reglages) . substr($contenu, $fin + strlen(self::FIN_BLOC));
        }
        if ($debut !== false || $fin !== false) {
            throw new RuntimeException('Bloc des réglages du portail abîmé dans wp-config.php : corrigez-le à la main.');
        }
        $chargement = preg_match('/^.*wp-settings\.php.*$/m', $contenu, $trouve, PREG_OFFSET_CAPTURE);
        $position = $chargement === 1 ? $trouve[0][1] : false;
        if ($position === false) {
            throw new RuntimeException('wp-config.php sans chargement de WordPress : fichier inattendu, rien n\'a été changé.');
        }
        // Avant la ligne de chargement, et avant le « if (!defined('ABSPATH')) » qui la précède souvent.
        $avant = substr($contenu, 0, $position);
        $abspath = strrpos($avant, "if ( ! defined( 'ABSPATH' ) )");
        $abspath = $abspath === false ? strrpos($avant, "if (!defined('ABSPATH'))") : $abspath;
        if ($abspath !== false) {
            $position = $abspath;
        }

        return substr($contenu, 0, $position) . self::bloc($reglages) . "\n\n" . substr($contenu, $position);
    }

    /** @param array{gele: bool} $reglages */
    public static function bloc(array $reglages): string
    {
        $lignes = [self::DEBUT_BLOC];
        if ($reglages['gele']) {
            // Version gelée : aucune mise à jour automatique (WordPress, extensions, thèmes).
            $lignes[] = "define('AUTOMATIC_UPDATER_DISABLED', true);";
            $lignes[] = "define('WP_AUTO_UPDATE_CORE', false);";
        }
        $lignes[] = self::FIN_BLOC;

        return implode("\n", $lignes);
    }

    /** Le site est-il gelé d'après son fichier ? (null : bloc absent) */
    public static function estGele(string $contenu): ?bool
    {
        $debut = strpos($contenu, self::DEBUT_BLOC);
        $fin = strpos($contenu, self::FIN_BLOC);
        if ($debut === false || $fin === false || $fin < $debut) {
            return null;
        }

        return str_contains(substr($contenu, $debut, $fin - $debut), "'AUTOMATIC_UPDATER_DISABLED', true");
    }

    /**
     * .htaccess d'un WordPress dans un sous-dossier : bloc standard de WordPress
     * (documentation « htaccess », section Basic WP) adapté au dossier.
     */
    public static function htaccess(string $dossier): string
    {
        if (preg_match('#^/[A-Za-z][A-Za-z0-9]*$#D', $dossier) !== 1) {
            throw new RuntimeException('Dossier refusé.');
        }

        return "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\n"
            . "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
            . "RewriteBase " . $dossier . "/\nRewriteRule ^index\\.php$ - [L]\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule . " . $dossier . "/index.php [L]\n</IfModule>\n# END WordPress\n";
    }
}
