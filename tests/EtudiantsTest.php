<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Comptes\DepotComptes;
use Portail\Etudiants\GestionEtudiants;
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

/**
 * Lot 4 : régimes, sessions et fiches des étudiants (noms inventés).
 */
final class EtudiantsTest extends TestCase
{
    private const URL = 'https://portail.bts-sup-sallanches.com';

    private DossierTemporaire $dossier;
    private PDO $pdo;
    private HorlogeFixe $horloge;
    private Chiffrement $chiffrement;
    private int $fi;
    private int $alt;
    private int $s2027;
    private int $s2028;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
        $this->horloge = new HorlogeFixe(1_794_000_000); // 06/11/2026
        $this->chiffrement = new Chiffrement(random_bytes(32));
        $depot = new DepotComptes($this->pdo);
        foreach (['mzanelli' => 'administrateur', 'cpetit' => 'enseignant', 'jroux' => 'administratif', 'pmoreau' => 'consultation'] as $id => $role) {
            $depot->creer($id, ucfirst($id), $id . '@exemple.fr', $role, MotsDePasse::hacher('un mot de passe solide'));
        }
        $this->pdo->exec('UPDATE comptes SET doit_changer_mdp = 0');
        $referentiel = new RegimesSessions($this->pdo);
        $this->fi = $referentiel->creerRegime('NDRC FI');
        $this->alt = $referentiel->creerRegime('NDRC ALT');
        $this->s2027 = $referentiel->creerSession('2027');
        $this->s2028 = $referentiel->creerSession('2028');
    }

    // ===== Régimes et sessions =====

    public function testEnseignantAdministratifEtAdministrateurCreentRegimesEtSessions(): void
    {
        foreach (['mzanelli' => '2029', 'cpetit' => '2030', 'jroux' => '2031'] as $qui => $annee) {
            $page = $this->en($qui, 'POST', '/regimes-sessions', ['action' => 'creer_session', 'annee' => $annee]);
            self::assertStringContainsString('Session créée', $page->corps, $qui);
        }
        self::assertSame(403, $this->en('pmoreau', 'POST', '/regimes-sessions', ['action' => 'creer_session', 'annee' => '2032'])->statut);
        self::assertSame(403, $this->en('pmoreau', 'GET', '/regimes-sessions')->statut);
        self::assertSame(0, $this->nombre('SELECT count(*) FROM sessions WHERE annee = 2032'));
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE action = 'création de session' AND cible = 'session 2031' AND compte_nom = 'Jroux'"));
    }

    public function testUnRegimeEnDoubleOuMalEcritEstRefuse(): void
    {
        $page = $this->en('cpetit', 'POST', '/regimes-sessions', ['action' => 'creer_regime', 'nom' => '  ndrc   fi ']);
        self::assertStringContainsString('existe déjà', $page->corps);
        $page = $this->en('cpetit', 'POST', '/regimes-sessions', ['action' => 'creer_regime', 'nom' => 'NDRC-FI']);
        self::assertStringContainsString('invalide', $page->corps);
        $page = $this->en('cpetit', 'POST', '/regimes-sessions', ['action' => 'creer_session', 'annee' => '28']);
        self::assertStringContainsString('invalide', $page->corps);
        self::assertSame(2, $this->nombre('SELECT count(*) FROM regimes'));
        // Règle de l'import : majuscules et espaces en trop ignorés, tiret refusé.
        $referentiel = new RegimesSessions($this->pdo);
        self::assertSame($this->fi, $referentiel->idRegime(' Ndrc  fi '));
        self::assertNull($referentiel->idRegime('NDRC-FI'));
    }

    public function testVersionWordPressGeleeEtArchivage(): void
    {
        $this->en('cpetit', 'POST', '/regimes-sessions', ['action' => 'version', 'id' => (string) $this->s2028, 'version' => '6.8.3']);
        self::assertSame('6.8.3', $this->texte('SELECT version_wp_gelee FROM sessions WHERE id = ' . $this->s2028));
        $page = $this->en('cpetit', 'POST', '/regimes-sessions', ['action' => 'version', 'id' => (string) $this->s2028, 'version' => '6.8; DROP']);
        self::assertStringContainsString('Version WordPress invalide', $page->corps);
        $this->en('mzanelli', 'POST', '/regimes-sessions', ['action' => 'archiver_session', 'id' => (string) $this->s2027]);
        self::assertSame(0, $this->nombre('SELECT active FROM sessions WHERE id = ' . $this->s2027));
    }

    public function testAnneeDeFormationCalculeeDepuisLaSession(): void
    {
        $novembre2026 = 1_794_000_000;
        self::assertSame('2e année', RegimesSessions::anneeDeFormation(2027, $novembre2026));
        self::assertSame('1re année', RegimesSessions::anneeDeFormation(2028, $novembre2026));
        self::assertSame('cursus terminé', RegimesSessions::anneeDeFormation(2026, $novembre2026));
        self::assertSame('à venir', RegimesSessions::anneeDeFormation(2029, $novembre2026));
        $fin_aout_2027 = (int) gmmktime(12, 0, 0, 8, 31, 2027);
        self::assertSame('2e année', RegimesSessions::anneeDeFormation(2027, $fin_aout_2027), 'Jusqu\'au 31 août');
        self::assertSame('2e année', RegimesSessions::anneeDeFormation(2028, $fin_aout_2027 + 86400), 'Dès le 1er septembre');
    }

    // ===== Ajout manuel =====

    public function testAjoutManuelAvecVerificationDeLIdentifiant(): void
    {
        $champs = ['nom' => 'de la Fontaine', 'prenom' => 'Jean', 'email' => 'jean.dlf@exemple.fr',
            'regime' => (string) $this->alt, 'session' => (string) $this->s2028];
        $apercu = $this->en('jroux', 'POST', '/etudiants/nouveau', $champs + ['action' => 'apercu']);
        self::assertStringContainsString('value="delafontainje"', $apercu->corps);
        self::assertStringContainsString('DelafontainjE', $apercu->corps);
        self::assertStringContainsString('DE LA FONTAINE Jean', $apercu->corps);
        self::assertSame(0, $this->nombre('SELECT count(*) FROM etudiants'), 'L\'aperçu n\'enregistre rien');

        $fin = $this->en('jroux', 'POST', '/etudiants/nouveau', $champs + ['action' => 'enregistrer', 'identifiant' => 'delafontainje']);
        self::assertSame(303, $fin->statut);
        self::assertSame('DE LA FONTAINE Jean', $this->texte("SELECT nom_complet FROM etudiants WHERE identifiant = 'delafontainje'"));
        self::assertSame('saisie', $this->texte("SELECT origine FROM etudiants WHERE identifiant = 'delafontainje'"));
        self::assertSame(1, $this->nombre("SELECT count(*) FROM journal WHERE action = 'ajout d''étudiant' AND detail LIKE '%NDRC ALT – session 2028'"));
    }

    public function testUnHomonymeRecoitLeChiffreSuivant(): void
    {
        $this->ajouter('HENRI GAULTIER', 'Jean-Marc', 'henrije');
        $apercu = $this->en('cpetit', 'POST', '/etudiants/nouveau', ['action' => 'apercu', 'nom' => 'Henri', 'prenom' => 'Jean-Marc',
            'email' => 'jm@exemple.fr', 'regime' => (string) $this->fi, 'session' => (string) $this->s2028]);
        self::assertStringContainsString('value="henrije2"', $apercu->corps);
    }

    public function testUnIdentifiantPrisProtegeOuMalFormeEstRefuse(): void
    {
        $this->ajouter('MARTIN', 'Élodie', 'martinel');
        $this->pdo->exec("INSERT INTO releve_sites (identifiant, dossier, releve_le) VALUES ('ancienb', '/WP', '2026-11-01T00:00:00Z')");
        $gestion = new GestionEtudiants($this->pdo);
        foreach (['martinel' => 'déjà pris', 'ancienb' => 'déjà pris', 'peda' => 'déjà pris', 'Martinel' => 'invalide', 'martin el' => 'invalide'] as $identifiant => $message) {
            try {
                $gestion->ajouter('X', 'Y', 'x@exemple.fr', $this->fi, $this->s2028, $identifiant);
                self::fail($identifiant . ' aurait dû être refusé');
            } catch (ErreurSaisie $e) {
                self::assertStringContainsString($message, $e->getMessage(), $identifiant);
            }
        }
        self::assertSame(1, $this->nombre('SELECT count(*) FROM etudiants'));
    }

    public function testOnNePlacePasUnEtudiantDansUneSessionArchivee(): void
    {
        (new RegimesSessions($this->pdo))->changerSessionActive($this->s2027, false);
        $this->expectException(ErreurSaisie::class);
        (new GestionEtudiants($this->pdo))->ajouter('X', 'Yves', 'x@exemple.fr', $this->fi, $this->s2027, 'xy');
    }

    public function testLaConsultationNePeutNiAjouterNiModifier(): void
    {
        $id = $this->ajouter('MARTIN', 'Élodie', 'martinel');
        self::assertSame(200, $this->en('pmoreau', 'GET', '/etudiants')->statut);
        self::assertSame(403, $this->en('pmoreau', 'GET', '/etudiants/' . $id)->statut, 'La direction ne voit pas les fiches');
        self::assertStringNotContainsString('href="/etudiants/' . $id . '"', $this->en('pmoreau', 'GET', '/etudiants')->corps);
        self::assertStringContainsString('href="/etudiants/' . $id . '"', $this->en('jroux', 'GET', '/etudiants')->corps);
        self::assertSame(403, $this->en('pmoreau', 'GET', '/etudiants/nouveau')->statut);
        self::assertSame(403, $this->en('pmoreau', 'POST', '/etudiants/nouveau', ['action' => 'enregistrer', 'nom' => 'A', 'prenom' => 'B',
            'email' => 'a@exemple.fr', 'regime' => (string) $this->fi, 'session' => (string) $this->s2028, 'identifiant' => 'ab'])->statut);
        self::assertSame(403, $this->en('pmoreau', 'POST', '/etudiants/' . $id, ['action' => 'coordonnees', 'nom_complet' => 'PIRATE', 'email' => 'p@exemple.fr'])->statut);
        self::assertSame('MARTIN Élodie', $this->texte('SELECT nom_complet FROM etudiants WHERE id = ' . $id));
    }

    // ===== Parcours =====

    public function testPassageFiAltParLAdministratifEtHistorique(): void
    {
        $id = $this->ajouter('MARTIN', 'Élodie', 'martinel');
        $page = $this->en('jroux', 'POST', '/etudiants/' . $id, ['action' => 'parcours', 'regime' => (string) $this->alt, 'session' => (string) $this->s2028]);
        self::assertStringContainsString('Les sites n&apos;ont pas été modifiés', $page->corps);
        self::assertSame($this->alt, $this->nombre('SELECT regime_id FROM etudiants WHERE id = ' . $id));
        self::assertSame("NDRC FI|NDRC ALT|Jroux", $this->texte("SELECT ancienne_valeur || '|' || nouvelle_valeur || '|' || auteur_nom FROM historique_etudiants WHERE champ = 'regime'"));
        self::assertStringContainsString('NDRC FI → NDRC ALT', $this->en('cpetit', 'GET', '/etudiants/' . $id)->corps);
    }

    public function testEnseignantAdministratifEtAdministrateurChangentLaSession(): void
    {
        $id = $this->ajouter('MARTIN', 'Élodie', 'martinel', $this->s2027);
        $refus = $this->en('pmoreau', 'POST', '/etudiants/' . $id, ['action' => 'parcours', 'regime' => (string) $this->fi, 'session' => (string) $this->s2028]);
        self::assertSame(403, $refus->statut, 'Formulaire forgé par la consultation');
        self::assertSame($this->s2027, $this->nombre('SELECT session_id FROM etudiants WHERE id = ' . $id));
        self::assertStringContainsString('<select id="session" name="session">', $this->en('jroux', 'GET', '/etudiants/' . $id)->corps);

        // Redoublant qui change aussi de régime (cas 7) : une seule opération, par l'administratif.
        $this->en('jroux', 'POST', '/etudiants/' . $id, ['action' => 'parcours', 'regime' => (string) $this->alt, 'session' => (string) $this->s2028]);
        self::assertSame(2, $this->nombre('SELECT count(*) FROM historique_etudiants'));
        self::assertSame('2027 → 2028 par Jroux', $this->texte("SELECT ancienne_valeur || ' → ' || nouvelle_valeur || ' par ' || auteur_nom FROM historique_etudiants WHERE champ = 'session'"));
        $this->en('cpetit', 'POST', '/etudiants/' . $id, ['action' => 'parcours', 'regime' => (string) $this->alt, 'session' => (string) $this->s2027]);
        self::assertSame($this->s2027, $this->nombre('SELECT session_id FROM etudiants WHERE id = ' . $id), 'Et par l\'enseignant');
        self::assertSame('martinel', $this->texte('SELECT identifiant FROM etudiants WHERE id = ' . $id), 'L\'identifiant ne change jamais');
    }

    public function testCorrectionDuNomEtDeLEmail(): void
    {
        $id = $this->ajouter('MARTIN', 'Élodie', 'martinel');
        $this->en('jroux', 'POST', '/etudiants/' . $id, ['action' => 'coordonnees', 'nom_complet' => 'MARTIN Élodie', 'email' => 'nouvelle@exemple.fr']);
        self::assertSame(1, $this->nombre("SELECT count(*) FROM historique_etudiants WHERE champ = 'email'"));
        self::assertSame(0, $this->nombre("SELECT count(*) FROM historique_etudiants WHERE champ = 'nom_complet'"), 'Nom inchangé : rien d\'inscrit');
        $page = $this->en('jroux', 'POST', '/etudiants/' . $id, ['action' => 'coordonnees', 'nom_complet' => 'MARTIN Élodie', 'email' => 'pas-une-adresse']);
        self::assertStringContainsString('Adresse e-mail invalide', $page->corps);
        self::assertSame('nouvelle@exemple.fr', $this->texte('SELECT email FROM etudiants WHERE id = ' . $id));
    }

    // ===== Liste =====

    public function testLeFiltreEstGardePourLaConnexionSuivante(): void
    {
        $this->ajouter('MARTIN', 'Élodie', 'martinel');
        $this->ajouter('LE', 'Minh', 'lemi', $this->s2027);
        $this->en('cpetit', 'POST', '/etudiants', ['action' => 'filtrer', 'regime' => '', 'session' => (string) $this->s2027]);
        $liste = $this->en('cpetit', 'GET', '/etudiants')->corps;
        self::assertStringContainsString('LE Minh', $liste);
        self::assertStringNotContainsString('MARTIN Élodie', $liste);
        self::assertStringContainsString('2e année', $liste);
        self::assertStringNotContainsString('lemi', $liste, 'L\'identifiant n\'apparaît que dans la fiche');
        self::assertStringContainsString('MARTIN Élodie', $this->en('jroux', 'GET', '/etudiants')->corps, 'Filtre propre à chaque compte');
    }

    public function testUnNomPiegeEstAfficheSansEtreExecute(): void
    {
        $id = (new GestionEtudiants($this->pdo))->ajouter('<script>alert(1)</script>', 'Eve', 'eve@exemple.fr', $this->fi, $this->s2028, 'scripev');
        $fiche = $this->en('cpetit', 'GET', '/etudiants/' . $id)->corps;
        self::assertStringNotContainsString('<script>', $fiche);
        self::assertStringContainsString('&lt;SCRIPT&gt;', $fiche);
        self::assertStringNotContainsString('<script>', $this->en('pmoreau', 'GET', '/etudiants')->corps);
    }

    // ===== Aides =====

    private function ajouter(string $nom, string $prenom, string $identifiant, ?int $session = null): int
    {
        return (new GestionEtudiants($this->pdo))->ajouter($nom, $prenom, $identifiant . '@exemple.fr', $this->fi, $session ?? $this->s2028, $identifiant);
    }

    /** @param array<string, string> $champs */
    private function en(string $identifiant, string $methode, string $chemin, array $champs = []): Reponse
    {
        $navigateur = new SessionMemoire();
        $compte = (new DepotComptes($this->pdo))->parIdentifiant($identifiant);
        self::assertNotNull($compte);
        $session = new Session($navigateur, $this->horloge);
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

    private function texte(string $sql): string
    {
        return (string) $this->pdo->query($sql)?->fetchColumn();
    }
}
