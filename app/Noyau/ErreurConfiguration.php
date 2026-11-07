<?php

declare(strict_types=1);

namespace Portail\Noyau;

use RuntimeException;

/**
 * Configuration absente, incomplète ou mal protégée : le portail refuse de démarrer.
 */
final class ErreurConfiguration extends RuntimeException
{
}
