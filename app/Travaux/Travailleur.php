<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Alertes\Alertes;
use Portail\Base\Lecture;
use Portail\Journal\Journal;
use Throwable;

/**
 * Travailleur en arrière-plan (bin/travaux.php, lancé chaque minute par une tâche planifiée).
 *
 * Règles :
 * - une seule étape à la fois, dans l'ordre des demandes, avec une pause entre
 *   deux étapes (blocage d'adresse IP par O2switch constaté en février 2026) ;
 * - une étape restée « en cours » après un arrêt brutal n'est jamais relancée
 *   d'elle-même : elle passe en échec « interrompue », à vérifier par une personne ;
 * - un type sans exécutant (lot pas encore livré) met l'étape en échec, sans rien toucher ;
 * - une alerte par e-mail quand un travail se termine avec des échecs.
 *
 * L'exclusion entre deux travailleurs (verrou de fichier) est assurée par bin/travaux.php.
 */
final class Travailleur
{
    /** @var \Closure(int): void */
    private \Closure $sommeil;

    /**
     * @param array<string, Executant> $executants type de travail => exécutant
     * @param (\Closure(int): void)|null $sommeil Remplaçable dans les tests
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Alertes $alertes,
        private readonly array $executants,
        private readonly int $pauseSecondes = 3,
        ?\Closure $sommeil = null,
    ) {
        $this->sommeil = $sommeil ?? static function (int $secondes): void {
            sleep(max(0, $secondes));
        };
    }

    /**
     * Traite des étapes jusqu'à épuisement de la file ou du temps donné.
     *
     * @param (\Closure(): float)|null $horloge Remplaçable dans les tests
     * @return int Nombre d'étapes traitées
     */
    public function tourner(int $budgetSecondes, ?\Closure $horloge = null): int
    {
        $horloge ??= static fn (): float => microtime(true);
        $fin = $horloge() + $budgetSecondes;
        $this->reprendreInterrompues();
        $traitees = 0;
        while ($horloge() < $fin) {
            $travail = $this->prochainTravail();
            if ($travail === null) {
                break;
            }
            $etape = $this->prochaineEtape($travail['id']);
            if ($etape === null) {
                $this->terminer($travail['id'], $travail['type'], $travail['parametres']);
                continue;
            }
            if ($traitees > 0) {
                ($this->sommeil)($this->pauseSecondes);
            }
            $this->executer($travail, $etape);
            $traitees++;
        }
        $this->alertes->expedier();

        return $traitees;
    }

    private function reprendreInterrompues(): void
    {
        $this->pdo->prepare(
            "UPDATE travaux_etapes SET etat = 'echec', termine_le = :m,
                    message = 'Interrompue : le travailleur s''est arrêté pendant cette étape. Vérifiez ce site avant de relancer.'
             WHERE etat = 'en_cours'"
        )->execute([':m' => self::maintenant()]);
    }

    /** @return array{id: int, type: string, parametres: array<array-key, mixed>}|null */
    private function prochainTravail(): ?array
    {
        $resultat = $this->pdo->query(
            "SELECT id, type, parametres FROM travaux WHERE etat IN ('en_cours', 'en_attente')
             ORDER BY etat = 'en_cours' DESC, id LIMIT 1"
        );
        $ligne = $resultat === false ? null : Lecture::ligne($resultat);
        if ($ligne === null) {
            return null;
        }
        $id = (int) Lecture::entier($ligne['id']);
        $this->pdo->prepare("UPDATE travaux SET etat = 'en_cours', commence_le = coalesce(commence_le, :m) WHERE id = :id")
            ->execute([':m' => self::maintenant(), ':id' => $id]);
        $parametres = Lecture::tableau(json_decode((string) Lecture::texte($ligne['parametres']), true)) ?? [];

        return ['id' => $id, 'type' => (string) Lecture::texte($ligne['type']), 'parametres' => $parametres];
    }

    /** @return array{id: int, cible: string, libelle: string}|null */
    private function prochaineEtape(int $travailId): ?array
    {
        $requete = $this->pdo->prepare("SELECT id, cible, libelle FROM travaux_etapes WHERE travail_id = :t AND etat = 'en_attente' ORDER BY rang LIMIT 1");
        $requete->execute([':t' => $travailId]);
        $ligne = Lecture::ligne($requete);

        return $ligne === null ? null : [
            'id' => (int) Lecture::entier($ligne['id']),
            'cible' => (string) Lecture::texte($ligne['cible']),
            'libelle' => (string) Lecture::texte($ligne['libelle']),
        ];
    }

    /**
     * @param array{id: int, type: string, parametres: array<array-key, mixed>} $travail
     * @param array{id: int, cible: string, libelle: string} $etape
     */
    private function executer(array $travail, array $etape): void
    {
        $this->pdo->prepare("UPDATE travaux_etapes SET etat = 'en_cours', commence_le = :m WHERE id = :id")
            ->execute([':m' => self::maintenant(), ':id' => $etape['id']]);
        $executant = $this->executants[$travail['type']] ?? null;
        try {
            if ($executant === null) {
                throw new \RuntimeException('Ce type de travail n\'est pas encore disponible sur ce portail : rien n\'a été fait.');
            }
            $resultat = $executant->executer($etape['cible'], $travail['parametres']);
            $etat = $resultat['etat'];
            $message = $resultat['message'];
            $secret = $resultat['secret'] ?? null;
        } catch (Throwable $e) {
            $etat = 'echec';
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Échec sans explication.';
            $secret = null;
        }
        $this->pdo->prepare('UPDATE travaux_etapes SET etat = :e, message = :msg, termine_le = :m, secret = :s WHERE id = :id')
            ->execute([':e' => $etat, ':msg' => mb_substr($message, 0, 1000, 'UTF-8'), ':m' => self::maintenant(), ':s' => $secret, ':id' => $etape['id']]);
        (new Journal($this->pdo))->ecrire('site', FileTravaux::LIBELLES[$travail['type']] ?? $travail['type'],
            $etat === 'echec' ? 'echec' : 'succes', null, 'travailleur', null, $etape['cible'],
            'travail n° ' . $travail['id'] . ' : ' . mb_substr($message, 0, 300, 'UTF-8'));
    }

    /** @param array<array-key, mixed> $parametres */
    private function terminer(int $id, string $type, array $parametres): void
    {
        $executant = $this->executants[$type] ?? null;
        if ($executant instanceof ApresTravail) {
            try {
                $executant->apresTravail($id, $parametres);
            } catch (Throwable $e) {
                (new Journal($this->pdo))->ecrire('site', 'fin de travail incomplète', 'echec', null, 'travailleur', null, 'travail n° ' . $id,
                    mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
            }
        }
        $this->pdo->prepare("UPDATE travaux SET etat = 'termine', termine_le = :m WHERE id = :id AND etat = 'en_cours'")
            ->execute([':m' => self::maintenant(), ':id' => $id]);
        $requete = $this->pdo->prepare("SELECT count(*) AS total, sum(etat = 'echec') AS echecs FROM travaux_etapes WHERE travail_id = :id");
        $requete->execute([':id' => $id]);
        $bilan = Lecture::ligne($requete) ?? [];
        $echecs = (int) Lecture::entier($bilan['echecs'] ?? 0);
        if ($echecs > 0) {
            $this->alertes->travailEnEchec($id, FileTravaux::LIBELLES[$type] ?? $type, $echecs, (int) Lecture::entier($bilan['total'] ?? 0));
        }
    }

    private static function maintenant(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
