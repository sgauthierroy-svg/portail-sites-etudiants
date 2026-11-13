<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Base\Lecture;
use Portail\Sites\ConfigWordPress;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Sites\SiteModele;
use RuntimeException;

/**
 * Gel (ou dégel) de la version du site modèle d'examen (décision du 13/11/2026 : tant que
 * l'année d'examen n'est pas figée, le modèle se met à jour). Seul le bloc des réglages du
 * portail change dans wp-config.php : mot de passe et clés ne sont ni lus ni recopiés.
 */
final class ExecutantGelerSujetModele implements Executant
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ServeurCpanel $cpanel,
        private readonly string $dossierPersonnel,
    ) {
    }

    public function executer(string $cible, array $parametres): array
    {
        if ($cible !== SiteModele::IDENTIFIANT) {
            throw new RuntimeException('Ce travail ne concerne que le site modèle d\'examen : rien n\'a été fait.');
        }
        $gele = ($parametres['gele'] ?? null) === true;
        $attendu = $this->dossierPersonnel . '/' . SiteModele::IDENTIFIANT . '.' . Releve::DOMAINE;
        $racine = $this->cpanel->sousDomaines()[SiteModele::IDENTIFIANT] ?? null;
        if ($racine === null || rtrim($racine, '/') !== $attendu) {
            throw new RuntimeException('Sous-domaine du site modèle introuvable ou inattendu : rien n\'a été fait.');
        }
        $fichier = $attendu . SiteModele::DOSSIER . '/wp-config.php';
        if (!is_file($fichier) || is_link($fichier)) {
            throw new RuntimeException('wp-config.php du site modèle introuvable : rien n\'a été fait.');
        }
        $contenu = file_get_contents($fichier);
        if ($contenu === false) {
            throw new RuntimeException('wp-config.php du site modèle illisible.');
        }
        $nouveau = ConfigWordPress::remplacerBloc($contenu, ['gele' => $gele]);
        // Écriture dans un fichier voisin puis remplacement : jamais de wp-config.php à moitié écrit.
        $provisoire = $fichier . '.portail-' . bin2hex(random_bytes(4));
        $droits = fileperms($fichier);
        if (file_put_contents($provisoire, $nouveau, LOCK_EX) === false || !chmod($provisoire, $droits === false ? 0600 : $droits & 0777)
            || !rename($provisoire, $fichier)) {
            if (is_file($provisoire)) {
                unlink($provisoire); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
            throw new RuntimeException('Écriture de wp-config.php impossible : rien n\'a été changé.');
        }
        $this->pdo->prepare('UPDATE sites SET gele = :g WHERE identifiant = :i AND dossier = :d')
            ->execute([':g' => $gele ? 1 : 0, ':i' => SiteModele::IDENTIFIANT, ':d' => SiteModele::DOSSIER]);
        $version = $this->pdo->prepare('SELECT version FROM sites WHERE identifiant = :i AND dossier = :d');
        $version->execute([':i' => SiteModele::IDENTIFIANT, ':d' => SiteModele::DOSSIER]);
        $numero = Lecture::texte($version->fetchColumn()) ?? '?';

        return [
            'etat' => 'reussi',
            'message' => $gele
                ? 'Version du site modèle gelée (WordPress ' . $numero . ') : plus aucune mise à jour automatique.'
                : 'Version du site modèle dégelée : les mises à jour automatiques de WordPress reprennent.',
        ];
    }
}
