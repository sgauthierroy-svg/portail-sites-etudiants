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
use Portail\Etudiants\Etudiant;
use Portail\Etudiants\GestionEtudiants;
use Portail\Etudiants\Identifiant;
use Portail\Etudiants\ImportEtudiants;
use Portail\Etudiants\RegimesSessions;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;
use Portail\Securite\Chiffrement;
use Portail\Securite\Horloge;
use Portail\Sites\Demandes;
use Portail\Sites\MiseALecart;
use Portail\Sites\Suppressions;
use Portail\Sites\DeploiementSujet;
use Portail\Sites\EpreuveSujet;
use Portail\Sites\GelSession;
use Portail\Sites\PreparationWordPress;
use Portail\Sites\Reinitialisations;
use Portail\Sites\SuiviCertificats;
use Portail\Sites\PreparationPrestaShop;
use Portail\Sites\Releve;
use Portail\Sites\SiteModele;
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
            '/recherche' => $this->pageConnectee($requete, Droits::CONSULTER,
                fn (Compte $moi) => new Reponse(200, Recherche::page($moi, $requete->champ('texte'), Pages::$rechercheEtudiants))),
            '/etudiants/import' => $this->pageConnectee($requete, Droits::IMPORTER, fn (Compte $moi) => $this->importEtudiants($requete, $moi)),
            '/etudiants/nouveau' => $this->pageConnectee($requete, Droits::CREER_ETUDIANT, fn (Compte $moi) => $this->nouvelEtudiant($requete, $moi)),
            '/journal' => $this->pageConnectee($requete, Droits::JOURNAL, fn (Compte $moi) => $this->journal($requete, $moi)),
            '/secours' => $this->pageConnectee($requete, Droits::GERER_COMPTES,
                fn (Compte $moi) => $this->seulementGet($requete) ?? new Reponse(200, Accueil::pageSecours())),
            '/sites' => $this->pageConnectee($requete, Droits::CONSULTER, fn (Compte $moi) => $this->voirTout($requete, $moi)),
            '/sites/prestashop' => $this->pageConnectee($requete, Droits::CREER_SITE, fn (Compte $moi) => $this->creerPrestaShop($requete, $moi)),
            '/sites/wordpress' => $this->pageConnectee($requete, Droits::CREER_SITE, fn (Compte $moi) => $this->creerWordPress($requete, $moi)),
            '/sites/certificats' => $this->pageConnectee($requete, Droits::CERTIFICATS, fn (Compte $moi) => $this->certificats($requete, $moi)),
            '/sites/sujet', '/sites/sujet/charger', '/sites/sujet/deployer', '/sites/sujet/cloturer', '/sites/sujet/gel'
                => $this->pageConnectee($requete, Droits::SUJETS_EXAMEN, fn (Compte $moi) => $this->siteModele($requete, $moi)),
            '/bibliotheque', '/bibliotheque/sujets', '/bibliotheque/versions' => $this->pageConnectee($requete, Droits::DOCUMENTS, fn (Compte $moi) => $this->bibliotheque($requete, $moi)),
            // Ancienne adresse des documents (réunis dans la bibliothèque le 11/11/2026).
            '/documents' => $this->pageConnectee($requete, Droits::DOCUMENTS, fn (Compte $moi) => Reponse::redirection('/bibliotheque')),
            '/travaux' => $this->pageConnectee($requete, Droits::CONSULTER, fn (Compte $moi) => $this->travaux($requete, $moi)),
            '/regimes-sessions' => $this->pageConnectee($requete, Droits::REGIMES_SESSIONS, fn (Compte $moi) => $this->regimesSessions($requete, $moi)),
            '/demandes', '/demandes/nouvelle' => $this->pageConnectee($requete, Droits::DEMANDER, fn (Compte $moi) => $this->demandes($requete, $moi)),
            '/sites/supprimer' => $this->pageConnectee($requete, Droits::SUPPRIMER, fn (Compte $moi) => $this->supprimerEnMasse($requete, $moi)),
            '/sites/supprimer/etudiant' => $this->pageConnectee($requete, Droits::SUPPRIMER, fn (Compte $moi) => $this->supprimerUnEtudiant($requete, $moi)),
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

    // ===== V2, lot 5 : création des WordPress /WP =====

    private function creerWordPress(Requete $requete, Compte $moi): Reponse
    {
        $preparation = new PreparationWordPress($this->pdo);
        $sessionChoisie = null;
        $erreur = null;
        if ($requete->methode === 'POST') {
            $sessionChoisie = self::idFacultatif($requete->champ('session'));
            switch ($requete->champ('action')) {
                case 'afficher':
                    break;
                case 'lancer':
                    try {
                        return Reponse::redirection('/travaux/' . $preparation->lancer($sessionChoisie ?? 0, $requete->liste('coches'), $moi, $requete->adresseIp));
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
        $sessions = $preparation->sessions();
        if ($sessionChoisie !== null && !in_array($sessionChoisie, array_column($sessions, 'id'), true)) {
            $sessionChoisie = null;
        }

        return new Reponse(200, PagesSites::creerWordPress($this->session->jeton(), $sessions, $sessionChoisie,
            $sessionChoisie === null ? null : $preparation->etudiants($sessionChoisie), Releve::dernier($this->pdo), $erreur));
    }

    // ===== Certificats https (administrateur, 14/11/2026) =====

    private function certificats(Requete $requete, Compte $moi): Reponse
    {
        $suivi = new SuiviCertificats($this->pdo);
        $erreur = null;
        $information = null;
        if ($requete->methode === 'POST') {
            try {
                switch ($requete->champ('action')) {
                    case 'verifier':
                        $suivi->demanderVerification($moi, $requete->adresseIp);
                        $information = 'Vérification demandée : le travailleur examine dix sites par minute. Rechargez la page dans quelques minutes.';
                        break;
                    case 'relancer':
                        $nombre = $suivi->relancer($requete->liste('coches'), $moi, $requete->adresseIp);
                        $information = $nombre . ' relance(s) demandée(s) : premier essai dès que le travailleur est libre, résultat dans la colonne « Relance ».';
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

        return new Reponse(200, PagesSites::certificats($this->session->jeton(), $suivi->liste(), $suivi->bilan(), $erreur, $information));
    }

    // ===== V2, lot 4 : site modèle d'examen =====

    private function siteModele(Requete $requete, Compte $moi): Reponse
    {
        $modele = new SiteModele($this->pdo);
        $deploiement = new DeploiementSujet($this->pdo);
        $epreuve = new EpreuveSujet($this->pdo);
        $sessionChoisie = null;
        $erreur = null;
        if ($requete->methode === 'POST') {
            $action = $requete->champ('action');
            if (in_array($action, ['afficher_deploiement', 'deployer', 'cloturer', 'lever_gel', 'refiger'], true)) {
                $sessionChoisie = self::idFacultatif($requete->champ('session'));
            }
            try {
                $id = match ($action) {
                    'installer' => $modele->installer(self::idFacultatif($requete->champ('paquet')), $requete->champ('confirmer') === '1', $moi, $requete->adresseIp),
                    'geler' => $modele->geler(true, $moi, $requete->adresseIp),
                    'degeler' => $modele->geler(false, $moi, $requete->adresseIp),
                    'deployer' => $deploiement->lancer($sessionChoisie ?? 0, $requete->liste('coches'), $requete->champ('confirmer') === '1', $moi, $requete->adresseIp),
                    'cloturer' => $epreuve->cloturer($sessionChoisie ?? 0, $requete->liste('coches'), $requete->champ('confirmer_cloture') === '1', $moi, $requete->adresseIp),
                    'lever_gel' => $epreuve->gel($sessionChoisie ?? 0, $requete->liste('coches'), false, $moi, $requete->adresseIp),
                    'refiger' => $epreuve->gel($sessionChoisie ?? 0, $requete->liste('coches'), true, $moi, $requete->adresseIp),
                    'afficher_deploiement' => 0,
                    default => null,
                };
                if ($id === null) {
                    return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
                }
                if ($id > 0) {
                    return Reponse::redirection('/travaux/' . $id);
                }
            } catch (ErreurSaisie $e) {
                $erreur = $e->getMessage();
            }
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }
        $sessions = $deploiement->sessions();
        if ($sessionChoisie !== null && !in_array($sessionChoisie, array_column($sessions, 'id'), true)) {
            $sessionChoisie = null;
        }

        return new Reponse(200, PagesSujet::page($this->session->jeton(), $modele->etat(), $modele->sujets(),
            MiseALecart::enCours($this->pdo, SiteModele::IDENTIFIANT),
            ['sessions' => $sessions, 'session' => $sessionChoisie, 'etudiants' => $sessionChoisie === null ? null : $deploiement->etudiants($sessionChoisie)],
            $erreur, substr($requete->chemin, strlen('/sites/sujet/')) ?: '', $modele->avancement()));
    }

    // ===== V2, lot 2 : bibliothèque et documents =====

    private function bibliotheque(Requete $requete, Compte $moi): Reponse
    {
        if ($this->dossierDonnees === null) {
            return new Reponse(503, Pages::message('Bibliothèque indisponible', 'Dossier des données non configuré.'));
        }
        $bibliotheque = new Bibliotheque($this->pdo, $this->dossierDonnees, $moi, $requete->adresseIp);
        // Documents : tous les comptes. Paquets (versions, sujets) : droit « bibliothèque ».
        $voirPaquets = Droits::peut($moi->role, Droits::BIBLIOTHEQUE);
        $erreur = null;
        $information = null;
        if ($requete->methode === 'POST') {
            $action = $requete->champ('action');
            if (in_array($action, ['deposer', 'enregistrer_depot', 'retirer'], true) && !$voirPaquets) {
                return $this->refuser($requete, $moi);
            }
            try {
                switch ($action) {
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
                    case 'deposer_document':
                        $this->avecFichierEnvoye($requete, fn (string $chemin) => $bibliotheque->ajouterDocument($chemin, $requete->champ('titre')));
                        $information = 'Document ajouté.';
                        break;
                    case 'enregistrer_depot_document':
                        $bibliotheque->ajouterDocument($bibliotheque->cheminDepot($requete->champ('fichier_depot'), 'pdf'), $requete->champ('titre'));
                        $information = 'Document enregistré depuis le dossier de dépôt.';
                        break;
                    case 'retirer_document':
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

        return new Reponse(200, PagesBibliotheque::bibliotheque($this->session->jeton(), $moi, $bibliotheque, $voirPaquets,
            $voirPaquets ? $bibliotheque->paquets() : [], $bibliotheque->documents(), $bibliotheque->depot(), self::limiteEnvoi(), $erreur, $information,
            substr($requete->chemin, strlen('/bibliotheque/')) ?: 'documents'));
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
        $erreur = null;
        $information = null;
        if ($requete->methode === 'POST') {
            try {
                $resultat = $this->actionGroupee($requete, $moi);
                if ($resultat instanceof Reponse) {
                    return $resultat;
                }
                $information = $resultat;
            } catch (ErreurSaisie $e) {
                $erreur = $e->getMessage();
            }
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }
        $file = new FileTravaux($this->pdo);

        return new Reponse(200, PagesTravaux::voirTout($this->session->jeton(), $moi, (new GestionEtudiants($this->pdo))->liste(null, null),
            Releve::parIdentifiant($this->pdo), Releve::identifiants($this->pdo), Releve::dernier($this->pdo), $file->dejaPrevu('releve'),
            $information, Releve::horsGel($this->pdo),
            (new Demandes($this->pdo))->etiquettes(), Suppressions::etudiantsSupprimes($this->pdo),
            Releve::wordpressHorsGel($this->pdo), $erreur));
    }

    /**
     * Actions groupées de la Liste des sites (administrateur) sur les étudiants cochés. Chaque action garde les droits
     * et les garde-fous de sa page d'origine. Rend le message à afficher, ou une page (confirmation, refus).
     */
    private function actionGroupee(Requete $requete, Compte $moi): Reponse|string
    {
        $coches = $requete->liste('coches');
        $action = $requete->champ('action');
        $droit = match ($action) {
            'groupe_certificats' => Droits::CERTIFICATS,
            'groupe_wordpress' => Droits::CREER_SITE,
            'groupe_supprimer', 'groupe_supprimer_confirme' => Droits::SUPPRIMER,
            default => null,
        };
        if ($droit === null) {
            return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
        }
        if (!Droits::peut($moi->role, $droit) || !Droits::peut($moi->role, Droits::CERTIFICATS)) {
            return $this->refuser($requete, $moi);
        }
        if ($coches === []) {
            throw new ErreurSaisie('Cochez au moins un étudiant dans la liste, puis choisissez l\'action.');
        }
        switch ($action) {
            case 'groupe_certificats':
                $r = (new SuiviCertificats($this->pdo))->relancerDepuisListe($coches, $moi, $requete->adresseIp);

                return $r['retenus'] . ' relance(s) de certificat demandée(s) : résultat sur la page « Certificats ».'
                    . self::passes($r['passes']);
            case 'groupe_wordpress':
                $r = (new PreparationWordPress($this->pdo))->depuisListe($coches, $moi, $requete->adresseIp);

                return $r['retenus'] . ' WordPress à créer : ' . self::listeTravaux($r['travaux']) . '.'
                    . self::passes($r['passes']);
            case 'groupe_supprimer':
                return new Reponse(200, PagesTravaux::confirmerSuppressionGroupee($this->session->jeton(),
                    (new Suppressions($this->pdo))->etudiantsCoches($coches), null));
            default:
                try {
                    $r = (new Suppressions($this->pdo))->depuisListe($coches, $requete->champ('choix'),
                        $requete->champ('confirmation'), $requete->champ('motif'), $moi, $requete->adresseIp);
                } catch (ErreurSaisie $e) {
                    return new Reponse(200, PagesTravaux::confirmerSuppressionGroupee($this->session->jeton(),
                        (new Suppressions($this->pdo))->etudiantsCoches($coches), $e->getMessage()));
                }

                return $r['retenus'] . ' suppression(s) inscrite(s), restaurables 8 jours : '
                    . self::listeTravaux($r['travaux']) . '.' . self::passes($r['passes']);
        }
    }

    /**
     * « travail n° 12 » ou « travaux n° 12, 13 ».
     *
     * @param list<int> $travaux
     */
    private static function listeTravaux(array $travaux): string
    {
        return (count($travaux) > 1 ? 'travaux n° ' : 'travail n° ') . implode(', ', $travaux);
    }

    /**
     * Fin du message d'une action groupée : les étudiants passés et pourquoi.
     *
     * @param list<string> $passes
     */
    private static function passes(array $passes): string
    {
        return $passes === [] ? '' : ' Passés : ' . implode(', ', $passes) . '.';
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
        return $this->seulementGet($requete) ?? new Reponse(200, PagesEtudiants::liste(
            $moi, (new GestionEtudiants($this->pdo))->liste(null, null), $this->horloge->maintenant()
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
                    case 'reinitialiser_wp':
                    case 'reinitialiser_presta':
                        if (!Droits::peut($moi->role, Droits::REINITIALISER_SITE)) {
                            return $this->refuser($requete, $moi);
                        }

                        return Reponse::redirection('/travaux/' . (new Reinitialisations($this->pdo))->lancer(
                            $requete->champ('action') === 'reinitialiser_wp' ? Reinitialisations::WORDPRESS : Reinitialisations::PRESTASHOP,
                            $etudiant->identifiant, $requete->champ('confirmer') === '1', $moi, $requete->adresseIp, $this->horloge->maintenant()));
                    case 'supprimer':
                        // Seul l'administrateur supprime (décision du 29/10/2026), en tapant l'identifiant pour confirmer.
                        if (!Droits::peut($moi->role, Droits::SUPPRIMER)) {
                            return $this->refuser($requete, $moi);
                        }

                        return Reponse::redirection('/travaux/' . (new Suppressions($this->pdo))->depuisFiche($etudiant->identifiant, $requete->champ('choix'),
                            $requete->champ('confirmation'), $moi, $requete->adresseIp));
                    case 'demander':
                        if (!Droits::peut($moi->role, Droits::DEMANDER)) {
                            return $this->refuser($requete, $moi);
                        }
                        $information = $this->demanderDepuisFiche($requete, $moi, $etudiant);
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
            $sites = Releve::sitesEtudiant($this->pdo, $etudiant->identifiant),
            Droits::peut($moi->role, Droits::REINITIALISER_SITE) ? (new Reinitialisations($this->pdo))->etat($etudiant->identifiant, $this->horloge->maintenant()) : null,
            $this->blocDemandes($moi, $etudiant->identifiant, '/etudiants/' . $etudiant->id, $sites),
            Releve::detailsTechniques($this->pdo, $etudiant->identifiant)));
    }

    /**
     * Fiche : demandes en attente, puis suppression directe (administrateur) ou formulaire de demande (enseignant, administratif).
     *
     * @param list<array{dossier: string, logiciel: string, version: ?string, dossier_admin: ?string, cree_par_portail: bool, date: string}> $sites
     */
    private function blocDemandes(Compte $moi, string $identifiant, string $action, array $sites): string
    {
        $demandes = new Demandes($this->pdo);
        $html = PagesDemandes::enAttenteFiche($demandes->enAttentePour($identifiant), Droits::peut($moi->role, Droits::TRAITER_DEMANDES));
        $supprimables = array_values(array_filter($sites, static fn (array $s): bool => in_array($s['dossier'], Suppressions::DOSSIERS, true)));
        if (Droits::peut($moi->role, Droits::SUPPRIMER)) {
            $requete = $this->pdo->prepare("SELECT 1 FROM travaux_etapes WHERE cible = :i AND etat IN ('en_attente', 'en_cours') LIMIT 1");
            $requete->execute([':i' => $identifiant]);

            return $html . PagesDemandes::suppressionFiche($this->session->jeton(), $action, $identifiant, $supprimables, $requete->fetchColumn() !== false);
        }
        if (!Droits::peut($moi->role, Droits::DEMANDER)) {
            return $html;
        }
        $choix = [];
        foreach ($supprimables as $site) {
            $choix['supprimer_site:' . $site['dossier']] = 'Supprimer : ' . Pages::nomSiteTexte($site['dossier']);
        }
        $choix['supprimer_etudiant'] = 'Supprimer l\'étudiant (tous ses sites et son adresse)';
        // Problème (SAV) depuis la fiche (demande du 18/11) : sur un de ses sites, ou sans site précis.
        foreach ($supprimables as $site) {
            $choix['probleme:' . $site['dossier']] = 'Signaler un problème : ' . Pages::nomSiteTexte($site['dossier']);
        }
        $choix['probleme'] = 'Signaler un problème (sans site précis)';
        if (!Droits::peut($moi->role, Droits::REINITIALISER_SITE)) {
            $dossiers = array_column($sites, 'dossier');
            if (in_array('/WP', $dossiers, true)) {
                $choix['reinitialiser_wordpress'] = 'Réinitialiser : ' . Pages::nomSiteTexte('/WP');
            }
            if (in_array('/Presta', $dossiers, true)) {
                $choix['reinitialiser_prestashop'] = 'Réinitialiser : ' . Pages::nomSiteTexte('/Presta');
            }
        }

        return $html . PagesDemandes::formulaireFiche($this->session->jeton(), $action, $choix);
    }

    /**
     * Demande envoyée depuis la fiche : suppression, réinitialisation, ou problème (SAV) sur un site ou sans site
     * précis. Rend le message de confirmation.
     */
    private function demanderDepuisFiche(Requete $requete, Compte $moi, Etudiant $etudiant): string
    {
        $demandes = new Demandes($this->pdo, $this->alertes);
        $choix = $requete->champ('choix');
        if ($choix === 'probleme' || str_starts_with($choix, 'probleme:')) {
            $site = $choix === 'probleme' ? '' : substr($choix, strlen('probleme:'));

            $saisie = ['objet' => 'probleme', 'cible' => 'etudiant', 'etudiant' => $etudiant->id, 'classe' => '',
                'site' => $site, 'texte' => $requete->champ('motif')];

            return $demandes->demander($saisie, $moi, $requete->adresseIp);
        }
        $demandes->creer($choix, $etudiant->identifiant, $requete->champ('motif'), $moi, $requete->adresseIp);

        return 'Demande envoyée au service informatique. Vous serez prévenu par e-mail de sa décision '
            . '(suivi : page « Demandes »).';
    }

    /** Supprimer un étudiant, depuis la rubrique « Sites » (administrateur, demande du 17/11/2026). */
    private function supprimerUnEtudiant(Requete $requete, Compte $moi): Reponse
    {
        $choisi = null;
        $erreur = null;
        $identifiant = '';
        if ($requete->methode === 'POST') {
            $identifiant = $requete->champ('identifiant');
            if ($requete->champ('action') === 'supprimer') {
                try {
                    return Reponse::redirection('/travaux/' . (new Suppressions($this->pdo))->depuisFiche($identifiant, $requete->champ('choix'),
                        $requete->champ('confirmation'), $moi, $requete->adresseIp));
                } catch (ErreurSaisie $e) {
                    $erreur = $e->getMessage();
                }
            } elseif ($requete->champ('action') !== 'afficher') {
                return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
            }
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }
        $etudiants = [];
        foreach ((new GestionEtudiants($this->pdo))->liste() as $etudiant) {
            $etudiants[] = ['identifiant' => $etudiant->identifiant, 'nom' => $etudiant->nomComplet, 'classe' => $etudiant->classe()];
            if ($etudiant->identifiant === $identifiant) {
                $requeteTravail = $this->pdo->prepare("SELECT 1 FROM travaux_etapes WHERE cible = :i AND etat IN ('en_attente', 'en_cours') LIMIT 1");
                $requeteTravail->execute([':i' => $identifiant]);
                $choisi = ['identifiant' => $identifiant, 'nom' => $etudiant->nomComplet, 'sites' => Releve::sitesEtudiant($this->pdo, $identifiant),
                    'prevu' => $requeteTravail->fetchColumn() !== false];
            }
        }

        return new Reponse(200, PagesDemandes::supprimerUnEtudiant($this->session->jeton(), $etudiants, $choisi, $erreur));
    }

    /** Suppression en masse par session (administrateur, demande du 16/11/2026). */
    private function supprimerEnMasse(Requete $requete, Compte $moi): Reponse
    {
        $suppressions = new Suppressions($this->pdo);
        $sessionChoisie = null;
        $erreur = null;
        if ($requete->methode === 'POST') {
            $sessionChoisie = self::idFacultatif($requete->champ('session'));
            switch ($requete->champ('action')) {
                case 'afficher':
                    break;
                case 'supprimer':
                    try {
                        $resultat = $suppressions->enMasse($sessionChoisie ?? 0, $requete->liste('coches'), $requete->champ('choix'),
                            $requete->champ('confirmation'), $requete->champ('motif'), $moi, $requete->adresseIp);

                        return Reponse::redirection('/travaux/' . $resultat['travaux'][0]);
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
        $sessions = $suppressions->sessions();
        if ($sessionChoisie !== null && !in_array($sessionChoisie, array_column($sessions, 'id'), true)) {
            $sessionChoisie = null;
        }

        return new Reponse(200, PagesDemandes::masse($this->session->jeton(), $sessions, $sessionChoisie,
            $sessionChoisie === null ? null : $suppressions->etudiantsDeSession($sessionChoisie), $erreur));
    }

    private function demandes(Requete $requete, Compte $moi): Reponse
    {
        $demandes = new Demandes($this->pdo, $this->alertes);
        $erreur = null;
        $information = null;
        if ($requete->chemin === '/demandes/nouvelle') {
            // « Faire une demande au service informatique » (demande du 18/11/2026).
            $valeurs = [];
            if ($requete->methode === 'POST') {
                if ($requete->champ('action') !== 'demander') {
                    return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
                }
                $valeurs = ['objet' => $requete->champ('objet'), 'cible' => $requete->champ('cible'), 'etudiant' => $requete->champ('etudiant'),
                    'classe' => $requete->champ('classe'), 'site' => $requete->champ('site'), 'texte' => mb_substr($requete->champ('texte'), 0, 2000, 'UTF-8')];
                try {
                    $information = $demandes->demander(['objet' => $valeurs['objet'], 'cible' => $valeurs['cible'],
                        'etudiant' => self::idFacultatif($valeurs['etudiant']), 'classe' => $valeurs['classe'], 'site' => $valeurs['site'],
                        'texte' => $valeurs['texte']], $moi, $requete->adresseIp);

                    return $this->pageDemandes($demandes, $moi, null, $information);
                } catch (ErreurSaisie $e) {
                    $erreur = $e->getMessage();
                }
            } elseif (($refus = $this->seulementGet($requete)) !== null) {
                return $refus;
            }
            $etudiants = array_map(static fn (\Portail\Etudiants\Etudiant $e): array => ['id' => $e->id, 'nom' => $e->nomComplet, 'classe' => $e->classe()],
                (new GestionEtudiants($this->pdo))->liste(null, null));

            return new Reponse(200, PagesDemandes::nouvelle($this->session->jeton(), $moi, $etudiants, $demandes->classes(), $valeurs, $erreur));
        }
        if ($requete->methode === 'POST') {
            try {
                $coches = array_map('intval', $requete->liste('coches'));
                switch ($requete->champ('action')) {
                    case 'valider':
                        if (!Droits::peut($moi->role, Droits::TRAITER_DEMANDES)) {
                            return $this->refuser($requete, $moi);
                        }
                        $resultat = $demandes->valider($coches, $requete->champ('confirmation'), $moi, $requete->adresseIp, $this->horloge->maintenant(),
                            $requete->champ('commentaire'));
                        $information = $resultat['validees'] . ' demande(s) validée(s)' . ($resultat['travaux'] === [] ? '.' : ' : travail n° '
                            . implode(', n° ', $resultat['travaux']) . ' (« Travaux en cours »).');
                        $erreur = $resultat['erreurs'] === [] ? null : 'Restées en attente : ' . implode(' ; ', $resultat['erreurs']);
                        break;
                    case 'refuser':
                        if (!Droits::peut($moi->role, Droits::TRAITER_DEMANDES)) {
                            return $this->refuser($requete, $moi);
                        }
                        $information = $demandes->refuser($coches, $requete->champ('commentaire'), $moi, $requete->adresseIp) . ' demande(s) refusée(s) : le demandeur est prévenu.';
                        break;
                    case 'annuler':
                        $demandes->annuler(self::idFacultatif($requete->champ('id')) ?? 0, $moi, $requete->adresseIp);
                        $information = 'Demande annulée.';
                        break;
                    case 'restaurer':
                        if (!Droits::peut($moi->role, Droits::SUPPRIMER)) {
                            return $this->refuser($requete, $moi);
                        }

                        return Reponse::redirection('/travaux/' . (new Suppressions($this->pdo))->restaurer($requete->champ('chemin'), $moi, $requete->adresseIp));
                    default:
                        return new Reponse(400, Pages::message('Action inconnue', 'Cette action n\'existe pas.'));
                }
            } catch (ErreurSaisie $e) {
                $erreur = $e->getMessage();
            }
        } elseif (($refus = $this->seulementGet($requete)) !== null) {
            return $refus;
        }

        return $this->pageDemandes($demandes, $moi, $erreur, $information);
    }

    private function pageDemandes(Demandes $demandes, Compte $moi, ?string $erreur, ?string $information): Reponse
    {
        $this->preparerCompteurs($moi);

        return new Reponse(200, PagesDemandes::page($this->session->jeton(), $moi,
            Droits::peut($moi->role, Droits::TRAITER_DEMANDES) ? $demandes->enAttente() : [], $demandes->de($moi->id),
            Droits::peut($moi->role, Droits::SUPPRIMER) ? MiseALecart::restaurables($this->pdo) : [], $erreur, $information));
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
                    case 'geler_wordpress':
                    case 'degeler_wordpress':
                        // Gel des /WP déjà en place (20/11/2026) : administrateur seulement.
                        if (!Droits::peut($moi->role, Droits::GELER_WORDPRESS)) {
                            return new Reponse(403, Pages::message('Accès refusé', 'Cette action est réservée au service informatique.'));
                        }
                        $geler = $requete->champ('action') === 'geler_wordpress';
                        $travail = (new GelSession($this->pdo))->demander($id, $geler, $moi, $requete->adresseIp);
                        $information = ($geler ? 'Gel' : 'Dégel') . ' des /WP prévu (travail n° ' . $travail . ') : suivez-le dans '
                            . '« Travaux », puis actualisez la liste des sites.';
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

        $gel = Droits::peut($moi->role, Droits::GELER_WORDPRESS) ? (new GelSession($this->pdo))->etat() : null;

        return new Reponse(200, PagesEtudiants::regimesSessions($this->session->jeton(), $referentiel->regimes(), $referentiel->sessions(),
            $erreur, $information, $gel));
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
        $this->preparerRecherche($moi);
        $this->preparerCompteurs($moi);
        if (!Droits::peut($moi->role, $droit)) {
            (new Journal($this->pdo))->ecrire('administration', 'accès refusé', 'refus', $moi->id, $moi->nomAffiche, $requete->adresseIp, $requete->chemin);

            return new Reponse(403, Pages::refus());
        }
        $this->session->noterActivite();

        return $page($moi);
    }

    /** Compteurs des demandes en attente du menu de l'administrateur, problèmes SAV à part. */
    private function preparerCompteurs(Compte $compte): void
    {
        $nature = Droits::peut($compte->role, Droits::TRAITER_DEMANDES)
            ? (new Demandes($this->pdo))->enAttenteParNature() : ['problemes' => 0, 'autres' => 0];
        Pages::$problemesEnAttente = $nature['problemes'];
        Pages::$demandesEnAttente = $nature['problemes'] + $nature['autres'];
    }

    /** Étudiants de la recherche rapide du bandeau (rôles qui ouvrent les fiches). */
    private function preparerRecherche(Compte $compte): void
    {
        Pages::$rechercheEtudiants = Droits::peut($compte->role, Droits::VOIR_FICHE)
            ? Recherche::etudiants($compte, (new GestionEtudiants($this->pdo))->liste(null, null)) : [];
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
                    $information = 'Compte réactivé.' . ($compte->doubleAuthActive
                        ? ' Si la personne n\'a plus le code à 6 chiffres sur son téléphone (application supprimée, téléphone changé), cliquez aussi '
                            . '« Réinitialiser le code à 6 chiffres » : elle reliera de nouveau son téléphone à sa prochaine connexion.'
                        : '');
                    break;
                case 'supprimer':
                    $gestion->supprimer($id, $requete->champ('confirmation'));

                    return new Reponse(200, Pages::message('Compte supprimé', 'Le compte « ' . $compte->identifiant
                        . ' » est supprimé. Ses actions restent dans le journal sous son nom.'));
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
            $this->preparerRecherche($compte);
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
        $this->preparerRecherche($compte);
        $this->preparerCompteurs($compte);
        if ($requete->methode === 'POST') {
            if ($requete->champ('action') !== 'secours_verifies' || $compte->role !== 'administrateur') {
                return $this->refuser($requete, $compte);
            }
            (new Journal($this->pdo))->verifierSecours(GestionComptes::ACTION_SECOURS, self::idFacultatif($requete->champ('jusqua')) ?? 0,
                $compte->nomAffiche, $compte->id, $requete->adresseIp);

            return Reponse::redirection('/');
        }
        $maintenant = $this->horloge->maintenant();
        $aRevoir = Droits::peut($compte->role, Droits::GERER_COMPTES)
            ? (new GestionComptes($this->pdo, $compte, $requete->adresseIp))->aRevoir($maintenant) : [];
        // Les actions de secours de la direction sont signalées aux administrateurs (30 derniers jours).
        $secours = $compte->role === 'administrateur'
            ? (new Journal($this->pdo))->recentes(GestionComptes::ACTION_SECOURS, gmdate('Y-m-d\\TH:i:s\\Z', $maintenant - 30 * 86400)) : [];

        $bloc = '';
        if (Droits::peut($compte->role, Droits::TRAITER_DEMANDES)) {
            // Pas de rechargement automatique : il compterait comme une activité (pas de déconnexion après 30 min).
            $bloc = AFaire::html(AFaire::lignes($this->pdo, $maintenant), (new FileTravaux($this->pdo))->enCours());
        }
        $bloc .= $this->blocDuRole($compte);

        return $this->seulementGet($requete)
            ?? new Reponse(200, Pages::accueil($this->session->jeton(), $compte, $aRevoir, $secours, $bloc));
    }

    /** Bloc de l'accueil propre au rôle (Accueil) : état des classes, gestes fréquents, demandes, secours. */
    private function blocDuRole(Compte $compte): string
    {
        $etudiants = Droits::peut($compte->role, Droits::CONSULTER)
            ? (new GestionEtudiants($this->pdo))->liste(null, null) : [];
        $actives = array_column((new RegimesSessions($this->pdo))->sessions(true), 'annee');
        $derniers = [];
        if ($compte->role === 'administratif') {
            $derniers = $etudiants;
            usort($derniers, static fn (Etudiant $a, Etudiant $b): int => strcmp($b->ajouteLe, $a->ajouteLe));
        }
        $mesDemandes = 0;
        if (Droits::peut($compte->role, Droits::DEMANDER) && !Droits::peut($compte->role, Droits::TRAITER_DEMANDES)) {
            $enAttente = static fn (array $d): bool => $d['etat'] === 'en_attente';
            $mesDemandes = count(array_filter((new Demandes($this->pdo))->de($compte->id), $enAttente));
        }
        $classes = Accueil::classes($this->pdo, $etudiants, $actives);

        return Accueil::html($compte, $classes, Releve::dernier($this->pdo), $derniers, $mesDemandes);
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
