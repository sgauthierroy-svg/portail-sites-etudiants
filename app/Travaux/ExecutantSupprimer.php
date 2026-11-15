<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Base\Installation;
use Portail\Base\Lecture;
use Portail\Journal\Journal;
use Portail\Sites\MiseALecart;
use Portail\Sites\NettoyageCpanel;
use Portail\Sites\OutilsWordPress;
use Portail\Sites\Releve;
use Portail\Sites\SiteModele;
use Portail\Sites\Suppressions;
use RuntimeException;
use Throwable;

/**
 * Suppression d'un site ou d'un étudiant (lot 5, paquet B, 15/11/2026), par l'administrateur seul.
 *
 * Rien n'est effacé tout de suite : le dossier (un site, ou tout le sous-domaine) est mis à l'écart 8 jours avec ce qu'il
 * faut pour le restaurer ; ses bases et utilisateurs MySQL restent en place. Au bout des 8 jours, la purge efface le dossier,
 * les bases, les utilisateurs et, pour un étudiant, le sous-domaine dans CPanel (décision du 15/11/2026 : tout, restaurable 8 jours).
 * Bases retenues : celles connues du portail, celles des fichiers de réglages si elles sont propres à l'étudiant
 * (jafl3067_identifiant…), et, pour un étudiant, toutes celles de CPanel à son nom exact (même règle que nettoyage_etudiants.sh).
 * Une base partagée (jafl3067_peda) ou protégée n'est jamais retenue.
 */
final class ExecutantSupprimer implements Executant
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly NettoyageCpanel $nettoyage,
        private readonly MiseALecart $ecart,
        private readonly string $dossierPersonnel,
        private readonly bool $etudiantEntier,
    ) {
    }

    public function executer(string $cible, array $parametres): array
    {
        if (in_array($cible, Installation::SITES_PROTEGES, true) || $cible === SiteModele::IDENTIFIANT || preg_match('/^[a-z][a-z0-9]{1,62}$/D', $cible) !== 1) {
            throw new RuntimeException('Site protégé ou identifiant refusé : rien n\'a été touché.');
        }
        $auteur = Lecture::texte($parametres['demande_par_nom'] ?? null) ?? 'portail';
        $motif = trim((string) Lecture::texte($parametres['motif'] ?? null));
        $raison = ' (par ' . $auteur . ($motif === '' ? '' : ', motif : ' . $motif) . ')';

        return $this->etudiantEntier
            ? $this->supprimerEtudiant($cible, $raison, $auteur)
            : $this->supprimerSite($cible, (string) Lecture::texte($parametres['dossier'] ?? null), $raison, $auteur);
    }

    /** @return array{etat: 'reussi'|'ignore', message: string} */
    private function supprimerSite(string $identifiant, string $dossier, string $raison, string $auteur): array
    {
        if (!in_array($dossier, Suppressions::DOSSIERS, true)) {
            throw new RuntimeException('Dossier refusé : rien n\'a été touché.');
        }
        $chemin = $this->racine($identifiant) . $dossier;
        $site = $this->lignes($this->pdo->prepare('SELECT * FROM sites WHERE identifiant = :i AND dossier = :d'), $identifiant, $dossier);
        $releve = $this->lignes($this->pdo->prepare('SELECT * FROM releve_sites WHERE identifiant = :i AND dossier = :d'), $identifiant, $dossier);
        if (!is_dir($chemin) || is_link($chemin)) {
            $this->effacerLignes($identifiant, $dossier);

            return ['etat' => 'ignore', 'message' => 'Site ' . $dossier . ' absent du serveur : rien à mettre à l\'écart, fiche mise à jour.'];
        }
        [$base, $utilisateur] = self::baseDuSite($this->pdo, $identifiant, $dossier, $chemin);
        $contenu = ['nom' => $this->nom($identifiant), 'sites' => $site, 'releve' => $releve, 'bases' => [], 'utilisateurs' => []];
        $nom = null;
        $this->pdo->beginTransaction();
        try {
            $nom = $this->ecart->mettre($chemin, $identifiant, $dossier, $base, $utilisateur, 'suppression du site ' . $dossier . $raison, null, $contenu, true);
            $this->effacerLignes($identifiant, $dossier);
            (new Journal($this->pdo))->ecrire('site', 'site supprimé (restaurable 8 jours)', 'succes', null, $auteur, null, $identifiant,
                $dossier . ' mis à l\'écart : ' . $nom . ' ; base ' . ($base ?? 'aucune'));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            if ($nom !== null) {
                $this->ecart->remettre($nom, $chemin);
            }
            throw $e;
        }

        return ['etat' => 'reussi', 'message' => 'Site ' . $dossier . ' supprimé : gardé jusqu\'au ' . self::jour(time() + MiseALecart::JOURS * 86400)
            . ' (bouton « Restaurer » de la page Demandes), puis effacé avec sa base' . ($base === null ? ' (aucune base propre au site trouvée)' : '') . '.'];
    }

    /** @return array{etat: 'reussi'|'ignore', message: string} */
    private function supprimerEtudiant(string $identifiant, string $raison, string $auteur): array
    {
        $etudiant = $this->lignes($this->pdo->prepare('SELECT * FROM etudiants WHERE identifiant = :i'), $identifiant)[0] ?? null;
        if ($etudiant === null) {
            return ['etat' => 'ignore', 'message' => 'Étudiant déjà supprimé : rien à faire.'];
        }
        unset($etudiant['id']);
        $racine = $this->racine($identifiant);
        $sites = $this->lignes($this->pdo->prepare('SELECT * FROM sites WHERE identifiant = :i'), $identifiant);
        $releve = $this->lignes($this->pdo->prepare('SELECT * FROM releve_sites WHERE identifiant = :i'), $identifiant);
        $bases = [];
        $utilisateurs = [];
        foreach ($sites as $site) {
            $bases[] = Lecture::texte($site['base'] ?? null);
            $utilisateurs[] = Lecture::texte($site['utilisateur_bdd'] ?? null);
        }
        foreach (Suppressions::DOSSIERS as $dossier) {
            if (is_dir($racine . $dossier) && !is_link($racine . $dossier)) {
                [$base, $utilisateur] = self::baseDuSite($this->pdo, $identifiant, $dossier, $racine . $dossier);
                $bases[] = $base;
                $utilisateurs[] = $utilisateur;
            }
        }
        // Toutes les bases et tous les utilisateurs CPanel au nom exact de l'étudiant, suivi ou non de _suffixe.
        $propre = '/^jafl3067_' . preg_quote($identifiant, '/') . '(_[A-Za-z0-9_]{1,40})?$/D';
        foreach ($this->nettoyage->bases() as $base) {
            if (preg_match($propre, $base) === 1) {
                $bases[] = $base;
            }
        }
        foreach ($this->nettoyage->utilisateurs() as $utilisateur) {
            if (preg_match($propre, $utilisateur) === 1 && strtolower($utilisateur) === $utilisateur) {
                $utilisateurs[] = $utilisateur;
            }
        }
        $protegees = $this->basesProtegees();
        $bases = array_values(array_filter(array_unique(array_filter($bases, 'is_string')), static fn (string $b): bool => !in_array($b, $protegees, true)));
        $utilisateurs = array_values(array_unique(array_filter($utilisateurs, 'is_string')));
        $contenu = ['nom' => (string) Lecture::texte($etudiant['nom_complet'] ?? null), 'etudiant' => $etudiant, 'sites' => $sites, 'releve' => $releve,
            'bases' => $bases, 'utilisateurs' => $utilisateurs];
        $source = is_dir($racine) && !is_link($racine) ? $racine : null;
        $nom = null;
        $this->pdo->beginTransaction();
        try {
            $nom = $this->ecart->mettre($source, $identifiant, MiseALecart::SOUS_DOMAINE, null, null, 'suppression de l\'étudiant' . $raison, null, $contenu, true);
            $this->pdo->prepare('DELETE FROM sites WHERE identifiant = :i')->execute([':i' => $identifiant]);
            $this->pdo->prepare('DELETE FROM releve_sites WHERE identifiant = :i')->execute([':i' => $identifiant]);
            $this->pdo->prepare('DELETE FROM certificats_a_poser WHERE identifiant = :i')->execute([':i' => $identifiant]);
            $this->pdo->prepare('DELETE FROM etudiants WHERE identifiant = :i')->execute([':i' => $identifiant]);
            (new Journal($this->pdo))->ecrire('site', 'étudiant supprimé (restaurable 8 jours)', 'succes', null, $auteur, null, $identifiant,
                count($sites) . ' site(s) du portail, ' . count($bases) . ' base(s) : ' . ($bases === [] ? 'aucune' : implode(', ', $bases)) . ' ; ' . $nom);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            if ($nom !== null && $source !== null) {
                $this->ecart->remettre($nom, $source);
            }
            throw $e;
        }

        return ['etat' => 'reussi', 'message' => 'Étudiant supprimé : ' . ($source === null ? 'aucun dossier sur le serveur' : 'dossier du sous-domaine')
            . ' et ' . count($bases) . ' base(s) MySQL gardés jusqu\'au ' . self::jour(time() + MiseALecart::JOURS * 86400)
            . ' (bouton « Restaurer » de la page Demandes), puis effacés avec le sous-domaine.'];
    }

    /**
     * Base et utilisateur MySQL d'un site : ceux du portail, sinon ceux de son fichier de réglages s'ils sont propres à l'étudiant.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function baseDuSite(PDO $pdo, string $identifiant, string $dossier, string $chemin): array
    {
        if (!str_starts_with($dossier, '/Presta')) {
            return OutilsWordPress::ancienneBase($pdo, $identifiant, $dossier, $chemin);
        }
        $requete = $pdo->prepare('SELECT base, utilisateur_bdd FROM sites WHERE identifiant = :i AND dossier = :d');
        $requete->execute([':i' => $identifiant, ':d' => $dossier]);
        $ligne = Lecture::ligne($requete);
        if ($ligne !== null) {
            return [Lecture::texte($ligne['base']), Lecture::texte($ligne['utilisateur_bdd'])];
        }
        $fichier = $chemin . '/app/config/parameters.php';
        $reglages = is_file($fichier) && !is_link($fichier) ? (string) file_get_contents($fichier, false, null, 0, 65536) : '';
        $propre = '/^jafl3067_' . preg_quote($identifiant, '/') . '(_[A-Za-z0-9_]{1,40})?$/D';
        $lire = static function (string $cle) use ($reglages, $propre): ?string {
            if (preg_match('/[\'"]' . $cle . '[\'"]\s*=>\s*[\'"]([A-Za-z0-9_]{1,64})[\'"]/', $reglages, $trouve) !== 1) {
                return null;
            }

            return preg_match($propre, $trouve[1]) === 1 ? $trouve[1] : null;
        };
        $utilisateur = $lire('database_user');

        return [$lire('database_name'), $utilisateur !== null && strtolower($utilisateur) === $utilisateur ? $utilisateur : null];
    }

    private function racine(string $identifiant): string
    {
        return $this->dossierPersonnel . '/' . $identifiant . '.' . Releve::DOMAINE;
    }

    private function effacerLignes(string $identifiant, string $dossier): void
    {
        $this->pdo->prepare('DELETE FROM sites WHERE identifiant = :i AND dossier = :d')->execute([':i' => $identifiant, ':d' => $dossier]);
        $this->pdo->prepare('DELETE FROM releve_sites WHERE identifiant = :i AND dossier = :d')->execute([':i' => $identifiant, ':d' => $dossier]);
    }

    /** @return list<array<array-key, mixed>> */
    private function lignes(\PDOStatement $requete, string $identifiant, ?string $dossier = null): array
    {
        $requete->execute($dossier === null ? [':i' => $identifiant] : [':i' => $identifiant, ':d' => $dossier]);
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $liste[] = $ligne;
        }

        return $liste;
    }

    private function nom(string $identifiant): string
    {
        $requete = $this->pdo->prepare('SELECT nom_complet FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);

        return (string) Lecture::texte($requete->fetchColumn());
    }

    /** @return list<string> */
    private function basesProtegees(): array
    {
        $resultat = $this->pdo->query("SELECT valeur FROM parametres WHERE cle = 'bases_protegees'");
        $liste = Lecture::tableau(json_decode((string) Lecture::texte($resultat === false ? null : $resultat->fetchColumn()), true)) ?? [];

        return array_values(array_merge(Installation::BASES_PROTEGEES, array_filter($liste, 'is_string')));
    }

    private static function jour(int $instant): string
    {
        return (new \DateTimeImmutable('@' . $instant))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y');
    }
}
