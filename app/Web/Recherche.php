<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Etudiants\Etudiant;

/**
 * Recherche rapide, en haut de chaque page (décisions du 17/11/2026 : une seule barre, pour tous les rôles ; demande du
 * 18/11/2026 : présente dès l'accueil de l'enseignant). La liste propose les situations courantes, les pages du menu et les
 * étudiants trouvés. Sans JavaScript, « Entrée » ouvre la page de résultats (/recherche).
 */
final class Recherche
{
    /**
     * Situations proposées selon le rôle (décision du 17/11/2026).
     *
     * @return list<array{titre: string, adresse: string, texte: string}>
     */
    public static function situations(Compte $compte): array
    {
        $peut = static fn (string $droit): bool => Droits::peut($compte->role, $droit);
        $demander = $peut(Droits::DEMANDER) && !$peut(Droits::TRAITER_DEMANDES);
        $liste = [];
        if ($peut(Droits::IMPORTER) || $peut(Droits::CREER_ETUDIANT)) {
            $liste[] = ['titre' => 'Un étudiant arrive', 'adresse' => $peut(Droits::IMPORTER) ? '/etudiants/import' : '/etudiants/nouveau',
                'texte' => 'Ajouter des étudiants, puis créer leur site WordPress.'];
        }
        if ($peut(Droits::VOIR_FICHE)) {
            $liste[] = ['titre' => 'Un étudiant part ou redouble', 'adresse' => '/etudiants',
                'texte' => 'Ouvrir sa fiche : changer sa session, ' . ($peut(Droits::SUPPRIMER) ? 'supprimer ses sites.' : 'demander la suppression de ses sites.')];
        }
        if ($peut(Droits::SUJETS_EXAMEN)) {
            $liste[] = ['titre' => 'Préparer un BTS blanc', 'adresse' => '/sites/sujet', 'texte' => 'Site modèle d\'examen : charger le sujet, le déployer, clôturer.'];
        }
        if ($demander) {
            $liste[] = ['titre' => 'Problème de site ou de boutique', 'adresse' => '/demandes/nouvelle', 'texte' => 'Le signaler au service informatique.'];
        } elseif ($peut(Droits::CONSULTER)) {
            $liste[] = ['titre' => 'Problème de site ou de boutique', 'adresse' => '/sites', 'texte' => 'Liste des sites : état de chaque site.'];
        }
        if ($peut(Droits::DOCUMENTS)) {
            $liste[] = ['titre' => 'Trouver un document', 'adresse' => '/bibliotheque', 'texte' => 'Référentiel, consignes, guides (PDF).'];
        }
        if ($demander) {
            $liste[] = ['titre' => 'Faire une demande au service informatique', 'adresse' => '/demandes/nouvelle', 'texte' => 'Suppression ou problème (SAV).'];
        }

        return $liste;
    }

    /**
     * Pages du menu, pour les trouver par leur nom (« certificats », « journal »…).
     *
     * @return list<array{titre: string, adresse: string, texte: string}>
     */
    public static function pages(Compte $compte): array
    {
        $liste = [];
        foreach (array_slice(Pages::destinations($compte), 1) as [$adresse, , $libelle, $description]) {
            $liste[] = ['titre' => $libelle, 'adresse' => $adresse, 'texte' => $description];
        }

        return $liste;
    }

    /**
     * Étudiants proposés : seulement pour les rôles qui ouvrent les fiches.
     *
     * @param list<Etudiant> $etudiants
     * @return list<array{titre: string, adresse: string, texte: string}>
     */
    public static function etudiants(Compte $compte, array $etudiants): array
    {
        if (!Droits::peut($compte->role, Droits::VOIR_FICHE)) {
            return [];
        }
        $liste = [];
        foreach ($etudiants as $etudiant) {
            $liste[] = ['titre' => $etudiant->nomComplet, 'adresse' => '/etudiants/' . $etudiant->id, 'texte' => $etudiant->classe()];
        }

        return $liste;
    }

    /**
     * Données de la liste déroulante, lues par public/recherche.js (bloc JSON jamais exécuté : CSP script-src 'self').
     *
     * @param list<array{titre: string, adresse: string, texte: string}> $etudiants
     */
    public static function donnees(Compte $compte, array $etudiants): string
    {
        $json = json_encode(['situations' => self::situations($compte), 'pages' => self::pages($compte), 'etudiants' => $etudiants],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '{}' : $json;
    }

    /**
     * Éléments dont le texte contient chaque mot cherché (sans tenir compte des majuscules ni des accents).
     *
     * @param list<array{titre: string, adresse: string, texte: string}> $elements
     * @return list<array{titre: string, adresse: string, texte: string}>
     */
    public static function filtrer(array $elements, string $cherche): array
    {
        $mots = preg_split('/\s+/u', self::normaliser($cherche), -1, PREG_SPLIT_NO_EMPTY);
        if ($mots === false || $mots === []) {
            return $elements;
        }

        return array_values(array_filter($elements, static function (array $element) use ($mots): bool {
            $texte = self::normaliser($element['titre'] . ' ' . $element['texte']);
            foreach ($mots as $mot) {
                if (!str_contains($texte, $mot)) {
                    return false;
                }
            }

            return true;
        }));
    }

    public static function normaliser(string $texte): string
    {
        $texte = mb_strtolower($texte, 'UTF-8');

        return strtr($texte, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
            '’' => "'", '-' => ' ']);
    }

    /**
     * Page de résultats (sans JavaScript, ou après « Entrée »).
     *
     * @param list<array{titre: string, adresse: string, texte: string}> $etudiants
     */
    public static function page(Compte $compte, string $cherche, array $etudiants): string
    {
        $cherche = trim(mb_substr($cherche, 0, 100, 'UTF-8'));
        $trouves = self::filtrer($etudiants, $cherche);
        $actions = self::filtrer(array_merge(self::situations($compte), self::pages($compte)), $cherche);
        $contenu = ($cherche === '' ? '' : '<p>Recherche : « ' . Html::e($cherche) . ' ».</p>')
            . self::bloc('Étudiants', $cherche === '' ? [] : $trouves)
            . self::bloc($cherche === '' ? 'Que voulez-vous faire ?' : 'Actions et pages', $cherche === '' ? self::situations($compte) : $actions);
        if ($cherche !== '' && $trouves === [] && $actions === []) {
            $contenu .= '<p>Rien trouvé. Essayez une partie du nom, ou ouvrez la <a href="/etudiants">liste des étudiants</a>.</p>';
        }

        return Pages::page('Recherche', $contenu);
    }

    /** @param list<array{titre: string, adresse: string, texte: string}> $liste */
    private static function bloc(string $titre, array $liste): string
    {
        if ($liste === []) {
            return '';
        }
        $lignes = '';
        foreach (array_slice($liste, 0, 50) as $element) {
            $lignes .= '<li><a href="' . Html::e($element['adresse']) . '">' . Html::e($element['titre']) . '</a> <span class="discret">'
                . Html::e($element['texte']) . '</span></li>';
        }

        return '<h2>' . Html::e($titre) . ' (' . count($liste) . ')</h2><ul class="resultats">' . $lignes . '</ul>';
    }
}
