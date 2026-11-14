<?php

declare(strict_types=1);

namespace Portail\Travaux;

/**
 * Récapitulatif HTML envoyé à l'administrateur après une création de sites : sélectionné dans la
 * messagerie puis collé dans Google Sheets, il garde une valeur par cellule (demande du 11/11/2026).
 * Un second tableau, à ne pas copier, rappelle à quel étudiant correspond chaque ligne.
 */
final class Recapitulatif
{
    /**
     * @param list<string> $colonnes En-têtes exacts de la feuille des accès
     * @param list<list<string>> $lignes Valeurs des colonnes, dans l'ordre alphabétique des étudiants
     * @param list<list<string>> $reperes Nom de l'étudiant et identifiant, dans le même ordre
     */
    public static function html(string $introduction, array $colonnes, array $lignes, array $reperes): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $numerotes = [];
        foreach ($reperes as $rang => $repere) {
            $numerotes[] = array_merge([(string) ($rang + 1)], $repere);
        }

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif">'
            . '<p>' . $e($introduction) . '</p>'
            . '<p><strong>1. À copier dans la feuille des accès.</strong> Sélectionnez les lignes de ce tableau (sans la ligne des titres), copiez (Ctrl + C), '
            . 'puis collez (Ctrl + V) dans la colonne « ' . $e($colonnes[0] ?? '') . ' », sur la ligne du premier étudiant.</p>'
            . self::tableau($colonnes, $lignes)
            . '<p><strong>2. Repère, à ne pas copier.</strong> Les lignes sont dans l\'ordre alphabétique des étudiants. '
            . 'Si la feuille n\'est pas dans le même ordre, ou si des étudiants manquent dans ce lot, collez ligne par ligne.</p>'
            . self::tableau(['Ligne', 'Étudiant', 'Identifiant'], $numerotes)
            . '<p>Ce message contient des mots de passe : supprimez-le une fois la feuille mise à jour. Le portail ne les a pas gardés.</p>'
            . '<p style="color:#666">Message automatique du portail des sites étudiants.</p></body></html>';
    }

    /**
     * @param list<string> $titres
     * @param list<list<string>> $rangees
     */
    private static function tableau(array $titres, array $rangees): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cellule = 'style="border:1px solid #999;padding:4px 8px;font-family:Arial,sans-serif;font-size:13px"';
        $html = '<table style="border-collapse:collapse"><thead><tr>';
        foreach ($titres as $titre) {
            $html .= '<th ' . $cellule . '>' . $e($titre) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rangees as $rangee) {
            $html .= '<tr>';
            foreach ($rangee as $valeur) {
                $html .= '<td ' . $cellule . '>' . $e($valeur) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }
}
