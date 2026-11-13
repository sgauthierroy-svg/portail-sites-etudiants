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
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Sites\ConfigWordPress;
use Portail\Sites\LigneWordPress;
use Portail\Sites\MiseALecart;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Sites\SiteModele;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\ExpediteurMemoire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Travaux\ExecutantGelerSujetModele;
use Portail\Travaux\ExecutantInstallerSujetModele;
use Portail\Travaux\Travailleur;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;
use RuntimeException;
use ZipArchive;

/**
 * V2, lot 4 : site modèle d'examen (CPanel et WP-CLI simulés, noms inventés).
 */
final class SiteModeleTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    private DossierTemporaire $dossier;
    private string $personnel;
    private string $donnees;
    private PDO $pdo;
    private ExpediteurMemoire $boite;
    private int $paquet;
    /** @var array{appels: list<string>, refuser: ?string, sousDomaines: array<string, string>} */
    public array $cpanel = ['appels' => [], 'refuser' => null, 'sousDomaines' => []];
    /** @var list<array{chemin: string, arguments: list<string>}> */
    public array $wp = [];
    public ?string $echecWp = null;
    public string $origine = 'http://martinel.bts-sup-sallanches.com/WP';

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
        $this->pdo->exec("INSERT INTO parametres (cle, valeur) VALUES ('adresse_alertes', 'admin@exemple.fr'), ('sites_proteges', '[\"peda\",\"sujet\"]'), ('bases_protegees', '[\"jafl3067_peda\"]')");
        $this->boite = new ExpediteurMemoire();
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'jroux' => 'administratif', 'pmoreau' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        mkdir($this->personnel . '/sujet.bts-sup-sallanches.com');
        $this->cpanel['sousDomaines']['sujet'] = $this->personnel . '/sujet.bts-sup-sallanches.com';
        Releve::enregistrer($this->pdo, $this->cpanel['sousDomaines'], [], '2026-11-13T12:00:00Z');
        $this->paquet = (new Bibliotheque($this->pdo, $this->donnees, $this->compte('cpetit')))
            ->ajouterPaquet($this->duplicator(), 'chocosup_archive.zip', 'sujet_wordpress', 'Chocosup', '');
    }

    // ===== Côté web =====

    public function testInstallationInscriteAvecConfirmationSiUnModeleExiste(): void
    {
        $modele = new SiteModele($this->pdo);
        $id = $modele->installer($this->paquet, false, $this->compte('cpetit'), '203.0.113.5');
        self::assertSame('installer_sujet_modele', $this->pdo->query('SELECT type FROM travaux WHERE id = ' . $id)->fetchColumn());
        self::assertSame('sujet', $this->pdo->query('SELECT cible FROM travaux_etapes WHERE travail_id = ' . $id)->fetchColumn());
        try {
            $modele->installer($this->paquet, false, $this->compte('cpetit'), null);
            self::fail('Deux travaux à la fois sur le modèle');
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString('déjà prévu ou en cours', $e->getMessage());
        }
        $this->tourner();
        try {
            $modele->installer($this->paquet, false, $this->compte('cpetit'), null);
            self::fail('Remplacement sans confirmation');
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString('Cochez la case', $e->getMessage());
        }
        self::assertIsInt($modele->installer($this->paquet, true, $this->compte('cpetit'), null));
    }

    public function testRefusSiLeSousDomaineNEstPasReleveOuSujetInconnu(): void
    {
        $modele = new SiteModele($this->pdo);
        try {
            $modele->installer(999, false, $this->compte('cpetit'), null);
            self::fail('Paquet inconnu');
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString('Choisissez un sujet', $e->getMessage());
        }
        Releve::enregistrer($this->pdo, [], [], '2026-11-13T13:00:00Z');
        $this->expectException(ErreurSaisie::class);
        $this->expectExceptionMessage('pas dans le dernier relevé');
        $modele->installer($this->paquet, false, $this->compte('cpetit'), null);
    }

    public function testPageReserveeAuxEnseignantsEtAdministrateurs(): void
    {
        foreach (['mzanelli' => 200, 'cpetit' => 200, 'jroux' => 403, 'pmoreau' => 403] as $compte => $code) {
            $reponse = $this->en($compte, 'GET', '/sites/sujet');
            self::assertSame($code, $reponse->statut, $compte);
            if ($code === 200) {
                self::assertStringContainsString('Installer ce sujet sur le site modèle', $reponse->corps);
                self::assertStringContainsString('Chocosup', $reponse->corps);
            }
        }
        $reponse = $this->en('cpetit', 'POST', '/sites/sujet', ['action' => 'installer', 'paquet' => (string) $this->paquet]);
        self::assertSame(303, $reponse->statut);
        self::assertMatchesRegularExpression('#^/travaux/[0-9]+$#', $reponse->enTetes['Location']);
        // « sujet » n'est pas présenté comme un sous-domaine sans étudiant.
        $tout = $this->en('mzanelli', 'GET', '/sites')->corps;
        self::assertStringContainsString('Site modèle d\'examen : <code>sujet</code>', $tout);
        self::assertStringNotContainsString('Sous-domaines sans étudiant', $tout);
    }

    // ===== Travailleur =====

    public function testInstallationComplete(): void
    {
        (new SiteModele($this->pdo))->installer($this->paquet, false, $this->compte('cpetit'), null);
        $this->tourner();
        $etape = $this->derniereEtape();
        self::assertSame('reussi', $etape['etat'], $etape['message']);
        $wp = $this->personnel . '/sujet.bts-sup-sallanches.com/WP';

        // Fichiers : WordPress en place ; Duplicator et ses paquets retirés ; wp-config et .htaccess du portail.
        self::assertFileExists($wp . '/wp-includes/version.php');
        self::assertFileDoesNotExist($wp . '/dup-installer');
        self::assertFileDoesNotExist($wp . '/installer.php');
        self::assertFileDoesNotExist($wp . '/dup-wp-config-arc__abc123.txt');
        self::assertFileDoesNotExist($wp . '/wp-content/backups-dup-lite');
        $config = (string) file_get_contents($wp . '/wp-config.php');
        self::assertStringContainsString("\$table_prefix = 'wpx_';", $config);
        self::assertMatchesRegularExpression("/define\\('DB_NAME', 'jafl3067_sujet_wp_[a-z0-9]{6}'\\);/", $config);
        self::assertFalse(ConfigWordPress::estGele($config));
        self::assertSame(0600, fileperms($wp . '/wp-config.php') & 0777);
        self::assertStringContainsString('RewriteBase /WP/', (string) file_get_contents($wp . '/.htaccess'));

        // CPanel : base et utilisateur neufs.
        self::assertCount(3, $this->cpanel['appels']);
        self::assertMatchesRegularExpression('/^creerBase jafl3067_sujet_wp_[a-z0-9]{6}$/', $this->cpanel['appels'][0]);

        // WP-CLI : import de la base filtrée, adresses http et https et échappées, chemin d'origine, réglages.
        $commandes = array_map(static fn (array $a): string => implode(' ', $a['arguments']), $this->wp);
        self::assertContains('search-replace http://martinel.bts-sup-sallanches.com/WP https://sujet.bts-sup-sallanches.com/WP --all-tables-with-prefix --quiet', $commandes);
        self::assertContains('search-replace https://martinel.bts-sup-sallanches.com/WP https://sujet.bts-sup-sallanches.com/WP --all-tables-with-prefix --quiet', $commandes);
        self::assertContains('search-replace http:\/\/martinel.bts-sup-sallanches.com\/WP https:\/\/sujet.bts-sup-sallanches.com\/WP --all-tables-with-prefix --quiet', $commandes);
        self::assertContains('search-replace ' . $this->personnel . '/martinel.bts-sup-sallanches.com/WP ' . $wp . ' --all-tables-with-prefix --quiet', $commandes);
        self::assertContains('option update blog_public 0', $commandes);
        self::assertContains('option update admin_email admin@exemple.fr', $commandes);
        foreach ($commandes as $commande) {
            self::assertStringNotContainsString('DB_PASSWORD', $commande);
        }

        // Base : site connu du portail, version lue.
        $site = $this->pdo->query("SELECT version, gele FROM sites WHERE identifiant = 'sujet' AND dossier = '/WP'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['version' => '6.8.3', 'gele' => 0], $site);

        // Liste de contrôle à l'administrateur : extension dangereuse signalée, comptes listés.
        self::assertCount(1, $this->boite->envoyes);
        $mail = $this->boite->envoyes[0];
        self::assertSame(['admin@exemple.fr'], $mail['destinataires']);
        self::assertStringContainsString('Cpetit a installé le sujet « Chocosup »', $mail['texte']);
        self::assertStringContainsString('wp-file-manager 8.0 (inactive)   ⚠ DONNE ACCÈS AUX FICHIERS DU SERVEUR', $mail['texte']);
        self::assertStringContainsString('martinel (administrator)', $mail['texte']);
        self::assertStringContainsString('ne bloque rien', $mail['texte']);
    }

    public function testRemplacementMetLAncienModeleALEcartEtPurgeApresHuitJours(): void
    {
        $modele = new SiteModele($this->pdo);
        $modele->installer($this->paquet, false, $this->compte('cpetit'), null);
        $this->tourner();
        $premiereBase = (string) $this->pdo->query("SELECT base FROM sites WHERE identifiant = 'sujet'")->fetchColumn();
        file_put_contents($this->personnel . '/sujet.bts-sup-sallanches.com/WP/marque.txt', 'premier modèle');

        $modele->installer($this->paquet, true, $this->compte('cpetit'), null);
        $this->tourner();
        self::assertSame('reussi', $this->derniereEtape()['etat'], $this->derniereEtape()['message']);
        self::assertFileDoesNotExist($this->personnel . '/sujet.bts-sup-sallanches.com/WP/marque.txt');
        $ecarts = MiseALecart::enCours($this->pdo, 'sujet');
        self::assertCount(1, $ecarts);
        $archive = $this->personnel . '/archives_suppression/portail/' . $ecarts[0]['chemin'];
        self::assertSame('premier modèle', file_get_contents($archive . '/marque.txt'));
        self::assertNotSame($premiereBase, $this->pdo->query("SELECT base FROM sites WHERE identifiant = 'sujet'")->fetchColumn());

        // Purge : rien avant 8 jours ; ensuite dossier, utilisateur et base de l'ancien modèle effacés.
        $ecart = new MiseALecart($this->pdo, $this->personnel);
        $cpanel = $this->fauxCpanel();
        $this->cpanel['appels'] = [];
        self::assertNull($ecart->purgerUne($cpanel, ['jafl3067_peda'], time() + 7 * 86400));
        self::assertSame($ecarts[0]['chemin'], $ecart->purgerUne($cpanel, ['jafl3067_peda'], time() + 9 * 86400));
        self::assertDirectoryDoesNotExist($archive);
        self::assertSame(['supprimerUtilisateur ' . str_replace('sujet_wp_', 'sujet_', $premiereBase), 'supprimerBase ' . $premiereBase], $this->cpanel['appels']);
        self::assertSame([], MiseALecart::enCours($this->pdo, 'sujet'));
    }

    public function testPurgeNEffacePasLaBaseDUnSiteRemisALaMain(): void
    {
        $modele = new SiteModele($this->pdo);
        $modele->installer($this->paquet, false, $this->compte('cpetit'), null);
        $this->tourner();
        $modele->installer($this->paquet, true, $this->compte('cpetit'), null);
        $this->tourner();
        // Retour en arrière fait à la main : l'ancien modèle est remis à sa place.
        $ecarts = MiseALecart::enCours($this->pdo, 'sujet');
        $wp = $this->personnel . '/sujet.bts-sup-sallanches.com/WP';
        rename($wp, $this->personnel . '/nouveau');
        rename($this->personnel . '/archives_suppression/portail/' . $ecarts[0]['chemin'], $wp);
        $this->cpanel['appels'] = [];
        self::assertSame($ecarts[0]['chemin'], (new MiseALecart($this->pdo, $this->personnel))->purgerUne($this->fauxCpanel(), [], time() + 9 * 86400));
        self::assertSame([], $this->cpanel['appels']);
        self::assertFileExists($wp . '/wp-config.php');
    }

    public function testEchecAvantBasculeNeChangeRienEtEffaceCeQuiAEteCree(): void
    {
        $modele = new SiteModele($this->pdo);
        $modele->installer($this->paquet, false, $this->compte('cpetit'), null);
        $this->tourner();
        file_put_contents($this->personnel . '/sujet.bts-sup-sallanches.com/WP/marque.txt', 'modèle en place');
        $this->cpanel['appels'] = [];
        $this->echecWp = 'db import';

        $modele->installer($this->paquet, true, $this->compte('cpetit'), null);
        $this->tourner();
        $etape = $this->derniereEtape();
        self::assertSame('echec', $etape['etat']);
        self::assertStringContainsString('Le site modèle n\'a pas changé', $etape['message']);
        self::assertSame('modèle en place', file_get_contents($this->personnel . '/sujet.bts-sup-sallanches.com/WP/marque.txt'));
        self::assertSame([], MiseALecart::enCours($this->pdo, 'sujet'));
        self::assertSame(['creerBase', 'creerUtilisateur', 'donnerDroits', 'supprimerUtilisateur', 'supprimerBase'],
            array_map(static fn (string $a): string => explode(' ', $a)[0], $this->cpanel['appels']));
        self::assertSame([], glob($this->donnees . '/tmp/sujet-*'));
    }

    public function testRefusSiLeDossierDuSousDomaineEstInattendu(): void
    {
        $this->cpanel['sousDomaines']['sujet'] = '/ailleurs/sujet';
        (new SiteModele($this->pdo))->installer($this->paquet, false, $this->compte('cpetit'), null);
        $this->tourner();
        self::assertStringContainsString('inattendu', $this->derniereEtape()['message']);
        self::assertSame([], $this->cpanel['appels']);
    }

    public function testGelEtDegelNeTouchentQueLeBlocDuPortail(): void
    {
        $modele = new SiteModele($this->pdo);
        $modele->installer($this->paquet, false, $this->compte('cpetit'), null);
        $this->tourner();
        $fichier = $this->personnel . '/sujet.bts-sup-sallanches.com/WP/wp-config.php';
        $avant = (string) file_get_contents($fichier);

        $modele->geler(true, $this->compte('cpetit'), null);
        $this->tourner();
        self::assertSame('reussi', $this->derniereEtape()['etat'], $this->derniereEtape()['message']);
        $gele = (string) file_get_contents($fichier);
        self::assertTrue(ConfigWordPress::estGele($gele));
        self::assertStringContainsString("define('WP_AUTO_UPDATE_CORE', false);", $gele);
        self::assertSame(0600, fileperms($fichier) & 0777);
        self::assertTrue((new SiteModele($this->pdo))->etat()['gele']);

        $modele->geler(false, $this->compte('cpetit'), null);
        $this->tourner();
        self::assertSame($avant, file_get_contents($fichier));

        // Un nouveau sujet garde le gel du modèle.
        $modele->geler(true, $this->compte('cpetit'), null);
        $this->tourner();
        $modele->installer($this->paquet, true, $this->compte('cpetit'), null);
        $this->tourner();
        self::assertTrue(ConfigWordPress::estGele((string) file_get_contents($fichier)));
    }

    // ===== Briques =====

    public function testBlocAjouteDansUnWpConfigEcritALaMain(): void
    {
        $manuel = "<?php\ndefine('DB_NAME', 'x');\n\$table_prefix = 'wp_';\n/* That's all, stop editing! */\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";
        $gele = ConfigWordPress::remplacerBloc($manuel, ['gele' => true]);
        self::assertTrue(ConfigWordPress::estGele($gele));
        self::assertLessThan(strpos($gele, "if ( ! defined( 'ABSPATH' ) )"), strpos($gele, ConfigWordPress::FIN_BLOC));
        self::assertStringContainsString("define('DB_NAME', 'x');", $gele);
        self::assertFalse(ConfigWordPress::estGele(ConfigWordPress::remplacerBloc($gele, ['gele' => false])));
        $this->expectException(RuntimeException::class);
        ConfigWordPress::remplacerBloc("<?php\n// rien\n", ['gele' => true]);
    }

    public function testPrefixeDesTablesEtFiltrageDeLExport(): void
    {
        $sql = $this->donnees . '/tmp/base.sql';
        file_put_contents($sql, "mysqldump: [Warning] Using a password on the command line interface can be insecure.\n"
            . "CREATE TABLE `wpx_actionscheduler_options` (\nCREATE TABLE `wpx_options` (\nCREATE TABLE `wpx_users` (\nINSERT INTO `wpx_options` VALUES (1);\n");
        self::assertSame('wpx_', ExecutantInstallerSujetModele::prefixeTables($sql));
        ExecutantInstallerSujetModele::filtrerSql($sql, $this->donnees . '/tmp/filtre.sql');
        self::assertStringNotContainsString('mysqldump:', (string) file_get_contents($this->donnees . '/tmp/filtre.sql'));
        self::assertStringContainsString('INSERT INTO `wpx_options`', (string) file_get_contents($this->donnees . '/tmp/filtre.sql'));
    }

    public function testMiseAJourDeLaBaseGardeLesTravauxExistants(): void
    {
        // Base arrêtée à la version 9, avec un travail et son étape, puis passage à la version 10.
        $pdo = Connexion::ouvrir($this->donnees . '/ancienne.sqlite');
        $pdo->exec('CREATE TABLE schema_versions (version INTEGER PRIMARY KEY, appliquee_le TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        foreach (Schema::versions() as $version => $sql) {
            if ($version < 10) {
                $pdo->exec($sql);
                $pdo->exec('INSERT INTO schema_versions (version) VALUES (' . $version . ')');
            }
        }
        $pdo->exec("INSERT INTO travaux (type, demande_par_nom, parametres) VALUES ('creer_prestashop', 'Cpetit', '{\"paquet_id\":1}')");
        $pdo->exec("INSERT INTO travaux_etapes (travail_id, rang, cible, libelle, etat) VALUES (1, 1, 'martinel', 'MARTIN Élodie', 'reussi')");

        self::assertSame([10], Schema::mettreAJour($pdo));
        self::assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame(['creer_prestashop', '{"paquet_id":1}'], $pdo->query('SELECT type, parametres FROM travaux')->fetch(PDO::FETCH_NUM));
        self::assertSame('martinel', $pdo->query('SELECT cible FROM travaux_etapes')->fetchColumn());
        $pdo->exec("INSERT INTO travaux (type, demande_par_nom) VALUES ('installer_sujet_modele', 'Cpetit')");
        // La suppression en cascade des étapes fonctionne toujours sur la table refaite.
        $pdo->exec('DELETE FROM travaux WHERE id = 1');
        self::assertSame(0, (int) $pdo->query('SELECT count(*) FROM travaux_etapes')->fetchColumn());
        self::assertSame('0', (string) $pdo->query('SELECT count(*) FROM pragma_foreign_key_check')->fetchColumn());
    }

    // ===== Outils de test =====

    private function tourner(): void
    {
        $cpanel = $this->fauxCpanel();
        $ecart = new MiseALecart($this->pdo, $this->personnel);
        $travailleur = new Travailleur($this->pdo, new Alertes($this->pdo, null, self::URL), [
            'installer_sujet_modele' => new ExecutantInstallerSujetModele($this->pdo, $cpanel, new Bibliotheque($this->pdo, $this->donnees),
                $this->fauxWp(), $ecart, $this->boite, $this->personnel, $this->donnees . '/tmp'),
            'geler_sujet_modele' => new ExecutantGelerSujetModele($this->pdo, $cpanel, $this->personnel),
        ], 0, static function (int $s): void {
        });
        $travailleur->tourner(60);
    }

    /** @return array{etat: string, message: string} */
    private function derniereEtape(): array
    {
        $ligne = $this->pdo->query('SELECT etat, message FROM travaux_etapes ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        return ['etat' => (string) $ligne['etat'], 'message' => (string) $ligne['message']];
    }

    private function fauxCpanel(): ServeurCpanel
    {
        $test = $this;

        return new class ($test) implements ServeurCpanel {
            public function __construct(private readonly SiteModeleTest $test)
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

            public function creerUtilisateur(string $utilisateur, #[\SensitiveParameter] string $motDePasse): void
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
                $this->test->cpanel['appels'][] = $appel;
            }
        };
    }

    private function fauxWp(): LigneWordPress
    {
        $test = $this;

        return new class ($test) implements LigneWordPress {
            public function __construct(private readonly SiteModeleTest $test)
            {
            }

            public function lancer(string $chemin, array $arguments, int $delaiSecondes = 300): string
            {
                $this->test->wp[] = ['chemin' => $chemin, 'arguments' => $arguments];
                $commande = implode(' ', array_slice($arguments, 0, 2));
                if ($this->test->echecWp === $commande) {
                    throw new RuntimeException('WP-CLI (' . $commande . ') a échoué : simulé.');
                }
                if (!is_file($chemin . '/wp-config.php')) {
                    throw new RuntimeException('wp-config.php absent');
                }

                return match ($commande) {
                    'option get' => $this->test->origine . "\n",
                    'plugin list' => '[{"name":"wpforms-lite","status":"active","version":"1.9"},{"name":"wp-file-manager","status":"inactive","version":"8.0"}]',
                    'user list' => '[{"user_login":"martinel","roles":"administrator"}]',
                    'theme list' => "astra\n",
                    'db import' => is_file($arguments[2] ?? '') ? 'Success' : throw new RuntimeException('fichier SQL absent'),
                    default => '',
                };
            }
        };
    }

    /** Paquet Duplicator simulé : WordPress, base, fichiers propres à Duplicator, un paquet rangé dans le site. */
    private function duplicator(): string
    {
        $chemin = $this->donnees . '/depot/chocosup_archive.zip';
        $zip = new ZipArchive();
        $zip->open($chemin, ZipArchive::CREATE);
        $zip->addFromString('index.php', '<?php');
        $zip->addFromString('wp-includes/version.php', "<?php\n\$wp_version = '6.8.3';\n");
        $zip->addFromString('wp-content/plugins/wpforms-lite/wpforms.php', '<?php');
        $zip->addFromString('wp-content/backups-dup-lite/ancien_archive.zip', 'paquet');
        $zip->addFromString('dup-installer/main.installer.php', '<?php');
        $zip->addFromString('dup-installer/dup-database__abc123.sql', "mysqldump: [Warning] avertissement\nCREATE TABLE `wpx_options` (\nCREATE TABLE `wpx_users` (\n");
        $zip->addFromString('dup-wp-config-arc__abc123.txt', "<?php define('DB_PASSWORD', 'ancien');");
        $zip->addFromString('installer.php', '<?php');
        $zip->close();

        return $chemin;
    }

    /** @param array<string, string> $champs */
    private function en(string $identifiant, string $methode, string $chemin, array $champs = []): Reponse
    {
        $compte = $this->compte($identifiant);
        $horloge = new HorlogeFixe(1_794_000_000);
        $session = new Session(new SessionMemoire(), $horloge);
        $session->connecter($compte->id, $compte->generation);
        if ($methode === 'POST') {
            $champs['jeton'] = $session->jeton();
        }

        return (new Application($this->pdo, new Chiffrement(random_bytes(32)), $horloge, $session, self::URL, null, $this->donnees))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null, []));
    }

    private function compte(string $identifiant): Compte
    {
        $compte = (new DepotComptes($this->pdo))->parIdentifiant($identifiant);
        self::assertNotNull($compte);

        return $compte;
    }
}
