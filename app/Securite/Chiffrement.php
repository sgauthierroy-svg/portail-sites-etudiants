<?php

declare(strict_types=1);

namespace Portail\Securite;

use RuntimeException;

/**
 * Chiffrement des secrets de double authentification : AES-256-GCM (OpenSSL ;
 * l'extension sodium est absente du serveur).
 *
 * - clé de 32 octets, lue dans config.php, jamais dans la base ;
 * - vecteur d'initialisation aléatoire de 12 octets, différent à chaque chiffrement ;
 * - le « contexte » (ex. « compte:12 ») est authentifié : un secret recopié
 *   sur la ligne d'un autre compte ne se déchiffre pas ;
 * - toute altération du texte chiffré est détectée et refusée.
 */
final class Chiffrement
{
    private const ALGO = 'aes-256-gcm';
    private const VERSION = 'v1';

    public function __construct(private readonly string $cle)
    {
        if (strlen($cle) !== 32) {
            throw new RuntimeException('La clé de chiffrement doit faire 32 octets.');
        }
    }

    public function chiffrer(string $clair, string $contexte): string
    {
        $iv = random_bytes(12);
        $etiquette = '';
        $chiffre = openssl_encrypt($clair, self::ALGO, $this->cle, OPENSSL_RAW_DATA, $iv, $etiquette, $contexte, 16);
        if ($chiffre === false) {
            throw new RuntimeException('Chiffrement impossible.');
        }

        return self::VERSION . ':' . base64_encode($iv . $etiquette . $chiffre);
    }

    public function dechiffrer(string $texte, string $contexte): string
    {
        [$version, $donnees] = array_pad(explode(':', $texte, 2), 2, '');
        $brut = base64_decode($donnees, true);
        if ($version !== self::VERSION || $brut === false || strlen($brut) < 28) {
            throw new RuntimeException('Secret chiffré illisible.');
        }
        $clair = openssl_decrypt(substr($brut, 28), self::ALGO, $this->cle, OPENSSL_RAW_DATA, substr($brut, 0, 12), substr($brut, 12, 16), $contexte);
        if ($clair === false) {
            throw new RuntimeException('Secret chiffré altéré ou clé incorrecte.');
        }

        return $clair;
    }
}
