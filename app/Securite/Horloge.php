<?php

declare(strict_types=1);

namespace Portail\Securite;

/**
 * Heure courante, remplaçable dans les tests (blocages, expirations, codes).
 */
interface Horloge
{
    /** Secondes depuis le 01/01/1970 UTC. */
    public function maintenant(): int;
}
