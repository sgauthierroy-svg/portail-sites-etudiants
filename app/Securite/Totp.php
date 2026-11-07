<?php

declare(strict_types=1);

namespace Portail\Securite;

/**
 * Codes à 6 chiffres des applications d'authentification
 * (TOTP, RFC 6238, fondé sur HOTP, RFC 4226) : SHA-1, pas de 30 secondes.
 *
 * Paramètres par défaut, compris par toutes les applications courantes
 * (Google Authenticator, Microsoft Authenticator, FreeOTP, Aegis…).
 */
final class Totp
{
    public const PAS = 30;
    public const CHIFFRES = 6;
    /** Tolérance d'un pas avant et après (horloge du téléphone un peu décalée). */
    public const TOLERANCE = 1;

    /** Secret de 20 octets (160 bits, taille recommandée par la RFC 4226). */
    public static function genererSecret(): string
    {
        return random_bytes(20);
    }

    public static function pas(int $instant): int
    {
        return intdiv($instant, self::PAS);
    }

    /** Code HOTP pour un compteur donné (RFC 4226, section 5.3). */
    public static function hotp(string $secret, int $compteur, int $chiffres = self::CHIFFRES): string
    {
        $message = pack('J', $compteur);
        $empreinte = hash_hmac('sha1', $message, $secret, true);
        $decalage = ord($empreinte[19]) & 0x0F;
        $valeur = ((ord($empreinte[$decalage]) & 0x7F) << 24)
            | (ord($empreinte[$decalage + 1]) << 16)
            | (ord($empreinte[$decalage + 2]) << 8)
            | ord($empreinte[$decalage + 3]);

        return str_pad((string) ($valeur % (10 ** $chiffres)), $chiffres, '0', STR_PAD_LEFT);
    }

    public static function code(string $secret, int $instant): string
    {
        return self::hotp($secret, self::pas($instant));
    }

    /**
     * Vérifie un code saisi.
     *
     * @param int|null $dernierPasUtilise Pas du dernier code accepté pour ce compte :
     *                                    un code déjà utilisé, ou plus ancien, est refusé (pas de rejeu).
     * @return int|null Pas accepté (à enregistrer), ou null si le code est refusé
     */
    public static function verifier(string $secret, string $saisi, int $instant, ?int $dernierPasUtilise): ?int
    {
        $saisi = str_replace(' ', '', $saisi);
        if (preg_match('/^[0-9]{6}$/', $saisi) !== 1) {
            return null;
        }
        $courant = self::pas($instant);
        $accepte = null;
        // Toutes les positions sont comparées, en temps constant, sans sortie anticipée.
        for ($ecart = -self::TOLERANCE; $ecart <= self::TOLERANCE; ++$ecart) {
            $pas = $courant + $ecart;
            if (hash_equals(self::hotp($secret, $pas), $saisi) && ($dernierPasUtilise === null || $pas > $dernierPasUtilise)) {
                $accepte = $pas;
            }
        }

        return $accepte;
    }

    /** Adresse otpauth:// lue par les applications (clé saisie à la main ou QR code). */
    public static function adresse(string $secret, string $compte, string $emetteur = 'Portail Sup\'Sallanches'): string
    {
        return 'otpauth://totp/' . rawurlencode($emetteur . ':' . $compte)
            . '?secret=' . Base32::encoder($secret)
            . '&issuer=' . rawurlencode($emetteur)
            . '&algorithm=SHA1&digits=' . self::CHIFFRES . '&period=' . self::PAS;
    }
}
