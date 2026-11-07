<?php

declare(strict_types=1);

namespace Portail\Tests;

use PHPUnit\Framework\TestCase;
use Portail\Etudiants\Identifiant;
use Portail\Noyau\ErreurSaisie;

/**
 * Règle de l'identifiant, identique à la Google Sheets (cahier des charges, 07/11/2026).
 *
 * Les noms ci-dessous sont inventés (sauf les exemples du cahier des charges).
 * Les résultats attendus ont été calculés à la main avec la règle écrite ;
 * ils sont à comparer avec ceux de la feuille (les fichiers .csv restent hors du dépôt).
 */
final class IdentifiantTest extends TestCase
{
    /** @return array<string, array{string, string, string}> nom complet, identifiant, identifiant WordPress */
    public static function nomsInventes(): array
    {
        return [
            '01 exemple du cahier' => ['GAUTHIER ROY Stéphanie', 'gauthierst', 'GauthiersT'],
            '02 deux particules, 11 lettres' => ['DE LA FONTAINE Jean', 'delafontainje', 'DelafontainjE'],
            '03 apostrophe' => ['N\'DIAYE Awa', 'ndiayeaw', 'NdiayeaW'],
            '04 particule seule' => ['LE Minh', 'lemi', 'LemI'],
            '05 accent sur le prénom' => ['MARTIN Élodie', 'martinel', 'MartineL'],
            '06 nom à tiret' => ['DUPONT-MOREL Léa', 'dupontle', 'DupontlE'],
            '07 deux noms, prénom à tiret' => ['HENRI GAULTIER Jean-Marc', 'henrije', 'HenrijE'],
            '08 particules néerlandaises' => ['VAN DER BERG Noa', 'vanderbergno', 'VanderbergnO'],
            '09 particule d\'' => ['D\'ALMEIDA Inês', 'dalmeidain', 'DalmeidaiN'],
            '10 particule arabe' => ['BEN SALEM Yasmine', 'bensalemya', 'BensalemyA'],
            '11 tilde espagnol' => ['PEÑA Inès', 'penain', 'PenaiN'],
            '12 lettres hors liste supprimées' => ['ŠIMIĆ Ana', 'imian', 'ImiaN'],
            '13 ligature supprimée' => ['CŒURDEVENT Paul', 'curdeventpa', 'CurdeventpA'],
            '14 nom long à tirets' => ['MONTGOLFIER-SAINT-ANDRÉ Zoé', 'montgolfierzo', 'MontgolfierzO'],
            '15 nom coupé à 11 lettres' => ['ABDELKADERIBRAHIM Ali', 'abdelkaderial', 'AbdelkaderiaL'],
            '16 prénom composé avec espace' => ['LOPEZ Jean Pierre', 'lopezpi', 'LopezpI'],
            '17 particule puis plusieurs noms' => ['DA COSTA DOS SANTOS Maëlys', 'dacostama', 'DacostamA'],
        ];
    }

    /** @dataProvider nomsInventes */
    public function testLIdentifiantEstCeluiDeLaFeuille(string $nomComplet, string $identifiant, string $wordpress): void
    {
        self::assertSame($identifiant, Identifiant::depuisNomComplet($nomComplet));
        self::assertSame($wordpress, Identifiant::wordpress($identifiant));
        self::assertTrue(Identifiant::formatValide($identifiant));
    }

    public function testLaListeCompteBienDixSeptNoms(): void
    {
        self::assertCount(17, self::nomsInventes());
    }

    public function testLaSaisieSepareeDonneLeMemeResultatQueLeNomComplet(): void
    {
        self::assertSame('delafontainje', Identifiant::depuisNomEtPrenom('De la Fontaine', 'Jean'));
        self::assertSame('lopezpi', Identifiant::depuisNomEtPrenom('LOPEZ', 'Jean Pierre'), 'Même limite que la feuille');
        self::assertSame('dupontma', Identifiant::depuisNomComplet('  dupont    Marie  '));
    }

    public function testLesHomonymesRecoiventUnChiffre(): void
    {
        $pris = ['henrije', 'henrije2'];
        self::assertSame('henrije3', Identifiant::premierLibre('henrije', fn (string $i): bool => in_array($i, $pris, true)));
        self::assertSame('martinel', Identifiant::premierLibre('martinel', fn (string $i): bool => false));
        self::assertSame('MaciteB2', Identifiant::wordpress('maciteb2'));
        // HENRI JEAN-MARC et HENRI GAULTIER Jean-Marc donnent les mêmes lettres.
        self::assertSame(Identifiant::depuisNomComplet('HENRI JEAN-MARC'), Identifiant::depuisNomComplet('HENRI GAULTIER Jean-Marc'));
    }

    public function testLesLimitesDeLaFeuilleSontReprisesALIdentique(): void
    {
        // ý n'est pas dans la liste des accents de la feuille : supprimé.
        self::assertSame('krnevol', Identifiant::depuisNomComplet('KÝRNEV Oleg'));
        // Une espace après l'apostrophe arrête le nom, comme dans la feuille.
        self::assertSame('dru', Identifiant::depuisNomComplet('D\' ALMEIDA Rui'));
        // Apostrophe typographique : supprimée, même résultat que l'apostrophe droite.
        self::assertSame('ndiayeaw', Identifiant::depuisNomComplet('N’DIAYE Awa'));
        // Prénom : 2 premières lettres une fois le tiret retiré.
        self::assertSame('martinjm', Identifiant::depuisNomComplet('MARTIN J-Marc'));
    }

    public function testAuDelaDeNeufHomonymesIlFautChoisirALaMain(): void
    {
        $this->expectException(ErreurSaisie::class);
        Identifiant::premierLibre('lemi', fn (string $i): bool => true);
    }

    public function testLeFormatDesNouveauxIdentifiants(): void
    {
        foreach (['lemi', 'delafontainje', 'maciteb2'] as $bon) {
            self::assertTrue(Identifiant::formatValide($bon), $bon);
        }
        foreach (['', 'a', 'Lemi', 'lemi1', 'lemi10', 'delafontainjea', 'lé', 'le-mi', 'le mi', '2lemi', "lemi\n"] as $mauvais) {
            self::assertFalse(Identifiant::formatValide($mauvais), $mauvais);
        }
    }

    public function testUnNomSansPrenomEstRefuse(): void
    {
        $this->expectException(ErreurSaisie::class);
        Identifiant::depuisNomComplet('MARTIN');
    }

    public function testUnNomSansLettreReconnueEstRefuse(): void
    {
        $this->expectException(ErreurSaisie::class);
        Identifiant::depuisNomComplet('ŠØŒ Ana');
    }
}
