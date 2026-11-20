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
use Portail\Sites\Certificats;
use Portail\Sites\GelWordPress;
use Portail\Sites\MiseALecart;
use Portail\Sites\Reinitialisations;
use Portail\Sites\LigneWordPress;
use Portail\Sites\AcmeSh;
use Portail\Sites\PreparationWordPress;
use Portail\Sites\RelanceCertificats;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\ExpediteurMemoire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Travaux\ExecutantCreerWordPress;
use Portail\Travaux\Travailleur;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;
use RuntimeException;

/**
 * V2, lot 5 : création du WordPress /WP d'un étudiant (CPanel, WP-CLI et certificats simulés, noms inventés).
 */
final class CreationWordPressTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    private DossierTemporaire $dossier;
    private string $personnel;
    private string $donnees;
    private PDO $pdo;
    private ExpediteurMemoire $boite;
    private Chiffrement $chiffrement;
    private int $session;
    /** @var array{appels: list<string>, sousDomaines: array<string, string>} */
    public array $cpanel = ['appels' => [], 'sousDomaines' => []];
    /** @var list<array{chemin: string, arguments: list<string>, environnement: array<string, string>}> */
    public array $wp = [];
    public ?string $echecWp = null;
    /** Sortie simulée de « wp user list » (CSV) sur un site existant. */
    public string $comptesWp = "user_login,user_email\n";
    public bool $echecCertificat = false;
    /** @var list<string> */
    public array $certificats = [];

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
        $this->pdo->exec("INSERT INTO parametres (cle, valeur) VALUES ('adresse_alertes', 'admin@exemple.fr'), ('sites_proteges', '[\"peda\",\"sujet\"]'), ('bases_protegees', '[]')");
        $this->boite = new ExpediteurMemoire();
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'pmoreau' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        $referentiel = new RegimesSessions($this->pdo);
        $fi = $referentiel->creerRegime('NDRC FI');
        $this->session = $referentiel->creerSession('2028');
        $gestion = new GestionEtudiants($this->pdo, $this->compte('mzanelli'));
        foreach ([['MARTIN', 'Élodie', 'martinel'], ['DURAND', 'Camille', 'durandca'], ['LEROY', 'Hugo', 'leroyhu']] as [$nom, $prenom, $id]) {
            $gestion->ajouter($nom, $prenom, $id . '@exemple.fr', $fi, $this->session, $id);
        }
        // durandca : sous-domaine et /WP existants (créés par les scripts) ; leroyhu : sous-domaine sans /WP.
        foreach (['durandca', 'leroyhu'] as $id) {
            mkdir($this->personnel . '/' . $id . '.bts-sup-sallanches.com');
            $this->cpanel['sousDomaines'][$id] = $this->personnel . '/' . $id . '.bts-sup-sallanches.com';
        }
        mkdir($this->personnel . '/durandca.bts-sup-sallanches.com/WP');
        file_put_contents($this->personnel . '/durandca.bts-sup-sallanches.com/WP/index.php', '<?php // site de l\'étudiant');
        Releve::enregistrer($this->pdo, $this->cpanel['sousDomaines'],
            ['durandca' => [['dossier' => '/WP', 'logiciel' => 'wordpress', 'version' => '7.1.4', 'module_mise_a_jour' => null]]], '2026-11-14T12:00:00Z');
    }

    public function testCreationAvecSousDomaineACreer(): void
    {
        $liste = (new PreparationWordPress($this->pdo))->etudiants($this->session);
        self::assertSame(['durandca', 'leroyhu', 'martinel'], array_column($liste, 'identifiant'));
        self::assertSame('WordPress /WP déjà présent', $liste[0]['empechement']);
        self::assertSame([null, null], [$liste[1]['empechement'], $liste[2]['empechement']]);
        self::assertSame([true, false], [$liste[1]['sous_domaine'], $liste[2]['sous_domaine']]);
        $identifiantWp = $liste[2]['identifiant_wp'];

        $id = (new PreparationWordPress($this->pdo))->lancer($this->session, ['martinel'], $this->compte('cpetit'), null);
        $this->tourner();

        $etape = $this->pdo->query('SELECT etat, message, secret FROM travaux_etapes WHERE travail_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('reussi', $etape['etat'], (string) $etape['message']);
        self::assertStringContainsString('WordPress 7.1.4 installé en https (dernière version, mises à jour automatiques)', (string) $etape['message']);
        self::assertStringContainsString('certificat posé', (string) $etape['message']);
        self::assertNull($etape['secret'], 'récapitulatif envoyé, secret effacé');
        // Sous-domaine créé, puis certificat posé sur son dossier.
        self::assertSame('creerSousDomaine martinel', $this->cpanel['appels'][0]);
        $racine = $this->personnel . '/martinel.bts-sup-sallanches.com';
        self::assertSame(['martinel.bts-sup-sallanches.com ' . $racine], $this->certificats);
        self::assertSame(1, (int) $this->pdo->query("SELECT count(*) FROM sous_domaines WHERE identifiant = 'martinel'")->fetchColumn());
        // Base et utilisateur propres au site.
        self::assertCount(1, array_filter($this->cpanel['appels'], static fn (string $a): bool => preg_match('/^creerBase jafl3067_martinel_WP_[a-z0-9]{6}$/', $a) === 1));
        self::assertCount(1, array_filter($this->cpanel['appels'], static fn (string $a): bool => preg_match('/^creerUtilisateur jafl3067_martinel_wp[a-z0-9]{6}$/', $a) === 1));
        // Fichiers : WordPress, configuration privée sans gel, redirection https.
        $wp = $racine . '/WP';
        self::assertFileExists($wp . '/wp-login.php');
        self::assertSame(0600, fileperms($wp . '/wp-config.php') & 0777);
        $config = (string) file_get_contents($wp . '/wp-config.php');
        self::assertStringContainsString("\$table_prefix = 'wp_'", $config);
        self::assertStringNotContainsString('AUTOMATIC_UPDATER_DISABLED', $config, 'version pas encore fixée : mises à jour automatiques');
        self::assertStringNotContainsString('PORTAIL_GEL', $config);
        self::assertFileExists($wp . '/' . GelWordPress::MODULE, 'module posé, inactif tant que la version n\'est pas gelée');
        self::assertSame('aucun', GelWordPress::etat($wp));
        self::assertStringContainsString('BEGIN HTTPS-PORTAIL', (string) file_get_contents($wp . '/.htaccess'));
        self::assertSame(0644, fileperms($wp . '/.htaccess') & 0777);
        self::assertSame([], glob($this->donnees . '/tmp/wp-*'));
        // WP-CLI : téléchargement en français (dernière version), installation, mot de passe par l'environnement.
        $commandes = array_map(static fn (array $a): string => implode(' ', $a['arguments']), $this->wp);
        self::assertSame('core download --locale=fr_FR', $commandes[0]);
        $installation = (string) current(array_filter($commandes, static fn (string $c): bool => str_starts_with($c, 'core install')));
        self::assertStringContainsString('--url=https://martinel.bts-sup-sallanches.com/WP --title=Sup --admin_user=' . $identifiantWp, $installation);
        self::assertStringContainsString('--admin_email=martinel@exemple.fr', $installation);
        self::assertContains('option update admin_email admin@exemple.fr', $commandes);
        self::assertContains('option update timezone_string Europe/Paris', $commandes);
        self::assertContains('option update WPLANG fr_FR', $commandes);
        $eval = (array) current(array_filter($this->wp, static fn (array $a): bool => $a['arguments'][0] === 'eval'));
        $motDePasse = (string) ($eval['environnement']['PORTAIL_MDP'] ?? '');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{4}(-[A-Za-z0-9]{4}){3}$/', $motDePasse);
        self::assertSame($identifiantWp, $eval['environnement']['PORTAIL_IDENTIFIANT'] ?? null);
        foreach ($this->wp as $appel) {
            self::assertStringNotContainsString($motDePasse, implode(' ', $appel['arguments']), 'jamais de mot de passe en ligne de commande');
        }
        // Inscrit au portail.
        $site = $this->pdo->query("SELECT version, gele FROM sites WHERE identifiant = 'martinel' AND dossier = '/WP'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['7.1.4', 0], [$site['version'], (int) $site['gele']]);
        self::assertSame('WordPress /WP déjà présent', (new PreparationWordPress($this->pdo))->etudiants($this->session)[2]['empechement']);
        // E-mails : accès à l'étudiant, récapitulatif aux colonnes de la feuille pour l'administrateur.
        self::assertCount(2, $this->boite->envoyes);
        [$etudiant, $recapitulatif] = $this->boite->envoyes;
        self::assertSame(['martinel@exemple.fr'], $etudiant['destinataires']);
        self::assertSame(ExecutantCreerWordPress::SUJET_ETUDIANT, $etudiant['sujet']);
        self::assertStringContainsString('https://martinel.bts-sup-sallanches.com/WP/wp-admin/', $etudiant['texte']);
        self::assertStringContainsString('Identifiant : ' . $identifiantWp, $etudiant['texte']);
        self::assertStringContainsString('Mot de passe : ' . $motDePasse, $etudiant['texte']);
        self::assertStringContainsString('avertissement de sécurité', $etudiant['texte']);
        self::assertSame(['admin@exemple.fr'], $recapitulatif['destinataires']);
        self::assertStringContainsString("Site wordpress\tIdentifiant WP\tMot de passe WP", $recapitulatif['texte']);
        self::assertStringContainsString("https://martinel.bts-sup-sallanches.com/WP/\t" . $identifiantWp . "\t" . $motDePasse, $recapitulatif['texte']);
        self::assertStringContainsString('<th style', (string) $recapitulatif['html']);
    }

    public function testVersionDeLaSessionGelee(): void
    {
        $this->pdo->exec("UPDATE sessions SET version_wp_gelee = '7.1.4' WHERE id = " . $this->session);
        (new PreparationWordPress($this->pdo))->lancer($this->session, ['leroyhu'], $this->compte('cpetit'), null);
        $this->tourner();
        self::assertSame('core download --locale=fr_FR --version=7.1.4', implode(' ', $this->wp[0]['arguments']));
        // Version de la session gelée avec ses correctifs (décision du 07/11/2026) : module de gel du portail actif.
        $wp = $this->personnel . '/leroyhu.bts-sup-sallanches.com/WP';
        $config = (string) file_get_contents($wp . '/wp-config.php');
        self::assertStringContainsString("define('PORTAIL_GEL', 'correctifs');", $config);
        self::assertStringNotContainsString('AUTOMATIC_UPDATER_DISABLED', $config, 'les correctifs de sécurité passent');
        self::assertSame(GelWordPress::module(), file_get_contents($wp . '/' . GelWordPress::MODULE));
        self::assertSame('correctifs', GelWordPress::etat($wp));
        self::assertSame(1, (int) $this->pdo->query("SELECT gele FROM sites WHERE identifiant = 'leroyhu'")->fetchColumn());
        // Sous-domaine existant : ni création, ni certificat.
        self::assertSame([], array_filter($this->cpanel['appels'], static fn (string $a): bool => str_starts_with($a, 'creerSousDomaine')));
        self::assertSame([], $this->certificats);
        self::assertStringNotContainsString('avertissement de sécurité', $this->boite->envoyes[0]['texte']);
    }

    public function testUnWpNonVideNEstJamaisTouche(): void
    {
        $this->pdo->exec("DELETE FROM releve_sites WHERE identifiant = 'durandca'"); // relevé pas à jour
        (new PreparationWordPress($this->pdo))->lancer($this->session, ['durandca'], $this->compte('cpetit'), null);
        $this->tourner();
        $etape = $this->pdo->query('SELECT etat, message FROM travaux_etapes')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ignore', $etape['etat']);
        self::assertSame('<?php // site de l\'étudiant', file_get_contents($this->personnel . '/durandca.bts-sup-sallanches.com/WP/index.php'));
        self::assertSame([], $this->cpanel['appels']);
        self::assertSame([], $this->wp);
    }

    public function testEchecDInstallationToutEstDefait(): void
    {
        (new PreparationWordPress($this->pdo))->lancer($this->session, ['martinel'], $this->compte('cpetit'), null);
        $this->echecWp = 'core install';
        $this->tourner();
        $etape = $this->pdo->query('SELECT etat, message, secret FROM travaux_etapes')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('echec', $etape['etat']);
        self::assertStringContainsString('le sous-domaine créé est gardé, vide', (string) $etape['message']);
        self::assertNull($etape['secret']);
        self::assertCount(1, array_filter($this->cpanel['appels'], static fn (string $a): bool => str_starts_with($a, 'supprimerBase jafl3067_martinel_WP_')));
        self::assertCount(1, array_filter($this->cpanel['appels'], static fn (string $a): bool => str_starts_with($a, 'supprimerUtilisateur jafl3067_martinel_wp')));
        self::assertDirectoryDoesNotExist($this->personnel . '/martinel.bts-sup-sallanches.com/WP');
        self::assertSame([], glob($this->donnees . '/tmp/wp-*'));
        self::assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM sites WHERE identifiant = 'martinel'")->fetchColumn());
        self::assertSame([], array_filter($this->boite->envoyes, static fn (array $e): bool => $e['destinataires'] === ['martinel@exemple.fr']));
    }

    public function testCertificatEnEchecLeSiteEstQuandMemeCree(): void
    {
        $this->echecCertificat = true;
        (new PreparationWordPress($this->pdo))->lancer($this->session, ['martinel'], $this->compte('cpetit'), null);
        $this->tourner();
        $etape = $this->pdo->query('SELECT etat, message FROM travaux_etapes')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('reussi', $etape['etat']);
        self::assertStringContainsString('certificat pas encore posé (Certificat non obtenu (acme.sh, code 1) : Invalid response', (string) $etape['message']);
        self::assertStringContainsString('réessaie seul toutes les 20 minutes', (string) $etape['message']);
        self::assertFileExists($this->personnel . '/martinel.bts-sup-sallanches.com/WP/wp-config.php');
        // Relances : pas avant 20 minutes ; échecs comptés ; alerte au 6e essai, puis plus rien.
        $relance = new RelanceCertificats($this->pdo);
        $alertes = new Alertes($this->pdo, $this->boite, self::URL);
        $debut = time();
        self::assertNull($relance->relancerUn($this->fauxCertificats(), $this->fauxCpanel(), $this->personnel, $alertes, $debut + 60));
        for ($essai = 2; $essai <= 6; $essai++) {
            self::assertSame('échec : martinel.bts-sup-sallanches.com',
                $relance->relancerUn($this->fauxCertificats(), $this->fauxCpanel(), $this->personnel, $alertes, $debut + ($essai - 1) * 1201));
        }
        self::assertSame(1, $alertes->nombreEnAttente());
        self::assertNull($relance->relancerUn($this->fauxCertificats(), $this->fauxCpanel(), $this->personnel, $alertes, $debut + 99999), 'abandon : plus d\'essai');
        // Un nouvel échec à la création relance le cycle ; une réussite retire le sous-domaine de la liste.
        $relance->inscrire('martinel', 'nouvel essai', $debut);
        $this->echecCertificat = false;
        self::assertSame('posé : martinel.bts-sup-sallanches.com', $relance->relancerUn($this->fauxCertificats(), $this->fauxCpanel(), $this->personnel, $alertes, $debut + 1300));
        self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM certificats_a_poser')->fetchColumn());
    }

    public function testRefusEtPage(): void
    {
        $preparation = new PreparationWordPress($this->pdo);
        foreach ([[['durandca'], 'ne peut pas recevoir'], [['inconnu'], 'ne peut pas recevoir'], [[], 'Cochez au moins un étudiant']] as [$coches, $attendu]) {
            try {
                $preparation->lancer($this->session, $coches, $this->compte('cpetit'), null);
                self::fail('Refus attendu : ' . $attendu);
            } catch (ErreurSaisie $e) {
                self::assertStringContainsString($attendu, $e->getMessage());
            }
        }
        $page = $this->en('cpetit', 'POST', '/sites/wordpress', ['action' => 'afficher', 'session' => (string) $this->session])->corps;
        self::assertStringContainsString('possible — sous-domaine à créer', $page);
        self::assertStringContainsString('pas encore fixée', $page);
        self::assertStringContainsString('data-tout-cocher', $page);
        self::assertStringNotContainsString('value="durandca"', $page);
        self::assertStringNotContainsString('" checked', $page, 'aucun étudiant coché d\'avance');
        self::assertSame(403, $this->en('pmoreau', 'GET', '/sites/wordpress')->statut);
        $reponse = $this->en('cpetit', 'POST', '/sites/wordpress', ['action' => 'lancer', 'session' => (string) $this->session], ['coches' => ['martinel']]);
        self::assertSame(303, $reponse->statut);
        self::assertSame('création déjà prévue', $preparation->etudiants($this->session)[2]['empechement']);
        self::assertStringContainsString('Créer des WordPress', $this->en('cpetit', 'GET', '/')->corps);
    }

    public function testRaisonDonneeParAcmeSh(): void
    {
        $sortie = "[Sat Nov 14 16:31:05 UTC 2026] Using CA: https://acme-v02.api.letsencrypt.org/directory\n"
            . "[Sat Nov 14 16:31:09 UTC 2026] \e[31mmartinel.bts-sup-sallanches.com: Invalid status. Verification error details: 1.2.3.4: Invalid response from http://martinel.bts-sup-sallanches.com/.well-known/acme-challenge/abc: 404\e[0m\n"
            . "[Sat Nov 14 16:31:09 UTC 2026] Please add '--debug' or '--log' to see more information.\n";
        $raison = AcmeSh::raison($sortie);
        self::assertStringStartsWith(' : martinel.bts-sup-sallanches.com: Invalid status. Verification error details', $raison);
        self::assertStringNotContainsString('Sat Nov', $raison);
        self::assertStringNotContainsString("\e[", $raison);
        self::assertSame('.', AcmeSh::raison("tout va bien\n"));
    }

    // ===== Réinitialisation (15/11/2026) =====

    public function testReinitialiserUnWordPressCreeParLePortail(): void
    {
        (new PreparationWordPress($this->pdo))->lancer($this->session, ['leroyhu'], $this->compte('cpetit'), null);
        $this->tourner();
        $wp = $this->personnel . '/leroyhu.bts-sup-sallanches.com/WP';
        file_put_contents($wp . '/travail-de-l-etudiant.txt', 'ancien contenu');
        $ancienne = $this->pdo->query("SELECT base, utilisateur_bdd FROM sites WHERE identifiant = 'leroyhu'")->fetch(PDO::FETCH_ASSOC);
        $this->boite->envoyes = [];
        $this->wp = [];
        (new DepotComptes($this->pdo))->creer('cpetit2', 'Cpetit2', 'cpetit2@exemple.fr', 'enseignant', MotsDePasse::hacher('un mot de passe solide'));

        $id = (new Reinitialisations($this->pdo))->lancer(Reinitialisations::WORDPRESS, 'leroyhu', true, $this->compte('cpetit'), null, time());
        $this->tourner();

        $etape = $this->pdo->query('SELECT etat, message FROM travaux_etapes WHERE travail_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('reussi', $etape['etat'], (string) $etape['message']);
        self::assertStringContainsString('l\'ancien est gardé 8 jours', (string) $etape['message']);
        self::assertSame([], array_filter($this->wp, static fn (array $a): bool => $a['arguments'][0] === 'user'), 'créé par le portail : pas besoin de vérifier les comptes');
        // Ancien /WP mis à l'écart avec sa base et son utilisateur (purgés au bout de 8 jours) ; nouveau /WP en place.
        $ecart = $this->pdo->query("SELECT chemin, base, utilisateur_bdd, motif FROM mises_a_l_ecart WHERE identifiant = 'leroyhu'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame([$ancienne['base'], $ancienne['utilisateur_bdd'], 'WordPress réinitialisé'], [$ecart['base'], $ecart['utilisateur_bdd'], $ecart['motif']]);
        self::assertFileExists($this->personnel . '/archives_suppression/portail/' . $ecart['chemin'] . '/travail-de-l-etudiant.txt');
        self::assertFileDoesNotExist($wp . '/travail-de-l-etudiant.txt');
        self::assertFileExists($wp . '/wp-config.php');
        $nouvelle = $this->pdo->query("SELECT base FROM sites WHERE identifiant = 'leroyhu'")->fetchColumn();
        self::assertNotSame($ancienne['base'], $nouvelle);
        // E-mails : l'étudiant (objet de réinitialisation) ; récapitulatif à l'administrateur ET aux enseignants.
        self::assertSame(ExecutantCreerWordPress::SUJET_REINITIALISATION, $this->boite->envoyes[0]['sujet']);
        self::assertStringContainsString('a été réinitialisé', $this->boite->envoyes[0]['texte']);
        self::assertSame(['admin@exemple.fr', 'cpetit@exemple.fr', 'cpetit2@exemple.fr'], $this->boite->envoyes[1]['destinataires']);
        self::assertStringContainsString('Sites WordPress réinitialisés', $this->boite->envoyes[1]['texte']);
    }

    public function testReinitialiserUnAncienSiteSeulementSIlEstALEtudiant(): void
    {
        // durandca : /WP créé par les scripts (base jafl3067_durandca, utilisateur partagé jafl3067_peda).
        $wp = $this->personnel . '/durandca.bts-sup-sallanches.com/WP';
        file_put_contents($wp . '/wp-config.php', "<?php\ndefine('DB_NAME', 'jafl3067_durandca');\ndefine('DB_USER', 'jafl3067_peda');\n\$table_prefix = 'wp_';\n");
        $identifiantWp = (new PreparationWordPress($this->pdo))->etudiants($this->session)[0]['identifiant_wp'];
        $reinitialiser = fn (): int => (new Reinitialisations($this->pdo))->lancer(Reinitialisations::WORDPRESS, 'durandca', true, $this->compte('cpetit'), null, time());

        // Aucun compte à son nom : refus, rien n'est touché.
        $this->comptesWp = "user_login,user_email\nAutreA,autre@exemple.fr\n";
        $reinitialiser();
        $this->tourner();
        $etape = $this->pdo->query("SELECT etat, message FROM travaux_etapes ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('echec', $etape['etat']);
        self::assertStringContainsString('site non reconnu comme le sien, rien n\'a été fait', (string) $etape['message']);
        self::assertFileExists($wp . '/index.php');
        self::assertSame([], array_filter($this->cpanel['appels'], static fn (string $a): bool => str_starts_with($a, 'creerBase')));

        // Son compte (identifiant WordPress, sans tenir compte des majuscules) : réinitialisé ; ancienne base purgeable,
        // utilisateur partagé jamais retenu.
        $this->comptesWp = "user_login,user_email\n" . strtolower($identifiantWp) . ",ancienne@exemple.fr\n";
        $reinitialiser();
        $this->tourner();
        $etape = $this->pdo->query("SELECT etat, message FROM travaux_etapes ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('reussi', $etape['etat'], (string) $etape['message']);
        $ecart = $this->pdo->query("SELECT base, utilisateur_bdd FROM mises_a_l_ecart WHERE identifiant = 'durandca'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['jafl3067_durandca', null], [$ecart['base'], $ecart['utilisateur_bdd']]);
    }

    public function testEchecDeConstructionLAncienSiteResteEnPlace(): void
    {
        (new PreparationWordPress($this->pdo))->lancer($this->session, ['leroyhu'], $this->compte('cpetit'), null);
        $this->tourner();
        $wp = $this->personnel . '/leroyhu.bts-sup-sallanches.com/WP';
        $config = file_get_contents($wp . '/wp-config.php');
        (new Reinitialisations($this->pdo))->lancer(Reinitialisations::WORDPRESS, 'leroyhu', true, $this->compte('cpetit'), null, time());
        $this->echecWp = 'core install';
        $this->tourner();
        $etape = $this->pdo->query("SELECT etat, message FROM travaux_etapes ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('echec', $etape['etat']);
        self::assertStringContainsString('Le WordPress en place n\'a pas été touché', (string) $etape['message']);
        self::assertSame($config, file_get_contents($wp . '/wp-config.php'));
        self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM mises_a_l_ecart')->fetchColumn());
        self::assertSame([], glob($this->donnees . '/tmp/wp-*'));
    }

    public function testFicheBoutonsConfirmationEtRedoublant(): void
    {
        (new PreparationWordPress($this->pdo))->lancer($this->session, ['leroyhu'], $this->compte('cpetit'), null);
        $this->tourner();
        $id = (int) $this->pdo->query("SELECT id FROM etudiants WHERE identifiant = 'leroyhu'")->fetchColumn();
        $page = $this->en('cpetit', 'GET', '/etudiants/' . $id)->corps;
        self::assertStringContainsString('Réinitialiser le WordPress (/WP)', $page);
        self::assertStringNotContainsString('Réinitialiser la boutique', $page, 'pas de boutique créée par le portail');
        self::assertStringNotContainsString('Redoublant', $page);
        $refus = $this->en('cpetit', 'POST', '/etudiants/' . $id, ['action' => 'reinitialiser_wp']);
        self::assertStringContainsString('Cochez la case de confirmation', $refus->corps);
        self::assertSame(303, $this->en('cpetit', 'POST', '/etudiants/' . $id, ['action' => 'reinitialiser_wp', 'confirmer' => '1'])->statut);
        self::assertStringContainsString('réinitialisation déjà prévue', $this->en('cpetit', 'GET', '/etudiants/' . $id)->corps);
        // Consultation : ni bouton, ni droit.
        self::assertStringNotContainsString('Réinitialiser le WordPress', $this->en('pmoreau', 'GET', '/etudiants/' . $id)->corps);
        // Redoublant de 1re année (session 2028 → 2029, en 1re année cette année scolaire) : suggestion affichée.
        $this->pdo->exec("DELETE FROM travaux_etapes; DELETE FROM travaux; UPDATE sites SET cree_le = '2026-09-01T00:00:00Z'");
        $this->pdo->exec("INSERT INTO historique_etudiants (date, etudiant_identifiant, champ, ancienne_valeur, nouvelle_valeur) VALUES ('2026-11-15T08:00:00Z', 'leroyhu', 'session', '2027', '2028')");
        $etat = (new Reinitialisations($this->pdo))->etat('leroyhu', strtotime('2026-11-15T09:00:00Z'));
        self::assertSame('Redoublant de 1re année : son WordPress est à réinitialiser (décision du 07/11/2026).', $etat['suggestion']);
        // Une fois le WordPress refait, plus de suggestion.
        $this->pdo->exec("UPDATE sites SET cree_le = '2026-11-15T10:00:00Z'");
        self::assertNull((new Reinitialisations($this->pdo))->etat('leroyhu', strtotime('2026-11-15T11:00:00Z'))['suggestion']);
    }

    // ===== Outils =====

    private function tourner(): void
    {
        $travailleur = new Travailleur($this->pdo, new Alertes($this->pdo, null, self::URL), [
            'creer_wordpress' => new ExecutantCreerWordPress($this->pdo, $this->fauxCpanel(), $this->fauxWp(), $this->fauxCertificats(), $this->chiffrement,
                $this->boite, $this->personnel, $this->donnees . '/tmp'),
            'reinitialiser_wordpress' => new ExecutantCreerWordPress($this->pdo, $this->fauxCpanel(), $this->fauxWp(), $this->fauxCertificats(), $this->chiffrement,
                $this->boite, $this->personnel, $this->donnees . '/tmp', new MiseALecart($this->pdo, $this->personnel), true),
        ], 0, static function (int $s): void {
        });
        $travailleur->tourner(60);
    }

    private function fauxCertificats(): Certificats
    {
        $test = $this;

        return new class ($test) implements Certificats {
            public function __construct(private readonly CreationWordPressTest $test)
            {
            }

            public function poser(string $sousDomaine, string $racine): void
            {
                if ($this->test->echecCertificat) {
                    throw new RuntimeException('Certificat non obtenu (acme.sh, code 1) : Invalid response from http://exemple/.well-known/acme-challenge/x: 404.');
                }
                $this->test->certificats[] = $sousDomaine . ' ' . $racine;
            }
        };
    }

    private function fauxCpanel(): ServeurCpanel
    {
        $test = $this;
        $personnel = $this->personnel;

        return new class ($test, $personnel) implements ServeurCpanel {
            public function __construct(private readonly CreationWordPressTest $test, private readonly string $personnel)
            {
            }

            public function sousDomaines(): array
            {
                return $this->test->cpanel['sousDomaines'];
            }

            public function creerSousDomaine(string $identifiant): void
            {
                $this->test->cpanel['appels'][] = 'creerSousDomaine ' . $identifiant;
                $dossier = $this->personnel . '/' . $identifiant . '.bts-sup-sallanches.com';
                mkdir($dossier);
                $this->test->cpanel['sousDomaines'][$identifiant] = $dossier;
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
            public function __construct(private readonly CreationWordPressTest $test)
            {
            }

            public function lancer(string $chemin, array $arguments, int $delaiSecondes = 300, #[\SensitiveParameter] array $environnement = []): string
            {
                $this->test->wp[] = ['chemin' => $chemin, 'arguments' => $arguments, 'environnement' => $environnement];
                $commande = implode(' ', array_slice($arguments, 0, 2));
                if ($this->test->echecWp === $commande) {
                    throw new RuntimeException('WP-CLI (' . $commande . ') a échoué : simulé.');
                }
                if ($commande === 'user list') {
                    return $this->test->comptesWp;
                }
                if ($commande === 'core download') {
                    $version = '7.1.4';
                    foreach ($arguments as $argument) {
                        $version = str_starts_with($argument, '--version=') ? substr($argument, 10) : $version;
                    }
                    mkdir($chemin . '/wp-includes', 0755, true);
                    file_put_contents($chemin . '/wp-includes/version.php', "<?php\n\$wp_version = '" . $version . "';\n");
                    file_put_contents($chemin . '/wp-login.php', '<?php');

                    return 'Success';
                }
                if (!is_file($chemin . '/wp-config.php')) {
                    throw new RuntimeException('wp-config.php absent');
                }

                return '';
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
