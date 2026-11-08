<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Comptes\DepotComptes;
use Portail\Etudiants\GestionEtudiants;
use Portail\Etudiants\ImportEtudiants;
use Portail\Etudiants\RegimesSessions;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Sites\Releve;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;

/**
 * Import des étudiants et relevé des sous-domaines (décisions du 08/11/2026). Noms inventés.
 */
final class ImportEtudiantsTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';
    private const TITRES = "NomPrénom\tClasse\tSession\tIdentifiant\tIdentifiant WordPress\tE-mail";

    private DossierTemporaire $dossier;
    private PDO $pdo;
    private HorlogeFixe $horloge;
    private Chiffrement $chiffrement;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->horloge = new HorlogeFixe(1_794_000_000);
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'jroux' => 'administratif', 'adirection' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('x'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        $referentiel = new RegimesSessions($this->pdo);
        $referentiel->creerRegime('NDRC FI');
        $referentiel->creerRegime('NDRC ALT');
        $referentiel->creerSession('2027');
        $referentiel->creerSession('2028');
    }

    // ===== Analyse =====

    public function testUnNouvelEtudiantConformeEstVertEtImporte(): void
    {
        $texte = self::texte(['MARTIN Élodie', 'NDRC FI', '2028', 'martinel', 'MartineL', 'elodie@exemple.fr']);
        $lignes = $this->import()->analyser($texte);
        self::assertSame('vert', $lignes[0]['statut']);
        self::assertSame(0, $this->nombre('SELECT count(*) FROM etudiants'), 'L\'analyse n\'enregistre rien');

        $bilan = $this->import()->importer($texte, []);
        self::assertSame(1, $bilan['importes']);
        self::assertSame('import', $this->texteSql("SELECT origine FROM etudiants WHERE identifiant = 'martinel'"));
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE action = 'import d''étudiants'"));
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE action = 'ajout d''étudiant' AND cible = 'martinel'"));
    }

    public function testUnTexteAvecUneColonneDeMotDePasseEstRefuseEnEntier(): void
    {
        foreach (['Mot de passe', 'Date de naissance', 'Code chiffré', 'MDP WordPress'] as $colonne) {
            try {
                $this->import()->analyser(self::TITRES . "\t" . $colonne . "\nMARTIN Élodie\tNDRC FI\t2028\tmartinel\tMartineL\te@exemple.fr\tsecret");
                self::fail($colonne . ' aurait dû faire refuser le texte');
            } catch (ErreurSaisie $e) {
                self::assertStringContainsString('refusé en entier', $e->getMessage(), $colonne);
            }
        }
    }

    public function testDesColonnesManquantesSontSignalees(): void
    {
        $this->expectExceptionMessage('Colonnes manquantes : Identifiant WordPress');
        $this->import()->analyser("NomPrénom\tClasse\tSession\tIdentifiant\tE-mail\nMARTIN Élodie\tNDRC FI\t2028\tmartinel\te@exemple.fr");
    }

    public function testLesLignesInvalidesSontRougesAvecLaRaison(): void
    {
        $lignes = $this->import()->analyser(self::texte(
            ['MARTIN Élodie', 'NDRC-FI', '2028', 'martinel', 'MartineL', 'e@exemple.fr'],
            ['LE Minh', 'NDRC FI', '2029', 'lemi', 'LemI', 'm@exemple.fr'],
            ['DUPONT Léa', 'NDRC FI', '2028', 'dupontle', 'DupontlE', 'pas-une-adresse'],
            ['', 'NDRC FI', '2028', 'xx', 'XX', 'x@exemple.fr'],
            ['PEDA Anne', 'NDRC FI', '2028', 'peda', 'PedA', 'p@exemple.fr'],
            ['DE LA FONTAINE Jean', 'NDRC FI', '2028', 'de-la', 'DelA', 'j@exemple.fr'],
        ));
        $raisons = array_map(static fn (array $l): string => $l['statut'] . ' : ' . implode(' ', $l['raisons']), $lignes);
        self::assertStringContainsString('tiret à la place d\'une espace', $raisons[0]);
        self::assertStringContainsString('Session « 2029 » inexistante', $raisons[1]);
        self::assertStringContainsString('E-mail mal formé', $raisons[2]);
        self::assertStringContainsString('Champ vide : nom complet', $raisons[3]);
        self::assertStringContainsString('site protégé', $raisons[4]);
        self::assertStringContainsString('invalide pour un sous-domaine', $raisons[5]);
        foreach ($lignes as $ligne) {
            self::assertSame('rouge', $ligne['statut']);
        }
    }

    public function testUnDoublonDansLeTexteEstRefuseAuxDeuxEndroits(): void
    {
        $lignes = $this->import()->analyser(self::texte(
            ['MARTIN Élodie', 'NDRC FI', '2028', 'martinel', 'MartineL', 'e@exemple.fr'],
            ['MARTIN Élodie', 'NDRC ALT', '2028', 'martinel', 'MartineL', 'e@exemple.fr'],
        ));
        self::assertSame(['rouge', 'rouge'], array_column($lignes, 'statut'));
    }

    public function testUnEtudiantDejaPresentEstGrisEtJamaisModifie(): void
    {
        $id = (new GestionEtudiants($this->pdo))->ajouter('MARTIN', 'Élodie', 'ancienne@exemple.fr', $this->regime('NDRC FI'), $this->session(2028), 'martinel');
        $texte = self::texte(['martin  élodie', 'NDRC ALT', '2028', 'martinel', 'MartineL', 'nouvelle@exemple.fr']);
        $ligne = $this->import()->analyser($texte)[0];
        self::assertSame('gris', $ligne['statut']);
        self::assertSame($id, $ligne['existantId']);
        self::assertStringContainsString(ImportEtudiants::MESSAGE_EXISTANT, $ligne['notes'][0]);
        self::assertStringContainsString('Différence avec la fiche : e-mail, classe', $ligne['notes'][0]);

        $this->import()->importer($texte, [2]);
        self::assertSame('ancienne@exemple.fr', $this->texteSql('SELECT email FROM etudiants WHERE id = ' . $id));
        self::assertSame(1, $this->nombre('SELECT count(*) FROM etudiants'));
    }

    public function testUnIdentifiantDejaPrisParUnAutreEtudiantEstRefuse(): void
    {
        (new GestionEtudiants($this->pdo))->ajouter('MARTIN', 'Élodie', 'e@exemple.fr', $this->regime('NDRC FI'), $this->session(2028), 'martinel');
        $ligne = $this->import()->analyser(self::texte(['MARTINEL Lucie', 'NDRC FI', '2028', 'martinel', 'MartineL', 'l@exemple.fr']))[0];
        self::assertSame('rouge', $ligne['statut']);
        self::assertStringContainsString('déjà pris par un autre étudiant (MARTIN Élodie)', $ligne['raisons'][0]);
    }

    public function testUnIdentifiantWordPressInattenduEstOrangeEtDemandeUneCoche(): void
    {
        $texte = self::texte(['MARTIN Élodie', 'NDRC FI', '2028', 'martinel', 'Martinel', 'e@exemple.fr']);
        $ligne = $this->import()->analyser($texte)[0];
        self::assertSame('orange', $ligne['statut']);
        self::assertStringContainsString('« MartineL »', $ligne['raisons'][0]);

        self::assertSame(1, $this->import()->importer($texte, [])['non_coches']);
        self::assertSame(0, $this->nombre('SELECT count(*) FROM etudiants'), 'Non cochée : non importée');
        self::assertSame(1, $this->import()->importer($texte, [2])['importes']);
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE detail LIKE '%(vérifié : Identifiant WordPress différent%'"));
    }

    public function testUneCocheSurUneLigneRefuseeNImportePasCetteLigne(): void
    {
        $texte = self::texte(['MARTIN Élodie', 'NDRC FI', '2029', 'martinel', 'MartineL', 'e@exemple.fr']);
        $bilan = $this->import()->importer($texte, [2]);
        self::assertSame(['importes' => 0, 'presents' => 0, 'refuses' => 1, 'non_coches' => 0], $bilan);
    }

    public function testUnIdentifiantHorsRegleExigeSonSousDomaineSurLeServeur(): void
    {
        // Étudiant de la session 2027 : ancien format, hors de la règle actuelle.
        $texte = self::texte(['MARTIN Alice', 'NDRC FI', '2027', 'alicm', 'AlicM', 'a@exemple.fr']);
        $sansReleve = $this->import()->analyser($texte)[0];
        self::assertSame('rouge', $sansReleve['statut']);
        self::assertStringContainsString('aucun relevé', $sansReleve['raisons'][0]);
        self::assertStringContainsString('la règle de la feuille donne « martinal »', $sansReleve['raisons'][0]);

        Releve::enregistrer($this->pdo, ['autrex' => '/home/x/autrex'], [], '2026-11-08T10:00:00Z');
        $absent = $this->import()->analyser($texte)[0];
        self::assertSame('rouge', $absent['statut']);
        self::assertStringContainsString('aucun sous-domaine à ce nom', $absent['raisons'][0]);

        Releve::enregistrer($this->pdo, ['alicm' => '/home/x/alicm'], ['alicm' => [['dossier' => '/WP', 'logiciel' => 'wordpress', 'version' => '6.4.2']]], '2026-11-08T11:00:00Z');
        $trouve = $this->import()->analyser($texte)[0];
        self::assertSame('vert', $trouve['statut']);
        self::assertStringContainsString('sous-domaine trouvé sur le serveur ✓', $trouve['notes'][0]);
        self::assertSame(1, $this->import()->importer($texte, [])['importes']);
    }

    public function testLesSeparateursEtLesGuillemetsDuCsvSontCompris(): void
    {
        $pointVirgule = "\u{FEFF}NomPrénom;Classe;Session;Identifiant;Identifiant WordPress;E-mail\r\n\"DE LA FONTAINE Jean\";NDRC FI;2028;delafontainje;DelafontainjE;j@exemple.fr\r\n";
        $virgule = "NomPrénom,Classe,Session,Identifiant,Identifiant WordPress,E-mail\n\"MARTIN, Élodie\",NDRC FI,2028,martinel,MartineL,e@exemple.fr\n";
        self::assertSame('vert', $this->import()->analyser($pointVirgule)[0]['statut']);
        $ligne = $this->import()->analyser($virgule)[0];
        self::assertSame('MARTIN, Élodie', $ligne['nom']);
        self::assertSame('martinel', $ligne['identifiant']);
    }

    // ===== Pages =====

    public function testLaDirectionNePeutPasImporter(): void
    {
        self::assertSame(403, $this->en('adirection', 'GET', '/etudiants/import')->statut);
        self::assertSame(403, $this->en('adirection', 'POST', '/etudiants/import', ['action' => 'importer',
            'texte' => self::texte(['MARTIN Élodie', 'NDRC FI', '2028', 'martinel', 'MartineL', 'e@exemple.fr'])])->statut);
        self::assertSame(0, $this->nombre('SELECT count(*) FROM etudiants'));
    }

    public function testApercuPuisImportParLAdministratif(): void
    {
        $texte = self::texte(
            ['MARTIN Élodie', 'NDRC FI', '2028', 'martinel', 'MartineL', 'e@exemple.fr'],
            ['<script>alert(1)</script> Eve', 'NDRC FI', '2028', 'scripev', 'ScripeV', 'eve@exemple.fr'],
        );
        $apercu = $this->en('jroux', 'POST', '/etudiants/import', ['action' => 'apercu', 'texte' => $texte]);
        self::assertStringContainsString('Rien n\'est encore enregistré', $apercu->corps);
        self::assertStringNotContainsString('<script>', $apercu->corps);
        self::assertStringContainsString('Annuler : quitter sans rien enregistrer', $apercu->corps);
        self::assertSame(0, $this->nombre('SELECT count(*) FROM etudiants'));

        $fin = $this->en('jroux', 'POST', '/etudiants/import', ['action' => 'importer', 'texte' => $texte]);
        self::assertStringContainsString('1 étudiant(s) importé(s)', $fin->corps, 'La ligne au nom piégé est hors règle et sans sous-domaine');
        self::assertSame(1, $this->nombre("SELECT count(*) FROM etudiants WHERE ajoute_par = (SELECT id FROM comptes WHERE identifiant = 'jroux')"));
    }

    // ===== Relevé =====

    public function testLeReleveLitLaReponseDeCpanelEtNeGardeQueLeDomaineDesEtudiants(): void
    {
        $json = json_encode(['result' => ['status' => 1, 'data' => ['sub_domains' => [
            ['domain' => 'alicm.bts-sup-sallanches.com', 'documentroot' => '/home/jafl3067/alicm.bts-sup-sallanches.com'],
            ['domain' => 'Martinel.bts-sup-sallanches.com', 'documentroot' => '/home/jafl3067/martinel.bts-sup-sallanches.com'],
            ['domain' => 'portail.bts-sup-sallanches.com', 'documentroot' => '/home/jafl3067/portail/public'],
            ['domain' => 'shop.supsallanches.com', 'documentroot' => '/home/jafl3067/shop'],
            ['domain' => 'a-b.bts-sup-sallanches.com', 'documentroot' => '/home/jafl3067/a-b'],
        ]]]], JSON_THROW_ON_ERROR);
        self::assertSame(['alicm', 'martinel', 'portail'], array_keys(Releve::sousDomaines($json)));

        $this->expectException(ErreurSaisie::class);
        Releve::sousDomaines('{"result":{"status":0,"errors":["Accès refusé"]}}');
    }

    public function testLeReleveRepereLesSitesWordPressEtPrestaShop(): void
    {
        $racine = $this->dossier->racine . '/alicm';
        mkdir($racine . '/WP/wp-includes', 0700, true);
        file_put_contents($racine . '/WP/wp-config.php', '<?php');
        file_put_contents($racine . '/WP/wp-includes/version.php', "<?php\n\$wp_version = '6.8.3';\n");
        mkdir($racine . '/Presta/app/config', 0700, true);
        file_put_contents($racine . '/Presta/app/config/parameters.php', '<?php');
        mkdir($racine . '/vide', 0700);
        mkdir($racine . '/WP-Sujet1', 0700);
        file_put_contents($racine . '/WP-Sujet1/wp-config.php', '<?php');

        $sites = Releve::sites($racine);
        usort($sites, static fn (array $a, array $b): int => strcmp($a['dossier'], $b['dossier']));
        self::assertSame([
            ['dossier' => '/Presta', 'logiciel' => 'prestashop', 'version' => null, 'module_mise_a_jour' => false],
            ['dossier' => '/WP', 'logiciel' => 'wordpress', 'version' => '6.8.3'],
        ], $sites);

        Releve::enregistrer($this->pdo, ['alicm' => $racine], ['alicm' => $sites], '2026-11-08T10:00:00Z');
        self::assertSame(2, $this->nombre('SELECT count(*) FROM releve_sites'));
        Releve::enregistrer($this->pdo, ['benm' => '/x'], [], '2026-11-08T11:00:00Z');
        self::assertSame(0, $this->nombre('SELECT count(*) FROM releve_sites'), 'Le relevé remplace le précédent');
        self::assertSame('benm', $this->texteSql('SELECT group_concat(identifiant) FROM sous_domaines'));
        self::assertTrue((new GestionEtudiants($this->pdo))->identifiantPris('benm'), 'Un sous-domaine existant n\'est pas proposé à un nouvel étudiant');
    }

    // ===== Aides =====

    /** @param list<string> ...$lignes */
    private static function texte(array ...$lignes): string
    {
        return self::TITRES . "\n" . implode("\n", array_map(static fn (array $l): string => implode("\t", $l), $lignes)) . "\n";
    }

    private function import(): ImportEtudiants
    {
        return new ImportEtudiants($this->pdo);
    }

    private function regime(string $nom): int
    {
        return (int) (new RegimesSessions($this->pdo))->idRegime($nom);
    }

    private function session(int $annee): int
    {
        return (int) (new RegimesSessions($this->pdo))->idSession($annee);
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

        return (new Application($this->pdo, $this->chiffrement, $this->horloge, $session, self::URL))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null));
    }

    private function nombre(string $sql): int
    {
        return (int) $this->pdo->query($sql)?->fetchColumn();
    }

    private function texteSql(string $sql): string
    {
        return (string) $this->pdo->query($sql)?->fetchColumn();
    }
}
