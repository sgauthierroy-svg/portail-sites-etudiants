<?php

declare(strict_types=1);

namespace Portail\Comptes;

use UnexpectedValueException;

/**
 * Un compte du portail, tel qu'enregistré en base.
 */
final class Compte
{
    public const ROLES = ['administrateur', 'enseignant', 'administratif', 'consultation'];

    public const LIBELLES_ROLES = [
        'administrateur' => 'Administrateur',
        'enseignant' => 'Enseignant',
        'administratif' => 'Administratif',
        // Rôle de la direction adjointe (décision du 07/11/2026). La valeur enregistrée
        // reste « consultation » : la base n'a pas à être reconstruite pour un libellé.
        'consultation' => 'Direction (suivi et secours)',
    ];

    /** @param 'administrateur'|'enseignant'|'administratif'|'consultation' $role */
    public function __construct(
        public readonly int $id,
        public readonly string $identifiant,
        public readonly string $nomAffiche,
        public readonly string $email,
        public readonly string $role,
        public readonly string $motDePasseHache,
        public readonly ?string $secret2faChiffre,
        public readonly bool $doubleAuthActive,
        public readonly ?int $dernierPas2fa,
        public readonly int $echecsConsecutifs,
        public readonly ?int $bloqueJusqua,
        public readonly bool $actif,
        public readonly bool $doitChangerMotDePasse,
        public readonly int $generation = 0,
    ) {
    }

    /** @param array<array-key, mixed> $ligne */
    public static function depuisLigne(array $ligne): self
    {
        $role = self::texte($ligne, 'role');
        if (!in_array($role, self::ROLES, true)) {
            throw new UnexpectedValueException('Rôle inconnu en base.');
        }
        $bloque = isset($ligne['bloque_jusqu_a']) && is_string($ligne['bloque_jusqu_a']) ? $ligne['bloque_jusqu_a'] : null;
        $secret = isset($ligne['secret_2fa_chiffre']) && is_string($ligne['secret_2fa_chiffre']) ? $ligne['secret_2fa_chiffre'] : null;
        $pas = isset($ligne['dernier_pas_2fa']) && is_int($ligne['dernier_pas_2fa']) ? $ligne['dernier_pas_2fa'] : null;

        return new self(
            self::entier($ligne, 'id'),
            self::texte($ligne, 'identifiant'),
            self::texte($ligne, 'nom_affiche'),
            self::texte($ligne, 'email'),
            $role,
            self::texte($ligne, 'mot_de_passe_hache'),
            $secret,
            self::entier($ligne, 'double_auth_active') === 1,
            $pas,
            self::entier($ligne, 'echecs_consecutifs'),
            $bloque === null ? null : self::instant($bloque),
            self::entier($ligne, 'actif') === 1,
            self::entier($ligne, 'doit_changer_mdp') === 1,
            self::entier($ligne, 'generation'),
        );
    }

    public function estBloque(int $maintenant): bool
    {
        return $this->bloqueJusqua !== null && $this->bloqueJusqua > $maintenant;
    }

    public function libelleRole(): string
    {
        return self::LIBELLES_ROLES[$this->role];
    }

    public static function instant(string $date): int
    {
        $instant = strtotime($date);
        if ($instant === false) {
            throw new UnexpectedValueException('Date illisible en base.');
        }

        return $instant;
    }

    public static function date(int $instant): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $instant);
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
