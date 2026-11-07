<?php

declare(strict_types=1);

namespace Portail\Web;

use RuntimeException;

/**
 * Sessions PHP, avec les réglages appliqués par ReglagesPhp
 * (cookie __Host-, Secure, HttpOnly, SameSite=Strict, mode strict).
 */
final class SessionPhp implements StockageSession
{
    public function __construct()
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            throw new RuntimeException('Session impossible à démarrer.');
        }
    }

    public function lire(): array
    {
        /** @var array<string, mixed> $donnees Les clés de $_SESSION sont toujours des textes. */
        $donnees = $_SESSION;

        return $donnees;
    }

    public function ecrire(array $donnees): void
    {
        $_SESSION = $donnees;
    }

    public function regenerer(): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Renouvellement de session impossible.');
        }
    }

    public function detruire(): void
    {
        $_SESSION = [];
        session_destroy();
        $nom = session_name();
        setcookie($nom === false ? '__Host-portail' : $nom, '', [
            'expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }
}
