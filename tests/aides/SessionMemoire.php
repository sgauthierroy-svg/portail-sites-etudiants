<?php

declare(strict_types=1);

namespace Portail\Tests\Aides;

use Portail\Web\StockageSession;

/** Session gardée en mémoire, pour simuler un navigateur dans les tests. */
final class SessionMemoire implements StockageSession
{
    /** @var array<string, mixed> */
    public array $donnees = [];
    public int $regenerations = 0;
    public int $destructions = 0;

    public function lire(): array
    {
        return $this->donnees;
    }

    public function ecrire(array $donnees): void
    {
        $this->donnees = $donnees;
    }

    public function regenerer(): void
    {
        ++$this->regenerations;
    }

    public function detruire(): void
    {
        $this->donnees = [];
        ++$this->destructions;
    }
}
