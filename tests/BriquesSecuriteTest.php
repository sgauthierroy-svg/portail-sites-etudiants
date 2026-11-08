<?php

declare(strict_types=1);

namespace Portail\Tests;

use PHPUnit\Framework\TestCase;
use Portail\Securite\Base32;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Securite\Totp;
use RuntimeException;

final class BriquesSecuriteTest extends TestCase
{
    private const SECRET_RFC = '12345678901234567890';

    /** Vecteurs officiels de la RFC 4226, annexe D. */
    public function testLesVecteursOfficielsHotpPassent(): void
    {
        $attendus = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        foreach ($attendus as $compteur => $code) {
            self::assertSame($code, Totp::hotp(self::SECRET_RFC, $compteur), 'compteur ' . $compteur);
        }
    }

    /** Vecteurs officiels de la RFC 6238, annexe B (SHA-1, 8 chiffres). */
    public function testLesVecteursOfficielsTotpPassent(): void
    {
        $attendus = [59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471',
            1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'];
        foreach ($attendus as $instant => $code) {
            self::assertSame($code, Totp::hotp(self::SECRET_RFC, Totp::pas($instant), 8), 'instant ' . $instant);
            self::assertSame(substr($code, -6), Totp::code(self::SECRET_RFC, $instant));
        }
    }

    public function testUnCodeEstAccepteAvecUnPasDeToleranceEtPasAuDela(): void
    {
        $instant = 1_800_000_000;
        $secret = Totp::genererSecret();
        foreach ([-30 => true, 0 => true, 30 => true, -60 => false, 60 => false] as $decalage => $accepte) {
            $code = Totp::code($secret, $instant + $decalage);
            self::assertSame($accepte, Totp::verifier($secret, $code, $instant, null) !== null, 'décalage ' . $decalage);
        }
    }

    public function testUnCodeNeSertQuUneFois(): void
    {
        $instant = 1_800_000_000;
        $secret = Totp::genererSecret();
        $code = Totp::code($secret, $instant);
        $pas = Totp::verifier($secret, $code, $instant, null);
        self::assertNotNull($pas);
        self::assertNull(Totp::verifier($secret, $code, $instant, $pas), 'Rejeu du même code');
        self::assertNull(Totp::verifier($secret, Totp::code($secret, $instant - 30), $instant, $pas), 'Code plus ancien');
        self::assertNotNull(Totp::verifier($secret, Totp::code($secret, $instant + 30), $instant + 30, $pas), 'Code suivant');
    }

    public function testUnCodeMalFormeEstRefuse(): void
    {
        $secret = Totp::genererSecret();
        foreach (['', '12345', '1234567', 'abcdef', '12 34 5a'] as $saisi) {
            self::assertNull(Totp::verifier($secret, $saisi, 1_800_000_000, null), $saisi);
        }
    }

    public function testBase32AllerRetourEtVecteursRfc4648(): void
    {
        self::assertSame('MZXW6YTBOI', Base32::encoder('foobar'));
        self::assertSame('foobar', Base32::decoder('mzxw 6ytb oi======'));
        $secret = Totp::genererSecret();
        self::assertSame($secret, Base32::decoder(Base32::encoder($secret)));
        self::assertSame(32, strlen(Base32::encoder($secret)));
    }

    public function testLAdresseOtpauthEstCompriseParLesApplications(): void
    {
        $adresse = Totp::adresse(self::SECRET_RFC, 'mzanelli');
        // Secret d'exemple public de la RFC 4226 (« 12345678901234567890 »), pas un vrai secret.
        self::assertStringStartsWith('otpauth://totp/Portail%20Sup%27Sallanches%3Amzanelli?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $adresse); // gitleaks:allow
        self::assertStringContainsString('&digits=6&period=30', $adresse);
    }

    public function testLeSecretChiffreSeDechiffreEtNEstPasLisible(): void
    {
        $chiffrement = new Chiffrement(random_bytes(32));
        $chiffre = $chiffrement->chiffrer(self::SECRET_RFC, 'compte:1');
        self::assertStringNotContainsString(self::SECRET_RFC, $chiffre);
        self::assertSame(self::SECRET_RFC, $chiffrement->dechiffrer($chiffre, 'compte:1'));
        self::assertNotSame($chiffre, $chiffrement->chiffrer(self::SECRET_RFC, 'compte:1'), 'Deux chiffrements diffèrent');
    }

    public function testUnSecretRecopieSurUnAutreCompteEstRefuse(): void
    {
        $chiffrement = new Chiffrement(random_bytes(32));
        $chiffre = $chiffrement->chiffrer(self::SECRET_RFC, 'compte:1');
        $this->expectException(RuntimeException::class);
        $chiffrement->dechiffrer($chiffre, 'compte:2');
    }

    public function testUnSecretAltereOuUneMauvaiseCleSontRefuses(): void
    {
        $chiffrement = new Chiffrement(random_bytes(32));
        $chiffre = $chiffrement->chiffrer(self::SECRET_RFC, 'compte:1');
        $brut = (string) base64_decode(substr($chiffre, 3), true);
        $altere = 'v1:' . base64_encode(substr($brut, 0, -1) . chr(ord(substr($brut, -1)) ^ 1));
        foreach ([[$chiffrement, $altere], [new Chiffrement(random_bytes(32)), $chiffre]] as [$outil, $texte]) {
            try {
                $outil->dechiffrer($texte, 'compte:1');
                self::fail('Déchiffrement accepté à tort');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLeMotDePasseEstHacheEtVerifie(): void
    {
        $hache = MotsDePasse::hacher('cheval correct agrafe pile');
        self::assertStringStartsNotWith('cheval', $hache);
        self::assertTrue(MotsDePasse::verifier('cheval correct agrafe pile', $hache));
        self::assertFalse(MotsDePasse::verifier('cheval correct agrafe pilE', $hache));
        self::assertFalse(MotsDePasse::doitEtreRehache($hache));
    }

    /** @return array<string, array{string}> */
    public function motsDePasseRefuses(): array
    {
        return ['trop court' => ['Court!2026'], 'contient l\'identifiant' => ['Mzanelli-2026-ok'],
            'courant' => ['Motdepasse1234'], 'répétitif' => ['abababababababab'], 'trop long' => [str_repeat('abcd', 33)],
            'forme A sans caractère spécial' => ['Lacdepassy74'], 'forme B sans majuscule' => ['lacdepassy2026x'],
            'phrase de 6 mots' => ['le lac de Passy en hiver'], 'phrase de mots d\'une lettre' => ['a b c d e f g h i j k']];
    }

    /** @dataProvider motsDePasseRefuses */
    public function testLesMotsDePasseFaiblesSontRefuses(string $motDePasse): void
    {
        self::assertNotNull(MotsDePasse::refus($motDePasse, 'mzanelli'));
    }

    /** @return array<string, array{string}> */
    public function formesCnil(): array
    {
        return [
            'A : 12 caractères, 4 types' => ['Lac-Passy-74'],
            'B : 14 caractères, 3 types' => ['LacDePassy2026'],
            'C : phrase de 7 mots' => ['le lac de Passy gèle en hiver'],
            'C : avec accents et ponctuation' => ['Élèves, sites et sujets : tout part en juin !'],
        ];
    }

    /** @dataProvider formesCnil */
    public function testLesTroisFormesDeLaCnilSontAcceptees(string $motDePasse): void
    {
        self::assertNull(MotsDePasse::refus($motDePasse, 'mzanelli'));
    }

    public function testLeMotDePasseProvisoireRespecteLaFormeA(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $provisoire = MotsDePasse::provisoire();
            self::assertSame(1, preg_match('/^[a-km-np-zA-HJ-KM-NP-Z2-9]{4}(-[a-km-np-zA-HJ-KM-NP-Z2-9]{4}){3}$/D', $provisoire), $provisoire);
        }
    }
}
