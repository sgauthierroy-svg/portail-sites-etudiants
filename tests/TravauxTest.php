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
use Portail\Etudiants\GestionEtudiants;
use Portail\Etudiants\RegimesSessions;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Systeme\Commande;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\ExpediteurMemoire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Travaux\Executant;
use Portail\Travaux\ExecutantReleve;
use Portail\Travaux\FileTravaux;
use Portail\Travaux\Travailleur;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;
use RuntimeException;

/**
 * V2, lot 1 : file d'attente, travailleur, « Voir tout », « Travaux en cours » (noms inventés).
 */
final class TravauxTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

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
        $this->pdo->exec("INSERT INTO parametres (cle, valeur) VALUES ('adresse_alertes', 'alertes@exemple.fr')");
        $this->horloge = new HorlogeFixe(1_794_000_000);
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $this->boite = new ExpediteurMemoire();
        $this->alertes = new Alertes($this->pdo, $this->boite, self::URL);
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'jroux' => 'administratif', 'pmoreau' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
    }

    // ===== Commande : jamais de shell =====

    public function testUneCommandeNePassePasParUnShell(): void
    {
        $resultat = Commande::lancer(['printf', '%s', 'a; touch ' . $this->dossier->racine . '/pirate']);
        self::assertSame(0, $resultat['code']);
        self::assertSame('a; touch ' . $this->dossier->racine . '/pirate', $resultat['sortie']);
        self::assertFileDoesNotExist($this->dossier->racine . '/pirate');
    }

    public function testUneCommandeTropLongueEstArretee(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('arrêté');
        Commande::lancer(['sleep', '5'], 1);
    }

    public function testLeCodeDeSortieEstRendu(): void
    {
        self::assertSame(3, Commande::lancer(['sh', '-c', 'exit 3'])['code']);
    }

    // ===== File et travailleur =====

    public function testLeTravailleurTraiteLesEtapesDansLOrdreAvecUnePause(): void
    {
        $vus = [];
        $pauses = [];
        $executant = $this->executant(static function (string $cible) use (&$vus): array {
            $vus[] = $cible;

            return ['etat' => 'reussi', 'message' => 'fait pour ' . $cible];
        });
        $id = $this->demander('creer_wordpress', ['martinel', 'duponta', 'leemi']);
        $travailleur = new Travailleur($this->pdo, $this->alertes, ['creer_wordpress' => $executant], 3,
            static function (int $s) use (&$pauses): void {
                $pauses[] = $s;
            });

        self::assertSame(3, $travailleur->tourner(50));
        self::assertSame(['martinel', 'duponta', 'leemi'], $vus);
        self::assertSame([3, 3], $pauses, 'Une pause entre deux étudiants, jamais avant le premier');
        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame('termine', $travail['etat']);
        self::assertSame(['reussi', 'reussi', 'reussi'], array_column($travail['etapes'], 'etat'));
        self::assertSame([], $this->boite->envoyes, 'Pas d\'alerte quand tout a réussi');
    }

    public function testUnEchecNArretePasLesAutresEtDeclencheUneAlerte(): void
    {
        $executant = $this->executant(static function (string $cible): array {
            if ($cible === 'duponta') {
                throw new RuntimeException('Sous-domaine introuvable.');
            }

            return ['etat' => 'reussi', 'message' => 'ok'];
        });
        $id = $this->demander('creer_wordpress', ['martinel', 'duponta', 'leemi']);
        (new Travailleur($this->pdo, $this->alertes, ['creer_wordpress' => $executant], 0, static function (): void {
        }))->tourner(50);

        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame(['reussi', 'echec', 'reussi'], array_column($travail['etapes'], 'etat'));
        self::assertSame('Sous-domaine introuvable.', $travail['etapes'][1]['message']);
        self::assertCount(1, $this->boite->envoyes);
        self::assertStringContainsString('1 échec', $this->boite->envoyes[0]['sujet']);
        self::assertStringContainsString('/travaux/' . $id, $this->boite->envoyes[0]['texte']);
        self::assertStringNotContainsString('duponta', $this->boite->envoyes[0]['texte'], 'Aucune donnée d\'étudiant dans l\'alerte');
    }

    public function testUnTypeSansExecutantNeFaitRien(): void
    {
        $id = $this->demander('supprimer_etudiant', ['martinel']);
        (new Travailleur($this->pdo, $this->alertes, [], 0, static function (): void {
        }))->tourner(50);
        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame('echec', $travail['etapes'][0]['etat']);
        self::assertStringContainsString('pas encore disponible', (string) $travail['etapes'][0]['message']);
    }

    public function testUneEtapeInterrompueNEstJamaisRelancee(): void
    {
        $id = $this->demander('creer_wordpress', ['martinel', 'duponta']);
        // Arrêt brutal simulé : la première étape est restée « en cours ».
        $this->pdo->exec("UPDATE travaux SET etat = 'en_cours'");
        $this->pdo->exec("UPDATE travaux_etapes SET etat = 'en_cours' WHERE rang = 1");
        $vus = [];
        (new Travailleur($this->pdo, $this->alertes, ['creer_wordpress' => $this->executant(static function (string $c) use (&$vus): array {
            $vus[] = $c;

            return ['etat' => 'reussi', 'message' => 'ok'];
        })], 0, static function (): void {
        }))->tourner(50);

        self::assertSame(['duponta'], $vus);
        $travail = (new FileTravaux($this->pdo))->detail($id);
        self::assertNotNull($travail);
        self::assertSame('echec', $travail['etapes'][0]['etat']);
        self::assertStringContainsString('Interrompue', (string) $travail['etapes'][0]['message']);
    }

    public function testLeBudgetDeTempsEstRespecte(): void
    {
        $this->demander('creer_wordpress', ['martinel', 'duponta', 'leemi']);
        $temps = 0.0;
        $executant = $this->executant(static function () use (&$temps): array {
            $temps += 30;

            return ['etat' => 'reussi', 'message' => 'ok'];
        });
        $travailleur = new Travailleur($this->pdo, $this->alertes, ['creer_wordpress' => $executant], 0, static function (): void {
        });
        self::assertSame(2, $travailleur->tourner(50, static function () use (&$temps): float {
            return $temps;
        }), 'Après 60 s, plus de nouvelle étape : la tâche suivante reprendra');
        self::assertSame(1, $travailleur->tourner(50, static function () use (&$temps): float {
            return $temps;
        }));
    }

    public function testArreterUnTravailAnnuleLesEtapesRestantes(): void
    {
        $id = $this->demander('creer_wordpress', ['martinel', 'duponta']);
        $file = new FileTravaux($this->pdo);
        self::assertSame(2, $file->annuler($id, $this->compte('mzanelli'), '192.0.2.1'));
        $travail = $file->detail($id);
        self::assertNotNull($travail);
        self::assertSame('annule', $travail['etat']);
        self::assertSame(0, (new Travailleur($this->pdo, $this->alertes, [], 0))->tourner(50));
    }

    public function testSeulsLeDemandeurEtLAdministrateurPeuventArreter(): void
    {
        $id = $this->demander('creer_wordpress', ['martinel'], 'cpetit');
        $file = new FileTravaux($this->pdo);
        $travail = $file->detail($id);
        self::assertNotNull($travail);
        self::assertTrue($file->peutAnnuler($travail['etat'], $travail['demande_par'], $this->compte('cpetit')));
        self::assertTrue($file->peutAnnuler($travail['etat'], $travail['demande_par'], $this->compte('mzanelli')));
        self::assertFalse($file->peutAnnuler($travail['etat'], $travail['demande_par'], $this->compte('jroux')));
        $reponse = $this->en('jroux', 'POST', '/travaux/' . $id, ['action' => 'annuler']);
        self::assertSame(403, $reponse->statut);
    }

    public function testUnTypeInconnuEstRefuseParLaBase(): void
    {
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO travaux (type, demande_par_nom) VALUES ('effacer_tout', 'x')");
    }

    // ===== Relevé depuis le portail =====

    public function testLeReleveSeLanceDepuisLePortailPuisParLeTravailleur(): void
    {
        $referentiel = new RegimesSessions($this->pdo);
        $fi = $referentiel->creerRegime('NDRC FI');
        $s2027 = $referentiel->creerSession('2027');
        (new GestionEtudiants($this->pdo, $this->compte('mzanelli')))->ajouter('MARTIN', 'Élodie', 'e.martin@exemple.fr', $fi, $s2027, 'martinel');
        $reponse = $this->en('cpetit', 'POST', '/travaux', ['action' => 'actualiser_releve']);
        self::assertSame(200, $reponse->statut);
        self::assertStringContainsString('Relevé demandé', $reponse->corps);
        self::assertStringContainsString('http-equiv="refresh"', $reponse->corps, 'La page se met à jour tant que le travail tourne');
        $deuxieme = $this->en('jroux', 'POST', '/travaux', ['action' => 'actualiser_releve']);
        self::assertStringContainsString('déjà prévu', $deuxieme->corps);

        $racine = $this->dossier->racine . '/martinel.bts-sup-sallanches.com';
        mkdir($racine . '/WP/wp-includes', 0700, true);
        file_put_contents($racine . '/WP/wp-config.php', '<?php');
        file_put_contents($racine . '/WP/wp-includes/version.php', "<?php\n\$wp_version = '7.1.4';");
        $json = json_encode(['result' => ['data' => ['sub_domains' => [
            ['domain' => 'martinel.bts-sup-sallanches.com', 'documentroot' => $racine],
            ['domain' => 'ancien.bts-sup-sallanches.com', 'documentroot' => $this->dossier->racine . '/vide'],
        ]]]]);
        (new Travailleur($this->pdo, $this->alertes, ['releve' => new ExecutantReleve($this->pdo, static fn (): string => (string) $json)], 0))->tourner(50);

        $liste = (new FileTravaux($this->pdo))->lister();
        self::assertSame('termine', $liste[0]['etat']);
        self::assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM sous_domaines')?->fetchColumn());

        // « Voir tout » montre le site relevé, et le sous-domaine sans étudiant à ceux qui voient les fiches.
        $page = $this->en('cpetit', 'GET', '/sites');
        self::assertSame(200, $page->statut);
        self::assertStringContainsString('WordPress 7.1.4', $page->corps);
        self::assertStringContainsString('MARTIN Élodie', $page->corps);
        self::assertStringContainsString('<code>ancien</code>', $page->corps);
        $direction = $this->en('pmoreau', 'GET', '/sites');
        self::assertSame(200, $direction->statut);
        self::assertStringNotContainsString('<code>ancien</code>', $direction->corps, 'La direction ne voit pas les identifiants');
        self::assertStringNotContainsString('Actualiser le relevé', $direction->corps);
    }

    public function testLaDirectionNePeutPasLancerDeReleve(): void
    {
        $reponse = $this->en('pmoreau', 'POST', '/travaux', ['action' => 'actualiser_releve']);
        self::assertSame(403, $reponse->statut);
        self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM travaux')?->fetchColumn());
    }

    public function testLeDetailDUnTravailSAffiche(): void
    {
        $id = $this->demander('creer_wordpress', ['martinel'], 'cpetit');
        $page = $this->en('pmoreau', 'GET', '/travaux/' . $id);
        self::assertSame(200, $page->statut);
        self::assertStringContainsString('Étudiant martinel', $page->corps);
        self::assertStringNotContainsString('Arrêter ce travail', $page->corps);
        self::assertSame(404, $this->en('cpetit', 'GET', '/travaux/999')->statut);
    }

    // ===== Aides =====

    /** @param callable(string): array{etat: 'reussi'|'ignore', message: string} $action */
    private function executant(callable $action): Executant
    {
        return new class ($action) implements Executant {
            /** @var callable(string): array{etat: 'reussi'|'ignore', message: string} */
            private $action;

            public function __construct(callable $action)
            {
                $this->action = $action;
            }

            public function executer(string $cible, array $parametres): array
            {
                return ($this->action)($cible);
            }
        };
    }

    /** @param list<string> $cibles */
    private function demander(string $type, array $cibles, string $auteur = 'mzanelli'): int
    {
        return (new FileTravaux($this->pdo))->demander($type, [], array_map(
            static fn (string $c): array => ['cible' => $c, 'libelle' => 'Étudiant ' . $c], $cibles), $this->compte($auteur), '192.0.2.1');
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
        $compte = $this->compte($identifiant);
        $session = new Session(new SessionMemoire(), $this->horloge);
        $session->connecter($compte->id, $compte->generation);
        if ($methode === 'POST') {
            $champs['jeton'] = $session->jeton();
        }

        return (new Application($this->pdo, $this->chiffrement, $this->horloge, $session, self::URL, $this->alertes))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null));
    }
}
