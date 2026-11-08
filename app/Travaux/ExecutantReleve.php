<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Sites\Releve;

/** Relevé des sous-domaines et des sites, lancé depuis le portail (bouton « Actualiser le relevé »). Lecture seule. */
final class ExecutantReleve implements Executant
{
    /** @param (callable(): string)|null $lireCpanel Remplaçable dans les tests */
    public function __construct(private readonly PDO $pdo, private readonly mixed $lireCpanel = null)
    {
    }

    public function executer(string $cible, array $parametres): array
    {
        $bilan = Releve::lancer($this->pdo, 'travailleur', is_callable($this->lireCpanel) ? $this->lireCpanel : null);

        return ['etat' => 'reussi', 'message' => $bilan['sous_domaines'] . ' sous-domaines, ' . $bilan['sites'] . ' sites WordPress ou PrestaShop.'];
    }
}
