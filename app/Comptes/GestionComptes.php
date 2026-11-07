<?php

declare(strict_types=1);

namespace Portail\Comptes;

use PDO;
use PDOException;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\MotsDePasse;

/**
 * Gestion des comptes par l'administrateur (écrans du portail) ou par la
 * commande de secours (SSH). Chaque action est inscrite au journal.
 *
 * Le mot de passe provisoire est renvoyé une seule fois, pour être affiché
 * puis transmis : il n'est conservé nulle part en clair.
 */
final class GestionComptes
{
    /** Trace complémentaire de chaque action de la direction sur les comptes. */
    public const ACTION_SECOURS = 'action de secours de la direction';

    /** Un compte actif non confirmé depuis ce délai apparaît dans « Comptes à revoir ». */
    public const DELAI_REVUE = '-6 months';

    private readonly DepotComptes $comptes;
    private readonly Journal $journal;

    /**
     * @param Compte|null $auteur Compte qui agit ; null pour la commande de secours en SSH
     */
    public function __construct(PDO $pdo, private readonly ?Compte $auteur, private readonly ?string $adresseIp)
    {
        $this->comptes = new DepotComptes($pdo);
        $this->journal = new Journal($pdo);
    }

    /** @return array{id: int, provisoire: string} */
    public function creer(string $identifiant, string $nomAffiche, string $email, string $role): array
    {
        $identifiant = trim($identifiant);
        if (preg_match('/^[a-z][a-z0-9]{2,31}$/', $identifiant) !== 1) {
            throw new ErreurSaisie('Identifiant invalide : 3 à 32 caractères, lettres minuscules et chiffres, en commençant par une lettre.');
        }
        if ($this->comptes->parIdentifiant($identifiant) !== null) {
            throw new ErreurSaisie('Cet identifiant est déjà utilisé.');
        }
        [$nomAffiche, $email, $role] = self::verifier($nomAffiche, $email, $role);
        $provisoire = self::provisoirePour($identifiant);
        $id = $this->comptes->creer($identifiant, $nomAffiche, $email, $role, MotsDePasse::hacher($provisoire));
        $this->tracer('création de compte', $identifiant, 'rôle : ' . Compte::LIBELLES_ROLES[$role]);

        return ['id' => $id, 'provisoire' => $provisoire];
    }

    public function modifier(int $id, string $nomAffiche, string $email, string $role): void
    {
        $compte = $this->trouver($id);
        [$nomAffiche, $email, $role] = self::verifier($nomAffiche, $email, $role);
        $this->protegerDernierAdmin(fn () => $this->comptes->modifier($id, $nomAffiche, $email, $role));
        $this->tracer('modification de compte', $compte->identifiant,
            $compte->role === $role ? null : 'rôle : ' . $compte->libelleRole() . ' → ' . Compte::LIBELLES_ROLES[$role]);
    }

    public function desactiver(int $id): void
    {
        $compte = $this->trouver($id);
        $this->protegerDernierAdmin(fn () => $this->comptes->changerActif($id, false));
        $this->tracer('désactivation de compte', $compte->identifiant);
    }

    public function reactiver(int $id): void
    {
        $compte = $this->trouver($id);
        $this->comptes->changerActif($id, true);
        $this->tracer('réactivation de compte', $compte->identifiant);
    }

    /** @return string Nouveau mot de passe provisoire, à transmettre */
    public function reinitialiserMotDePasse(int $id): string
    {
        $compte = $this->trouver($id);
        $provisoire = self::provisoirePour($compte->identifiant);
        $this->comptes->motDePasseProvisoire($id, MotsDePasse::hacher($provisoire));
        $this->tracer('réinitialisation du mot de passe', $compte->identifiant);

        return $provisoire;
    }

    public function reinitialiserDoubleAuth(int $id): void
    {
        $compte = $this->trouver($id);
        $this->comptes->reinitialiserDoubleAuth($id);
        $this->tracer('réinitialisation de la double authentification', $compte->identifiant);
    }

    /** Confirme qu'un compte est toujours nécessaire : il quitte « Comptes à revoir » pour 6 mois. */
    public function confirmer(int $id, int $maintenant): void
    {
        $compte = $this->trouver($id);
        $this->comptes->marquerRevu($id, gmdate('Y-m-d\\TH:i:s\\Z', $maintenant));
        $this->tracer('revue de compte', $compte->identifiant, 'toujours nécessaire');
    }

    /** @return list<array{id: int, identifiant: string, nom: string, depuis: string}> */
    public function aRevoir(int $maintenant): array
    {
        $limite = strtotime(self::DELAI_REVUE, $maintenant);

        return $this->comptes->aRevoir(gmdate('Y-m-d\\TH:i:s\\Z', $limite === false ? $maintenant : $limite));
    }

    private function trouver(int $id): Compte
    {
        $compte = $this->comptes->parId($id);
        if ($compte === null) {
            throw new ErreurSaisie('Compte introuvable.');
        }

        return $compte;
    }

    /** @param callable(): void $action */
    private function protegerDernierAdmin(callable $action): void
    {
        try {
            $action();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'dernier_administrateur')) {
                throw new ErreurSaisie('Impossible : c\'est le dernier administrateur actif. Créez ou réactivez d\'abord un autre administrateur.');
            }
            throw $e;
        }
    }

    /** @return array{string, string, 'administrateur'|'enseignant'|'administratif'|'consultation'} */
    private static function verifier(string $nomAffiche, string $email, string $role): array
    {
        $nomAffiche = trim(preg_replace('/\s+/u', ' ', $nomAffiche) ?? '');
        if ($nomAffiche === '' || mb_strlen($nomAffiche, 'UTF-8') > 100) {
            throw new ErreurSaisie('Le nom affiché doit compter de 1 à 100 caractères.');
        }
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new ErreurSaisie('Adresse e-mail invalide.');
        }
        foreach (Compte::ROLES as $connu) {
            if ($connu === $role) {
                return [$nomAffiche, $email, $connu];
            }
        }
        throw new ErreurSaisie('Rôle inconnu.');
    }

    private static function provisoirePour(string $identifiant): string
    {
        do {
            $provisoire = MotsDePasse::provisoire();
        } while (MotsDePasse::refus($provisoire, $identifiant) !== null);

        return $provisoire;
    }

    private function tracer(string $action, string $cible, ?string $detail = null): void
    {
        $this->journal->ecrire('administration', $action, 'succes', $this->auteur?->id,
            $this->auteur !== null ? $this->auteur->nomAffiche : 'commande de secours (SSH)', $this->adresseIp, $cible, $detail);
        // Toute action de la direction sur les comptes est une action de secours :
        // elle est signalée aux administrateurs (page d'accueil ; e-mail avec le lot des alertes).
        if ($this->auteur !== null && $this->auteur->role === 'consultation') {
            $this->journal->ecrire('administration', self::ACTION_SECOURS, 'succes', $this->auteur->id,
                $this->auteur->nomAffiche, $this->adresseIp, $cible, $action . ($detail === null ? '' : ' (' . $detail . ')'));
        }
    }
}
