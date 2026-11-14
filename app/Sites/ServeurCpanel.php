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

    /**
     * Crée le sous-domaine identifiant.bts-sup-sallanches.com, dossier ~/identifiant.bts-sup-sallanches.com
     * (méthode de creation_wp.sh, validée le 29/10/2026). La liste des sous-domaines est relue ensuite.
     */
    public function creerSousDomaine(string $identifiant): void;

    public function creerBase(string $base): void;

    public function creerUtilisateur(string $utilisateur, #[\SensitiveParameter] string $motDePasse): void;

    public function donnerDroits(string $utilisateur, string $base): void;

    public function supprimerBase(string $base): void;

    public function supprimerUtilisateur(string $utilisateur): void;
}
