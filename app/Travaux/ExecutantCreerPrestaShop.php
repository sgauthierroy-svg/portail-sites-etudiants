<?php

declare(strict_types=1);

namespace Portail\Travaux;

use FilesystemIterator;
use PDO;
use Portail\Base\Lecture;
use Portail\Bibliotheque\Bibliotheque;
use Portail\Courriel\Expediteur;
use Portail\Journal\Journal;
use Portail\Securite\Chiffrement;
use Portail\Securite\MotsDePasse;
use Portail\Sites\InstallateurPrestaShop;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Création d'une boutique PrestaShop d'entraînement dans /Presta (V2, lot 3,
 * décisions du 08/11/2026). Reprend creation_presta.sh (validé le 25/10/2026), avec :
 * - un utilisateur MySQL propre au site, mot de passe tiré au hasard, écrit seulement
 *   dans la configuration de la boutique ;
 * - le paquet de la bibliothèque, empreinte revérifiée ;
 * - https dès l'installation ;
 * - dossier d'administration admin-identifiant-xxxxxx (6 caractères au hasard) ;
 * - e-mail à l'étudiant, récapitulatif à l'administrateur en fin de travail ;
 * - en cas d'échec, effacement de ce que cette étape a créé, et de rien d'autre.
 */
final class ExecutantCreerPrestaShop implements Executant, ApresTravail
{
    private const DOMAINE = Releve::DOMAINE;
    private const PREFIXE = 'jafl3067_';

    /** Colonnes du récapitulatif : en-têtes exacts de la feuille des accès (donnés le 11/11/2026). */
    public const COLONNES = ['Prestashopadmin', 'Identifiantprestashop', 'Mot de passe Prestashop'];

    /** Objet de l'e-mail à l'étudiant : il doit reconnaître l'établissement et l'importance du message (11/11/2026). */
    public const SUJET_ETUDIANT = 'IMPORTANT – Sup\'Sallanches (BTS NDRC) : vos accès à votre boutique PrestaShop, à conserver';

    /** @var array<string, string> empreinte => chemin du prestashop.zip intérieur déjà extrait */
    private array $interieurs = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ServeurCpanel $cpanel,
        private readonly Bibliotheque $bibliotheque,
        private readonly InstallateurPrestaShop $installateur,
        private readonly Chiffrement $chiffrement,
        private readonly ?Expediteur $expediteur,
        private readonly string $dossierPersonnel,
        private readonly string $dossierTemporaire,
    ) {
    }

    public function executer(string $cible, array $parametres): array
    {
        // Le travailleur crée ses propres fichiers en privé (umask 077). Un site, lui, doit
        // être lisible par le serveur web (.htaccess, images, styles) : droits habituels
        // 755 pour les dossiers et 644 pour les fichiers, le temps de cette étape seulement
        // (constaté le 08/11/2026 : « Server unable to read htaccess file »).
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
        $racine = $this->racine($cible);
        $presta = $racine . '/Presta';
        if (is_dir($presta) && (new FilesystemIterator($presta))->valid()) {
            return ['etat' => 'ignore', 'message' => 'Le dossier /Presta existe déjà et n\'est pas vide : rien n\'a été fait.'];
        }
        $paquetId = Lecture::entier($parametres['paquet_id'] ?? null);
        $nomBoutique = Lecture::texte($parametres['nom_boutique'] ?? null) ?? 'Sup';
        if ($paquetId === null) {
            throw new RuntimeException('Aucun paquet PrestaShop indiqué pour ce travail.');
        }
        [$zipInterieur, $version] = $this->paquet($paquetId);

        $base = self::PREFIXE . $cible . '_Presta';
        $utilisateur = self::PREFIXE . $cible . '_presta';
        // Majuscules, minuscules et chiffres (CPanel exige un mot de passe « fort »), sans caractère spécial.
        $motDePasseBdd = str_replace('-', '', MotsDePasse::provisoire() . MotsDePasse::provisoire());
        $motDePasse = MotsDePasse::provisoire();
        $crees = ['dossier' => false, 'base' => false, 'utilisateur' => false];
        $dossierExistait = is_dir($presta);
        try {
            $this->cpanel->creerBase($base);
            $crees['base'] = true;
            $this->cpanel->creerUtilisateur($utilisateur, $motDePasseBdd);
            $crees['utilisateur'] = true;
            $this->cpanel->donnerDroits($utilisateur, $base);
            if (!$dossierExistait && !mkdir($presta, 0755)) {
                throw new RuntimeException('Création du dossier /Presta impossible.');
            }
            $crees['dossier'] = true;
            Bibliotheque::extraire($zipInterieur, $presta);
            $this->installateur->installer($presta, [
                'domaine' => $cible . '.' . self::DOMAINE, 'base' => $base, 'utilisateur' => $utilisateur, 'mot_de_passe_bdd' => $motDePasseBdd,
                'nom' => $nomBoutique, 'email' => $etudiant['email'], 'mot_de_passe' => $motDePasse,
            ]);
            $this->installateur->activerHttps($base, $utilisateur, $motDePasseBdd);
            $dossierAdmin = $this->renommerAdministration($presta, $cible);
            foreach (['install', 'install-dev'] as $installation) {
                if (is_dir($presta . '/' . $installation)) {
                    self::effacerDossier($presta . '/' . $installation, $presta);
                }
            }
            if (is_dir($presta . '/var/cache')) {
                self::effacerDossier($presta . '/var/cache', $presta, false);
            }
        } catch (Throwable $e) {
            $this->defaire($crees, $presta, $base, $utilisateur, $dossierExistait);
            throw new RuntimeException($e->getMessage() . ' Ce qui avait été créé pour cette étape a été effacé.', 0, $e);
        }

        $this->pdo->prepare(
            "INSERT INTO sites (identifiant, dossier, logiciel, version, dossier_admin, base, utilisateur_bdd)
             VALUES (:i, '/Presta', 'prestashop', :v, :a, :b, :u)
             ON CONFLICT (identifiant, dossier) DO UPDATE SET version = :v2, dossier_admin = :a2, base = :b2, utilisateur_bdd = :u2, cree_le = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')"
        )->execute([':i' => $cible, ':v' => $version, ':a' => $dossierAdmin, ':b' => $base, ':u' => $utilisateur,
            ':v2' => $version, ':a2' => $dossierAdmin, ':b2' => $base, ':u2' => $utilisateur]);
        $this->pdo->prepare(
            "INSERT INTO releve_sites (identifiant, dossier, logiciel, version, releve_le) VALUES (:i, '/Presta', 'prestashop', :v, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
             ON CONFLICT (identifiant, dossier) DO UPDATE SET logiciel = 'prestashop', version = :v2"
        )->execute([':i' => $cible, ':v' => $version, ':v2' => $version]);

        $boutique = 'https://' . $cible . '.' . self::DOMAINE . '/Presta/';
        $administration = $boutique . $dossierAdmin . '/';
        $envoye = $this->ecrireEtudiant($etudiant, $boutique, $administration, $motDePasse);
        $ligne = implode("\t", [$cible, $boutique, $administration, $etudiant['email'], $motDePasse]);

        return [
            'etat' => 'reussi',
            'message' => 'Boutique PrestaShop ' . $version . ' installée en https. ' . ($envoye ? 'Accès envoyés à l\'étudiant.' : 'E-mail à l\'étudiant NON envoyé : accès dans le récapitulatif de l\'administrateur.'),
            'secret' => $this->chiffrement->chiffrer($ligne, 'recapitulatif-prestashop'),
        ];
    }

    public function apresTravail(int $travailId, array $parametres): void
    {
        $requete = $this->pdo->prepare("SELECT libelle, secret FROM travaux_etapes WHERE travail_id = :t AND secret IS NOT NULL ORDER BY rang");
        $requete->execute([':t' => $travailId]);
        $lignes = [];
        $reperes = [];
        foreach (Lecture::lignes($requete) as $etape) {
            try {
                // Secret : identifiant, boutique, administration, e-mail, mot de passe.
                $valeurs = explode("\t", $this->chiffrement->dechiffrer((string) Lecture::texte($etape['secret']), 'recapitulatif-prestashop'));
                $lignes[] = [$valeurs[2] ?? '', $valeurs[3] ?? '', $valeurs[4] ?? ''];
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
        $texte = 'Boutiques PrestaShop créées par le travail n° ' . $travailId . ' (' . count($lignes) . ").\n\n"
            . "Le tableau à copier dans la feuille des accès est dans la version HTML de ce message.\n"
            . "Version texte (colonnes séparées par des tabulations), dans l'ordre alphabétique des étudiants :\n"
            . implode("\t", self::COLONNES) . "\n";
        foreach ($lignes as $rang => $ligne) {
            $texte .= implode("\t", $ligne) . "\t(" . $reperes[$rang][0] . ")\n";
        }
        $texte .= "\nCe message contient des mots de passe : supprimez-le une fois la feuille mise à jour."
            . "\nLe portail ne les a pas gardés.\n\n-- \nMessage automatique du portail des sites étudiants.";
        $this->envoyer($adresse === null ? [] : [$adresse], 'Accès PrestaShop à reporter dans la feuille (travail n° ' . $travailId . ')', $texte,
            'récapitulatif à l\'administrateur', self::tableauHtml($travailId, $lignes, $reperes));
    }

    private function parametre(string $cle): ?string
    {
        $requete = $this->pdo->prepare('SELECT valeur FROM parametres WHERE cle = :c');
        $requete->execute([':c' => $cle]);

        return Lecture::texte($requete->fetchColumn());
    }

    /** @return array{nom: string, email: string} */
    private function etudiant(string $identifiant): array
    {
        $requete = $this->pdo->prepare('SELECT nom_complet, email FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            throw new RuntimeException('Étudiant inconnu du portail : rien n\'a été fait.');
        }
        $proteges = Lecture::tableau(json_decode((string) $this->parametre('sites_proteges'), true)) ?? [];
        if (in_array($identifiant, $proteges, true)) {
            throw new RuntimeException('Sous-domaine protégé : refusé.');
        }

        return ['nom' => (string) Lecture::texte($ligne['nom_complet']), 'email' => (string) Lecture::texte($ligne['email'])];
    }

    /** Dossier du sous-domaine, d'après CPanel, et seulement s'il est exactement à l'endroit attendu. */
    private function racine(string $identifiant): string
    {
        $racine = $this->cpanel->sousDomaines()[$identifiant] ?? null;
        $attendu = $this->dossierPersonnel . '/' . $identifiant . '.' . self::DOMAINE;
        if ($racine === null) {
            throw new RuntimeException('Sous-domaine introuvable dans CPanel : rien n\'a été fait.');
        }
        if (rtrim($racine, '/') !== $attendu || !is_dir($attendu) || is_link($attendu) || realpath($attendu) !== $attendu) {
            throw new RuntimeException('Dossier du sous-domaine inattendu (' . $racine . ') : refusé par prudence.');
        }

        return $attendu;
    }

    /** @return array{string, string} Chemin du ZIP à décompresser dans /Presta, version */
    private function paquet(int $paquetId): array
    {
        $chemin = $this->bibliotheque->cheminVerifie($paquetId);
        $requete = $this->pdo->prepare("SELECT version, empreinte FROM paquets WHERE id = :id AND type = 'prestashop'");
        $requete->execute([':id' => $paquetId]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            throw new RuntimeException('Ce paquet n\'est pas un paquet d\'installation PrestaShop.');
        }
        $empreinte = (string) Lecture::texte($ligne['empreinte']);
        if (!isset($this->interieurs[$empreinte])) {
            if (in_array('prestashop.zip', Bibliotheque::nomsZip($chemin), true)) {
                $interieur = $this->dossierTemporaire . '/prestashop-' . bin2hex(random_bytes(8)) . '.zip';
                Bibliotheque::extraireEntree($chemin, 'prestashop.zip', $interieur);
                $this->interieurs[$empreinte] = $interieur;
            } else {
                $this->interieurs[$empreinte] = $chemin;
            }
        }

        return [$this->interieurs[$empreinte], (string) Lecture::texte($ligne['version'])];
    }

    public function __destruct()
    {
        foreach ($this->interieurs as $chemin) {
            if (str_starts_with($chemin, $this->dossierTemporaire . '/prestashop-') && is_file($chemin)) {
                unlink($chemin); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
        }
    }

    private function renommerAdministration(string $presta, string $identifiant): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $suffixe = '';
        for ($i = 0; $i < 6; $i++) {
            $suffixe .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $nouveau = 'admin-' . $identifiant . '-' . $suffixe;
        $candidats = glob($presta . '/admin*', GLOB_ONLYDIR);
        foreach ($candidats === false ? [] : $candidats as $dossier) {
            $nom = basename($dossier);
            if (!in_array($nom, ['admin-api', 'admin-dev'], true) && is_file($dossier . '/index.php') && !is_link($dossier)) {
                if (!rename($dossier, $presta . '/' . $nouveau)) {
                    throw new RuntimeException('Renommage du dossier d\'administration impossible.');
                }

                return $nouveau;
            }
        }
        throw new RuntimeException('Dossier d\'administration de PrestaShop introuvable après l\'installation.');
    }

    /** @param array{dossier: bool, base: bool, utilisateur: bool} $crees */
    private function defaire(array $crees, string $presta, string $base, string $utilisateur, bool $dossierExistait): void
    {
        $journal = new Journal($this->pdo);
        try {
            // Dossier /Presta : effacé si cette étape l'a créé ; vidé s'il existait vide avant.
            if ($crees['dossier'] && is_dir($presta)) {
                self::effacerDossier($presta, dirname($presta), !$dossierExistait);
            }
            if ($crees['utilisateur']) {
                $this->cpanel->supprimerUtilisateur($utilisateur);
            }
            if ($crees['base']) {
                $this->cpanel->supprimerBase($base);
            }
        } catch (Throwable $e) {
            $journal->ecrire('site', 'nettoyage après échec incomplet', 'echec', null, 'travailleur', null, $base, mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
        }
    }

    /** Efface un dossier, seulement s'il est à l'intérieur de $limite (jamais au-dessus), sans suivre de lien. */
    private static function effacerDossier(string $dossier, string $limite, bool $luiAussi = true): void
    {
        $reel = realpath($dossier);
        $limiteReelle = realpath($limite);
        if ($reel === false || $limiteReelle === false || !str_starts_with($reel . '/', $limiteReelle . '/') || $reel === $limiteReelle) {
            throw new RuntimeException('Effacement refusé hors du dossier du site.');
        }
        $elements = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($reel, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($elements as $element) {
            if (!$element instanceof \SplFileInfo) {
                continue;
            }
            $chemin = $element->getPathname();
            if ($element->isDir() && !$element->isLink()) {
                rmdir($chemin);
            } else {
                unlink($chemin); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
        }
        if ($luiAussi) {
            rmdir($reel);
        }
    }

    /** @param array{nom: string, email: string} $etudiant */
    private function ecrireEtudiant(array $etudiant, string $boutique, string $administration, #[\SensitiveParameter] string $motDePasse): bool
    {
        $texte = "Bonjour,\n\n"
            . "Ce message vous est envoyé par votre établissement, Sup'Sallanches (BTS NDRC). "
            . "Il contient les accès à votre boutique PrestaShop, qui servira en cours : CONSERVEZ-LE.\n\n"
            . 'Votre boutique : ' . $boutique . "\n"
            . 'Administration de la boutique : ' . $administration . "\n"
            . 'Identifiant : ' . $etudiant['email'] . "\n"
            . 'Mot de passe : ' . $motDePasse . "\n\n"
            . "Ces accès sont personnels : ne les communiquez à personne et ne publiez pas le lien d'administration.\n"
            . "En cas de difficulté, adressez-vous à votre enseignant.\n\n"
            . "-- \nSup'Sallanches – BTS NDRC, portail des sites étudiants. Message automatique : ne pas y répondre.";

        return $this->envoyer([$etudiant['email']], self::SUJET_ETUDIANT, $texte, 'accès PrestaShop à l\'étudiant');
    }

    /**
     * Tableau HTML : sélectionné dans la messagerie puis collé dans Google Sheets, il garde une valeur
     * par cellule. Il ne contient que les trois colonnes de la feuille (demande du 11/11/2026) ;
     * un second tableau, à ne pas copier, rappelle à quel étudiant correspond chaque ligne.
     *
     * @param list<list<string>> $lignes Valeurs des colonnes de la feuille
     * @param list<list<string>> $reperes Nom de l'étudiant et identifiant, dans le même ordre
     */
    public static function tableauHtml(int $travailId, array $lignes, array $reperes): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $numerotes = [];
        foreach ($reperes as $rang => $repere) {
            $numerotes[] = array_merge([(string) ($rang + 1)], $repere);
        }

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif">'
            . '<p>Boutiques PrestaShop créées par le travail n° ' . $travailId . ' (' . count($lignes) . ').</p>'
            . '<p><strong>1. À copier dans la feuille des accès.</strong> Sélectionnez les lignes de ce tableau (sans la ligne des titres), copiez (Ctrl + C), '
            . 'puis collez (Ctrl + V) dans la colonne « ' . $e(self::COLONNES[0]) . ' », sur la ligne du premier étudiant.</p>'
            . self::tableau(self::COLONNES, $lignes)
            . '<p><strong>2. Repère, à ne pas copier.</strong> Les lignes sont dans l\'ordre alphabétique des étudiants. '
            . 'Si la feuille n\'est pas dans le même ordre, ou si des étudiants manquent dans ce lot, collez ligne par ligne.</p>'
            . self::tableau(['Ligne', 'Étudiant', 'Identifiant'], $numerotes)
            . '<p>Ce message contient des mots de passe : supprimez-le une fois la feuille mise à jour. Le portail ne les a pas gardés.</p>'
            . '<p style="color:#666">Message automatique du portail des sites étudiants.</p></body></html>';
    }

    /**
     * @param list<string> $titres
     * @param list<list<string>> $rangees
     */
    private static function tableau(array $titres, array $rangees): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cellule = 'style="border:1px solid #999;padding:4px 8px;font-family:Arial,sans-serif;font-size:13px"';
        $html = '<table style="border-collapse:collapse"><thead><tr>';
        foreach ($titres as $titre) {
            $html .= '<th ' . $cellule . '>' . $e($titre) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rangees as $rangee) {
            $html .= '<tr>';
            foreach ($rangee as $valeur) {
                $html .= '<td ' . $cellule . '>' . $e($valeur) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
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
}
