<?php

declare(strict_types=1);

namespace Portail\Securite;

use InvalidArgumentException;

/**
 * Encodage Base32 (RFC 4648), format des clés des applications d'authentification.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encoder(string $octets): string
    {
        $bits = '';
        foreach (str_split($octets) as $octet) {
            $bits .= str_pad(decbin(ord($octet)), 8, '0', STR_PAD_LEFT);
        }
        $resultat = '';
        foreach (str_split($bits, 5) as $morceau) {
            $resultat .= self::ALPHABET[(int) bindec(str_pad($morceau, 5, '0'))];
        }

        return $resultat;
    }

    public static function decoder(string $texte): string
    {
        $texte = strtoupper(str_replace([' ', '='], '', $texte));
        if ($texte === '' || strspn($texte, self::ALPHABET) !== strlen($texte)) {
            throw new InvalidArgumentException('Texte Base32 invalide.');
        }
        $bits = '';
        foreach (str_split($texte) as $caractere) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $caractere)), 5, '0', STR_PAD_LEFT);
        }
        $octets = '';
        foreach (str_split($bits, 8) as $morceau) {
            if (strlen($morceau) === 8) {
                $octets .= chr((int) bindec($morceau));
            }
        }

        return $octets;
    }
}
