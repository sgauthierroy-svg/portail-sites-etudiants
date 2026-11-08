<?php

declare(strict_types=1);

namespace Portail\Securite;

use InvalidArgumentException;

/**
 * Générateur de QR code (norme ISO/IEC 18004), mode octets, niveau de correction M.
 *
 * Écrit pour le portail, sans bibliothèque extérieure (pas de Composer en
 * production) : il sert uniquement à afficher le lien d'activation de la
 * double authentification (otpauth://…) que l'application du téléphone lit.
 * Le dessin est produit sur le serveur, en SVG intégré à la page : aucune
 * image n'est demandée à un service extérieur, le secret ne quitte pas le portail.
 *
 * Algorithme repris de la description publiée par Project Nayuki
 * (« QR Code generator library », licence MIT) ; résultats comparés module par
 * module à la bibliothèque Python « qrcode » et relus par un décodeur (tests).
 */
final class QrCode
{
    /** Codes de correction par bloc, niveau M, versions 1 à 40 (indice = version). */
    private const CORRECTION_PAR_BLOC = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26,
        26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];

    /** Nombre de blocs, niveau M, versions 1 à 40. */
    private const NOMBRE_DE_BLOCS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18,
        20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

    /** Bits du niveau de correction M dans les informations de format. */
    private const NIVEAU_M = 0;

    /** @var array<int, array<int, bool>> */
    private array $modules = [];
    /** @var array<int, array<int, bool>> */
    private array $fonction = [];
    private int $taille = 0;

    private function __construct(private readonly int $version)
    {
        $this->taille = $version * 4 + 17;
        $ligne = array_fill(0, $this->taille, false);
        $this->modules = array_fill(0, $this->taille, $ligne);
        $this->fonction = array_fill(0, $this->taille, $ligne);
    }

    /**
     * Matrice du QR code : true = module noir.
     *
     * @param int|null $masque Masque imposé (0 à 7), pour les tests ; null = meilleur masque
     * @return list<list<bool>> Lignes de haut en bas, modules de gauche à droite
     */
    public static function matrice(string $donnees, ?int $masque = null): array
    {
        if ($masque !== null && ($masque < 0 || $masque > 7)) {
            throw new InvalidArgumentException('Masque invalide.');
        }
        $version = self::versionMinimale(strlen($donnees));
        $qr = new self($version);
        $qr->dessinerMotifs();
        $qr->placerDonnees(self::ajouterCorrection(self::codeDonnees($donnees, $version), $version));

        if ($masque === null) {
            $meilleur = 0;
            $penaliteMin = PHP_INT_MAX;
            for ($essai = 0; $essai < 8; $essai++) {
                $qr->appliquerMasque($essai);
                $qr->dessinerFormat($essai);
                $penalite = $qr->penalite();
                if ($penalite < $penaliteMin) {
                    $meilleur = $essai;
                    $penaliteMin = $penalite;
                }
                $qr->appliquerMasque($essai); // un masque appliqué deux fois s'annule
            }
            $masque = $meilleur;
        }
        $qr->appliquerMasque($masque);
        $qr->dessinerFormat($masque);

        $resultat = [];
        foreach ($qr->modules as $ligne) {
            $resultat[] = array_values($ligne);
        }

        return $resultat;
    }

    /**
     * Dessin SVG, avec la marge blanche de 4 modules exigée par la norme.
     * Les données ne figurent pas dans le SVG sous forme lisible : seulement des carrés.
     */
    public static function svg(string $donnees, string $titre): string
    {
        $matrice = self::matrice($donnees);
        $taille = count($matrice);
        $chemin = '';
        foreach ($matrice as $y => $ligne) {
            foreach ($ligne as $x => $noir) {
                if ($noir) {
                    $chemin .= 'M' . ($x + 4) . ',' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $cote = $taille + 8;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $cote . ' ' . $cote . '" width="240" height="240"'
            . ' role="img" aria-label="' . htmlspecialchars($titre, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#ffffff"/><path fill="#000000" d="' . $chemin . '"/></svg>';
    }

    // ===== Données =====

    private static function versionMinimale(int $octets): int
    {
        for ($version = 1; $version <= 40; $version++) {
            $bitsDisponibles = self::octetsDeDonnees($version) * 8;
            $bitsNecessaires = 4 + ($version <= 9 ? 8 : 16) + $octets * 8;
            if ($bitsNecessaires <= $bitsDisponibles) {
                return $version;
            }
        }
        throw new InvalidArgumentException('Données trop longues pour un QR code.');
    }

    /** @return list<int> Octets de données, complétés selon la norme */
    private static function codeDonnees(string $donnees, int $version): array
    {
        $bits = [];
        $ajouter = static function (int $valeur, int $longueur) use (&$bits): void {
            for ($i = $longueur - 1; $i >= 0; $i--) {
                $bits[] = ($valeur >> $i) & 1;
            }
        };
        $ajouter(0b0100, 4); // mode octets
        $ajouter(strlen($donnees), $version <= 9 ? 8 : 16);
        foreach (str_split($donnees) as $octet) {
            $ajouter(ord($octet), 8);
        }
        $capacite = self::octetsDeDonnees($version) * 8;
        $ajouter(0, min(4, $capacite - count($bits)));
        $ajouter(0, (8 - count($bits) % 8) % 8);
        for ($remplissage = 0xEC; count($bits) < $capacite; $remplissage ^= 0xEC ^ 0x11) {
            $ajouter($remplissage, 8);
        }
        $octets = [];
        foreach (array_chunk($bits, 8) as $groupe) {
            $valeur = 0;
            foreach ($groupe as $bit) {
                $valeur = ($valeur << 1) | $bit;
            }
            $octets[] = $valeur;
        }

        return $octets;
    }

    /**
     * Découpe en blocs, ajoute la correction de Reed-Solomon, puis entrelace.
     *
     * @param list<int> $donnees
     * @return list<int>
     */
    private static function ajouterCorrection(array $donnees, int $version): array
    {
        $nombreBlocs = self::NOMBRE_DE_BLOCS[$version];
        $correction = self::CORRECTION_PAR_BLOC[$version];
        $brut = intdiv(self::modulesDeDonnees($version), 8);
        $blocsCourts = $nombreBlocs - $brut % $nombreBlocs;
        $longueurCourte = intdiv($brut, $nombreBlocs);
        $diviseur = self::diviseurReedSolomon($correction);

        $blocs = [];
        $position = 0;
        for ($i = 0; $i < $nombreBlocs; $i++) {
            $longueur = $longueurCourte - $correction + ($i < $blocsCourts ? 0 : 1);
            $bloc = array_slice($donnees, $position, $longueur);
            $position += $longueur;
            $reste = self::resteReedSolomon($bloc, $diviseur);
            if ($i < $blocsCourts) {
                $bloc[] = -1; // place vide, sautée à l'entrelacement
            }
            $blocs[] = array_merge($bloc, $reste);
        }
        $resultat = [];
        $longueurBloc = count($blocs[0]);
        for ($i = 0; $i < $longueurBloc; $i++) {
            foreach ($blocs as $bloc) {
                if ($bloc[$i] !== -1) {
                    $resultat[] = $bloc[$i];
                }
            }
        }

        return $resultat;
    }

    private static function modulesDeDonnees(int $version): int
    {
        $resultat = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $alignements = intdiv($version, 7) + 2;
            $resultat -= (25 * $alignements - 10) * $alignements - 55;
            if ($version >= 7) {
                $resultat -= 36;
            }
        }

        return $resultat;
    }

    private static function octetsDeDonnees(int $version): int
    {
        return intdiv(self::modulesDeDonnees($version), 8) - self::CORRECTION_PAR_BLOC[$version] * self::NOMBRE_DE_BLOCS[$version];
    }

    /** @return array<int, int> */
    private static function diviseurReedSolomon(int $degre): array
    {
        $resultat = array_fill(0, $degre, 0);
        $resultat[$degre - 1] = 1;
        $racine = 1;
        for ($i = 0; $i < $degre; $i++) {
            for ($j = 0; $j < $degre; $j++) {
                $resultat[$j] = self::multiplier($resultat[$j], $racine);
                if ($j + 1 < $degre) {
                    $resultat[$j] ^= $resultat[$j + 1];
                }
            }
            $racine = self::multiplier($racine, 0x02);
        }

        return $resultat;
    }

    /**
     * @param list<int> $donnees
     * @param array<int, int> $diviseur
     * @return list<int>
     */
    private static function resteReedSolomon(array $donnees, array $diviseur): array
    {
        $resultat = array_fill(0, count($diviseur), 0);
        foreach ($donnees as $octet) {
            $facteur = $octet ^ array_shift($resultat);
            $resultat[] = 0;
            foreach ($diviseur as $i => $coefficient) {
                $resultat[$i] ^= self::multiplier($coefficient, $facteur);
            }
        }
        $reste = [];
        foreach ($resultat as $valeur) {
            $reste[] = $valeur;
        }

        return $reste;
    }

    /** Multiplication dans GF(2^8), polynôme 0x11D. */
    private static function multiplier(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    // ===== Dessin =====

    private function dessinerMotifs(): void
    {
        for ($i = 0; $i < $this->taille; $i++) {
            $this->fixer(6, $i, $i % 2 === 0);
            $this->fixer($i, 6, $i % 2 === 0);
        }
        $this->reperePosition(3, 3);
        $this->reperePosition($this->taille - 4, 3);
        $this->reperePosition(3, $this->taille - 4);

        $positions = $this->positionsAlignement();
        $nombre = count($positions);
        for ($i = 0; $i < $nombre; $i++) {
            for ($j = 0; $j < $nombre; $j++) {
                $coin = ($i === 0 && $j === 0) || ($i === 0 && $j === $nombre - 1) || ($i === $nombre - 1 && $j === 0);
                if (!$coin) {
                    $this->repereAlignement($positions[$i], $positions[$j]);
                }
            }
        }
        $this->dessinerFormat(0); // réserve les zones ; réécrit avec le vrai masque
        $this->dessinerVersion();
    }

    private function reperePosition(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->taille && $yy >= 0 && $yy < $this->taille) {
                    $this->fixer($xx, $yy, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    private function repereAlignement(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->fixer($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    private function positionsAlignement(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $nombre = intdiv($this->version, 7) + 2;
        $pas = $this->version === 32 ? 26 : intdiv($this->version * 4 + $nombre * 2 + 1, $nombre * 2 - 2) * 2;
        $positions = [];
        for ($i = 0, $position = $this->taille - 7; $i < $nombre - 1; $i++, $position -= $pas) {
            array_unshift($positions, $position);
        }
        array_unshift($positions, 6);

        return $positions;
    }

    private function dessinerFormat(int $masque): void
    {
        $donnees = (self::NIVEAU_M << 3) | $masque;
        $reste = $donnees;
        for ($i = 0; $i < 10; $i++) {
            $reste = ($reste << 1) ^ (($reste >> 9) * 0x537);
        }
        $bits = (($donnees << 10) | $reste) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->fixer(8, $i, self::bit($bits, $i));
        }
        $this->fixer(8, 7, self::bit($bits, 6));
        $this->fixer(8, 8, self::bit($bits, 7));
        $this->fixer(7, 8, self::bit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->fixer(14 - $i, 8, self::bit($bits, $i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->fixer($this->taille - 1 - $i, 8, self::bit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->fixer(8, $this->taille - 15 + $i, self::bit($bits, $i));
        }
        $this->fixer(8, $this->taille - 8, true); // module toujours noir
    }

    private function dessinerVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $reste = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $reste = ($reste << 1) ^ (($reste >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $reste;
        for ($i = 0; $i < 18; $i++) {
            $a = $this->taille - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->fixer($a, $b, self::bit($bits, $i));
            $this->fixer($b, $a, self::bit($bits, $i));
        }
    }

    /** @param list<int> $octets */
    private function placerDonnees(array $octets): void
    {
        $i = 0;
        $total = count($octets) * 8;
        $droite = $this->taille - 1;
        for (; $droite >= 1; $droite -= 2) {
            // La colonne 6 (motif de synchronisation) est sautée : on passe à la 5.
            $droite = $droite === 6 ? 5 : $droite;
            for ($vertical = 0; $vertical < $this->taille; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $droite - $j;
                    $montant = (($droite + 1) & 2) === 0;
                    $y = $montant ? $this->taille - 1 - $vertical : $vertical;
                    if (!$this->fonction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = self::bit($octets[$i >> 3], 7 - ($i & 7));
                        $i++;
                    }
                }
            }
        }
    }

    private function appliquerMasque(int $masque): void
    {
        for ($y = 0; $y < $this->taille; $y++) {
            for ($x = 0; $x < $this->taille; $x++) {
                $inverser = match ($masque) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($inverser && !$this->fonction[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** Pénalité de la norme (règles N1 à N4) : le masque le plus lisible a la plus faible. */
    private function penalite(): int
    {
        $resultat = 0;
        $n = $this->taille;
        for ($sens = 0; $sens < 2; $sens++) {
            for ($a = 0; $a < $n; $a++) {
                $couleur = false;
                $suite = 0;
                $historique = [0, 0, 0, 0, 0, 0, 0];
                for ($b = 0; $b < $n; $b++) {
                    $module = $sens === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];
                    if ($module === $couleur) {
                        $suite++;
                        if ($suite === 5) {
                            $resultat += 3;
                        } elseif ($suite > 5) {
                            $resultat++;
                        }
                    } else {
                        $this->ajouterHistorique($suite, $historique);
                        if (!$couleur) {
                            $resultat += $this->motifsTrouves($historique) * 40;
                        }
                        $couleur = $module;
                        $suite = 1;
                    }
                }
                $resultat += $this->fermerHistorique($couleur, $suite, $historique) * 40;
            }
        }
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $resultat += 3;
                }
            }
        }
        $noirs = 0;
        foreach ($this->modules as $ligne) {
            $noirs += count(array_filter($ligne));
        }
        $total = $n * $n;
        $k = intdiv(abs($noirs * 20 - $total * 10) + $total - 1, $total) - 1;

        return $resultat + $k * 10;
    }

    /** @param list<int> $historique */
    private function motifsTrouves(array $historique): int
    {
        $c = $historique[1];
        $coeur = $c > 0 && $historique[2] === $c && $historique[3] === $c * 3 && $historique[4] === $c && $historique[5] === $c;

        return ($coeur && $historique[0] >= $c * 4 && $historique[6] >= $c ? 1 : 0)
            + ($coeur && $historique[6] >= $c * 4 && $historique[0] >= $c ? 1 : 0);
    }

    /** @param list<int> $historique */
    private function fermerHistorique(bool $couleur, int $suite, array &$historique): int
    {
        if ($couleur) {
            $this->ajouterHistorique($suite, $historique);
            $suite = 0;
        }
        $suite += $this->taille;
        $this->ajouterHistorique($suite, $historique);

        return $this->motifsTrouves($historique);
    }

    /** @param list<int> $historique */
    private function ajouterHistorique(int $suite, array &$historique): void
    {
        if ($historique[0] === 0) {
            $suite += $this->taille;
        }
        array_pop($historique);
        array_unshift($historique, $suite);
    }

    private function fixer(int $x, int $y, bool $noir): void
    {
        $this->modules[$y][$x] = $noir;
        $this->fonction[$y][$x] = true;
    }

    private static function bit(int $valeur, int $rang): bool
    {
        return (($valeur >> $rang) & 1) !== 0;
    }
}
