<?php

declare(strict_types=1);

namespace Portail\Sites;

/**
 * Pose d'un certificat https reconnu par les navigateurs sur un sous-domaine (remplaçable dans les tests).
 * Lève une exception si le certificat n'a pas pu être obtenu ou installé.
 */
interface Certificats
{
    public function poser(string $sousDomaine, string $racine): void;
}
