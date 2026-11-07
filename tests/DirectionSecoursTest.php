<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Comptes\DepotComptes;
use Portail\Comptes\GestionComptes;
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
 * Décisions du 07/11/2026 : la direction adjointe a un pouvoir de secours sur les
 * comptes, signalé aux administrateurs ; tout compte actif est à revoir après 6 mois.
 */
final class DirectionSecoursTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';
    private const MAINTENANT = 1_794_000_000; // 06/11/2026

    private DossierTemporaire $dossier;
    private PDO $pdo;
    private HorlogeFixe $horloge;
    private Chiffrement $chiffrement;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->horloge = new HorlogeFixe(self::MAINTENANT);
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'adirection' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        // Comptes créés la veille : rien à revoir.
        $this->pdo->exec("UPDATE comptes SET doit_changer_mdp = 0, cree_le = '2026-11-05T08:00:00Z'");
    }

    public function testLaDirectionPeutInstallerUnNouvelAdministrateur(): void
    {
        $page = $this->en('adirection', 'POST', '/comptes/nouveau',
            ['identifiant' => 'nouvelinfo', 'nom' => 'Nouvel INFORMATICIEN', 'email' => 'informatique@exemple.fr', 'role' => 'administrateur']);
        self::assertStringContainsString('Mot de passe provisoire', $page->corps);
        self::assertSame('administrateur', $this->texte("SELECT role FROM comptes WHERE identifiant = 'nouvelinfo'"));
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE action = '" . GestionComptes::ACTION_SECOURS . "' AND cible = 'nouvelinfo'"));
    }

    public function testLesActionsDeSecoursSontSignaleesAuxAdministrateursSeulement(): void
    {
        $id = (int) $this->texte("SELECT id FROM comptes WHERE identifiant = 'cpetit'");
        $this->en('adirection', 'POST', '/comptes/' . $id, ['action' => 'double_auth']);
        $accueilAdmin = $this->en('mzanelli', 'GET', '/')->corps;
        self::assertStringContainsString('Actions de secours de la direction', $accueilAdmin);
        self::assertStringContainsString('réinitialisation de la double authentification – compte cpetit', $accueilAdmin);
        self::assertStringNotContainsString('Actions de secours', $this->en('cpetit', 'GET', '/')->corps);

        // Une action de l'administrateur n'est pas une action de secours.
        $this->en('mzanelli', 'POST', '/comptes/' . $id, ['action' => 'double_auth']);
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE action = '" . GestionComptes::ACTION_SECOURS . "'"));
    }

    public function testLaDirectionNeTouchePasAuxEtudiants(): void
    {
        $referentiel = new RegimesSessions($this->pdo);
        $regime = $referentiel->creerRegime('NDRC FI');
        $session = $referentiel->creerSession('2028');
        $id = (new GestionEtudiants($this->pdo))->ajouter('MARTIN', 'Élodie', 'e@exemple.fr', $regime, $session, 'martinel');

        self::assertStringContainsString('MARTIN Élodie', $this->en('adirection', 'GET', '/etudiants')->corps);
        self::assertSame(403, $this->en('adirection', 'GET', '/etudiants/' . $id)->statut);
        self::assertSame(403, $this->en('adirection', 'GET', '/etudiants/nouveau')->statut);
        self::assertSame(403, $this->en('adirection', 'POST', '/regimes-sessions', ['action' => 'creer_session', 'annee' => '2029'])->statut);
    }

    public function testLaDirectionNePeutPasDesactiverLeDernierAdministrateur(): void
    {
        $id = (int) $this->texte("SELECT id FROM comptes WHERE identifiant = 'mzanelli'");
        $page = $this->en('adirection', 'POST', '/comptes/' . $id, ['action' => 'desactiver']);
        self::assertStringContainsString('dernier administrateur actif', $page->corps);
        self::assertSame(1, $this->nombre("SELECT actif FROM comptes WHERE identifiant = 'mzanelli'"));
    }

    public function testUnCompteNonConfirmeDepuisSixMoisEstSignale(): void
    {
        $this->pdo->exec("UPDATE comptes SET cree_le = '2026-05-01T08:00:00Z' WHERE identifiant = 'cpetit'");
        $this->pdo->exec("UPDATE comptes SET cree_le = '2026-05-10T08:00:00Z' WHERE identifiant = 'mzanelli'"); // 5 mois et 27 jours
        foreach (['mzanelli', 'adirection'] as $qui) {
            $accueil = $this->en($qui, 'GET', '/')->corps;
            self::assertStringContainsString('Comptes à revoir', $accueil, $qui);
            self::assertStringContainsString('Cpetit (cpetit)', $accueil, $qui);
            self::assertStringNotContainsString('Mzanelli (mzanelli)', $accueil, 'Moins de 6 mois');
        }
        self::assertStringNotContainsString('Comptes à revoir', $this->en('cpetit', 'GET', '/')->corps, 'Réservé à qui gère les comptes');

        $id = (int) $this->texte("SELECT id FROM comptes WHERE identifiant = 'cpetit'");
        $page = $this->en('mzanelli', 'POST', '/comptes/' . $id, ['action' => 'confirmer']);
        self::assertStringContainsString('ne sera plus signalé avant 6 mois', $page->corps);
        self::assertStringNotContainsString('Comptes à revoir', $this->en('mzanelli', 'GET', '/')->corps);
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE action = 'revue de compte' AND cible = 'cpetit'"));
    }

    public function testUnCompteDesactiveNEstPasSignale(): void
    {
        $this->pdo->exec("UPDATE comptes SET cree_le = '2025-01-01T08:00:00Z', actif = 0 WHERE identifiant = 'cpetit'");
        self::assertStringNotContainsString('Comptes à revoir', $this->en('mzanelli', 'GET', '/')->corps);
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

    private function nombre(string $sql): int
    {
        return (int) $this->pdo->query($sql)?->fetchColumn();
    }

    private function texte(string $sql): string
    {
        return (string) $this->pdo->query($sql)?->fetchColumn();
    }
}
