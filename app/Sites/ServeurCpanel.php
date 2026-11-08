<?php

declare(strict_types=1);

namespace Portail\Sites;

/**
 * Opérations CPanel dont les travaux ont besoin. Remplaçable dans les tests.
 * Chaque méthode lève une exception en cas de refus de CPanel.
 */
interface ServeurCpanel
{
    /** @return array<string, string> identifiant => dossier racine du sous-domaine (bts-sup-sallanches.com) */
    public function sousDomaines(): array;

    public function creerBase(string $base): void;

    public function creerUtilisateur(string $utilisateur, #[\SensitiveParameter] string $motDePasse): void;

    public function donnerDroits(string $utilisateur, string $base): void;

    public function supprimerBase(string $base): void;

    public function supprimerUtilisateur(string $utilisateur): void;
}
