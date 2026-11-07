<?php

declare(strict_types=1);

namespace Portail\Tests;

use PHPUnit\Framework\TestCase;
use Portail\Noyau\Config;
use Portail\Noyau\ErreurConfiguration;
use Portail\Tests\Aides\DossierTemporaire;

final class ConfigTest extends TestCase
{
    private DossierTemporaire $dossier;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
    }

    public function testUneConfigurationValideEstLue(): void
    {
        $config = Config::charger($this->dossier->ecrireConfig());
        self::assertSame('alertes@exemple.fr', $config->adresseAlertes);
        self::assertSame(32, strlen($config->cleBinaire()));
    }

    public function testUnFichierLisibleParLesAutresComptesEstRefuse(): void
    {
        $this->expectException(ErreurConfiguration::class);
        $this->expectExceptionMessage('droits 600');
        Config::charger($this->dossier->ecrireConfig([], 0644));
    }

    public function testUnFichierAbsentEstRefuse(): void
    {
        $this->expectException(ErreurConfiguration::class);
        Config::charger($this->dossier->racine . '/data/absent.php');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public function configurationsInvalides(): array
    {
        return [
            'adresse en http' => [['url_portail' => 'http://portail.bts-sup-sallanches.com'], 'url_portail'],
            'clé vide (modèle non rempli)' => [['cle_chiffrement' => ''], 'cle_chiffrement'],
            'clé trop courte' => [['cle_chiffrement' => 'abcd'], 'cle_chiffrement'],
            'adresse d\'alerte invalide' => [['adresse_alertes' => 'pas-une-adresse'], 'adresse_alertes'],
            'dossier relatif' => [['dossier_donnees' => 'data'], 'dossier_donnees'],
            'mot de passe smtp vide' => [['smtp' => ['hote' => 'h', 'port' => 465, 'utilisateur' => 'p@exemple.fr', 'mot_de_passe' => '']], 'smtp'],
            'port en texte' => [['smtp' => ['hote' => 'h', 'port' => '465', 'utilisateur' => 'p@exemple.fr', 'mot_de_passe' => 'x']], 'smtp.port'],
        ];
    }

    /**
     * @dataProvider configurationsInvalides
     * @param array<string, mixed> $remplacements
     */
    public function testUneValeurInvalideEstRefuseeAvecUnMessageClair(array $remplacements, string $motif): void
    {
        $this->expectException(ErreurConfiguration::class);
        $this->expectExceptionMessage($motif);
        Config::charger($this->dossier->ecrireConfig($remplacements));
    }

    public function testLeModeleVersionneEstRefuseTelQuel(): void
    {
        // Le modèle (cases secrètes vides) ne doit jamais pouvoir servir de configuration réelle.
        $copie = $this->dossier->racine . '/data/modele.php';
        copy(__DIR__ . '/../config/config.exemple.php', $copie);
        chmod($copie, 0600);
        $this->expectException(ErreurConfiguration::class);
        Config::charger($copie);
    }
}
