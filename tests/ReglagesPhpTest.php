<?php

declare(strict_types=1);

namespace Portail\Tests;

use PHPUnit\Framework\TestCase;
use Portail\Noyau\ErreurConfiguration;
use Portail\Noyau\ReglagesPhp;
use Portail\Tests\Aides\DossierTemporaire;

/**
 * Les réglages sont appliqués dans un processus séparé : une fois le
 * cloisonnement actif, le processus ne peut plus lire hors du portail.
 */
final class ReglagesPhpTest extends TestCase
{
    public function testLesReglagesSontAppliquesEtLeCloisonnementFonctionne(): void
    {
        $portail = new DossierTemporaire();
        $voisin = new DossierTemporaire(); // joue le rôle d'un site étudiant
        file_put_contents($portail->racine . '/data/temoin.txt', 'dedans');
        file_put_contents($voisin->racine . '/data/wp-config.php', 'secret du voisin');

        $resultat = $this->sonder($portail->racine, $voisin->racine . '/data/wp-config.php');

        self::assertSame('0', $resultat['valeurs']['display_errors']);
        self::assertSame('1', $resultat['valeurs']['session.cookie_secure']);
        self::assertSame('1', $resultat['valeurs']['session.cookie_httponly']);
        self::assertSame('Strict', $resultat['valeurs']['session.cookie_samesite']);
        self::assertSame('1800', $resultat['valeurs']['session.gc_maxlifetime']);
        self::assertSame($portail->racine . '/data/sessions', $resultat['valeurs']['session.save_path']);
        self::assertSame($portail->racine . '/logs/php_errors.log', $resultat['valeurs']['error_log']);
        self::assertSame($portail->racine . '/', $resultat['valeurs']['open_basedir']);

        self::assertSame('dedans', $resultat['lecture_dedans'], 'Le portail doit lire ses propres fichiers.');
        self::assertFalse($resultat['lecture_dehors'], 'Le portail ne doit rien lire hors de ~/portail.');
        self::assertFalse($resultat['ecriture_dehors'], 'Le portail ne doit rien écrire hors de ~/portail.');
        self::assertFileDoesNotExist($voisin->racine . '/data/intrus.txt');
    }

    public function testUneRacineInvalideEstRefusee(): void
    {
        $this->expectException(ErreurConfiguration::class);
        ReglagesPhp::appliquer('portail-relatif');
    }

    /** @return array{valeurs: array<string, string|false>, lecture_dedans: string|false, lecture_dehors: string|false, ecriture_dehors: int|false} */
    private function sonder(string $racine, string $dehors): array
    {
        $commande = [PHP_BINARY, __DIR__ . '/aides/sonde_reglages.php', $racine, $dehors];
        $processus = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
        self::assertIsResource($processus);
        $sortie = (string) stream_get_contents($tubes[1]);
        $erreurs = (string) stream_get_contents($tubes[2]);
        fclose($tubes[1]);
        fclose($tubes[2]);
        self::assertSame(0, proc_close($processus), 'Sonde en échec : ' . $erreurs . $sortie);

        /** @var array{valeurs: array<string, string|false>, lecture_dedans: string|false, lecture_dehors: string|false, ecriture_dehors: int|false} */
        return json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
    }
}
