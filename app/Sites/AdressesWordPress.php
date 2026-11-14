<?php

declare(strict_types=1);

namespace Portail\Sites;

use RuntimeException;

/**
 * Remplacement des adresses d'un WordPress copié d'un autre emplacement (installation d'un sujet
 * sur le site modèle, déploiement du modèle dans les /WP2). WP-CLI search-replace traite les
 * données sérialisées de WordPress ; la colonne guid n'est pas exclue ici, comme dans
 * deploy_duplicator.sh validé en octobre 2026 (sites neufs, sans flux RSS suivis).
 */
final class AdressesWordPress
{
    /**
     * Adresses du site d'origine (lues dans la base du site déjà importée) → $nouvelle, en http comme
     * en https, et sous la forme « échappée » (https:\/\/…) qu'utilisent les blocs et les
     * constructeurs de pages ; puis le chemin du dossier d'origine sur le serveur, s'il s'agissait
     * d'un site de ce compte.
     */
    public static function remplacer(LigneWordPress $wp, string $chemin, string $nouvelle, string $dossierPersonnel, string $final): void
    {
        $nouvelle = rtrim($nouvelle, '/');
        $origines = [];
        foreach (['siteurl', 'home'] as $option) {
            $valeur = rtrim(trim($wp->lancer($chemin, ['option', 'get', $option])), '/');
            if (preg_match('#^https?://([A-Za-z0-9.-]+)(/[A-Za-z0-9/_.~-]*)?$#D', $valeur, $morceaux) !== 1) {
                throw new RuntimeException('Adresse du site d\'origine illisible (' . $option . ').');
            }
            $origines[$morceaux[1] . ($morceaux[2] ?? '')] = [$morceaux[1], $morceaux[2] ?? ''];
        }
        // Les plus longues d'abord : « hote/WP » avant « hote ».
        uksort($origines, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($origines as $hoteChemin => [$hote, $sousDossier]) {
            foreach (['http://', 'https://'] as $schema) {
                $ancienne = $schema . $hoteChemin;
                if ($ancienne !== $nouvelle) {
                    $wp->lancer($chemin, ['search-replace', $ancienne, $nouvelle, '--all-tables-with-prefix', '--quiet'], 600);
                    $wp->lancer($chemin, ['search-replace', str_replace('/', '\\/', $ancienne), str_replace('/', '\\/', $nouvelle),
                        '--all-tables-with-prefix', '--quiet'], 600);
                }
            }
            if (preg_match('/^[a-z0-9-]+\.' . preg_quote(Releve::DOMAINE, '/') . '$/D', $hote) === 1) {
                $cheminOrigine = $dossierPersonnel . '/' . $hote . $sousDossier;
                if ($cheminOrigine !== $final) {
                    $wp->lancer($chemin, ['search-replace', $cheminOrigine, $final, '--all-tables-with-prefix', '--quiet'], 600);
                }
            }
        }
    }
}
