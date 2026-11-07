<?php

declare(strict_types=1);

namespace Portail\Noyau;

use PDO;
use Portail\Base\Connexion;
use Portail\Web\Application;
use Portail\Web\EnTetes;

/**
 * Démarrage d'une requête web, dans un ordre qui ne laisse rien passer :
 * 1. gestion des erreurs (aucun détail technique affiché) ;
 * 2. réglages de sécurité PHP (cloisonnement dans ~/portail) ;
 * 3. en-têtes de sécurité ;
 * 4. configuration (refusée si mal protégée) ;
 * 5. base de données (refusée si non installée) ;
 * 6. page demandée.
 */
final class Demarrage
{
    public static function web(string $racine): void
    {
        Erreurs::installer(true);
        // Tout fichier créé par le portail (base, sessions, journaux) est privé.
        umask(0077);
        ReglagesPhp::appliquer($racine);
        // Avant tout le reste, pour que même une page d'erreur les porte.
        EnTetes::envoyer();
        $config = Config::charger($racine . '/data/config.php');
        self::base($config);

        $methode = self::serveur('REQUEST_METHOD', 'GET');
        $chemin = parse_url(self::serveur('REQUEST_URI', '/'), PHP_URL_PATH);

        (new Application())
            ->traiter($methode, is_string($chemin) ? $chemin : '/')
            ->envoyer();
    }

    /** Valeur de $_SERVER, toujours vérifiée : elle vient du visiteur ou du serveur web. */
    private static function serveur(string $cle, string $defaut): string
    {
        $valeur = $_SERVER[$cle] ?? null;

        return is_string($valeur) ? $valeur : $defaut;
    }

    /** Ouvre la base et vérifie qu'elle a été installée par bin/installer.php. */
    public static function base(Config $config): PDO
    {
        $chemin = $config->dossierDonnees . '/portail.sqlite';
        if (!is_file($chemin)) {
            throw new ErreurConfiguration('Base non installée : lancer bin/installer.php en SSH.');
        }

        return Connexion::ouvrir($chemin);
    }
}
