<?php

declare(strict_types=1);

namespace Portail\Web;

use PDO;
use Portail\Authentification\Authentification;
use Portail\Comptes\Compte;
use Portail\Comptes\DepotComptes;
use Portail\Comptes\Droits;
use Portail\Comptes\GestionComptes;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\Horloge;

/**
 * Aiguillage des requêtes vers les pages du portail.
 *
 * Règles communes :
 * - tout formulaire envoyé (POST) doit porter le jeton de la session et, si le
 *   navigateur l'indique, venir de l'adresse du portail ; sinon : refus (403) ;
 * - toute page autre que celles de la connexion exige une connexion complète ;
 * - tant que le mot de passe provisoire n'est pas changé, seule la page
 *   de changement est accessible.
 */
final class Application
{
    private const MESSAGE_ECHEC = 'Identifiant, mot de passe ou code incorrect. Après plusieurs erreurs, l\'accès est suspendu 15 minutes.';
    private const MESSAGE_ADRESSE = 'Trop d\'essais depuis cette connexion. Réessayez dans 15 minutes.';

    private readonly Authentification $authentification;
    private readonly DepotComptes $comptes;

    public function __construct(
        private readonly PDO $pdo,
        Chiffrement $chiffrement,
        private readonly Horloge $horloge,
        private readonly Session $session,
        private readonly string $urlPortail,
    ) {
        $this->authentification = new Authentification($pdo, $chiffrement, $horloge);
        $this->comptes = new DepotComptes($pdo);
    }

    public function traiter(Requete $requete): Reponse
    {
        if ($requete->methode === 'POST' && !$this->formulaireAuthentique($requete)) {
            return new Reponse(403, Pages::message('Action refusée', 'Le formulaire a expiré ou ne vient pas du portail. Rechargez la page et recommencez.'));
        }

        return match ($requete->chemin) {
            '/connexion' => $this->connexion($requete),
            '/connexion/code' => $this->code($requete),
            '/connexion/activation' => $this->activation($requete),
            '/mot-de-passe' => $this->motDePasse($requete),
            '/deconnexion' => $this->deconnexion($requete),
            '/' => $this->accueil($requete),
            '/comptes', '/comptes/nouveau' => $this->pageConnectee($requete, Droits::GERER_COMPTES, fn (Compte $moi) => $this->comptes($requete, $moi)),
            default => preg_match('#^/comptes/([1-9][0-9]{0,9})$#', $requete->chemin, $trouve) === 1
                ? $this->pageConnectee($requete, Droits::GERER_COMPTES, fn (Compte $moi) => $this->ficheCompte($requete, $moi, (int) $trouve[1]))
                : new Reponse(404, Pages::message('Page introuvable', 'Cette page n\'existe pas.')),
        };
    }

    /**
     * Page réservée aux comptes connectés qui ont le droit demandé.
     *
     * @param callable(Compte): Reponse $page
     */
    private function pageConnectee(Requete $requete, string $droit, callable $page): Reponse
    {
        $moi = $this->compteConnecte();
        if ($moi === null) {
            return Reponse::redirection('/connexion');
        }
        if ($moi->doitChangerMotDePasse) {
            return Reponse::redirection('/mot-de-passe');
        }
        if (!Droits::peut($moi->role, $droit)) {
            (new Journal($this->pdo))->ecrire('administration', 'accès refusé', 'refus', $moi->id, $moi->nomAffiche, $requete->adresseIp, $requete->chemin);

            return new Reponse(403, Pages::refus());
        }
        $this->session->noterActivite();

        return $page($moi);
    }

    private function comptes(Requete $requete, Compte $moi): Reponse
    {
        if ($requete->chemin === '/comptes') {
            return $this->seulementGet($requete) ?? new Reponse(200, Pages::comptes($this->session->jeton(), $this->comptes->liste(), null));
        }
        if ($requete->methode !== 'POST') {
            return $this->seulementGet($requete) ?? new Reponse(200, Pages::nouveauCompte($this->session->jeton(), null, []));
        }
        $valeurs = ['identifiant' => $requete->champ('identifiant'), 'nom' => $requete->champ('nom'),
            'email' => $requete->champ('email'), 'role' => $requete->champ('role')];
        try {
            $cree = $this->gestion($moi, $requete)->creer($valeurs['identifiant'], $valeurs['nom'], $valeurs['email'], $valeurs['role']);
        } catch (ErreurSaisie $e) {
            return new Reponse(200, Pages::nouveauCompte($this->session->jeton(), $e->getMessage(), $valeurs));
        }

        return new Reponse(200, Pages::motDePasseProvisoire(trim($valeurs['identifiant']), $cree['provisoire']));
    }

    private function ficheCompte(Requete $requete, Compte $moi, int $id): Reponse
    {
        $compte = $this->comptes->parId($id);
        if ($compte === null) {
            return new Reponse(404, Pages::message('Page introuvable', 'Ce compte n\'existe pas.'));
        }
        if ($requete->methode !== 'POST') {
            return $this->seulementGet($requete) ?? new Reponse(200, Pages::ficheCompte($this->session->jeton(), $compte, $moi, null, null));
        }
        $gestion = $this->gestion($moi, $requete);
        $information = '';
        try {
            switch ($requete->champ('action')) {
                case 'modifier':
                    $gestion->modifier($id, $requete->champ('nom'), $requete->champ('email'), $requete->champ('role'));
                    $information = 'Modifications enregistrées.';
                    break;
                case 'mot_de_passe':
                    return new Reponse(200, Pages::motDePasseProvisoire($compte->identifiant, $gestion->reinitialiserMotDePasse($id)));
                case 'double_auth':
                    $gestion->reinitialiserDoubleAuth($id);
                    $information = 'Double authentification réinitialisée : le téléphone sera à relier à la prochaine connexion.';
                    break;
                case 'desactiver':
                    $gestion->desactiver($id);
                    $information = 'Compte désactivé.';
                    break;
                case 'reactiver':
                    $gestion->reactiver($id);
                    $information = 'Compte réactivé.';
                    break;
                default:
                    return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
            }
        } catch (ErreurSaisie $e) {
            return new Reponse(200, Pages::ficheCompte($this->session->jeton(), $compte, $moi, $e->getMessage(), null));
        }
        // L'administrateur qui modifie son propre compte peut avoir fermé sa propre session.
        if ($this->compteConnecte() === null) {
            return Reponse::redirection('/connexion');
        }
        $apres = $this->comptes->parId($id) ?? $compte;

        return new Reponse(200, Pages::ficheCompte($this->session->jeton(), $apres, $moi, null, $information));
    }

    private function gestion(Compte $moi, Requete $requete): GestionComptes
    {
        return new GestionComptes($this->pdo, $moi, $requete->adresseIp);
    }

    private function connexion(Requete $requete): Reponse
    {
        if ($this->compteConnecte() !== null) {
            return Reponse::redirection('/');
        }
        if ($requete->methode !== 'POST') {
            $information = $this->session->vientDExpirer() ? 'Vous avez été déconnecté après 30 minutes sans activité.' : null;

            return $this->seulementGet($requete) ?? new Reponse(200, Pages::connexion($this->session->jeton(), null, $information));
        }
        if ($this->authentification->adresseBloquee($requete->adresseIp)) {
            return new Reponse(429, Pages::connexion($this->session->jeton(), self::MESSAGE_ADRESSE, null));
        }
        $compte = $this->authentification->verifierMotDePasse($requete->champ('identifiant'), $requete->champ('mot_de_passe'), $requete->adresseIp);
        if ($compte === null) {
            return new Reponse(200, Pages::connexion($this->session->jeton(), self::MESSAGE_ECHEC, null));
        }
        $this->session->mettreEnAttente($compte->id);

        return Reponse::redirection($compte->doubleAuthActive ? '/connexion/code' : '/connexion/activation');
    }

    private function code(Requete $requete): Reponse
    {
        $compteId = $this->session->compteEnAttente();
        if ($compteId === null) {
            return Reponse::redirection('/connexion');
        }
        if ($requete->methode !== 'POST') {
            return $this->seulementGet($requete) ?? new Reponse(200, Pages::code($this->session->jeton(), null));
        }
        if ($this->authentification->adresseBloquee($requete->adresseIp)) {
            return new Reponse(429, Pages::code($this->session->jeton(), self::MESSAGE_ADRESSE));
        }
        $compte = $this->authentification->verifierCode($compteId, $requete->champ('code'), $requete->adresseIp);
        if ($compte === null) {
            return $this->echecDeuxiemeEtape($compteId);
        }

        return $this->connexionComplete($compte);
    }

    private function activation(Requete $requete): Reponse
    {
        $compteId = $this->session->compteEnAttente();
        $compte = $compteId === null ? null : $this->comptes->parId($compteId);
        if ($compteId === null || $compte === null || $compte->doubleAuthActive) {
            return Reponse::redirection('/connexion');
        }
        $secret = $this->session->secretInscription();
        if ($secret === null) {
            $secret = $this->authentification->nouveauSecretChiffre($compteId);
            $this->session->garderSecretInscription($secret);
        }
        if ($requete->methode !== 'POST') {
            return $this->seulementGet($requete)
                ?? new Reponse(200, Pages::activation($this->session->jeton(), $this->authentification->secretLisible($compteId, $secret), null));
        }
        $active = $this->authentification->activer($compteId, $secret, $requete->champ('code'), $requete->adresseIp);
        if ($active === null) {
            $bloque = $this->echecDeuxiemeEtape($compteId);
            if ($bloque->statut !== 200 || $this->session->compteEnAttente() === null) {
                return $bloque;
            }

            return new Reponse(200, Pages::activation($this->session->jeton(), $this->authentification->secretLisible($compteId, $secret),
                'Code incorrect. Vérifiez que l\'heure de votre téléphone est réglée automatiquement.'));
        }

        return $this->connexionComplete($active);
    }

    private function motDePasse(Requete $requete): Reponse
    {
        $compte = $this->compteConnecte();
        if ($compte === null) {
            return Reponse::redirection('/connexion');
        }
        $this->session->noterActivite();
        if ($requete->methode !== 'POST') {
            return $this->seulementGet($requete)
                ?? new Reponse(200, Pages::changementMotDePasse($this->session->jeton(), null, $compte->doitChangerMotDePasse));
        }
        $refus = $this->authentification->changerMotDePasse(
            $compte->id, $requete->champ('actuel'), $requete->champ('nouveau'), $requete->champ('confirmation'), $requete->adresseIp
        );
        if ($refus !== null) {
            return new Reponse(200, Pages::changementMotDePasse($this->session->jeton(), $refus, $compte->doitChangerMotDePasse));
        }
        // Nouvel identifiant de session après un changement de mot de passe ;
        // les autres sessions de ce compte sont fermées (génération +1).
        $this->session->connecter($compte->id, $compte->generation + 1);

        return Reponse::redirection('/');
    }

    private function deconnexion(Requete $requete): Reponse
    {
        if ($requete->methode !== 'POST') {
            return new Reponse(405, Pages::message('Méthode refusée', 'Utilisez le bouton « Se déconnecter ».'), Reponse::enTetesHtml(['Allow' => 'POST']));
        }
        $compte = $this->compteConnecte();
        if ($compte !== null) {
            (new Journal($this->pdo))->ecrire('connexion', 'déconnexion', 'succes', $compte->id, $compte->nomAffiche, $requete->adresseIp);
        }
        $this->session->deconnecter();

        return Reponse::redirection('/connexion');
    }

    private function accueil(Requete $requete): Reponse
    {
        $compte = $this->compteConnecte();
        if ($compte === null) {
            return Reponse::redirection('/connexion');
        }
        if ($compte->doitChangerMotDePasse) {
            return Reponse::redirection('/mot-de-passe');
        }
        $this->session->noterActivite();

        return $this->seulementGet($requete) ?? new Reponse(200, Pages::accueil($this->session->jeton(), $compte));
    }

    private function connexionComplete(Compte $compte): Reponse
    {
        $this->session->connecter($compte->id, $compte->generation);

        return Reponse::redirection($compte->doitChangerMotDePasse ? '/mot-de-passe' : '/');
    }

    /** Après un code faux : retour au début si le compte vient d'être bloqué. */
    private function echecDeuxiemeEtape(int $compteId): Reponse
    {
        $compte = $this->comptes->parId($compteId);
        if ($compte === null || !$compte->actif || $compte->estBloque($this->horloge->maintenant())) {
            $this->session->oublierAttente();

            return new Reponse(200, Pages::connexion($this->session->jeton(), self::MESSAGE_ECHEC, null));
        }

        return new Reponse(200, Pages::code($this->session->jeton(), self::MESSAGE_ECHEC));
    }

    /** Compte connecté, encore actif et non bloqué ; sinon la session est fermée. */
    private function compteConnecte(): ?Compte
    {
        $id = $this->session->compteConnecte();
        if ($id === null) {
            return null;
        }
        $compte = $this->comptes->parId($id);
        // Compte supprimé, désactivé, ou modifié depuis la connexion (rôle, réinitialisation) : session fermée.
        if ($compte === null || !$compte->actif || $compte->generation !== $this->session->generation()) {
            $this->session->deconnecter();

            return null;
        }

        return $compte;
    }

    private function formulaireAuthentique(Requete $requete): bool
    {
        if ($requete->origine !== null && $requete->origine !== rtrim($this->urlPortail, '/')) {
            return false;
        }

        return $this->session->jetonValide($requete->champ('jeton'));
    }

    private function seulementGet(Requete $requete): ?Reponse
    {
        if ($requete->methode === 'GET' || $requete->methode === 'HEAD') {
            return null;
        }

        return new Reponse(405, Pages::message('Méthode refusée', 'Cette action n\'est pas permise ici.'), Reponse::enTetesHtml(['Allow' => 'GET, HEAD, POST']));
    }
}
