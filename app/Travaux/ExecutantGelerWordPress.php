<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Sites\ConfigWordPress;
use Portail\Sites\EpreuveWordPress;
use Portail\Sites\GelWordPress;
use Portail\Sites\OutilsWordPress;
use Portail\Sites\ServeurCpanel;
use RuntimeException;

/**
 * Gel (ou dégel) de la version d'un WordPress /WP déjà en place (constat du 20/11/2026), une étape par étudiant.
 *
 * Gel : module de gel du portail posé, puis PORTAIL_GEL « correctifs » dans le bloc du portail de wp-config.php
 * (décision du 07/11/2026 : la version reste, ses correctifs de sécurité passent). Un site déjà gelé sans aucune
 * mise à jour par le portail le reste. Dégel : le bloc perd son gel ; le module reste, inactif.
 * Seul le bloc du portail change dans wp-config.php (ajouté s'il manque, avant le chargement de WordPress) :
 * mot de passe et clés ne sont ni lus à part ni recopiés.
 */
final class ExecutantGelerWordPress implements Executant
{
    private const DOSSIER = '/WP';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ServeurCpanel $cpanel,
        private readonly string $dossierPersonnel,
    ) {
    }

    public function executer(string $cible, array $parametres): array
    {
        $geler = ($parametres['geler'] ?? null) === true;
        $site = EpreuveWordPress::racine($this->cpanel, $this->dossierPersonnel, $cible) . self::DOSSIER;
        $fichier = $site . '/wp-config.php';
        $config = is_file($fichier) && !is_link($fichier) && !is_link($site) ? file_get_contents($fichier) : false;
        if ($config === false) {
            throw new RuntimeException('WordPress /WP introuvable : rien n\'a été fait.');
        }
        $mode = ConfigWordPress::modeGel($config);
        $lectureSeule = ConfigWordPress::estLectureSeule($config);
        $masque = umask(0022);
        try {
            if ($geler) {
                GelWordPress::poserModule($site);
                if ($mode !== 'total') {
                    $reglages = ['gele' => false, 'correctifs' => true, 'lecture_seule' => $lectureSeule];
                    EpreuveWordPress::ecrireReglages($fichier, $reglages);
                }
            } elseif ($mode !== null) {
                EpreuveWordPress::ecrireReglages($fichier, ['gele' => false, 'lecture_seule' => $lectureSeule]);
            }
        } finally {
            umask($masque);
        }
        $etat = GelWordPress::etat($site);
        $gele = in_array($etat, ['total', 'correctifs'], true) ? 1 : 0;
        $this->pdo->prepare('UPDATE sites SET gele = :g WHERE identifiant = :i AND dossier = :d')
            ->execute([':g' => $gele, ':i' => $cible, ':d' => self::DOSSIER]);
        $this->pdo->prepare('UPDATE releve_sites SET gel_wordpress = :g WHERE identifiant = :i AND dossier = :d')
            ->execute([':g' => $etat, ':i' => $cible, ':d' => self::DOSSIER]);

        $version = OutilsWordPress::versionWordPress($site) ?? '?';

        return ['etat' => 'reussi', 'message' => self::message($geler, $mode, $etat, $version)];
    }

    private static function message(bool $geler, ?string $avant, string $etat, string $version): string
    {
        if ($geler) {
            return $avant === 'total'
                ? 'WordPress ' . $version . ' déjà gelé par le portail, sans aucune mise à jour ; bouton retiré.'
                : 'WordPress ' . $version . ' gelé : correctifs de sécurité seulement, bouton de mise à jour retiré.';
        }
        if ($etat !== 'aucun') {
            // Gels des anciens scripts (GelWordPress::MODULES_SCRIPTS) : le portail ne les retire pas.
            return 'Gel du portail retiré, mais un gel posé par un ancien script reste actif (WordPress ' . $version
                . ') : à retirer en SSH, voir le guide.';
        }

        return 'WordPress ' . $version . ' dégelé : les mises à jour automatiques de WordPress reprennent.';
    }
}
