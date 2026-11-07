<?php

declare(strict_types=1);

namespace Portail\Securite;

/**
 * Mots de passe des comptes du portail.
 *
 * Hachage Argon2id (bcrypt si le serveur n'en dispose pas), jamais réversible.
 * Règles : 12 caractères au moins (OWASP ASVS 4.0, exigence 2.1.1), 128 au
 * plus, différent de l'identifiant, absent de la liste des plus courants.
 * Pas de règle de composition imposée (ASVS 2.1.9) : une phrase longue vaut
 * mieux qu'un mot court compliqué.
 */
final class MotsDePasse
{
    public const LONGUEUR_MIN = 12;
    public const LONGUEUR_MAX = 128;

    /** Extrait des mots de passe les plus utilisés (longueur ≥ 12). */
    private const COURANTS = [
        '123456789012', '1234567890ab', 'azertyuiopqs', 'qwertyuiopas', 'motdepasse123',
        'motdepasse1234', 'password1234', 'password12345', 'azerty123456', 'qwerty123456',
        'aaaaaaaaaaaa', '000000000000', '111111111111', 'iloveyou1234', 'administrateur',
        'supsallanches', 'sallanches74', 'bienvenue123', 'bienvenue1234', 'portail12345',
    ];

    /**
     * Mot de passe provisoire, à transmettre puis à changer à la première connexion :
     * 4 groupes de 4 caractères sans lettres ambiguës (ni l, ni o, ni 0, ni 1), soit
     * environ 79 bits de hasard. Exemple : « kq7m-vx3d-p9ta-hc4w ».
     */
    public static function provisoire(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $groupes = [];
        for ($g = 0; $g < 4; ++$g) {
            $groupe = '';
            for ($i = 0; $i < 4; ++$i) {
                $groupe .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groupes[] = $groupe;
        }

        return implode('-', $groupes);
    }

    public static function hacher(string $motDePasse): string
    {
        return password_hash($motDePasse, self::algorithme());
    }

    public static function verifier(string $motDePasse, string $hache): bool
    {
        return password_verify($motDePasse, $hache);
    }

    public static function doitEtreRehache(string $hache): bool
    {
        return password_needs_rehash($hache, self::algorithme());
    }

    /**
     * Empreinte factice, vérifiée quand l'identifiant n'existe pas : la réponse
     * prend le même temps, et ne révèle donc pas quels comptes existent.
     */
    public static function empreinteFactice(): string
    {
        static $factice = null;
        if (!is_string($factice)) {
            $factice = password_hash(bin2hex(random_bytes(16)), self::algorithme());
        }

        return $factice;
    }

    /** @return string|null Raison du refus, ou null si le mot de passe est accepté */
    public static function refus(string $motDePasse, string $identifiant): ?string
    {
        $longueur = mb_strlen($motDePasse, 'UTF-8');
        if ($longueur < self::LONGUEUR_MIN) {
            return 'Le mot de passe doit compter au moins ' . self::LONGUEUR_MIN . ' caractères.';
        }
        if ($longueur > self::LONGUEUR_MAX) {
            return 'Le mot de passe ne doit pas dépasser ' . self::LONGUEUR_MAX . ' caractères.';
        }
        $minuscule = mb_strtolower($motDePasse, 'UTF-8');
        if (str_contains($minuscule, mb_strtolower($identifiant, 'UTF-8'))) {
            return 'Le mot de passe ne doit pas contenir votre identifiant.';
        }
        if (in_array($minuscule, self::COURANTS, true) || count(array_unique(mb_str_split($motDePasse))) < 4) {
            return 'Ce mot de passe est trop courant ou trop simple.';
        }

        return null;
    }

    private static function algorithme(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
