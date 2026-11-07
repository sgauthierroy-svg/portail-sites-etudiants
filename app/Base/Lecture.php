<?php

declare(strict_types=1);

namespace Portail\Base;

use PDOStatement;

/**
 * Lecture typée des résultats SQLite : toute valeur lue est vérifiée
 * avant usage (une base modifiée à la main ne doit pas tromper le portail).
 */
final class Lecture
{
    public static function entier(mixed $valeur): ?int
    {
        return is_int($valeur) ? $valeur : null;
    }

    public static function texte(mixed $valeur): ?string
    {
        return is_string($valeur) ? $valeur : null;
    }

    /** @return list<array<array-key, mixed>> Lignes du résultat (tableaux associatifs) */
    public static function lignes(PDOStatement|false $resultat): array
    {
        if ($resultat === false) {
            return [];
        }
        $toutes = $resultat->fetchAll();
        $lignes = [];
        foreach (array_keys($toutes) as $rang) {
            $ligne = self::tableau($toutes[$rang]);
            if ($ligne !== null) {
                $lignes[] = $ligne;
            }
        }

        return $lignes;
    }

    /** @return array<array-key, mixed>|null Ligne suivante, ou null */
    public static function ligne(PDOStatement $resultat): ?array
    {
        return self::tableau($resultat->fetch());
    }

    /** @return array<array-key, mixed>|null */
    private static function tableau(mixed $valeur): ?array
    {
        return is_array($valeur) ? $valeur : null;
    }
}
