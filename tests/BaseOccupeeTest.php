<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDOException;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Installation;
use Portail\Tests\Aides\DossierTemporaire;
use RuntimeException;

/**
 * Base occupée par le travailleur (incident du 14/11/2026, « database is locked ») :
 * en mode WAL, une page peut lire pendant qu'un autre programme écrit.
 */
final class BaseOccupeeTest extends TestCase
{
    public function testLInstallationPasseLaBaseEnWal(): void
    {
        $dossier = new DossierTemporaire();
        $dossier->ecrireConfig();

        $compteRendu = Installation::installer($dossier->racine);

        self::assertContains('Base en mode WAL (lecture possible pendant les travaux) ✓', $compteRendu);
        $pdo = Connexion::ouvrir($dossier->racine . '/data/portail.sqlite');
        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')?->fetchColumn());
        // Les fichiers de travail de SQLite ne sont pas plus ouverts que la base.
        foreach (['-wal', '-shm'] as $suffixe) {
            $fichier = $dossier->racine . '/data/portail.sqlite' . $suffixe;
            if (is_file($fichier)) {
                self::assertSame(0, fileperms($fichier) & 0077, $suffixe);
            }
        }
    }

    public function testUnePagePeutLirePendantQuUnTravailEcrit(): void
    {
        $dossier = new DossierTemporaire();
        $dossier->ecrireConfig();
        Installation::installer($dossier->racine);
        $chemin = $dossier->racine . '/data/portail.sqlite';

        $travailleur = Connexion::ouvrir($chemin);
        $travailleur->exec('BEGIN EXCLUSIVE');
        $travailleur->exec("UPDATE parametres SET valeur = 'en cours' WHERE cle = 'adresse_alertes'");

        $page = Connexion::ouvrir($chemin);
        $page->exec('PRAGMA busy_timeout = 200');
        $debut = microtime(true);
        $valeur = $page->query("SELECT valeur FROM parametres WHERE cle = 'adresse_alertes'")?->fetchColumn();

        self::assertSame('alertes@exemple.fr', $valeur, 'la page lit la dernière version enregistrée');
        self::assertLessThan(0.2, microtime(true) - $debut, 'la page n\'a pas attendu');
        $travailleur->exec('COMMIT');
    }

    public function testUneBaseOccupeeEstReconnue(): void
    {
        $dossier = new DossierTemporaire();
        $dossier->ecrireConfig();
        Installation::installer($dossier->racine);
        $chemin = $dossier->racine . '/data/portail.sqlite';

        $premier = Connexion::ouvrir($chemin);
        $premier->exec('BEGIN IMMEDIATE');
        $second = Connexion::ouvrir($chemin);
        $second->exec('PRAGMA busy_timeout = 50');
        try {
            $second->exec("UPDATE parametres SET valeur = 'x' WHERE cle = 'adresse_alertes'");
            self::fail('Écriture simultanée acceptée');
        } catch (PDOException $e) {
            self::assertTrue(Connexion::estOccupee($e));
        } finally {
            $premier->exec('ROLLBACK');
        }
        self::assertFalse(Connexion::estOccupee(new RuntimeException('database is locked')));
        self::assertFalse(Connexion::estOccupee(new PDOException('no such table: x')));
        self::assertSame(15000, (int) Connexion::ouvrir($chemin)->query('PRAGMA busy_timeout')?->fetchColumn());
    }
}
