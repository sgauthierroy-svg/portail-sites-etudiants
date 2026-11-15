<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Lecture;
use Portail\Journal\Journal;
use Portail\Systeme\Fichiers;
use RuntimeException;
use Throwable;

/**
 * Mise à l'écart d'un site remplacé (décision du 08/11/2026 : sauvegarde de 8 jours).
 *
 * Le dossier du site est déplacé, tel quel, dans ~/archives_suppression/portail/ (hors de
 * portée du web) ; sa base et son utilisateur MySQL restent en place. Retour en arrière
 * pendant 8 jours : remettre le dossier à sa place (son wp-config.php pointe toujours sur sa base).
 * Au-delà, le travailleur efface le dossier, la base et l'utilisateur, une mise à l'écart à la fois.
 */
final class MiseALecart
{
    public const JOURS = 8;

    public function __construct(private readonly PDO $pdo, private readonly string $dossierPersonnel)
    {
    }

    public function racine(): string
    {
        return $this->dossierPersonnel . '/archives_suppression/portail';
    }

    /** Dossier entier du sous-domaine d'un étudiant supprimé (lot 5, paquet B) : le sous-domaine est retiré de CPanel à l'effacement. */
    public const SOUS_DOMAINE = '/Sousdomaine';

    /**
     * Déplace le dossier et l'inscrit. Source null : rien à déplacer (étudiant supprimé sans dossier sur le serveur).
     *
     * @param ?array<string, mixed> $contenu De quoi revenir en arrière et tout effacer au bout des 8 jours (suppressions)
     * @return string Nom du dossier mis à l'écart
     */
    public function mettre(?string $source, string $identifiant, string $dossier, ?string $base, ?string $utilisateur, string $motif, ?int $maintenant = null,
        ?array $contenu = null, bool $restaurable = false): string
    {
        $maintenant ??= time();
        $racine = $this->racine();
        if (!is_dir($racine) && !mkdir($racine, 0700, true)) {
            throw new RuntimeException('Création du dossier des mises à l\'écart impossible.');
        }
        $nom = gmdate('Ymd-His', $maintenant) . '-' . $identifiant . '-' . trim(str_replace('/', '-', $dossier), '-') . '-' . bin2hex(random_bytes(3));
        if ($source !== null && (is_link($source) || !is_dir($source) || !rename($source, $racine . '/' . $nom))) {
            throw new RuntimeException('Mise à l\'écart de ' . $dossier . ' impossible : rien n\'a été déplacé.');
        }
        $this->pdo->prepare(
            'INSERT INTO mises_a_l_ecart (identifiant, dossier, chemin, base, utilisateur_bdd, motif, mis_le, expire_le, restaurable, contenu)
             VALUES (:i, :d, :c, :b, :u, :m, :le, :exp, :r, :contenu)'
        )->execute([':i' => $identifiant, ':d' => $dossier, ':c' => $nom, ':b' => $base, ':u' => $utilisateur, ':m' => mb_substr($motif, 0, 200, 'UTF-8'),
            ':le' => gmdate('Y-m-d\TH:i:s\Z', $maintenant), ':exp' => gmdate('Y-m-d\TH:i:s\Z', $maintenant + self::JOURS * 86400),
            ':r' => $restaurable ? 1 : 0,
            ':contenu' => $contenu === null ? null : json_encode($contenu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

        return $nom;
    }

    /** Annule une mise à l'écart qui vient d'être faite (échec de l'étape suivante). */
    public function remettre(string $nom, string $destination): void
    {
        if (file_exists($destination) || !rename($this->racine() . '/' . $nom, $destination)) {
            throw new RuntimeException('Le site mis à l\'écart n\'a pas pu être remis à sa place : il est dans archives_suppression/portail/' . $nom . '.');
        }
        $this->pdo->prepare('DELETE FROM mises_a_l_ecart WHERE chemin = :c')->execute([':c' => $nom]);
    }

    /**
     * Efface la plus ancienne mise à l'écart arrivée à échéance.
     *
     * Étudiant supprimé (dossier SOUS_DOMAINE) : en plus, toutes ses bases et tous ses utilisateurs MySQL notés à la
     * suppression, puis son sous-domaine dans CPanel et le renouvellement de son certificat ; sans $nettoyage, rien n'est
     * effacé et l'essai est remis au lendemain. Chaque effacement est vérifié avant (déjà fait : rien à refaire).
     *
     * @param list<string> $basesProtegees
     * @return ?string Ce qui a été effacé (null : rien à faire)
     */
    public function purgerUne(ServeurCpanel $cpanel, array $basesProtegees, ?int $maintenant = null, ?NettoyageCpanel $nettoyage = null,
        ?RetraitCertificats $certificats = null): ?string
    {
        $requete = $this->pdo->prepare('SELECT id, identifiant, dossier, chemin, base, utilisateur_bdd, contenu FROM mises_a_l_ecart
            WHERE purge_le IS NULL AND restaure_le IS NULL AND expire_le <= :m ORDER BY expire_le LIMIT 1');
        $requete->execute([':m' => gmdate('Y-m-d\TH:i:s\Z', $maintenant ?? time())]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            return null;
        }
        $nom = (string) Lecture::texte($ligne['chemin']);
        $identifiant = (string) Lecture::texte($ligne['identifiant']);
        $dossier = (string) Lecture::texte($ligne['dossier']);
        $contenu = self::contenu(Lecture::texte($ligne['contenu']));
        $base = Lecture::texte($ligne['base']);
        $utilisateur = Lecture::texte($ligne['utilisateur_bdd']);
        $bases = array_values(array_unique(array_merge($base === null ? [] : [$base], $contenu['bases'])));
        $utilisateurs = array_values(array_unique(array_merge($utilisateur === null ? [] : [$utilisateur], $contenu['utilisateurs'])));
        $journal = new Journal($this->pdo);
        try {
            $sousDomaine = $dossier === self::SOUS_DOMAINE;
            if ($sousDomaine && $nettoyage === null) {
                throw new RuntimeException('effacement d\'un étudiant supprimé impossible sans l\'accès à CPanel');
            }
            $chemin = $this->racine() . '/' . $nom;
            if (is_dir($chemin) && !is_link($chemin)) {
                Fichiers::effacerDossier($chemin, $this->racine());
            }
            // Base et utilisateur effacés seulement s'ils ne servent plus : ni à un site connu du portail,
            // ni au site en place à l'adresse d'origine (cas d'un retour en arrière fait à la main).
            $enPlace = $this->configurationEnPlace($identifiant, $sousDomaine ? '/WP' : $dossier);
            $existants = $nettoyage === null ? null : ['bases' => $nettoyage->bases(), 'utilisateurs' => $nettoyage->utilisateurs()];
            foreach ($utilisateurs as $utilisateur) {
                if (!$this->enUsage('utilisateur_bdd', $utilisateur) && !str_contains($enPlace, "'" . $utilisateur . "'")
                    && ($existants === null || in_array($utilisateur, $existants['utilisateurs'], true))) {
                    $cpanel->supprimerUtilisateur($utilisateur);
                }
            }
            foreach ($bases as $base) {
                if (!in_array($base, $basesProtegees, true) && !$this->enUsage('base', $base) && !str_contains($enPlace, "'" . $base . "'")
                    && ($existants === null || in_array($base, $existants['bases'], true))) {
                    $cpanel->supprimerBase($base);
                }
            }
            if ($sousDomaine && !$this->etudiantPresent($identifiant)) {
                if ($certificats !== null) {
                    try {
                        $certificats->retirer($identifiant . '.' . Releve::DOMAINE);
                    } catch (Throwable) {
                        // Sans conséquence : acme.sh échouera seulement à renouveler un certificat devenu inutile.
                    }
                }
                $nettoyage->supprimerSousDomaine($identifiant);
                $this->pdo->prepare('DELETE FROM sous_domaines WHERE identifiant = :i')->execute([':i' => $identifiant]);
                $this->pdo->prepare('DELETE FROM releve_sites WHERE identifiant = :i')->execute([':i' => $identifiant]);
                $this->pdo->prepare('DELETE FROM etat_certificats WHERE identifiant = :i')->execute([':i' => $identifiant]);
                $this->pdo->prepare('DELETE FROM certificats_a_poser WHERE identifiant = :i')->execute([':i' => $identifiant]);
            }
        } catch (Throwable $e) {
            $journal->ecrire('site', 'effacement d\'une mise à l\'écart', 'echec', null, 'travailleur', null, $nom, mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
            // Réessayé à la prochaine échéance : un jour plus tard.
            $this->pdo->prepare("UPDATE mises_a_l_ecart SET expire_le = strftime('%Y-%m-%dT%H:%M:%SZ', expire_le, '+1 day') WHERE id = :id")
                ->execute([':id' => Lecture::entier($ligne['id'])]);

            return null;
        }
        $this->pdo->prepare("UPDATE mises_a_l_ecart SET purge_le = strftime('%Y-%m-%dT%H:%M:%SZ', 'now') WHERE id = :id")->execute([':id' => Lecture::entier($ligne['id'])]);
        $journal->ecrire('site', 'mise à l\'écart effacée (8 jours écoulés)', 'succes', null, 'travailleur', null, $nom,
            ($bases === [] ? 'sans base' : implode(', ', $bases)) . ' ; ' . ($utilisateurs === [] ? 'sans utilisateur' : implode(', ', $utilisateurs))
            . ($dossier === self::SOUS_DOMAINE ? ' ; sous-domaine retiré de CPanel' : ''));

        return $nom;
    }

    /**
     * Suppressions qu'on peut encore restaurer (8 jours), les plus récentes d'abord.
     *
     * @return list<array{chemin: string, identifiant: string, dossier: string, motif: string, mis_le: string, expire_le: string, nom: string}>
     */
    public static function restaurables(PDO $pdo): array
    {
        $requete = $pdo->query('SELECT chemin, identifiant, dossier, motif, mis_le, expire_le, contenu FROM mises_a_l_ecart
            WHERE restaurable = 1 AND purge_le IS NULL AND restaure_le IS NULL ORDER BY mis_le DESC');
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $contenu = self::contenu(Lecture::texte($ligne['contenu']));
            $liste[] = [
                'chemin' => (string) Lecture::texte($ligne['chemin']), 'identifiant' => (string) Lecture::texte($ligne['identifiant']),
                'dossier' => (string) Lecture::texte($ligne['dossier']), 'motif' => (string) Lecture::texte($ligne['motif']),
                'mis_le' => (string) Lecture::texte($ligne['mis_le']), 'expire_le' => (string) Lecture::texte($ligne['expire_le']),
                'nom' => $contenu['nom'],
            ];
        }

        return $liste;
    }

    /**
     * Mise à l'écart restaurable, par son nom, avec son contenu.
     *
     * @return ?array{identifiant: string, dossier: string, expire_le: string, contenu: array{nom: string, bases: list<string>, utilisateurs: list<string>, etudiant: ?array<array-key, mixed>, sites: list<array<array-key, mixed>>, releve: list<array<array-key, mixed>>}}
     */
    public function restaurable(string $chemin): ?array
    {
        $requete = $this->pdo->prepare('SELECT identifiant, dossier, expire_le, contenu FROM mises_a_l_ecart
            WHERE chemin = :c AND restaurable = 1 AND purge_le IS NULL AND restaure_le IS NULL');
        $requete->execute([':c' => $chemin]);
        $ligne = Lecture::ligne($requete);

        return $ligne === null ? null : ['identifiant' => (string) Lecture::texte($ligne['identifiant']), 'dossier' => (string) Lecture::texte($ligne['dossier']),
            'expire_le' => (string) Lecture::texte($ligne['expire_le']), 'contenu' => self::contenu(Lecture::texte($ligne['contenu']))];
    }

    /** Remet le dossier mis à l'écart à sa place (restauration demandée par l'administrateur) ; la ligne reste, marquée restaurée. */
    public function restaurerDossier(string $chemin, string $destination): void
    {
        $source = $this->racine() . '/' . $chemin;
        if (!is_dir($source) || is_link($source)) {
            return;
        }
        $existant = is_dir($destination) && !is_link($destination) ? scandir($destination) : false;
        if (is_array($existant) && count($existant) === 2) {
            rmdir($destination);
        }
        if (file_exists($destination) || is_link($destination) || !rename($source, $destination)) {
            throw new RuntimeException('Restauration impossible : un dossier est déjà à cette place. Rien n\'a été déplacé.');
        }
    }

    public function marquerRestauree(string $chemin): void
    {
        $this->pdo->prepare("UPDATE mises_a_l_ecart SET restaure_le = strftime('%Y-%m-%dT%H:%M:%SZ', 'now') WHERE chemin = :c")->execute([':c' => $chemin]);
    }

    /** Étudiant supprimé, encore restaurable : date d'effacement définitif, sinon null. Son identifiant reste réservé jusque-là. */
    public static function suppressionEnCours(PDO $pdo, string $identifiant): ?string
    {
        $requete = $pdo->prepare('SELECT expire_le FROM mises_a_l_ecart
            WHERE identifiant = :i AND dossier = :d AND restaurable = 1 AND purge_le IS NULL AND restaure_le IS NULL ORDER BY expire_le DESC LIMIT 1');
        $requete->execute([':i' => $identifiant, ':d' => self::SOUS_DOMAINE]);

        return Lecture::texte($requete->fetchColumn());
    }

    /**
     * @return array{nom: string, bases: list<string>, utilisateurs: list<string>, etudiant: ?array<array-key, mixed>, sites: list<array<array-key, mixed>>, releve: list<array<array-key, mixed>>}
     */
    private static function contenu(?string $json): array
    {
        $donnees = $json === null ? [] : (Lecture::tableau(json_decode($json, true)) ?? []);
        $textes = static function (mixed $liste): array {
            $resultat = [];
            $tableau = Lecture::tableau($liste) ?? [];
            foreach (array_keys($tableau) as $cle) {
                $texte = Lecture::texte($tableau[$cle]);
                if ($texte !== null) {
                    $resultat[] = $texte;
                }
            }

            return $resultat;
        };
        $lignes = static function (mixed $liste): array {
            $resultat = [];
            $tableau = Lecture::tableau($liste) ?? [];
            foreach (array_keys($tableau) as $cle) {
                $ligne = Lecture::tableau($tableau[$cle]);
                if ($ligne !== null) {
                    $resultat[] = $ligne;
                }
            }

            return $resultat;
        };
        $etudiant = Lecture::tableau($donnees['etudiant'] ?? null);

        return ['nom' => (string) Lecture::texte($donnees['nom'] ?? null), 'bases' => $textes($donnees['bases'] ?? null),
            'utilisateurs' => $textes($donnees['utilisateurs'] ?? null), 'etudiant' => $etudiant,
            'sites' => $lignes($donnees['sites'] ?? null), 'releve' => $lignes($donnees['releve'] ?? null)];
    }

    private function etudiantPresent(string $identifiant): bool
    {
        $requete = $this->pdo->prepare('SELECT 1 FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);

        return $requete->fetchColumn() !== false;
    }

    /** @return list<array{chemin: string, identifiant: string, dossier: string, motif: string, mis_le: string, expire_le: string}> */
    public static function enCours(PDO $pdo, ?string $identifiant = null): array
    {
        $requete = $pdo->prepare('SELECT chemin, identifiant, dossier, motif, mis_le, expire_le FROM mises_a_l_ecart
            WHERE purge_le IS NULL AND restaure_le IS NULL AND (:i IS NULL OR identifiant = :i2) ORDER BY mis_le DESC');
        $requete->execute([':i' => $identifiant, ':i2' => $identifiant]);
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $liste[] = [
                'chemin' => (string) Lecture::texte($ligne['chemin']), 'identifiant' => (string) Lecture::texte($ligne['identifiant']),
                'dossier' => (string) Lecture::texte($ligne['dossier']), 'motif' => (string) Lecture::texte($ligne['motif']),
                'mis_le' => (string) Lecture::texte($ligne['mis_le']), 'expire_le' => (string) Lecture::texte($ligne['expire_le']),
            ];
        }

        return $liste;
    }

    /**
     * Configurations des sites actuellement en place chez cet étudiant (dossier d'origine et ses autres sites) :
     * une base partagée entre deux de ses sites (scripts d'avant le portail) n'est jamais effacée.
     */
    private function configurationEnPlace(string $identifiant, string $dossier): string
    {
        $racine = $this->dossierPersonnel . '/' . $identifiant . '.' . Releve::DOMAINE;
        $contenu = '';
        foreach (array_unique([$dossier, '/WP', '/WP2', '/Presta', '/Presta2']) as $sousDossier) {
            foreach (['/wp-config.php', '/app/config/parameters.php'] as $fichier) {
                $chemin = $racine . $sousDossier . $fichier;
                if (is_file($chemin) && !is_link($chemin)) {
                    $contenu .= (string) file_get_contents($chemin, false, null, 0, 65536);
                }
            }
        }

        return $contenu;
    }

    private function enUsage(string $colonne, string $valeur): bool
    {
        $requete = $this->pdo->prepare($colonne === 'base' ? 'SELECT 1 FROM sites WHERE base = :v' : 'SELECT 1 FROM sites WHERE utilisateur_bdd = :v');
        $requete->execute([':v' => $valeur]);

        return $requete->fetchColumn() !== false;
    }
}
