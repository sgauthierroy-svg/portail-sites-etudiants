<?php

declare(strict_types=1);

namespace Portail\Sites;

/**
 * Opérations CPanel de la suppression d'un étudiant (lot 5, paquet B, 15/11/2026). Remplaçable dans les tests.
 * Séparées de ServeurCpanel pour ne servir qu'à la suppression et à son effacement au bout de 8 jours.
 */
interface NettoyageCpanel
{
    /** @return list<string> Noms des bases MySQL du compte */
    public function bases(): array;

    /** @return list<string> Noms des utilisateurs MySQL du compte */
    public function utilisateurs(): array;

    /**
     * Retire le sous-domaine identifiant.bts-sup-sallanches.com de CPanel (ses fichiers ne sont pas touchés).
     * Lève une exception si le sous-domaine est toujours là ensuite.
     */
    public function supprimerSousDomaine(string $identifiant): void;
}
