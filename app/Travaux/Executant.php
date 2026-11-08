<?php

declare(strict_types=1);

namespace Portail\Travaux;

/**
 * Exécution d'une étape d'un type de travail (un étudiant, ou « serveur »).
 *
 * Retourne l'état final et un message lisible par l'enseignant. Une exception
 * signifie un échec : son message est affiché tel quel, il ne doit donc
 * contenir ni mot de passe ni chemin sensible.
 */
interface Executant
{
    /**
     * @param array<array-key, mixed> $parametres Paramètres du travail (lus depuis la base)
     * @return array{etat: 'reussi'|'ignore', message: string}
     */
    public function executer(string $cible, array $parametres): array;
}
