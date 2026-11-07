<?php

declare(strict_types=1);

namespace Portail\Noyau;

use ErrorException;
use Throwable;

/**
 * Gestion des erreurs : le détail va au journal des erreurs (~/portail/logs),
 * le visiteur ne voit qu'un message neutre et un numéro de référence.
 */
final class Erreurs
{
    public static function installer(bool $web): void
    {
        // Tout avertissement PHP devient une erreur : pas de fonctionnement dégradé en silence.
        set_error_handler(static function (int $niveau, string $message, string $fichier, int $ligne): bool {
            if ((error_reporting() & $niveau) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $niveau, $fichier, $ligne);
        });

        set_exception_handler(static function (Throwable $e) use ($web): void {
            $reference = bin2hex(random_bytes(6));
            error_log(sprintf(
                '[portail %s] %s: %s dans %s:%d',
                $reference,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            if ($web) {
                self::pageErreur($reference);
            } else {
                fwrite(STDERR, 'Erreur (référence ' . $reference . ') : ' . $e->getMessage() . PHP_EOL);
                exit(1);
            }
        });
    }

    private static function pageErreur(string $reference): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Erreur</title>'
            . '<p>Une erreur est survenue. Elle a été enregistrée.</p>'
            . '<p>Référence à communiquer à l\'administrateur : ' . \Portail\Web\Html::e($reference) . '</p></html>';
    }
}
