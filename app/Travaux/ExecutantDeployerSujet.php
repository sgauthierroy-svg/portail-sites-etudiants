<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Base\Lecture;
use Portail\Courriel\Expediteur;
use Portail\Journal\Journal;
use Portail\Securite\MotsDePasse;
use Portail\Sites\AdressesWordPress;
use Portail\Sites\ConfigWordPress;
use Portail\Sites\DeploiementSujet;
use Portail\Sites\LigneWordPress;
use Portail\Sites\MiseALecart;
use Portail\Sites\OutilsWordPress;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Sites\SiteModele;
use Portail\Systeme\Fichiers;
use RuntimeException;
use Throwable;

/**
 * Déploiement du site modèle d'examen dans les /WP2 des étudiants (V2, lot 4, paquet 2 ;
 * décisions des 13 et 14/11/2026).
 *
 * Étape « sujet » : photo du modèle (fichiers et base) dans ~/portail/data/tmp/photos/, prise une
 * fois au début : tous les étudiants reçoivent exactement le même sujet, même si le modèle change
 * pendant le déploiement. Après le travail, la photo est gardée 8 jours (mise à l'écart).
 *
 * Étape étudiant : comme l'installation d'un sujet sur le modèle (ExecutantInstallerSujetModele),
 * - base et utilisateur MySQL neufs, propres à ce /WP2, mot de passe tiré au hasard écrit seulement
 *   dans wp-config.php (droits 600, écrit par le portail) ;
 * - version gelée (sujet d'examen : pas de mise à jour automatique) ;
 * - comptes et mots de passe du modèle conservés (décision du 14/11/2026 : mot de passe commun,
 *   celui du modèle ; aucun mot de passe envoyé par e-mail), sessions ouvertes du modèle effacées ;
 * - préparation à part, puis bascule : un /WP2 existant est mis à l'écart 8 jours, intact ;
 * - en cas d'échec, effacement de ce que cette étape a créé, et de rien d'autre.
 * Aucun e-mail à l'étudiant (décision du 11/11/2026) ; en fin de travail, liste des /WP2 créés à
 * l'administrateur et aux comptes enseignants.
 */
final class ExecutantDeployerSujet implements Executant, ApresTravail
{
    private const PREFIXE = 'jafl3067_';

    /** Fichiers du modèle jamais copiés : réécrits pour chaque étudiant, ou propres au serveur. */
    public const EXCLUS = ['wp-config.php', '.htaccess', 'error_log', 'wp-content/debug.log', 'wp-content/cache', 'wp-content/upgrade'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ServeurCpanel $cpanel,
        private readonly LigneWordPress $wp,
        private readonly MiseALecart $ecart,
        private readonly ?Expediteur $expediteur,
        private readonly string $dossierPersonnel,
        private readonly string $dossierTemporaire,
    ) {
    }

    public function executer(string $cible, array $parametres): array
    {
        // Fichiers d'un site : lisibles par le serveur web (voir ExecutantCreerPrestaShop).
        $masque = umask(0022);
        try {
            $photo = $this->dossierPhoto($parametres);

            return $cible === SiteModele::IDENTIFIANT ? $this->photographier($photo) : $this->deployer($cible, $photo);
        } finally {
            umask($masque);
        }
    }

    // ===== Photo du modèle =====

    /** @return array{etat: 'reussi'|'ignore', message: string} */
    private function photographier(string $photo): array
    {
        if (is_file($photo . '/complete')) {
            return ['etat' => 'ignore', 'message' => 'Photo du site modèle déjà prise.'];
        }
        $modele = $this->racine(SiteModele::IDENTIFIANT) . SiteModele::DOSSIER;
        if (!is_file($modele . '/wp-config.php') || is_link($modele . '/wp-config.php')) {
            throw new RuntimeException('Site modèle introuvable (pas de wp-config.php) : rien n\'a été fait.');
        }
        $racinePhotos = $this->dossierTemporaire . '/photos';
        if (!is_dir($racinePhotos) && !mkdir($racinePhotos, 0700)) {
            throw new RuntimeException('Création du dossier des photos impossible.');
        }
        if (is_dir($photo)) {
            Fichiers::effacerDossier($photo, $racinePhotos); // photo interrompue : reprise de zéro
        }
        try {
            if (!mkdir($photo, 0700) || !mkdir($photo . '/fichiers', 0755)) {
                throw new RuntimeException('Création du dossier de la photo impossible.');
            }
            $brute = $photo . '/export.sql';
            $this->wp->lancer($modele, ['db', 'export', $brute], 900);
            OutilsWordPress::filtrerSql([$brute], $photo . '/base.sql');
            unlink($brute); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            $prefixe = OutilsWordPress::prefixeTables($photo . '/base.sql');
            $nombre = Fichiers::copierDossier($modele, $photo . '/fichiers', self::EXCLUS);
            OutilsWordPress::nettoyer($photo . '/fichiers');
            $version = OutilsWordPress::versionWordPress($photo . '/fichiers');
            if ($version === null) {
                throw new RuntimeException('Le site modèle n\'est pas un WordPress complet (version illisible).');
            }
            $infos = json_encode(['prefixe' => $prefixe, 'version' => $version, 'prise_le' => gmdate('Y-m-d\TH:i:s\Z')], JSON_THROW_ON_ERROR);
            if (file_put_contents($photo . '/photo.json', $infos) === false || file_put_contents($photo . '/complete', '') === false) {
                throw new RuntimeException('Écriture de la photo impossible.');
            }
        } catch (Throwable $e) {
            if (is_dir($photo)) {
                Fichiers::effacerDossier($photo, $racinePhotos);
            }
            throw new RuntimeException($e->getMessage() . ' Aucun étudiant ne recevra le sujet : relancez le déploiement.', 0, $e);
        }

        return ['etat' => 'reussi', 'message' => 'Photo du site modèle prise (WordPress ' . $version . ', ' . $nombre . ' fichiers).'];
    }

    // ===== /WP2 d'un étudiant =====

    /** @return array{etat: 'reussi', message: string} */
    private function deployer(string $cible, string $photo): array
    {
        $this->verifierEtudiant($cible);
        if (!is_file($photo . '/complete')) {
            throw new RuntimeException('Photo du site modèle absente (première étape en échec ?) : rien n\'a été fait.');
        }
        $infos = Lecture::tableau(json_decode((string) file_get_contents($photo . '/photo.json'), true)) ?? [];
        $prefixe = Lecture::texte($infos['prefixe'] ?? null);
        $version = Lecture::texte($infos['version'] ?? null);
        if ($prefixe === null) {
            throw new RuntimeException('Photo du site modèle incomplète : rien n\'a été fait.');
        }
        $racine = $this->racine($cible);
        $final = $racine . DeploiementSujet::DOSSIER;
        $adresse = 'https://' . $cible . '.' . Releve::DOMAINE . DeploiementSujet::DOSSIER;

        $suffixe = OutilsWordPress::suffixe();
        $base = self::PREFIXE . $cible . '_WP2_' . $suffixe;
        $utilisateur = self::PREFIXE . $cible . '_w2' . $suffixe;
        $motDePasseBdd = str_replace('-', '', MotsDePasse::provisoire() . MotsDePasse::provisoire());
        $preparation = $this->dossierTemporaire . '/wp2-' . $cible . '-' . $suffixe;
        $crees = ['base' => false, 'utilisateur' => false];
        $miseALecart = null;
        try {
            if (!mkdir($preparation, 0755)) {
                throw new RuntimeException('Création du dossier de préparation impossible.');
            }
            Fichiers::copierDossier($photo . '/fichiers', $preparation);
            $this->cpanel->creerBase($base);
            $crees['base'] = true;
            $this->cpanel->creerUtilisateur($utilisateur, $motDePasseBdd);
            $crees['utilisateur'] = true;
            $this->cpanel->donnerDroits($utilisateur, $base);
            self::ecrire($preparation . '/wp-config.php', ConfigWordPress::contenu(
                ['base' => $base, 'utilisateur' => $utilisateur, 'mot_de_passe' => $motDePasseBdd, 'prefixe' => $prefixe], ['gele' => true]), 0600);
            $this->wp->lancer($preparation, ['db', 'import', $photo . '/base.sql'], 900);
            AdressesWordPress::remplacer($this->wp, $preparation, $adresse, $this->dossierPersonnel, $final);
            $this->wp->lancer($preparation, ['option', 'update', 'blog_public', '0']);
            // Sessions ouvertes sur le modèle : sans valeur ici (clés neuves), effacées par propreté.
            $this->wp->lancer($preparation, ['db', 'query', 'DELETE FROM `' . $prefixe . "usermeta` WHERE meta_key = 'session_tokens'"]);
            $alerte = $this->parametre('adresse_alertes');
            if ($alerte !== null) {
                $this->wp->lancer($preparation, ['option', 'update', 'admin_email', $alerte]);
                $this->wp->lancer($preparation, ['option', 'update', 'new_admin_email', $alerte]);
            }
            self::ecrire($preparation . '/.htaccess', ConfigWordPress::htaccess(DeploiementSujet::DOSSIER), 0644);

            // Bascule : un /WP2 existant est mis à l'écart 8 jours, le nouveau prend sa place.
            if (Fichiers::nonVide($final)) {
                [$ancienneBase, $ancienUtilisateur] = $this->ancienneBase($cible, $final);
                $miseALecart = $this->ecart->mettre($final, $cible, DeploiementSujet::DOSSIER, $ancienneBase, $ancienUtilisateur,
                    'remplacé par le sujet d\'examen (déploiement du site modèle)');
            } elseif (is_dir($final) && !is_link($final)) {
                rmdir($final);
            }
            if (!rename($preparation, $final)) {
                if ($miseALecart !== null) {
                    $this->ecart->remettre($miseALecart, $final);
                }
                throw new RuntimeException('Mise en place du /WP2 impossible.');
            }
        } catch (Throwable $e) {
            $this->defaire($crees, $preparation, $base, $utilisateur);
            throw new RuntimeException($e->getMessage() . ' Le /WP2 de cet étudiant n\'a pas changé ; ce que cette étape avait créé a été effacé.', 0, $e);
        }

        $this->pdo->prepare(
            "INSERT INTO sites (identifiant, dossier, logiciel, version, base, utilisateur_bdd, gele)
             VALUES (:i, :d, 'wordpress', :v, :b, :u, 1)
             ON CONFLICT (identifiant, dossier) DO UPDATE SET logiciel = 'wordpress', version = :v2, base = :b2, utilisateur_bdd = :u2,
                 dossier_admin = NULL, gele = 1, cree_le = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')"
        )->execute([':i' => $cible, ':d' => DeploiementSujet::DOSSIER, ':v' => $version, ':b' => $base, ':u' => $utilisateur,
            ':v2' => $version, ':b2' => $base, ':u2' => $utilisateur]);
        $this->pdo->prepare(
            "INSERT INTO releve_sites (identifiant, dossier, logiciel, version, releve_le) VALUES (:i, :d, 'wordpress', :v, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
             ON CONFLICT (identifiant, dossier) DO UPDATE SET logiciel = 'wordpress', version = :v2"
        )->execute([':i' => $cible, ':d' => DeploiementSujet::DOSSIER, ':v' => $version, ':v2' => $version]);

        return [
            'etat' => 'reussi',
            'message' => 'Sujet copié dans /WP2 (WordPress ' . ($version ?? '?') . ', version gelée). '
                . ($miseALecart !== null ? 'L\'ancien /WP2 est gardé 8 jours à l\'écart.' : ''),
        ];
    }

    /**
     * Base et utilisateur MySQL d'un /WP2 existant, pour les effacer au bout des 8 jours.
     * Connus du portail : ceux de la table sites. Sinon (sites faits par les scripts) : lus dans son
     * wp-config.php, et retenus seulement s'ils sont propres à cet étudiant (jamais un utilisateur
     * partagé comme jafl3067_peda).
     *
     * @return array{?string, ?string}
     */
    private function ancienneBase(string $identifiant, string $final): array
    {
        $requete = $this->pdo->prepare('SELECT base, utilisateur_bdd FROM sites WHERE identifiant = :i AND dossier = :d');
        $requete->execute([':i' => $identifiant, ':d' => DeploiementSujet::DOSSIER]);
        $ligne = Lecture::ligne($requete);
        if ($ligne !== null) {
            return [Lecture::texte($ligne['base']), Lecture::texte($ligne['utilisateur_bdd'])];
        }
        $config = is_file($final . '/wp-config.php') && !is_link($final . '/wp-config.php')
            ? (string) file_get_contents($final . '/wp-config.php', false, null, 0, 65536) : '';
        $propre = '/^' . self::PREFIXE . preg_quote($identifiant, '/') . '_[A-Za-z0-9_]{1,40}$/D';
        $lire = static function (string $nom) use ($config, $propre): ?string {
            if (preg_match('/define\(\s*[\'"]' . $nom . '[\'"]\s*,\s*[\'"]([A-Za-z0-9_]{1,64})[\'"]\s*\)/', $config, $trouve) !== 1) {
                return null;
            }

            return preg_match($propre, $trouve[1]) === 1 ? $trouve[1] : null;
        };
        $utilisateur = $lire('DB_USER');

        return [$lire('DB_NAME'), $utilisateur !== null && strtolower($utilisateur) === $utilisateur ? $utilisateur : null];
    }

    // ===== Fin du travail =====

    public function apresTravail(int $travailId, array $parametres): void
    {
        // La photo est gardée 8 jours (retrouver ce qui a été déployé), puis effacée par la purge.
        try {
            $photo = $this->dossierPhoto($parametres);
            if (is_dir($photo)) {
                $this->ecart->mettre($photo, SiteModele::IDENTIFIANT, '/photo', null, null, 'photo du site modèle déployée (travail n° ' . $travailId . ')');
            }
        } catch (Throwable $e) {
            (new Journal($this->pdo))->ecrire('site', 'photo du site modèle non mise à l\'écart', 'echec', null, 'travailleur', null, 'travail n° ' . $travailId,
                mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
        }

        $requete = $this->pdo->prepare("SELECT cible, libelle, etat FROM travaux_etapes WHERE travail_id = :t AND cible != :s ORDER BY rang");
        $requete->execute([':t' => $travailId, ':s' => SiteModele::IDENTIFIANT]);
        $reussis = '';
        $echecs = 0;
        $total = 0;
        foreach (Lecture::lignes($requete) as $etape) {
            $total++;
            $identifiant = (string) Lecture::texte($etape['cible']);
            if (Lecture::texte($etape['etat']) === 'reussi') {
                $adresse = 'https://' . $identifiant . '.' . Releve::DOMAINE . DeploiementSujet::DOSSIER . '/';
                $reussis .= '   - ' . (Lecture::texte($etape['libelle']) ?? $identifiant) . ' : ' . $adresse . '  (administration : ' . $adresse . "wp-admin/)\n";
            } else {
                $echecs++;
            }
        }
        if ($total === 0) {
            return;
        }
        $auteur = Lecture::texte($parametres['demande_par_nom'] ?? null) ?? 'un compte du portail';
        $texte = "Bonjour,\n\n" . 'Le sujet d\'examen a été déployé par ' . $auteur . ' (travail n° ' . $travailId . ') : '
            . ($total - $echecs) . ' /WP2 sur ' . $total . ".\n\n"
            . ($reussis === '' ? "Aucun /WP2 n'a été créé.\n" : "/WP2 créés :\n" . $reussis)
            . ($echecs > 0 ? "\n" . $echecs . " étudiant(s) en échec : voir le détail du travail dans le portail.\n" : '')
            . "\nIdentifiant et mot de passe : ceux du compte administrateur du site modèle (aucun mot de passe dans ce message)."
            . "\nLes étudiants n'ont reçu aucun e-mail.\n\n-- \nMessage automatique du portail des sites étudiants.";
        $destinataires = $this->destinataires();
        $journal = new Journal($this->pdo);
        try {
            if ($this->expediteur === null || $destinataires === []) {
                throw new RuntimeException('aucun expéditeur ou destinataire');
            }
            $this->expediteur->envoyer($destinataires, 'Sujet d\'examen déployé dans les /WP2 (travail n° ' . $travailId . ')', $texte);
            $journal->ecrire('site', 'e-mail envoyé', 'succes', null, 'travailleur', null, 'travail n° ' . $travailId, 'liste des /WP2 → ' . count($destinataires) . ' destinataire(s)');
        } catch (Throwable $e) {
            $journal->ecrire('site', 'échec d\'envoi d\'e-mail', 'echec', null, 'travailleur', null, 'travail n° ' . $travailId, mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
        }
    }

    /** @return list<string> Adresse d'alerte et comptes enseignants actifs (sans doublon) */
    private function destinataires(): array
    {
        $liste = [];
        $alerte = $this->parametre('adresse_alertes');
        if ($alerte !== null) {
            $liste[] = $alerte;
        }
        foreach (Lecture::lignes($this->pdo->query("SELECT email FROM comptes WHERE role = 'enseignant' AND actif = 1 ORDER BY id")) as $ligne) {
            $email = Lecture::texte($ligne['email']);
            if ($email !== null && !in_array(strtolower($email), array_map('strtolower', $liste), true)) {
                $liste[] = $email;
            }
        }

        return $liste;
    }

    // ===== Outils =====

    /** @param array<array-key, mixed> $parametres */
    private function dossierPhoto(array $parametres): string
    {
        $nom = Lecture::texte($parametres['photo'] ?? null);
        if ($nom === null || preg_match('/^photo-[0-9]{8}-[0-9]{6}-[a-z0-9]{6}$/D', $nom) !== 1) {
            throw new RuntimeException('Photo du site modèle mal nommée : rien n\'a été fait.');
        }

        return $this->dossierTemporaire . '/photos/' . $nom;
    }

    private function verifierEtudiant(string $identifiant): void
    {
        $requete = $this->pdo->prepare('SELECT 1 FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);
        if ($requete->fetchColumn() === false) {
            throw new RuntimeException('Étudiant inconnu du portail : rien n\'a été fait.');
        }
        $proteges = Lecture::tableau(json_decode((string) $this->parametre('sites_proteges'), true)) ?? [];
        if (in_array($identifiant, $proteges, true)) {
            throw new RuntimeException('Sous-domaine protégé : refusé.');
        }
    }

    /** Dossier du sous-domaine, d'après CPanel, et seulement s'il est exactement à l'endroit attendu. */
    private function racine(string $identifiant): string
    {
        $racine = $this->cpanel->sousDomaines()[$identifiant] ?? null;
        $attendu = $this->dossierPersonnel . '/' . $identifiant . '.' . Releve::DOMAINE;
        if ($racine === null) {
            throw new RuntimeException('Sous-domaine introuvable dans CPanel : rien n\'a été fait.');
        }
        if (rtrim($racine, '/') !== $attendu || !is_dir($attendu) || is_link($attendu) || realpath($attendu) !== $attendu) {
            throw new RuntimeException('Dossier du sous-domaine inattendu (' . $racine . ') : refusé par prudence.');
        }

        return $attendu;
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
