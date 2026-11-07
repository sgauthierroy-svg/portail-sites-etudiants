<?php

declare(strict_types=1);

namespace Portail\Web;

/**
 * Aiguillage des requêtes vers les pages du portail.
 * Lot 1 : une seule page, l'attente ; les autres arrivent avec les lots suivants.
 */
final class Application
{
    public function traiter(string $methode, string $chemin): Reponse
    {
        if ($chemin !== '/') {
            return new Reponse(404, self::page('Page introuvable', 'Cette page n\'existe pas.'));
        }
        if ($methode !== 'GET' && $methode !== 'HEAD') {
            return new Reponse(
                405,
                self::page('Méthode refusée', 'Cette action n\'est pas permise ici.'),
                ['Content-Type' => 'text/html; charset=utf-8', 'Allow' => 'GET, HEAD'],
            );
        }

        return new Reponse(200, self::page('Portail des sites étudiants', 'Portail en préparation.'));
    }

    private static function page(string $titre, string $message): string
    {
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . Html::e($titre) . ' – Sup\'Sallanches</title></head>'
            . '<body><h1>' . Html::e($titre) . '</h1><p>' . Html::e($message) . '</p></body></html>';
    }
}
