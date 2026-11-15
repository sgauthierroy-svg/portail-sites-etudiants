<?php

declare(strict_types=1);

namespace Portail\Sites;

use Portail\Systeme\Commande;
use RuntimeException;

/**
 * Certificats Let's Encrypt par acme.sh (installé dans ~/.acme.sh depuis le 25/10/2026, AutoSSL étant
 * désactivé chez O2switch). Mêmes commandes que certificats.sh, validé le 25/10/2026 :
 * obtention par le dossier du site (code 2 : certificat déjà valide), puis installation dans CPanel.
 * Le renouvellement est ensuite fait par la tâche planifiée d'acme.sh.
 */
final class AcmeSh implements Certificats, RetraitCertificats
{
    public function __construct(private readonly string $dossierPersonnel)
    {
    }

    public function poser(string $sousDomaine, string $racine): void
    {
        if (preg_match('/^[a-z0-9]{2,63}\.' . preg_quote(Releve::DOMAINE, '/') . '$/D', $sousDomaine) !== 1) {
            throw new RuntimeException('Sous-domaine refusé pour le certificat.');
        }
        $acme = $this->dossierPersonnel . '/.acme.sh/acme.sh';
        if (!is_file($acme)) {
            throw new RuntimeException('acme.sh introuvable (' . $acme . ').');
        }
        $obtention = Commande::lancer([$acme, '--issue', '-d', $sousDomaine, '-w', $racine, '--keylength', '2048'], 300);
        if ($obtention['code'] !== 0 && $obtention['code'] !== 2) {
            throw new RuntimeException('Certificat non obtenu (acme.sh, code ' . $obtention['code'] . ')' . self::raison($obtention['sortie'] . "\n" . $obtention['erreurs']));
        }
        $installation = Commande::lancer([$acme, '--deploy', '-d', $sousDomaine, '--deploy-hook', 'cpanel_uapi'], 300);
        if ($installation['code'] !== 0) {
            throw new RuntimeException('Certificat obtenu mais pas installé dans CPanel (acme.sh, code ' . $installation['code'] . ')'
                . self::raison($installation['sortie'] . "\n" . $installation['erreurs']));
        }
    }

    /**
     * Sous-domaine retiré de CPanel : acme.sh cesse de renouveler son certificat (commande « --remove » de la documentation
     * d'acme.sh ; les fichiers du certificat restent dans ~/.acme.sh). Un certificat inconnu d'acme.sh n'est pas une erreur.
     */
    public function retirer(string $sousDomaine): void
    {
        if (preg_match('/^[a-z0-9]{2,63}\.' . preg_quote(Releve::DOMAINE, '/') . '$/D', $sousDomaine) !== 1) {
            throw new RuntimeException('Sous-domaine refusé pour le certificat.');
        }
        $acme = $this->dossierPersonnel . '/.acme.sh/acme.sh';
        if (is_file($acme)) {
            Commande::lancer([$acme, '--remove', '-d', $sousDomaine], 60);
        }
    }

    /**
     * Raison donnée par acme.sh (lignes d'erreur, sans couleurs ni date), pour le message du travail.
     * acme.sh n'affiche pas de secret : adresses, codes de vérification et messages de Let's Encrypt.
     */
    public static function raison(string $sortie): string
    {
        $lignes = [];
        foreach (explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', $sortie)) as $ligne) {
            $ligne = trim((string) preg_replace('/^\[[^\]]*\]\s*/', '', trim($ligne)));
            if ($ligne !== '' && preg_match('/error|invalid|fail|timeout|refused|not found|denied|NXDOMAIN|Verify/i', $ligne) === 1) {
                $lignes[] = $ligne;
            }
        }
        $lignes = array_slice(array_values(array_unique($lignes)), -2);

        return $lignes === [] ? '.' : ' : ' . mb_substr(implode(' / ', $lignes), 0, 220, 'UTF-8') . '.';
    }
}
