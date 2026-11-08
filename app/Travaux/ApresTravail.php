<?php

declare(strict_types=1);

namespace Portail\Travaux;

/** Exécutant qui a quelque chose à faire une fois toutes les étapes d'un travail terminées (récapitulatif). */
interface ApresTravail
{
    /** @param array<array-key, mixed> $parametres */
    public function apresTravail(int $travailId, array $parametres): void;
}
