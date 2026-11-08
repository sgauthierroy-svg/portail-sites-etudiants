<?php

declare(strict_types=1);

namespace Portail\Web;

/**
 * En-têtes de sécurité envoyés avec chaque réponse.
 * (Le renvoi vers https et HSTS sont assurés par le .htaccess du serveur.)
 */
final class EnTetes
{
    /** @return array<string, string> */
    public static function securite(): array
    {
        return [
            // Seules les ressources du portail lui-même sont chargées : ni script
            // extérieur, ni script écrit dans la page, ni affichage dans un cadre.
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
                . "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            // same-origin et non no-referrer : avec no-referrer, le navigateur envoie
            // « Origin: null » sur les formulaires du portail lui-même, que la
            // vérification d'origine refuse (norme Fetch ; constaté le 08/11/2026).
            // Aucune adresse du portail n'est transmise aux autres sites.
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            // Aucune page du portail n'est gardée en cache (données personnelles).
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ];
    }

    public static function envoyer(): void
    {
        header_remove('X-Powered-By');
        foreach (self::securite() as $nom => $valeur) {
            header($nom . ': ' . $valeur);
        }
    }
}
