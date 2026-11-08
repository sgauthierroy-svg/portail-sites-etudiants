<?php

declare(strict_types=1);

namespace Portail\Tests;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Alertes\Alertes;
use Portail\Authentification\Authentification;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Comptes\CommandeComptes;
use Portail\Comptes\DepotComptes;
use Portail\Courriel\ExpediteurSmtp;
use Portail\Securite\Base32;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Securite\Totp;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\ExpediteurMemoire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;
use RuntimeException;

/**
 * Alertes par e-mail (décisions du 08/11/2026) et envoi SMTP.
 */
final class AlertesTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';
    private const ALERTES = 'alertes@exemple.fr';

    private DossierTemporaire $dossier;
    private PDO $pdo;
    private HorlogeFixe $horloge;
    private Chiffrement $chiffrement;
    private ExpediteurMemoire $boite;
    private Alertes $alertes;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->pdo->exec("INSERT INTO parametres (cle, valeur) VALUES ('adresse_alertes', '" . self::ALERTES . "')");
        $this->horloge = new HorlogeFixe(1_794_000_000);
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $this->boite = new ExpediteurMemoire();
        $this->alertes = new Alertes($this->pdo, $this->boite, self::URL);
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'adirection' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('bon mot de passe'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
    }

    public function testActionDeSecoursAlerteLAdresseEtLaPersonneTouchee(): void
    {
        $id = (int) $this->pdo->query("SELECT id FROM comptes WHERE identifiant = 'cpetit'")?->fetchColumn();
        $this->en('adirection', 'POST', '/comptes/' . $id, ['action' => 'double_auth']);
        $secours = $this->envoisDont('Action de secours');
        self::assertCount(1, $secours);
        self::assertSame([self::ALERTES, 'cpetit@exemple.fr'], $secours[0]['destinataires']);
        self::assertStringContainsString('réinitialisation de la double authentification', $secours[0]['texte']);
        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM journal WHERE action = 'alerte envoyée'")?->fetchColumn());
    }

    public function testCompteBloqueAlerteLAdresseEtLaPersonne(): void
    {
        $auth = new Authentification($this->pdo, $this->chiffrement, $this->horloge, $this->alertes);
        for ($i = 0; $i < Authentification::ECHECS_MAX; $i++) {
            $auth->verifierMotDePasse('cpetit', 'MotSaisiParErreur', '198.51.100.7');
        }
        $this->alertes->expedier();
        $bloque = $this->envoisDont('Compte bloqué');
        self::assertCount(1, $bloque, 'Une seule alerte, au moment du blocage');
        self::assertSame([self::ALERTES, 'cpetit@exemple.fr'], $bloque[0]['destinataires']);
        self::assertStringContainsString('198.51.100.7', $bloque[0]['texte']);
        self::assertStringNotContainsString('MotSaisiParErreur', $bloque[0]['texte'], 'Jamais le mot de passe saisi');
    }

    public function testAdresseBloqueeAlerteUneSeuleFois(): void
    {
        $auth = new Authentification($this->pdo, $this->chiffrement, $this->horloge, $this->alertes);
        for ($i = 0; $i < Authentification::ECHECS_MAX_ADRESSE + 5; $i++) {
            $auth->verifierMotDePasse('inconnu' . $i, 'faux', '203.0.113.9');
        }
        $this->alertes->expedier();
        $adresse = $this->envoisDont('Adresse IP bloquée');
        self::assertCount(1, $adresse);
        self::assertSame([self::ALERTES], $adresse[0]['destinataires']);
    }

    public function testConnexionDUnCompteDirectionEstSignalee(): void
    {
        $auth = new Authentification($this->pdo, $this->chiffrement, $this->horloge, $this->alertes);
        $compte = $auth->verifierMotDePasse('adirection', 'bon mot de passe', '198.51.100.7');
        self::assertNotNull($compte);
        $secret = $auth->nouveauSecretChiffre($compte->id);
        $code = Totp::code(Base32::decoder($auth->secretLisible($compte->id, $secret)), $this->horloge->maintenant());
        self::assertNotNull($auth->activer($compte->id, $secret, $code, '198.51.100.7'));
        $this->alertes->expedier();
        self::assertCount(1, $this->envoisDont('Connexion d\'un compte Direction'));
    }

    public function testLeRoleAdministrateurAttribueEstSignale(): void
    {
        $this->en('mzanelli', 'POST', '/comptes/nouveau', ['identifiant' => 'nouvelinfo', 'nom' => 'N I', 'email' => 'n@exemple.fr', 'role' => 'administrateur']);
        $id = (int) $this->pdo->query("SELECT id FROM comptes WHERE identifiant = 'cpetit'")?->fetchColumn();
        $this->en('mzanelli', 'POST', '/comptes/' . $id, ['action' => 'modifier', 'nom' => 'Cpetit', 'email' => 'cpetit@exemple.fr', 'role' => 'administrateur']);
        $this->en('mzanelli', 'POST', '/comptes/' . $id, ['action' => 'modifier', 'nom' => 'Claire', 'email' => 'cpetit@exemple.fr', 'role' => 'administrateur']);
        (new CommandeComptes($this->pdo, $this->alertes))->executer(['creer-admin', 'secours', 'Admin SECOURS', 's@exemple.fr']);
        $this->alertes->expedier();
        $roles = $this->envoisDont('Rôle Administrateur');
        self::assertCount(3, $roles, 'Création, changement de rôle, commande de secours ; pas pour un simple changement de nom');
        self::assertStringContainsString('commande de secours (SSH)', $roles[2]['texte']);
    }

    public function testLaRevueHebdomadaireNEnvoieRienSansCompteARevoir(): void
    {
        $this->alertes->revueHebdomadaire([]);
        self::assertSame(0, $this->alertes->nombreEnAttente());
        $this->alertes->revueHebdomadaire([['id' => 2, 'identifiant' => 'cpetit', 'nom' => 'Claire PETIT', 'depuis' => '2026-05-01T08:00:00Z']]);
        $this->alertes->expedier();
        self::assertStringContainsString('Claire PETIT (cpetit), non confirmé depuis le 01/05/2026', $this->envoisDont('à revoir')[0]['texte']);
    }

    public function testUnEchecDEnvoiNeBloqueRienEtEstJournalise(): void
    {
        $alertes = new Alertes($this->pdo, new ExpediteurMemoire(true), self::URL);
        $alertes->test();
        self::assertSame(0, $alertes->expedier());
        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM journal WHERE action = 'échec d''envoi d''alerte' AND detail LIKE '%serveur injoignable%'")?->fetchColumn());
    }

    // ===== Envoi SMTP =====

    public function testLeMessageEstEncodeEtSansInjectionPossible(): void
    {
        $message = ExpediteurSmtp::message('portail@bts-sup-sallanches.com', 'Portail Sup\'Sallanches', ['a@exemple.fr'], 'Compte bloqué', ".\nligne\nsecrète", 1_794_000_000);
        self::assertStringContainsString("Subject: =?UTF-8?B?" . base64_encode('Compte bloqué') . "?=\r\n", $message);
        self::assertStringContainsString("Content-Transfer-Encoding: base64\r\n", $message);
        self::assertStringNotContainsString('secrète', $message, 'Corps encodé : aucune ligne ne commence par un point');
        foreach ([["a@exemple.fr\r\nBcc: x@pirate.fr"], ['pas-une-adresse']] as $destinataires) {
            try {
                ExpediteurSmtp::message('portail@bts-sup-sallanches.com', 'P', $destinataires, 'S', 'T', 0);
                self::fail('Adresse piégée acceptée');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        ExpediteurSmtp::message('portail@bts-sup-sallanches.com', 'P', ['a@exemple.fr'], "Sujet\r\nBcc: x@pirate.fr", 'T', 0);
    }

    public function testLeDialogueSmtpAvecUnFauxServeur(): void
    {
        [$trace] = $this->fauxServeur(false, function (int $port): void {
            (new ExpediteurSmtp('127.0.0.1', $port, 'portail@bts-sup-sallanches.com', 'mot-de-passe-test', 'Portail', false))
                ->envoyer(['a@exemple.fr', 'b@exemple.fr'], 'Test', 'Bonjour');
        });
        self::assertStringContainsString("EHLO portail.bts-sup-sallanches.com\r\nAUTH LOGIN\r\n" . base64_encode('portail@bts-sup-sallanches.com') . "\r\n"
            . base64_encode('mot-de-passe-test') . "\r\nMAIL FROM:<portail@bts-sup-sallanches.com>\r\nRCPT TO:<a@exemple.fr>\r\nRCPT TO:<b@exemple.fr>\r\nDATA\r\n", $trace);
        self::assertStringEndsWith("\r\n.\r\nQUIT\r\n", $trace);
    }

    public function testUnMotDePasseRefuseDonneUneErreurClaire(): void
    {
        [, $erreur] = $this->fauxServeur(true, function (int $port): void {
            (new ExpediteurSmtp('127.0.0.1', $port, 'portail@bts-sup-sallanches.com', 'faux', 'Portail', false))->envoyer(['a@exemple.fr'], 'T', 'T');
        });
        self::assertStringContainsString('authentification refusée', $erreur);
    }

    // ===== Aides =====

    /**
     * @param callable(int): void $client
     * @return array{string, string} conversation reçue par le faux serveur, message d'erreur du client
     */
    private function fauxServeur(bool $refuserAuth, callable $client): array
    {
        $port = random_int(20000, 40000);
        $trace = $this->dossier->racine . '/trace_smtp.txt';
        $processus = proc_open([PHP_BINARY, __DIR__ . '/aides/faux_smtp.php', (string) $port, $trace, $refuserAuth ? '1' : '0'], [], $tubes);
        self::assertIsResource($processus);
        usleep(300_000);
        $erreur = '';
        try {
            $client($port);
        } catch (RuntimeException $e) {
            $erreur = $e->getMessage();
        }
        proc_close($processus);

        return [is_file($trace) ? (string) file_get_contents($trace) : '', $erreur];
    }

    /** @return list<array{destinataires: list<string>, sujet: string, texte: string}> */
    private function envoisDont(string $sujet): array
    {
        return array_values(array_filter($this->boite->envoyes, static fn (array $e): bool => str_contains($e['sujet'], $sujet)));
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
        $reponse = (new Application($this->pdo, $this->chiffrement, $this->horloge, $session, self::URL, $this->alertes))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null));
        $this->alertes->expedier();

        return $reponse;
    }
}
