<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Alertes\Alertes;
use Portail\Base\Connexion;
use Portail\Base\Installation;
use Portail\Base\Schema;
use Portail\Comptes\Compte;
use Portail\Comptes\DepotComptes;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Sites\Certificats;
use Portail\Sites\RelanceCertificats;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Sites\SondeCertificat;
use Portail\Sites\SondeOpenssl;
use Portail\Sites\SuiviCertificats;
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
 * Suivi des certificats par l'administrateur (demande du 14/11/2026) : vérification, page, relance, lundi.
 */
final class CertificatsTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    private DossierTemporaire $dossier;
    private PDO $pdo;
    /** @var array<string, array{reconnu: bool, expire: ?int, detail: string}> hôte => réponse de la sonde */
    public array $reponses = [];
    /** @var list<string> */
    public array $examines = [];
    /** @var list<string> */
    public array $poses = [];
    private int $maintenant;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        foreach (Installation::DOSSIERS_PRIVES as $relatif) {
            @mkdir($this->dossier->racine . '/' . $relatif, 0700, true);
        }
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->pdo->exec("INSERT INTO parametres (cle, valeur) VALUES ('adresse_alertes', 'admin@exemple.fr'), ('sites_proteges', '[\"peda\",\"sujet\"]'), ('bases_protegees', '[]')");
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        $sousDomaines = [];
        foreach (['etudiant01', 'etudiant02', 'etudiant03', 'etudiant04', 'etudiant05', 'etudiant06', 'etudiant07', 'etudiant08', 'etudiant09',
            'etudiant10', 'etudiant11', 'peda'] as $id) {
            $sousDomaines[$id] = $this->dossier->racine . '/' . $id . '.bts-sup-sallanches.com';
            @mkdir($sousDomaines[$id]);
        }
        Releve::enregistrer($this->pdo, $sousDomaines, [], '2026-11-14T12:00:00Z');
        $this->maintenant = time();
        foreach (array_keys($sousDomaines) as $id) {
            $this->reponses[$id . '.bts-sup-sallanches.com'] = ['reconnu' => true, 'expire' => $this->maintenant + 60 * 86400, 'detail' => 'reconnu par les navigateurs'];
        }
        $this->reponses['etudiant03.bts-sup-sallanches.com'] = ['reconnu' => false, 'expire' => null, 'detail' => 'non reconnu : self-signed certificate (code 18)'];
        $this->reponses['etudiant05.bts-sup-sallanches.com'] = ['reconnu' => true, 'expire' => $this->maintenant + 5 * 86400, 'detail' => 'reconnu par les navigateurs'];
    }

    public function testVerificationQuotidienneParLots(): void
    {
        $suivi = new SuiviCertificats($this->pdo);
        // Jamais vérifié : demandée d'office, dix sites par passage.
        self::assertSame(10, $suivi->verifierLot($this->sonde(), $this->maintenant));
        self::assertSame(2, $suivi->verifierLot($this->sonde(), $this->maintenant));
        self::assertSame(0, $suivi->verifierLot($this->sonde(), $this->maintenant), 'tout est vu : vérification close');
        self::assertCount(12, $this->examines);
        $bilan = $suivi->bilan($this->maintenant);
        self::assertSame(['non_reconnus' => 1, 'expirent' => 1, 'abandons' => 0, 'relances' => 0, 'jamais' => 0, 'demande' => null],
            array_diff_key($bilan, ['derniere' => true]));
        self::assertNotNull($bilan['derniere']);
        // Pas d'autre vérification avant 24 heures, puis une nouvelle d'office.
        self::assertSame(0, $suivi->verifierLot($this->sonde(), $this->maintenant + 3600));
        self::assertSame(10, $suivi->verifierLot($this->sonde(), $this->maintenant + 86401));
        // Problèmes d'abord dans la liste.
        $liste = $suivi->liste($this->maintenant);
        self::assertSame(['etudiant03', 'etudiant05'], [$liste[0]['identifiant'], $liste[1]['identifiant']]);
        self::assertTrue($liste[0]['probleme'] && $liste[1]['probleme']);
        self::assertFalse($liste[2]['probleme']);
    }

    public function testRelanceDemandeeParLAdministrateur(): void
    {
        $suivi = new SuiviCertificats($this->pdo);
        while ($suivi->verifierLot($this->sonde(), $this->maintenant) > 0) {
        }
        foreach ([[['peda'], 'protégé'], [['inconnu'], 'inconnu du relevé'], [[], 'Cochez au moins']] as [$coches, $attendu]) {
            try {
                $suivi->relancer($coches, $this->compte('mzanelli'), null, $this->maintenant);
                self::fail('Refus attendu : ' . $attendu);
            } catch (ErreurSaisie $e) {
                self::assertStringContainsString($attendu, $e->getMessage());
            }
        }
        self::assertSame(1, $suivi->relancer(['etudiant03'], $this->compte('mzanelli'), null, $this->maintenant));
        self::assertSame(1, $suivi->bilan($this->maintenant)['relances']);
        // Le travailleur, libre, essaie tout de suite ; posé : relance close, ancien état effacé (revu à la vérification suivante).
        $relance = new RelanceCertificats($this->pdo);
        self::assertSame('posé : etudiant03.bts-sup-sallanches.com',
            $relance->relancerUn($this->certificats(), $this->cpanel(), $this->dossier->racine, new Alertes($this->pdo, null, self::URL), $this->maintenant));
        self::assertSame(['etudiant03.bts-sup-sallanches.com'], $this->poses);
        $ligne = array_values(array_filter($suivi->liste($this->maintenant), static fn (array $l): bool => $l['identifiant'] === 'etudiant03'))[0];
        self::assertNull($ligne['reconnu']);
        self::assertNull($ligne['relance']);
    }

    public function testAlerteDuLundiSansNomDEtudiant(): void
    {
        $suivi = new SuiviCertificats($this->pdo);
        $boite = new ExpediteurMemoire();
        $alertes = new Alertes($this->pdo, $boite, self::URL);
        // Jamais vérifié : l'alerte le dit.
        $alertes->certificats($suivi->bilan(), SuiviCertificats::SEUIL_JOURS);
        self::assertSame(1, $alertes->nombreEnAttente());
        while ($suivi->verifierLot($this->sonde()) > 0) {
        }
        $alertes = new Alertes($this->pdo, $boite, self::URL);
        $alertes->certificats($suivi->bilan(), SuiviCertificats::SEUIL_JOURS);
        $alertes->expedier();
        $texte = $boite->envoyes[0]['texte'];
        self::assertStringContainsString('1 site(s) dont le certificat n\'est pas reconnu', $texte);
        self::assertStringContainsString('1 certificat(s) qui expirent dans moins de 14 jours', $texte);
        self::assertStringContainsString(self::URL . '/sites/certificats', $texte);
        self::assertStringNotContainsString('etudiant', $texte, 'aucun nom d\'étudiant dans l\'e-mail');
        // Tout va bien : rien n'est envoyé.
        $this->reponses['etudiant03.bts-sup-sallanches.com'] = $this->reponses['etudiant01.bts-sup-sallanches.com'];
        $this->reponses['etudiant05.bts-sup-sallanches.com'] = $this->reponses['etudiant01.bts-sup-sallanches.com'];
        $suivi->demanderVerification($this->compte('mzanelli'), null, time() + 5);
        while ($suivi->verifierLot($this->sonde(), time() + 10) > 0) {
        }
        $alertes = new Alertes($this->pdo, $boite, self::URL);
        $alertes->certificats($suivi->bilan(), SuiviCertificats::SEUIL_JOURS);
        self::assertSame(0, $alertes->nombreEnAttente());
    }

    public function testPageReserveeALAdministrateur(): void
    {
        self::assertSame(403, $this->en('cpetit', 'GET', '/sites/certificats')->statut);
        self::assertStringNotContainsString('/sites/certificats', $this->en('cpetit', 'GET', '/')->corps);
        self::assertStringContainsString('/sites/certificats', $this->en('mzanelli', 'GET', '/')->corps);
        $page = $this->en('mzanelli', 'GET', '/sites/certificats')->corps;
        self::assertStringContainsString('Dernière vérification complète : <strong>jamais', $page);
        self::assertStringContainsString('pas encore vérifié', $page);
        self::assertStringNotContainsString('value="peda"', $page, 'site protégé : pas de relance depuis le portail');
        $page = $this->en('mzanelli', 'POST', '/sites/certificats', ['action' => 'verifier'])->corps;
        self::assertStringContainsString('Vérification demandée', $page);
        $page = $this->en('mzanelli', 'POST', '/sites/certificats', ['action' => 'relancer'], ['coches' => ['etudiant03']])->corps;
        self::assertStringContainsString('1 relance(s) demandée(s)', $page);
        self::assertStringContainsString('en cours (0 essai(s)', $page);
        self::assertSame(403, $this->en('cpetit', 'POST', '/sites/certificats', ['action' => 'relancer'], ['coches' => ['etudiant03']])->statut);
    }

    public function testLectureDeLaReponseDOpenssl(): void
    {
        $pem = self::certificatDeTest();
        $ok = SondeOpenssl::lire("CONNECTED(00000003)\n---\nServer certificate\n" . $pem . "\n---\n    Verify return code: 0 (ok)\n");
        self::assertTrue($ok['reconnu']);
        self::assertIsInt($ok['expire']);
        $ko = SondeOpenssl::lire($pem . "\nVerify return code: 18 (self-signed certificate)\n");
        self::assertFalse($ko['reconnu']);
        self::assertSame('non reconnu : self-signed certificate (code 18)', $ko['detail']);
        self::assertSame('pas de certificat reçu', SondeOpenssl::lire("connect:errno=111\n")['detail']);
    }

    // ===== Outils =====

    private static function certificatDeTest(): string
    {
        $cle = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($cle);
        $demande = openssl_csr_new(['commonName' => 'essai.bts-sup-sallanches.com'], $cle);
        self::assertNotFalse($demande);
        $certificat = openssl_csr_sign($demande, null, $cle, 30);
        self::assertNotFalse($certificat);
        openssl_x509_export($certificat, $pem);

        return (string) $pem;
    }

    private function sonde(): SondeCertificat
    {
        $test = $this;

        return new class ($test) implements SondeCertificat {
            public function __construct(private readonly CertificatsTest $test)
            {
            }

            public function examiner(string $hote): array
            {
                $this->test->examines[] = $hote;

                return $this->test->reponses[$hote] ?? ['reconnu' => false, 'expire' => null, 'detail' => 'pas de réponse'];
            }
        };
    }

    private function certificats(): Certificats
    {
        $test = $this;

        return new class ($test) implements Certificats {
            public function __construct(private readonly CertificatsTest $test)
            {
            }

            public function poser(string $sousDomaine, string $racine): void
            {
                $this->test->poses[] = $sousDomaine;
            }
        };
    }

    private function cpanel(): ServeurCpanel
    {
        $racine = $this->dossier->racine;

        return new class ($racine) implements ServeurCpanel {
            public function __construct(private readonly string $racine)
            {
            }

            public function sousDomaines(): array
            {
                return ['etudiant03' => $this->racine . '/etudiant03.bts-sup-sallanches.com'];
            }

            public function creerSousDomaine(string $identifiant): void
            {
                throw new RuntimeException('non prévu');
            }

            public function creerBase(string $base): void
            {
            }

            public function creerUtilisateur(string $utilisateur, #[\SensitiveParameter] string $motDePasse): void
            {
            }

            public function donnerDroits(string $utilisateur, string $base): void
            {
            }

            public function supprimerBase(string $base): void
            {
            }

            public function supprimerUtilisateur(string $utilisateur): void
            {
            }
        };
    }

    /**
     * @param array<string, string> $champs
     * @param array<string, list<string>> $listes
     */
    private function en(string $identifiant, string $methode, string $chemin, array $champs = [], array $listes = []): Reponse
    {
        $compte = $this->compte($identifiant);
        $horloge = new HorlogeFixe(1_794_000_000);
        $session = new Session(new SessionMemoire(), $horloge);
        $session->connecter($compte->id, $compte->generation);
        if ($methode === 'POST') {
            $champs['jeton'] = $session->jeton();
        }

        return (new Application($this->pdo, new Chiffrement(random_bytes(32)), $horloge, $session, self::URL, null, $this->dossier->racine . '/data'))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null, $listes));
    }

    private function compte(string $identifiant): Compte
    {
        $compte = (new DepotComptes($this->pdo))->parIdentifiant($identifiant);
        self::assertNotNull($compte);

        return $compte;
    }
}
