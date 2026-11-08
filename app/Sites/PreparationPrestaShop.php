<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;
use Portail\Travaux\FileTravaux;

/**
 * Côté web de la création des boutiques (V2, lot 3) : qui peut en recevoir une,
 * et inscription du travail. Rien n'est fait sur le serveur ici.
 */
final class PreparationPrestaShop
{
    public const NOM_BOUTIQUE = 'Sup';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id: int, annee: int, paquet_id: ?int, version: ?string}> Sessions actives */
    public function sessions(): array
    {
        $liste = [];
        foreach (Lecture::lignes($this->pdo->query(
            'SELECT s.id, s.annee, s.paquet_prestashop_id, p.version FROM sessions s LEFT JOIN paquets p ON p.id = s.paquet_prestashop_id
             WHERE s.active = 1 ORDER BY s.annee'
        )) as $ligne) {
            $liste[] = [
                'id' => (int) Lecture::entier($ligne['id']),
                'annee' => (int) Lecture::entier($ligne['annee']),
                'paquet_id' => Lecture::entier($ligne['paquet_prestashop_id']),
                'version' => Lecture::texte($ligne['version']),
            ];
        }

        return $liste;
    }

    /** @return list<array{id: int, version: string}> Paquets d'installation PrestaShop de la bibliothèque */
    public function paquets(): array
    {
        $liste = [];
        foreach (Lecture::lignes($this->pdo->query("SELECT id, version FROM paquets WHERE type = 'prestashop' ORDER BY version")) as $ligne) {
            $liste[] = ['id' => (int) Lecture::entier($ligne['id']), 'version' => (string) Lecture::texte($ligne['version'])];
        }

        return $liste;
    }

    /**
     * Étudiants de la session, et pour chacun la raison qui empêche la création (null : possible).
     *
     * @return list<array{identifiant: string, nom: string, email: string, empechement: ?string}>
     */
    public function etudiants(int $sessionId): array
    {
        $requete = $this->pdo->prepare(
            "SELECT e.identifiant, e.nom_complet, e.email,
                    EXISTS (SELECT 1 FROM sous_domaines d WHERE d.identifiant = e.identifiant) AS sous_domaine,
                    EXISTS (SELECT 1 FROM releve_sites r WHERE r.identifiant = e.identifiant AND r.dossier = '/Presta') AS releve,
                    EXISTS (SELECT 1 FROM sites s WHERE s.identifiant = e.identifiant AND s.dossier = '/Presta') AS cree,
                    EXISTS (SELECT 1 FROM travaux_etapes t JOIN travaux w ON w.id = t.travail_id
                            WHERE t.cible = e.identifiant AND w.type = 'creer_prestashop' AND t.etat IN ('en_attente', 'en_cours')) AS prevu
             FROM etudiants e WHERE e.session_id = :s ORDER BY e.nom_complet COLLATE NOCASE"
        );
        $requete->execute([':s' => $sessionId]);
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $empechement = null;
            if (Lecture::entier($ligne['sous_domaine']) !== 1) {
                $empechement = 'sous-domaine absent du dernier relevé';
            }
            if (Lecture::entier($ligne['cree']) === 1 || Lecture::entier($ligne['releve']) === 1) {
                $empechement = 'boutique déjà présente';
            }
            if (Lecture::entier($ligne['prevu']) === 1) {
                $empechement = 'création déjà prévue';
            }
            $liste[] = [
                'identifiant' => (string) Lecture::texte($ligne['identifiant']),
                'nom' => (string) Lecture::texte($ligne['nom_complet']),
                'email' => (string) Lecture::texte($ligne['email']),
                'empechement' => $empechement,
            ];
        }

        return $liste;
    }

    /**
     * Inscrit la création des boutiques des étudiants cochés.
     * Une session sans version PrestaShop reçoit celle du paquet choisi (elle la garde ensuite).
     *
     * @param list<string> $coches Identifiants
     */
    public function lancer(int $sessionId, ?int $paquetChoisi, array $coches, Compte $auteur, ?string $adresseIp): int
    {
        $session = null;
        foreach ($this->sessions() as $candidate) {
            if ($candidate['id'] === $sessionId) {
                $session = $candidate;
            }
        }
        if ($session === null) {
            throw new ErreurSaisie('Session inconnue ou archivée.');
        }
        $paquet = $session['paquet_id'];
        $versions = array_column($this->paquets(), 'version', 'id');
        if ($paquet === null) {
            if ($paquetChoisi === null || !isset($versions[$paquetChoisi])) {
                throw new ErreurSaisie('Choisissez la version PrestaShop de la session (paquet de la bibliothèque).');
            }
            $paquet = $paquetChoisi;
        }
        $possibles = [];
        foreach ($this->etudiants($sessionId) as $etudiant) {
            if ($etudiant['empechement'] === null) {
                $possibles[$etudiant['identifiant']] = $etudiant['nom'];
            }
        }
        $etapes = [];
        foreach (array_unique($coches) as $identifiant) {
            if (!isset($possibles[$identifiant])) {
                throw new ErreurSaisie('Un étudiant coché ne peut pas recevoir de boutique (déjà présente, prévue, ou hors de cette session). Rechargez la page.');
            }
            $etapes[] = ['cible' => $identifiant, 'libelle' => $possibles[$identifiant]];
        }
        if ($etapes === []) {
            throw new ErreurSaisie('Cochez au moins un étudiant.');
        }
        if ($session['paquet_id'] === null) {
            $this->pdo->prepare('UPDATE sessions SET paquet_prestashop_id = :p WHERE id = :s AND paquet_prestashop_id IS NULL')
                ->execute([':p' => $paquet, ':s' => $sessionId]);
            (new Journal($this->pdo))->ecrire('site', 'version PrestaShop de la session', 'succes', $auteur->id, $auteur->nomAffiche, $adresseIp,
                'session ' . $session['annee'], 'paquet n° ' . $paquet . ' (' . ($versions[$paquet] ?? '?') . ')');
        }

        return (new FileTravaux($this->pdo))->demander('creer_prestashop', ['paquet_id' => $paquet, 'nom_boutique' => self::NOM_BOUTIQUE],
            $etapes, $auteur, $adresseIp);
    }
}
