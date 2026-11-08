<?php

declare(strict_types=1);

namespace Portail\Courriel;

/**
 * Envoi d'un e-mail en texte brut. Lève une RuntimeException en cas d'échec.
 */
interface Expediteur
{
    /** @param list<string> $destinataires */
    public function envoyer(array $destinataires, string $sujet, string $texte): void;
}
