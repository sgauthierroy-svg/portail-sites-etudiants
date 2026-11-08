<?php

declare(strict_types=1);

namespace Portail\Bibliotheque;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;
use ZipArchive;

/**
 * Bibliothèque (paquets PrestaShop, sujets) et espace documentaire (PDF) — V2, lot 2,
 * décisions du 08/11/2026.
 *
 * Règles :
 * - un fichier est rangé sous le nom de son empreinte SHA-256, jamais sous le nom
 *   choisi par la personne qui le dépose ; les dossiers sont hors d'atteinte du web ;
 * - un ZIP est refusé s'il ne ressemble pas à ce qu'on annonce, ou s'il contient
 *   un chemin qui sortirait du dossier où on le décompressera (« zip slip ») ;
 * - l'empreinte est revérifiée avant chaque utilisation (cheminVerifie) ;
 * - un paquet utilisé par une session ne peut pas être retiré.
 */
final class Bibliotheque
{
    public const TYPES = [
        'prestashop' => 'Paquet d\'installation PrestaShop',
        'sujet_wordpress' => 'Sujet WordPress (Duplicator)',
        'sujet_prestashop' => 'Sujet PrestaShop',
    ];

    public const TAILLE_MAX_PAQUET = 2 * 1024 * 1024 * 1024;
    public const TAILLE_MAX_PDF = 30 * 1024 * 1024;

    /** Noms acceptés dans le dossier de dépôt (les autres sont signalés, jamais lus). */
    private const NOM_DEPOT = '/^[A-Za-z0-9][A-Za-z0-9._() -]{0,149}\.(zip|pdf)$/D';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dossierDonnees,
        private readonly ?Compte $auteur = null,
        private readonly ?string $adresseIp = null,
    ) {
    }

    // ===== Paquets =====

    /**
     * Ajoute un paquet. $source est déjà dans le dossier du portail (data/tmp pour un
     * envoi par la page, data/depot pour un dépôt par CPanel) : il est déplacé.
     */
    public function ajouterPaquet(string $source, string $nomOrigine, string $type, string $nom, string $version): int
    {
        $auteur = $this->auteurObligatoire();
        if (!isset(self::TYPES[$type])) {
            throw new ErreurSaisie('Type de paquet inconnu.');
        }
        if ($type === 'sujet_prestashop') {
            throw new ErreurSaisie('Les sujets PrestaShop arrivent avec le lot 6 : leur contrôle n\'est pas encore écrit.');
        }
        $nom = self::texteCourt($nom, 100, 'Le nom');
        $taille = self::taille($source, self::TAILLE_MAX_PAQUET, '2 Go');
        $versionWordPress = null;
        $version = trim($version);
        if ($type === 'prestashop') {
            if (preg_match('/^[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}(\.[0-9]{1,3})?$/D', $version) !== 1) {
                throw new ErreurSaisie('Indiquez le numéro de version PrestaShop, par exemple 9.1.5.');
            }
            if (preg_match('/([0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $nomOrigine, $trouve) === 1 && $trouve[1] !== $version) {
                throw new ErreurSaisie('Le nom du fichier indique la version ' . $trouve[1] . ', pas ' . $version . ' : vérifiez avant de déposer.');
            }
            self::analyserPrestaShop($source);
            if ($this->paquetPrestaShop($version) !== null) {
                throw new ErreurSaisie('La version ' . $version . ' de PrestaShop est déjà dans la bibliothèque.');
            }
        } else {
            $version = '';
            $versionWordPress = self::analyserSujetWordPress($source);
        }
        $empreinte = $this->ranger($source, 'bibliotheque', 'zip');
        try {
            $this->pdo->prepare(
                'INSERT INTO paquets (type, nom, version, version_wordpress, empreinte, taille, depose_par, depose_par_nom)
                 VALUES (:t, :n, :v, :w, :e, :s, :c, :cn)'
            )->execute([':t' => $type, ':n' => $nom, ':v' => $version === '' ? null : $version, ':w' => $versionWordPress,
                ':e' => $empreinte, ':s' => $taille, ':c' => $auteur->id, ':cn' => $auteur->nomAffiche]);
        } catch (\PDOException) {
            $this->effacer('bibliotheque', $empreinte, 'zip');
            throw new ErreurSaisie('Ce paquet n\'a pas pu être enregistré (version ou contenu déjà présents).');
        }
        $id = (int) $this->pdo->lastInsertId();
        $this->tracer('paquet ajouté', 'paquet n° ' . $id, self::TYPES[$type] . ' : ' . $nom . ($version === '' ? '' : ' ' . $version)
            . ' (' . self::lisible($taille) . ', empreinte ' . substr($empreinte, 0, 12) . '…)');

        return $id;
    }

    /**
     * @return list<array{id: int, type: string, nom: string, version: ?string, version_wordpress: ?string, taille: int,
     *                    depose_le: string, depose_par: ?int, depose_par_nom: string, sessions: list<int>}>
     */
    public function paquets(): array
    {
        $resultat = $this->pdo->query('SELECT * FROM paquets ORDER BY type, nom, version');
        $utilisations = [];
        foreach (Lecture::lignes($this->pdo->query('SELECT paquet_prestashop_id AS p, annee FROM sessions WHERE paquet_prestashop_id IS NOT NULL ORDER BY annee')) as $ligne) {
            $utilisations[(int) Lecture::entier($ligne['p'])][] = (int) Lecture::entier($ligne['annee']);
        }
        $liste = [];
        foreach (Lecture::lignes($resultat) as $ligne) {
            $id = (int) Lecture::entier($ligne['id']);
            $liste[] = [
                'id' => $id,
                'type' => (string) Lecture::texte($ligne['type']),
                'nom' => (string) Lecture::texte($ligne['nom']),
                'version' => Lecture::texte($ligne['version']),
                'version_wordpress' => Lecture::texte($ligne['version_wordpress']),
                'taille' => (int) Lecture::entier($ligne['taille']),
                'depose_le' => (string) Lecture::texte($ligne['depose_le']),
                'depose_par' => Lecture::entier($ligne['depose_par']),
                'depose_par_nom' => (string) Lecture::texte($ligne['depose_par_nom']),
                'sessions' => $utilisations[$id] ?? [],
            ];
        }

        return $liste;
    }

    public function peutRetirer(?int $deposePar, Compte $moi): bool
    {
        return $deposePar === $moi->id || Droits::peut($moi->role, Droits::SUPPRIMER);
    }

    public function retirerPaquet(int $id): void
    {
        $auteur = $this->auteurObligatoire();
        $ligne = $this->ligne('paquets', $id);
        if (!$this->peutRetirer(Lecture::entier($ligne['depose_par']), $auteur)) {
            throw new ErreurSaisie('Seuls la personne qui l\'a déposé et l\'administrateur peuvent retirer ce paquet.');
        }
        try {
            $this->pdo->prepare('DELETE FROM paquets WHERE id = :id')->execute([':id' => $id]);
        } catch (\PDOException) {
            throw new ErreurSaisie('Ce paquet est utilisé par une session : il ne peut pas être retiré.');
        }
        $this->effacer('bibliotheque', (string) Lecture::texte($ligne['empreinte']), 'zip');
        $this->tracer('paquet retiré', 'paquet n° ' . $id, (string) Lecture::texte($ligne['nom']));
    }

    /** Chemin du fichier d'un paquet, après vérification de son empreinte (avant chaque utilisation). */
    public function cheminVerifie(int $id): string
    {
        $ligne = $this->ligne('paquets', $id);
        $empreinte = (string) Lecture::texte($ligne['empreinte']);
        $chemin = $this->dossierDonnees . '/bibliotheque/' . $empreinte . '.zip';
        if (!is_file($chemin) || hash_file('sha256', $chemin) !== $empreinte) {
            throw new ErreurSaisie('Le fichier du paquet n° ' . $id . ' a disparu ou a été modifié depuis son dépôt : il n\'est pas utilisé.');
        }

        return $chemin;
    }

    // ===== Documents =====

    public function ajouterDocument(string $source, string $titre): int
    {
        $auteur = $this->auteurObligatoire();
        $titre = self::texteCourt($titre, 150, 'Le titre');
        $taille = self::taille($source, self::TAILLE_MAX_PDF, '30 Mo');
        $debut = file_get_contents($source, false, null, 0, 5);
        if ($debut !== '%PDF-') {
            throw new ErreurSaisie('Ce fichier n\'est pas un PDF : seuls les PDF sont acceptés.');
        }
        $empreinte = $this->ranger($source, 'documents', 'pdf');
        try {
            $this->pdo->prepare('INSERT INTO documents (titre, empreinte, taille, depose_par, depose_par_nom) VALUES (:t, :e, :s, :c, :cn)')
                ->execute([':t' => $titre, ':e' => $empreinte, ':s' => $taille, ':c' => $auteur->id, ':cn' => $auteur->nomAffiche]);
        } catch (\PDOException) {
            $this->effacer('documents', $empreinte, 'pdf');
            throw new ErreurSaisie('Ce document n\'a pas pu être enregistré.');
        }
        $id = (int) $this->pdo->lastInsertId();
        $this->tracer('document ajouté', 'document n° ' . $id, $titre . ' (' . self::lisible($taille) . ')');

        return $id;
    }

    /** @return list<array{id: int, titre: string, taille: int, depose_le: string, depose_par: ?int, depose_par_nom: string}> */
    public function documents(): array
    {
        $liste = [];
        foreach (Lecture::lignes($this->pdo->query('SELECT * FROM documents ORDER BY titre COLLATE NOCASE')) as $ligne) {
            $liste[] = [
                'id' => (int) Lecture::entier($ligne['id']),
                'titre' => (string) Lecture::texte($ligne['titre']),
                'taille' => (int) Lecture::entier($ligne['taille']),
                'depose_le' => (string) Lecture::texte($ligne['depose_le']),
                'depose_par' => Lecture::entier($ligne['depose_par']),
                'depose_par_nom' => (string) Lecture::texte($ligne['depose_par_nom']),
            ];
        }

        return $liste;
    }

    /** @return array{titre: string, contenu: string}|null */
    public function lireDocument(int $id): ?array
    {
        $requete = $this->pdo->prepare('SELECT titre, empreinte FROM documents WHERE id = :id');
        $requete->execute([':id' => $id]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            return null;
        }
        $contenu = file_get_contents($this->dossierDonnees . '/documents/' . (string) Lecture::texte($ligne['empreinte']) . '.pdf');

        return $contenu === false ? null : ['titre' => (string) Lecture::texte($ligne['titre']), 'contenu' => $contenu];
    }

    public function retirerDocument(int $id): void
    {
        $auteur = $this->auteurObligatoire();
        $ligne = $this->ligne('documents', $id);
        if (!$this->peutRetirer(Lecture::entier($ligne['depose_par']), $auteur)) {
            throw new ErreurSaisie('Seuls la personne qui l\'a déposé et l\'administrateur peuvent retirer ce document.');
        }
        $this->pdo->prepare('DELETE FROM documents WHERE id = :id')->execute([':id' => $id]);
        $this->effacer('documents', (string) Lecture::texte($ligne['empreinte']), 'pdf');
        $this->tracer('document retiré', 'document n° ' . $id, (string) Lecture::texte($ligne['titre']));
    }

    // ===== Dossier de dépôt (fichiers trop gros pour la page, déposés avec le gestionnaire de fichiers de CPanel) =====

    /** @return array{acceptes: list<array{nom: string, taille: int, extension: string}>, ignores: int} */
    public function depot(): array
    {
        $dossier = $this->dossierDonnees . '/depot';
        $entrees = is_dir($dossier) ? scandir($dossier) : false;
        $acceptes = [];
        $ignores = 0;
        foreach ($entrees === false ? [] : $entrees as $entree) {
            if ($entree === '.' || $entree === '..') {
                continue;
            }
            $chemin = $dossier . '/' . $entree;
            if (preg_match(self::NOM_DEPOT, $entree, $trouve) !== 1 || !is_file($chemin) || is_link($chemin)) {
                $ignores++;
                continue;
            }
            $acceptes[] = ['nom' => $entree, 'taille' => (int) filesize($chemin), 'extension' => strtolower($trouve[1])];
        }

        return ['acceptes' => $acceptes, 'ignores' => $ignores];
    }

    /** Chemin d'un fichier du dépôt, seulement s'il figure dans la liste acceptée (jamais un chemin saisi). */
    public function cheminDepot(string $nom, string $extension): string
    {
        foreach ($this->depot()['acceptes'] as $fichier) {
            if ($fichier['nom'] === $nom && $fichier['extension'] === $extension) {
                return $this->dossierDonnees . '/depot/' . $fichier['nom'];
            }
        }
        throw new ErreurSaisie('Ce fichier n\'est plus dans le dossier de dépôt.');
    }

    // ===== Contrôle des archives =====

    /** Paquet PrestaShop officiel : prestashop.zip (+ index.php) à la racine, ou install/index_cli.php. */
    public static function analyserPrestaShop(string $chemin): void
    {
        $noms = self::nomsZip($chemin);
        $double = in_array('prestashop.zip', $noms, true) && in_array('index.php', $noms, true);
        if (!$double && !in_array('install/index_cli.php', $noms, true)) {
            throw new ErreurSaisie('Ce ZIP n\'est pas un paquet d\'installation PrestaShop (ni prestashop.zip, ni installateur à l\'intérieur).');
        }
    }

    /** Paquet Duplicator : base dup-installer/dup-database__….sql et WordPress. @return ?string Version WordPress lue */
    public static function analyserSujetWordPress(string $chemin): ?string
    {
        $noms = self::nomsZip($chemin);
        $sql = preg_grep('#^dup-installer/dup-database__[A-Za-z0-9_-]+\.sql$#D', $noms);
        if ($sql === [] || !in_array('wp-includes/version.php', $noms, true)) {
            throw new ErreurSaisie('Ce ZIP n\'est pas un paquet Duplicator de site WordPress (base ou fichiers WordPress absents).');
        }
        $zip = new ZipArchive();
        if ($zip->open($chemin, ZipArchive::RDONLY) !== true) {
            return null;
        }
        $contenu = $zip->getFromName('wp-includes/version.php', 8192);
        $zip->close();

        return is_string($contenu) && preg_match('/\$wp_version\s*=\s*\'([0-9.]{1,20})\'/', $contenu, $trouve) === 1 ? $trouve[1] : null;
    }

    /** @return list<string> Noms des entrées, après refus de tout chemin dangereux */
    private static function nomsZip(string $chemin): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ErreurSaisie('L\'extension zip de PHP est absente du serveur : dépôt impossible (voir le guide d\'administration).');
        }
        $zip = new ZipArchive();
        if ($zip->open($chemin, ZipArchive::RDONLY) !== true) {
            throw new ErreurSaisie('Ce fichier n\'est pas une archive ZIP lisible.');
        }
        $noms = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nom = $zip->getNameIndex($i);
                if (!is_string($nom) || $nom === '' || str_contains($nom, "\0") || str_contains($nom, '\\')
                    || str_starts_with($nom, '/') || preg_match('#(^|/)\.\.(/|$)#', $nom) === 1) {
                    throw new ErreurSaisie('Archive refusée : elle contient un chemin qui sortirait du dossier du site.');
                }
                $noms[] = $nom;
            }
        } finally {
            $zip->close();
        }

        return $noms;
    }

    // ===== Outils =====

    /** Range le fichier sous le nom de son empreinte. @return string Empreinte */
    private function ranger(string $source, string $sousDossier, string $extension): string
    {
        $empreinte = hash_file('sha256', $source);
        if ($empreinte === false) {
            throw new ErreurSaisie('Fichier illisible.');
        }
        $cible = $this->dossierDonnees . '/' . $sousDossier . '/' . $empreinte . '.' . $extension;
        if (is_file($cible)) {
            throw new ErreurSaisie('Ce fichier est déjà enregistré (même contenu).');
        }
        $deplace = rename($source, $cible);
        if (!$deplace) {
            throw new ErreurSaisie('Le fichier n\'a pas pu être rangé sur le serveur.');
        }
        chmod($cible, 0600);

        return $empreinte;
    }

    private function effacer(string $sousDossier, string $empreinte, string $extension): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $empreinte) === 1) {
            $chemin = $this->dossierDonnees . '/' . $sousDossier . '/' . $empreinte . '.' . $extension;
            if (is_file($chemin)) {
                // Chemin construit ici : dossier fixe + empreinte vérifiée (64 chiffres hexadécimaux) + extension fixe.
                unlink($chemin); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
        }
    }

    /** @return array<array-key, mixed> */
    private function ligne(string $table, int $id): array
    {
        // Nom de table choisi dans le code (paquets ou documents), jamais par le visiteur.
        $requete = $this->pdo->prepare('SELECT * FROM ' . ($table === 'paquets' ? 'paquets' : 'documents') . ' WHERE id = :id'); // nosemgrep: outils.semgrep.requete-sql-construite-par-concatenation
        $requete->execute([':id' => $id]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            throw new ErreurSaisie('Élément introuvable.');
        }

        return $ligne;
    }

    private function paquetPrestaShop(string $version): ?int
    {
        $requete = $this->pdo->prepare("SELECT id FROM paquets WHERE type = 'prestashop' AND version = :v");
        $requete->execute([':v' => $version]);

        return Lecture::entier($requete->fetchColumn());
    }

    private function auteurObligatoire(): Compte
    {
        if ($this->auteur === null) {
            throw new ErreurSaisie('Action réservée à une personne connectée.');
        }

        return $this->auteur;
    }

    private function tracer(string $action, string $cible, string $detail): void
    {
        (new Journal($this->pdo))->ecrire('site', $action, 'succes', $this->auteur?->id, $this->auteur?->nomAffiche, $this->adresseIp, $cible, $detail);
    }

    private static function texteCourt(string $texte, int $max, string $libelle): string
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));
        if ($texte === '' || mb_strlen($texte, 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/', $texte) === 1) {
            throw new ErreurSaisie($libelle . ' est obligatoire (' . $max . ' caractères au plus).');
        }

        return $texte;
    }

    private static function taille(string $chemin, int $max, string $libelle): int
    {
        $taille = is_file($chemin) ? filesize($chemin) : false;
        if ($taille === false || $taille === 0) {
            throw new ErreurSaisie('Fichier vide ou absent.');
        }
        if ($taille > $max) {
            throw new ErreurSaisie('Fichier trop gros (' . $libelle . ' au plus).');
        }

        return $taille;
    }

    public static function lisible(int $octets): string
    {
        if ($octets >= 1024 * 1024 * 1024) {
            return number_format($octets / (1024 * 1024 * 1024), 1, ',', ' ') . ' Go';
        }
        if ($octets >= 1024 * 1024) {
            return number_format($octets / (1024 * 1024), 1, ',', ' ') . ' Mo';
        }

        return max(1, (int) round($octets / 1024)) . ' ko';
    }
}
