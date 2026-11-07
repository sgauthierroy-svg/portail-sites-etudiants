<?php

declare(strict_types=1);

namespace Portail\Etudiants;

use UnexpectedValueException;

/**
 * Fiche d'un étudiant, avec le nom de son régime et l'année de sa session.
 */
final class Etudiant
{
    public function __construct(
        public readonly int $id,
        public readonly string $nomComplet,
        public readonly string $identifiant,
        public readonly string $identifiantWp,
        public readonly string $email,
        public readonly int $regimeId,
        public readonly string $regime,
        public readonly int $sessionId,
        public readonly int $session,
        public readonly string $origine,
        public readonly string $ajouteLe,
    ) {
    }

    /** Classe affichée : « NDRC ALT – session 2028 ». */
    public function classe(): string
    {
        return $this->regime . ' – session ' . $this->session;
    }

    /** @param array<array-key, mixed> $ligne */
    public static function depuisLigne(array $ligne): self
    {
        return new self(
            self::entier($ligne, 'id'),
            self::texte($ligne, 'nom_complet'),
            self::texte($ligne, 'identifiant'),
            self::texte($ligne, 'identifiant_wp'),
            self::texte($ligne, 'email'),
            self::entier($ligne, 'regime_id'),
            self::texte($ligne, 'regime'),
            self::entier($ligne, 'session_id'),
            self::entier($ligne, 'annee'),
            self::texte($ligne, 'origine'),
            self::texte($ligne, 'ajoute_le'),
        );
    }

    /** @param array<array-key, mixed> $ligne */
    private static function texte(array $ligne, string $cle): string
    {
        $valeur = $ligne[$cle] ?? null;
        if (!is_string($valeur)) {
            throw new UnexpectedValueException('Champ ' . $cle . ' invalide en base.');
        }

        return $valeur;
    }

    /** @param array<array-key, mixed> $ligne */
    private static function entier(array $ligne, string $cle): int
    {
        $valeur = $ligne[$cle] ?? null;
        if (!is_int($valeur)) {
            throw new UnexpectedValueException('Champ ' . $cle . ' invalide en base.');
        }

        return $valeur;
    }
}
