<?php

declare(strict_types=1);

namespace Portail\Web;

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
     */
    public function __construct(
        public readonly string $methode,
        public readonly string $chemin,
        public readonly array $formulaire = [],
        public readonly string $adresseIp = '0.0.0.0',
        public readonly ?string $origine = null,
        public readonly array $listes = [],
    ) {
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
        );
    }

    /** Valeur de $_SERVER, toujours vérifiée : elle vient du visiteur ou du serveur web. */
    private static function serveur(string $cle, string $defaut): string
    {
        $valeur = $_SERVER[$cle] ?? null;

        return is_string($valeur) ? $valeur : $defaut;
    }
}
