<?php

declare(strict_types=1);

namespace Portail\Securite;

/**
 * Mots de passe des comptes du portail.
 *
 * Hachage Argon2id (bcrypt si le serveur n'en dispose pas), jamais réversible.
 * Règles : 128 caractères au plus, différent de l'identifiant, absent de la
 * liste des plus courants, et l'une des trois formes données en exemple par
 * la CNIL (délibération n° 2022-100 du 21/07/2022 ; décision du 08/11/2026) :
 *   A. 12 caractères au moins : majuscules, minuscules, chiffres et caractères spéciaux ;
 *   B. 14 caractères au moins : majuscules, minuscules et chiffres ;
 *   C. une phrase de passe d'au moins 7 mots (mot = 2 caractères au moins).
 * Note : OWASP ASVS 4.0 (2.1.9) déconseille les règles de composition ; la
 * forme C laisse la liberté d'une phrase longue sans composition.
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
     * 4 groupes de 4 caractères sans caractères ambigus (ni l, I, o, O, 0, 1), soit
     * environ 92 bits de hasard (seuil CNIL : 80). Exemple : « kQ7m-Vx3d-p9TA-hc4W ».
     * Les tirets en font un mot de passe de forme A (GestionComptes vérifie la forme).
     */
    public static function provisoire(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
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
        if (!self::formeCnil($motDePasse, $longueur)) {
            return self::FORMES;
        }

        return null;
    }

    /** Les trois formes acceptées, telles qu'affichées aux utilisateurs. */
    public const FORMES = 'Choisissez l\'une des trois formes recommandées par la CNIL : '
        . '12 caractères au moins avec majuscules, minuscules, chiffres et caractères spéciaux ; '
        . 'ou 14 caractères au moins avec majuscules, minuscules et chiffres ; '
        . 'ou une phrase d\'au moins 7 mots.';

    private static function formeCnil(string $motDePasse, int $longueur): bool
    {
        $minuscules = preg_match('/\p{Ll}/u', $motDePasse) === 1;
        $majuscules = preg_match('/\p{Lu}/u', $motDePasse) === 1;
        $chiffres = preg_match('/\p{N}/u', $motDePasse) === 1;
        $speciaux = preg_match('/[^\p{L}\p{N}\s]/u', $motDePasse) === 1;
        if ($longueur >= 12 && $minuscules && $majuscules && $chiffres && $speciaux) {
            return true;
        }
        if ($longueur >= 14 && $minuscules && $majuscules && $chiffres) {
            return true;
        }
        $mots = preg_split('/\s+/u', trim($motDePasse), -1, PREG_SPLIT_NO_EMPTY);
        $mots = $mots === false ? [] : $mots;

        return count(array_filter($mots, static fn (string $mot): bool => mb_strlen($mot, 'UTF-8') >= 2)) >= 7;
    }

    private static function algorithme(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
