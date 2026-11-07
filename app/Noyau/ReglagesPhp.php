<?php

declare(strict_types=1);

namespace Portail\Noyau;

/**
 * Réglages de sécurité PHP propres au portail.
 *
 * Sur le serveur (PHP via LiteSpeed/mod_lsapi), les fichiers .user.ini sont
 * ignorés : mesure du 06/11/2026. Le portail applique donc lui-même ces
 * réglages, au tout début de chaque requête. Ils ne touchent que les pages
 * du portail, par construction.
 *
 * Si un seul réglage ne peut pas être appliqué, le portail refuse de
 * continuer (pas de fonctionnement dégradé en silence).
 */
final class ReglagesPhp
{
    /** Durée maximale d'une session sans activité, en secondes (30 min). */
    public const INACTIVITE_MAX = 1800;

    /**
     * @param string $racine Dossier ~/portail (contient app, data, logs, public)
     * @return array<string, string> Réglages appliqués
     */
    public static function reglages(string $racine): array
    {
        return [
            'display_errors' => '0',
            'display_startup_errors' => '0',
            'log_errors' => '1',
            'error_log' => $racine . '/logs/php_errors.log',
            'error_reporting' => (string) E_ALL,
            'html_errors' => '0',
            'session.save_path' => $racine . '/data/sessions',
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
            'session.cookie_secure' => '1',
            'session.cookie_httponly' => '1',
            'session.cookie_samesite' => 'Strict',
            'session.cookie_lifetime' => '0',
            'session.gc_maxlifetime' => (string) self::INACTIVITE_MAX,
            'session.gc_probability' => '1',
            'session.gc_divisor' => '100',
            'session.sid_length' => '48',
            'session.sid_bits_per_character' => '6',
            // Préfixe __Host- : cookie limité à ce sous-domaine, en https, sans domaine élargi.
            'session.name' => '__Host-portail',
            'session.cookie_path' => '/',
            'session.cookie_domain' => '',
            'session.cache_limiter' => 'nocache',
            'zend.exception_ignore_args' => '1',
            // En dernier : à partir de là, aucun fichier hors du portail n'est lisible.
            'open_basedir' => $racine . '/',
        ];
    }

    public static function appliquer(string $racine): void
    {
        if (!str_starts_with($racine, '/') || !is_dir($racine)) {
            throw new ErreurConfiguration('Dossier racine du portail invalide.');
        }
        foreach (self::reglages($racine) as $nom => $valeur) {
            if (ini_set($nom, $valeur) === false || ini_get($nom) !== $valeur) {
                throw new ErreurConfiguration('Réglage PHP impossible à appliquer : ' . $nom . '.');
            }
        }
    }
}
