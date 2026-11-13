<?php

declare(strict_types=1);

namespace Portail\Systeme;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Opérations sur les dossiers des sites, toujours bornées à un dossier limite. */
final class Fichiers
{
    /** Efface un dossier, seulement s'il est à l'intérieur de $limite (jamais au-dessus), sans suivre de lien. */
    public static function effacerDossier(string $dossier, string $limite, bool $luiAussi = true): void
    {
        $reel = realpath($dossier);
        $limiteReelle = realpath($limite);
        if ($reel === false || $limiteReelle === false || !str_starts_with($reel . '/', $limiteReelle . '/') || $reel === $limiteReelle) {
            throw new RuntimeException('Effacement refusé hors du dossier prévu.');
        }
        $elements = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($reel, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($elements as $element) {
            if (!$element instanceof \SplFileInfo) {
                continue;
            }
            $chemin = $element->getPathname();
            if ($element->isDir() && !$element->isLink()) {
                rmdir($chemin);
            } else {
                unlink($chemin); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
        }
        if ($luiAussi) {
            rmdir($reel);
        }
    }

    /** Le dossier existe-t-il et contient-il quelque chose ? */
    public static function nonVide(string $dossier): bool
    {
        return is_dir($dossier) && (new FilesystemIterator($dossier))->valid();
    }
}
