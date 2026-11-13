<?php

declare(strict_types=1);

namespace Portail\Sites;

use Portail\Systeme\Commande;
use RuntimeException;

/**
 * WP-CLI du serveur (/usr/local/bin/wp, version 2.12.0 relevée le 13/11/2026), lancé avec le
 * PHP du travailleur. Les extensions et le thème du site ne sont jamais chargés
 * (--skip-plugins --skip-themes) : le portail n'exécute pas le code installé par un étudiant.
 */
final class WpCli implements LigneWordPress
{
    public function __construct(private readonly string $programme = '/usr/local/bin/wp')
    {
    }

    public function lancer(string $chemin, array $arguments, int $delaiSecondes = 300): string
    {
        if ($arguments === []) {
            throw new RuntimeException('Commande WP-CLI vide.');
        }
        $resultat = Commande::lancer(array_merge([PHP_BINARY, $this->programme, '--path=' . $chemin, '--skip-plugins', '--skip-themes', '--no-color'],
            $arguments), $delaiSecondes, $chemin);
        if ($resultat['code'] !== 0) {
            $lignes = array_slice(array_filter(explode("\n", trim($resultat['erreurs'] . "\n" . $resultat['sortie']))), -2);
            throw new RuntimeException('WP-CLI (' . $arguments[0] . ' ' . ($arguments[1] ?? '') . ') a échoué : '
                . mb_substr(implode(' ', $lignes), 0, 300, 'UTF-8'));
        }

        return $resultat['sortie'];
    }
}
