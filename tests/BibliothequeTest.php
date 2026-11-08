<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Installation;
use Portail\Base\Schema;
use Portail\Bibliotheque\Bibliotheque;
use Portail\Comptes\Compte;
use Portail\Comptes\DepotComptes;
use Portail\Etudiants\RegimesSessions;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Tests\Aides\DossierTemporaire;
use Portail\Tests\Aides\HorlogeFixe;
use Portail\Tests\Aides\SessionMemoire;
use Portail\Web\Application;
use Portail\Web\Reponse;
use Portail\Web\Requete;
use Portail\Web\Session;
use ZipArchive;

/**
 * V2, lot 2 : bibliothèque (paquets PrestaShop, sujets WordPress) et documents PDF.
 */
final class BibliothequeTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    private DossierTemporaire $dossier;
    private string $donnees;
    private PDO $pdo;
    private HorlogeFixe $horloge;
    private Chiffrement $chiffrement;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->donnees = $this->dossier->racine . '/data';
        foreach (Installation::DOSSIERS_PRIVES as $relatif) {
            @mkdir($this->dossier->racine . '/' . $relatif, 0700, true);
        }
        $this->pdo = Connexion::ouvrir($this->donnees . '/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->horloge = new HorlogeFixe(1_794_000_000);
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'lbernard' => 'enseignant', 'jroux' => 'administratif', 'pmoreau' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
    }

    // ===== Paquets PrestaShop =====

    public function testUnPaquetPrestaShopEstRangeSousSonEmpreinte(): void
    {
        $zip = $this->zip('prestashop_9.1.5.zip', ['index.php' => '<?php', 'prestashop.zip' => 'interne', 'Install_PrestaShop.html' => 'x']);
        $empreinte = (string) hash_file('sha256', $zip);
        $id = $this->bibliotheque('cpetit')->ajouterPaquet($zip, 'prestashop_9.1.5.zip', 'prestashop', 'PrestaShop', '9.1.5', false);

        self::assertFileExists($this->donnees . '/bibliotheque/' . $empreinte . '.zip');
        self::assertFileDoesNotExist($zip, 'Le fichier quitte le dossier de dépôt');
        self::assertSame(0600, fileperms($this->donnees . '/bibliotheque/' . $empreinte . '.zip') & 0777);
        self::assertSame($this->donnees . '/bibliotheque/' . $empreinte . '.zip', $this->bibliotheque('cpetit')->cheminVerifie($id));
        $paquets = $this->bibliotheque('cpetit')->paquets();
        self::assertSame('9.1.5', $paquets[0]['version']);
    }

    public function testUnPaquetModifieApresDepotNEstPlusUtilise(): void
    {
        $zip = $this->zip('ps.zip', ['index.php' => '<?php', 'prestashop.zip' => 'interne']);
        $id = $this->bibliotheque('cpetit')->ajouterPaquet($zip, 'ps.zip', 'prestashop', 'PrestaShop', '9.1.5', false);
        $empreinte = $this->pdo->query('SELECT empreinte FROM paquets')?->fetchColumn();
        file_put_contents($this->donnees . '/bibliotheque/' . $empreinte . '.zip', 'modifié', FILE_APPEND);

        $this->expectException(ErreurSaisie::class);
        $this->expectExceptionMessage('modifié depuis son dépôt');
        $this->bibliotheque('cpetit')->cheminVerifie($id);
    }

    /** @return array<string, array{array<string, string>, string, string, string}> */
    public static function paquetsRefuses(): array
    {
        return [
            'pas PrestaShop' => [['lisezmoi.txt' => 'x'], 'ps.zip', '9.1.5', 'pas un paquet d\'installation PrestaShop'],
            'version absente' => [['index.php' => '<?php', 'prestashop.zip' => 'x'], 'ps.zip', '', 'numéro de version'],
            'nom contradictoire' => [['index.php' => '<?php', 'prestashop.zip' => 'x'], 'prestashop_9.1.4.zip', '9.1.5', 'indique la version 9.1.4'],
            'zip slip' => [['../../.bashrc' => 'pirate', 'index.php' => '<?php', 'prestashop.zip' => 'x'], 'ps.zip', '9.1.5', 'sortirait du dossier'],
            'chemin absolu' => [['/etc/passwd' => 'pirate', 'install/index_cli.php' => '<?php'], 'ps.zip', '9.1.5', 'sortirait du dossier'],
        ];
    }

    /**
     * @dataProvider paquetsRefuses
     * @param array<string, string> $contenu
     */
    public function testUnPaquetDouteuxEstRefuse(array $contenu, string $nomFichier, string $version, string $message): void
    {
        $zip = $this->zip('depose.zip', $contenu);
        try {
            $this->bibliotheque('cpetit')->ajouterPaquet($zip, $nomFichier, 'prestashop', 'PrestaShop', $version, false);
            self::fail('Paquet accepté à tort');
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM paquets')?->fetchColumn());
        self::assertSame(['.', '..'], scandir($this->donnees . '/bibliotheque'));
    }

    public function testUneVersionPrestaShopNeSeDeposeQuUneFois(): void
    {
        $this->bibliotheque('cpetit')->ajouterPaquet($this->zip('a.zip', ['install/index_cli.php' => '<?php']), 'a.zip', 'prestashop', 'PrestaShop', '9.1.5', false);
        $this->expectExceptionMessage('déjà dans la bibliothèque');
        $this->bibliotheque('cpetit')->ajouterPaquet($this->zip('b.zip', ['install/index_cli.php' => '<?php // autre']), 'b.zip', 'prestashop', 'PrestaShop', '9.1.5', false);
    }

    public function testUnPaquetUtiliseParUneSessionNePeutPasEtreRetire(): void
    {
        $id = $this->bibliotheque('cpetit')->ajouterPaquet($this->zip('a.zip', ['install/index_cli.php' => '<?php']), 'a.zip', 'prestashop', 'PrestaShop', '9.1.5', false);
        $session = (new RegimesSessions($this->pdo))->creerSession('2027');
        $this->pdo->prepare('UPDATE sessions SET paquet_prestashop_id = :p WHERE id = :s')->execute([':p' => $id, ':s' => $session]);

        $this->expectExceptionMessage('utilisé par une session');
        $this->bibliotheque('mzanelli')->retirerPaquet($id);
    }

    public function testSeulsLeDeposantEtLAdministrateurRetirent(): void
    {
        $id = $this->bibliotheque('cpetit')->ajouterPaquet($this->zip('a.zip', ['install/index_cli.php' => '<?php']), 'a.zip', 'prestashop', 'PrestaShop', '9.1.5', false);
        try {
            $this->bibliotheque('lbernard')->retirerPaquet($id);
            self::fail('Retrait accepté à tort');
        } catch (ErreurSaisie $e) {
            self::assertStringContainsString('Seuls', $e->getMessage());
        }
        $this->bibliotheque('mzanelli')->retirerPaquet($id);
        self::assertSame([], $this->bibliotheque('cpetit')->paquets());
        self::assertSame(['.', '..'], scandir($this->donnees . '/bibliotheque'), 'Le fichier est effacé');
    }

    // ===== Sujets WordPress (Duplicator) =====

    public function testUnSujetDuplicatorEstReconnuAvecSaVersionWordPress(): void
    {
        $zip = $this->zip('chocosup_archive.zip', [
            'dup-installer/dup-database__a1b2c3-04120000.sql' => 'CREATE TABLE x;',
            'wp-includes/version.php' => "<?php\n\$wp_version = '7.1.4';",
            'wp-config-sample.php' => '<?php',
        ]);
        $this->bibliotheque('cpetit')->ajouterPaquet($zip, 'chocosup_archive.zip', 'sujet_wordpress', 'Chocosup', '', false);
        $paquet = $this->bibliotheque('cpetit')->paquets()[0];
        self::assertSame('7.1.4', $paquet['version_wordpress']);
        self::assertNull($paquet['version']);
    }

    public function testUnZipQuelconqueNEstPasUnSujet(): void
    {
        $this->expectExceptionMessage('pas un paquet Duplicator');
        $this->bibliotheque('cpetit')->ajouterPaquet($this->zip('x.zip', ['wp-includes/version.php' => '<?php']), 'x.zip', 'sujet_wordpress', 'Sujet', '', false);
    }

    public function testLesSujetsPrestaShopArriventAvecLeLot6(): void
    {
        $this->expectExceptionMessage('lot 6');
        $this->bibliotheque('cpetit')->ajouterPaquet($this->zip('x.zip', ['a' => 'b']), 'x.zip', 'sujet_prestashop', 'Sujet', '', false);
    }

    // ===== Documents =====

    public function testSeulsLesPdfSontAcceptes(): void
    {
        $faux = $this->donnees . '/depot/faux.pdf';
        file_put_contents($faux, '<html><script>alert(1)</script>');
        $this->expectExceptionMessage('n\'est pas un PDF');
        $this->bibliotheque('pmoreau')->ajouterDocument($faux, 'Référentiel', false);
    }

    public function testToutLeMondeDeposeEtLitUnDocumentQuiSeTelecharge(): void
    {
        file_put_contents($this->donnees . '/depot/referentiel.pdf', "%PDF-1.7\ncontenu");
        $page = $this->en('pmoreau', 'GET', '/documents');
        self::assertSame(200, $page->statut);
        self::assertStringContainsString('referentiel.pdf', $page->corps);
        $this->en('pmoreau', 'POST', '/documents', ['action' => 'enregistrer_depot', 'fichier_depot' => 'referentiel.pdf', 'titre' => 'Référentiel BTS NDRC']);
        $id = (int) $this->pdo->query('SELECT id FROM documents')?->fetchColumn();
        self::assertGreaterThan(0, $id);

        $telechargement = $this->en('jroux', 'GET', '/documents/' . $id);
        self::assertSame(200, $telechargement->statut);
        self::assertSame("%PDF-1.7\ncontenu", $telechargement->corps);
        self::assertSame('application/pdf', $telechargement->enTetes['Content-Type']);
        self::assertSame('attachment; filename="Referentiel-BTS-NDRC.pdf"', $telechargement->enTetes['Content-Disposition']);
        self::assertSame('sandbox', $telechargement->enTetes['Content-Security-Policy']);

        // Retrait : la personne qui l'a déposé (ou l'administrateur), avec confirmation.
        $refus = $this->en('cpetit', 'POST', '/documents', ['action' => 'retirer', 'id' => (string) $id, 'confirmer' => '1']);
        self::assertStringContainsString('Seuls', $refus->corps);
        $sansConfirmation = $this->en('pmoreau', 'POST', '/documents', ['action' => 'retirer', 'id' => (string) $id]);
        self::assertStringContainsString('définitivement', $sansConfirmation->corps);
        $this->en('pmoreau', 'POST', '/documents', ['action' => 'retirer', 'id' => (string) $id, 'confirmer' => '1']);
        self::assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM documents')?->fetchColumn());
    }

    public function testLeDepotNeLitQueDesNomsSurs(): void
    {
        file_put_contents($this->donnees . '/depot/bon fichier (1).zip', 'x');
        file_put_contents($this->donnees . '/depot/.cache.zip', 'x');
        file_put_contents($this->donnees . '/depot/script.php', 'x');
        symlink('/etc/passwd', $this->donnees . '/depot/lien.pdf');
        $depot = $this->bibliotheque('cpetit')->depot();
        self::assertSame(['bon fichier (1).zip'], array_column($depot['acceptes'], 'nom'));
        self::assertSame(3, $depot['ignores']);

        $this->expectExceptionMessage('plus dans le dossier');
        $this->bibliotheque('cpetit')->cheminDepot('../config.php', 'zip');
    }

    // ===== Droits et pages =====

    public function testLaBibliothequeEstReserveeAuxEnseignantsEtALAdministrateur(): void
    {
        self::assertSame(200, $this->en('cpetit', 'GET', '/bibliotheque')->statut);
        self::assertSame(200, $this->en('mzanelli', 'GET', '/bibliotheque')->statut);
        self::assertSame(403, $this->en('jroux', 'GET', '/bibliotheque')->statut);
        self::assertSame(403, $this->en('pmoreau', 'GET', '/bibliotheque')->statut);
    }

    public function testUnEnvoiTropGrosEstExpliqueEtNonRefuseCommeFormulaireExpire(): void
    {
        $session = new Session(new SessionMemoire(), $this->horloge);
        $reponse = (new Application($this->pdo, $this->chiffrement, $this->horloge, $session, self::URL, null, $this->donnees))
            ->traiter(new Requete('POST', '/bibliotheque', [], '192.0.2.1', self::URL, [], [], true));
        self::assertSame(413, $reponse->statut);
        self::assertStringContainsString('portail/data/depot', $reponse->corps);
    }

    public function testUnFichierAbsentOuInterrompuEstExplique(): void
    {
        $sansFichier = $this->en('cpetit', 'POST', '/bibliotheque', ['action' => 'deposer', 'type' => 'prestashop', 'nom' => 'PS', 'version' => '9.1.5']);
        self::assertStringContainsString('Choisissez un fichier', $sansFichier->corps);
        $trop = $this->en('cpetit', 'POST', '/bibliotheque', ['action' => 'deposer', 'type' => 'prestashop', 'nom' => 'PS', 'version' => '9.1.5'], ['fichier' => UPLOAD_ERR_INI_SIZE]);
        self::assertStringContainsString('dépôt par CPanel', $trop->corps);
    }

    public function testLaVersionSixDeLaBaseRefuseUnTypeInconnu(): void
    {
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO paquets (type, nom, empreinte, taille, depose_par_nom) VALUES ('script', 'x', '" . str_repeat('a', 64) . "', 1, 'x')");
    }

    // ===== Aides =====

    /** @param array<string, string> $contenu */
    private function zip(string $nom, array $contenu): string
    {
        $chemin = $this->donnees . '/depot/' . $nom;
        $zip = new ZipArchive();
        self::assertTrue($zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($contenu as $entree => $texte) {
            $zip->addFromString($entree, $texte);
        }
        $zip->close();

        return $chemin;
    }

    private function bibliotheque(string $identifiant): Bibliotheque
    {
        return new Bibliotheque($this->pdo, $this->donnees, $this->compte($identifiant), '192.0.2.1');
    }

    private function compte(string $identifiant): Compte
    {
        $compte = (new DepotComptes($this->pdo))->parIdentifiant($identifiant);
        self::assertNotNull($compte);

        return $compte;
    }

    /**
     * @param array<string, string> $champs
     * @param array<string, array{chemin: string, nom: string, taille: int}|int> $fichiers
     */
    private function en(string $identifiant, string $methode, string $chemin, array $champs = [], array $fichiers = []): Reponse
    {
        $compte = $this->compte($identifiant);
        $session = new Session(new SessionMemoire(), $this->horloge);
        $session->connecter($compte->id, $compte->generation);
        if ($methode === 'POST') {
            $champs['jeton'] = $session->jeton();
        }

        return (new Application($this->pdo, $this->chiffrement, $this->horloge, $session, self::URL, null, $this->donnees))
            ->traiter(new Requete($methode, $chemin, $champs, '192.0.2.1', $methode === 'POST' ? self::URL : null, [], $fichiers));
    }
}
