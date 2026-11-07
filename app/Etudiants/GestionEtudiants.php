<?php

declare(strict_types=1);

namespace Portail\Etudiants;

use PDO;
use Portail\Base\Lecture;
use Portail\Base\Installation;
use Portail\Comptes\Compte;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;

/**
 * Fiches des étudiants : ajout manuel, corrections, changement de régime ou
 * de session (cahier des charges, « Parcours des étudiants »).
 *
 * - L'identifiant ne change jamais : une erreur d'identifiant se corrige par
 *   suppression puis recréation (cas 11, administrateur, V2).
 * - Chaque changement de régime, de session, de nom ou d'e-mail est inscrit
 *   dans l'historique de l'étudiant et au journal.
 * - Aucun site n'est touché ici : les actions sur les sites arrivent en V2.
 *
 * Les droits (qui peut ajouter, modifier, changer de session) sont vérifiés
 * par l'appelant, à chaque requête (Droits).
 */
final class GestionEtudiants
{
    private const SELECTION = 'SELECT e.*, r.nom AS regime, s.annee FROM etudiants e
        JOIN regimes r ON r.id = e.regime_id JOIN sessions s ON s.id = e.session_id';

    private readonly RegimesSessions $regimesSessions;
    private readonly Journal $journal;

    public function __construct(private readonly PDO $pdo, private readonly ?Compte $auteur = null, private readonly ?string $adresseIp = null)
    {
        $this->regimesSessions = new RegimesSessions($pdo);
        $this->journal = new Journal($pdo);
    }

    /** @return list<Etudiant> Étudiants, filtrés par régime et/ou session, par ordre alphabétique */
    public function liste(?int $regimeId = null, ?int $sessionId = null): array
    {
        $requete = $this->pdo->prepare(self::SELECTION
            . ' WHERE (:r IS NULL OR e.regime_id = :r2) AND (:s IS NULL OR e.session_id = :s2)'
            . ' ORDER BY e.nom_complet COLLATE NOCASE, e.identifiant');
        $requete->execute([':r' => $regimeId, ':r2' => $regimeId, ':s' => $sessionId, ':s2' => $sessionId]);
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $liste[] = Etudiant::depuisLigne($ligne);
        }

        return $liste;
    }

    public function parId(int $id): ?Etudiant
    {
        $requete = $this->pdo->prepare(self::SELECTION . ' WHERE e.id = :id');
        $requete->execute([':id' => $id]);
        $ligne = Lecture::ligne($requete);

        return $ligne === null ? null : Etudiant::depuisLigne($ligne);
    }

    /**
     * Un identifiant est pris s'il appartient déjà à un étudiant, à un site
     * relevé sur le serveur ou à un site protégé.
     */
    public function identifiantPris(string $identifiant): bool
    {
        if (in_array($identifiant, Installation::SITES_PROTEGES, true)) {
            return true;
        }
        $requete = $this->pdo->prepare('SELECT 1 FROM etudiants WHERE identifiant = :i
            UNION SELECT 1 FROM releve_sites WHERE identifiant = :i2');
        $requete->execute([':i' => $identifiant, ':i2' => $identifiant]);

        return $requete->fetchColumn() !== false;
    }

    /** @return array{identifiant: string, identifiant_wp: string} Proposition, à vérifier par l'utilisateur */
    public function proposer(string $nom, string $prenom): array
    {
        $identifiant = Identifiant::premierLibre(Identifiant::depuisNomEtPrenom($nom, $prenom), $this->identifiantPris(...));

        return ['identifiant' => $identifiant, 'identifiant_wp' => Identifiant::wordpress($identifiant)];
    }

    /** Ajout manuel d'un étudiant (cas 2 : arrivée en cours d'année). */
    public function ajouter(string $nom, string $prenom, string $email, int $regimeId, int $sessionId, string $identifiant): int
    {
        $nomComplet = self::nomComplet($nom, $prenom);
        $email = self::email($email);
        $identifiant = trim($identifiant);
        if (!Identifiant::formatValide($identifiant)) {
            throw new ErreurSaisie('Identifiant invalide : 2 à 13 lettres minuscules sans accent, puis un chiffre de 2 à 9 pour un homonyme.');
        }
        $this->verifierPlacement($regimeId, $sessionId);

        $this->pdo->beginTransaction();
        try {
            // Vérification dans la transaction : deux ajouts simultanés ne peuvent pas prendre le même identifiant.
            if ($this->identifiantPris($identifiant)) {
                throw new ErreurSaisie('L\'identifiant « ' . $identifiant . ' » est déjà pris. Proposition suivante : '
                    . Identifiant::premierLibre(preg_replace('/[0-9]$/', '', $identifiant) ?? $identifiant, $this->identifiantPris(...)) . '.');
            }
            $requete = $this->pdo->prepare('INSERT INTO etudiants
                (nom_complet, identifiant, identifiant_wp, email, regime_id, session_id, ajoute_par, origine)
                VALUES (:nom, :identifiant, :wp, :email, :regime, :session, :par, \'saisie\')');
            $requete->execute([
                ':nom' => $nomComplet, ':identifiant' => $identifiant, ':wp' => Identifiant::wordpress($identifiant),
                ':email' => $email, ':regime' => $regimeId, ':session' => $sessionId, ':par' => $this->auteur?->id,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->tracer('ajout d\'étudiant', $identifiant, $nomComplet . ' – ' . $this->classe($regimeId, $sessionId));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    /** Correction du nom complet ou de l'e-mail (cas 10). */
    public function modifierCoordonnees(int $id, string $nomComplet, string $email): void
    {
        $etudiant = $this->trouver($id);
        $nomComplet = self::nettoyer($nomComplet);
        if ($nomComplet === '' || mb_strlen($nomComplet, 'UTF-8') > 150) {
            throw new ErreurSaisie('Le nom complet doit compter de 1 à 150 caractères.');
        }
        $email = self::email($email);
        $this->enTransaction(function () use ($etudiant, $nomComplet, $email): void {
            if ($nomComplet !== $etudiant->nomComplet) {
                $this->changer($etudiant, 'nom_complet', $nomComplet, $etudiant->nomComplet, $nomComplet);
            }
            if ($email !== $etudiant->email) {
                $this->changer($etudiant, 'email', $email, $etudiant->email, $email);
            }
        });
    }

    /**
     * Changement de régime et/ou de session, en une seule opération (cas 4 à 7).
     * Les sites ne sont pas touchés ici.
     */
    public function changerParcours(int $id, int $regimeId, int $sessionId): void
    {
        $etudiant = $this->trouver($id);
        if ($regimeId === $etudiant->regimeId && $sessionId === $etudiant->sessionId) {
            return;
        }
        if ($regimeId !== $etudiant->regimeId && !$this->regimesSessions->regimeActif($regimeId)) {
            throw new ErreurSaisie('Ce régime est désactivé ou n\'existe pas.');
        }
        if ($sessionId !== $etudiant->sessionId && !$this->regimesSessions->sessionActive($sessionId)) {
            throw new ErreurSaisie('Cette session est archivée ou n\'existe pas.');
        }
        $this->enTransaction(function () use ($etudiant, $regimeId, $sessionId): void {
            if ($regimeId !== $etudiant->regimeId) {
                $this->changer($etudiant, 'regime', $regimeId, $etudiant->regime, $this->regimesSessions->nomRegime($regimeId));
            }
            if ($sessionId !== $etudiant->sessionId) {
                $this->changer($etudiant, 'session', $sessionId, (string) $etudiant->session, (string) $this->regimesSessions->anneeSession($sessionId));
            }
        });
    }

    /** @return list<array{date: string, champ: string, ancienne: ?string, nouvelle: ?string, auteur: ?string}> Plus récent d'abord */
    public function historique(string $identifiant): array
    {
        $requete = $this->pdo->prepare('SELECT date, champ, ancienne_valeur, nouvelle_valeur, auteur_nom
            FROM historique_etudiants WHERE etudiant_identifiant = :i ORDER BY date DESC, id DESC');
        $requete->execute([':i' => $identifiant]);
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            if (is_string($ligne['date']) && is_string($ligne['champ'])) {
                $liste[] = [
                    'date' => $ligne['date'],
                    'champ' => $ligne['champ'],
                    'ancienne' => is_string($ligne['ancienne_valeur']) ? $ligne['ancienne_valeur'] : null,
                    'nouvelle' => is_string($ligne['nouvelle_valeur']) ? $ligne['nouvelle_valeur'] : null,
                    'auteur' => is_string($ligne['auteur_nom']) ? $ligne['auteur_nom'] : null,
                ];
            }
        }

        return $liste;
    }

    /** Nom complet d'un ajout manuel, au format d'EcoleDirecte : « NOM Prénom ». */
    public static function nomComplet(string $nom, string $prenom): string
    {
        $nom = mb_strtoupper(self::nettoyer($nom), 'UTF-8');
        $prenom = self::nettoyer($prenom);
        if ($nom === '' || $prenom === '') {
            throw new ErreurSaisie('Le nom et le prénom sont obligatoires.');
        }
        $complet = $nom . ' ' . $prenom;
        if (mb_strlen($complet, 'UTF-8') > 150) {
            throw new ErreurSaisie('Nom et prénom trop longs (150 caractères au plus).');
        }

        return $complet;
    }

    /** @param 'nom_complet'|'email'|'regime'|'session' $champ */
    private function changer(Etudiant $etudiant, string $champ, string|int $valeur, string $ancienne, string $nouvelle): void
    {
        $colonne = match ($champ) {
            'nom_complet' => 'nom_complet',
            'email' => 'email',
            'regime' => 'regime_id',
            'session' => 'session_id',
        };
        // Nom de colonne choisi dans la liste fermée ci-dessus, jamais reçu du visiteur.
        $requete = $this->pdo->prepare('UPDATE etudiants SET ' . $colonne . ' = :v WHERE id = :id'); // nosemgrep: outils.semgrep.requete-sql-construite-par-concatenation
        $requete->execute([':v' => $valeur, ':id' => $etudiant->id]);

        $trace = $this->pdo->prepare('INSERT INTO historique_etudiants
            (etudiant_identifiant, champ, ancienne_valeur, nouvelle_valeur, auteur_compte_id, auteur_nom)
            VALUES (:i, :champ, :ancienne, :nouvelle, :auteur, :nom)');
        $trace->execute([
            ':i' => $etudiant->identifiant, ':champ' => $champ, ':ancienne' => $ancienne, ':nouvelle' => $nouvelle,
            ':auteur' => $this->auteur?->id, ':nom' => $this->auteur?->nomAffiche,
        ]);
        $libelles = ['nom_complet' => 'nom', 'email' => 'e-mail', 'regime' => 'régime', 'session' => 'session'];
        $this->tracer('modification d\'étudiant', $etudiant->identifiant, $libelles[$champ] . ' : ' . $ancienne . ' → ' . $nouvelle);
    }

    /** @param callable(): void $action */
    private function enTransaction(callable $action): void
    {
        $this->pdo->beginTransaction();
        try {
            $action();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function verifierPlacement(int $regimeId, int $sessionId): void
    {
        if (!$this->regimesSessions->regimeActif($regimeId)) {
            throw new ErreurSaisie('Choisissez un régime actif. S\'il manque, créez-le d\'abord (Régimes et sessions).');
        }
        if (!$this->regimesSessions->sessionActive($sessionId)) {
            throw new ErreurSaisie('Choisissez une session active. Si elle manque, créez-la d\'abord (Régimes et sessions).');
        }
    }

    private function classe(int $regimeId, int $sessionId): string
    {
        return $this->regimesSessions->nomRegime($regimeId) . ' – session ' . $this->regimesSessions->anneeSession($sessionId);
    }

    private function trouver(int $id): Etudiant
    {
        $etudiant = $this->parId($id);
        if ($etudiant === null) {
            throw new ErreurSaisie('Étudiant introuvable.');
        }

        return $etudiant;
    }

    private static function email(string $email): string
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new ErreurSaisie('Adresse e-mail invalide.');
        }

        return $email;
    }

    private static function nettoyer(string $texte): string
    {
        return trim(preg_replace('/\s+/u', ' ', $texte) ?? '');
    }

    private function tracer(string $action, string $cible, ?string $detail = null): void
    {
        $this->journal->ecrire('administration', $action, 'succes', $this->auteur?->id, $this->auteur?->nomAffiche, $this->adresseIp, $cible, $detail);
    }
}
