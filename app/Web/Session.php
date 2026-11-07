<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Noyau\ReglagesPhp;
use Portail\Securite\Horloge;

/**
 * État de connexion d'un visiteur.
 *
 * - « attente » : mot de passe juste, code pas encore saisi (5 minutes au plus) ;
 * - « connecté » : connexion complète ; déconnexion après 30 minutes sans
 *   activité, et dans tous les cas après 12 heures ;
 * - un jeton anti-falsification par session, exigé dans chaque formulaire.
 */
final class Session
{
    public const DUREE_ATTENTE = 300;
    public const DUREE_MAX = 43200;

    /** @var array<string, mixed> */
    private array $donnees = [];

    public function __construct(private readonly StockageSession $stockage, private readonly Horloge $horloge)
    {
        $this->donnees = $stockage->lire();
        $this->verifierExpiration();
    }

    public function jeton(): string
    {
        $jeton = $this->texte('jeton');
        if ($jeton === null || strlen($jeton) !== 64) {
            $jeton = bin2hex(random_bytes(32));
            $this->donnees['jeton'] = $jeton;
            $this->sauver();
        }

        return $jeton;
    }

    public function jetonValide(string $soumis): bool
    {
        $jeton = $this->texte('jeton');

        return $jeton !== null && strlen($jeton) === 64 && hash_equals($jeton, $soumis);
    }

    /** Vient d'être déconnecté pour inactivité (message affiché une seule fois). */
    public function vientDExpirer(): bool
    {
        if (($this->donnees['expiree'] ?? false) !== true) {
            return false;
        }
        unset($this->donnees['expiree']);
        $this->sauver();

        return true;
    }

    // ----- Étape intermédiaire : mot de passe juste, code attendu -----

    public function mettreEnAttente(int $compteId): void
    {
        $this->stockage->regenerer();
        $this->donnees = ['attente_compte' => $compteId, 'attente_depuis' => $this->horloge->maintenant()];
        $this->sauver();
    }

    public function compteEnAttente(): ?int
    {
        $compte = $this->entier('attente_compte');
        $depuis = $this->entier('attente_depuis');
        if ($compte === null || $depuis === null) {
            return null;
        }
        if ($this->horloge->maintenant() - $depuis > self::DUREE_ATTENTE) {
            $this->oublierAttente();

            return null;
        }

        return $compte;
    }

    public function secretInscription(): ?string
    {
        return $this->texte('secret_inscription');
    }

    public function garderSecretInscription(string $secretChiffre): void
    {
        $this->donnees['secret_inscription'] = $secretChiffre;
        $this->sauver();
    }

    public function oublierAttente(): void
    {
        unset($this->donnees['attente_compte'], $this->donnees['attente_depuis'], $this->donnees['secret_inscription']);
        $this->sauver();
    }

    // ----- Connexion complète -----

    public function connecter(int $compteId): void
    {
        $this->stockage->regenerer();
        $maintenant = $this->horloge->maintenant();
        $this->donnees = ['compte' => $compteId, 'connecte_le' => $maintenant, 'activite' => $maintenant];
        $this->jeton();
    }

    public function compteConnecte(): ?int
    {
        return $this->entier('compte');
    }

    /** À appeler à chaque page vue par un visiteur connecté. */
    public function noterActivite(): void
    {
        if ($this->compteConnecte() !== null) {
            $this->donnees['activite'] = $this->horloge->maintenant();
            $this->sauver();
        }
    }

    public function deconnecter(): void
    {
        $this->donnees = [];
        $this->stockage->detruire();
    }

    private function verifierExpiration(): void
    {
        if ($this->compteConnecte() === null) {
            return;
        }
        $maintenant = $this->horloge->maintenant();
        $activite = $this->entier('activite');
        $debut = $this->entier('connecte_le');
        if ($activite === null || $debut === null
            || $maintenant - $activite > ReglagesPhp::INACTIVITE_MAX
            || $maintenant - $debut > self::DUREE_MAX) {
            $this->deconnecter();
            $this->donnees = ['expiree' => true];
            $this->sauver();
        }
    }

    private function texte(string $cle): ?string
    {
        return isset($this->donnees[$cle]) && is_string($this->donnees[$cle]) ? $this->donnees[$cle] : null;
    }

    private function entier(string $cle): ?int
    {
        return isset($this->donnees[$cle]) && is_int($this->donnees[$cle]) ? $this->donnees[$cle] : null;
    }

    private function sauver(): void
    {
        $this->stockage->ecrire($this->donnees);
    }
}
