<?php

declare(strict_types=1);

namespace Portail\Tests\Aides;

use Portail\Securite\Horloge;

final class HorlogeFixe implements Horloge
{
    public function __construct(public int $instant = 1_800_000_000)
    {
    }

    public function maintenant(): int
    {
        return $this->instant;
    }

    public function avancer(int $secondes): void
    {
        $this->instant += $secondes;
    }
}
