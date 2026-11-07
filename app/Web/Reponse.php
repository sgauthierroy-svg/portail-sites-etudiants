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

    /** Redirection vers une page du portail (chemin interne uniquement). */
    public static function redirection(string $chemin): self
    {
        if (preg_match('#^/[a-z0-9/-]*$#', $chemin) !== 1) {
            $chemin = '/';
        }

        return new self(303, '', ['Location' => $chemin]);
    }

    /**
     * @param array<string, string> $autres
     * @return array<string, string>
     */
    public static function enTetesHtml(array $autres = []): array
    {
        return ['Content-Type' => 'text/html; charset=utf-8'] + $autres;
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
