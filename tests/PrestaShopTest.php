<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Alertes\Alertes;
use Portail\Base\Connexion;
use Portail\Base\Installation;
use Portail\Base\Schema;
use Portail\Bibliotheque\Bibliotheque;
use Portail\Comptes\Compte;
use Portail\Comptes\DepotComptes;
use Portail\Etudiants\GestionEtudiants;
use Portail\Etudiants\RegimesSessions;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Sites\InstallateurPrestaShop;
use Portail\Sites\PreparationPrestaShop;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\ExpediteurMemoire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Travaux\ExecutantCreerPrestaShop;
use Portail\Travaux\FileTravaux;
use Portail\Travaux\Travailleur;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;
use RuntimeException;
use ZipArchive;

/**
 * V2, lot 3 : boutiques PrestaShop d'entraînement (CPanel et installateur simulés, noms inventés).
 */
final class PrestaShopTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    private DossierTemporaire $dossier;
    private string $personnel;
    private string $donnees;
    private PDO $pdo;
    private HorlogeFixe $horloge;
    private Chiffrement $chiffrement;
    private ExpediteurMemoire $boite;
    private int $session;
    private int $paquet;
    /** @var array{appels: list<string>, refuser: ?string, sousDomaines: array<string, string>} */
    public array $cpanel = ['appels' => [], 'refuser' => null, 'sousDomaines' => []];
    public ?string $echecInstallation = null;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->personnel = $this->dossier->racine;
        $this->donnees = $this->dossier->racine . '/data';
        foreach (Installation::DOSSIERS_PRIVES as $relatif) {
            @mkdir($this->dossier->racine . '/' . $relatif, 0700, true);
        }
        $this->pdo = Connexion::ouvrir($this->donnees . '/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->pdo->exec("INSERT INTO parametres (cle, valeur) VALUES ('adresse_alertes', 'admin@exemple.fr'), ('sites_proteges', '[\"peda\"]')");
        $this->horloge = new HorlogeFixe(1_794_000_000);
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $this->boite = new ExpediteurMemoire();
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'jroux' => 'administratif', 'pmoreau' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        $referentiel = new RegimesSessions($this->pdo);
        $fi = $referentiel->creerRegime('NDRC FI');
        $this->session = $referentiel->creerSession('2027');
        $gestion = new GestionEtudiants($this->pdo, $this->compte('mzanelli'));
        foreach ([['MARTIN', 'Élodie', 'martinel'], ['DURAND', 'Camille', 'durandca'], ['LEROY', 'Hugo', 'leroyhu']] as [$nom, $prenom, $id]) {
            $gestion->ajouter($nom, $prenom, $id . '@exemple.fr', $fi, $this->session, $id);
        }
        foreach (['martinel', 'durandca'] as $id) {
            mkdir($this->personnel . '/' . $id . '.bts-sup-sallanches.com');
            $this->cpanel['sousDomaines'][$id] = $this->personnel . '/' . $id . '.bts-sup-sallanches.com';
        }
        Releve::enregistrer($this->pdo, $this->cpanel['sousDomaines'], [], '2026-11-08T12:00:00Z');

        // Paquet officiel simulé : prestashop.zip (avec installateur et dossier admin) dans le ZIP déposé.
        $interieur = $this->donnees . '/tmp/interieur.zip';
        $zip = new ZipArchive();
        $zip->open($interieur, ZipArchive::CREATE);
        $zip->addFromString('index.php', '<?php');
        $zip->addFromString('install/index_cli.php', '<?php');
        $zip->addFromString('admin/index.php', '<?php');
        $zip->addFromString('admin-api/index.php', '<?php');
        $zip->close();
        $exterieur = $this->donnees . '/depot/prestashop_9.1.5.zip';
        $zip = new ZipArchive();
        $zip->open($exterieur, ZipArchive::CREATE);
        $zip->addFromString('index.php', '<?php');
        $zip->addFile($interieur, 'prestashop.zip');
        $zip->close();
        unlink($interieur);
        $this->paquet = (new Bibliotheque($this->pdo, $this->donnees, $this->compte('cpetit')))
            ->ajouterPaquet($exterieur, 'prestashop_9.1.5.zip', 'prestashop', 'PrestaShop', '9.1.5');
    }

    // ===== Préparation (côté web) =====

    public function testSeulsLesEtudiantsAvecSousDomaineSontProposes(): void
    {
        $etudiants = (new PreparationPrestaShop($this->pdo))->etudiants($this->session);
        self::assertSame(['durandca' => null, 'leroyhu' => 'sous-domaine absent du dernier relevé', 'martinel' => null],
            array_column($etudiants, 'empechement', 'identifiant'));
    }

    public function testLancerFixeLaVersionDeLaSessionEtRefuseUnEtudiantNonPropose(): void
    {
        $preparation = new PreparationPrestaShop($this->pdo);
        try {
            $preparation->lancer($this->session, $this->paquet, ['martinel', 'leroyhu'], $this->compte('cpetit'), null);
            self::fail('Étudiant sans sous-domaine accepté');
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString('ne peut pas recevoir', $e->getMessage());
        }
        self::assertNull($preparation->sessions()[0]['paquet_id'], 'Rien n\'est fixé si la demande est refusée');

        $id = $preparation->lancer($this->session, $this->paquet, ['martinel'], $this->compte('cpetit'), null);
        self::assertSame($this->paquet, $preparation->sessions()[0]['paquet_id']);
        self::assertSame('9.1.5', $preparation->sessions()[0]['version']);
        self::assertSame('création déjà prévue', array_column($preparation->etudiants($this->session), 'empechement', 'identifiant')['martinel']);
        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame('MARTIN Élodie', $travail['etapes'][0]['libelle']);
    }

    public function testLaPageEstReserveeAuxComptesQuiCreentDesSites(): void
    {
        self::assertSame(200, $this->en('jroux', 'GET', '/sites/prestashop')->statut);
        self::assertSame(403, $this->en('pmoreau', 'GET', '/sites/prestashop')->statut);
        $page = $this->en('cpetit', 'POST', '/sites/prestashop', ['action' => 'afficher', 'session' => (string) $this->session]);
        self::assertStringContainsString('value="martinel" checked', $page->corps);
        self::assertStringNotContainsString('value="leroyhu"', $page->corps);
        self::assertStringContainsString('PrestaShop 9.1.5', $page->corps);
        $lancement = $this->en('cpetit', 'POST', '/sites/prestashop', ['action' => 'lancer', 'session' => (string) $this->session, 'paquet' => (string) $this->paquet],
            ['coches' => ['martinel', 'durandca']]);
        self::assertSame(303, $lancement->statut);
        self::assertMatchesRegularExpression('#^/travaux/[0-9]+$#', $lancement->enTetes['Location']);
    }

    // ===== Exécution (travailleur) =====

    public function testUneBoutiqueEstCreeeEtLesAccesEnvoyes(): void
    {
        $id = (new PreparationPrestaShop($this->pdo))->lancer($this->session, $this->paquet, ['martinel', 'durandca'], $this->compte('cpetit'), null);
        $masque = umask(0077); // comme bin/travaux.php
        $this->travailleur()->tourner(50);
        umask($masque);

        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame(['reussi', 'reussi'], array_column($travail['etapes'], 'etat'));
        $presta = $this->personnel . '/martinel.bts-sup-sallanches.com/Presta';
        self::assertDirectoryDoesNotExist($presta . '/install', 'Installateur effacé');
        $admins = glob($presta . '/admin-martinel-*') ?: [];
        self::assertCount(1, $admins);
        self::assertMatchesRegularExpression('#/admin-martinel-[a-z2-9]{6}$#', $admins[0]);
        self::assertDirectoryExists($presta . '/admin-api', 'admin-api n\'est pas le dossier d\'administration');
        self::assertSame(0755, fileperms($presta) & 0777, 'Dossiers lisibles par le serveur web');
        self::assertSame(0644, fileperms($presta . '/index.php') & 0777, 'Fichiers lisibles par le serveur web (.htaccess…)');
        self::assertContains('creerUtilisateur jafl3067_martinel_presta', $this->cpanel['appels']);
        self::assertContains('donnerDroits jafl3067_martinel_presta jafl3067_martinel_Presta', $this->cpanel['appels']);

        // E-mail à chaque étudiant, puis un récapitulatif à l'administrateur.
        self::assertCount(3, $this->boite->envoyes);
        $eleve = $this->boite->envoyes[0];
        self::assertSame(['martinel@exemple.fr'], $eleve['destinataires']);
        self::assertStringContainsString('https://martinel.bts-sup-sallanches.com/Presta/admin-martinel-', $eleve['texte']);
        self::assertMatchesRegularExpression('/Mot de passe : [A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}/', $eleve['texte']);
        $recap = $this->boite->envoyes[2];
        self::assertSame(['admin@exemple.fr'], $recap['destinataires']);
        self::assertStringContainsString("martinel\thttps://martinel.bts-sup-sallanches.com/Presta/", $recap['texte']);
        self::assertStringContainsString('durandca', $recap['texte']);
        self::assertNotNull($recap['html'], 'Tableau à copier dans Google Sheets');
        self::assertStringContainsString('>martinel</td><td', (string) $recap['html']);
        self::assertSame(2, substr_count((string) $recap['html'], '<tr><td'), 'Une ligne par boutique');
        self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM travaux_etapes WHERE secret IS NOT NULL')?->fetchColumn(),
            'Aucun mot de passe gardé après le récapitulatif');

        // La fiche montre le lien d'administration aux enseignants, pas à l'administratif.
        $etudiant = (int) $this->pdo->query("SELECT id FROM etudiants WHERE identifiant = 'martinel'")?->fetchColumn();
        self::assertStringContainsString('/Presta/admin-martinel-', $this->en('cpetit', 'GET', '/etudiants/' . $etudiant)->corps);
        self::assertStringNotContainsString('/Presta/admin-martinel-', $this->en('jroux', 'GET', '/etudiants/' . $etudiant)->corps);
        self::assertStringContainsString('PrestaShop 9.1.5', $this->en('cpetit', 'GET', '/sites')->corps);
    }

    public function testUnEchecDInstallationEffaceCeQueLEtapeACree(): void
    {
        $this->echecInstallation = 'base injoignable';
        $id = (new PreparationPrestaShop($this->pdo))->lancer($this->session, $this->paquet, ['martinel'], $this->compte('cpetit'), null);
        $this->travailleur()->tourner(50);

        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame('echec', $travail['etapes'][0]['etat']);
        self::assertStringContainsString('base injoignable', (string) $travail['etapes'][0]['message']);
        self::assertStringContainsString('a été effacé', (string) $travail['etapes'][0]['message']);
        self::assertDirectoryDoesNotExist($this->personnel . '/martinel.bts-sup-sallanches.com/Presta');
        self::assertContains('supprimerUtilisateur jafl3067_martinel_presta', $this->cpanel['appels']);
        self::assertContains('supprimerBase jafl3067_martinel_Presta', $this->cpanel['appels']);
        self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM sites')?->fetchColumn());
        self::assertSame([], array_filter($this->boite->envoyes, static fn (array $e): bool => $e['destinataires'] === ['martinel@exemple.fr']));
    }

    public function testUnDossierPrestaNonVideNEstJamaisTouche(): void
    {
        $presta = $this->personnel . '/martinel.bts-sup-sallanches.com/Presta';
        mkdir($presta);
        file_put_contents($presta . '/travail-etudiant.txt', 'à garder');
        $id = (new PreparationPrestaShop($this->pdo))->lancer($this->session, $this->paquet, ['martinel'], $this->compte('cpetit'), null);
        $this->travailleur()->tourner(50);

        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame('ignore', $travail['etapes'][0]['etat']);
        self::assertSame('à garder', file_get_contents($presta . '/travail-etudiant.txt'));
        self::assertSame([], $this->cpanel['appels']);
    }

    public function testUnRefusDeCpanelArreteLEtapeSansRienLaisser(): void
    {
        $this->cpanel['refuser'] = 'creerUtilisateur';
        $id = (new PreparationPrestaShop($this->pdo))->lancer($this->session, $this->paquet, ['martinel'], $this->compte('cpetit'), null);
        $this->travailleur()->tourner(50);
        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame('echec', $travail['etapes'][0]['etat']);
        self::assertContains('supprimerBase jafl3067_martinel_Presta', $this->cpanel['appels']);
        self::assertNotContains('supprimerUtilisateur jafl3067_martinel_presta', $this->cpanel['appels'], 'Jamais créé : jamais supprimé');
    }

    public function testUnSousDomaineAuMauvaisEndroitEstRefuse(): void
    {
        mkdir($this->personnel . '/ailleurs');
        $this->cpanel['sousDomaines']['martinel'] = $this->personnel . '/ailleurs';
        $id = (new PreparationPrestaShop($this->pdo))->lancer($this->session, $this->paquet, ['martinel'], $this->compte('cpetit'), null);
        $this->travailleur()->tourner(50);
        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertStringContainsString('inattendu', (string) $travail['etapes'][0]['message']);
        self::assertSame([], $this->cpanel['appels']);
    }

    public function testUnPaquetModifieNEstPasInstalle(): void
    {
        $empreinte = $this->pdo->query('SELECT empreinte FROM paquets')?->fetchColumn();
        file_put_contents($this->donnees . '/bibliotheque/' . $empreinte . '.zip', 'x', FILE_APPEND);
        $id = (new PreparationPrestaShop($this->pdo))->lancer($this->session, $this->paquet, ['martinel'], $this->compte('cpetit'), null);
        $this->travailleur()->tourner(50);
        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertStringContainsString('modifié depuis son dépôt', (string) $travail['etapes'][0]['message']);
        self::assertSame([], $this->cpanel['appels']);
    }

    // ===== Gel par surveillance (décision du 08/11/2026) =====

    public function testLeReleveSignaleLesBoutiquesHorsDeLaVersionDeLaSession(): void
    {
        $this->pdo->prepare('UPDATE sessions SET paquet_prestashop_id = :p WHERE id = :s')->execute([':p' => $this->paquet, ':s' => $this->session]);
        $sites = [];
        foreach (['martinel' => ['9.1.5', false], 'durandca' => ['9.2.0', false], 'leroyhu' => ['9.1.5', true]] as $id => [$version, $module]) {
            $racine = $this->personnel . '/releve-' . $id;
            mkdir($racine . '/Presta/app/config', 0755, true);
            file_put_contents($racine . '/Presta/app/config/parameters.php', '<?php');
            file_put_contents($racine . '/Presta/app/AppKernel.php', "<?php\nclass AppKernel {\n    const VERSION = '" . $version . "';\n}");
            if ($module) {
                mkdir($racine . '/Presta/modules/autoupgrade', 0755, true);
            }
            $sites[$id] = Releve::sites($racine);
        }
        self::assertSame('9.2.0', $sites['durandca'][0]['version']);
        Releve::enregistrer($this->pdo, ['martinel' => '/a', 'durandca' => '/b', 'leroyhu' => '/c'], $sites, '2026-11-09T06:13:00Z');

        self::assertSame(['durandca' => 'version 9.2.0 au lieu de 9.1.5', 'leroyhu' => 'module de mise à jour installé'], Releve::horsGel($this->pdo));
        $page = $this->en('cpetit', 'GET', '/sites');
        self::assertStringContainsString('hors gel : version 9.2.0 au lieu de 9.1.5', $page->corps);

        $boite = new ExpediteurMemoire();
        $alertes = new Alertes($this->pdo, $boite, self::URL);
        $alertes->boutiquesHorsGel(count(Releve::horsGel($this->pdo)));
        $alertes->expedier();
        self::assertCount(1, $boite->envoyes);
        self::assertSame(['admin@exemple.fr'], $boite->envoyes[0]['destinataires']);
        self::assertStringNotContainsString('durandca', $boite->envoyes[0]['texte'], 'Aucun étudiant nommé dans l\'e-mail');
    }

    // ===== Aides =====

    private function travailleur(): Travailleur
    {
        $test = $this;
        $cpanel = new class ($test) implements ServeurCpanel {
            public function __construct(private readonly PrestaShopTest $test)
            {
            }

            public function sousDomaines(): array
            {
                return $this->test->cpanel['sousDomaines'];
            }

            public function creerBase(string $base): void
            {
                $this->noter('creerBase ' . $base);
            }

            public function creerUtilisateur(string $utilisateur, string $motDePasse): void
            {
                $this->noter('creerUtilisateur ' . $utilisateur);
            }

            public function donnerDroits(string $utilisateur, string $base): void
            {
                $this->noter('donnerDroits ' . $utilisateur . ' ' . $base);
            }

            public function supprimerBase(string $base): void
            {
                $this->noter('supprimerBase ' . $base);
            }

            public function supprimerUtilisateur(string $utilisateur): void
            {
                $this->noter('supprimerUtilisateur ' . $utilisateur);
            }

            private function noter(string $appel): void
            {
                if ($this->test->cpanel['refuser'] !== null && str_starts_with($appel, $this->test->cpanel['refuser'])) {
                    throw new RuntimeException('CPanel a refusé ' . $appel);
                }
                $this->test->cpanel['appels'][] = $appel;
            }
        };
        $installateur = new class ($test) extends InstallateurPrestaShop {
            public function __construct(private readonly PrestaShopTest $test)
            {
            }

            public function installer(string $dossier, array $options): void
            {
                if ($this->test->echecInstallation !== null) {
                    throw new RuntimeException('Installation PrestaShop échouée : ' . $this->test->echecInstallation);
                }
                mkdir($dossier . '/app/config', 0755, true);
                file_put_contents($dossier . '/app/config/parameters.php', '<?php');
            }

            public function activerHttps(string $base, string $utilisateur, string $motDePasse): void
            {
            }
        };
        $executant = new ExecutantCreerPrestaShop($this->pdo, $cpanel, new Bibliotheque($this->pdo, $this->donnees), $installateur,
            $this->chiffrement, $this->boite, $this->personnel, $this->donnees . '/tmp');

        return new Travailleur($this->pdo, new Alertes($this->pdo, $this->boite, self::URL), ['creer_prestashop' => $executant], 0);
    }

    private function compte(string $identifiant): Compte
    {
        $compte = (new DepotComptes($this->pdo))->parIdentifiant($identifiant);
        self::assertNotNull($compte);

        return $compte;
    }

    /**
     * @param array<string, string> $champs
     * @param array<string, list<string>> $listes
     */
    private function en(string $identifiant, string $methode, string $chemin, array $champs = [], array $listes = []): Reponse
    {
        $compte = $this->compte($identifiant);
        $session = new Session(new SessionMemoire(), $this->horloge);
        $session->connecter($compte->id, $compte->generation);
        if ($methode === 'POST') {
            $champs['jeton'] = $session->jeton();
        }

        return (new Application($this->pdo, $this->chiffrement, $this->horloge, $session, self::URL, null, $this->donnees))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null, $listes));
    }
}
