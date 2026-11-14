<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Alertes\Alertes;
use Portail\Base\Lecture;
use Portail\Journal\Journal;
use RuntimeException;
use Throwable;

/**
 * Relance des certificats des sous-domaines créés par le portail (constat du 14/11/2026 : le certificat
 * d'un sous-domaine tout neuf n'est pas obtenu tout de suite ; la même commande réussit plus tard).
 *
 * Let's Encrypt n'accepte que 5 échecs de validation par sous-domaine et par heure, et en rend un toutes
 * les 12 minutes (letsencrypt.org/docs/rate-limits, « Authorization Failures per Hostname per Account »).
 * D'où un essai toutes les 20 minutes, 6 au plus (2 heures), puis une alerte à l'administrateur.
 * Le travailleur relance un certificat à la fois, seulement quand il n'a pas d'autre travail.
 */
final class RelanceCertificats
{
    public const INTERVALLE = 1200;
    public const ESSAIS = 6;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Inscrit un sous-domaine dont le certificat reste à poser (premier essai en échec). */
    public function inscrire(string $identifiant, string $raison, ?int $maintenant = null): void
    {
        $maintenant ??= time();
        $this->pdo->prepare(
            'INSERT INTO certificats_a_poser (identifiant, essais, prochain_essai, derniere_erreur) VALUES (:i, 1, :p, :e)
             ON CONFLICT (identifiant) DO UPDATE SET essais = 1, prochain_essai = :p2, derniere_erreur = :e2, abandonne_le = NULL'
        )->execute([':i' => $identifiant, ':p' => self::date($maintenant + self::INTERVALLE), ':e' => mb_substr($raison, 0, 300, 'UTF-8'),
            ':p2' => self::date($maintenant + self::INTERVALLE), ':e2' => mb_substr($raison, 0, 300, 'UTF-8')]);
    }

    /** @return ?string Ce qui a été fait (null : rien à faire) */
    public function relancerUn(Certificats $certificats, ServeurCpanel $cpanel, string $dossierPersonnel, Alertes $alertes, ?int $maintenant = null): ?string
    {
        $maintenant ??= time();
        $requete = $this->pdo->prepare('SELECT identifiant, essais FROM certificats_a_poser WHERE abandonne_le IS NULL AND prochain_essai <= :m ORDER BY prochain_essai LIMIT 1');
        $requete->execute([':m' => self::date($maintenant)]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            return null;
        }
        $identifiant = (string) Lecture::texte($ligne['identifiant']);
        $essais = (int) Lecture::entier($ligne['essais']) + 1;
        $sousDomaine = $identifiant . '.' . Releve::DOMAINE;
        $journal = new Journal($this->pdo);
        try {
            $certificats->poser($sousDomaine, EpreuveWordPress::racine($cpanel, $dossierPersonnel, $identifiant));
        } catch (Throwable $e) {
            $raison = mb_substr($e->getMessage(), 0, 300, 'UTF-8');
            $abandon = $essais >= self::ESSAIS;
            $this->pdo->prepare('UPDATE certificats_a_poser SET essais = :n, prochain_essai = :p, derniere_erreur = :e, abandonne_le = :a WHERE identifiant = :i')
                ->execute([':n' => $essais, ':p' => self::date($maintenant + self::INTERVALLE), ':e' => $raison,
                    ':a' => $abandon ? self::date($maintenant) : null, ':i' => $identifiant]);
            $journal->ecrire('site', 'certificat non posé (relance)', 'echec', null, 'travailleur', null, $sousDomaine, 'essai ' . $essais . ' : ' . $raison);
            if ($abandon) {
                $alertes->certificatNonPose($sousDomaine, $essais, $raison);
            }

            return 'échec : ' . $sousDomaine;
        }
        $this->pdo->prepare('DELETE FROM certificats_a_poser WHERE identifiant = :i')->execute([':i' => $identifiant]);
        $journal->ecrire('site', 'certificat posé (relance)', 'succes', null, 'travailleur', null, $sousDomaine, 'essai ' . $essais);

        return 'posé : ' . $sousDomaine;
    }

    private static function date(int $horodatage): string
    {
        if ($horodatage <= 0) {
            throw new RuntimeException('Date invalide.');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $horodatage);
    }
}
