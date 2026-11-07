<?php

declare(strict_types=1);

namespace Portail\Tests\Aides;

/**
 * Crée un faux dossier ~/portail (data, logs) et une configuration valide,
 * effacés à la fin du test.
 */
final class DossierTemporaire
{
    public readonly string $racine;

    public function __construct()
    {
        $this->racine = sys_get_temp_dir() . '/portail_test_' . bin2hex(random_bytes(6));
        mkdir($this->racine . '/data', 0700, true);
        mkdir($this->racine . '/logs', 0700, true);
    }

    /** @param array<string, mixed> $remplacements */
    public function ecrireConfig(array $remplacements = [], int $droits = 0600): string
    {
        $config = array_replace([
            'url_portail' => 'https://portail.bts-sup-sallanches.com',
            'dossier_donnees' => $this->racine . '/data',
            'dossier_journaux' => $this->racine . '/logs',
            'adresse_alertes' => 'alertes@exemple.fr',
            'smtp' => [
                'hote' => 'smtp.exemple.fr',
                'port' => 465,
                'utilisateur' => 'portail@exemple.fr',
                'mot_de_passe' => 'mot-de-passe-de-test',
            ],
            'cle_chiffrement' => str_repeat('ab', 32),
        ], $remplacements);
        $chemin = $this->racine . '/data/config.php';
        file_put_contents($chemin, '<?php return ' . var_export($config, true) . ';');
        chmod($chemin, $droits);

        return $chemin;
    }

    public function __destruct()
    {
        $this->effacer($this->racine);
    }

    private function effacer(string $chemin): void
    {
        if (is_dir($chemin) && !is_link($chemin)) {
            foreach (scandir($chemin) ?: [] as $element) {
                if ($element !== '.' && $element !== '..') {
                    $this->effacer($chemin . '/' . $element);
                }
            }
            rmdir($chemin);
        } elseif (file_exists($chemin)) {
            unlink($chemin);
        }
    }
}
