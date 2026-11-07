<?php

declare(strict_types=1);

namespace Portail\Securite;

final class HorlogeSysteme implements Horloge
{
    public function maintenant(): int
    {
        return time();
    }
}
