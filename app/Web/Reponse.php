<?php

declare(strict_types=1);

namespace Portail\Web;

/**
 * Réponse HTTP préparée par l'application, envoyée en une seule fois.
 */
final class Reponse
{
    /** @param array<string, string> $enTetes */
    public function __construct(
        public readonly int $statut,
        public readonly string $corps,
        public readonly array $enTetes = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    public function envoyer(): void
    {
        http_response_code($this->statut);
        foreach ($this->enTetes as $nom => $valeur) {
            header($nom . ': ' . $valeur);
        }
        echo $this->corps;
    }
}
