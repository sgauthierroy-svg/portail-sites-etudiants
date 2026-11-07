<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Comptes\CommandeComptes;
use Portail\Comptes\DepotComptes;
use Portail\Comptes\Droits;
use Portail\Comptes\GestionComptes;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;

final class RolesEtComptesTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    private DossierTemporaire $dossier;
    private PDO $pdo;
    private HorlogeFixe $horloge;
    private Chiffrement $chiffrement;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->horloge = new HorlogeFixe();
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $depot = new DepotComptes($this->pdo);
        // Mot de passe déjà choisi : seuls les droits sont testés ici.
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'jroux' => 'administratif', 'pmoreau' => 'consultation'] as $id => $role) {
            $this->ids[$id] = $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
    }

    // ===== Matrice des droits =====

    public function testLaMatriceDesDroitsCorrespondAuxDecisions(): void
    {
        $refuses = [
            'enseignant' => [Droits::SUPPRIMER, Droits::TRAITER_DEMANDES, Droits::GERER_COMPTES, Droits::PARAMETRES],
            'administratif' => [Droits::SUPPRIMER, Droits::TRAITER_DEMANDES, Droits::GERER_COMPTES, Droits::PARAMETRES,
                Droits::REINITIALISER_SITE, Droits::BIBLIOTHEQUE, Droits::CHANGER_SESSION],
            'consultation' => [Droits::IMPORTER, Droits::CREER_ETUDIANT, Droits::MODIFIER_ETUDIANT, Droits::DEMANDER,
                Droits::SUPPRIMER, Droits::GERER_COMPTES, Droits::REINITIALISER_SITE],
        ];
        foreach ($refuses as $role => $droits) {
            foreach ($droits as $droit) {
                self::assertFalse(Droits::peut($role, $droit), $role . ' ne doit pas avoir ' . $droit);
            }
        }
        self::assertTrue(Droits::peut('enseignant', Droits::REINITIALISER_SITE));
        self::assertTrue(Droits::peut('administratif', Droits::DEMANDER));
        foreach (['administrateur', 'enseignant', 'administratif'] as $role) {
            self::assertTrue(Droits::peut($role, Droits::REGIMES_SESSIONS), $role . ' crée régimes et sessions');
        }
        self::assertFalse(Droits::peut('consultation', Droits::REGIMES_SESSIONS));
        self::assertSame([Droits::CONSULTER, Droits::JOURNAL], Droits::de('consultation'));
        self::assertTrue(Droits::peut('administrateur', Droits::SUPPRIMER), 'Seul l\'administrateur supprime');
        self::assertFalse(Droits::peut('superadmin', Droits::CONSULTER));
        self::assertFalse(Droits::peut('administrateur', 'droit_inexistant'));
    }

    public function testSeulLAdministrateurAccedeALaGestionDesComptes(): void
    {
        foreach (['mzanelli' => 200, 'cpetit' => 403, 'jroux' => 403, 'pmoreau' => 403] as $id => $attendu) {
            self::assertSame($attendu, $this->en($id, 'GET', '/comptes')->statut, $id . ' /comptes');
            self::assertSame($attendu, $this->en($id, 'GET', '/comptes/' . $this->ids['cpetit'])->statut, $id . ' fiche');
        }
        self::assertSame(3, $this->nombre("SELECT count(*) FROM journal WHERE action = 'accès refusé'") / 2);
    }

    public function testUnRoleSansDroitNePeutPasCreerDeCompteMemeEnForgeantLeFormulaire(): void
    {
        foreach (['cpetit', 'jroux', 'pmoreau'] as $id) {
            $reponse = $this->en($id, 'POST', '/comptes/nouveau', ['identifiant' => 'pirate' . $id, 'nom' => 'X', 'email' => 'x@exemple.fr', 'role' => 'administrateur']);
            self::assertSame(403, $reponse->statut);
        }
        self::assertSame(0, $this->nombre("SELECT count(*) FROM comptes WHERE identifiant LIKE 'pirate%'"));
    }

    public function testSansConnexionToutRenvoieALaPageDeConnexion(): void
    {
        $reponse = (new Application($this->pdo, $this->chiffrement, $this->horloge, new Session(new SessionMemoire(), $this->horloge), self::URL))
            ->traiter(new Requete('GET', '/comptes', [], '192.0.2.1'));
        self::assertSame(303, $reponse->statut);
        self::assertSame('/connexion', $reponse->enTetes['Location']);
    }

    // ===== Gestion des comptes par l'administrateur =====

    public function testLAdministrateurCreeUnCompteAvecMotDePasseProvisoire(): void
    {
        $page = $this->en('mzanelli', 'POST', '/comptes/nouveau', ['identifiant' => 'sgauthierroy', 'nom' => 'Stéphanie GAUTHIER-ROY', 'email' => 's.gauthier.roy@gmail.com', 'role' => 'administrateur']);
        self::assertSame(200, $page->statut);
        self::assertSame(1, preg_match('#<code>([a-z2-9]{4}(-[a-z2-9]{4}){3})</code>#', $page->corps, $trouve));
        $cree = (new DepotComptes($this->pdo))->parIdentifiant('sgauthierroy');
        self::assertNotNull($cree);
        self::assertTrue(MotsDePasse::verifier($trouve[1], $cree->motDePasseHache));
        self::assertTrue($cree->doitChangerMotDePasse);
        self::assertFalse($cree->doubleAuthActive);
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE action = 'création de compte' AND cible = 'sgauthierroy' AND compte_nom = 'Mzanelli'"));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public function creationsRefusees(): array
    {
        return [
            'identifiant en majuscules' => [['identifiant' => 'CPetit2', 'nom' => 'X', 'email' => 'x@exemple.fr', 'role' => 'enseignant'], 'Identifiant invalide'],
            'identifiant déjà pris' => [['identifiant' => 'cpetit', 'nom' => 'X', 'email' => 'x@exemple.fr', 'role' => 'enseignant'], 'déjà utilisé'],
            'e-mail invalide' => [['identifiant' => 'nouveau', 'nom' => 'X', 'email' => 'pas-une-adresse', 'role' => 'enseignant'], 'e-mail invalide'],
            'rôle inventé' => [['identifiant' => 'nouveau', 'nom' => 'X', 'email' => 'x@exemple.fr', 'role' => 'superadmin'], 'Rôle inconnu'],
            'nom vide' => [['identifiant' => 'nouveau', 'nom' => '   ', 'email' => 'x@exemple.fr', 'role' => 'enseignant'], 'Nom affiché'],
        ];
    }

    /**
     * @dataProvider creationsRefusees
     * @param array<string, string> $champs
     */
    public function testUneCreationInvalideEstRefuseeAvecUnMessageClair(array $champs, string $message): void
    {
        $page = $this->en('mzanelli', 'POST', '/comptes/nouveau', $champs);
        self::assertStringContainsString($message, $page->corps);
        self::assertSame(4, $this->nombre('SELECT count(*) FROM comptes'));
    }

    public function testLeDernierAdministrateurNePeutPasEtreDesactiveNiRetrograde(): void
    {
        $page = $this->en('mzanelli', 'POST', '/comptes/' . $this->ids['mzanelli'], ['action' => 'desactiver']);
        self::assertStringContainsString('dernier administrateur actif', $page->corps);
        $page = $this->en('mzanelli', 'POST', '/comptes/' . $this->ids['mzanelli'], ['action' => 'modifier', 'nom' => 'Marc', 'email' => 'm@exemple.fr', 'role' => 'enseignant']);
        self::assertStringContainsString('dernier administrateur actif', $page->corps);
        self::assertSame('administrateur', $this->texte("SELECT role FROM comptes WHERE identifiant = 'mzanelli'"));
    }

    public function testChangerLeRoleFermeLaSessionOuverteDeLaPersonne(): void
    {
        $navigateurClaire = $this->connecte('cpetit');
        self::assertSame(200, $this->traiter($navigateurClaire, 'GET', '/')->statut);

        $this->en('mzanelli', 'POST', '/comptes/' . $this->ids['cpetit'], ['action' => 'modifier', 'nom' => 'Claire PETIT', 'email' => 'cpetit@exemple.fr', 'role' => 'consultation']);

        $this->assertRedirection('/connexion', $this->traiter($navigateurClaire, 'GET', '/'));
        self::assertSame('consultation', $this->texte("SELECT role FROM comptes WHERE identifiant = 'cpetit'"));
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE action = 'modification de compte' AND detail LIKE '%Enseignant → Consultation%'"));
    }

    public function testDesactiverFermeLaSessionEtEmpecheLaConnexion(): void
    {
        $navigateurJulie = $this->connecte('jroux');
        $this->en('mzanelli', 'POST', '/comptes/' . $this->ids['jroux'], ['action' => 'desactiver']);
        $this->assertRedirection('/connexion', $this->traiter($navigateurJulie, 'GET', '/'));
        self::assertSame(0, $this->nombre("SELECT actif FROM comptes WHERE identifiant = 'jroux'"));
    }

    public function testReinitialiserLeMotDePasseEtLeTelephone(): void
    {
        $this->pdo->exec("UPDATE comptes SET double_auth_active = 1, secret_2fa_chiffre = 'v1:x', echecs_consecutifs = 3, bloque_jusqu_a = '2099-01-01T00:00:00Z' WHERE identifiant = 'cpetit'");
        $page = $this->en('mzanelli', 'POST', '/comptes/' . $this->ids['cpetit'], ['action' => 'mot_de_passe']);
        self::assertStringContainsString('plus jamais affiché', $page->corps);
        $this->en('mzanelli', 'POST', '/comptes/' . $this->ids['cpetit'], ['action' => 'double_auth']);

        $claire = (new DepotComptes($this->pdo))->parIdentifiant('cpetit');
        self::assertNotNull($claire);
        self::assertTrue($claire->doitChangerMotDePasse);
        self::assertFalse($claire->doubleAuthActive);
        self::assertNull($claire->secret2faChiffre);
        self::assertNull($claire->bloqueJusqua, 'La réinitialisation débloque le compte');
    }

    // ===== Commande de secours (SSH) =====

    public function testLaCommandeDeSecoursCreeUnAdministrateur(): void
    {
        $lignes = (new CommandeComptes($this->pdo))->executer(['creer-admin', 'sgauthierroy', 'Stéphanie GAUTHIER-ROY', 's.gauthier.roy@gmail.com']);
        self::assertStringContainsString('affiché une seule fois', $lignes[1]);
        self::assertSame('administrateur', $this->texte("SELECT role FROM comptes WHERE identifiant = 'sgauthierroy'"));
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE compte_nom = 'commande de secours (SSH)' AND action = 'création de compte'"));
    }

    public function testLaCommandeDeSecoursRedonneLaMainSurUnCompteDesactive(): void
    {
        (new GestionComptes($this->pdo, null, null))->creer('sgauthierroy', 'Stéphanie', 's@exemple.fr', 'administrateur');
        $this->pdo->exec("UPDATE comptes SET actif = 0, double_auth_active = 1, secret_2fa_chiffre = 'v1:x' WHERE identifiant = 'mzanelli'");
        $lignes = (new CommandeComptes($this->pdo))->executer(['reinitialiser', 'mzanelli']);
        self::assertStringContainsString('Mot de passe provisoire', $lignes[1]);
        self::assertSame(1, $this->nombre("SELECT actif FROM comptes WHERE identifiant = 'mzanelli'"));
        self::assertSame(0, $this->nombre("SELECT double_auth_active FROM comptes WHERE identifiant = 'mzanelli'"));
    }

    public function testLaCommandeDeSecoursRefuseUneSaisieIncorrecte(): void
    {
        $commande = new CommandeComptes($this->pdo);
        self::assertSame(CommandeComptes::AIDE, $commande->executer([]));
        $this->expectException(ErreurSaisie::class);
        $commande->executer(['creer-admin', 'incomplet']);
    }

    public function testLaListeNAfficheAucunSecret(): void
    {
        $lignes = implode("\n", (new CommandeComptes($this->pdo))->executer(['liste']));
        self::assertStringContainsString('mzanelli', $lignes);
        self::assertStringNotContainsString('$argon2', $lignes);
        self::assertStringNotContainsString('$2y$', $lignes);
    }

    // ===== Aides =====

    private function connecte(string $identifiant): SessionMemoire
    {
        $navigateur = new SessionMemoire();
        $compte = (new DepotComptes($this->pdo))->parIdentifiant($identifiant);
        self::assertNotNull($compte);
        (new Session($navigateur, $this->horloge))->connecter($compte->id, $compte->generation);

        return $navigateur;
    }

    /** @param array<string, string> $champs */
    private function en(string $identifiant, string $methode, string $chemin, array $champs = []): Reponse
    {
        return $this->traiter($this->connecte($identifiant), $methode, $chemin, $champs);
    }

    /** @param array<string, string> $champs */
    private function traiter(SessionMemoire $navigateur, string $methode, string $chemin, array $champs = []): Reponse
    {
        $session = new Session($navigateur, $this->horloge);
        if ($methode === 'POST') {
            $champs['jeton'] = $session->jeton();
        }

        return (new Application($this->pdo, $this->chiffrement, $this->horloge, $session, self::URL))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null));
    }

    private function assertRedirection(string $vers, Reponse $reponse): void
    {
        self::assertSame(303, $reponse->statut);
        self::assertSame($vers, $reponse->enTetes['Location'] ?? null);
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
