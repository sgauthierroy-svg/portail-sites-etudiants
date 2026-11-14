<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;

/**
 * Suivi des certificats https par l'administrateur (demande du 14/11/2026).
 *
 * - Vérification : le travailleur examine chaque sous-domaine du relevé comme un navigateur
 *   (SondeOpenssl), par lots de quelques-uns quand il n'a pas d'autre travail ; une fois par jour
 *   d'office, ou quand l'administrateur la demande. Les pages ne touchent jamais le serveur : elles
 *   inscrivent la demande.
 * - Relance : l'administrateur coche des sous-domaines ; chacun est confié à RelanceCertificats
 *   (un essai tout de suite, puis toutes les 20 minutes, 6 au plus).
 * - Lundi : bilan chiffré, sans nom d'étudiant, dans l'e-mail de la tâche hebdomadaire.
 *
 * acme.sh renouvelle chaque certificat de lui-même environ 30 jours avant sa fin (tous les 60 jours,
 * certificats.sh) : un certificat qui expire dans moins de SEUIL_JOURS jours signale un renouvellement en panne.
 */
final class SuiviCertificats
{
    public const LOT = 10;
    public const SEUIL_JOURS = 14;
    private const DEMANDE = 'demandee_le';
    private const DERNIERE = 'derniere_le';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Inscrit une vérification complète (page de l'administrateur). */
    public function demanderVerification(Compte $auteur, ?string $adresseIp, ?int $maintenant = null): void
    {
        $this->ecrireParametre(self::DEMANDE, self::date($maintenant ?? time()));
        (new Journal($this->pdo))->ecrire('site', 'vérification des certificats demandée', 'succes', $auteur->id, $auteur->nomAffiche, $adresseIp, null, null);
    }

    /**
     * Travailleur, quand il n'a pas d'autre travail : examine au plus LOT sous-domaines pas encore
     * vus depuis la demande. Demande d'office si la dernière vérification complète a plus d'un jour.
     *
     * @return int Nombre de sous-domaines examinés
     */
    public function verifierLot(SondeCertificat $sonde, ?int $maintenant = null): int
    {
        $maintenant ??= time();
        $demande = $this->date_(self::DEMANDE);
        if ($demande === null) {
            $derniere = $this->date_(self::DERNIERE);
            if ($derniere !== null && strtotime($derniere) > $maintenant - 86400) {
                return 0;
            }
            $demande = self::date($maintenant);
            $this->ecrireParametre(self::DEMANDE, $demande);
        }
        $requete = $this->pdo->prepare(
            'SELECT d.identifiant FROM sous_domaines d LEFT JOIN etat_certificats e ON e.identifiant = d.identifiant
             WHERE e.verifie_le IS NULL OR e.verifie_le < :d ORDER BY d.identifiant LIMIT 10' // = self::LOT (texte fixe exigé par les règles)
        );
        $requete->execute([':d' => $demande]);
        $aExaminer = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $aExaminer[] = (string) Lecture::texte($ligne['identifiant']);
        }
        if ($aExaminer === []) {
            $this->ecrireParametre(self::DERNIERE, $demande);
            $this->pdo->exec('UPDATE verification_certificats SET demandee_le = NULL WHERE id = 1');

            return 0;
        }
        $enregistrer = $this->pdo->prepare(
            'INSERT INTO etat_certificats (identifiant, verifie_le, reconnu, expire_le, detail) VALUES (:i, :v, :r, :e, :d)
             ON CONFLICT (identifiant) DO UPDATE SET verifie_le = :v2, reconnu = :r2, expire_le = :e2, detail = :d2'
        );
        foreach ($aExaminer as $identifiant) {
            $etat = $sonde->examiner($identifiant . '.' . Releve::DOMAINE);
            $valeurs = [self::date(max($maintenant, time())), $etat['reconnu'] ? 1 : 0,
                $etat['expire'] === null ? null : self::date($etat['expire']), mb_substr($etat['detail'], 0, 200, 'UTF-8')];
            $enregistrer->execute([':i' => $identifiant, ':v' => $valeurs[0], ':r' => $valeurs[1], ':e' => $valeurs[2], ':d' => $valeurs[3],
                ':v2' => $valeurs[0], ':r2' => $valeurs[1], ':e2' => $valeurs[2], ':d2' => $valeurs[3]]);
        }

        return count($aExaminer);
    }

    /**
     * Tous les sous-domaines du relevé, les problèmes d'abord.
     *
     * @return list<array{identifiant: string, protege: bool, verifie_le: ?string, reconnu: ?bool, expire_le: ?string, detail: ?string,
     *                    probleme: bool, relance: ?array{essais: int, prochain_essai: string, derniere_erreur: ?string, abandonne_le: ?string}}>
     */
    public function liste(?int $maintenant = null): array
    {
        $limite = self::date(($maintenant ?? time()) + self::SEUIL_JOURS * 86400);
        $proteges = Lecture::tableau(json_decode((string) $this->parametre('sites_proteges'), true)) ?? [];
        $liste = [];
        foreach (Lecture::lignes($this->pdo->query(
            'SELECT d.identifiant, e.verifie_le, e.reconnu, e.expire_le, e.detail,
                    r.essais, r.prochain_essai, r.derniere_erreur, r.abandonne_le, r.identifiant AS relance
             FROM sous_domaines d LEFT JOIN etat_certificats e ON e.identifiant = d.identifiant
                  LEFT JOIN certificats_a_poser r ON r.identifiant = d.identifiant
             ORDER BY d.identifiant'
        )) as $ligne) {
            $reconnu = Lecture::entier($ligne['reconnu']);
            $expire = Lecture::texte($ligne['expire_le']);
            $relance = Lecture::texte($ligne['relance']) === null ? null : [
                'essais' => (int) Lecture::entier($ligne['essais']),
                'prochain_essai' => (string) Lecture::texte($ligne['prochain_essai']),
                'derniere_erreur' => Lecture::texte($ligne['derniere_erreur']),
                'abandonne_le' => Lecture::texte($ligne['abandonne_le']),
            ];
            $identifiant = (string) Lecture::texte($ligne['identifiant']);
            $liste[] = [
                'identifiant' => $identifiant,
                'protege' => in_array($identifiant, $proteges, true),
                'verifie_le' => Lecture::texte($ligne['verifie_le']),
                'reconnu' => $reconnu === null ? null : $reconnu === 1,
                'expire_le' => $expire,
                'detail' => Lecture::texte($ligne['detail']),
                'probleme' => $reconnu === 0 || ($expire !== null && $expire < $limite) || ($relance !== null && $relance['abandonne_le'] !== null),
                'relance' => $relance,
            ];
        }
        usort($liste, static fn (array $a, array $b): int => [$b['probleme'], $a['identifiant']] <=> [$a['probleme'], $b['identifiant']]);

        return $liste;
    }

    /** @return array{non_reconnus: int, expirent: int, abandons: int, relances: int, jamais: int, derniere: ?string, demande: ?string} */
    public function bilan(?int $maintenant = null): array
    {
        $limite = self::date(($maintenant ?? time()) + self::SEUIL_JOURS * 86400);
        $bilan = ['non_reconnus' => 0, 'expirent' => 0, 'abandons' => 0, 'relances' => 0, 'jamais' => 0,
            'derniere' => $this->date_(self::DERNIERE), 'demande' => $this->date_(self::DEMANDE)];
        foreach ($this->liste($maintenant) as $ligne) {
            $bilan['jamais'] += $ligne['reconnu'] === null ? 1 : 0;
            $bilan['non_reconnus'] += $ligne['reconnu'] === false ? 1 : 0;
            $bilan['expirent'] += $ligne['reconnu'] === true && $ligne['expire_le'] !== null && $ligne['expire_le'] < $limite ? 1 : 0;
            $bilan['abandons'] += $ligne['relance'] !== null && $ligne['relance']['abandonne_le'] !== null ? 1 : 0;
            $bilan['relances'] += $ligne['relance'] !== null && $ligne['relance']['abandonne_le'] === null ? 1 : 0;
        }

        return $bilan;
    }

    /**
     * Relance demandée par l'administrateur : un essai dès que le travailleur est libre, puis toutes les 20 minutes.
     *
     * @param list<string> $coches Identifiants
     */
    public function relancer(array $coches, Compte $auteur, ?string $adresseIp, ?int $maintenant = null): int
    {
        if ($coches === []) {
            throw new ErreurSaisie('Cochez au moins un sous-domaine.');
        }
        $possibles = [];
        foreach ($this->liste($maintenant) as $ligne) {
            if (!$ligne['protege']) {
                $possibles[] = $ligne['identifiant'];
            }
        }
        foreach ($coches as $identifiant) {
            if (!in_array($identifiant, $possibles, true)) {
                throw new ErreurSaisie('Un sous-domaine coché est inconnu du relevé ou protégé (certificat à poser avec certificats.sh). Rechargez la page.');
            }
        }
        $relance = new RelanceCertificats($this->pdo);
        foreach (array_values(array_unique($coches)) as $identifiant) {
            $relance->demander($identifiant, 'relance demandée par ' . $auteur->nomAffiche, $maintenant);
        }
        (new Journal($this->pdo))->ecrire('site', 'relance de certificats demandée', 'succes', $auteur->id, $auteur->nomAffiche, $adresseIp,
            count(array_unique($coches)) . ' sous-domaine(s)', implode(', ', array_unique($coches)));

        return count(array_unique($coches));
    }

    private function parametre(string $cle): ?string
    {
        $requete = $this->pdo->prepare('SELECT valeur FROM parametres WHERE cle = :c');
        $requete->execute([':c' => $cle]);

        return Lecture::texte($requete->fetchColumn());
    }

    /** Date de la demande en cours ou de la dernière vérification complète (table à une ligne). */
    private function date_(string $colonne): ?string
    {
        $resultat = $this->pdo->query($colonne === self::DEMANDE
            ? 'SELECT demandee_le FROM verification_certificats WHERE id = 1'
            : 'SELECT derniere_le FROM verification_certificats WHERE id = 1');

        return $resultat === false ? null : Lecture::texte($resultat->fetchColumn());
    }

    private function ecrireParametre(string $colonne, string $valeur): void
    {
        $this->pdo->prepare($colonne === self::DEMANDE
            ? 'UPDATE verification_certificats SET demandee_le = :v WHERE id = 1'
            : 'UPDATE verification_certificats SET derniere_le = :v WHERE id = 1')->execute([':v' => $valeur]);
    }

    private static function date(int $horodatage): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $horodatage);
    }
}
