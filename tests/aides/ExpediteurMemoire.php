<?php

declare(strict_types=1);

namespace Portail\Tests\Aides;

use Portail\Courriel\Expediteur;
use RuntimeException;

/** Expéditeur de test : garde les e-mails en mémoire, ou échoue sur demande. */
final class ExpediteurMemoire implements Expediteur
{
    /** @var list<array{destinataires: list<string>, sujet: string, texte: string}> */
    public array $envoyes = [];

    public function __construct(public bool $enPanne = false)
    {
    }

    public function envoyer(array $destinataires, string $sujet, string $texte): void
    {
        if ($this->enPanne) {
            throw new RuntimeException('serveur injoignable');
        }
        $this->envoyes[] = ['destinataires' => $destinataires, 'sujet' => $sujet, 'texte' => $texte];
    }
}
