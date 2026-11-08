<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Base\Lecture;

/**
 * Requête reçue, réduite à ce dont le portail a besoin.
 * Seules les valeurs textuelles des formulaires sont gardées.
 */
final class Requete
{
    /** Champs autorisés à dépasser 10 000 caractères, avec leur limite (texte collé de l'import). */
    private const CHAMPS_LONGS = ['texte' => 1_000_000];

    /**
     * @param array<string, string> $formulaire
     * @param array<string, list<string>> $listes Cases à cocher multiples (nom[] dans le formulaire)
     * @param array<string, array{chemin: string, nom: string, taille: int}|int> $fichiers Fichiers envoyés :
     *        vérifiés par is_uploaded_file(), ou code d'erreur PHP de l'envoi (UPLOAD_ERR_…)
     * @param bool $tropGros Envoi plus gros que ce que le serveur accepte : le formulaire a été vidé par PHP
     */
    public function __construct(
        public readonly string $methode,
        public readonly string $chemin,
        public readonly array $formulaire = [],
        public readonly string $adresseIp = '0.0.0.0',
        public readonly ?string $origine = null,
        public readonly array $listes = [],
        public readonly array $fichiers = [],
        public readonly bool $tropGros = false,
    ) {
    }

    /** @return array{chemin: string, nom: string, taille: int}|int|null */
    public function fichier(string $nom): array|int|null
    {
        return $this->fichiers[$nom] ?? null;
    }

    public function champ(string $nom): string
    {
        return $this->formulaire[$nom] ?? '';
    }

    /** @return list<string> */
    public function liste(string $nom): array
    {
        return $this->listes[$nom] ?? [];
    }

    public static function depuisServeur(): self
    {
        $formulaire = [];
        $listes = [];
        foreach ($_POST as $cle => $valeur) {
            if (!is_string($cle)) {
                continue;
            }
            if (is_string($valeur) && strlen($valeur) <= (self::CHAMPS_LONGS[$cle] ?? 10000)) {
                $formulaire[$cle] = $valeur;
            } elseif (is_array($valeur) && count($valeur) <= 1000) {
                $listes[$cle] = array_values(array_filter($valeur, static fn (mixed $v): bool => is_string($v) && strlen($v) <= 100));
            }
        }
        $fichiers = [];
        /** @var array<array-key, mixed> $envois Contenu fourni par PHP, vérifié champ par champ ci-dessous. */
        $envois = $_FILES;
        foreach (array_keys($envois) as $cle) {
            $fichier = Lecture::tableau($envois[$cle]);
            $erreur = Lecture::entier($fichier['error'] ?? null);
            if (!is_string($cle) || $fichier === null || $erreur === null) {
                continue; // Envois multiples (nom[]) : jamais utilisés par le portail.
            }
            $temporaire = Lecture::texte($fichier['tmp_name'] ?? null);
            $nomOrigine = Lecture::texte($fichier['name'] ?? null);
            // Le fichier reçu est encore dans le dossier temporaire du serveur, hors du portail :
            // seul is_uploaded_file() y touche ici (les autres lectures y sont interdites, open_basedir).
            if ($erreur === UPLOAD_ERR_OK && $temporaire !== null && $nomOrigine !== null && is_uploaded_file($temporaire)) {
                $fichiers[$cle] = ['chemin' => $temporaire, 'nom' => mb_substr($nomOrigine, 0, 200, 'UTF-8'), 'taille' => (int) Lecture::entier($fichier['size'] ?? 0)];
            } elseif ($erreur !== UPLOAD_ERR_NO_FILE) {
                $fichiers[$cle] = $erreur;
            }
        }
        $longueur = (int) self::serveur('CONTENT_LENGTH', '0');
        $limite = self::octets((string) ini_get('post_max_size'));
        $tropGros = strtoupper(self::serveur('REQUEST_METHOD', 'GET')) === 'POST' && $limite > 0 && $longueur > $limite;
        $chemin = parse_url(self::serveur('REQUEST_URI', '/'), PHP_URL_PATH);
        $ip = self::serveur('REMOTE_ADDR', '0.0.0.0');
        $origine = self::serveur('HTTP_ORIGIN', '');

        return new self(
            strtoupper(self::serveur('REQUEST_METHOD', 'GET')),
            is_string($chemin) ? $chemin : '/',
            $formulaire,
            filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0',
            $origine === '' ? null : $origine,
            $listes,
            $fichiers,
            $tropGros,
        );
    }

    /** « 128M » → 134217728 (notation des réglages PHP). 0 : pas de limite. */
    public static function octets(string $reglage): int
    {
        $reglage = trim($reglage);
        if (preg_match('/^([0-9]+)\s*([KMG]?)$/i', $reglage, $parties) !== 1) {
            return 0;
        }
        $facteur = match (strtoupper($parties[2])) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };

        return (int) $parties[1] * $facteur;
    }

    /** Valeur de $_SERVER, toujours vérifiée : elle vient du visiteur ou du serveur web. */
    private static function serveur(string $cle, string $defaut): string
    {
        $valeur = $_SERVER[$cle] ?? null;

        return is_string($valeur) ? $valeur : $defaut;
    }
}
