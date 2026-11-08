<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Comptes\DepotComptes;
use Portail\Journal\Journal;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;

/**
 * Page du journal : les connexions (adresses IP, échecs) sont réservées à
 * l'administrateur et à la direction (décision du 07/11/2026).
 */
final class JournalPageTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    private DossierTemporaire $dossier;
    private PDO $pdo;
    private HorlogeFixe $horloge;
    private Chiffrement $chiffrement;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->horloge = new HorlogeFixe(1_794_000_000);
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'jroux' => 'administratif', 'adirection' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('x'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        $journal = new Journal($this->pdo);
        $journal->ecrire('connexion', 'connexion', 'echec', null, 'cpetit', '198.51.100.7', null, 'mot de passe incorrect');
        $journal->ecrire('administration', 'ajout d\'étudiant', 'succes', null, 'Jroux', '198.51.100.8', 'martinel', '<script>alert(1)</script>');
    }

    public function testLEnseignantEtLAdministratifVoientLesActionsSansLesConnexionsNiLesAdresses(): void
    {
        foreach (['cpetit', 'jroux'] as $qui) {
            $page = $this->en($qui, 'GET', '/journal')->corps;
            self::assertStringContainsString('martinel', $page, $qui);
            self::assertStringNotContainsString('mot de passe incorrect', $page, $qui);
            self::assertStringNotContainsString('198.51.100', $page, $qui . ' : aucune adresse IP');
            self::assertStringNotContainsString('value="connexion"', $page, $qui . ' : pas de filtre Connexions');
            // Un formulaire forgé qui demande les connexions n'affiche que ce qui est autorisé.
            $force = $this->en($qui, 'POST', '/journal', ['action' => 'filtrer', 'categorie' => 'connexion'])->corps;
            self::assertStringNotContainsString('mot de passe incorrect', $force, $qui);
        }
    }

    public function testLAdministrateurEtLaDirectionVoientLesConnexionsEtLesAdresses(): void
    {
        foreach (['mzanelli', 'adirection'] as $qui) {
            $page = $this->en($qui, 'POST', '/journal', ['action' => 'filtrer', 'categorie' => 'connexion'])->corps;
            self::assertStringContainsString('mot de passe incorrect', $page, $qui);
            self::assertStringContainsString('198.51.100.7', $page, $qui);
            self::assertStringNotContainsString('martinel', $page, $qui . ' : filtre appliqué');
        }
    }

    public function testLeContenuDuJournalEstAfficheSansEtreExecute(): void
    {
        $page = $this->en('cpetit', 'GET', '/journal')->corps;
        self::assertStringNotContainsString('<script>', $page);
        self::assertStringContainsString('&lt;script&gt;', $page);
    }

    public function testLeJournalSeLitParPagesDeCent(): void
    {
        $journal = new Journal($this->pdo);
        for ($i = 1; $i <= 150; $i++) {
            $journal->ecrire('administration', 'action ' . $i, 'succes', null, 'Test', null, 'cible' . $i);
        }
        $premiere = $this->en('cpetit', 'GET', '/journal')->corps;
        self::assertStringContainsString('cible150<', $premiere);
        self::assertStringNotContainsString('cible50<', $premiere);
        self::assertSame(1, preg_match('/name="avant" value="([0-9]+)"/', $premiere, $trouve));
        $suivante = $this->en('cpetit', 'POST', '/journal', ['action' => 'filtrer', 'categorie' => '', 'avant' => $trouve[1]])->corps;
        self::assertStringContainsString('cible50<', $suivante);
        self::assertStringNotContainsString('cible51<', $suivante);
        self::assertStringNotContainsString('Entrées plus anciennes', $suivante);
    }

    /** @param array<string, string> $champs */
    private function en(string $identifiant, string $methode, string $chemin, array $champs = []): Reponse
    {
        $compte = (new DepotComptes($this->pdo))->parIdentifiant($identifiant);
        self::assertNotNull($compte);
        $session = new Session(new SessionMemoire(), $this->horloge);
        $session->connecter($compte->id, $compte->generation);
        if ($methode === 'POST') {
            $champs['jeton'] = $session->jeton();
        }

        return (new Application($this->pdo, $this->chiffrement, $this->horloge, $session, self::URL))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null));
    }
}
