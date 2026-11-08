<?php

declare(strict_types=1);

namespace Portail\Systeme;

use RuntimeException;

/**
 * Lancement d'un programme du serveur (uapi, wp, php…), réservé au travailleur
 * et aux commandes en SSH : la partie web du portail ne lance jamais rien.
 *
 * Le programme et ses arguments sont passés en liste, sans shell : aucun
 * caractère d'un argument (espace, ;, $, `…) ne peut être interprété comme une
 * commande. Durée bornée : au-delà, le programme est arrêté.
 */
final class Commande
{
    /** Sortie gardée au plus (octets), pour ne jamais remplir la mémoire. */
    private const SORTIE_MAX = 4_000_000;

    /**
     * @param non-empty-list<string> $argv Programme puis arguments
     * @param array<string, string> $environnement Variables ajoutées (ex. mot de passe, jamais en argument)
     * @return array{code: int, sortie: string, erreurs: string}
     */
    public static function lancer(array $argv, int $delaiSecondes = 120, ?string $dossier = null, array $environnement = []): array
    {
        // Deuxième barrière, en plus des règles d'analyse du code : jamais depuis une page web.
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Commande refusée hors ligne de commande.');
        }
        foreach ($argv as $argument) {
            if (str_contains($argument, "\0")) {
                throw new RuntimeException('Argument de commande invalide.');
            }
        }
        $env = getenv();
        foreach ($environnement as $nom => $valeur) {
            $env[$nom] = $valeur;
        }
        // Liste d'arguments, sans shell (voir ci-dessus).
        // nosemgrep: outils..cache.semgrep-rules.php.lang.security.exec-use
        $processus = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, $dossier, $env);
        if (!is_resource($processus)) {
            throw new RuntimeException('Impossible de lancer ' . basename($argv[0]) . '.');
        }
        fclose($tubes[0]);
        stream_set_blocking($tubes[1], false);
        stream_set_blocking($tubes[2], false);
        $sortie = '';
        $erreurs = '';
        $limite = microtime(true) + $delaiSecondes;
        $depasse = false;
        $codeSortie = null;
        while (true) {
            $lecture = [$tubes[1], $tubes[2]];
            $rien = null;
            $autre = null;
            $pret = @stream_select($lecture, $rien, $autre, 1);
            if ($pret !== false) {
                foreach ($lecture as $tube) {
                    $morceau = (string) fread($tube, 65536);
                    if ($tube === $tubes[1]) {
                        $sortie .= strlen($sortie) < self::SORTIE_MAX ? $morceau : '';
                    } else {
                        $erreurs .= strlen($erreurs) < self::SORTIE_MAX ? $morceau : '';
                    }
                }
            }
            $etat = proc_get_status($processus);
            if (!$etat['running']) {
                // Le code de sortie n'est donné qu'une fois, au premier constat d'arrêt.
                $codeSortie ??= $etat['exitcode'];
                if (feof($tubes[1]) && feof($tubes[2])) {
                    break;
                }
            }
            if (microtime(true) > $limite) {
                $depasse = true;
                proc_terminate($processus, 9);
                break;
            }
        }
        fclose($tubes[1]);
        fclose($tubes[2]);
        $code = proc_close($processus);
        if ($depasse) {
            throw new RuntimeException(basename($argv[0]) . ' n\'a pas répondu en ' . $delaiSecondes . ' s : arrêté.');
        }
        if ($codeSortie !== null && $codeSortie >= 0) {
            $code = $codeSortie;
        }

        return ['code' => $code, 'sortie' => $sortie, 'erreurs' => $erreurs];
    }
}
