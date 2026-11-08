<?php

declare(strict_types=1);

namespace Portail\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Portail\Base\Connexion;
use Portail\Base\Schema;
use Portail\Tests\Aides\DossierTemporaire;

final class SchemaTest extends TestCase
{
    private DossierTemporaire $dossier;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dossier = new DossierTemporaire();
        $this->pdo = Connexion::ouvrir($this->dossier->racine . '/data/portail.sqlite');
        Schema::mettreAJour($this->pdo);
    }

    public function testLesQuatorzeTablesExistent(): void
    {
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name <> 'schema_versions' ORDER BY name")
            ?->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            'comptes', 'documents', 'etudiants', 'historique_etudiants', 'journal', 'paquets', 'parametres',
            'regimes', 'releve_sites', 'sessions', 'sous_domaines', 'tentatives_connexion',
            'travaux', 'travaux_etapes',
        ], $tables, 'v6 (08/11/2026) : bibliothèque et documents');
    }

    public function testLaMiseAJourPeutEtreRelanceeSansEffet(): void
    {
        self::assertSame([], Schema::mettreAJour($this->pdo));
    }

    public function testLeFichierDeLaBaseEstEnDroits600(): void
    {
        self::assertSame(0600, fileperms($this->dossier->racine . '/data/portail.sqlite') & 0777);
    }

    public function testLesClesEtrangeresSontVerifiees(): void
    {
        self::assertSame(1, $this->pdo->query('PRAGMA foreign_keys')?->fetchColumn());
    }

    // ----- Journal : non modifiable, effaçable seulement à échéance -----

    public function testUneLigneDuJournalNePeutPasEtreModifiee(): void
    {
        $this->ecrireJournal('connexion', '2026-11-07T10:00:00Z');
        $this->attendreRefus('trace_non_modifiable', "UPDATE journal SET resultat = 'succes'");
    }

    public function testUneLigneRecenteDuJournalNePeutPasEtreSupprimee(): void
    {
        $this->ecrireJournal('connexion', gmdate('Y-m-d\TH:i:s\Z'));
        $this->attendreRefus('trace_non_supprimable', 'DELETE FROM journal');
    }

    public function testLaPurgeRespecteLesDureesDeConservation(): void
    {
        $ilYa = static fn (string $duree): string => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($duree));
        $this->ecrireJournal('connexion', $ilYa('-13 months'));       // effacée (> 1 an)
        $this->ecrireJournal('administration', $ilYa('-13 months'));  // effacée (> 1 an)
        $this->ecrireJournal('connexion', $ilYa('-11 months'));       // gardée
        $this->ecrireJournal('site', $ilYa('-18 months'));            // gardée (< 2 ans)
        $this->ecrireJournal('site', $ilYa('-25 months'));            // effacée (> 2 ans)

        self::assertSame(3, Schema::purger($this->pdo));
        $restantes = $this->pdo->query('SELECT categorie FROM journal ORDER BY categorie')?->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['connexion', 'site'], $restantes);
    }

    public function testLesTentativesEtLHistoriqueSontAussiProteges(): void
    {
        $this->pdo->exec("INSERT INTO tentatives_connexion (adresse_ip, identifiant_saisi, reussie) VALUES ('192.0.2.1', 'x', 0)");
        $this->attendreRefus('trace_non_modifiable', 'UPDATE tentatives_connexion SET reussie = 1');
        $this->attendreRefus('trace_non_supprimable', 'DELETE FROM tentatives_connexion');

        $this->pdo->exec("INSERT INTO historique_etudiants (etudiant_identifiant, champ, ancienne_valeur, nouvelle_valeur) VALUES ('bernardhu', 'session', '2028', '2029')");
        $this->attendreRefus('trace_non_modifiable', "UPDATE historique_etudiants SET nouvelle_valeur = '2030'");
        $this->attendreRefus('trace_non_supprimable', 'DELETE FROM historique_etudiants');
    }

    // ----- Dernier administrateur -----

    public function testLeDernierAdministrateurNePeutEtreNiDesactiveNiRetrogradeNiSupprime(): void
    {
        $this->ajouterCompte('mzanelli', 'administrateur');
        $this->attendreRefus('dernier_administrateur', "UPDATE comptes SET actif = 0 WHERE identifiant = 'mzanelli'");
        $this->attendreRefus('dernier_administrateur', "UPDATE comptes SET role = 'enseignant' WHERE identifiant = 'mzanelli'");
        $this->attendreRefus('dernier_administrateur', "DELETE FROM comptes WHERE identifiant = 'mzanelli'");
    }

    public function testUnAdministrateurPeutEtreDesactiveSIlEnResteUnAutre(): void
    {
        $this->ajouterCompte('mzanelli', 'administrateur');
        $this->ajouterCompte('sgauthierroy', 'administrateur');
        $this->pdo->exec("UPDATE comptes SET actif = 0 WHERE identifiant = 'sgauthierroy'");
        $this->attendreRefus('dernier_administrateur', "UPDATE comptes SET actif = 0 WHERE identifiant = 'mzanelli'");
    }

    // ----- Contraintes de format -----

    /** @return array<string, array{string}> */
    public function identifiantsDeCompteRefuses(): array
    {
        return ['majuscule' => ['MZanelli'], 'espace' => ['m zanelli'], 'trop court' => ['mz'], 'commence par un chiffre' => ['2mz'], 'injection' => ["x'--"]];
    }

    /** @dataProvider identifiantsDeCompteRefuses */
    public function testUnIdentifiantDeCompteMalFormeEstRefuse(string $identifiant): void
    {
        $this->expectException(PDOException::class);
        $this->ajouterCompte($identifiant, 'enseignant');
    }

    public function testUnRoleInconnuEstRefuse(): void
    {
        $this->expectException(PDOException::class);
        $this->ajouterCompte('cpetit', 'superadmin');
    }

    public function testUnEtudiantRespecteLeFormatDIdentifiantEtSesRattachements(): void
    {
        $this->pdo->exec("INSERT INTO regimes (nom) VALUES ('NDRC FI')");
        $this->pdo->exec('INSERT INTO sessions (annee) VALUES (2028)');
        $ajout = $this->pdo->prepare(
            "INSERT INTO etudiants (nom_complet, identifiant, identifiant_wp, email, regime_id, session_id, origine)
             VALUES ('GAUTHIER ROY Stéphanie', :id, 'GauthiersT', 'etudiante@exemple.fr', :regime, 1, 'import')"
        );
        $ajout->execute([':id' => 'gauthierst', ':regime' => 1]);

        foreach ([['GauthierST', 1], ['gauthier-st', 1], ['autre', 99]] as [$identifiant, $regime]) {
            try {
                $ajout->execute([':id' => $identifiant, ':regime' => $regime]);
                self::fail('Ligne acceptée à tort : ' . $identifiant);
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
        $this->attendreRefus('FOREIGN KEY', 'DELETE FROM regimes');
    }

    public function testUnRegimeEstUniqueSansTenirCompteDesMajuscules(): void
    {
        $this->pdo->exec("INSERT INTO regimes (nom) VALUES ('NDRC ALT')");
        $this->attendreRefus('UNIQUE', "INSERT INTO regimes (nom) VALUES ('ndrc alt')");
        $this->attendreRefus('CHECK', "INSERT INTO regimes (nom) VALUES (' NDRC FI')");
    }

    // ----- Aides -----

    private function ecrireJournal(string $categorie, string $date): void
    {
        $requete = $this->pdo->prepare("INSERT INTO journal (date, categorie, action, resultat) VALUES (:date, :categorie, 'essai', 'echec')");
        $requete->execute([':date' => $date, ':categorie' => $categorie]);
    }

    private function ajouterCompte(string $identifiant, string $role): void
    {
        $requete = $this->pdo->prepare(
            "INSERT INTO comptes (identifiant, nom_affiche, email, role, mot_de_passe_hache)
             VALUES (:identifiant, 'Test', 'test@exemple.fr', :role, :hache)"
        );
        $requete->execute([':identifiant' => $identifiant, ':role' => $role, ':hache' => password_hash('essai', PASSWORD_DEFAULT)]);
    }

    private function attendreRefus(string $motif, string $sql): void
    {
        try {
            $this->pdo->exec($sql);
            self::fail('Requête acceptée à tort : ' . $sql);
        } catch (PDOException $e) {
            self::assertStringContainsString($motif, $e->getMessage());
        }
    }
}
