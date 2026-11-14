<?php

declare(strict_types=1);

namespace Portail\Sites;

/** Examen du certificat https d'un site, comme un navigateur (remplaçable dans les tests). */
interface SondeCertificat
{
    /** @return array{reconnu: bool, expire: ?int, detail: string} expire : date de fin (horodatage), si lisible */
    public function examiner(string $hote): array;
}
