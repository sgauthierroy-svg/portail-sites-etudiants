<?php

declare(strict_types=1);

namespace Portail\Sites;

use Portail\Systeme\Commande;
use RuntimeException;
use Throwable;

/**
 * Certificat d'un site vu comme par un navigateur : même méthode que certificats.sh et passage_https.sh
 * (validée le 25/10/2026) — openssl s_client avec vérification du nom, deux essais de 15 secondes
 * (un serveur lent ne doit pas faire croire à un certificat non reconnu). Lecture seule.
 */
final class SondeOpenssl implements SondeCertificat
{
    public function examiner(string $hote): array
    {
        if (preg_match('/^[a-z0-9-]{1,63}\.' . preg_quote(Releve::DOMAINE, '/') . '$/D', $hote) !== 1) {
            throw new RuntimeException('Nom de site refusé.');
        }
        $resultat = ['reconnu' => false, 'expire' => null, 'detail' => 'pas de réponse'];
        for ($essai = 1; $essai <= 2; $essai++) {
            try {
                $sortie = Commande::lancer(['openssl', 's_client', '-connect', $hote . ':443', '-servername', $hote, '-verify_hostname', $hote], 15);
                $resultat = self::lire($sortie['sortie']);
            } catch (Throwable $e) {
                $resultat = ['reconnu' => false, 'expire' => null, 'detail' => mb_substr($e->getMessage(), 0, 150, 'UTF-8')];
            }
            if ($resultat['reconnu']) {
                break;
            }
            if ($essai === 1) {
                sleep(2);
            }
        }

        return $resultat;
    }

    /** @return array{reconnu: bool, expire: ?int, detail: string} */
    public static function lire(string $sortie): array
    {
        $expire = null;
        if (preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $sortie, $pem) === 1) {
            $infos = openssl_x509_parse($pem[0]);
            $expire = is_array($infos) ? \Portail\Base\Lecture::entier($infos['validTo_time_t'] ?? null) : null;
        }
        if (preg_match('/Verify return code: ([0-9]+) \(([^)]*)\)/', $sortie, $code) !== 1) {
            return ['reconnu' => false, 'expire' => $expire, 'detail' => $expire === null ? 'pas de certificat reçu' : 'vérification illisible'];
        }

        return $code[1] === '0'
            ? ['reconnu' => true, 'expire' => $expire, 'detail' => 'reconnu par les navigateurs']
            : ['reconnu' => false, 'expire' => $expire, 'detail' => mb_substr('non reconnu : ' . $code[2] . ' (code ' . $code[1] . ')', 0, 200, 'UTF-8')];
    }
}
