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
use Portail\Etudiants\ImportEtudiants;
use Portail\Etudiants\RegimesSessions;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Sites\Demandes;
use Portail\Sites\MiseALecart;
use Portail\Sites\NettoyageCpanel;
use Portail\Sites\Releve;
use Portail\Sites\RetraitCertificats;
use Portail\Sites\ServeurCpanel;
use Portail\Sites\Suppressions;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\ExpediteurMemoire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Travaux\ExecutantRestaurer;
use Portail\Travaux\ExecutantSupprimer;
use Portail\Travaux\FileTravaux;
use Portail\Travaux\Travailleur;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;

/**
 * Lot 5, paquet B (15/11/2026) : demandes à l'administrateur, suppression d'un site ou d'un étudiant (restaurable 8 jours),
 * restauration et effacement au bout des 8 jours. CPanel simulé, noms inventés.
 */
final class SuppressionsTest extends TestCase
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
    private int $regime;
    /** @var array{appels: list<string>, sousDomaines: array<string, string>, bases: list<string>, utilisateurs: list<string>} */
    public array $cpanel = ['appels' => [], 'sousDomaines' => [], 'bases' => [], 'utilisateurs' => []];
    /** @var list<string> */
    public array $certificatsRetires = [];

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
        $this->pdo->exec("INSERT INTO parametres (cle, valeur) VALUES ('adresse_alertes', 'admin@exemple.fr'), ('bases_protegees', '[\"jafl3067_peda\"]')");
        $this->horloge = new HorlogeFixe(1_794_000_000);
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $this->boite = new ExpediteurMemoire();
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'jroux' => 'administratif'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        $referentiel = new RegimesSessions($this->pdo);
        $this->regime = $referentiel->creerRegime('NDRC FI');
        $this->session = $referentiel->creerSession('2027');
        $gestion = new GestionEtudiants($this->pdo, $this->compte('mzanelli'));
        foreach ([['MARTIN', 'Élodie', 'martinel'], ['DURAND', 'Camille', 'durandca'], ['LEROY', 'Hugo', 'leroyhu']] as [$nom, $prenom, $id]) {
            $gestion->ajouter($nom, $prenom, $id . '@exemple.fr', $this->regime, $this->session, $id);
        }
        // martinel : /WP des scripts (base propre dans wp-config.php), /WP2 avec la base partagée des scripts, /Presta créée par le portail.
        $racine = $this->personnel . '/martinel.bts-sup-sallanches.com';
        mkdir($racine . '/WP', 0700, true);
        file_put_contents($racine . '/WP/wp-config.php', "<?php\ndefine('DB_NAME', 'jafl3067_martinel_WP');\ndefine('DB_USER', 'jafl3067_martinel_wp');\n");
        mkdir($racine . '/WP2', 0700, true);
        file_put_contents($racine . '/WP2/wp-config.php', "<?php\ndefine('DB_NAME', 'jafl3067_peda');\ndefine('DB_USER', 'jafl3067_peda');\n");
        mkdir($racine . '/Presta/app/config', 0700, true);
        file_put_contents($racine . '/Presta/app/config/parameters.php', "<?php return ['parameters' => ['database_name' => 'jafl3067_martinel_Presta']];");
        $this->pdo->exec("INSERT INTO sites (identifiant, dossier, logiciel, version, dossier_admin, base, utilisateur_bdd)
            VALUES ('martinel', '/Presta', 'prestashop', '9.1.5', 'admin-martinel-abc123', 'jafl3067_martinel_Presta', 'jafl3067_martinel_presta')");
        mkdir($this->personnel . '/durandca.bts-sup-sallanches.com/WP', 0700, true);
        $this->cpanel['sousDomaines'] = ['martinel' => $racine, 'durandca' => $this->personnel . '/durandca.bts-sup-sallanches.com'];
        $this->cpanel['bases'] = ['jafl3067_martinel_WP', 'jafl3067_martinel_Presta', 'jafl3067_martinel_ancienne', 'jafl3067_martinelb', 'jafl3067_peda', 'jafl3067_durandca_WP'];
        $this->cpanel['utilisateurs'] = ['jafl3067_martinel_wp', 'jafl3067_martinel_presta', 'jafl3067_peda'];
        Releve::enregistrer($this->pdo, $this->cpanel['sousDomaines'], [
            'martinel' => [['dossier' => '/WP', 'logiciel' => 'wordpress', 'version' => '7.1.4'], ['dossier' => '/WP2', 'logiciel' => 'wordpress', 'version' => '7.1.4'],
                ['dossier' => '/Presta', 'logiciel' => 'prestashop', 'version' => '9.1.5']],
            'durandca' => [['dossier' => '/WP', 'logiciel' => 'wordpress', 'version' => '7.1.4']],
        ], '2026-11-15T08:00:00Z');
    }

    // ===== Suppression d'un étudiant, restauration, effacement =====

    public function testSeulLAdministrateurSupprimeEtIlConfirmeEnTapantLIdentifiant(): void
    {
        $fiche = '/etudiants/' . $this->idEtudiant('martinel');
        self::assertStringNotContainsString('Supprimer (administrateur)', $this->en('cpetit', 'GET', $fiche)->corps);
        self::assertStringContainsString('Demander à l\'administrateur', $this->en('cpetit', 'GET', $fiche)->corps);
        self::assertSame(403, $this->en('cpetit', 'POST', $fiche, ['action' => 'supprimer', 'choix' => 'etudiant', 'confirmation' => 'martinel'])->statut);

        $page = $this->en('mzanelli', 'GET', $fiche)->corps;
        self::assertStringContainsString('Supprimer (administrateur)', $page);
        self::assertStringContainsString('value="site:/WP2"', $page);
        $refus = $this->en('mzanelli', 'POST', $fiche, ['action' => 'supprimer', 'choix' => 'etudiant', 'confirmation' => 'martine']);
        self::assertStringContainsString('tapez exactement l&apos;identifiant', $refus->corps);
        self::assertSame(0, $this->nombre('SELECT count(*) FROM travaux'));

        $reponse = $this->en('mzanelli', 'POST', $fiche, ['action' => 'supprimer', 'choix' => 'etudiant', 'confirmation' => 'martinel']);
        self::assertSame(303, $reponse->statut);
        self::assertSame('supprimer_etudiant', $this->pdo->query('SELECT type FROM travaux')->fetchColumn());
    }

    public function testSupprimerUnEtudiantMetToutALEcartEtGardeSonIdentifiant(): void
    {
        (new Suppressions($this->pdo))->lancer(Suppressions::ETUDIANT, [['martinel', null]], $this->compte('mzanelli'), null, 'Départ');
        $this->tourner();

        self::assertSame('reussi', $this->pdo->query('SELECT etat FROM travaux_etapes')->fetchColumn());
        self::assertFalse(is_dir($this->personnel . '/martinel.bts-sup-sallanches.com'), 'Dossier du sous-domaine mis à l\'écart');
        self::assertSame(0, $this->nombre("SELECT count(*) FROM etudiants WHERE identifiant = 'martinel'"), 'L\'étudiant quitte la liste');
        self::assertSame(0, $this->nombre("SELECT count(*) FROM sites WHERE identifiant = 'martinel'"));
        self::assertSame([], $this->cpanel['appels'], 'Rien n\'est effacé dans CPanel pendant les 8 jours');
        self::assertArrayHasKey('martinel', $this->cpanel['sousDomaines'], 'Le sous-domaine reste jusqu\'à l\'effacement');

        $ecart = MiseALecart::restaurables($this->pdo)[0];
        self::assertSame(MiseALecart::SOUS_DOMAINE, $ecart['dossier']);
        self::assertSame('MARTIN Élodie', $ecart['nom']);
        $contenu = json_decode((string) $this->pdo->query('SELECT contenu FROM mises_a_l_ecart')->fetchColumn(), true);
        self::assertIsArray($contenu);
        sort($contenu['bases']);
        self::assertSame(['jafl3067_martinel_Presta', 'jafl3067_martinel_WP', 'jafl3067_martinel_ancienne'], $contenu['bases'],
            'Bases à son nom exact ; ni martinelb, ni la base partagée peda');
        self::assertNotContains('jafl3067_peda', $contenu['utilisateurs']);

        // Identifiant réservé pendant 8 jours : ni ajout, ni import.
        self::assertTrue((new GestionEtudiants($this->pdo))->identifiantPris('martinel'));
        $lignes = (new ImportEtudiants($this->pdo, $this->compte('jroux'), null))
            ->analyser("NomPrénom\tClasse\tSession\tIdentifiant\tIdentifiant WordPress\tE-mail\nMARTIN Élodie\tNDRC FI\t2027\tmartinel\tMartineL\tmartinel@exemple.fr");
        self::assertSame('rouge', $lignes[0]['statut']);
        self::assertStringContainsString('Étudiant supprimé, restaurable', implode(' ', $lignes[0]['raisons']));

        // Voir tout : plus parmi les sous-domaines sans étudiant, mais signalé comme restaurable.
        $page = $this->en('mzanelli', 'GET', '/sites')->corps;
        self::assertStringContainsString('Étudiants supprimés, restaurables', $page);
    }

    public function testRestaurerRemetLaFicheLesSitesEtLeDossier(): void
    {
        (new Suppressions($this->pdo))->lancer(Suppressions::ETUDIANT, [['martinel', null]], $this->compte('mzanelli'), null);
        $this->tourner();
        $chemin = MiseALecart::restaurables($this->pdo)[0]['chemin'];
        self::assertSame(403, $this->en('cpetit', 'POST', '/demandes', ['action' => 'restaurer', 'chemin' => $chemin])->statut);
        self::assertSame(303, $this->en('mzanelli', 'POST', '/demandes', ['action' => 'restaurer', 'chemin' => $chemin])->statut);
        $this->tourner();

        self::assertSame(1, $this->nombre("SELECT count(*) FROM etudiants WHERE identifiant = 'martinel' AND nom_complet = 'MARTIN Élodie'"));
        self::assertSame('jafl3067_martinel_Presta', $this->pdo->query("SELECT base FROM sites WHERE identifiant = 'martinel'")->fetchColumn());
        self::assertFileExists($this->personnel . '/martinel.bts-sup-sallanches.com/WP/wp-config.php');
        self::assertSame(3, $this->nombre("SELECT count(*) FROM releve_sites WHERE identifiant = 'martinel'"));
        self::assertSame([], MiseALecart::restaurables($this->pdo));
        self::assertNull((new MiseALecart($this->pdo, $this->personnel))->purgerUne($this->fauxCpanel(), [], $this->horloge->instant + 9 * 86400,
            $this->fauxCpanel(), $this->fauxCertificats()), 'Une suppression restaurée n\'est jamais effacée');
        self::assertSame([], $this->cpanel['appels']);
    }

    public function testAuBoutDeHuitJoursToutEstEffaceSousDomaineCompris(): void
    {
        (new Suppressions($this->pdo))->lancer(Suppressions::ETUDIANT, [['martinel', null]], $this->compte('mzanelli'), null);
        $this->tourner();
        $ecart = new MiseALecart($this->pdo, $this->personnel);
        self::assertNull($ecart->purgerUne($this->fauxCpanel(), ['jafl3067_peda'], time() + 3 * 86400, $this->fauxCpanel(), $this->fauxCertificats()));
        self::assertNull($ecart->purgerUne($this->fauxCpanel(), ['jafl3067_peda'], time() + 9 * 86400), 'Sans accès au nettoyage CPanel : rien n\'est effacé');
        self::assertSame([], $this->cpanel['appels']);
        $this->pdo->exec("UPDATE mises_a_l_ecart SET expire_le = '2020-01-01T00:00:00Z'");
        mkdir($this->personnel . '/martinel.bts-sup-sallanches.com');

        self::assertNotNull($ecart->purgerUne($this->fauxCpanel(), ['jafl3067_peda'], time(), $this->fauxCpanel(), $this->fauxCertificats()));
        $appels = $this->cpanel['appels'];
        sort($appels);
        self::assertSame(['base jafl3067_martinel_Presta', 'base jafl3067_martinel_WP', 'base jafl3067_martinel_ancienne', 'sous-domaine martinel',
            'utilisateur jafl3067_martinel_presta', 'utilisateur jafl3067_martinel_wp'], $appels);
        self::assertSame(['martinel.bts-sup-sallanches.com'], $this->certificatsRetires);
        self::assertDirectoryDoesNotExist($this->personnel . '/martinel.bts-sup-sallanches.com', 'Dossier vide laissé par CPanel effacé');
        self::assertSame(0, $this->nombre("SELECT count(*) FROM sous_domaines WHERE identifiant = 'martinel'"));
        self::assertSame([], glob($ecart->racine() . '/*martinel*') ?: []);
        self::assertFalse((new GestionEtudiants($this->pdo))->identifiantPris('martinel'), 'Identifiant libre après l\'effacement');
    }

    public function testSupprimerPuisRestaurerUnSeulSite(): void
    {
        $suppressions = new Suppressions($this->pdo);
        try {
            $suppressions->lancer(Suppressions::SITE, [['durandca', '/Presta']], $this->compte('mzanelli'), null);
            self::fail('Site inexistant accepté');
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString('n\'a pas de site /Presta', $e->getMessage());
        }
        $suppressions->lancer(Suppressions::SITE, [['martinel', '/Presta']], $this->compte('mzanelli'), null);
        $this->tourner();
        self::assertDirectoryDoesNotExist($this->personnel . '/martinel.bts-sup-sallanches.com/Presta');
        self::assertDirectoryExists($this->personnel . '/martinel.bts-sup-sallanches.com/WP', 'Les autres sites ne sont pas touchés');
        self::assertSame(1, $this->nombre("SELECT count(*) FROM etudiants WHERE identifiant = 'martinel'"));
        self::assertSame('jafl3067_martinel_Presta', $this->pdo->query('SELECT base FROM mises_a_l_ecart')->fetchColumn());

        $suppressions->restaurer(MiseALecart::restaurables($this->pdo)[0]['chemin'], $this->compte('mzanelli'), null);
        $this->tourner();
        self::assertFileExists($this->personnel . '/martinel.bts-sup-sallanches.com/Presta/app/config/parameters.php');
        self::assertSame('admin-martinel-abc123', $this->pdo->query("SELECT dossier_admin FROM sites WHERE dossier = '/Presta'")->fetchColumn());
    }

    public function testLaBaseDuWp2PartageeNEstJamaisRetenue(): void
    {
        (new Suppressions($this->pdo))->lancer(Suppressions::SITE, [['martinel', '/WP2']], $this->compte('mzanelli'), null);
        $this->tourner();
        self::assertNull($this->pdo->query('SELECT base FROM mises_a_l_ecart')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT utilisateur_bdd FROM mises_a_l_ecart')->fetchColumn());
    }

    public function testSuppressionEnMasseDUneSessionParLAdministrateur(): void
    {
        self::assertSame(403, $this->en('cpetit', 'GET', '/sites/supprimer')->statut, 'Administrateur seul');
        $page = $this->en('mzanelli', 'POST', '/sites/supprimer', ['action' => 'afficher', 'session' => (string) $this->session])->corps;
        self::assertStringContainsString('Session 2027 (3 étudiants)', $page);
        self::assertStringContainsString('Boutique (cours) <span class="tech">/Presta</span>', $page, 'Sites en mots simples');
        self::assertStringContainsString('data-tout-cocher', $page);

        $tous = ['martinel', 'durandca', 'leroyhu'];
        $sansMot = $this->en('mzanelli', 'POST', '/sites/supprimer', ['action' => 'supprimer', 'session' => (string) $this->session, 'choix' => 'etudiant',
            'confirmation' => 'oui'], ['coches' => $tous]);
        self::assertStringContainsString('tapez SUPPRIMER', $sansMot->corps);
        self::assertSame(0, $this->nombre('SELECT count(*) FROM travaux'));

        // Seulement les /WP : l'étudiant sans /WP est passé.
        $resultat = (new Suppressions($this->pdo))->enMasse($this->session, $tous, 'site:/WP', 'SUPPRIMER', 'Fin de formation', $this->compte('mzanelli'), null);
        self::assertSame(2, $resultat['retenus']);
        self::assertSame(['LEROY Hugo (pas de site /WP)'], $resultat['passes']);
        $this->tourner();
        self::assertDirectoryDoesNotExist($this->personnel . '/durandca.bts-sup-sallanches.com/WP');
        self::assertSame(3, $this->nombre('SELECT count(*) FROM etudiants'), 'Les étudiants restent');

        // Puis tout : un seul travail pour la session.
        $reponse = $this->en('mzanelli', 'POST', '/sites/supprimer', ['action' => 'supprimer', 'session' => (string) $this->session, 'choix' => 'etudiant',
            'confirmation' => 'SUPPRIMER', 'motif' => 'Fin de formation'], ['coches' => $tous]);
        self::assertSame(303, $reponse->statut);
        self::assertSame(3, $this->nombre("SELECT count(*) FROM travaux_etapes e JOIN travaux t ON t.id = e.travail_id WHERE t.type = 'supprimer_etudiant'"));
        $this->tourner();
        self::assertSame(0, $this->nombre('SELECT count(*) FROM etudiants'));
        self::assertCount(3, Suppressions::etudiantsSupprimes($this->pdo));
        self::assertStringContainsString('Fin de formation', (string) $this->pdo->query("SELECT motif FROM mises_a_l_ecart WHERE identifiant = 'leroyhu'")->fetchColumn());
    }

    // ===== Demandes à l'administrateur =====

    public function testUneDemandeAUnMotifObligatoireEtPrevientLAdministrateurSansNomDEtudiant(): void
    {
        $fiche = '/etudiants/' . $this->idEtudiant('martinel');
        $sans = $this->en('jroux', 'POST', $fiche, ['action' => 'demander', 'choix' => 'supprimer_etudiant', 'motif' => ' ']);
        self::assertStringContainsString('Le motif est obligatoire', $sans->corps);
        $alertes = new Alertes($this->pdo, $this->boite, self::URL);
        $demandes = new Demandes($this->pdo, $alertes);
        $demandes->creer('supprimer_etudiant', 'martinel', 'Départ de l\'établissement', $this->compte('jroux'), null);
        try {
            $demandes->creer('supprimer_etudiant', 'martinel', 'Encore', $this->compte('cpetit'), null);
            self::fail('Demande en double acceptée');
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString('déjà en attente', $e->getMessage());
        }
        $alertes->expedier();
        self::assertCount(1, $this->boite->envoyes);
        self::assertSame(['admin@exemple.fr'], $this->boite->envoyes[0]['destinataires']);
        self::assertStringNotContainsString('MARTIN', $this->boite->envoyes[0]['texte']);
        self::assertStringNotContainsString('martinel', $this->boite->envoyes[0]['texte']);

        // Compteur dans le menu de l'administrateur, étiquette dans « Voir tout ».
        self::assertStringContainsString('<span class="compteur" aria-label="1 en attente">1</span>', $this->en('mzanelli', 'GET', '/')->corps);
        self::assertStringNotContainsString('class="compteur"', $this->en('jroux', 'GET', '/')->corps);
        self::assertStringContainsString('Suppression demandée', $this->en('cpetit', 'GET', '/sites')->corps);
        self::assertStringContainsString('Mes demandes', $this->en('jroux', 'GET', '/demandes')->corps);
    }

    public function testLAdministrateurValideParLotEnTapantSupprimerUnSeulTravail(): void
    {
        $alertes = new Alertes($this->pdo, $this->boite, self::URL);
        $demandes = new Demandes($this->pdo, $alertes);
        $a = $demandes->creer('supprimer_etudiant', 'martinel', 'Fin de formation', $this->compte('jroux'), null);
        $b = $demandes->creer('supprimer_etudiant', 'durandca', 'Fin de formation', $this->compte('cpetit'), null);
        self::assertSame(403, $this->en('cpetit', 'POST', '/demandes', ['action' => 'valider', 'confirmation' => 'SUPPRIMER'], ['coches' => [(string) $a]])->statut);

        $sansMot = $this->en('mzanelli', 'POST', '/demandes', ['action' => 'valider', 'confirmation' => 'oui'], ['coches' => [(string) $a, (string) $b]]);
        self::assertStringContainsString('tapez SUPPRIMER', $sansMot->corps);
        self::assertSame(0, $this->nombre('SELECT count(*) FROM travaux'));

        $resultat = $demandes->valider([$a, $b], 'SUPPRIMER', $this->compte('mzanelli'), null, $this->horloge->maintenant());
        self::assertSame(2, $resultat['validees']);
        self::assertCount(1, $resultat['travaux'], 'Un seul travail pour le lot');
        self::assertSame(2, $this->nombre('SELECT count(*) FROM travaux_etapes'));
        self::assertSame(2, $this->nombre("SELECT count(*) FROM demandes WHERE etat = 'validee' AND travail_id IS NOT NULL"));
        $alertes->expedier();
        $decisions = array_values(array_filter($this->boite->envoyes, static fn (array $e): bool => $e['sujet'] === '[Portail] Réponse à vos demandes'));
        self::assertCount(2, $decisions, 'Chaque demandeur est prévenu');
        self::assertSame(['jroux@exemple.fr'], $decisions[0]['destinataires']);
    }

    public function testRefusCommenteEtAnnulationParLeDemandeur(): void
    {
        $alertes = new Alertes($this->pdo, $this->boite, self::URL);
        $demandes = new Demandes($this->pdo, $alertes);
        $a = $demandes->creer('supprimer_site:/WP2', 'martinel', 'Site cassé, à refaire', $this->compte('jroux'), null);
        $b = $demandes->creer('reinitialiser_wordpress', 'durandca', 'Redoublement', $this->compte('jroux'), null);
        try {
            $demandes->refuser([$a], '', $this->compte('mzanelli'), null);
            self::fail('Refus sans commentaire accepté');
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString('commentaire', $e->getMessage());
        }
        self::assertSame(1, $demandes->refuser([$a], 'Le /WP2 sert au BTS blanc', $this->compte('mzanelli'), null));
        try {
            $demandes->annuler($b, $this->compte('cpetit'), null);
            self::fail('Annulation par un autre compte acceptée');
        } catch (ErreurSaisie) {
        }
        $demandes->annuler($b, $this->compte('jroux'), null);
        self::assertSame(0, $demandes->nombreEnAttente());
        $page = $this->en('jroux', 'GET', '/demandes')->corps;
        self::assertStringContainsString('Le /WP2 sert au BTS blanc', $page);
        self::assertStringContainsString('Annulée', $page);
    }

    // ===== Outils =====

    private function tourner(): void
    {
        $ecart = new MiseALecart($this->pdo, $this->personnel);
        $travailleur = new Travailleur($this->pdo, new Alertes($this->pdo, null, self::URL), [
            'supprimer_site' => new ExecutantSupprimer($this->pdo, $this->fauxCpanel(), $ecart, $this->personnel, false),
            'supprimer_etudiant' => new ExecutantSupprimer($this->pdo, $this->fauxCpanel(), $ecart, $this->personnel, true),
            'restaurer' => new ExecutantRestaurer($this->pdo, $this->fauxCpanel(), $ecart, $this->personnel),
        ], 0, static function (int $s): void {
        });
        $travailleur->tourner(60);
    }

    private function fauxCpanel(): ServeurCpanel&NettoyageCpanel
    {
        $test = $this;

        return new class ($test) implements ServeurCpanel, NettoyageCpanel {
            public function __construct(private readonly SuppressionsTest $test)
            {
            }

            public function sousDomaines(): array
            {
                return $this->test->cpanel['sousDomaines'];
            }

            public function creerSousDomaine(string $identifiant): void
            {
                $this->test->cpanel['appels'][] = 'création ' . $identifiant;
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
                $this->test->cpanel['appels'][] = 'base ' . $base;
            }

            public function supprimerUtilisateur(string $utilisateur): void
            {
                $this->test->cpanel['appels'][] = 'utilisateur ' . $utilisateur;
            }

            public function bases(): array
            {
                return $this->test->cpanel['bases'];
            }

            public function utilisateurs(): array
            {
                return $this->test->cpanel['utilisateurs'];
            }

            public function supprimerSousDomaine(string $identifiant): void
            {
                $this->test->cpanel['appels'][] = 'sous-domaine ' . $identifiant;
                unset($this->test->cpanel['sousDomaines'][$identifiant]);
            }
        };
    }

    private function fauxCertificats(): RetraitCertificats
    {
        $test = $this;

        return new class ($test) implements RetraitCertificats {
            public function __construct(private readonly SuppressionsTest $test)
            {
            }

            public function retirer(string $sousDomaine): void
            {
                $this->test->certificatsRetires[] = $sousDomaine;
            }
        };
    }

    private function idEtudiant(string $identifiant): int
    {
        $requete = $this->pdo->prepare('SELECT id FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);

        return (int) $requete->fetchColumn();
    }

    private function nombre(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
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
