<?php

declare(strict_types=1);

namespace Portail\Sites;

/**
 * Commandes WP-CLI sur un WordPress du serveur (remplaçable dans les tests).
 * Jamais de mot de passe dans les arguments : WP-CLI lit la base dans wp-config.php ; un secret
 * dont une commande a besoin passe par une variable d'environnement (lisible par le seul compte).
 */
interface LigneWordPress
{
    /**
     * @param list<string> $arguments Commande WP-CLI puis ses options (sans --path)
     * @param array<string, string> $environnement Variables ajoutées pour cette commande seulement
     * @return string Sortie standard
     */
    public function lancer(string $chemin, array $arguments, int $delaiSecondes = 300, #[\SensitiveParameter] array $environnement = []): string;
}
