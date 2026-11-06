<?php

declare(strict_types=1);

namespace Portail\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Le modèle de configuration versionné ne doit contenir aucun secret rempli.
 */
final class ModeleConfigurationTest extends TestCase
{
    public function testLeModeleNeContientAucunSecretRempli(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/config.exemple.php';

        $secretsRemplis = [];
        array_walk_recursive(
            $config,
            static function (mixed $valeur, int|string $cle) use (&$secretsRemplis): void {
                if (preg_match('/mot_de_passe|cle|secret|jeton|token/i', (string) $cle) === 1 && $valeur !== '') {
                    $secretsRemplis[] = (string) $cle;
                }
            }
        );

        self::assertSame([], $secretsRemplis, 'Le modèle de configuration contient un secret rempli.');
    }

    public function testLeModeleNePointeQueVersLeDossierDuPortail(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/config.exemple.php';

        self::assertStringStartsWith('/home/jafl3067/portail/', (string) $config['dossier_donnees'] . '/');
        self::assertStringStartsWith('/home/jafl3067/portail/', (string) $config['dossier_journaux'] . '/');
        self::assertStringStartsWith('https://', (string) $config['url_portail']);
    }
}
