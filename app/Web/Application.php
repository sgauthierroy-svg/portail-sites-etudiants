<?php

declare(strict_types=1);

namespace Portail\Web;

use PDO;
use Portail\Alertes\Alertes;
use Portail\Authentification\Authentification;
use Portail\Bibliotheque\Bibliotheque;
use Portail\Comptes\Compte;
use Portail\Comptes\DepotComptes;
use Portail\Comptes\Droits;
use Portail\Comptes\GestionComptes;
use Portail\Etudiants\GestionEtudiants;
use Portail\Etudiants\Identifiant;
use Portail\Etudiants\ImportEtudiants;
use Portail\Etudiants\RegimesSessions;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\Horloge;
use Portail\Sites\PreparationPrestaShop;
use Portail\Sites\Releve;
use Portail\Travaux\FileTravaux;

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

    private readonly Alertes $alertes;

    public function __construct(
        private readonly PDO $pdo,
        Chiffrement $chiffrement,
        private readonly Horloge $horloge,
        private readonly Session $session,
        private readonly string $urlPortail,
        ?Alertes $alertes = null,
        private readonly ?string $dossierDonnees = null,
    ) {
        // Sans expéditeur (tests), les alertes sont préparées mais jamais envoyées.
        $this->alertes = $alertes ?? new Alertes($pdo, null, $urlPortail);
        $this->authentification = new Authentification($pdo, $chiffrement, $horloge, $this->alertes);
        $this->comptes = new DepotComptes($pdo);
    }

    public function traiter(Requete $requete): Reponse
    {
        Pages::sansCadre();
        if ($requete->tropGros) {
            return new Reponse(413, Pages::message('Fichier trop gros',
                'Le serveur refuse les envois de cette taille par la page. Déposez le fichier avec le gestionnaire de fichiers de CPanel, '
                . 'dans portail/data/depot, puis enregistrez-le depuis la bibliothèque ou les documents.'));
        }
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
            '/etudiants' => $this->pageConnectee($requete, Droits::CONSULTER, fn (Compte $moi) => $this->etudiants($requete, $moi)),
            '/etudiants/import' => $this->pageConnectee($requete, Droits::IMPORTER, fn (Compte $moi) => $this->importEtudiants($requete, $moi)),
            '/etudiants/nouveau' => $this->pageConnectee($requete, Droits::CREER_ETUDIANT, fn (Compte $moi) => $this->nouvelEtudiant($requete, $moi)),
            '/journal' => $this->pageConnectee($requete, Droits::JOURNAL, fn (Compte $moi) => $this->journal($requete, $moi)),
            '/sites' => $this->pageConnectee($requete, Droits::CONSULTER, fn (Compte $moi) => $this->voirTout($requete, $moi)),
            '/sites/prestashop' => $this->pageConnectee($requete, Droits::CREER_SITE, fn (Compte $moi) => $this->creerPrestaShop($requete, $moi)),
            '/bibliotheque' => $this->pageConnectee($requete, Droits::BIBLIOTHEQUE, fn (Compte $moi) => $this->bibliotheque($requete, $moi)),
            '/documents' => $this->pageConnectee($requete, Droits::DOCUMENTS, fn (Compte $moi) => $this->documents($requete, $moi)),
            '/travaux' => $this->pageConnectee($requete, Droits::CONSULTER, fn (Compte $moi) => $this->travaux($requete, $moi)),
            '/regimes-sessions' => $this->pageConnectee($requete, Droits::REGIMES_SESSIONS, fn (Compte $moi) => $this->regimesSessions($requete, $moi)),
            default => $this->pageNumerotee($requete),
        };
    }

    private function pageNumerotee(Requete $requete): Reponse
    {
        if (preg_match('#^/comptes/([1-9][0-9]{0,9})$#', $requete->chemin, $trouve) === 1) {
            return $this->pageConnectee($requete, Droits::GERER_COMPTES, fn (Compte $moi) => $this->ficheCompte($requete, $moi, (int) $trouve[1]));
        }
        if (preg_match('#^/etudiants/([1-9][0-9]{0,9})$#', $requete->chemin, $trouve) === 1) {
            return $this->pageConnectee($requete, Droits::VOIR_FICHE, fn (Compte $moi) => $this->ficheEtudiant($requete, $moi, (int) $trouve[1]));
        }

        if (preg_match('#^/documents/([1-9][0-9]{0,9})$#', $requete->chemin, $trouve) === 1) {
            return $this->pageConnectee($requete, Droits::DOCUMENTS, fn (Compte $moi) => $this->telechargerDocument($requete, (int) $trouve[1]));
        }
        if (preg_match('#^/travaux/([1-9][0-9]{0,9})$#', $requete->chemin, $trouve) === 1) {
            return $this->pageConnectee($requete, Droits::CONSULTER, fn (Compte $moi) => $this->travail($requete, $moi, (int) $trouve[1]));
        }

        return new Reponse(404, Pages::message('Page introuvable', 'Cette page n\'existe pas.'));
    }

    // ===== V2, lot 3 : boutiques PrestaShop =====

    private function creerPrestaShop(Requete $requete, Compte $moi): Reponse
    {
        $preparation = new PreparationPrestaShop($this->pdo);
        $sessionChoisie = null;
        $erreur = null;
        if ($requete->methode === 'POST') {
            $sessionChoisie = self::idFacultatif($requete->champ('session'));
            switch ($requete->champ('action')) {
                case 'afficher':
                    break;
                case 'lancer':
                    try {
                        $id = $preparation->lancer($sessionChoisie ?? 0, self::idFacultatif($requete->champ('paquet')),
                            $requete->liste('coches'), $moi, $requete->adresseIp);

                        return Reponse::redirection('/travaux/' . $id);
                    } catch (ErreurSaisie $e) {
                        $erreur = $e->getMessage();
                    }
                    break;
                default:
                    return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
            }
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }

        return new Reponse(200, PagesSites::creerPrestaShop($this->session->jeton(), $preparation->sessions(), $preparation->paquets(), $sessionChoisie,
            $sessionChoisie === null ? null : $preparation->etudiants($sessionChoisie), Releve::dernier($this->pdo), $erreur));
    }

    // ===== V2, lot 2 : bibliothèque et documents =====

    private function bibliotheque(Requete $requete, Compte $moi): Reponse
    {
        if ($this->dossierDonnees === null) {
            return new Reponse(503, Pages::message('Bibliothèque indisponible', 'Dossier des données non configuré.'));
        }
        $bibliotheque = new Bibliotheque($this->pdo, $this->dossierDonnees, $moi, $requete->adresseIp);
        $erreur = null;
        $information = null;
        if ($requete->methode === 'POST') {
            try {
                switch ($requete->champ('action')) {
                    case 'deposer':
                        $this->avecFichierEnvoye($requete, fn (string $chemin, string $nomOrigine) => $bibliotheque->ajouterPaquet(
                            $chemin, $nomOrigine, $requete->champ('type'), $requete->champ('nom'), $requete->champ('version')));
                        $information = 'Paquet ajouté à la bibliothèque.';
                        break;
                    case 'enregistrer_depot':
                        $nom = $requete->champ('fichier_depot');
                        $bibliotheque->ajouterPaquet($bibliotheque->cheminDepot($nom, 'zip'), $nom, $requete->champ('type'), $requete->champ('nom'), $requete->champ('version'));
                        $information = 'Paquet enregistré depuis le dossier de dépôt.';
                        break;
                    case 'retirer':
                        $this->confirmationObligatoire($requete);
                        $bibliotheque->retirerPaquet(self::idFacultatif($requete->champ('id')) ?? 0);
                        $information = 'Paquet retiré de la bibliothèque.';
                        break;
                    default:
                        return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
                }
            } catch (ErreurSaisie $e) {
                $erreur = $e->getMessage();
            }
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }

        return new Reponse(200, PagesBibliotheque::bibliotheque($this->session->jeton(), $moi, $bibliotheque, $bibliotheque->paquets(),
            $bibliotheque->depot(), self::limiteEnvoi(), $erreur, $information));
    }

    private function documents(Requete $requete, Compte $moi): Reponse
    {
        if ($this->dossierDonnees === null) {
            return new Reponse(503, Pages::message('Documents indisponibles', 'Dossier des données non configuré.'));
        }
        $bibliotheque = new Bibliotheque($this->pdo, $this->dossierDonnees, $moi, $requete->adresseIp);
        $erreur = null;
        $information = null;
        if ($requete->methode === 'POST') {
            try {
                switch ($requete->champ('action')) {
                    case 'deposer':
                        $this->avecFichierEnvoye($requete, fn (string $chemin) => $bibliotheque->ajouterDocument($chemin, $requete->champ('titre')));
                        $information = 'Document ajouté.';
                        break;
                    case 'enregistrer_depot':
                        $bibliotheque->ajouterDocument($bibliotheque->cheminDepot($requete->champ('fichier_depot'), 'pdf'), $requete->champ('titre'));
                        $information = 'Document enregistré depuis le dossier de dépôt.';
                        break;
                    case 'retirer':
                        $this->confirmationObligatoire($requete);
                        $bibliotheque->retirerDocument(self::idFacultatif($requete->champ('id')) ?? 0);
                        $information = 'Document retiré.';
                        break;
                    default:
                        return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
                }
            } catch (ErreurSaisie $e) {
                $erreur = $e->getMessage();
            }
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }

        return new Reponse(200, PagesBibliotheque::documents($this->session->jeton(), $moi, $bibliotheque, $bibliotheque->documents(),
            $bibliotheque->depot(), self::limiteEnvoi(), $erreur, $information));
    }

    private function telechargerDocument(Requete $requete, int $id): Reponse
    {
        if (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }
        $document = $this->dossierDonnees === null ? null : (new Bibliotheque($this->pdo, $this->dossierDonnees))->lireDocument($id);
        if ($document === null) {
            return new Reponse(404, Pages::message('Document introuvable', 'Ce document n\'existe pas.'));
        }
        $nomFichier = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $document['titre'])), '-');

        // Téléchargé et non affiché dans la page : un PDF ne s'exécute jamais avec les droits du portail.
        return new Reponse(200, $document['contenu'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . ($nomFichier === '' ? 'document' : substr($nomFichier, 0, 80)) . '.pdf"',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }

    /**
     * Fichier envoyé par la page : d'abord rapatrié dans data/tmp (move_uploaded_file, seule
     * opération permise sur le dossier temporaire du serveur), puis confié à $action, qui le
     * déplace à sa place définitive. Ce qui reste dans data/tmp est effacé dans tous les cas.
     *
     * @param \Closure(string, string): mixed $action Chemin dans data/tmp, nom d'origine
     */
    private function avecFichierEnvoye(Requete $requete, \Closure $action): void
    {
        $fichier = $requete->fichier('fichier');
        if (!is_array($fichier)) {
            throw new ErreurSaisie(match ($fichier) {
                null => 'Choisissez un fichier.',
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop gros pour un envoi par la page : utilisez le dépôt par CPanel.',
                default => 'L\'envoi du fichier a été interrompu : recommencez.',
            });
        }
        if ($this->dossierDonnees === null) {
            throw new ErreurSaisie('Dossier des données non configuré.');
        }
        $local = $this->dossierDonnees . '/tmp/envoi-' . bin2hex(random_bytes(12));
        if (!move_uploaded_file($fichier['chemin'], $local)) {
            throw new ErreurSaisie('Le fichier n\'a pas pu être reçu sur le serveur : recommencez, ou utilisez le dépôt par CPanel.');
        }
        try {
            $action($local, $fichier['nom']);
        } finally {
            if (is_file($local)) {
                unlink($local); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
        }
    }

    private function confirmationObligatoire(Requete $requete): void
    {
        if ($requete->champ('confirmer') !== '1') {
            throw new ErreurSaisie('Cochez « définitivement » pour confirmer le retrait.');
        }
    }

    /** Taille maximale d'un envoi par la page, d'après les réglages PHP du serveur (0 : pas de limite). */
    private static function limiteEnvoi(): int
    {
        $envoi = Requete::octets((string) ini_get('upload_max_filesize'));
        $formulaire = Requete::octets((string) ini_get('post_max_size'));
        if ($envoi === 0 || $formulaire === 0) {
            return max($envoi, $formulaire);
        }

        return min($envoi, $formulaire);
    }

    // ===== V2, lot 1 : « Voir tout » et travaux en arrière-plan =====

    private function voirTout(Requete $requete, Compte $moi): Reponse
    {
        if ($requete->methode === 'POST') {
            if ($requete->champ('action') !== 'filtrer') {
                return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
            }
            $regime = self::idFacultatif($requete->champ('regime'));
            $session = self::idFacultatif($requete->champ('session'));
            $this->comptes->enregistrerFiltre($moi->id, $regime === null && $session === null ? null
                : 'regime=' . ($regime ?? '') . ';session=' . ($session ?? ''));

            return Reponse::redirection('/sites');
        }
        if (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }
        [$regime, $session] = self::lireFiltre($this->comptes->dernierFiltre($moi->id));
        $referentiel = new RegimesSessions($this->pdo);
        $file = new FileTravaux($this->pdo);

        return new Reponse(200, PagesTravaux::voirTout($this->session->jeton(), $moi, (new GestionEtudiants($this->pdo))->liste($regime, $session),
            Releve::parIdentifiant($this->pdo), Releve::identifiants($this->pdo), Releve::dernier($this->pdo), $file->dejaPrevu('releve'),
            $referentiel->regimes(), $referentiel->sessions(), $regime, $session, null, Releve::horsGel($this->pdo)));
    }

    private function travaux(Requete $requete, Compte $moi): Reponse
    {
        $file = new FileTravaux($this->pdo);
        $erreur = null;
        $information = null;
        if ($requete->methode === 'POST') {
            if ($requete->champ('action') !== 'actualiser_releve') {
                return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
            }
            if (!Droits::peut($moi->role, Droits::ACTUALISER_RELEVE)) {
                return $this->refuser($requete, $moi);
            }
            $prevu = $file->dejaPrevu('releve');
            if ($prevu !== null) {
                $erreur = 'Un relevé est déjà prévu (travail n° ' . $prevu . ') : inutile d\'en demander un deuxième.';
            } else {
                $id = $file->demander('releve', [], [['cible' => 'serveur', 'libelle' => 'Relevé des sous-domaines et des sites']], $moi, $requete->adresseIp);
                $information = 'Relevé demandé (travail n° ' . $id . '). Il démarre dans la minute.';
            }
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }
        if ($file->actifs() > 0) {
            Pages::$rafraichir = 15;
        }

        return new Reponse(200, PagesTravaux::liste($this->session->jeton(), $moi, $file->lister(), Releve::dernier($this->pdo),
            $file->dejaPrevu('releve'), $erreur, $information));
    }

    private function travail(Requete $requete, Compte $moi, int $id): Reponse
    {
        $file = new FileTravaux($this->pdo);
        $travail = $file->detail($id);
        if ($travail === null) {
            return new Reponse(404, Pages::message('Travail introuvable', 'Ce travail n\'existe pas.'));
        }
        $erreur = null;
        $information = null;
        if ($requete->methode === 'POST') {
            if ($requete->champ('action') !== 'annuler') {
                return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
            }
            if (!$file->peutAnnuler($travail['etat'], $travail['demande_par'], $moi)) {
                return $this->refuser($requete, $moi);
            }
            $annulees = $file->annuler($id, $moi, $requete->adresseIp);
            $information = $annulees . ' étape(s) annulée(s).';
            $travail = $file->detail($id) ?? $travail;
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }
        if (in_array($travail['etat'], ['en_attente', 'en_cours'], true)) {
            Pages::$rafraichir = 15;
        }

        return new Reponse(200, PagesTravaux::detail($this->session->jeton(), $travail, $file->peutAnnuler($travail['etat'], $travail['demande_par'], $moi), $erreur, $information));
    }

    // ===== Étudiants, régimes et sessions (lot 4) =====

    private function etudiants(Requete $requete, Compte $moi): Reponse
    {
        if ($requete->methode === 'POST') {
            if ($requete->champ('action') !== 'filtrer') {
                return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
            }
            $regime = self::idFacultatif($requete->champ('regime'));
            $session = self::idFacultatif($requete->champ('session'));
            $this->comptes->enregistrerFiltre($moi->id, $regime === null && $session === null ? null
                : 'regime=' . ($regime ?? '') . ';session=' . ($session ?? ''));

            return Reponse::redirection('/etudiants');
        }
        [$regime, $session] = self::lireFiltre($this->comptes->dernierFiltre($moi->id));
        $referentiel = new RegimesSessions($this->pdo);

        return $this->seulementGet($requete) ?? new Reponse(200, PagesEtudiants::liste(
            $this->session->jeton(), $moi, (new GestionEtudiants($this->pdo))->liste($regime, $session),
            $referentiel->regimes(), $referentiel->sessions(), $regime, $session, $this->horloge->maintenant()
        ));
    }

    private function nouvelEtudiant(Requete $requete, Compte $moi): Reponse
    {
        $referentiel = new RegimesSessions($this->pdo);
        $regimes = $referentiel->regimes(true);
        $sessions = $referentiel->sessions(true);
        $jeton = $this->session->jeton();
        if ($requete->methode !== 'POST') {
            return $this->seulementGet($requete) ?? new Reponse(200, PagesEtudiants::nouveau($jeton, $regimes, $sessions, [], null));
        }
        $valeurs = [];
        foreach (['nom', 'prenom', 'email', 'regime', 'session', 'identifiant'] as $champ) {
            $valeurs[$champ] = $requete->champ($champ);
        }
        $gestion = new GestionEtudiants($this->pdo, $moi, $requete->adresseIp);
        $regimeId = self::idFacultatif($valeurs['regime']) ?? 0;
        $sessionId = self::idFacultatif($valeurs['session']) ?? 0;
        $action = $requete->champ('action');
        if ($action === 'corriger') {
            return new Reponse(200, PagesEtudiants::nouveau($jeton, $regimes, $sessions, $valeurs, null));
        }
        if ($action !== 'apercu' && $action !== 'enregistrer') {
            return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
        }
        try {
            $nomComplet = GestionEtudiants::nomComplet($valeurs['nom'], $valeurs['prenom']);
            if (!$referentiel->regimeActif($regimeId) || !$referentiel->sessionActive($sessionId)) {
                throw new ErreurSaisie('Choisissez un régime actif et une session active.');
            }
            $classe = $referentiel->nomRegime($regimeId) . ' – session ' . $referentiel->anneeSession($sessionId);
            if ($action === 'apercu') {
                $proposition = $gestion->proposer($valeurs['nom'], $valeurs['prenom']);
                $valeurs['identifiant'] = $proposition['identifiant'];

                return new Reponse(200, PagesEtudiants::apercu($jeton, $valeurs, $nomComplet, $classe, $proposition['identifiant_wp'], null));
            }
        } catch (ErreurSaisie $e) {
            return new Reponse(200, PagesEtudiants::nouveau($jeton, $regimes, $sessions, $valeurs, $e->getMessage()));
        }
        try {
            $id = $gestion->ajouter($valeurs['nom'], $valeurs['prenom'], $valeurs['email'], $regimeId, $sessionId, $valeurs['identifiant']);
        } catch (ErreurSaisie $e) {
            $wp = Identifiant::formatValide(trim($valeurs['identifiant'])) ? Identifiant::wordpress(trim($valeurs['identifiant'])) : '';

            return new Reponse(200, PagesEtudiants::apercu($jeton, $valeurs, $nomComplet, $classe, $wp, $e->getMessage()));
        }

        return Reponse::redirection('/etudiants/' . $id);
    }

    private function importEtudiants(Requete $requete, Compte $moi): Reponse
    {
        $releve = Releve::dernier($this->pdo);
        $jeton = $this->session->jeton();
        if ($requete->methode !== 'POST') {
            return $this->seulementGet($requete) ?? new Reponse(200, PagesEtudiants::import($jeton, $moi, '', null, null, null, $releve));
        }
        $texte = $requete->champ('texte');
        $import = new ImportEtudiants($this->pdo, $moi, $requete->adresseIp);
        try {
            switch ($requete->champ('action')) {
                case 'apercu':
                    return new Reponse(200, PagesEtudiants::import($jeton, $moi, $texte, $import->analyser($texte), null, null, $releve));
                case 'importer':
                    $coches = [];
                    foreach ($requete->liste('coches') as $valeur) {
                        if (preg_match('/^[1-9][0-9]{0,3}$/D', $valeur) === 1) {
                            $coches[] = (int) $valeur;
                        }
                    }

                    return new Reponse(200, PagesEtudiants::import($jeton, $moi, $texte, null, null, $import->importer($texte, $coches), $releve));
                default:
                    return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
            }
        } catch (ErreurSaisie $e) {
            return new Reponse(200, PagesEtudiants::import($jeton, $moi, $texte, null, $e->getMessage(), null, $releve));
        }
    }

    private function ficheEtudiant(Requete $requete, Compte $moi, int $id): Reponse
    {
        $gestion = new GestionEtudiants($this->pdo, $moi, $requete->adresseIp);
        $etudiant = $gestion->parId($id);
        if ($etudiant === null) {
            return new Reponse(404, Pages::message('Page introuvable', 'Cet étudiant n\'existe pas.'));
        }
        $erreur = null;
        $information = null;
        if ($requete->methode === 'POST') {
            try {
                switch ($requete->champ('action')) {
                    case 'coordonnees':
                        if (!Droits::peut($moi->role, Droits::MODIFIER_ETUDIANT)) {
                            return $this->refuser($requete, $moi);
                        }
                        $gestion->modifierCoordonnees($id, $requete->champ('nom_complet'), $requete->champ('email'));
                        $information = 'Modifications enregistrées.';
                        break;
                    case 'parcours':
                        $regimeId = self::idFacultatif($requete->champ('regime')) ?? 0;
                        $sessionId = self::idFacultatif($requete->champ('session')) ?? 0;
                        // Régime (cas 4) et session (redoublement, cas 5 à 7) : enseignant,
                        // administratif et administrateur (décision du 07/11/2026). Les deux droits
                        // restent distincts pour pouvoir les séparer plus tard sans toucher aux pages.
                        if (!Droits::peut($moi->role, Droits::MODIFIER_ETUDIANT)
                            || ($sessionId !== $etudiant->sessionId && !Droits::peut($moi->role, Droits::CHANGER_SESSION))) {
                            return $this->refuser($requete, $moi);
                        }
                        $gestion->changerParcours($id, $regimeId, $sessionId);
                        $information = 'Changement enregistré dans le parcours de l\'étudiant. Les sites n\'ont pas été modifiés.';
                        break;
                    default:
                        return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
                }
            } catch (ErreurSaisie $e) {
                $erreur = $e->getMessage();
            }
            $etudiant = $gestion->parId($id) ?? $etudiant;
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }
        $referentiel = new RegimesSessions($this->pdo);

        return new Reponse(200, PagesEtudiants::fiche($this->session->jeton(), $moi, $etudiant, $referentiel->regimes(), $referentiel->sessions(),
            $gestion->historique($etudiant->identifiant), $this->horloge->maintenant(), $erreur, $information,
            (new PreparationPrestaShop($this->pdo))->sitesDe($etudiant->identifiant)));
    }

    private function regimesSessions(Requete $requete, Compte $moi): Reponse
    {
        $referentiel = new RegimesSessions($this->pdo, $moi, $requete->adresseIp);
        $erreur = null;
        $information = null;
        if ($requete->methode === 'POST') {
            $id = self::idFacultatif($requete->champ('id')) ?? 0;
            try {
                switch ($requete->champ('action')) {
                    case 'creer_regime':
                        $referentiel->creerRegime($requete->champ('nom'));
                        $information = 'Régime créé.';
                        break;
                    case 'desactiver_regime':
                    case 'reactiver_regime':
                        $referentiel->changerRegimeActif($id, $requete->champ('action') === 'reactiver_regime');
                        $information = 'Régime mis à jour.';
                        break;
                    case 'creer_session':
                        $referentiel->creerSession($requete->champ('annee'));
                        $information = 'Session créée.';
                        break;
                    case 'archiver_session':
                    case 'reactiver_session':
                        $referentiel->changerSessionActive($id, $requete->champ('action') === 'reactiver_session');
                        $information = 'Session mise à jour.';
                        break;
                    case 'version':
                        $referentiel->gelerVersion($id, $requete->champ('version'));
                        $information = 'Version WordPress enregistrée.';
                        break;
                    default:
                        return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
                }
            } catch (ErreurSaisie $e) {
                $erreur = $e->getMessage();
            }
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }

        return new Reponse(200, PagesEtudiants::regimesSessions($this->session->jeton(), $referentiel->regimes(), $referentiel->sessions(), $erreur, $information));
    }

    private function journal(Requete $requete, Compte $moi): Reponse
    {
        if ($requete->methode !== 'POST' && ($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }
        if ($requete->methode === 'POST' && $requete->champ('action') !== 'filtrer') {
            return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
        }
        // Connexions (adresses IP, échecs) : administrateur et direction seulement (décision du 07/11/2026).
        $voirConnexions = Droits::peut($moi->role, Droits::JOURNAL_CONNEXIONS);
        $autorisees = $voirConnexions ? Journal::LIBELLES : array_diff_key(Journal::LIBELLES, ['connexion' => true]);
        $choisie = $requete->champ('categorie');
        $categories = isset($autorisees[$choisie]) ? [$choisie] : array_keys($autorisees);
        $avant = self::idFacultatif($requete->champ('avant'));
        $entrees = (new Journal($this->pdo))->lister(array_map('strval', $categories), $avant, 101);
        $suite = count($entrees) > 100;

        return new Reponse(200, Pages::journal($this->session->jeton(), $autorisees, isset($autorisees[$choisie]) ? $choisie : '',
            array_slice($entrees, 0, 100), $voirConnexions, $suite));
    }

    private function refuser(Requete $requete, Compte $moi): Reponse
    {
        (new Journal($this->pdo))->ecrire('administration', 'accès refusé', 'refus', $moi->id, $moi->nomAffiche, $requete->adresseIp,
            $requete->chemin, 'action : ' . mb_substr($requete->champ('action'), 0, 40, 'UTF-8'));

        return new Reponse(403, Pages::refus());
    }

    private static function idFacultatif(string $valeur): ?int
    {
        return preg_match('/^[1-9][0-9]{0,9}$/D', $valeur) === 1 ? (int) $valeur : null;
    }

    /** @return array{?int, ?int} Régime et session du filtre enregistré */
    private static function lireFiltre(?string $filtre): array
    {
        if ($filtre === null || preg_match('/^regime=([0-9]*);session=([0-9]*)$/', $filtre, $parties) !== 1) {
            return [null, null];
        }

        return [self::idFacultatif($parties[1]), self::idFacultatif($parties[2])];
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
        Pages::cadre($moi, $this->session->jeton(), $requete->chemin);
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
            return $this->seulementGet($requete) ?? new Reponse(200, Pages::ficheCompte($this->session->jeton(), $compte, $moi, null, null, $this->comptes->revuLe($id)));
        }
        $gestion = $this->gestion($moi, $requete);
        $information = '';
        try {
            // Un compte désactivé ne peut pas se connecter : le réinitialiser ne servirait à rien
            // et laisserait croire le contraire (constat du 08/11/2026). On le réactive d'abord.
            if (!$compte->actif && in_array($requete->champ('action'), ['mot_de_passe', 'double_auth'], true)) {
                throw new ErreurSaisie('Ce compte est désactivé : réactivez-le avant de réinitialiser son mot de passe ou son code.');
            }
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
                case 'confirmer':
                    $gestion->confirmer($id, $this->horloge->maintenant());
                    $information = 'Compte confirmé : il ne sera plus signalé avant 6 mois.';
                    break;
                default:
                    return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
            }
        } catch (ErreurSaisie $e) {
            return new Reponse(200, Pages::ficheCompte($this->session->jeton(), $compte, $moi, $e->getMessage(), null, $this->comptes->revuLe($id)));
        }
        // L'administrateur qui modifie son propre compte peut avoir fermé sa propre session.
        if ($this->compteConnecte() === null) {
            return Reponse::redirection('/connexion');
        }
        $apres = $this->comptes->parId($id) ?? $compte;

        return new Reponse(200, Pages::ficheCompte($this->session->jeton(), $apres, $moi, null, $information, $this->comptes->revuLe($id)));
    }

    private function gestion(Compte $moi, Requete $requete): GestionComptes
    {
        return new GestionComptes($this->pdo, $moi, $requete->adresseIp, $this->alertes);
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
                ?? new Reponse(200, Pages::activation($this->session->jeton(), $this->authentification->secretLisible($compteId, $secret), $compte->identifiant, null));
        }
        $active = $this->authentification->activer($compteId, $secret, $requete->champ('code'), $requete->adresseIp);
        if ($active === null) {
            $bloque = $this->echecDeuxiemeEtape($compteId);
            if ($bloque->statut !== 200 || $this->session->compteEnAttente() === null) {
                return $bloque;
            }

            return new Reponse(200, Pages::activation($this->session->jeton(), $this->authentification->secretLisible($compteId, $secret), $compte->identifiant,
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
        if (!$compte->doitChangerMotDePasse) {
            Pages::cadre($compte, $this->session->jeton(), $requete->chemin);
        }
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

        Pages::cadre($compte, $this->session->jeton(), $requete->chemin);
        $maintenant = $this->horloge->maintenant();
        $aRevoir = Droits::peut($compte->role, Droits::GERER_COMPTES)
            ? (new GestionComptes($this->pdo, $compte, $requete->adresseIp))->aRevoir($maintenant) : [];
        // Les actions de secours de la direction sont signalées aux administrateurs (30 derniers jours).
        $secours = $compte->role === 'administrateur'
            ? (new Journal($this->pdo))->recentes(GestionComptes::ACTION_SECOURS, gmdate('Y-m-d\\TH:i:s\\Z', $maintenant - 30 * 86400)) : [];

        return $this->seulementGet($requete) ?? new Reponse(200, Pages::accueil($this->session->jeton(), $compte, $aRevoir, $secours));
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
