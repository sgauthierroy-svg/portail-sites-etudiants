<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Systeme\Commande;
use RuntimeException;

/**
 * Installation en ligne de commande de PrestaShop (install/index_cli.php),
 * options validées par creation_presta.sh avec PrestaShop 9.1.5 le 25/10/2026.
 */
class InstallateurPrestaShop
{
    /**
     * @param array{domaine: string, base: string, utilisateur: string, mot_de_passe_bdd: string, nom: string,
     *              email: string, mot_de_passe: string} $options
     */
    public function installer(string $dossier, #[\SensitiveParameter] array $options): void
    {
        $installateur = is_file($dossier . '/install/index_cli.php') ? 'install' : 'install-dev';
        $resultat = Commande::lancer([
            PHP_BINARY, $dossier . '/' . $installateur . '/index_cli.php',
            '--domain=' . $options['domaine'], '--base_uri=/Presta/',
            '--db_server=localhost', '--db_name=' . $options['base'], '--db_user=' . $options['utilisateur'],
            '--db_password=' . $options['mot_de_passe_bdd'], '--prefix=ps_',
            '--name=' . $options['nom'], '--email=' . $options['email'], '--password=' . $options['mot_de_passe'],
            '--language=fr', '--country=fr', '--fixtures=1', '--newsletter=0', '--send_email=0', '--ssl=1',
        ], 900, $dossier);
        if ($resultat['code'] !== 0 || !is_file($dossier . '/app/config/parameters.php')) {
            // Dernières lignes seulement, sans les options (qui contiennent les mots de passe).
            $lignes = array_slice(array_filter(explode("\n", trim($resultat['sortie'] . "\n" . $resultat['erreurs']))), -3);
            throw new RuntimeException('Installation PrestaShop échouée : ' . mb_substr(implode(' ', $lignes), 0, 400, 'UTF-8'));
        }
    }

    /** https partout (comme passage_https.sh), sur la base de la boutique. */
    public function activerHttps(string $base, string $utilisateur, #[\SensitiveParameter] string $motDePasse): void
    {
        $pdo = new PDO('mysql:host=localhost;dbname=' . $base . ';charset=utf8mb4', $utilisateur, $motDePasse,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $modifiees = $pdo->exec("UPDATE ps_configuration SET value = '1' WHERE name IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE')");
        if ($modifiees === false) {
            throw new RuntimeException('Activation de https dans PrestaShop impossible.');
        }
    }
}
