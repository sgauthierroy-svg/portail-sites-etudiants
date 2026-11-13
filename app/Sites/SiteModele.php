<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Noyau\ErreurSaisie;
use Portail\Travaux\FileTravaux;

/**
 * Site modèle d'examen (V2, lot 4, décisions du 13/11/2026) : sous-domaine « sujet », WordPress
 * dans /WP. Les enseignants y chargent le sujet (paquet Duplicator de la bibliothèque), puis il
 * est copié dans les /WP2 des étudiants. Côté web : état et inscription des travaux seulement.
 */
final class SiteModele
{
    public const IDENTIFIANT = 'sujet';
    public const DOSSIER = '/WP';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function adresse(): string
    {
        return 'https://' . self::IDENTIFIANT . '.' . Releve::DOMAINE . self::DOSSIER . '/';
    }

    /**
     * @return array{connu: bool, installe: bool, version: ?string, gele: bool, installe_le: ?string, travail: ?int}
     *         connu : sous-domaine présent dans le dernier relevé ; travail : travail en attente ou en cours sur le modèle
     */
    public function etat(): array
    {
        $connu = $this->pdo->prepare('SELECT 1 FROM sous_domaines WHERE identifiant = :i');
        $connu->execute([':i' => self::IDENTIFIANT]);
        // Version : celle du dernier relevé si elle existe (WordPress a pu se mettre à jour depuis l'installation).
        $site = $this->pdo->prepare('SELECT coalesce(r.version, s.version) AS version, s.gele, s.cree_le FROM sites s
            LEFT JOIN releve_sites r ON r.identifiant = s.identifiant AND r.dossier = s.dossier WHERE s.identifiant = :i AND s.dossier = :d');
        $site->execute([':i' => self::IDENTIFIANT, ':d' => self::DOSSIER]);
        $ligne = Lecture::ligne($site);

        return [
            'connu' => $connu->fetchColumn() !== false,
            'installe' => $ligne !== null,
            'version' => $ligne === null ? null : Lecture::texte($ligne['version']),
            'gele' => $ligne !== null && Lecture::entier($ligne['gele']) === 1,
            'installe_le' => $ligne === null ? null : Lecture::texte($ligne['cree_le']),
            'travail' => $this->travailEnCours(),
        ];
    }

    /** @return list<array{id: int, nom: string, version_wordpress: ?string, depose_le: string, depose_par_nom: string}> Sujets WordPress de la bibliothèque */
    public function sujets(): array
    {
        $liste = [];
        foreach (Lecture::lignes($this->pdo->query("SELECT id, nom, version_wordpress, depose_le, depose_par_nom FROM paquets WHERE type = 'sujet_wordpress' ORDER BY depose_le DESC")) as $ligne) {
            $liste[] = [
                'id' => (int) Lecture::entier($ligne['id']), 'nom' => (string) Lecture::texte($ligne['nom']),
                'version_wordpress' => Lecture::texte($ligne['version_wordpress']), 'depose_le' => (string) Lecture::texte($ligne['depose_le']),
                'depose_par_nom' => (string) Lecture::texte($ligne['depose_par_nom']),
            ];
        }

        return $liste;
    }

    /** Inscrit l'installation d'un sujet sur le site modèle (le modèle actuel est mis à l'écart 8 jours). */
    public function installer(?int $paquetId, bool $confirme, Compte $auteur, ?string $adresseIp): int
    {
        $etat = $this->verifierLibre();
        if (!in_array($paquetId, array_column($this->sujets(), 'id'), true)) {
            throw new ErreurSaisie('Choisissez un sujet WordPress de la bibliothèque.');
        }
        if ($etat['installe'] && !$confirme) {
            throw new ErreurSaisie('Cochez la case de confirmation : le site modèle actuel sera remplacé (gardé 8 jours à l\'écart).');
        }

        return (new FileTravaux($this->pdo))->demander('installer_sujet_modele', ['paquet_id' => $paquetId, 'demande_par_nom' => $auteur->nomAffiche],
            [['cible' => self::IDENTIFIANT, 'libelle' => 'Site modèle d\'examen']], $auteur, $adresseIp);
    }

    /** Inscrit le gel (ou le dégel) de la version du site modèle. */
    public function geler(bool $gele, Compte $auteur, ?string $adresseIp): int
    {
        $etat = $this->verifierLibre();
        if (!$etat['installe']) {
            throw new ErreurSaisie('Aucun sujet n\'est installé sur le site modèle.');
        }
        if ($etat['gele'] === $gele) {
            throw new ErreurSaisie($gele ? 'La version du site modèle est déjà gelée.' : 'La version du site modèle n\'est pas gelée.');
        }

        return (new FileTravaux($this->pdo))->demander('geler_sujet_modele', ['gele' => $gele],
            [['cible' => self::IDENTIFIANT, 'libelle' => 'Site modèle d\'examen']], $auteur, $adresseIp);
    }

    /** @return array{connu: bool, installe: bool, version: ?string, gele: bool, installe_le: ?string, travail: ?int} */
    private function verifierLibre(): array
    {
        $etat = $this->etat();
        if (!$etat['connu']) {
            throw new ErreurSaisie('Le sous-domaine « ' . self::IDENTIFIANT . ' » n\'est pas dans le dernier relevé : créez-le dans CPanel, puis actualisez le relevé.');
        }
        if ($etat['travail'] !== null) {
            throw new ErreurSaisie('Un travail sur le site modèle est déjà prévu ou en cours (travail n° ' . $etat['travail'] . ') : attendez qu\'il se termine.');
        }

        return $etat;
    }

    private function travailEnCours(): ?int
    {
        $requete = $this->pdo->prepare(
            "SELECT w.id FROM travaux w JOIN travaux_etapes t ON t.travail_id = w.id
             WHERE t.cible = :c AND w.type IN ('installer_sujet_modele', 'geler_sujet_modele') AND t.etat IN ('en_attente', 'en_cours') ORDER BY w.id LIMIT 1"
        );
        $requete->execute([':c' => self::IDENTIFIANT]);

        return Lecture::entier($requete->fetchColumn());
    }
}
