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

    /** Déplace le dossier et l'inscrit. @return string Nom du dossier mis à l'écart */
    public function mettre(string $source, string $identifiant, string $dossier, ?string $base, ?string $utilisateur, string $motif, ?int $maintenant = null): string
    {
        $maintenant ??= time();
        $racine = $this->racine();
        if (!is_dir($racine) && !mkdir($racine, 0700, true)) {
            throw new RuntimeException('Création du dossier des mises à l\'écart impossible.');
        }
        $nom = gmdate('Ymd-His', $maintenant) . '-' . $identifiant . '-' . trim(str_replace('/', '-', $dossier), '-') . '-' . bin2hex(random_bytes(3));
        if (is_link($source) || !is_dir($source) || !rename($source, $racine . '/' . $nom)) {
            throw new RuntimeException('Mise à l\'écart de ' . $dossier . ' impossible : rien n\'a été déplacé.');
        }
        $this->pdo->prepare(
            'INSERT INTO mises_a_l_ecart (identifiant, dossier, chemin, base, utilisateur_bdd, motif, mis_le, expire_le) VALUES (:i, :d, :c, :b, :u, :m, :le, :exp)'
        )->execute([':i' => $identifiant, ':d' => $dossier, ':c' => $nom, ':b' => $base, ':u' => $utilisateur, ':m' => mb_substr($motif, 0, 200, 'UTF-8'),
            ':le' => gmdate('Y-m-d\TH:i:s\Z', $maintenant), ':exp' => gmdate('Y-m-d\TH:i:s\Z', $maintenant + self::JOURS * 86400)]);

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
     * @param list<string> $basesProtegees
     * @return ?string Ce qui a été effacé (null : rien à faire)
     */
    public function purgerUne(ServeurCpanel $cpanel, array $basesProtegees, ?int $maintenant = null): ?string
    {
        $requete = $this->pdo->prepare('SELECT id, identifiant, dossier, chemin, base, utilisateur_bdd FROM mises_a_l_ecart WHERE purge_le IS NULL AND expire_le <= :m ORDER BY expire_le LIMIT 1');
        $requete->execute([':m' => gmdate('Y-m-d\TH:i:s\Z', $maintenant ?? time())]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            return null;
        }
        $nom = (string) Lecture::texte($ligne['chemin']);
        $base = Lecture::texte($ligne['base']);
        $utilisateur = Lecture::texte($ligne['utilisateur_bdd']);
        $journal = new Journal($this->pdo);
        try {
            $chemin = $this->racine() . '/' . $nom;
            if (is_dir($chemin) && !is_link($chemin)) {
                Fichiers::effacerDossier($chemin, $this->racine());
            }
            // Base et utilisateur effacés seulement s'ils ne servent plus : ni à un site connu du portail,
            // ni au site en place à l'adresse d'origine (cas d'un retour en arrière fait à la main).
            $enPlace = $this->configurationEnPlace((string) Lecture::texte($ligne['identifiant']), (string) Lecture::texte($ligne['dossier']));
            if ($utilisateur !== null && !$this->enUsage('utilisateur_bdd', $utilisateur) && !str_contains($enPlace, "'" . $utilisateur . "'")) {
                $cpanel->supprimerUtilisateur($utilisateur);
            }
            if ($base !== null && !in_array($base, $basesProtegees, true) && !$this->enUsage('base', $base) && !str_contains($enPlace, "'" . $base . "'")) {
                $cpanel->supprimerBase($base);
            }
        } catch (Throwable $e) {
            $journal->ecrire('site', 'effacement d\'une mise à l\'écart', 'echec', null, 'travailleur', null, $nom, mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
            // Réessayé à la prochaine échéance : un jour plus tard.
            $this->pdo->prepare("UPDATE mises_a_l_ecart SET expire_le = strftime('%Y-%m-%dT%H:%M:%SZ', expire_le, '+1 day') WHERE id = :id")
                ->execute([':id' => Lecture::entier($ligne['id'])]);

            return null;
        }
        $this->pdo->prepare("UPDATE mises_a_l_ecart SET purge_le = strftime('%Y-%m-%dT%H:%M:%SZ', 'now') WHERE id = :id")->execute([':id' => Lecture::entier($ligne['id'])]);
        $journal->ecrire('site', 'mise à l\'écart effacée (8 jours écoulés)', 'succes', null, 'travailleur', null, $nom, ($base ?? 'sans base') . ', ' . ($utilisateur ?? 'sans utilisateur'));

        return $nom;
    }

    /** @return list<array{chemin: string, identifiant: string, dossier: string, motif: string, mis_le: string, expire_le: string}> */
    public static function enCours(PDO $pdo, ?string $identifiant = null): array
    {
        $requete = $pdo->prepare('SELECT chemin, identifiant, dossier, motif, mis_le, expire_le FROM mises_a_l_ecart
            WHERE purge_le IS NULL AND (:i IS NULL OR identifiant = :i2) ORDER BY mis_le DESC');
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

    /** Configuration du site actuellement à l'adresse d'origine (vide s'il n'y en a pas). */
    private function configurationEnPlace(string $identifiant, string $dossier): string
    {
        $site = $this->dossierPersonnel . '/' . $identifiant . '.' . Releve::DOMAINE . $dossier;
        $contenu = '';
        foreach (['/wp-config.php', '/app/config/parameters.php'] as $fichier) {
            if (is_file($site . $fichier) && !is_link($site . $fichier)) {
                $contenu .= (string) file_get_contents($site . $fichier, false, null, 0, 65536);
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
