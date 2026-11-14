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
use Portail\Etudiants\GestionEtudiants;
use Portail\Etudiants\RegimesSessions;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Sites\DeploiementSujet;
use Portail\Sites\EpreuveSujet;
use Portail\Sites\EpreuveWordPress;
use Portail\Sites\LigneWordPress;
use Portail\Sites\MiseALecart;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Sites\SiteModele;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\ExpediteurMemoire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Travaux\ExecutantCloturerEpreuve;
use Portail\Travaux\ExecutantDeployerSujet;
use Portail\Travaux\ExecutantGelEpreuve;
use Portail\Travaux\Travailleur;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;
use RuntimeException;

/**
 * V2, lot 4, paquet 2 : déploiement du site modèle dans les /WP2 (CPanel et WP-CLI simulés, noms inventés).
 */
final class DeploiementSujetTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    private DossierTemporaire $dossier;
    private string $personnel;
    private string $donnees;
    private string $modele;
    private PDO $pdo;
    private ExpediteurMemoire $boite;
    private int $session;
    /** @var array{appels: list<string>, sousDomaines: array<string, string>} */
    public array $cpanel = ['appels' => [], 'sousDomaines' => []];
    /** @var list<array{chemin: string, arguments: list<string>}> */
    public array $wp = [];
    /** Commande WP-CLI à faire échouer (ex. « db import ») */
    public ?string $echecWp = null;
    /** @var array<string, string> chemin du site => SQL importé */
    public array $imports = [];

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
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'lblanc' => 'enseignant', 'jroux' => 'administratif', 'pmoreau' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec("UPDATE comptes SET doit_changer_mdp = 0");
        $this->pdo->exec("UPDATE comptes SET actif = 0 WHERE identifiant = 'lblanc'");
        $referentiel = new RegimesSessions($this->pdo);
        $fi = $referentiel->creerRegime('NDRC FI');
        $this->session = $referentiel->creerSession('2027');
        $gestion = new GestionEtudiants($this->pdo, $this->compte('mzanelli'));
        foreach ([['MARTIN', 'Élodie', 'martinel'], ['DURAND', 'Camille', 'durandca'], ['LEROY', 'Hugo', 'leroyhu']] as [$nom, $prenom, $id]) {
            $gestion->ajouter($nom, $prenom, $id . '@exemple.fr', $fi, $this->session, $id);
        }
        foreach (['martinel', 'durandca', 'sujet'] as $id) {
            mkdir($this->personnel . '/' . $id . '.bts-sup-sallanches.com');
            $this->cpanel['sousDomaines'][$id] = $this->personnel . '/' . $id . '.bts-sup-sallanches.com';
        }

        // Site modèle installé par le portail (paquet 1).
        $this->modele = $this->personnel . '/sujet.bts-sup-sallanches.com/WP';
        $this->fichier($this->modele . '/wp-config.php', "<?php define('DB_PASSWORD', 'secret-du-modele');");
        $this->fichier($this->modele . '/index.php', '<?php');
        $this->fichier($this->modele . '/.htaccess', '# modèle');
        $this->fichier($this->modele . '/error_log', 'erreur');
        $this->fichier($this->modele . '/wp-includes/version.php', "<?php\n\$wp_version = '7.1.4';\n");
        $this->fichier($this->modele . '/wp-content/uploads/2026/11/serum.png', 'image');
        $this->fichier($this->modele . '/wp-content/backups-dup-lite/ancien_archive.zip', 'paquet');
        symlink('/etc', $this->modele . '/wp-content/lien-dangereux');
        $this->pdo->exec("INSERT INTO sites (identifiant, dossier, logiciel, version, base, utilisateur_bdd, gele)
            VALUES ('sujet', '/WP', 'wordpress', '7.1.4', 'jafl3067_sujet_wp_abc234', 'jafl3067_sujet_abc234', 1)");

        // /WP2 d'une année précédente, fait par deploy_duplicator.sh (utilisateur MySQL partagé).
        $ancien = $this->personnel . '/durandca.bts-sup-sallanches.com/WP2';
        $this->fichier($ancien . '/wp-config.php', "<?php\ndefine( 'DB_NAME', 'jafl3067_durandca_WP2' );\ndefine( 'DB_USER', 'jafl3067_peda' );\n");
        $this->fichier($ancien . '/index.php', '<?php // ancien');
        Releve::enregistrer($this->pdo, $this->cpanel['sousDomaines'],
            ['durandca' => [['dossier' => '/WP2', 'logiciel' => 'wordpress', 'version' => '6.8.3', 'module_mise_a_jour' => null]]], '2026-11-14T12:00:00Z');
    }

    // ===== Déploiement complet =====

    public function testDeploiementCompletPhotoPuisUnWp2ParEtudiant(): void
    {
        $id = (new DeploiementSujet($this->pdo))->lancer($this->session, ['durandca', 'martinel'], true, $this->compte('cpetit'), '203.0.113.5');
        $etapes = $this->pdo->query('SELECT cible FROM travaux_etapes WHERE travail_id = ' . $id . ' ORDER BY rang')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['sujet', 'durandca', 'martinel'], $etapes, 'photo d\'abord, puis ordre alphabétique des noms');
        $this->tourner();

        $etats = $this->pdo->query('SELECT cible, etat, message FROM travaux_etapes WHERE travail_id = ' . $id . ' ORDER BY rang')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($etats as $etape) {
            self::assertSame('reussi', $etape['etat'], $etape['cible'] . ' : ' . $etape['message']);
        }
        self::assertStringContainsString('Photo du site modèle prise (WordPress 7.1.4', (string) $etats[0]['message']);

        $wp2 = $this->personnel . '/martinel.bts-sup-sallanches.com/WP2';
        self::assertFileExists($wp2 . '/index.php');
        self::assertFileExists($wp2 . '/wp-content/uploads/2026/11/serum.png');
        self::assertFileDoesNotExist($wp2 . '/error_log');
        self::assertFileDoesNotExist($wp2 . '/wp-content/lien-dangereux', 'un lien du modèle n\'est jamais recopié');
        self::assertDirectoryDoesNotExist($wp2 . '/wp-content/backups-dup-lite');
        self::assertStringContainsString('RewriteBase /WP2/', (string) file_get_contents($wp2 . '/.htaccess'));
        $config = (string) file_get_contents($wp2 . '/wp-config.php');
        self::assertMatchesRegularExpression("/define\('DB_NAME', 'jafl3067_martinel_WP2_[a-z0-9]{6}'\)/", $config);
        self::assertMatchesRegularExpression("/define\('DB_USER', 'jafl3067_martinel_w2[a-z0-9]{6}'\)/", $config);
        self::assertStringNotContainsString('secret-du-modele', $config);
        self::assertStringContainsString("'AUTOMATIC_UPDATER_DISABLED', true", $config, 'version gelée');
        self::assertStringContainsString("\$table_prefix = 'wpx_'", $config);
        self::assertSame(0600, fileperms($wp2 . '/wp-config.php') & 0777);

        // Les commandes WP-CLI tournent dans le dossier de préparation, avant la bascule.
        $preparation = '';
        foreach (array_keys($this->imports) as $chemin) {
            $preparation = str_contains($chemin, '/tmp/wp2-martinel-') ? $chemin : $preparation;
        }
        self::assertNotSame('', $preparation);
        // Base : celle de la photo, sans la ligne d'avertissement de mysqldump.
        self::assertStringNotContainsString('mysqldump:', $this->imports[$preparation]);
        self::assertStringContainsString('CREATE TABLE `wpx_options`', $this->imports[$preparation]);
        // Adresses du modèle → /WP2 de l'étudiant (http, https, échappées), puis chemin du dossier.
        $remplacements = array_map(static fn (array $a): string => $a['arguments'][1] . ' → ' . $a['arguments'][2],
            array_values(array_filter($this->wp, static fn (array $a): bool => $a['chemin'] === $preparation && $a['arguments'][0] === 'search-replace')));
        self::assertContains('https://sujet.bts-sup-sallanches.com/WP → https://martinel.bts-sup-sallanches.com/WP2', $remplacements);
        self::assertContains('http://sujet.bts-sup-sallanches.com/WP → https://martinel.bts-sup-sallanches.com/WP2', $remplacements);
        self::assertContains('https:\/\/sujet.bts-sup-sallanches.com\/WP → https:\/\/martinel.bts-sup-sallanches.com\/WP2', $remplacements);
        self::assertContains($this->modele . ' → ' . $wp2, $remplacements);
        $commandes = array_map(static fn (array $a): string => implode(' ', $a['arguments']), array_filter($this->wp, static fn (array $a): bool => $a['chemin'] === $preparation));
        self::assertContains('option update blog_public 0', $commandes);
        self::assertContains('option update admin_email admin@exemple.fr', $commandes);
        self::assertContains("db query DELETE FROM `wpx_usermeta` WHERE meta_key = 'session_tokens'", $commandes);

        // Base et utilisateur propres à chaque /WP2 ; rien n'est supprimé.
        self::assertCount(2, array_filter($this->cpanel['appels'], static fn (string $a): bool => str_starts_with($a, 'creerUtilisateur jafl3067_')));
        self::assertSame([], array_filter($this->cpanel['appels'], static fn (string $a): bool => str_starts_with($a, 'supprimer')));
        $site = $this->pdo->query("SELECT version, gele, base FROM sites WHERE identifiant = 'martinel' AND dossier = '/WP2'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['version' => '7.1.4', 'gele' => 1], ['version' => $site['version'], 'gele' => (int) $site['gele']]);

        // Ancien /WP2 : mis à l'écart, sa base retenue, l'utilisateur partagé jamais.
        self::assertStringNotContainsString('ancien', (string) file_get_contents($this->personnel . '/durandca.bts-sup-sallanches.com/WP2/index.php'));
        $ecart = $this->pdo->query("SELECT chemin, base, utilisateur_bdd FROM mises_a_l_ecart WHERE identifiant = 'durandca'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('jafl3067_durandca_WP2', $ecart['base']);
        self::assertNull($ecart['utilisateur_bdd'], 'jafl3067_peda ne doit jamais être effacé');
        self::assertFileExists($this->personnel . '/archives_suppression/portail/' . $ecart['chemin'] . '/index.php');

        // Photo gardée 8 jours à l'écart, plus rien dans data/tmp/photos.
        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM mises_a_l_ecart WHERE identifiant = 'sujet' AND dossier = '/photo'")->fetchColumn());
        self::assertSame([], array_values(array_diff((array) scandir($this->donnees . '/tmp/photos'), ['.', '..'])));

        // E-mail : administrateur et enseignants actifs, liens des sites, aucun mot de passe.
        self::assertCount(1, $this->boite->envoyes);
        $envoi = $this->boite->envoyes[0];
        self::assertSame(['admin@exemple.fr', 'cpetit@exemple.fr'], $envoi['destinataires']);
        self::assertStringContainsString('2 /WP2 sur 2', $envoi['texte']);
        self::assertStringContainsString('https://martinel.bts-sup-sallanches.com/WP2/wp-admin/', $envoi['texte']);
        self::assertStringNotContainsString('secret-du-modele', $envoi['texte']);
    }

    public function testEchecChezUnEtudiantLaisseSonWp2Intact(): void
    {
        (new DeploiementSujet($this->pdo))->lancer($this->session, ['durandca'], true, $this->compte('cpetit'), null);
        $this->echecWp = 'db import';
        $this->tourner();
        $etapes = $this->pdo->query("SELECT cible, etat, message FROM travaux_etapes ORDER BY rang")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame('reussi', $etapes[0]['etat']);
        self::assertSame('echec', $etapes[1]['etat']);
        self::assertStringContainsString('n\'a pas changé', (string) $etapes[1]['message']);
        self::assertStringContainsString('ancien', (string) file_get_contents($this->personnel . '/durandca.bts-sup-sallanches.com/WP2/index.php'));
        self::assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM mises_a_l_ecart WHERE identifiant = 'durandca'")->fetchColumn());
        // Ce que l'étape avait créé est effacé.
        self::assertCount(1, array_filter($this->cpanel['appels'], static fn (string $a): bool => str_starts_with($a, 'supprimerBase jafl3067_durandca_WP2_')));
        self::assertCount(1, array_filter($this->cpanel['appels'], static fn (string $a): bool => str_starts_with($a, 'supprimerUtilisateur jafl3067_durandca_w2')));
        self::assertSame([], glob($this->donnees . '/tmp/wp2-*'));
        self::assertStringContainsString('0 /WP2 sur 1', $this->boite->envoyes[0]['texte']);
    }

    public function testPhotoEnEchecAucunEtudiantNeRecoitLeSujet(): void
    {
        (new DeploiementSujet($this->pdo))->lancer($this->session, ['martinel'], false, $this->compte('cpetit'), null);
        $this->echecWp = 'db export';
        $this->tourner();
        $etapes = $this->pdo->query("SELECT etat, message FROM travaux_etapes ORDER BY rang")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame('echec', $etapes[0]['etat']);
        self::assertStringContainsString('relancez le déploiement', (string) $etapes[0]['message']);
        self::assertSame('echec', $etapes[1]['etat']);
        self::assertStringContainsString('Photo du site modèle absente', (string) $etapes[1]['message']);
        self::assertDirectoryDoesNotExist($this->personnel . '/martinel.bts-sup-sallanches.com/WP2');
        self::assertSame([], $this->cpanel['appels'] === [] ? [] : array_filter($this->cpanel['appels'], static fn (string $a): bool => str_starts_with($a, 'creer')));
    }

    // ===== Côté web =====

    public function testRefusEtConfirmations(): void
    {
        $deploiement = new DeploiementSujet($this->pdo);
        $liste = $deploiement->etudiants($this->session);
        self::assertSame(['durandca', 'leroyhu', 'martinel'], array_column($liste, 'identifiant'));
        self::assertSame([true, false, false], array_column($liste, 'remplace'));
        self::assertSame('sous-domaine absent du dernier relevé', $liste[1]['empechement']);

        foreach ([
            [['leroyhu'], false, 'ne peut pas recevoir'],
            [['inconnu'], false, 'ne peut pas recevoir'],
            [[], false, 'Cochez au moins un étudiant'],
            [['durandca'], false, 'Cochez la case de confirmation'],
        ] as [$coches, $confirme, $attendu]) {
            try {
                $deploiement->lancer($this->session, $coches, $confirme, $this->compte('cpetit'), null);
                self::fail('Refus attendu : ' . $attendu);
            } catch (ErreurSaisie $e) {
                self::assertStringContainsString($attendu, $e->getMessage());
            }
        }
        $deploiement->lancer($this->session, ['martinel'], false, $this->compte('cpetit'), null);
        // Photo pas encore prise : ni second déploiement, ni nouveau sujet sur le modèle.
        foreach ([fn () => $deploiement->lancer($this->session, ['durandca'], true, $this->compte('cpetit'), null),
                  fn () => (new SiteModele($this->pdo))->geler(false, $this->compte('cpetit'), null)] as $action) {
            try {
                $action();
                self::fail('Travail sur le modèle déjà prévu');
            } catch (ErreurSaisie $e) {
                self::assertStringContainsString('déjà prévu ou en cours', $e->getMessage());
            }
        }
        self::assertSame('déploiement déjà prévu', $deploiement->etudiants($this->session)[2]['empechement']);
    }

    public function testAucunSujetInstalleRienADeployer(): void
    {
        $this->pdo->exec("DELETE FROM sites WHERE identifiant = 'sujet'");
        $this->expectException(ErreurSaisie::class);
        $this->expectExceptionMessage('Aucun sujet n\'est installé');
        (new DeploiementSujet($this->pdo))->lancer($this->session, ['martinel'], false, $this->compte('cpetit'), null);
    }

    public function testPageDeDeploiement(): void
    {
        $page = $this->en('cpetit', 'GET', '/sites/sujet')->corps;
        self::assertStringContainsString('Déployer chez les étudiants', $page);
        $page = $this->en('cpetit', 'POST', '/sites/sujet', ['action' => 'afficher_deploiement', 'session' => (string) $this->session])->corps;
        self::assertStringContainsString('remplace le /WP2 actuel', $page);
        self::assertStringContainsString('name="coches[]" value="martinel"', $page);
        self::assertStringNotContainsString('value="leroyhu"', $page);
        self::assertStringContainsString('name="confirmer"', $page);
        foreach (['jroux', 'pmoreau'] as $compte) {
            self::assertSame(403, $this->en($compte, 'POST', '/sites/sujet', ['action' => 'afficher_deploiement', 'session' => (string) $this->session])->statut);
        }
        $reponse = $this->en('cpetit', 'POST', '/sites/sujet', ['action' => 'deployer', 'session' => (string) $this->session], ['coches' => ['martinel']]);
        self::assertSame(303, $reponse->statut);
        self::assertMatchesRegularExpression('#^/travaux/[0-9]+$#', $reponse->enTetes['Location']);
        $refus = $this->en('cpetit', 'POST', '/sites/sujet', ['action' => 'deployer', 'session' => (string) $this->session], ['coches' => ['durandca']]);
        self::assertSame(200, $refus->statut);
        self::assertStringContainsString('déjà prévu ou en cours', $refus->corps);
    }

    // ===== Épreuve : clôture et gel (paquet 3) =====

    public function testClotureCopieDeconnecteEtFige(): void
    {
        $wp2 = $this->deployerMartinel();
        $configAvant = (string) file_get_contents($wp2 . '/wp-config.php');
        $this->wp = [];
        $this->boite->envoyes = [];

        $id = (new EpreuveSujet($this->pdo))->cloturer($this->session, ['martinel'], true, $this->compte('cpetit'), null);
        $this->tourner();

        $etape = $this->pdo->query('SELECT etat, message FROM travaux_etapes WHERE travail_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('reussi', $etape['etat'], (string) $etape['message']);
        // Copie datée d'abord (base et fichiers), puis sessions fermées.
        $commandes = array_map(static fn (array $a): string => $a['chemin'] . ' : ' . implode(' ', $a['arguments']), $this->wp);
        self::assertStringStartsWith($wp2 . ' : db export ', $commandes[0]);
        self::assertContains($wp2 . " : db query DELETE FROM `wpx_usermeta` WHERE meta_key IN ('session_tokens', 'portail_activite')", $commandes);
        $copie = $this->pdo->query("SELECT chemin, base, utilisateur_bdd, expire_le FROM mises_a_l_ecart WHERE identifiant = 'martinel' AND dossier = '/WP2'")->fetch(PDO::FETCH_ASSOC);
        self::assertNull($copie['base'], 'la purge de la copie n\'efface jamais la base du /WP2 en place');
        self::assertNull($copie['utilisateur_bdd']);
        $dossierCopie = $this->personnel . '/archives_suppression/portail/' . $copie['chemin'];
        self::assertFileExists($dossierCopie . '/base.sql');
        self::assertFileExists($dossierCopie . '/fichiers/wp-content/uploads/2026/11/serum.png');
        self::assertSame([], glob($this->donnees . '/tmp/copies/*'));
        // Lecture seule : module + bloc du portail ; le reste de wp-config.php ne change pas.
        self::assertSame(EpreuveWordPress::contenuModule(), file_get_contents($wp2 . '/' . EpreuveWordPress::MODULE));
        self::assertSame(0644, fileperms($wp2 . '/' . EpreuveWordPress::MODULE) & 0777);
        $config = (string) file_get_contents($wp2 . '/wp-config.php');
        self::assertStringContainsString("define('PORTAIL_LECTURE_SEULE', true);", $config);
        self::assertStringContainsString("define('DISALLOW_FILE_MODS', true);", $config);
        self::assertStringContainsString("'AUTOMATIC_UPDATER_DISABLED', true", $config, 'la version reste gelée');
        self::assertSame(self::horsBloc($configAvant), self::horsBloc($config));
        self::assertSame(0600, fileperms($wp2 . '/wp-config.php') & 0777);
        $site = $this->pdo->query("SELECT cloture_le, lecture_seule FROM sites WHERE identifiant = 'martinel' AND dossier = '/WP2'")->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($site['cloture_le']);
        self::assertSame(1, (int) $site['lecture_seule']);
        // E-mail : administrateur et enseignants actifs, sans mot de passe.
        self::assertCount(1, $this->boite->envoyes);
        self::assertSame(['admin@exemple.fr', 'cpetit@exemple.fr'], $this->boite->envoyes[0]['destinataires']);
        self::assertStringContainsString('1 /WP2 sur 1', $this->boite->envoyes[0]['texte']);
        self::assertStringNotContainsString('DB_PASSWORD', $this->boite->envoyes[0]['texte']);
        // Plus rien à clore pour cet étudiant ; son état est affiché.
        $etudiant = $this->etudiant('martinel');
        self::assertTrue($etudiant['lecture_seule']);
    }

    public function testLeverLeGelPuisRefiger(): void
    {
        $wp2 = $this->deployerMartinel();
        $epreuve = new EpreuveSujet($this->pdo);
        $epreuve->cloturer($this->session, ['martinel'], true, $this->compte('cpetit'), null);
        $this->tourner();

        $epreuve->gel($this->session, ['martinel'], false, $this->compte('cpetit'), null);
        $this->tourner();
        $config = (string) file_get_contents($wp2 . '/wp-config.php');
        self::assertStringNotContainsString('PORTAIL_LECTURE_SEULE', $config);
        self::assertStringNotContainsString('DISALLOW_FILE_MODS', $config);
        self::assertStringContainsString("'AUTOMATIC_UPDATER_DISABLED', true", $config);
        self::assertFalse($this->etudiant('martinel')['lecture_seule']);
        self::assertNotNull($this->etudiant('martinel')['cloture_le']);

        $epreuve->gel($this->session, ['martinel'], true, $this->compte('cpetit'), null);
        $this->tourner();
        self::assertStringContainsString("define('PORTAIL_LECTURE_SEULE', true);", (string) file_get_contents($wp2 . '/wp-config.php'));
        self::assertTrue($this->etudiant('martinel')['lecture_seule']);
        $messages = $this->pdo->query("SELECT message FROM travaux_etapes t JOIN travaux w ON w.id = t.travail_id WHERE w.type = 'lever_gel_epreuve' ORDER BY t.id")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['Gel levé : le /WP2 peut de nouveau être modifié.', '/WP2 figé de nouveau (lecture seule).'], $messages);

        // Un nouveau déploiement remet l'épreuve à zéro.
        (new DeploiementSujet($this->pdo))->lancer($this->session, ['martinel'], true, $this->compte('cpetit'), null);
        $this->tourner();
        $etudiant = $this->etudiant('martinel');
        self::assertNull($etudiant['cloture_le']);
        self::assertFalse($etudiant['lecture_seule']);
        self::assertStringNotContainsString('PORTAIL_LECTURE_SEULE', (string) file_get_contents($wp2 . '/wp-config.php'));
    }

    public function testCopieEnEchecLeWp2NeChangePas(): void
    {
        $wp2 = $this->deployerMartinel();
        $configAvant = file_get_contents($wp2 . '/wp-config.php');
        (new EpreuveSujet($this->pdo))->cloturer($this->session, ['martinel'], true, $this->compte('cpetit'), null);
        $this->echecWp = 'db export';
        $this->tourner();
        $etape = $this->pdo->query("SELECT t.etat, t.message FROM travaux_etapes t JOIN travaux w ON w.id = t.travail_id WHERE w.type = 'cloturer_epreuve'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('echec', $etape['etat']);
        self::assertStringContainsString('rien n\'a été changé dans son /WP2', (string) $etape['message']);
        self::assertSame($configAvant, file_get_contents($wp2 . '/wp-config.php'));
        self::assertFileDoesNotExist($wp2 . '/' . EpreuveWordPress::MODULE);
        self::assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM mises_a_l_ecart WHERE identifiant = 'martinel' AND dossier = '/WP2'")->fetchColumn());
        self::assertSame([], glob($this->donnees . '/tmp/copies/*'));
        self::assertFalse($this->etudiant('martinel')['lecture_seule']);
        self::assertSame([], array_filter($this->wp, static fn (array $a): bool => $a['arguments'][0] === 'db' && $a['arguments'][1] === 'query' && $a['chemin'] === $wp2
            && str_contains($a['arguments'][2], 'portail_activite')), 'aucune session fermée');
    }

    public function testRefusDeClotureEtDeGel(): void
    {
        $this->deployerMartinel();
        $epreuve = new EpreuveSujet($this->pdo);
        foreach ([
            [fn () => $epreuve->cloturer($this->session, ['martinel'], false, $this->compte('cpetit'), null), 'Cochez la case de confirmation'],
            [fn () => $epreuve->cloturer($this->session, ['durandca'], true, $this->compte('cpetit'), null), 'ne peut pas être clos'],
            [fn () => $epreuve->cloturer($this->session, [], true, $this->compte('cpetit'), null), 'Cochez au moins un étudiant'],
            [fn () => $epreuve->cloturer($this->session, ['inconnu'], true, $this->compte('cpetit'), null), 'pas dans cette session'],
            [fn () => $epreuve->gel($this->session, ['martinel'], false, $this->compte('cpetit'), null), 'n\'a pas de /WP2 figé'],
            [fn () => $epreuve->gel($this->session, ['martinel'], true, $this->compte('cpetit'), null), 'ne peut pas être refigé'],
        ] as [$action, $attendu]) {
            try {
                $action();
                self::fail('Refus attendu : ' . $attendu);
            } catch (ErreurSaisie $e) {
                self::assertStringContainsString($attendu, $e->getMessage());
            }
        }
        // Clôture prévue : ni seconde clôture, ni déploiement par-dessus.
        $epreuve->cloturer($this->session, ['martinel'], true, $this->compte('cpetit'), null);
        self::assertSame('clôture ou gel prévu', $this->etudiant('martinel')['empechement']);
        $this->expectException(ErreurSaisie::class);
        $epreuve->cloturer($this->session, ['martinel'], true, $this->compte('cpetit'), null);
    }

    public function testPageDeLEpreuve(): void
    {
        $this->deployerMartinel();
        $page = $this->en('cpetit', 'POST', '/sites/sujet', ['action' => 'afficher_deploiement', 'session' => (string) $this->session])->corps;
        self::assertStringContainsString('Épreuve : clôture et gel', $page);
        self::assertSame(2, substr_count($page, 'data-tout-cocher'), 'une case « Tout cocher » par tableau');
        self::assertStringContainsString('épreuve ouverte', $page);
        self::assertStringContainsString('name="confirmer_cloture"', $page);
        $refus = $this->en('cpetit', 'POST', '/sites/sujet', ['action' => 'cloturer', 'session' => (string) $this->session], ['coches' => ['martinel']]);
        self::assertSame(200, $refus->statut);
        self::assertStringContainsString('Cochez la case de confirmation', $refus->corps);
        $reponse = $this->en('cpetit', 'POST', '/sites/sujet', ['action' => 'cloturer', 'session' => (string) $this->session, 'confirmer_cloture' => '1'], ['coches' => ['martinel']]);
        self::assertSame(303, $reponse->statut);
        $this->tourner();
        // Les enseignants lèvent le gel (décision du 14/11/2026) ; l'administratif n'a pas accès à la page.
        $page = $this->en('cpetit', 'POST', '/sites/sujet', ['action' => 'afficher_deploiement', 'session' => (string) $this->session])->corps;
        self::assertStringContainsString('— figé', $page);
        self::assertSame(303, $this->en('cpetit', 'POST', '/sites/sujet', ['action' => 'lever_gel', 'session' => (string) $this->session], ['coches' => ['martinel']])->statut);
        self::assertSame(403, $this->en('jroux', 'POST', '/sites/sujet', ['action' => 'lever_gel', 'session' => (string) $this->session], ['coches' => ['martinel']])->statut);
    }

    public function testLeModuleEstDuPhpValide(): void
    {
        $code = EpreuveWordPress::contenuModule();
        token_get_all($code, TOKEN_PARSE); // ParseError si la syntaxe est fausse
        self::assertStringContainsString('time() - $derniere > 3600', $code);
        self::assertStringNotContainsString('%INACTIVITE%', $code);
        self::assertStringContainsString("!defined('PORTAIL_LECTURE_SEULE')", $code, 'inactif sans la constante (gel levé)');
    }

    private function deployerMartinel(): string
    {
        (new DeploiementSujet($this->pdo))->lancer($this->session, ['martinel'], false, $this->compte('cpetit'), null);
        $this->tourner();
        $wp2 = $this->personnel . '/martinel.bts-sup-sallanches.com/WP2';
        self::assertFileExists($wp2 . '/wp-config.php');

        return $wp2;
    }

    /** @return array{identifiant: string, nom: string, regime: string, empechement: ?string, remplace: bool, deploye_le: ?string, cloture_le: ?string, lecture_seule: bool, epreuve_prevue: bool} */
    private function etudiant(string $identifiant): array
    {
        foreach ((new DeploiementSujet($this->pdo))->etudiants($this->session) as $etudiant) {
            if ($etudiant['identifiant'] === $identifiant) {
                return $etudiant;
            }
        }
        self::fail('Étudiant absent : ' . $identifiant);
    }

    private static function horsBloc(string $config): string
    {
        return (string) preg_replace('#// === Réglages gérés.*// === Fin des réglages gérés par le portail ===#s', '', $config);
    }

    // ===== Outils =====

    private function tourner(): void
    {
        $travailleur = new Travailleur($this->pdo, new Alertes($this->pdo, null, self::URL), [
            'deployer_sujet_wp' => new ExecutantDeployerSujet($this->pdo, $this->fauxCpanel(), $this->fauxWp(), new MiseALecart($this->pdo, $this->personnel),
                $this->boite, $this->personnel, $this->donnees . '/tmp'),
            'cloturer_epreuve' => new ExecutantCloturerEpreuve($this->pdo, $this->fauxCpanel(), $this->fauxWp(), new MiseALecart($this->pdo, $this->personnel),
                $this->boite, $this->personnel, $this->donnees . '/tmp'),
            'lever_gel_epreuve' => new ExecutantGelEpreuve($this->pdo, $this->fauxCpanel(), $this->personnel),
        ], 0, static function (int $s): void {
        });
        $travailleur->tourner(60);
    }

    private function fichier(string $chemin, string $contenu): void
    {
        if (!is_dir(dirname($chemin))) {
            mkdir(dirname($chemin), 0755, true);
        }
        file_put_contents($chemin, $contenu);
    }

    private function fauxCpanel(): ServeurCpanel
    {
        $test = $this;

        return new class ($test) implements ServeurCpanel {
            public function __construct(private readonly DeploiementSujetTest $test)
            {
            }

            public function sousDomaines(): array
            {
                return $this->test->cpanel['sousDomaines'];
            }

            public function creerBase(string $base): void
            {
                $this->test->cpanel['appels'][] = 'creerBase ' . $base;
            }

            public function creerUtilisateur(string $utilisateur, #[\SensitiveParameter] string $motDePasse): void
            {
                $this->test->cpanel['appels'][] = 'creerUtilisateur ' . $utilisateur;
            }

            public function donnerDroits(string $utilisateur, string $base): void
            {
                $this->test->cpanel['appels'][] = 'donnerDroits ' . $utilisateur . ' ' . $base;
            }

            public function supprimerBase(string $base): void
            {
                $this->test->cpanel['appels'][] = 'supprimerBase ' . $base;
            }

            public function supprimerUtilisateur(string $utilisateur): void
            {
                $this->test->cpanel['appels'][] = 'supprimerUtilisateur ' . $utilisateur;
            }
        };
    }

    private function fauxWp(): LigneWordPress
    {
        $test = $this;

        return new class ($test) implements LigneWordPress {
            public function __construct(private readonly DeploiementSujetTest $test)
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
                if ($commande === 'db export') {
                    file_put_contents($arguments[2], "mysqldump: [Warning] avertissement\nCREATE TABLE `wpx_options` (\nCREATE TABLE `wpx_users` (\nCREATE TABLE `wpx_usermeta` (\n");

                    return 'Success';
                }
                if ($commande === 'db import') {
                    $this->test->imports[$chemin] = (string) file_get_contents($arguments[2]);

                    return 'Success';
                }

                return $commande === 'option get' ? "https://sujet.bts-sup-sallanches.com/WP\n" : '';
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

        return (new Application($this->pdo, new Chiffrement(random_bytes(32)), $horloge, $session, self::URL, null, $this->donnees))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null, $listes));
    }

    private function compte(string $identifiant): Compte
    {
        $compte = (new DepotComptes($this->pdo))->parIdentifiant($identifiant);
        self::assertNotNull($compte);

        return $compte;
    }
}
