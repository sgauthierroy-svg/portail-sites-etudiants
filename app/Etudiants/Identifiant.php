<?php

declare(strict_types=1);

namespace Portail\Etudiants;

use Portail\Noyau\ErreurSaisie;

/**
 * Calcul de l'identifiant d'un étudiant (sous-domaine) et de son identifiant
 * WordPress, selon la même règle que la Google Sheets (formules corrigées des
 * colonnes E et F de ListeGlobale, cahier des charges du 07/11/2026) :
 *
 * - nom : premier nom de famille, particules soudées au mot suivant
 *   (DE LA FONTAINE → delafontaine), coupé au premier tiret, sans accents,
 *   en minuscules, 11 lettres au plus ;
 * - prénom : ses 2 premières lettres ;
 * - homonyme : chiffre 2, 3… ajouté ;
 * - identifiant WordPress : les mêmes lettres, la première et la dernière
 *   en majuscule (gauthierst → GauthiersT), chiffre éventuel conservé.
 *
 * Limites reprises volontairement de la feuille, pour que le portail et la
 * feuille donnent toujours le même résultat (vérifié le 07/11/2026 sur 17 noms) :
 * - le prénom est le dernier mot du nom complet (« Jean Pierre » donne pi) ;
 * - les lettres hors de la liste des accents (ý, Š, Ć, Ø, Œ…) sont supprimées ;
 * - une espace après l'apostrophe (« D' ALMEIDA ») arrête le nom à « d ».
 */
final class Identifiant
{
    public const LETTRES_NOM_MAX = 11;
    public const LETTRES_PRENOM = 2;

    /** Format des nouveaux identifiants : 13 lettres au plus, chiffre éventuel (homonyme). */
    public const FORMAT = '/^[a-z]{2,13}[2-9]?$/D';

    /**
     * Début du nom de famille : particules (suivies d'une espace ou d'une apostrophe),
     * préfixes d', l', o', n', puis le premier mot jusqu'au tiret, à l'espace ou à l'apostrophe.
     * Copie de l'expression REGEXEXTRACT de la colonne E de la feuille.
     */
    public const MOTIF_NOM = "/^(?:(?:de|du|des|le|la|del|van|von|ben|al|el|da|dos|di|der)[ ']|(?:d|l|o|n)')*[^- ']+/iu";

    /**
     * Accents remplacés, copie des SUBSTITUTE/REGEXREPLACE de la feuille.
     * Toute autre lettre (ý, Š, Ć, Ø, Œ…) est supprimée, comme dans la feuille.
     */
    private const ACCENTS = [
        '/[àáâãäå]/u' => 'a',
        '/ç/u' => 'c',
        '/[èéêë]/u' => 'e',
        '/[ìíîï]/u' => 'i',
        '/[òóôõö]/u' => 'o',
        '/[ùúûü]/u' => 'u',
        '/ÿ/u' => 'y',
        '/ñ/u' => 'n',
    ];

    /**
     * Lettres de l'identifiant, sans chiffre d'homonyme, à partir du nom complet
     * tel qu'exporté d'EcoleDirecte (« GAUTHIER ROY Stéphanie »).
     * Même enchaînement que la feuille : espaces nettoyées, dernier mot = prénom,
     * le reste passe par MOTIF_NOM.
     */
    public static function depuisNomComplet(string $nomComplet): string
    {
        $texte = trim(preg_replace('/ +/', ' ', $nomComplet) ?? '');
        if (preg_match('/^(.+) ([^ ]+)$/Dsu', $texte, $parties) !== 1) {
            throw new ErreurSaisie('Le nom complet doit contenir au moins un nom et un prénom.');
        }
        $debutNom = preg_match(self::MOTIF_NOM, $parties[1], $trouve) === 1 ? $trouve[0] : '';
        $lettresNom = substr(self::lettres(str_replace(["'", ' '], '', $debutNom)), 0, self::LETTRES_NOM_MAX);
        $lettresPrenom = substr(self::lettres($parties[2]), 0, self::LETTRES_PRENOM);
        if ($lettresNom === '' || $lettresPrenom === '') {
            throw new ErreurSaisie('Aucune lettre exploitable dans le nom ou le prénom : choisissez l\'identifiant à la main.');
        }

        return $lettresNom . $lettresPrenom;
    }

    /**
     * Même calcul, à partir du nom et du prénom saisis séparément (ajout manuel) :
     * on reconstitue « NOM Prénom », exactement ce que la feuille recevrait.
     */
    public static function depuisNomEtPrenom(string $nom, string $prenom): string
    {
        $nom = trim(preg_replace('/\s+/u', ' ', $nom) ?? '');
        $prenom = trim(preg_replace('/\s+/u', ' ', $prenom) ?? '');
        if ($nom === '' || $prenom === '') {
            throw new ErreurSaisie('Le nom et le prénom sont obligatoires.');
        }

        return self::depuisNomComplet(mb_strtoupper($nom, 'UTF-8') . ' ' . $prenom);
    }

    /**
     * Premier identifiant libre : les lettres, puis les lettres suivies de 2, 3… 9.
     *
     * @param callable(string): bool $estPris
     */
    public static function premierLibre(string $lettres, callable $estPris): string
    {
        if (!$estPris($lettres)) {
            return $lettres;
        }
        for ($chiffre = 2; $chiffre <= 9; $chiffre++) {
            if (!$estPris($lettres . $chiffre)) {
                return $lettres . $chiffre;
            }
        }
        throw new ErreurSaisie('Plus de 9 homonymes pour « ' . $lettres . ' » : choisissez l\'identifiant à la main.');
    }

    /** Identifiant WordPress : première et dernière lettre en majuscule, chiffre conservé. */
    public static function wordpress(string $identifiant): string
    {
        if (preg_match('/^([a-z]+)([0-9]?)$/D', $identifiant, $parties) !== 1) {
            throw new ErreurSaisie('Identifiant invalide : lettres minuscules, puis un chiffre éventuel.');
        }
        $lettres = $parties[1];
        if (strlen($lettres) === 1) {
            return strtoupper($lettres) . $parties[2];
        }

        return strtoupper($lettres[0]) . substr($lettres, 1, -1) . strtoupper(substr($lettres, -1)) . $parties[2];
    }

    public static function formatValide(string $identifiant): bool
    {
        return preg_match(self::FORMAT, $identifiant) === 1;
    }

    /** Minuscules, accents de la liste remplacés, tout ce qui n'est pas a-z supprimé. */
    private static function lettres(string $texte): string
    {
        $texte = mb_strtolower($texte, 'UTF-8');
        foreach (self::ACCENTS as $motif => $lettre) {
            $texte = preg_replace($motif, $lettre, $texte) ?? '';
        }

        return preg_replace('/[^a-z]/', '', $texte) ?? '';
    }
}
