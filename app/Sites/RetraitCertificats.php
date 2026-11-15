<?php

declare(strict_types=1);

namespace Portail\Sites;

/** Arrêt du renouvellement du certificat d'un sous-domaine retiré de CPanel (remplaçable dans les tests). */
interface RetraitCertificats
{
    public function retirer(string $sousDomaine): void;
}
