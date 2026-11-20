<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Alertes\Alertes;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Comptes\Compte;
use Portail\Comptes\DepotComptes;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Sites\ConfigWordPress;
use Portail\Sites\GelSession;
use Portail\Sites\GelWordPress;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\ExpediteurMemoire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Travaux\ExecutantGelerWordPress;
use Portail\Travaux\FileTravaux;
use Portail\Travaux\Travailleur;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;
use RuntimeException;

/**
 * Gel de version des WordPress (constat du 20/11/2026) : module de gel du portail, état lu par le relevé,
 * gel des /WP déjà en place d'une session, alerte « WordPress hors gel ». Noms et identifiants inventés.
 */
final class GelWordPressTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    /** wp-config.php tel que l'écrit WP-CLI (creation_wp.sh), avec la ligne ajoutée par gel_wordpress.sh. */
    private const CONFIG_SCRIPT = "<?php\ndefine( 'DB_NAME', 'jafl3067_martinel' );\ndefine( 'DB_PASSWORD', 'secret-de-test' );\n"
        . "\$table_prefix = 'wp_';\ndefine( 'WP_AUTO_UPDATE_CORE', 'minor' );\n"
        . "/* That's all, stop editing! Happy publishing. */\n"
        . "if ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";

    private DossierTemporaire $dossier;
    private PDO $pdo;
    private string $personnel;
    private ExpediteurMemoire $boite;
    private Alertes $alertes;
    private int $session;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->pdo->exec("INSERT INTO parametres (cle, valeur) VALUES ('adresse_alertes', 'alertes@exemple.fr')");
        $this->personnel = $this->dossier->racine . '/personnel';
        mkdir($this->personnel, 0700);
        $this->boite = new ExpediteurMemoire();
        $this->alertes = new Alertes($this->pdo, $this->boite, self::URL);
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        $this->pdo->exec("INSERT INTO regimes (nom) VALUES ('NDRC FI')");
        $this->pdo->exec("INSERT INTO sessions (annee, version_wp_gelee) VALUES (2027, '7.1.5')");
        $this->session = (int) $this->pdo->lastInsertId();
        $ajout = $this->pdo->prepare("INSERT INTO etudiants (nom_complet, identifiant, identifiant_wp, email, regime_id, session_id, origine)
            VALUES (:n, :i, :w, :e, 1, :s, 'saisie')");
        foreach (['martinel' => 'MARTIN Élodie', 'duponta' => 'DUPONT Alice'] as $identifiant => $nom) {
            $ajout->execute([':n' => $nom, ':i' => $identifiant, ':w' => ucfirst($identifiant), ':e' => $identifiant . '@exemple.fr',
                ':s' => $this->session]);
        }
    }

    // ===== Bloc de wp-config.php =====

    public function testLeBlocDistingueGelTotalEtGelAvecCorrectifs(): void
    {
        $total = ConfigWordPress::bloc(['gele' => true]);
        self::assertStringContainsString("define('AUTOMATIC_UPDATER_DISABLED', true);", $total);
        self::assertStringContainsString("define('PORTAIL_GEL', 'total');", $total);
        $correctifs = ConfigWordPress::bloc(['gele' => false, 'correctifs' => true]);
        self::assertStringContainsString("define('PORTAIL_GEL', 'correctifs');", $correctifs);
        self::assertStringNotContainsString('AUTOMATIC_UPDATER_DISABLED', $correctifs);
        self::assertStringNotContainsString('WP_AUTO_UPDATE_CORE', $correctifs, 'jamais redéfini : un ancien script l\'a peut-être écrit');
        self::assertStringNotContainsString('PORTAIL_GEL', ConfigWordPress::bloc(['gele' => false]));

        self::assertSame('total', ConfigWordPress::modeGel(ConfigWordPress::remplacerBloc(self::CONFIG_SCRIPT, ['gele' => true])));
        self::assertSame('correctifs', ConfigWordPress::modeGel(
            ConfigWordPress::remplacerBloc(self::CONFIG_SCRIPT, ['gele' => false, 'correctifs' => true])));
        self::assertNull(ConfigWordPress::modeGel(self::CONFIG_SCRIPT));
        // Gel total écrit avant le 20/11/2026 (sans PORTAIL_GEL) : toujours reconnu.
        $ancien = ConfigWordPress::DEBUT_BLOC . "\ndefine('AUTOMATIC_UPDATER_DISABLED', true);\n" . ConfigWordPress::FIN_BLOC;
        self::assertSame('total', ConfigWordPress::modeGel($ancien));
    }

    public function testLeBlocSAjouteAvantLeChargementDeWordPressDansUnFichierDeScript(): void
    {
        $nouveau = ConfigWordPress::remplacerBloc(self::CONFIG_SCRIPT, ['gele' => false, 'correctifs' => true]);
        self::assertLessThan(strpos($nouveau, "if ( ! defined( 'ABSPATH' ) )"), strpos($nouveau, ConfigWordPress::DEBUT_BLOC));
        self::assertSame(1, substr_count($nouveau, 'WP_AUTO_UPDATE_CORE'));
        self::assertStringContainsString("define( 'DB_PASSWORD', 'secret-de-test' );", $nouveau, 'le reste du fichier ne change pas');
        $fichier = $this->dossier->racine . '/essai-config.php';
        file_put_contents($fichier, $nouveau);
        self::assertSame(0, $this->php(['-l', $fichier])['code']);
    }

    // ===== Module de gel (exécuté avec de fausses fonctions WordPress) =====

    public function testLeModuleNAgitQuAvecPortailGel(): void
    {
        self::assertSame('aucun filtre', $this->essayerModule(null));
    }

    public function testLeModuleRetireLeBoutonEtLesEMailsDeSucces(): void
    {
        $resultat = $this->essayerModule('correctifs');
        self::assertStringContainsString('update_core=do_not_allow', $resultat);
        self::assertStringContainsString('edit_posts=edit_posts', $resultat, 'les autres droits ne changent pas');
        self::assertStringContainsString('majeure=non', $resultat);
        self::assertStringContainsString('mineure=oui', $resultat);
        self::assertStringContainsString('extensions=non themes=non', $resultat);
        self::assertStringContainsString('avis=non', $resultat);
        self::assertStringContainsString('succes=non echec=oui', $resultat);
    }

    public function testEnGelTotalLeModuleRefuseAussiLesCorrectifs(): void
    {
        self::assertStringContainsString('mineure=non', $this->essayerModule('total'));
    }

    // ===== État lu par le relevé =====

    public function testEtatDeGelLuDansLesFichiers(): void
    {
        $site = $this->site('martinel', self::CONFIG_SCRIPT);
        self::assertSame('aucun', GelWordPress::etat($site));
        mkdir($site . '/wp-content/mu-plugins', 0755, true);
        file_put_contents($site . '/wp-content/mu-plugins/gel-version-examen.php', '<?php // gel_wordpress.sh');
        self::assertSame('correctifs', GelWordPress::etat($site), 'gel de gel_wordpress.sh');
        unlink($site . '/wp-content/mu-plugins/gel-version-examen.php');
        file_put_contents($site . '/wp-content/mu-plugins/gel-version-session.php',
            "<?php add_filter( 'automatic_updater_disabled', '__return_true' );");
        self::assertSame('total', GelWordPress::etat($site), 'gel_wp_session.sh, façon « total »');
        unlink($site . '/wp-content/mu-plugins/gel-version-session.php');

        file_put_contents($site . '/wp-config.php', ConfigWordPress::remplacerBloc(self::CONFIG_SCRIPT, ['gele' => true]));
        self::assertSame('incomplet', GelWordPress::etat($site), 'gel du portail sans le module : bouton encore visible');
        GelWordPress::poserModule($site);
        self::assertSame('total', GelWordPress::etat($site));
        self::assertSame(0644, fileperms($site . '/' . GelWordPress::MODULE) & 0777);
        self::assertSame('wordpress', Releve::sites(dirname($site))[0]['logiciel']);
        self::assertSame('total', Releve::sites(dirname($site))[0]['gel'] ?? null);
    }

    // ===== Travail « Geler les /WP » =====

    public function testGelerUnWpDeScriptPuisLeDegeler(): void
    {
        $site = $this->site('martinel', self::CONFIG_SCRIPT);
        mkdir($site . '/wp-content/mu-plugins', 0755, true);
        file_put_contents($site . '/wp-content/mu-plugins/gel-version-examen.php', '<?php // gel_wordpress.sh');
        $executant = new ExecutantGelerWordPress($this->pdo, $this->cpanel(['martinel']), $this->personnel);
        $this->releve('martinel', '/WP', '7.1.5', 'correctifs');

        $resultat = $executant->executer('martinel', ['geler' => true]);
        self::assertStringContainsString('WordPress 7.1.5 gelé : correctifs de sécurité seulement', $resultat['message']);
        $config = (string) file_get_contents($site . '/wp-config.php');
        self::assertSame('correctifs', ConfigWordPress::modeGel($config));
        self::assertSame(1, substr_count($config, 'WP_AUTO_UPDATE_CORE'));
        self::assertSame(0600, fileperms($site . '/wp-config.php') & 0777);
        self::assertSame(GelWordPress::module(), file_get_contents($site . '/' . GelWordPress::MODULE));
        self::assertSame('correctifs', $this->gelReleve('martinel'));
        // Deuxième passage : rien ne change.
        $executant->executer('martinel', ['geler' => true]);
        self::assertSame($config, file_get_contents($site . '/wp-config.php'));

        $resultat = $executant->executer('martinel', ['geler' => false]);
        self::assertNull(ConfigWordPress::modeGel((string) file_get_contents($site . '/wp-config.php')));
        self::assertStringContainsString('ancien script reste actif', $resultat['message']);
        unlink($site . '/wp-content/mu-plugins/gel-version-examen.php');
        $resultat = $executant->executer('martinel', ['geler' => false]);
        self::assertStringContainsString('dégelé', $resultat['message']);
        self::assertSame('aucun', $this->gelReleve('martinel'));
    }

    public function testUnGelTotalDuPortailNEstPasAffaibli(): void
    {
        $site = $this->site('martinel', ConfigWordPress::remplacerBloc(self::CONFIG_SCRIPT, ['gele' => true]));
        $resultat = (new ExecutantGelerWordPress($this->pdo, $this->cpanel(['martinel']), $this->personnel))
            ->executer('martinel', ['geler' => true]);
        self::assertStringContainsString('déjà gelé par le portail', $resultat['message']);
        self::assertSame('total', GelWordPress::etat($site));
    }

    public function testUnWpAbsentOuUnSousDomaineInattenduEstRefuse(): void
    {
        mkdir($this->personnel . '/duponta.bts-sup-sallanches.com', 0755);
        $executant = new ExecutantGelerWordPress($this->pdo, $this->cpanel(['duponta']), $this->personnel);
        try {
            $executant->executer('duponta', ['geler' => true]);
            self::fail('/WP absent : refus attendu');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('introuvable', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $executant->executer('inconnu', ['geler' => true]);
    }

    // ===== Demande depuis « Régimes et sessions » =====

    public function testLaDemandeCreeUneEtapeParWpReleve(): void
    {
        $this->releve('martinel', '/WP', '7.1.5', 'aucun');
        $this->releve('duponta', '/WP', '7.1.5', 'correctifs');
        $this->releve('duponta', '/WP2', '7.1.5', 'incomplet');
        $gel = new GelSession($this->pdo);
        self::assertSame([$this->session => ['total' => 2, 'geles' => 1]], $gel->etat());

        $id = $gel->demander($this->session, true, $this->compte('mzanelli'), null);
        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame(['DUPONT Alice', 'MARTIN Élodie'], array_column($travail['etapes'], 'libelle'));
        $this->attendreRefus('déjà prévu', fn () => $gel->demander($this->session, false, $this->compte('mzanelli'), null));
    }

    public function testLaDemandeExigeLaVersionDeLaSessionEtUnWpReleve(): void
    {
        $gel = new GelSession($this->pdo);
        $this->attendreRefus('actualisez la liste des sites', fn () => $gel->demander($this->session, true, $this->compte('mzanelli'), null));
        $this->releve('martinel', '/WP', '7.1.5', 'aucun');
        $this->pdo->exec('UPDATE sessions SET version_wp_gelee = NULL');
        $this->attendreRefus('Inscrivez d\'abord la version', fn () => $gel->demander($this->session, true, $this->compte('mzanelli'), null));
        $this->pdo->exec('UPDATE sessions SET active = 0');
        $this->attendreRefus('archivée', fn () => $gel->demander($this->session, false, $this->compte('mzanelli'), null));
    }

    public function testLeTravailleurGeleLaSessionEntiere(): void
    {
        foreach (['martinel', 'duponta'] as $identifiant) {
            $this->site($identifiant, self::CONFIG_SCRIPT);
            $this->releve($identifiant, '/WP', '7.1.5', 'aucun');
        }
        (new GelSession($this->pdo))->demander($this->session, true, $this->compte('mzanelli'), null);
        $executant = new ExecutantGelerWordPress($this->pdo, $this->cpanel(['martinel', 'duponta']), $this->personnel);
        (new Travailleur($this->pdo, $this->alertes, [GelSession::TYPE => $executant], 0, static function (): void {
        }))->tourner(50);
        self::assertSame([$this->session => ['total' => 2, 'geles' => 2]], (new GelSession($this->pdo))->etat());
        self::assertSame([], $this->boite->envoyes, 'aucune alerte quand tout a réussi');
    }

    public function testLaPageDesSessionsMontreLeGelALAdministrateurSeulement(): void
    {
        $this->releve('martinel', '/WP', '7.1.5', 'aucun');
        $admin = $this->en('mzanelli', 'GET', '/regimes-sessions');
        self::assertSame(200, $admin->statut);
        self::assertStringContainsString('0 gelé(s) sur 1', $admin->corps);
        self::assertStringContainsString('Geler les /WP', $admin->corps);
        $enseignant = $this->en('cpetit', 'GET', '/regimes-sessions');
        self::assertStringNotContainsString('Geler les /WP', $enseignant->corps);
        $refus = $this->en('cpetit', 'POST', '/regimes-sessions', ['action' => 'geler_wordpress', 'id' => (string) $this->session]);
        self::assertSame(403, $refus->statut);
        self::assertNull((new FileTravaux($this->pdo))->dejaPrevu(GelSession::TYPE));

        $page = $this->en('mzanelli', 'POST', '/regimes-sessions', ['action' => 'geler_wordpress', 'id' => (string) $this->session]);
        self::assertStringContainsString('Gel des /WP prévu', $page->corps);
        self::assertNotNull((new FileTravaux($this->pdo))->dejaPrevu(GelSession::TYPE));
    }

    // ===== Surveillance : relevé, alerte, liste des sites =====

    public function testWordPressHorsGel(): void
    {
        $this->releve('martinel', '/WP', '7.1.6', 'correctifs');     // correctif de la même branche : permis
        $this->releve('duponta', '/WP', '7.2.0', 'correctifs');      // autre branche
        $this->releve('duponta', '/WP2', '7.1.4', 'incomplet');      // bouton encore visible
        self::assertSame([
            'duponta' => ['/WP' => 'version 7.2.0 au lieu de 7.1.5', '/WP2' => 'bouton de mise à jour encore visible'],
        ], Releve::wordpressHorsGel($this->pdo));

        $this->pdo->exec("UPDATE releve_sites SET gel_wordpress = 'aucun' WHERE identifiant = 'martinel'");
        self::assertSame('version non gelée', Releve::wordpressHorsGel($this->pdo)['martinel']['/WP'] ?? null);
        // Version de la session pas encore fixée : /WP libre ; le /WP2 reste surveillé.
        $this->pdo->exec('UPDATE sessions SET version_wp_gelee = NULL');
        self::assertSame(['duponta' => ['/WP2' => 'bouton de mise à jour encore visible']], Releve::wordpressHorsGel($this->pdo));
        // Session archivée : plus rien.
        $this->pdo->exec('UPDATE sessions SET active = 0');
        self::assertSame([], Releve::wordpressHorsGel($this->pdo));
    }

    public function testAlerteEtListeDesSitesSansNomDEtudiantDansLEMail(): void
    {
        $this->releve('duponta', '/WP', '7.2.0', 'correctifs');
        $this->alertes->wordpressHorsGel(array_sum(array_map('count', Releve::wordpressHorsGel($this->pdo))));
        $this->alertes->wordpressHorsGel(0);
        self::assertSame(1, $this->alertes->nombreEnAttente());
        $this->alertes->expedier();
        self::assertStringEndsWith('1 site(s) WordPress hors du gel de leur session', $this->boite->envoyes[0]['sujet']);
        self::assertStringNotContainsString('duponta', $this->boite->envoyes[0]['texte']);
        self::assertStringNotContainsString('DUPONT', $this->boite->envoyes[0]['texte']);

        $page = $this->en('mzanelli', 'GET', '/sites');
        self::assertStringContainsString('hors gel : version 7.2.0 au lieu de 7.1.5', $page->corps);
    }

    // ===== Aides =====

    /** Crée ~/identifiant.bts-sup-sallanches.com/WP avec un wp-config.php et un numéro de version. */
    private function site(string $identifiant, string $config): string
    {
        $site = $this->personnel . '/' . $identifiant . '.bts-sup-sallanches.com/WP';
        mkdir($site . '/wp-includes', 0755, true);
        file_put_contents($site . '/wp-config.php', $config);
        file_put_contents($site . '/wp-includes/version.php', "<?php\n\$wp_version = '7.1.5';\n");

        return $site;
    }

    private function releve(string $identifiant, string $dossier, string $version, string $gel): void
    {
        $this->pdo->prepare("INSERT INTO releve_sites (identifiant, dossier, logiciel, version, gel_wordpress, releve_le)
            VALUES (:i, :d, 'wordpress', :v, :g, '2026-11-20T08:00:00Z')")
            ->execute([':i' => $identifiant, ':d' => $dossier, ':v' => $version, ':g' => $gel]);
    }

    private function gelReleve(string $identifiant): ?string
    {
        $requete = $this->pdo->prepare("SELECT gel_wordpress FROM releve_sites WHERE identifiant = :i AND dossier = '/WP'");
        $requete->execute([':i' => $identifiant]);
        $gel = $requete->fetchColumn();

        return is_string($gel) ? $gel : null;
    }

    /** @param list<string> $identifiants */
    private function cpanel(array $identifiants): ServeurCpanel
    {
        $sousDomaines = [];
        foreach ($identifiants as $identifiant) {
            $sousDomaines[$identifiant] = $this->personnel . '/' . $identifiant . '.bts-sup-sallanches.com';
        }

        return new class ($sousDomaines) implements ServeurCpanel {
            /** @param array<string, string> $sousDomaines */
            public function __construct(private readonly array $sousDomaines)
            {
            }

            public function sousDomaines(): array
            {
                return $this->sousDomaines;
            }

            public function creerSousDomaine(string $identifiant): void
            {
                throw new RuntimeException('non prévu');
            }

            public function creerBase(string $base): void
            {
                throw new RuntimeException('non prévu');
            }

            public function creerUtilisateur(string $utilisateur, #[\SensitiveParameter] string $motDePasse): void
            {
                throw new RuntimeException('non prévu');
            }

            public function donnerDroits(string $utilisateur, string $base): void
            {
                throw new RuntimeException('non prévu');
            }

            public function supprimerBase(string $base): void
            {
                throw new RuntimeException('non prévu');
            }

            public function supprimerUtilisateur(string $utilisateur): void
            {
                throw new RuntimeException('non prévu');
            }
        };
    }

    /** Charge le module avec de fausses fonctions WordPress et décrit l'effet de ses filtres. */
    private function essayerModule(?string $gel): string
    {
        $module = $this->dossier->racine . '/module.php';
        file_put_contents($module, GelWordPress::module());
        $essai = $this->dossier->racine . '/essai.php';
        file_put_contents($essai, '<?php
define("ABSPATH", "/");
' . ($gel === null ? '' : 'define("PORTAIL_GEL", ' . var_export($gel, true) . ');') . '
$GLOBALS["filtres"] = [];
function add_filter($nom, $rappel, $priorite = 10, $arguments = 1) { $GLOBALS["filtres"][$nom] = $rappel; }
function __return_false() { return false; }
function f($nom, ...$valeurs) { return isset($GLOBALS["filtres"][$nom]) ? $GLOBALS["filtres"][$nom](...$valeurs) : "?"; }
function oui($v) { return $v === true ? "oui" : ($v === false ? "non" : var_export($v, true)); }
require ' . var_export($module, true) . ';
if ($GLOBALS["filtres"] === []) { echo "aucun filtre"; exit; }
echo "update_core=", implode(",", f("map_meta_cap", ["update_core"], "update_core")),
    " edit_posts=", implode(",", f("map_meta_cap", ["edit_posts"], "edit_posts")),
    " majeure=", oui(f("allow_major_auto_core_updates", true)), " mineure=", oui(f("allow_minor_auto_core_updates", true)),
    " extensions=", oui(f("auto_update_plugin", true)), " themes=", oui(f("auto_update_theme", true)),
    " avis=", oui(f("send_core_update_notification_email", true)),
    " succes=", oui(f("auto_core_update_send_email", true, "success")), " echec=", oui(f("auto_core_update_send_email", true, "fail"));
');
        $resultat = $this->php([$essai]);
        self::assertSame(0, $resultat['code'], $resultat['sortie']);

        return $resultat['sortie'];
    }

    /**
     * @param list<string> $arguments
     * @return array{code: int, sortie: string}
     */
    private function php(array $arguments): array
    {
        $resultat = \Portail\Systeme\Commande::lancer(array_merge([PHP_BINARY], $arguments), 30);

        return ['code' => $resultat['code'], 'sortie' => $resultat['sortie']];
    }

    private function attendreRefus(string $morceau, callable $action): void
    {
        try {
            $action();
            self::fail('Refus attendu : ' . $morceau);
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString($morceau, $e->getMessage());
        }
    }

    private function compte(string $identifiant): Compte
    {
        $compte = (new DepotComptes($this->pdo))->parIdentifiant($identifiant);
        self::assertNotNull($compte);

        return $compte;
    }

    /** @param array<string, string> $champs */
    private function en(string $identifiant, string $methode, string $chemin, array $champs = []): Reponse
    {
        $horloge = new HorlogeFixe(1_794_000_000);
        $compte = $this->compte($identifiant);
        $session = new Session(new SessionMemoire(), $horloge);
        $session->connecter($compte->id, $compte->generation);
        if ($methode === 'POST') {
            $champs['jeton'] = $session->jeton();
        }

        return (new Application($this->pdo, new Chiffrement(random_bytes(32)), $horloge, $session, self::URL, $this->alertes))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null));
    }
}
