<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Installation;
use Portail\Noyau\ErreurConfiguration;
use Portail\Tests\Aides\DossierTemporaire;

final class InstallationTest extends TestCase
{
    public function testLInstallationCreeLesDossiersPrivesEtLaBase(): void
    {
        $dossier = new DossierTemporaire();
        $dossier->ecrireConfig();

        $compteRendu = Installation::installer($dossier->racine);

        foreach (Installation::DOSSIERS_PRIVES as $relatif) {
            self::assertSame(0700, fileperms($dossier->racine . '/' . $relatif) & 0777, $relatif);
        }
        self::assertSame(0600, fileperms($dossier->racine . '/data/portail.sqlite') & 0777);
        self::assertContains('Base : version(s) 1, 2, 3, 4 appliquée(s) ✓', $compteRendu);

        $pdo = Connexion::ouvrir($dossier->racine . '/data/portail.sqlite');
        $parametres = $pdo->query('SELECT cle, valeur FROM parametres ORDER BY cle')?->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('alertes@exemple.fr', $parametres['adresse_alertes'] ?? null);
        self::assertContains('prova', json_decode((string) ($parametres['sites_proteges'] ?? ''), true));
        self::assertContains('portail', json_decode((string) ($parametres['sites_proteges'] ?? ''), true));
    }

    public function testRelancerLInstallationNEcraseRien(): void
    {
        $dossier = new DossierTemporaire();
        $dossier->ecrireConfig();
        Installation::installer($dossier->racine);

        $pdo = Connexion::ouvrir($dossier->racine . '/data/portail.sqlite');
        $pdo->exec("UPDATE parametres SET valeur = 'informatique@ecs-sallanches.fr' WHERE cle = 'adresse_alertes'");

        $compteRendu = Installation::installer($dossier->racine);
        self::assertContains('Base déjà à jour ✓', $compteRendu);
        self::assertSame(
            'informatique@ecs-sallanches.fr',
            $pdo->query("SELECT valeur FROM parametres WHERE cle = 'adresse_alertes'")?->fetchColumn()
        );
    }

    public function testSansConfigurationLInstallationSArreteAvantLaBase(): void
    {
        $dossier = new DossierTemporaire();
        try {
            Installation::installer($dossier->racine);
            self::fail('Installation lancée sans configuration.');
        } catch (ErreurConfiguration) {
            self::assertFileDoesNotExist($dossier->racine . '/data/portail.sqlite');
        }
    }
}
