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

    /**
     * Copie le contenu de $source dans $destination (qui doit exister). Les liens symboliques ne
     * sont jamais suivis ni recopiés : un lien posé dans un site ne fait rien sortir du dossier.
     * Droits : ceux de la source, sans les bits spéciaux (au plus 755 / 644 selon la source).
     *
     * @param list<string> $exclus Chemins relatifs à ne pas copier (fichier ou dossier entier)
     * @return int Nombre de fichiers copiés
     */
    public static function copierDossier(string $source, string $destination, array $exclus = []): int
    {
        $racine = realpath($source);
        if ($racine === false || !is_dir($racine) || !is_dir($destination) || is_link($destination)) {
            throw new RuntimeException('Copie impossible : dossier source ou destination introuvable.');
        }
        $elements = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        $copies = 0;
        foreach ($elements as $element) {
            if (!$element instanceof \SplFileInfo || $element->isLink()) {
                continue;
            }
            $relatif = substr($element->getPathname(), strlen($racine) + 1);
            foreach ($exclus as $exclu) {
                if ($relatif === $exclu || str_starts_with($relatif, $exclu . '/')) {
                    continue 2;
                }
            }
            $cible = $destination . '/' . $relatif;
            $droits = $element->getPerms() & 0755;
            if ($element->isDir()) {
                if (!is_dir($cible) && !mkdir($cible, 0755)) {
                    throw new RuntimeException('Copie impossible : création d\'un dossier refusée.');
                }
                chmod($cible, $droits | 0700);
            } elseif ($element->isFile()) {
                if (!copy($element->getPathname(), $cible)) {
                    throw new RuntimeException('Copie impossible : un fichier n\'a pas pu être copié.');
                }
                chmod($cible, $droits | 0600);
                $copies++;
            }
        }

        return $copies;
    }

    /** Le dossier existe-t-il et contient-il quelque chose ? */
    public static function nonVide(string $dossier): bool
    {
        return is_dir($dossier) && (new FilesystemIterator($dossier))->valid();
    }
}
