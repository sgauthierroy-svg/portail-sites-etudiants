<?php

declare(strict_types=1);

namespace Portail\Web;

/**
 * Requête reçue, réduite à ce dont le portail a besoin.
 * Seules les valeurs textuelles des formulaires sont gardées.
 */
final class Requete
{
    /** @param array<string, string> $formulaire */
    public function __construct(
        public readonly string $methode,
        public readonly string $chemin,
        public readonly array $formulaire = [],
        public readonly string $adresseIp = '0.0.0.0',
        public readonly ?string $origine = null,
    ) {
    }

    public function champ(string $nom): string
    {
        return $this->formulaire[$nom] ?? '';
    }

    public static function depuisServeur(): self
    {
        $formulaire = [];
        foreach ($_POST as $cle => $valeur) {
            if (is_string($cle) && is_string($valeur) && strlen($valeur) <= 10000) {
                $formulaire[$cle] = $valeur;
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
        );
    }

    /** Valeur de $_SERVER, toujours vérifiée : elle vient du visiteur ou du serveur web. */
    private static function serveur(string $cle, string $defaut): string
    {
        $valeur = $_SERVER[$cle] ?? null;

        return is_string($valeur) ? $valeur : $defaut;
    }
}
