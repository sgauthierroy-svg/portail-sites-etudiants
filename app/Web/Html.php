<?php

declare(strict_types=1);

namespace Portail\Web;

/**
 * Échappement de tout texte inséré dans une page.
 * Règle du portail : aucune donnée n'est affichée sans passer par Html::e().
 */
final class Html
{
    /** @psalm-taint-escape html */
    public static function e(string $texte): string
    {
        return htmlspecialchars($texte, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
