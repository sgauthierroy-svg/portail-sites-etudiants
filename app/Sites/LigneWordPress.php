<?php

declare(strict_types=1);

namespace Portail\Sites;

/**
 * Commandes WP-CLI sur un WordPress du serveur (remplaçable dans les tests).
 * Jamais de mot de passe dans les arguments : WP-CLI lit la base dans wp-config.php.
 */
interface LigneWordPress
{
    /**
     * @param list<string> $arguments Commande WP-CLI puis ses options (sans --path)
     * @return string Sortie standard
     */
    public function lancer(string $chemin, array $arguments, int $delaiSecondes = 300): string;
}
