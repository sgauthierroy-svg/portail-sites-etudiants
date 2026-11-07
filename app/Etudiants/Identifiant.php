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
 * feuille donnent toujours le même résultat :
 * - le prénom est le dernier mot du nom complet (« Jean Pierre » donne pi) ;
 * - les lettres hors de la liste des accents (Š, Ć, Ø, Œ…) sont supprimées.
 */
final class Identifiant
{
    public const LETTRES_NOM_MAX = 11;
    public const LETTRES_PRENOM = 2;

    /** Format des nouveaux identifiants : 13 lettres au plus, chiffre éventuel (homonyme). */
    public const FORMAT = '/^[a-z]{2,13}[2-9]?$/D';

    /** Particules soudées au mot qui suit. */
    public const PARTICULES = [
        'de', 'du', 'des', 'le', 'la', 'del', 'van', 'von', 'ben', 'al', 'el', 'da', 'dos', 'di', 'der',
        "d'", "l'", "o'", "n'",
    ];

    /** Lettres accentuées reconnues ; toute autre lettre est supprimée. */
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        '’' => "'", 'ʼ' => "'",
    ];

    /**
     * Lettres de l'identifiant, sans chiffre d'homonyme, à partir du nom complet
     * tel qu'exporté d'EcoleDirecte (« GAUTHIER ROY Stéphanie »).
     */
    public static function depuisNomComplet(string $nomComplet): string
    {
        $mots = self::mots($nomComplet);
        if (count($mots) < 2) {
            throw new ErreurSaisie('Le nom complet doit contenir au moins un nom et un prénom.');
        }
        $prenom = array_pop($mots);

        return self::assembler($mots, $prenom);
    }

    /** Même calcul, à partir du nom et du prénom saisis séparément (ajout manuel). */
    public static function depuisNomEtPrenom(string $nom, string $prenom): string
    {
        $motsNom = self::mots($nom);
        $motsPrenom = self::mots($prenom);
        if ($motsNom === [] || $motsPrenom === []) {
            throw new ErreurSaisie('Le nom et le prénom sont obligatoires.');
        }

        // Comme la feuille, qui reçoit « NOM Prénom » : le prénom retenu est le dernier mot.
        return self::assembler($motsNom, $motsPrenom[count($motsPrenom) - 1]);
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

    /**
     * @param list<string> $motsNom
     */
    private static function assembler(array $motsNom, string $prenom): string
    {
        // Particules soudées : on avance tant que le mot est une particule
        // et qu'il en reste au moins un après lui.
        $nom = '';
        $i = 0;
        $dernier = count($motsNom) - 1;
        while ($i < $dernier && in_array($motsNom[$i], self::PARTICULES, true)) {
            $nom .= $motsNom[$i];
            $i++;
        }
        $nom .= $motsNom[$i];

        $lettresNom = substr(self::lettres(self::avantTiret($nom)), 0, self::LETTRES_NOM_MAX);
        $lettresPrenom = substr(self::lettres(self::avantTiret($prenom)), 0, self::LETTRES_PRENOM);
        if ($lettresNom === '' || $lettresPrenom === '') {
            throw new ErreurSaisie('Aucune lettre exploitable dans le nom ou le prénom : choisissez l\'identifiant à la main.');
        }

        return $lettresNom . $lettresPrenom;
    }

    /** @return list<string> Mots en minuscules, accents remplacés */
    private static function mots(string $texte): array
    {
        $texte = strtr(mb_strtolower(trim($texte), 'UTF-8'), self::ACCENTS);
        $mots = preg_split('/\s+/u', $texte, -1, PREG_SPLIT_NO_EMPTY);

        return $mots === false ? [] : $mots;
    }

    private static function avantTiret(string $mot): string
    {
        $position = strpos($mot, '-');

        return $position === false ? $mot : substr($mot, 0, $position);
    }

    private static function lettres(string $mot): string
    {
        return preg_replace('/[^a-z]/', '', $mot) ?? '';
    }
}
