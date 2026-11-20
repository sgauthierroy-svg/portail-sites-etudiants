<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Installation;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Noyau\ErreurSaisie;
use Portail\Travaux\FileTravaux;

/**
 * Côté web des suppressions et des restaurations (lot 5, paquet B, 15/11/2026). Rien n'est fait sur le serveur ici :
 * le portail inscrit un travail, que le travailleur exécute.
 *
 * Décisions : seul l'administrateur supprime (29/10/2026) ; supprimer un étudiant retire tout, sous-domaine compris, et
 * reste restaurable 8 jours (15/11/2026) ; il confirme en tapant l'identifiant (maquette du 05/11/2026).
 */
final class Suppressions
{
    public const SITE = 'supprimer_site';
    public const ETUDIANT = 'supprimer_etudiant';
    public const RESTAURER = 'restaurer';
    /** Sites d'un étudiant qu'on peut supprimer un à un. */
    public const DOSSIERS = ['/WP', '/WP2', '/Presta', '/Presta2'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Suppression depuis la fiche : choix « site:/WP » ou « etudiant », confirmée en tapant l'identifiant.
     *
     * @return int Numéro du travail
     */
    public function depuisFiche(string $identifiant, string $choix, string $confirmation, Compte $auteur, ?string $adresseIp): int
    {
        if (trim($confirmation) !== $identifiant) {
            throw new ErreurSaisie('Pour confirmer, tapez exactement l\'identifiant de l\'étudiant (« ' . $identifiant . ' ») : rien n\'a été supprimé.');
        }
        if ($choix === 'etudiant') {
            return $this->lancer(self::ETUDIANT, [[$identifiant, null]], $auteur, $adresseIp)[0];
        }
        $dossier = str_starts_with($choix, 'site:') ? substr($choix, 5) : '';

        return $this->lancer(self::SITE, [[$identifiant, $dossier]], $auteur, $adresseIp)[0];
    }

    /**
     * Inscrit des suppressions (fiche, ou demandes validées par lot). Un travail pour les étudiants, un par dossier pour les sites.
     *
     * @param list<array{0: string, 1: ?string}> $cibles identifiant, dossier (null pour un étudiant)
     * @return list<int> Numéros des travaux
     */
    public function lancer(string $type, array $cibles, Compte $auteur, ?string $adresseIp, string $motif = ''): array
    {
        if ($type !== self::SITE && $type !== self::ETUDIANT) {
            throw new ErreurSaisie('Suppression inconnue.');
        }
        $groupes = [];
        foreach ($cibles as [$identifiant, $dossier]) {
            $nom = $this->verifier($type, $identifiant, $dossier);
            $groupes[$type === self::SITE ? (string) $dossier : ''][$identifiant] = ['cible' => $identifiant, 'libelle' => $nom];
        }
        $file = new FileTravaux($this->pdo);
        $travaux = [];
        foreach ($groupes as $dossier => $etapes) {
            $parametres = ['demande_par_nom' => $auteur->nomAffiche, 'motif' => mb_substr($motif, 0, 200, 'UTF-8')];
            if ($type === self::SITE) {
                $parametres['dossier'] = $dossier;
            }
            $travaux[] = $file->demander($type, $parametres, array_values($etapes), $auteur, $adresseIp);
        }

        return $travaux;
    }

    /**
     * Toutes les sessions, actives ou archivées, avec leur nombre d'étudiants (page « Supprimer en masse »).
     *
     * @return list<array{id: int, annee: int, etudiants: int}>
     */
    public function sessions(): array
    {
        $liste = [];
        $resultat = $this->pdo->query('SELECT s.id, s.annee, count(e.id) AS nombre FROM sessions s LEFT JOIN etudiants e ON e.session_id = s.id
            GROUP BY s.id, s.annee ORDER BY s.annee');
        foreach (Lecture::lignes($resultat) as $ligne) {
            $liste[] = ['id' => (int) Lecture::entier($ligne['id']), 'annee' => (int) Lecture::entier($ligne['annee']), 'etudiants' => (int) Lecture::entier($ligne['nombre'])];
        }

        return $liste;
    }

    /**
     * Étudiants d'une session avec leurs sites (dernier relevé et portail) et ce qui empêche de les supprimer.
     *
     * @return list<array{identifiant: string, nom: string, regime: string, sites: list<string>, empechement: ?string}>
     */
    public function etudiantsDeSession(int $session): array
    {
        $requete = $this->pdo->prepare('SELECT e.identifiant, e.nom_complet, r.nom AS regime FROM etudiants e JOIN regimes r ON r.id = e.regime_id
            WHERE e.session_id = :s ORDER BY e.nom_complet');
        $requete->execute([':s' => $session]);
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $identifiant = (string) Lecture::texte($ligne['identifiant']);
            $empechement = null;
            if (in_array($identifiant, Installation::SITES_PROTEGES, true) || $identifiant === SiteModele::IDENTIFIANT) {
                $empechement = 'site protégé';
            } elseif ($this->travailPrevu($identifiant)) {
                $empechement = 'travail déjà prévu';
            }
            $liste[] = ['identifiant' => $identifiant, 'nom' => (string) Lecture::texte($ligne['nom_complet']), 'regime' => (string) Lecture::texte($ligne['regime']),
                'sites' => array_values(array_intersect(array_column(Releve::sitesEtudiant($this->pdo, $identifiant), 'dossier'), self::DOSSIERS)),
                'empechement' => $empechement];
        }

        return $liste;
    }

    /**
     * Suppression en masse (demande du 16/11/2026, par exemple toute la session 2027 en juillet), administrateur seul,
     * confirmée en tapant SUPPRIMER. Choix « etudiant » (tout, sous-domaine compris) ou « site:/WP »… : un étudiant coché
     * qui n'a pas ce site est simplement passé.
     *
     * @param list<string> $coches
     * @return array{travaux: list<int>, retenus: int, passes: list<string>}
     */
    public function enMasse(int $session, array $coches, string $choix, string $confirmation, string $motif,
        Compte $auteur, ?string $adresseIp): array
    {
        return $this->supprimerCoches($this->etudiantsDeSession($session), $coches, $choix, $confirmation, $motif,
            $auteur, $adresseIp);
    }

    /**
     * Suppression depuis la Liste des sites (actions groupées, administrateur seul) : les étudiants cochés, quelle
     * que soit leur session, avec les mêmes garde-fous que la suppression par liste (SUPPRIMER à taper, sites
     * protégés passés).
     *
     * @param list<string> $coches
     * @return array{travaux: list<int>, retenus: int, passes: list<string>}
     */
    public function depuisListe(array $coches, string $choix, string $confirmation, string $motif, Compte $auteur,
        ?string $adresseIp): array
    {
        return $this->supprimerCoches($this->etudiantsCoches($coches), $coches, $choix, $confirmation, $motif, $auteur,
            $adresseIp);
    }

    /**
     * Étudiants cochés dans la Liste des sites, avec leurs sites et ce qui empêche de les supprimer (page de
     * confirmation).
     *
     * @param list<string> $coches
     * @return list<array{identifiant: string, nom: string, regime: string, sites: list<string>,
     *                    empechement: ?string}>
     */
    public function etudiantsCoches(array $coches): array
    {
        $liste = [];
        foreach ($this->sessions() as $session) {
            foreach ($this->etudiantsDeSession($session['id']) as $etudiant) {
                if (in_array($etudiant['identifiant'], $coches, true)) {
                    $liste[] = $etudiant;
                }
            }
        }

        return $liste;
    }

    /**
     * Garde-fous communs aux suppressions de plusieurs étudiants, puis un seul travail.
     *
     * @param list<array{identifiant: string, nom: string, regime: string, sites: list<string>,
     *                   empechement: ?string}> $candidats
     * @param list<string> $coches
     * @return array{travaux: list<int>, retenus: int, passes: list<string>}
     */
    private function supprimerCoches(array $candidats, array $coches, string $choix, string $confirmation,
        string $motif, Compte $auteur, ?string $adresseIp): array
    {
        if (trim($confirmation) !== Demandes::MOT_CONFIRMATION) {
            throw new ErreurSaisie('Pour confirmer, tapez ' . Demandes::MOT_CONFIRMATION . ' en majuscules : rien n\'a été supprimé.');
        }
        $dossier = null;
        if ($choix !== 'etudiant') {
            $dossier = str_starts_with($choix, 'site:') ? substr($choix, 5) : '';
            if (!in_array($dossier, self::DOSSIERS, true)) {
                throw new ErreurSaisie('Choisissez ce qu\'il faut supprimer.');
            }
        }
        $etudiants = [];
        foreach ($candidats as $etudiant) {
            $etudiants[$etudiant['identifiant']] = $etudiant;
        }
        $cibles = [];
        $passes = [];
        foreach (array_values(array_unique($coches)) as $identifiant) {
            $etudiant = $etudiants[$identifiant] ?? null;
            if ($etudiant === null) {
                continue;
            }
            if ($etudiant['empechement'] !== null) {
                $passes[] = $etudiant['nom'] . ' (' . $etudiant['empechement'] . ')';
            } elseif ($dossier !== null && !in_array($dossier, $etudiant['sites'], true)) {
                $passes[] = $etudiant['nom'] . ' (pas de site ' . $dossier . ')';
            } else {
                $cibles[] = [$identifiant, $dossier];
            }
        }
        if ($cibles === []) {
            throw new ErreurSaisie('Aucun étudiant coché ne peut être supprimé' . ($passes === [] ? '.' : ' : ' . implode(', ', $passes) . '.'));
        }
        $motif = trim((string) preg_replace('/\s+/u', ' ', $motif));

        return ['travaux' => $this->lancer($dossier === null ? self::ETUDIANT : self::SITE, $cibles, $auteur, $adresseIp,
            $motif === '' ? 'suppression en masse' : mb_substr($motif, 0, 200, 'UTF-8')), 'retenus' => count($cibles), 'passes' => $passes];
    }

    /** Restauration d'une suppression de moins de 8 jours. @return int Numéro du travail */
    public function restaurer(string $chemin, Compte $auteur, ?string $adresseIp): int
    {
        $requete = $this->pdo->prepare('SELECT identifiant, dossier, contenu FROM mises_a_l_ecart
            WHERE chemin = :c AND restaurable = 1 AND purge_le IS NULL AND restaure_le IS NULL');
        $requete->execute([':c' => $chemin]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            throw new ErreurSaisie('Cette suppression n\'est plus restaurable (déjà restaurée, ou effacée au bout des 8 jours).');
        }
        $identifiant = (string) Lecture::texte($ligne['identifiant']);
        if ($this->travailPrevu($identifiant)) {
            throw new ErreurSaisie('Un travail est déjà prévu pour cet étudiant : attendez qu\'il soit terminé (« Travaux en cours »).');
        }
        $nom = Lecture::texte(Lecture::tableau(json_decode((string) Lecture::texte($ligne['contenu']), true))['nom'] ?? null) ?? $identifiant;

        return (new FileTravaux($this->pdo))->demander(self::RESTAURER, ['chemin' => $chemin, 'demande_par_nom' => $auteur->nomAffiche],
            [['cible' => $identifiant, 'libelle' => mb_substr($nom === '' ? $identifiant : $nom, 0, 150, 'UTF-8')]], $auteur, $adresseIp);
    }

    /** @return array<string, string> identifiant => date d'effacement définitif, des étudiants supprimés encore restaurables */
    public static function etudiantsSupprimes(PDO $pdo): array
    {
        $liste = [];
        foreach (MiseALecart::restaurables($pdo) as $ecart) {
            if ($ecart['dossier'] === MiseALecart::SOUS_DOMAINE) {
                $liste[$ecart['identifiant']] = $ecart['expire_le'];
            }
        }

        return $liste;
    }

    /** Nom de l'étudiant si la suppression est possible ; sinon une erreur lisible. */
    public function verifier(string $type, string $identifiant, ?string $dossier): string
    {
        if (in_array($identifiant, Installation::SITES_PROTEGES, true) || $identifiant === SiteModele::IDENTIFIANT) {
            throw new ErreurSaisie('« ' . $identifiant . ' » est un site protégé : jamais supprimé par le portail.');
        }
        $requete = $this->pdo->prepare('SELECT nom_complet FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);
        $nom = Lecture::texte($requete->fetchColumn());
        if ($nom === null) {
            throw new ErreurSaisie('Étudiant « ' . $identifiant . ' » inconnu (déjà supprimé ?).');
        }
        if ($this->travailPrevu($identifiant)) {
            throw new ErreurSaisie($nom . ' : un travail est déjà prévu pour cet étudiant ; attendez qu\'il soit terminé (« Travaux en cours »).');
        }
        if ($type === self::SITE) {
            if (!in_array($dossier, self::DOSSIERS, true)) {
                throw new ErreurSaisie('Choisissez le site à supprimer.');
            }
            $dossiers = array_column(Releve::sitesEtudiant($this->pdo, $identifiant), 'dossier');
            if (!in_array($dossier, $dossiers, true)) {
                throw new ErreurSaisie($nom . ' n\'a pas de site ' . $dossier . ' connu (dernier relevé ou portail) : rien à supprimer.');
            }
        }

        return $nom;
    }

    private function travailPrevu(string $identifiant): bool
    {
        $requete = $this->pdo->prepare("SELECT 1 FROM travaux_etapes WHERE cible = :i AND etat IN ('en_attente', 'en_cours') LIMIT 1");
        $requete->execute([':i' => $identifiant]);

        return $requete->fetchColumn() !== false;
    }
}
