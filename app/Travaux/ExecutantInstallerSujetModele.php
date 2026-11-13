<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Base\Lecture;
use Portail\Bibliotheque\Bibliotheque;
use Portail\Courriel\Expediteur;
use Portail\Journal\Journal;
use Portail\Securite\MotsDePasse;
use Portail\Sites\ConfigWordPress;
use Portail\Sites\LigneWordPress;
use Portail\Sites\MiseALecart;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Sites\SiteModele;
use Portail\Systeme\Fichiers;
use RuntimeException;
use Throwable;

/**
 * Installation d'un sujet (paquet Duplicator de la bibliothèque) sur le site modèle d'examen
 * (V2, lot 4, décisions du 13/11/2026). Reprend deploy_duplicator.sh (validé en octobre 2026),
 * sans l'installateur de Duplicator, avec :
 * - une base et un utilisateur MySQL neufs, propres à cette installation ;
 * - un wp-config.php écrit par le portail (aucun mot de passe en ligne de commande) ;
 * - les adresses remplacées par https://sujet.bts-sup-sallanches.com/WP (WP-CLI search-replace,
 *   qui traite les données sérialisées de WordPress) ;
 * - préparation à part, puis bascule : le modèle actuel reste en ligne jusqu'au dernier moment,
 *   puis il est mis à l'écart 8 jours (dossier et base intacts) ;
 * - en cas d'échec avant la bascule, effacement de ce que cette étape a créé, et de rien d'autre ;
 * - alerte à l'administrateur avec une liste de contrôle (contrôle non bloquant).
 */
final class ExecutantInstallerSujetModele implements Executant
{
    private const PREFIXE = 'jafl3067_';

    /** Extensions qui donnent accès aux fichiers du serveur : signalées à l'administrateur (13/11/2026). */
    public const EXTENSIONS_FICHIERS = ['wp-file-manager', 'file-manager', 'file-manager-advanced', 'filester', 'wpide', 'advanced-file-manager', 'wp-file-manager-pro'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ServeurCpanel $cpanel,
        private readonly Bibliotheque $bibliotheque,
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
            return $this->installer($cible, $parametres);
        } finally {
            umask($masque);
        }
    }

    /**
     * @param array<array-key, mixed> $parametres
     * @return array{etat: 'reussi', message: string}
     */
    private function installer(string $cible, array $parametres): array
    {
        if ($cible !== SiteModele::IDENTIFIANT) {
            throw new RuntimeException('Ce travail ne concerne que le site modèle d\'examen : rien n\'a été fait.');
        }
        $paquetId = Lecture::entier($parametres['paquet_id'] ?? null);
        if ($paquetId === null) {
            throw new RuntimeException('Aucun sujet indiqué pour ce travail.');
        }
        $requete = $this->pdo->prepare("SELECT nom FROM paquets WHERE id = :id AND type = 'sujet_wordpress'");
        $requete->execute([':id' => $paquetId]);
        $nomSujet = Lecture::texte($requete->fetchColumn());
        if ($nomSujet === null) {
            throw new RuntimeException('Ce paquet n\'est pas un sujet WordPress de la bibliothèque.');
        }
        $zip = $this->bibliotheque->cheminVerifie($paquetId);
        Bibliotheque::analyserSujetWordPress($zip);
        $racine = $this->racine();
        $final = $racine . SiteModele::DOSSIER;
        $ancien = $this->siteActuel();

        $suffixe = self::suffixe();
        $base = self::PREFIXE . 'sujet_wp_' . $suffixe;
        $utilisateur = self::PREFIXE . 'sujet_' . $suffixe;
        $motDePasseBdd = str_replace('-', '', MotsDePasse::provisoire() . MotsDePasse::provisoire());
        $preparation = $this->dossierTemporaire . '/sujet-' . $suffixe;
        $sqlFiltre = $this->dossierTemporaire . '/sujet-' . $suffixe . '.sql';
        $crees = ['base' => false, 'utilisateur' => false];
        try {
            if (!mkdir($preparation, 0755)) {
                throw new RuntimeException('Création du dossier de préparation impossible.');
            }
            Bibliotheque::extraire($zip, $preparation);
            $sql = glob($preparation . '/dup-installer/dup-database__*.sql');
            if ($sql === false || $sql === []) {
                throw new RuntimeException('Base du sujet introuvable dans le paquet (dup-installer/dup-database__….sql).');
            }
            $prefixe = self::prefixeTables($sql[0]);
            self::filtrerSql($sql[0], $sqlFiltre);

            $this->cpanel->creerBase($base);
            $crees['base'] = true;
            $this->cpanel->creerUtilisateur($utilisateur, $motDePasseBdd);
            $crees['utilisateur'] = true;
            $this->cpanel->donnerDroits($utilisateur, $base);
            self::ecrire($preparation . '/wp-config.php', ConfigWordPress::contenu(
                ['base' => $base, 'utilisateur' => $utilisateur, 'mot_de_passe' => $motDePasseBdd, 'prefixe' => $prefixe],
                ['gele' => $ancien['gele']]), 0600);
            $this->wp->lancer($preparation, ['db', 'import', $sqlFiltre], 900);

            $this->remplacerAdresses($preparation, $final);
            $this->wp->lancer($preparation, ['option', 'update', 'blog_public', '0']);
            $adresse = $this->parametre('adresse_alertes');
            if ($adresse !== null) {
                $this->wp->lancer($preparation, ['option', 'update', 'admin_email', $adresse]);
                $this->wp->lancer($preparation, ['option', 'update', 'new_admin_email', $adresse]);
            }
            $this->nettoyer($preparation);
            self::ecrire($preparation . '/.htaccess', ConfigWordPress::htaccess(SiteModele::DOSSIER), 0644);
            $version = self::versionWordPress($preparation);
            $controle = $this->controle($preparation);

            // Bascule : le modèle actuel est mis à l'écart, le nouveau prend sa place.
            $miseALecart = null;
            if (Fichiers::nonVide($final)) {
                $miseALecart = $this->ecart->mettre($final, SiteModele::IDENTIFIANT, SiteModele::DOSSIER, $ancien['base'], $ancien['utilisateur'],
                    'remplacé par le sujet « ' . $nomSujet . ' » (paquet n° ' . $paquetId . ')');
            } elseif (is_dir($final) && !is_link($final)) {
                rmdir($final);
            }
            if (!rename($preparation, $final)) {
                if ($miseALecart !== null) {
                    $this->ecart->remettre($miseALecart, $final);
                }
                throw new RuntimeException('Mise en place du nouveau site modèle impossible.');
            }
        } catch (Throwable $e) {
            $this->defaire($crees, $preparation, $base, $utilisateur);
            throw new RuntimeException($e->getMessage() . ' Le site modèle n\'a pas changé ; ce que cette étape avait créé a été effacé.', 0, $e);
        } finally {
            if (is_file($sqlFiltre)) {
                unlink($sqlFiltre); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
        }

        $this->pdo->prepare(
            "INSERT INTO sites (identifiant, dossier, logiciel, version, base, utilisateur_bdd, gele)
             VALUES (:i, :d, 'wordpress', :v, :b, :u, :g)
             ON CONFLICT (identifiant, dossier) DO UPDATE SET logiciel = 'wordpress', version = :v2, base = :b2, utilisateur_bdd = :u2,
                 dossier_admin = NULL, cree_le = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')"
        )->execute([':i' => SiteModele::IDENTIFIANT, ':d' => SiteModele::DOSSIER, ':v' => $version, ':b' => $base, ':u' => $utilisateur,
            ':g' => $ancien['gele'] ? 1 : 0, ':v2' => $version, ':b2' => $base, ':u2' => $utilisateur]);
        $this->pdo->prepare(
            "INSERT INTO releve_sites (identifiant, dossier, logiciel, version, releve_le) VALUES (:i, :d, 'wordpress', :v, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
             ON CONFLICT (identifiant, dossier) DO UPDATE SET logiciel = 'wordpress', version = :v2"
        )->execute([':i' => SiteModele::IDENTIFIANT, ':d' => SiteModele::DOSSIER, ':v' => $version, ':v2' => $version]);

        $auteur = Lecture::texte($parametres['demande_par_nom'] ?? null) ?? 'un compte du portail';
        $this->alerter($nomSujet, $paquetId, $auteur, $version, $ancien['gele'], $controle);

        return [
            'etat' => 'reussi',
            'message' => 'Sujet « ' . $nomSujet . ' » installé sur le site modèle (WordPress ' . ($version ?? '?') . ', '
                . ($ancien['gele'] ? 'version gelée' : 'mises à jour automatiques actives') . '). '
                . ($ancien['installe'] ? 'L\'ancien modèle est gardé 8 jours à l\'écart. ' : '')
                . 'Liste de contrôle envoyée à l\'administrateur.',
        ];
    }

    /** Dossier du sous-domaine « sujet », d'après CPanel, et seulement s'il est exactement à l'endroit attendu. */
    private function racine(): string
    {
        $identifiant = SiteModele::IDENTIFIANT;
        $racine = $this->cpanel->sousDomaines()[$identifiant] ?? null;
        $attendu = $this->dossierPersonnel . '/' . $identifiant . '.' . Releve::DOMAINE;
        if ($racine === null) {
            throw new RuntimeException('Sous-domaine « ' . $identifiant . ' » introuvable dans CPanel : créez-le d\'abord. Rien n\'a été fait.');
        }
        if (rtrim($racine, '/') !== $attendu || !is_dir($attendu) || is_link($attendu) || realpath($attendu) !== $attendu) {
            throw new RuntimeException('Dossier du sous-domaine inattendu (' . $racine . ') : refusé par prudence.');
        }

        return $attendu;
    }

    /** @return array{installe: bool, base: ?string, utilisateur: ?string, gele: bool} */
    private function siteActuel(): array
    {
        $requete = $this->pdo->prepare('SELECT base, utilisateur_bdd, gele FROM sites WHERE identifiant = :i AND dossier = :d');
        $requete->execute([':i' => SiteModele::IDENTIFIANT, ':d' => SiteModele::DOSSIER]);
        $ligne = Lecture::ligne($requete);

        return $ligne === null ? ['installe' => false, 'base' => null, 'utilisateur' => null, 'gele' => false] : [
            'installe' => true, 'base' => Lecture::texte($ligne['base']), 'utilisateur' => Lecture::texte($ligne['utilisateur_bdd']),
            'gele' => Lecture::entier($ligne['gele']) === 1,
        ];
    }

    /**
     * Adresses du site d'origine → adresse du site modèle, en http comme en https, et sous la forme
     * « échappée » (https:\/\/…) qu'utilisent les blocs et les constructeurs de pages ; puis le
     * chemin du dossier d'origine sur le serveur, s'il s'agissait d'un site de ce compte.
     */
    private function remplacerAdresses(string $preparation, string $final): void
    {
        $nouvelle = rtrim(SiteModele::adresse(), '/');
        $origines = [];
        foreach (['siteurl', 'home'] as $option) {
            $valeur = rtrim(trim($this->wp->lancer($preparation, ['option', 'get', $option])), '/');
            if (preg_match('#^https?://([A-Za-z0-9.-]+)(/[A-Za-z0-9/_.~-]*)?$#D', $valeur, $morceaux) !== 1) {
                throw new RuntimeException('Adresse du site d\'origine illisible dans le sujet (' . $option . ').');
            }
            $origines[$morceaux[1] . ($morceaux[2] ?? '')] = [$morceaux[1], $morceaux[2] ?? ''];
        }
        // Les plus longues d'abord : « hote/WP » avant « hote ».
        uksort($origines, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($origines as $hoteChemin => [$hote, $chemin]) {
            foreach (['http://', 'https://'] as $schema) {
                $ancienne = $schema . $hoteChemin;
                if ($ancienne !== $nouvelle) {
                    $this->wp->lancer($preparation, ['search-replace', $ancienne, $nouvelle, '--all-tables-with-prefix', '--quiet'], 600);
                }
                $echappee = str_replace('/', '\\/', $ancienne);
                if ($ancienne !== $nouvelle) {
                    $this->wp->lancer($preparation, ['search-replace', $echappee, str_replace('/', '\\/', $nouvelle), '--all-tables-with-prefix', '--quiet'], 600);
                }
            }
            if (preg_match('/^[a-z0-9-]+\.' . preg_quote(Releve::DOMAINE, '/') . '$/D', $hote) === 1) {
                $cheminOrigine = $this->dossierPersonnel . '/' . $hote . $chemin;
                if ($cheminOrigine !== $final) {
                    $this->wp->lancer($preparation, ['search-replace', $cheminOrigine, $final, '--all-tables-with-prefix', '--quiet'], 600);
                }
            }
        }
    }

    /** Fichiers de Duplicator et paquets Duplicator rangés dans le site : jamais copiés vers les étudiants. */
    private function nettoyer(string $preparation): void
    {
        foreach (['dup-installer', 'wp-content/backups-dup-lite', 'wp-content/backups-dup-pro', 'wp-snapshots'] as $dossier) {
            if (is_dir($preparation . '/' . $dossier) && !is_link($preparation . '/' . $dossier)) {
                Fichiers::effacerDossier($preparation . '/' . $dossier, $preparation);
            }
        }
        $fichiers = glob($preparation . '/{installer.php,installer-backup.php,dup-*.txt,dup-*.php,*_archive.zip,*_archive.daf}', GLOB_BRACE);
        foreach ($fichiers === false ? [] : $fichiers as $fichier) {
            if (is_file($fichier)) {
                unlink($fichier); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
        }
    }

    /** @return array{extensions: list<array{nom: string, etat: string, version: string}>, theme: string, comptes: list<array{login: string, roles: string}>} */
    private function controle(string $preparation): array
    {
        $extensions = [];
        $liste = Lecture::tableau(json_decode($this->wp->lancer($preparation, ['plugin', 'list', '--fields=name,status,version', '--format=json']), true)) ?? [];
        foreach (array_keys($liste) as $rang) {
            $ligne = Lecture::tableau($liste[$rang]) ?? [];
            $extensions[] = ['nom' => (string) Lecture::texte($ligne['name'] ?? null), 'etat' => (string) Lecture::texte($ligne['status'] ?? null),
                'version' => (string) Lecture::texte($ligne['version'] ?? null)];
        }
        $comptes = [];
        $liste = Lecture::tableau(json_decode($this->wp->lancer($preparation, ['user', 'list', '--fields=user_login,roles', '--format=json']), true)) ?? [];
        foreach (array_keys($liste) as $rang) {
            $ligne = Lecture::tableau($liste[$rang]) ?? [];
            $comptes[] = ['login' => (string) Lecture::texte($ligne['user_login'] ?? null), 'roles' => (string) Lecture::texte($ligne['roles'] ?? null)];
        }
        $theme = trim($this->wp->lancer($preparation, ['theme', 'list', '--status=active', '--field=name']));

        return ['extensions' => $extensions, 'theme' => $theme, 'comptes' => $comptes];
    }

    /** @param array{extensions: list<array{nom: string, etat: string, version: string}>, theme: string, comptes: list<array{login: string, roles: string}>} $controle */
    private function alerter(string $nomSujet, int $paquetId, string $auteur, ?string $version, bool $gele, array $controle): void
    {
        $adresse = $this->parametre('adresse_alertes');
        $extensions = '';
        $fichiers = 0;
        foreach ($controle['extensions'] as $extension) {
            $danger = in_array($extension['nom'], self::EXTENSIONS_FICHIERS, true);
            $fichiers += $danger ? 1 : 0;
            $extensions .= '   - ' . $extension['nom'] . ' ' . $extension['version'] . ' (' . $extension['etat'] . ')'
                . ($danger ? '   ⚠ DONNE ACCÈS AUX FICHIERS DU SERVEUR : à retirer' : '') . "\n";
        }
        $comptes = '';
        foreach ($controle['comptes'] as $compte) {
            $comptes .= '   - ' . $compte['login'] . ' (' . $compte['roles'] . ")\n";
        }
        $texte = "Bonjour,\n\n" . $auteur . ' a installé le sujet « ' . $nomSujet . ' » (paquet n° ' . $paquetId . ') sur le site modèle d\'examen.' . "\n\n"
            . 'Site modèle : ' . SiteModele::adresse() . "\n"
            . 'Administration : ' . SiteModele::adresse() . "wp-admin/\n"
            . 'WordPress ' . ($version ?? '(version illisible)') . ', ' . ($gele ? 'version gelée.' : 'mises à jour automatiques actives (pas encore gelé).') . "\n\n"
            . "À VÉRIFIER (bon fonctionnement et sécurité). Ce contrôle ne bloque rien : le sujet peut déjà être déployé.\n\n"
            . '1. Extensions présentes (' . count($controle['extensions']) . ($fichiers > 0 ? ', dont ' . $fichiers . ' à retirer' : '') . ") :\n"
            . ($extensions === '' ? "   (aucune)\n" : $extensions)
            . "   Elles seront copiées chez chaque étudiant : retirez toute extension inconnue ou inutile au sujet.\n\n"
            . '2. Comptes du site (' . count($controle['comptes']) . ") :\n" . ($comptes === '' ? "   (aucun)\n" : $comptes)
            . "   Aucun compte d'étudiant ne doit rester : le modèle part chez tous les étudiants.\n\n"
            . '3. Thème actif : ' . ($controle['theme'] === '' ? '(aucun)' : $controle['theme']) . "\n\n"
            . "4. Ouvrir le site modèle : il doit s'afficher avec le cadenas (https), sans erreur.\n\n"
            . "-- \nMessage automatique du portail des sites étudiants.";
        $journal = new Journal($this->pdo);
        try {
            if ($this->expediteur === null || $adresse === null) {
                throw new RuntimeException('aucun expéditeur ou destinataire');
            }
            $this->expediteur->envoyer([$adresse], 'Site modèle d\'examen : sujet « ' . $nomSujet . ' » installé, à vérifier', $texte);
            $journal->ecrire('site', 'e-mail envoyé', 'succes', null, 'travailleur', null, null, 'liste de contrôle du site modèle → ' . $adresse);
        } catch (Throwable $e) {
            $journal->ecrire('site', 'échec d\'envoi d\'e-mail', 'echec', null, 'travailleur', null, null, 'liste de contrôle du site modèle : ' . mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
        }
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

    /** 6 caractères au hasard (minuscules et chiffres) : base et utilisateur propres à cette installation. */
    public static function suffixe(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $suffixe = '';
        for ($i = 0; $i < 6; $i++) {
            $suffixe .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffixe;
    }

    /** Préfixe des tables d'après la base exportée : celui qui a à la fois …options et …users. */
    public static function prefixeTables(string $sql): string
    {
        $flux = fopen($sql, 'rb');
        if ($flux === false) {
            throw new RuntimeException('Base du sujet illisible.');
        }
        $tables = [];
        $lu = 0;
        while (($ligne = fgets($flux, 1_000_000)) !== false && $lu < 200_000_000) {
            $lu += strlen($ligne);
            if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)? `([A-Za-z0-9_]{1,64})`/', $ligne, $trouve) === 1) {
                $tables[$trouve[1]] = true;
            }
        }
        fclose($flux);
        $candidats = [];
        foreach (array_keys($tables) as $table) {
            if (str_ends_with($table, 'options')) {
                $prefixe = substr($table, 0, -7);
                if (isset($tables[$prefixe . 'users']) && preg_match('/^[A-Za-z0-9_]{1,20}$/D', $prefixe) === 1) {
                    $candidats[] = $prefixe;
                }
            }
        }
        if ($candidats === []) {
            throw new RuntimeException('Tables WordPress introuvables dans la base du sujet.');
        }
        usort($candidats, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        return $candidats[0];
    }

    /** Copie de la base sans les messages d'avertissement que l'export laisse parfois au début (« mysqldump: … »). */
    public static function filtrerSql(string $source, string $cible): void
    {
        $entree = fopen($source, 'rb');
        $sortie = fopen($cible, 'xb');
        if ($entree === false || $sortie === false) {
            throw new RuntimeException('Préparation de la base du sujet impossible.');
        }
        chmod($cible, 0600);
        while (($ligne = fgets($entree)) !== false) {
            if (!str_starts_with($ligne, 'mysqldump:') && !str_starts_with($ligne, 'mariadb-dump:')) {
                fwrite($sortie, $ligne);
            }
        }
        fclose($entree);
        fclose($sortie);
    }

    public static function versionWordPress(string $chemin): ?string
    {
        $contenu = is_file($chemin . '/wp-includes/version.php') ? file_get_contents($chemin . '/wp-includes/version.php', false, null, 0, 8192) : false;

        return is_string($contenu) && preg_match('/\$wp_version\s*=\s*\'([0-9.]{1,20})\'/', $contenu, $trouve) === 1 ? $trouve[1] : null;
    }

    private static function ecrire(string $fichier, string $contenu, int $droits): void
    {
        if (file_put_contents($fichier, $contenu, LOCK_EX) === false || !chmod($fichier, $droits)) {
            throw new RuntimeException('Écriture de ' . basename($fichier) . ' impossible.');
        }
    }
}
