<?php

declare(strict_types=1);

namespace Portail\Authentification;

use PDO;
use Portail\Comptes\Compte;
use Portail\Comptes\DepotComptes;
use Portail\Journal\Journal;
use Portail\Securite\Base32;
use Portail\Securite\Chiffrement;
use Portail\Securite\Horloge;
use Portail\Securite\MotsDePasse;
use Portail\Securite\Totp;
use RuntimeException;

/**
 * Règles de la connexion : mot de passe, puis code à 6 chiffres.
 *
 * - 5 échecs consécutifs sur un compte (mot de passe ou code) : compte bloqué 15 min ;
 * - 30 échecs en 15 min depuis une même adresse : adresse bloquée 15 min ;
 * - le message d'erreur ne dit jamais si c'est l'identifiant, le mot de passe
 *   ou le code qui est faux, ni si le compte existe ou est bloqué ;
 * - le compteur d'échecs n'est remis à zéro qu'après une connexion complète.
 */
final class Authentification
{
    public const ECHECS_MAX = 5;
    public const DUREE_BLOCAGE = 900;
    public const ECHECS_MAX_ADRESSE = 30;

    private readonly DepotComptes $comptes;
    private readonly Journal $journal;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Chiffrement $chiffrement,
        private readonly Horloge $horloge,
    ) {
        $this->comptes = new DepotComptes($pdo);
        $this->journal = new Journal($pdo);
    }

    public function adresseBloquee(string $ip): bool
    {
        $requete = $this->pdo->prepare('SELECT count(*) FROM tentatives_connexion WHERE adresse_ip = :ip AND reussie = 0 AND date > :depuis');
        $requete->execute([':ip' => $ip, ':depuis' => Compte::date($this->horloge->maintenant() - self::DUREE_BLOCAGE)]);

        return (int) $requete->fetchColumn() >= self::ECHECS_MAX_ADRESSE;
    }

    /** Première étape. @return Compte|null Le compte si le mot de passe est juste et le compte utilisable */
    public function verifierMotDePasse(string $identifiant, string $motDePasse, string $ip): ?Compte
    {
        $identifiant = mb_substr(trim($identifiant), 0, 64, 'UTF-8');
        $compte = $this->comptes->parIdentifiant($identifiant);
        // Même calcul, que le compte existe ou non : la durée de réponse ne trahit rien.
        $juste = MotsDePasse::verifier($motDePasse, $compte !== null ? $compte->motDePasseHache : MotsDePasse::empreinteFactice());

        if ($compte === null || !$juste || !$compte->actif || $compte->estBloque($this->horloge->maintenant())) {
            $this->echec($compte, $identifiant, $ip, match (true) {
                $compte === null => 'identifiant inconnu',
                !$juste => 'mot de passe faux',
                !$compte->actif => 'compte désactivé',
                default => 'compte bloqué',
            });

            return null;
        }
        if (MotsDePasse::doitEtreRehache($compte->motDePasseHache)) {
            $this->comptes->rehacher($compte->id, MotsDePasse::hacher($motDePasse));
        }

        return $compte;
    }

    /** Deuxième étape, compte déjà équipé. */
    public function verifierCode(int $compteId, string $code, string $ip): ?Compte
    {
        $compte = $this->compteUtilisable($compteId);
        if ($compte === null || !$compte->doubleAuthActive || $compte->secret2faChiffre === null) {
            return null;
        }
        $secret = $this->chiffrement->dechiffrer($compte->secret2faChiffre, self::contexte($compte->id));
        $pas = Totp::verifier($secret, $code, $this->horloge->maintenant(), $compte->dernierPas2fa);
        if ($pas === null) {
            $this->echec($compte, $compte->identifiant, $ip, 'code faux ou déjà utilisé');

            return null;
        }
        $this->reussite($compte, $pas, $ip, 'connexion');

        return $compte;
    }

    /** Nouveau secret à afficher à l'activation, chiffré pour être gardé en session. */
    public function nouveauSecretChiffre(int $compteId): string
    {
        return $this->chiffrement->chiffrer(Totp::genererSecret(), self::contexteInscription($compteId));
    }

    public function secretLisible(int $compteId, string $secretChiffre): string
    {
        return Base32::encoder($this->chiffrement->dechiffrer($secretChiffre, self::contexteInscription($compteId)));
    }

    /** Deuxième étape, première connexion : activation de la double authentification. */
    public function activer(int $compteId, string $secretChiffreSession, string $code, string $ip): ?Compte
    {
        $compte = $this->compteUtilisable($compteId);
        if ($compte === null || $compte->doubleAuthActive) {
            return null;
        }
        $secret = $this->chiffrement->dechiffrer($secretChiffreSession, self::contexteInscription($compteId));
        $pas = Totp::verifier($secret, $code, $this->horloge->maintenant(), null);
        if ($pas === null) {
            $this->echec($compte, $compte->identifiant, $ip, 'code d\'activation faux');

            return null;
        }
        $this->comptes->activerDoubleAuth($compte->id, $this->chiffrement->chiffrer($secret, self::contexte($compte->id)));
        $this->journal->ecrire('connexion', 'activation double authentification', 'succes', $compte->id, $compte->nomAffiche, $ip);
        $this->reussite($compte, $pas, $ip, 'première connexion');

        return $compte;
    }

    /** @return string|null Raison du refus, ou null si le mot de passe a été changé */
    public function changerMotDePasse(int $compteId, string $actuel, string $nouveau, string $confirmation, string $ip): ?string
    {
        $compte = $this->comptes->parId($compteId);
        if ($compte === null) {
            throw new RuntimeException('Compte introuvable.');
        }
        if (!MotsDePasse::verifier($actuel, $compte->motDePasseHache)) {
            $this->journal->ecrire('connexion', 'changement mot de passe', 'refus', $compte->id, $compte->nomAffiche, $ip, null, 'mot de passe actuel faux');

            return 'Le mot de passe actuel est incorrect.';
        }
        if (!hash_equals($nouveau, $confirmation)) {
            return 'Les deux nouveaux mots de passe ne sont pas identiques.';
        }
        if (MotsDePasse::verifier($nouveau, $compte->motDePasseHache)) {
            return 'Le nouveau mot de passe doit être différent de l\'actuel.';
        }
        $refus = MotsDePasse::refus($nouveau, $compte->identifiant);
        if ($refus !== null) {
            return $refus;
        }
        $this->comptes->changerMotDePasse($compte->id, MotsDePasse::hacher($nouveau));
        $this->journal->ecrire('connexion', 'changement mot de passe', 'succes', $compte->id, $compte->nomAffiche, $ip);

        return null;
    }

    private function compteUtilisable(int $compteId): ?Compte
    {
        $compte = $this->comptes->parId($compteId);
        if ($compte === null || !$compte->actif || $compte->estBloque($this->horloge->maintenant())) {
            return null;
        }

        return $compte;
    }

    private function echec(?Compte $compte, string $identifiantSaisi, string $ip, string $raison): void
    {
        $maintenant = $this->horloge->maintenant();
        $this->tentative($identifiantSaisi, $ip, false);
        $this->journal->ecrire('connexion', 'connexion', 'echec', $compte?->id, $compte?->nomAffiche, $ip, $identifiantSaisi, $raison);
        if ($compte === null || $compte->estBloque($maintenant) || !$compte->actif) {
            return;
        }
        $bloque = $compte->echecsConsecutifs + 1 >= self::ECHECS_MAX;
        $this->comptes->enregistrerEchec($compte->id, $bloque ? $maintenant + self::DUREE_BLOCAGE : null);
        if ($bloque) {
            $this->journal->ecrire('connexion', 'blocage du compte', 'refus', $compte->id, $compte->nomAffiche, $ip, null,
                self::ECHECS_MAX . ' échecs consécutifs : bloqué ' . intdiv(self::DUREE_BLOCAGE, 60) . ' minutes');
        }
    }

    private function reussite(Compte $compte, int $pas, string $ip, string $detail): void
    {
        $this->tentative($compte->identifiant, $ip, true);
        $this->comptes->enregistrerConnexion($compte->id, $pas, $this->horloge->maintenant());
        $this->journal->ecrire('connexion', 'connexion', 'succes', $compte->id, $compte->nomAffiche, $ip, null, $detail);
    }

    private function tentative(string $identifiantSaisi, string $ip, bool $reussie): void
    {
        $requete = $this->pdo->prepare(
            'INSERT INTO tentatives_connexion (date, adresse_ip, identifiant_saisi, reussie) VALUES (:date, :ip, :identifiant, :reussie)'
        );
        $requete->execute([':date' => Compte::date($this->horloge->maintenant()), ':ip' => $ip,
            ':identifiant' => mb_substr($identifiantSaisi, 0, 64, 'UTF-8'), ':reussie' => $reussie ? 1 : 0]);
    }

    private static function contexte(int $compteId): string
    {
        return 'compte:' . $compteId;
    }

    private static function contexteInscription(int $compteId): string
    {
        return 'inscription:' . $compteId;
    }
}
