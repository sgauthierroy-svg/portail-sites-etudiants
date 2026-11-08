<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Comptes\DepotComptes;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Securite\Totp;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;

/**
 * Parcours de connexion de bout en bout : chaque test simule un navigateur.
 */
final class ConnexionTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';
    private const MDP_PROVISOIRE = 'provisoire-recu-par-mail';
    private const MDP_CHOISI = 'le lac de Passy gèle chaque hiver ici';
    private const IP = '192.0.2.10';

    private DossierTemporaire $dossier;
    private PDO $pdo;
    private Chiffrement $chiffrement;
    private HorlogeFixe $horloge;
    private SessionMemoire $navigateur;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $this->horloge = new HorlogeFixe();
        $this->navigateur = new SessionMemoire();
        (new DepotComptes($this->pdo))->creer('mzanelli', 'Marc ZANELLI', 'informatique@ecs-sallanches.fr', 'administrateur', MotsDePasse::hacher(self::MDP_PROVISOIRE));
    }

    // ===== Première connexion complète =====

    public function testPremiereConnexionActivationPuisChangementDuMotDePasse(): void
    {
        self::assertSame(200, $this->get('/connexion')->statut);
        $this->assertRedirection('/connexion/activation', $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_PROVISOIRE]));

        $page = $this->get('/connexion/activation');
        self::assertStringContainsString('Activer la double authentification', $page->corps);
        $cle = $this->cleAffichee($page);
        self::assertStringContainsString(implode(' ', str_split($cle, 4)), $page->corps);
        self::assertStringContainsString('<svg', $page->corps, 'QR code d\'activation affiché');
        self::assertStringNotContainsString('otpauth', $page->corps, 'Le lien n\'apparaît pas en clair dans la page');

        $page = $this->post('/connexion/activation', ['code' => '000000']);
        self::assertStringContainsString('Code incorrect', $page->corps);

        $this->assertRedirection('/mot-de-passe', $this->post('/connexion/activation', ['code' => $this->codePour($cle)]));
        self::assertSame(1, $this->ligne('SELECT double_auth_active FROM comptes')['double_auth_active']);

        // Tant que le mot de passe provisoire n'est pas changé, l'accueil est inaccessible.
        $this->assertRedirection('/mot-de-passe', $this->get('/'));
        $refus = $this->post('/mot-de-passe', ['actuel' => self::MDP_PROVISOIRE, 'nouveau' => 'trop court', 'confirmation' => 'trop court']);
        self::assertStringContainsString('au moins 12', $refus->corps);
        $this->assertRedirection('/', $this->post('/mot-de-passe', ['actuel' => self::MDP_PROVISOIRE, 'nouveau' => self::MDP_CHOISI, 'confirmation' => self::MDP_CHOISI]));

        $accueil = $this->get('/');
        self::assertSame(200, $accueil->statut);
        self::assertStringContainsString('Bonjour Marc ZANELLI (Administrateur)', $accueil->corps);
    }

    public function testLeSecretEstEnregistreChiffreEtLaCleNestAfficheeQuALActivation(): void
    {
        $cle = $this->activerCompte();
        $secret = (string) $this->ligne('SELECT secret_2fa_chiffre FROM comptes')['secret_2fa_chiffre'];
        self::assertStringStartsWith('v1:', $secret);
        self::assertStringNotContainsString($cle, $secret);
        $this->deconnexion();
        $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_CHOISI]);
        $this->assertRedirection('/connexion', $this->get('/connexion/activation'), 'Plus de page d\'activation une fois activé');
    }

    // ===== Connexions suivantes =====

    public function testConnexionAvecCodeEtRefusDuRejeu(): void
    {
        $cle = $this->activerCompte();
        $this->deconnexion();
        $this->horloge->avancer(60);

        $this->assertRedirection('/connexion/code', $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_CHOISI]));
        $code = $this->codePour($cle);
        $this->assertRedirection('/', $this->post('/connexion/code', ['code' => $code]));
        $this->deconnexion();

        // Le même code, réutilisé dans sa minute de validité : refusé.
        $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_CHOISI]);
        self::assertStringContainsString('incorrect', $this->post('/connexion/code', ['code' => $code])->corps);
        self::assertNull($this->navigateur->donnees['compte'] ?? null);
    }

    public function testLeMessageDErreurNeRevelePasSiLeCompteExiste(): void
    {
        $inconnu = $this->post('/connexion', ['identifiant' => 'personne', 'mot_de_passe' => 'peu importe']);
        $mauvais = $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => 'mauvais mot de passe']);
        self::assertSame($this->sansJeton($inconnu->corps), $this->sansJeton($mauvais->corps));
        self::assertSame(2, $this->ligne('SELECT count(*) AS n FROM tentatives_connexion WHERE reussie = 0')['n']);
    }

    public function testCinqEchecsBloquentLeCompteQuinzeMinutes(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => 'mauvais ' . $i]);
        }
        $bloque = $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_PROVISOIRE]);
        self::assertSame(200, $bloque->statut);
        self::assertStringContainsString('incorrect', $bloque->corps, 'Même le bon mot de passe est refusé pendant le blocage');
        self::assertSame(1, $this->ligne("SELECT count(*) AS n FROM journal WHERE action = 'blocage du compte'")['n']);

        $this->horloge->avancer(14 * 60);
        self::assertStringContainsString('incorrect', $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_PROVISOIRE])->corps);
        $this->horloge->avancer(61);
        $this->assertRedirection('/connexion/activation', $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_PROVISOIRE]));
    }

    public function testLesCodesFauxComptentAussiPourLeBlocage(): void
    {
        $this->activerCompte();
        $this->deconnexion();
        $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_CHOISI]);
        for ($i = 0; $i < 4; ++$i) {
            self::assertStringContainsString('Code de vérification', $this->post('/connexion/code', ['code' => '00000' . $i])->corps);
        }
        $derniere = $this->post('/connexion/code', ['code' => '000009']);
        self::assertStringContainsString('<h1>Connexion</h1>', $derniere->corps, 'Bloqué : retour au début');
        $this->assertRedirection('/connexion', $this->get('/connexion/code'));
    }

    public function testUneAdresseQuiMultiplieLesEssaisEstBloquee(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->post('/connexion', ['identifiant' => 'essai' . $i, 'mot_de_passe' => 'x']);
        }
        self::assertSame(429, $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_PROVISOIRE])->statut);
        self::assertSame(200, $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_PROVISOIRE], '198.51.100.7')->statut === 303 ? 200 : 303, 'Une autre adresse n\'est pas bloquée');
    }

    public function testUnCompteDesactiveNePeutPasSeConnecter(): void
    {
        (new DepotComptes($this->pdo))->creer('sgauthierroy', 'Stéphanie GAUTHIER-ROY', 's.gauthier.roy@gmail.com', 'administrateur', MotsDePasse::hacher(self::MDP_PROVISOIRE));
        $this->pdo->exec("UPDATE comptes SET actif = 0 WHERE identifiant = 'sgauthierroy'");
        self::assertStringContainsString('incorrect', $this->post('/connexion', ['identifiant' => 'sgauthierroy', 'mot_de_passe' => self::MDP_PROVISOIRE])->corps);
    }

    // ===== Sessions =====

    public function testLAttenteDuCodeExpireApresCinqMinutes(): void
    {
        $this->activerCompte();
        $this->deconnexion();
        $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_CHOISI]);
        $this->horloge->avancer(301);
        $this->assertRedirection('/connexion', $this->get('/connexion/code'));
    }

    public function testDeconnexionApresTrenteMinutesSansActivite(): void
    {
        $this->activerCompte();
        $this->horloge->avancer(29 * 60);
        self::assertSame(200, $this->get('/')->statut, 'Encore connecté à 29 min');
        $this->horloge->avancer(29 * 60);
        self::assertSame(200, $this->get('/')->statut, 'Chaque page vue relance le délai');
        $this->horloge->avancer(31 * 60);
        $this->assertRedirection('/connexion', $this->get('/'));
        self::assertStringContainsString('30 minutes sans activité', $this->get('/connexion')->corps);
    }

    public function testLIdentifiantDeSessionChangeAChaqueEtape(): void
    {
        $this->activerCompte();
        // Mot de passe juste, puis connexion complète, puis changement de mot de passe.
        self::assertSame(3, $this->navigateur->regenerations);
    }

    public function testLaDeconnexionExigeUnFormulaireEtFermeLaSession(): void
    {
        $this->activerCompte();
        self::assertSame(405, $this->get('/deconnexion')->statut);
        $this->assertRedirection('/connexion', $this->deconnexion());
        $this->assertRedirection('/connexion', $this->get('/'));
        self::assertSame(1, $this->ligne("SELECT count(*) AS n FROM journal WHERE action = 'déconnexion'")['n']);
    }

    // ===== Formulaires falsifiés =====

    public function testUnFormulaireSansJetonEstRefuse(): void
    {
        $this->get('/connexion');
        $reponse = $this->traiter(new Requete('POST', '/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_PROVISOIRE], self::IP));
        self::assertSame(403, $reponse->statut);
        self::assertSame(0, $this->ligne('SELECT count(*) AS n FROM tentatives_connexion')['n'], 'Rien n\'est tenté');
    }

    public function testUnFormulaireVenantDUnAutreSiteEstRefuse(): void
    {
        $jeton = $this->jeton();
        $reponse = $this->traiter(new Requete('POST', '/connexion', ['jeton' => $jeton, 'identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_PROVISOIRE], self::IP, 'https://site-pirate.exemple'));
        self::assertSame(403, $reponse->statut);
    }

    public function testAucunMotDePasseNiCleNApparaissentDansLaBase(): void
    {
        $cle = $this->activerCompte();
        $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => 'tentative-secrete-123']);
        $contenu = (string) file_get_contents($this->dossier->racine . '/data/portail.sqlite');
        foreach ([self::MDP_PROVISOIRE, self::MDP_CHOISI, 'tentative-secrete-123', $cle] as $secret) {
            self::assertStringNotContainsString($secret, $contenu);
        }
    }

    public function testUneRedirectionNeSortJamaisDuPortail(): void
    {
        self::assertSame('/', Reponse::redirection('https://site-pirate.exemple')->enTetes['Location']);
        self::assertSame('/', Reponse::redirection('//site-pirate.exemple')->enTetes['Location']);
    }

    // ===== Aides =====

    private function application(): Application
    {
        return new Application($this->pdo, $this->chiffrement, $this->horloge, new Session($this->navigateur, $this->horloge), self::URL);
    }

    private function traiter(Requete $requete): Reponse
    {
        return $this->application()->traiter($requete);
    }

    private function get(string $chemin): Reponse
    {
        return $this->traiter(new Requete('GET', $chemin, [], self::IP));
    }

    /** @param array<string, string> $champs */
    private function post(string $chemin, array $champs, string $ip = self::IP): Reponse
    {
        return $this->traiter(new Requete('POST', $chemin, $champs + ['jeton' => $this->jeton()], $ip, self::URL));
    }

    private function jeton(): string
    {
        return (new Session($this->navigateur, $this->horloge))->jeton();
    }

    private function deconnexion(): Reponse
    {
        return $this->post('/deconnexion', []);
    }

    /** Première connexion complète ; renvoie la clé affichée. */
    private function activerCompte(): string
    {
        $this->post('/connexion', ['identifiant' => 'mzanelli', 'mot_de_passe' => self::MDP_PROVISOIRE]);
        $cle = $this->cleAffichee($this->get('/connexion/activation'));
        $this->post('/connexion/activation', ['code' => $this->codePour($cle)]);
        $this->post('/mot-de-passe', ['actuel' => self::MDP_PROVISOIRE, 'nouveau' => self::MDP_CHOISI, 'confirmation' => self::MDP_CHOISI]);
        $this->horloge->avancer(30);

        return $cle;
    }

    private function cleAffichee(Reponse $page): string
    {
        self::assertSame(1, preg_match('#<code>([A-Z2-7 ]+)</code>#', $page->corps, $trouve));

        return str_replace(' ', '', $trouve[1]);
    }

    private function codePour(string $cle): string
    {
        return Totp::code(\Portail\Securite\Base32::decoder($cle), $this->horloge->maintenant());
    }

    private function assertRedirection(string $vers, Reponse $reponse, string $message = ''): void
    {
        self::assertSame(303, $reponse->statut, $message . ' (statut)');
        self::assertSame($vers, $reponse->enTetes['Location'] ?? null, $message);
    }

    private function sansJeton(string $page): string
    {
        return (string) preg_replace('/name="jeton" value="[0-9a-f]+"/', '', $page);
    }

    /** @return array<string, mixed> */
    private function ligne(string $sql): array
    {
        $ligne = $this->pdo->query($sql)?->fetch();
        self::assertIsArray($ligne);

        return $ligne;
    }
}
