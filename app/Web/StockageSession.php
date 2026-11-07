<?php

declare(strict_types=1);

namespace Portail\Web;

/**
 * Support des données de session : sessions PHP sur le serveur
 * (fichiers dans ~/portail/data/sessions), mémoire dans les tests.
 */
interface StockageSession
{
    /** @return array<string, mixed> */
    public function lire(): array;

    /** @param array<string, mixed> $donnees */
    public function ecrire(array $donnees): void;

    /** Change l'identifiant de session (contre la fixation de session). */
    public function regenerer(): void;

    public function detruire(): void;
}
