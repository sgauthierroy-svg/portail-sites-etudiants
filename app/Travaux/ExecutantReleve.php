<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Alertes\Alertes;
use Portail\Sites\Releve;

/** Relevé des sous-domaines et des sites, lancé depuis le portail (bouton « Actualiser le relevé »). Lecture seule. */
final class ExecutantReleve implements Executant
{
    /** @param (callable(): string)|null $lireCpanel Remplaçable dans les tests */
    public function __construct(private readonly PDO $pdo, private readonly mixed $lireCpanel = null, private readonly ?Alertes $alertes = null)
    {
    }

    public function executer(string $cible, array $parametres): array
    {
        $bilan = Releve::lancer($this->pdo, 'travailleur', is_callable($this->lireCpanel) ? $this->lireCpanel : null);

        $horsGel = count(Releve::horsGel($this->pdo));
        $this->alertes?->boutiquesHorsGel($horsGel);

        return ['etat' => 'reussi', 'message' => $bilan['sous_domaines'] . ' sous-domaines, ' . $bilan['sites'] . ' sites WordPress ou PrestaShop.'
            . ($horsGel > 0 ? ' ' . $horsGel . ' boutique(s) hors de la version de leur session : voir « Voir tout ».' : '')];
    }
}
