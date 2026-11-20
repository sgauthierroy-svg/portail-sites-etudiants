<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Base\Lecture;
use Portail\Sites\ConfigWordPress;
use Portail\Sites\GelWordPress;
use Portail\Sites\DeploiementSujet;
use Portail\Sites\EpreuveWordPress;
use Portail\Sites\ServeurCpanel;
use RuntimeException;

/**
 * Lever le gel d'un /WP2 clos, ou le refiger (décision du 14/11/2026 : les enseignants le peuvent).
 * Seul le bloc des réglages du portail change dans wp-config.php ; le module reste dans le site,
 * inactif tant que la lecture seule est levée.
 */
final class ExecutantGelEpreuve implements Executant
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ServeurCpanel $cpanel,
        private readonly string $dossierPersonnel,
    ) {
    }

    public function executer(string $cible, array $parametres): array
    {
        $figer = ($parametres['figer'] ?? null) === true;
        $requete = $this->pdo->prepare('SELECT cloture_le FROM sites WHERE identifiant = :i AND dossier = :d');
        $requete->execute([':i' => $cible, ':d' => DeploiementSujet::DOSSIER]);
        if (Lecture::texte($requete->fetchColumn()) === null) {
            throw new RuntimeException('L\'épreuve n\'est pas close pour ce /WP2 : rien n\'a été fait.');
        }
        $site = EpreuveWordPress::racine($this->cpanel, $this->dossierPersonnel, $cible) . DeploiementSujet::DOSSIER;
        $fichierConfig = $site . '/wp-config.php';
        $config = is_file($fichierConfig) && !is_link($fichierConfig) ? file_get_contents($fichierConfig) : false;
        if ($config === false) {
            throw new RuntimeException('wp-config.php du /WP2 introuvable : rien n\'a été fait.');
        }
        $masque = umask(0022);
        try {
            if ($figer) {
                EpreuveWordPress::poserModule($site);
            }
            GelWordPress::poserModule($site); // /WP2 déployés avant le 20/11/2026 : module de gel absent
            EpreuveWordPress::ecrireReglages($fichierConfig, ['gele' => ConfigWordPress::estGele($config) ?? true, 'lecture_seule' => $figer]);
        } finally {
            umask($masque);
        }
        $this->pdo->prepare('UPDATE sites SET lecture_seule = :l WHERE identifiant = :i AND dossier = :d')
            ->execute([':l' => $figer ? 1 : 0, ':i' => $cible, ':d' => DeploiementSujet::DOSSIER]);

        return [
            'etat' => 'reussi',
            'message' => $figer ? '/WP2 figé de nouveau (lecture seule).' : 'Gel levé : le /WP2 peut de nouveau être modifié.',
        ];
    }
}
