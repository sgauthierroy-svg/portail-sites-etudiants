<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Comptes\DepotComptes;
use Portail\Etudiants\GestionEtudiants;
use Portail\Etudiants\RegimesSessions;
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
 * Recherche rapide du bandeau (décisions des 17 et 18/11/2026) : sur chaque page, pour tous les rôles. Noms inventés.
 */
final class RechercheTest extends TestCase
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
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'jroux' => 'administratif', 'dlambert' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        $referentiel = new RegimesSessions($this->pdo);
        $regime = $referentiel->creerRegime('NDRC ALT');
        $session = $referentiel->creerSession('2028');
        $admin = $depot->parIdentifiant('mzanelli');
        self::assertNotNull($admin);
        (new GestionEtudiants($this->pdo, $admin))->ajouter('MARTIN', 'Élodie', 'martinel@exemple.fr', $regime, $session, 'martinel');
    }

    public function testLaBarreEstSurLAccueilDeLEnseignantAvecSesSituations(): void
    {
        $accueil = $this->en('cpetit', 'GET', '/')->corps;
        self::assertStringContainsString('<form class="recherche" method="post" action="/recherche" role="search">', $accueil);
        self::assertStringContainsString('<script src="/recherche.js" defer></script>', $accueil);
        self::assertSame(1, substr_count($accueil, 'role="search"'), 'Une seule barre de recherche');
        self::assertStringContainsString('"titre":"Préparer un BTS blanc"', $accueil);
        self::assertStringContainsString('"titre":"MARTIN Élodie"', $accueil);
        self::assertStringNotContainsString('martinel', $accueil, 'L\'identifiant n\'est pas dans la page');
        self::assertStringContainsString('role="search"', $this->en('cpetit', 'GET', '/etudiants')->corps, 'Sur chaque page');

        $direction = $this->en('dlambert', 'GET', '/')->corps;
        self::assertStringContainsString('role="search"', $direction, 'Pour tous les rôles');
        self::assertStringContainsString('"etudiants":[]', $direction, 'La direction n\'ouvre pas les fiches');
        self::assertStringNotContainsString('Préparer un BTS blanc', $direction);
        self::assertStringNotContainsString('"titre":"Faire une demande au service informatique"', $this->en('mzanelli', 'GET', '/')->corps);
    }

    public function testLaPageDeResultatsTrouveEtudiantsEtActionsSansAccents(): void
    {
        $page = $this->en('cpetit', 'POST', '/recherche', ['texte' => 'elodie'])->corps;
        self::assertStringContainsString('<a href="/etudiants/1">MARTIN Élodie</a>', $page);
        $actions = $this->en('jroux', 'POST', '/recherche', ['texte' => 'probleme boutique'])->corps;
        self::assertStringContainsString('Problème de site ou de boutique', $actions);
        self::assertStringContainsString('href="/demandes/nouvelle"', $actions);
        self::assertStringContainsString('Rien trouvé', $this->en('cpetit', 'POST', '/recherche', ['texte' => 'zzzz'])->corps);
        self::assertStringContainsString('Que voulez-vous faire ?', $this->en('cpetit', 'GET', '/recherche')->corps);
        self::assertStringContainsString('&lt;b&gt;', $this->en('cpetit', 'POST', '/recherche', ['texte' => '<b>'])->corps, 'Texte cherché échappé');
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

        return (new Application($this->pdo, $this->chiffrement, $this->horloge, $session, self::URL, null, $this->dossier->racine . '/data'))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null, []));
    }
}
