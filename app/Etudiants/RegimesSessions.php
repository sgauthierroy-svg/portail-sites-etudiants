<?php

declare(strict_types=1);

namespace Portail\Etudiants;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;

/**
 * Régimes (NDRC FI, NDRC ALT…) et sessions d'examen (2027, 2028…).
 *
 * Un régime ou une session n'est jamais supprimé : on le désactive ou on
 * l'archive, pour que les fiches et l'historique gardent leur sens.
 * Un étudiant ne peut être placé que dans un régime actif et une session active.
 */
final class RegimesSessions
{
    private readonly Journal $journal;

    public function __construct(private readonly PDO $pdo, private readonly ?Compte $auteur = null, private readonly ?string $adresseIp = null)
    {
        $this->journal = new Journal($pdo);
    }

    /** @return list<array{id: int, nom: string, actif: bool}> */
    public function regimes(bool $actifsSeulement = false): array
    {
        $resultat = $this->pdo->query('SELECT id, nom, actif FROM regimes'
            . ($actifsSeulement ? ' WHERE actif = 1' : '') . ' ORDER BY actif DESC, nom');
        $liste = [];
        foreach (Lecture::lignes($resultat) as $ligne) {
            if (is_int($ligne['id']) && is_string($ligne['nom']) && is_int($ligne['actif'])) {
                $liste[] = ['id' => $ligne['id'], 'nom' => $ligne['nom'], 'actif' => $ligne['actif'] === 1];
            }
        }

        return $liste;
    }

    /** @return list<array{id: int, annee: int, version_wp: ?string, active: bool}> */
    public function sessions(bool $activesSeulement = false): array
    {
        $resultat = $this->pdo->query('SELECT id, annee, version_wp_gelee, active FROM sessions'
            . ($activesSeulement ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, annee');
        $liste = [];
        foreach (Lecture::lignes($resultat) as $ligne) {
            if (is_int($ligne['id']) && is_int($ligne['annee']) && is_int($ligne['active'])) {
                $version = is_string($ligne['version_wp_gelee']) ? $ligne['version_wp_gelee'] : null;
                $liste[] = ['id' => $ligne['id'], 'annee' => $ligne['annee'], 'version_wp' => $version, 'active' => $ligne['active'] === 1];
            }
        }

        return $liste;
    }

    public function creerRegime(string $nom): int
    {
        $nom = self::nettoyer($nom);
        if ($nom === '' || mb_strlen($nom, 'UTF-8') > 40) {
            throw new ErreurSaisie('Le nom du régime doit compter de 1 à 40 caractères (ex. NDRC FI).');
        }
        if (preg_match('/^[\p{L}0-9][\p{L}0-9 \'()+.\/]*$/u', $nom) !== 1) {
            throw new ErreurSaisie('Nom de régime invalide : lettres, chiffres et espaces seulement (ex. NDRC ALT).');
        }
        if ($this->idRegime($nom) !== null) {
            throw new ErreurSaisie('Ce régime existe déjà.');
        }
        $requete = $this->pdo->prepare('INSERT INTO regimes (nom) VALUES (:nom)');
        $requete->execute([':nom' => $nom]);
        $id = (int) $this->pdo->lastInsertId();
        $this->tracer('création de régime', $nom);

        return $id;
    }

    public function changerRegimeActif(int $id, bool $actif): void
    {
        $nom = $this->nomRegime($id);
        $requete = $this->pdo->prepare('UPDATE regimes SET actif = :actif WHERE id = :id');
        $requete->execute([':actif' => $actif ? 1 : 0, ':id' => $id]);
        $this->tracer($actif ? 'réactivation de régime' : 'désactivation de régime', $nom);
    }

    public function creerSession(string $annee): int
    {
        $annee = trim($annee);
        if (preg_match('/^20[2-9][0-9]$/', $annee) !== 1) {
            throw new ErreurSaisie('Année de session invalide : 4 chiffres, l\'année de l\'examen (ex. 2028).');
        }
        if ($this->idSession((int) $annee) !== null) {
            throw new ErreurSaisie('La session ' . $annee . ' existe déjà.');
        }
        $requete = $this->pdo->prepare('INSERT INTO sessions (annee) VALUES (:annee)');
        $requete->execute([':annee' => (int) $annee]);
        $id = (int) $this->pdo->lastInsertId();
        $this->tracer('création de session', 'session ' . $annee);

        return $id;
    }

    public function changerSessionActive(int $id, bool $active): void
    {
        $annee = $this->anneeSession($id);
        $requete = $this->pdo->prepare('UPDATE sessions SET active = :active WHERE id = :id');
        $requete->execute([':active' => $active ? 1 : 0, ':id' => $id]);
        $this->tracer($active ? 'réactivation de session' : 'archivage de session', 'session ' . $annee);
    }

    /** Version WordPress gelée de la session (ex. 6.8) ; vide = pas de gel. */
    public function gelerVersion(int $id, string $version): void
    {
        $annee = $this->anneeSession($id);
        $version = trim($version);
        if ($version !== '' && preg_match('/^[0-9]{1,2}\.[0-9]{1,2}(\.[0-9]{1,2})?$/', $version) !== 1) {
            throw new ErreurSaisie('Version WordPress invalide (ex. 6.8 ou 6.8.3).');
        }
        $requete = $this->pdo->prepare('UPDATE sessions SET version_wp_gelee = :version WHERE id = :id');
        $requete->execute([':version' => $version === '' ? null : $version, ':id' => $id]);
        $this->tracer('version WordPress de session', 'session ' . $annee, $version === '' ? 'gel retiré' : 'gelée en ' . $version);
    }

    public function nomRegime(int $id): string
    {
        $requete = $this->pdo->prepare('SELECT nom FROM regimes WHERE id = :id');
        $requete->execute([':id' => $id]);
        $nom = Lecture::texte($requete->fetchColumn());
        if ($nom === null) {
            throw new ErreurSaisie('Régime introuvable.');
        }

        return $nom;
    }

    public function anneeSession(int $id): int
    {
        $requete = $this->pdo->prepare('SELECT annee FROM sessions WHERE id = :id');
        $requete->execute([':id' => $id]);
        $annee = Lecture::entier($requete->fetchColumn());
        if ($annee === null) {
            throw new ErreurSaisie('Session introuvable.');
        }

        return $annee;
    }

    public function regimeActif(int $id): bool
    {
        $requete = $this->pdo->prepare('SELECT actif FROM regimes WHERE id = :id');
        $requete->execute([':id' => $id]);

        return Lecture::entier($requete->fetchColumn()) === 1;
    }

    public function sessionActive(int $id): bool
    {
        $requete = $this->pdo->prepare('SELECT active FROM sessions WHERE id = :id');
        $requete->execute([':id' => $id]);

        return Lecture::entier($requete->fetchColumn()) === 1;
    }

    /** Régime par son nom ; majuscules et espaces en trop ignorés, tiret refusé (règle de l'import). */
    public function idRegime(string $nom): ?int
    {
        $requete = $this->pdo->prepare('SELECT id FROM regimes WHERE nom = :nom COLLATE NOCASE');
        $requete->execute([':nom' => self::nettoyer($nom)]);
        return Lecture::entier($requete->fetchColumn());
    }

    public function idSession(int $annee): ?int
    {
        $requete = $this->pdo->prepare('SELECT id FROM sessions WHERE annee = :annee');
        $requete->execute([':annee' => $annee]);
        return Lecture::entier($requete->fetchColumn());
    }

    /**
     * Année de formation pour une session (année de l'examen), à une date donnée.
     * L'année scolaire commence le 1er septembre : en 2026-2027, la session 2027
     * est en 2e année et la session 2028 en 1re année.
     */
    public static function anneeDeFormation(int $session, int $instant): string
    {
        $annee = (int) gmdate('Y', $instant);
        $rentree = (int) gmdate('n', $instant) >= 9 ? $annee : $annee - 1;

        return match ($session - $rentree) {
            1 => '2e année',
            2 => '1re année',
            default => $session - $rentree < 1 ? 'cursus terminé' : 'à venir',
        };
    }

    private static function nettoyer(string $nom): string
    {
        return trim(preg_replace('/\s+/u', ' ', $nom) ?? '');
    }

    private function tracer(string $action, string $cible, ?string $detail = null): void
    {
        $this->journal->ecrire('administration', $action, 'succes', $this->auteur?->id, $this->auteur?->nomAffiche, $this->adresseIp, $cible, $detail);
    }
}
