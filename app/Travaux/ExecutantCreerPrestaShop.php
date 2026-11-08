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

    /** Colonnes du récapitulatif, dans l'ordre de la feuille des accès. */
    public const COLONNES = ['Identifiant', 'Boutique', 'Administration', 'E-mail (identifiant PrestaShop)', 'Mot de passe PrestaShop'];

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
        $requete = $this->pdo->prepare("SELECT id, secret FROM travaux_etapes WHERE travail_id = :t AND secret IS NOT NULL ORDER BY rang");
        $requete->execute([':t' => $travailId]);
        $lignes = [];
        foreach (Lecture::lignes($requete) as $etape) {
            try {
                $lignes[] = $this->chiffrement->dechiffrer((string) Lecture::texte($etape['secret']), 'recapitulatif-prestashop');
            } catch (Throwable) {
                $lignes[] = '(ligne illisible)';
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
            . "Version texte (colonnes séparées par des tabulations) :\n"
            . implode("\t", self::COLONNES) . "\n" . implode("\n", $lignes)
            . "\n\nCe message contient des mots de passe : supprimez-le une fois la feuille mise à jour."
            . "\nLe portail ne les a pas gardés.\n\n-- \nMessage automatique du portail des sites étudiants.";
        $this->envoyer($adresse === null ? [] : [$adresse], 'Récapitulatif des boutiques PrestaShop créées (travail n° ' . $travailId . ')', $texte,
            'récapitulatif à l\'administrateur', self::tableauHtml($travailId, $lignes));
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
        $texte = "Bonjour,\n\nVotre boutique PrestaShop d'entraînement est prête.\n\n"
            . 'Boutique : ' . $boutique . "\n"
            . 'Administration : ' . $administration . "\n"
            . 'Identifiant : ' . $etudiant['email'] . "\n"
            . 'Mot de passe : ' . $motDePasse . "\n\n"
            . "Gardez ces accès pour vous. Le lien d'administration est personnel : ne le publiez pas.\n\n"
            . "-- \nPortail des sites étudiants, Sup'Sallanches. Ne pas répondre à ce message.";

        return $this->envoyer([$etudiant['email']], 'Votre boutique PrestaShop', $texte, 'accès PrestaShop à l\'étudiant');
    }

    /**
     * Tableau HTML : sélectionné dans la messagerie puis collé dans Google Sheets, il garde
     * une valeur par cellule (au contraire du texte, où les tabulations se perdent).
     *
     * @param list<string> $lignes Valeurs séparées par des tabulations
     */
    public static function tableauHtml(int $travailId, array $lignes): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cellule = 'style="border:1px solid #999;padding:4px 8px;font-family:Arial,sans-serif;font-size:13px"';
        $corps = '';
        foreach ($lignes as $ligne) {
            $corps .= '<tr>';
            foreach (explode("\t", $ligne) as $valeur) {
                $corps .= '<td ' . $cellule . '>' . $e($valeur) . '</td>';
            }
            $corps .= '</tr>';
        }
        $entete = '';
        foreach (self::COLONNES as $colonne) {
            $entete .= '<th ' . $cellule . '>' . $e($colonne) . '</th>';
        }

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif">'
            . '<p>Boutiques PrestaShop créées par le travail n° ' . $travailId . ' (' . count($lignes) . ').</p>'
            . '<p><strong>Pour la feuille des accès :</strong> sélectionnez les lignes du tableau (sans la ligne des titres si la feuille les a déjà), '
            . 'copiez (Ctrl + C), puis collez dans la première cellule libre de la feuille (Ctrl + V) : chaque valeur va dans sa colonne.</p>'
            . '<table style="border-collapse:collapse"><thead><tr>' . $entete . '</tr></thead><tbody>' . $corps . '</tbody></table>'
            . '<p>Ce message contient des mots de passe : supprimez-le une fois la feuille mise à jour. Le portail ne les a pas gardés.</p>'
            . '<p style="color:#666">Message automatique du portail des sites étudiants.</p></body></html>';
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
