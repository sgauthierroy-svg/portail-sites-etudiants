<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Base\Lecture;
use Portail\Courriel\Expediteur;
use Portail\Journal\Journal;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Sites\Certificats;
use Portail\Sites\ConfigWordPress;
use Portail\Sites\EpreuveWordPress;
use Portail\Sites\LigneWordPress;
use Portail\Sites\OutilsWordPress;
use Portail\Sites\PreparationWordPress;
use Portail\Sites\RelanceCertificats;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Systeme\Fichiers;
use RuntimeException;
use Throwable;

/**
 * Création du WordPress /WP d'un étudiant (V2, lot 5 ; demande du 14/11/2026). Reprend creation_wp.sh
 * (validé le 29/10/2026) et l'état relevé le 14/11/2026 sur un /WP de 1re année : WordPress en français,
 * titre « Sup », thème et extensions par défaut, permaliens simples, fuseau de Paris, un seul compte
 * administrateur (identifiant WordPress de l'étudiant), redirection http → https. Avec :
 * - le sous-domaine créé s'il manque, puis son certificat en fin d'étape (acme.sh, comme certificats.sh),
 *   relancé seul toutes les 20 minutes en cas d'échec (RelanceCertificats) ;
 * - une base et un utilisateur MySQL propres au site (décision du 07/11/2026) ;
 * - la version de la session si elle est fixée (gelée), sinon la dernière (mises à jour automatiques) ;
 * - un mot de passe tiré au hasard, jamais en ligne de commande, jamais gardé par le portail ;
 * - e-mail à l'étudiant, récapitulatif aux colonnes de la feuille pour l'administrateur ;
 * - préparation à part, puis mise en place : en cas d'échec, ce que l'étape a créé est effacé
 *   (sauf un sous-domaine créé, gardé vide), et un /WP non vide n'est jamais touché.
 */
final class ExecutantCreerWordPress implements Executant, ApresTravail
{
    private const PREFIXE = 'jafl3067_';

    /** Colonnes du récapitulatif : en-têtes exacts de la feuille des accès (donnés le 11/11/2026). */
    public const COLONNES = ['Site wordpress', 'Identifiant WP', 'Mot de passe WP'];

    public const SUJET_ETUDIANT = 'IMPORTANT – Sup\'Sallanches (BTS NDRC) : vos accès à votre site WordPress, à conserver';

    /** Redirection vers https des sites étudiants (passage_https.sh, 25/10/2026). */
    public const REDIRECTION_HTTPS = "# BEGIN HTTPS-PORTAIL\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{HTTPS} off\n"
        . "RewriteCond %{HTTP:X-Forwarded-Proto} !https\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n</IfModule>\n# END HTTPS-PORTAIL\n";

    /** Mot de passe posé sans passer par la ligne de commande (lu dans l'environnement de cette seule commande). */
    private const POSER_MOT_DE_PASSE = '$u = get_user_by("login", (string) getenv("PORTAIL_IDENTIFIANT")); '
        . 'if (!$u) { fwrite(STDERR, "compte introuvable"); exit(1); } wp_set_password((string) getenv("PORTAIL_MDP"), $u->ID);';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ServeurCpanel $cpanel,
        private readonly LigneWordPress $wp,
        private readonly Certificats $certificats,
        private readonly Chiffrement $chiffrement,
        private readonly ?Expediteur $expediteur,
        private readonly string $dossierPersonnel,
        private readonly string $dossierTemporaire,
    ) {
    }

    public function executer(string $cible, array $parametres): array
    {
        // Fichiers d'un site : lisibles par le serveur web (755/644), voir ExecutantCreerPrestaShop.
        $masque = umask(0022);
        try {
            return $this->creer($cible, $parametres);
        } finally {
            umask($masque);
        }
    }

    /**
     * @param array<array-key, mixed> $parametres
     * @return array{etat: 'reussi'|'ignore', message: string, secret?: string}
     */
    private function creer(string $cible, array $parametres): array
    {
        $etudiant = $this->etudiant($cible);
        $version = Lecture::texte($parametres['version'] ?? null);
        if ($version !== null && preg_match('/^[0-9]{1,2}\.[0-9]{1,2}(\.[0-9]{1,2})?$/D', $version) !== 1) {
            throw new RuntimeException('Version WordPress de la session illisible (' . $version . ') : rien n\'a été fait.');
        }
        $titre = Lecture::texte($parametres['titre'] ?? null) ?? PreparationWordPress::TITRE;
        $sousDomaine = $cible . '.' . Releve::DOMAINE;

        // 1. Sous-domaine (créé s'il manque), puis vérification de son dossier.
        $nouveau = false;
        if (!isset($this->cpanel->sousDomaines()[$cible])) {
            $this->cpanel->creerSousDomaine($cible);
            $nouveau = true;
            $this->pdo->prepare("INSERT OR IGNORE INTO sous_domaines (identifiant, releve_le) VALUES (:i, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))")
                ->execute([':i' => $cible]);
        }
        $racine = EpreuveWordPress::racine($this->cpanel, $this->dossierPersonnel, $cible);
        $final = $racine . PreparationWordPress::DOSSIER;
        if (is_link($final)) {
            throw new RuntimeException('/WP est un lien : refusé par prudence.');
        }
        if (Fichiers::nonVide($final)) {
            return ['etat' => 'ignore', 'message' => 'Le dossier /WP existe déjà et n\'est pas vide : rien n\'a été fait.'];
        }

        // 2. Préparation à part : WordPress, base, configuration, installation.
        $suffixe = OutilsWordPress::suffixe();
        $base = self::PREFIXE . $cible . '_WP_' . $suffixe;
        $utilisateur = self::PREFIXE . $cible . '_wp' . $suffixe;
        $motDePasseBdd = str_replace('-', '', MotsDePasse::provisoire() . MotsDePasse::provisoire());
        $motDePasse = MotsDePasse::provisoire();
        $preparation = $this->dossierTemporaire . '/wp-' . $cible . '-' . $suffixe;
        $adresse = 'https://' . $sousDomaine . PreparationWordPress::DOSSIER;
        $crees = ['base' => false, 'utilisateur' => false];
        try {
            if (!mkdir($preparation, 0755)) {
                throw new RuntimeException('Création du dossier de préparation impossible.');
            }
            $this->wp->lancer($preparation, array_merge(['core', 'download', '--locale=fr_FR'], $version === null ? [] : ['--version=' . $version]), 600);
            $installee = OutilsWordPress::versionWordPress($preparation);
            if ($installee === null) {
                throw new RuntimeException('WordPress téléchargé incomplet (version illisible).');
            }
            $this->cpanel->creerBase($base);
            $crees['base'] = true;
            $this->cpanel->creerUtilisateur($utilisateur, $motDePasseBdd);
            $crees['utilisateur'] = true;
            $this->cpanel->donnerDroits($utilisateur, $base);
            self::ecrire($preparation . '/wp-config.php', ConfigWordPress::contenu(
                ['base' => $base, 'utilisateur' => $utilisateur, 'mot_de_passe' => $motDePasseBdd, 'prefixe' => 'wp_'], ['gele' => $version !== null]), 0600);
            // Compte créé avec un mot de passe jetable, aussitôt remplacé par le vrai (lu dans l'environnement).
            $this->wp->lancer($preparation, ['core', 'install', '--url=' . $adresse, '--title=' . $titre, '--admin_user=' . $etudiant['identifiant_wp'],
                '--admin_password=' . bin2hex(random_bytes(24)), '--admin_email=' . $etudiant['email'], '--locale=fr_FR', '--skip-email']);
            $this->wp->lancer($preparation, ['eval', self::POSER_MOT_DE_PASSE], 120,
                ['PORTAIL_IDENTIFIANT' => $etudiant['identifiant_wp'], 'PORTAIL_MDP' => $motDePasse]);
            $alerte = $this->parametre('adresse_alertes');
            if ($alerte !== null) {
                $this->wp->lancer($preparation, ['option', 'update', 'admin_email', $alerte]);
            }
            $this->wp->lancer($preparation, ['option', 'update', 'timezone_string', 'Europe/Paris']);
            $this->wp->lancer($preparation, ['option', 'update', 'WPLANG', 'fr_FR']);
            self::ecrire($preparation . '/.htaccess', self::REDIRECTION_HTTPS, 0644);

            // 3. Mise en place.
            if (is_dir($final) && !rmdir($final)) {
                throw new RuntimeException('Dossier /WP vide impossible à remplacer.');
            }
            if (!rename($preparation, $final)) {
                throw new RuntimeException('Mise en place du /WP impossible.');
            }
        } catch (Throwable $e) {
            $this->defaire($crees, $preparation, $base, $utilisateur);
            throw new RuntimeException($e->getMessage() . ' Ce que cette étape avait créé a été effacé'
                . ($nouveau ? ' (le sous-domaine créé est gardé, vide).' : '.'), 0, $e);
        }

        $this->pdo->prepare(
            "INSERT INTO sites (identifiant, dossier, logiciel, version, base, utilisateur_bdd, gele)
             VALUES (:i, '/WP', 'wordpress', :v, :b, :u, :g)
             ON CONFLICT (identifiant, dossier) DO UPDATE SET logiciel = 'wordpress', version = :v2, base = :b2, utilisateur_bdd = :u2, gele = :g2,
                 dossier_admin = NULL, cloture_le = NULL, lecture_seule = 0, cree_le = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')"
        )->execute([':i' => $cible, ':v' => $installee, ':b' => $base, ':u' => $utilisateur, ':g' => $version !== null ? 1 : 0,
            ':v2' => $installee, ':b2' => $base, ':u2' => $utilisateur, ':g2' => $version !== null ? 1 : 0]);
        $this->pdo->prepare(
            "INSERT INTO releve_sites (identifiant, dossier, logiciel, version, releve_le) VALUES (:i, '/WP', 'wordpress', :v, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
             ON CONFLICT (identifiant, dossier) DO UPDATE SET logiciel = 'wordpress', version = :v2"
        )->execute([':i' => $cible, ':v' => $installee, ':v2' => $installee]);

        // 4. Certificat d'un sous-domaine neuf, à la fin : le sous-domaine a eu le temps de devenir joignable
        //    (premier essai réel du 14/11/2026 : échec juste après la création, réussite plus tard).
        //    Un échec n'empêche pas le site : le travailleur réessaie seul toutes les 20 minutes.
        $certificat = '';
        if ($nouveau) {
            try {
                $this->certificats->poser($sousDomaine, $racine);
                $certificat = ' Sous-domaine créé, certificat posé (CPanel l\'active en 30 minutes au plus).';
            } catch (Throwable $e) {
                (new RelanceCertificats($this->pdo))->inscrire($cible, $e->getMessage());
                $certificat = ' Sous-domaine créé ; certificat pas encore posé (' . mb_substr($e->getMessage(), 0, 250, 'UTF-8')
                    . ') : le portail réessaie seul toutes les 20 minutes, et alerte l\'administrateur s\'il n\'y arrive pas en 2 heures.';
            }
        }

        $site = $adresse . '/';
        $envoye = $this->ecrireEtudiant($etudiant, $site, $motDePasse, $nouveau);

        return [
            'etat' => 'reussi',
            'message' => 'WordPress ' . $installee . ' installé en https' . ($version === null ? ' (dernière version, mises à jour automatiques).' : ' (version de la session, gelée).')
                . $certificat . ' ' . ($envoye ? 'Accès envoyés à l\'étudiant.' : 'E-mail à l\'étudiant NON envoyé : accès dans le récapitulatif de l\'administrateur.'),
            'secret' => $this->chiffrement->chiffrer(implode("\t", [$cible, $site, $etudiant['identifiant_wp'], $motDePasse]), 'recapitulatif-wordpress'),
        ];
    }

    public function apresTravail(int $travailId, array $parametres): void
    {
        $requete = $this->pdo->prepare('SELECT libelle, secret FROM travaux_etapes WHERE travail_id = :t AND secret IS NOT NULL ORDER BY rang');
        $requete->execute([':t' => $travailId]);
        $lignes = [];
        $reperes = [];
        foreach (Lecture::lignes($requete) as $etape) {
            try {
                // Secret : identifiant, site, identifiant WordPress, mot de passe.
                $valeurs = explode("\t", $this->chiffrement->dechiffrer((string) Lecture::texte($etape['secret']), 'recapitulatif-wordpress'));
                $lignes[] = [$valeurs[1] ?? '', $valeurs[2] ?? '', $valeurs[3] ?? ''];
                $reperes[] = [(string) Lecture::texte($etape['libelle']), $valeurs[0]];
            } catch (Throwable) {
                $lignes[] = ['(ligne illisible)', '', ''];
                $reperes[] = [(string) Lecture::texte($etape['libelle']), ''];
            }
        }
        // Effacés dans tous les cas : le portail ne garde aucun mot de passe d'étudiant.
        $this->pdo->prepare('UPDATE travaux_etapes SET secret = NULL WHERE travail_id = :t')->execute([':t' => $travailId]);
        if ($lignes === []) {
            return;
        }
        $adresse = $this->parametre('adresse_alertes');
        $texte = 'Sites WordPress créés par le travail n° ' . $travailId . ' (' . count($lignes) . ").\n\n"
            . "Le tableau à copier dans la feuille des accès est dans la version HTML de ce message.\n"
            . "Version texte (colonnes séparées par des tabulations), dans l'ordre alphabétique des étudiants :\n"
            . implode("\t", self::COLONNES) . "\n";
        foreach ($lignes as $rang => $ligne) {
            $texte .= implode("\t", $ligne) . "\t(" . $reperes[$rang][0] . ")\n";
        }
        $texte .= "\nCe message contient des mots de passe : supprimez-le une fois la feuille mise à jour."
            . "\nLe portail ne les a pas gardés.\n\n-- \nMessage automatique du portail des sites étudiants.";
        $this->envoyer($adresse === null ? [] : [$adresse], 'Accès WordPress à reporter dans la feuille (travail n° ' . $travailId . ')', $texte,
            'récapitulatif à l\'administrateur', Recapitulatif::html('Sites WordPress créés par le travail n° ' . $travailId . ' (' . count($lignes) . ').',
                self::COLONNES, $lignes, $reperes));
    }

    /** @return array{nom: string, email: string, identifiant_wp: string} */
    private function etudiant(string $identifiant): array
    {
        $requete = $this->pdo->prepare('SELECT nom_complet, email, identifiant_wp FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            throw new RuntimeException('Étudiant inconnu du portail : rien n\'a été fait.');
        }
        $proteges = Lecture::tableau(json_decode((string) $this->parametre('sites_proteges'), true)) ?? [];
        if (in_array($identifiant, $proteges, true)) {
            throw new RuntimeException('Sous-domaine protégé : refusé.');
        }
        $identifiantWp = (string) Lecture::texte($ligne['identifiant_wp']);
        if (preg_match('/^[A-Za-z0-9]{2,16}$/D', $identifiantWp) !== 1) {
            throw new RuntimeException('Identifiant WordPress de l\'étudiant invalide : rien n\'a été fait.');
        }

        return ['nom' => (string) Lecture::texte($ligne['nom_complet']), 'email' => (string) Lecture::texte($ligne['email']), 'identifiant_wp' => $identifiantWp];
    }

    /** @param array{base: bool, utilisateur: bool} $crees */
    private function defaire(array $crees, string $preparation, string $base, string $utilisateur): void
    {
        try {
            if (is_dir($preparation) && !is_link($preparation)) {
                Fichiers::effacerDossier($preparation, $this->dossierTemporaire);
            }
            if ($crees['utilisateur']) {
                $this->cpanel->supprimerUtilisateur($utilisateur);
            }
            if ($crees['base']) {
                $this->cpanel->supprimerBase($base);
            }
        } catch (Throwable $e) {
            (new Journal($this->pdo))->ecrire('site', 'nettoyage après échec incomplet', 'echec', null, 'travailleur', null, $base, mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
        }
    }

    /** @param array{nom: string, email: string, identifiant_wp: string} $etudiant */
    private function ecrireEtudiant(array $etudiant, string $site, #[\SensitiveParameter] string $motDePasse, bool $nouveau): bool
    {
        $texte = "Bonjour,\n\n"
            . "Ce message vous est envoyé par votre établissement, Sup'Sallanches (BTS NDRC). "
            . "Il contient les accès à votre site WordPress, qui servira en cours : CONSERVEZ-LE.\n\n"
            . 'Votre site : ' . $site . "\n"
            . 'Administration du site : ' . $site . "wp-admin/\n"
            . 'Identifiant : ' . $etudiant['identifiant_wp'] . "\n"
            . 'Mot de passe : ' . $motDePasse . "\n\n"
            . ($nouveau ? "Votre site vient d'être créé : si votre navigateur affiche un avertissement de sécurité, réessayez une heure plus tard.\n" : '')
            . "Ces accès sont personnels : ne les communiquez à personne.\n"
            . "En cas de difficulté, adressez-vous à votre enseignant.\n\n"
            . "-- \nSup'Sallanches – BTS NDRC, portail des sites étudiants. Message automatique : ne pas y répondre.";

        return $this->envoyer([$etudiant['email']], self::SUJET_ETUDIANT, $texte, 'accès WordPress à l\'étudiant');
    }

    /** @param list<string> $destinataires */
    private function envoyer(array $destinataires, string $sujet, string $texte, string $quoi, ?string $html = null): bool
    {
        $journal = new Journal($this->pdo);
        try {
            if ($this->expediteur === null || $destinataires === []) {
                throw new RuntimeException('aucun expéditeur ou destinataire');
            }
            $this->expediteur->envoyer($destinataires, $sujet, $texte, $html);
            $journal->ecrire('site', 'e-mail envoyé', 'succes', null, 'travailleur', null, null, $quoi . ' → ' . implode(', ', $destinataires));

            return true;
        } catch (Throwable $e) {
            $journal->ecrire('site', 'échec d\'envoi d\'e-mail', 'echec', null, 'travailleur', null, null, $quoi . ' : ' . mb_substr($e->getMessage(), 0, 300, 'UTF-8'));

            return false;
        }
    }

    private function parametre(string $cle): ?string
    {
        $requete = $this->pdo->prepare('SELECT valeur FROM parametres WHERE cle = :c');
        $requete->execute([':c' => $cle]);

        return Lecture::texte($requete->fetchColumn());
    }

    private static function ecrire(string $fichier, string $contenu, int $droits): void
    {
        if (file_put_contents($fichier, $contenu, LOCK_EX) === false || !chmod($fichier, $droits)) {
            throw new RuntimeException('Écriture de ' . basename($fichier) . ' impossible.');
        }
    }
}
