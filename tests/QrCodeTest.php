<?php

declare(strict_types=1);

namespace Portail\Tests;

use PHPUnit\Framework\TestCase;
use Portail\Securite\QrCode;
use Portail\Securite\Totp;

/**
 * QR code d'activation. Les empreintes attendues viennent de la bibliothèque
 * Python « qrcode » 8.x (référence), avec le même masque imposé ; le 08/11/2026,
 * 60 cas de 1 à 2 300 octets et les 8 masques ont été comparés module par module
 * (60/60 identiques) et 7 QR codes au masque automatique relus par le décodeur
 * d'OpenCV (7/7).
 */
final class QrCodeTest extends TestCase
{
    /** @return array<string, array{string, int, int, string}> données, masque, version, empreinte */
    public static function references(): array
    {
        return [
            'lien d\'activation' => ['otpauth://totp/Portail%20Sup%27Sallanches%3Asgauthierroy?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'
                . '&issuer=Portail%20Sup%27Sallanches&algorithm=SHA1&digits=6&period=30', 2, 9,
                '5e5505e46acba1d8b413381e5dc4883058795ae91d1e24ed45640ff45307b596'],
            'texte court' => ['BTS NDRC', 5, 1, '5b929ec363a9273ea9403bfc6ac2a5a5ec2ccf92a94cad3b80e95a4c60069604'],
            '400 octets' => [str_repeat('x', 400), 7, 15, '0d998cc2105d862c5658e6ab5900b0a447e7ab91d40e29a1aa3c1d30df071dc9'],
        ];
    }

    /** @dataProvider references */
    public function testLeQrCodeEstIdentiqueALaReference(string $donnees, int $masque, int $version, string $empreinte): void
    {
        $matrice = QrCode::matrice($donnees, $masque);
        self::assertCount($version * 4 + 17, $matrice);
        $texte = implode("\n", array_map(static fn (array $ligne): string => implode('', array_map(static fn (bool $b): string => $b ? '1' : '0', $ligne)), $matrice));
        self::assertSame($empreinte, hash('sha256', $texte));
    }

    public function testLeLienDActivationTientDansUnQrCodeLisibleAuTelephone(): void
    {
        $adresse = Totp::adresse(random_bytes(20), 'un.identifiant.long');
        $matrice = QrCode::matrice($adresse);
        self::assertLessThanOrEqual(10 * 4 + 17, count($matrice), 'Version 10 au plus : modules assez gros à l\'écran');
    }

    public function testLeDessinNeContientPasLeSecretEnClair(): void
    {
        $svg = QrCode::svg('otpauth://totp/x?secret=JBSWY3DPEHPK3PXP', 'QR code d\'activation');
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $svg);
        self::assertStringNotContainsString('otpauth', $svg);
        self::assertStringNotContainsString('<script', $svg);
        self::assertStringContainsString('aria-label="QR code d&apos;activation"', $svg);
    }

    public function testDesDonneesTropLonguesSontRefusees(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::matrice(str_repeat('x', 2400));
    }
}
