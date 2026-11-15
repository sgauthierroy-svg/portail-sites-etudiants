<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Base\Lecture;
use Portail\Journal\Journal;
use Portail\Sites\MiseALecart;
use Portail\Sites\RelanceCertificats;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use RuntimeException;
use Throwable;

/**
 * Restauration d'une suppression de moins de 8 jours (lot 5, paquet B, 15/11/2026), par l'administrateur seul.
 *
 * Le dossier mis à l'écart reprend sa place, tel quel : ses fichiers de réglages pointent toujours sur ses bases, qui
 * n'ont pas été effacées. Pour un étudiant, sa fiche et ses sites reviennent dans le portail ; si son sous-domaine a été
 * retiré de CPanel entre-temps (à la main), il est recréé et son certificat redemandé.
 */
final class ExecutantRestaurer implements Executant
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ServeurCpanel $cpanel,
        private readonly MiseALecart $ecart,
        private readonly string $dossierPersonnel,
    ) {
    }

    public function executer(string $cible, array $parametres): array
    {
        $chemin = (string) Lecture::texte($parametres['chemin'] ?? null);
        $auteur = Lecture::texte($parametres['demande_par_nom'] ?? null) ?? 'portail';
        $ecart = preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $chemin) === 1 ? $this->ecart->restaurable($chemin) : null;
        if ($ecart === null || $ecart['identifiant'] !== $cible) {
            throw new RuntimeException('Cette suppression n\'est plus restaurable (déjà restaurée, ou effacée au bout des 8 jours).');
        }
        $racine = $this->dossierPersonnel . '/' . $cible . '.' . Releve::DOMAINE;
        $contenu = $ecart['contenu'];

        if ($ecart['dossier'] === MiseALecart::SOUS_DOMAINE) {
            if ($this->etudiantPresent($cible)) {
                throw new RuntimeException('Un étudiant porte de nouveau cet identifiant dans le portail : restauration impossible, rien n\'a été touché.');
            }
            $etudiant = $contenu['etudiant'];
            if ($etudiant === null) {
                throw new RuntimeException('Fiche de l\'étudiant introuvable dans la sauvegarde : restauration impossible.');
            }
            $recree = !isset($this->cpanel->sousDomaines()[$cible]);
            if ($recree) {
                $this->cpanel->creerSousDomaine($cible);
            }
            $this->ecart->restaurerDossier($chemin, $racine);
            $this->pdo->beginTransaction();
            try {
                $this->insererEtudiant($etudiant);
                $this->insererSites($contenu['sites'], $contenu['releve']);
                $this->ecart->marquerRestauree($chemin);
                (new Journal($this->pdo))->ecrire('site', 'étudiant restauré', 'succes', null, $auteur, null, $cible, $chemin);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw new RuntimeException('Dossier remis en place, mais la fiche n\'a pas pu être restaurée : ' . mb_substr($e->getMessage(), 0, 200, 'UTF-8'));
            }
            if ($recree) {
                (new RelanceCertificats($this->pdo))->demander($cible, 'sous-domaine recréé à la restauration');
            }

            return ['etat' => 'reussi', 'message' => 'Étudiant restauré : fiche, sites et bases remis en place'
                . ($recree ? ' ; sous-domaine recréé, certificat https demandé (page « Certificats »)' : '') . '.'];
        }

        if (!$this->etudiantPresent($cible)) {
            throw new RuntimeException('L\'étudiant a été supprimé depuis : restaurez d\'abord l\'étudiant.');
        }
        if (!is_dir($racine) || is_link($racine)) {
            throw new RuntimeException('Dossier du sous-domaine absent : restauration impossible.');
        }
        $this->ecart->restaurerDossier($chemin, $racine . $ecart['dossier']);
        $this->pdo->beginTransaction();
        try {
            $this->insererSites($contenu['sites'], $contenu['releve']);
            $this->ecart->marquerRestauree($chemin);
            (new Journal($this->pdo))->ecrire('site', 'site restauré', 'succes', null, $auteur, null, $cible, $ecart['dossier'] . ' ; ' . $chemin);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Site remis en place, mais sa ligne dans le portail n\'a pas pu être restaurée : ' . mb_substr($e->getMessage(), 0, 200, 'UTF-8'));
        }

        return ['etat' => 'reussi', 'message' => 'Site ' . $ecart['dossier'] . ' restauré, avec sa base.'];
    }

    /** @param array<array-key, mixed> $e */
    private function insererEtudiant(array $e): void
    {
        $this->pdo->prepare('INSERT INTO etudiants (nom_complet, identifiant, identifiant_wp, email, regime_id, session_id, ajoute_le, ajoute_par, origine)
            VALUES (:n, :i, :w, :e, :r, :s, :a, NULL, :o)')->execute([
            ':n' => Lecture::texte($e['nom_complet'] ?? null), ':i' => Lecture::texte($e['identifiant'] ?? null), ':w' => Lecture::texte($e['identifiant_wp'] ?? null),
            ':e' => Lecture::texte($e['email'] ?? null), ':r' => Lecture::entier($e['regime_id'] ?? null), ':s' => Lecture::entier($e['session_id'] ?? null),
            ':a' => Lecture::texte($e['ajoute_le'] ?? null) ?? gmdate('Y-m-d\TH:i:s\Z'), ':o' => Lecture::texte($e['origine'] ?? null),
        ]);
    }

    /**
     * @param list<array<array-key, mixed>> $sites
     * @param list<array<array-key, mixed>> $releve
     */
    private function insererSites(array $sites, array $releve): void
    {
        $site = $this->pdo->prepare('INSERT INTO sites (identifiant, dossier, logiciel, version, dossier_admin, base, utilisateur_bdd, cree_le, gele, cloture_le, lecture_seule)
            VALUES (:i, :d, :l, :v, :a, :b, :u, :c, :g, :cl, :ls)');
        foreach ($sites as $s) {
            $site->execute([':i' => Lecture::texte($s['identifiant'] ?? null), ':d' => Lecture::texte($s['dossier'] ?? null), ':l' => Lecture::texte($s['logiciel'] ?? null),
                ':v' => Lecture::texte($s['version'] ?? null), ':a' => Lecture::texte($s['dossier_admin'] ?? null), ':b' => Lecture::texte($s['base'] ?? null),
                ':u' => Lecture::texte($s['utilisateur_bdd'] ?? null), ':c' => Lecture::texte($s['cree_le'] ?? null) ?? gmdate('Y-m-d\TH:i:s\Z'),
                ':g' => Lecture::entier($s['gele'] ?? null) ?? 0, ':cl' => Lecture::texte($s['cloture_le'] ?? null), ':ls' => Lecture::entier($s['lecture_seule'] ?? null) ?? 0]);
        }
        $ligne = $this->pdo->prepare('INSERT OR IGNORE INTO releve_sites (identifiant, dossier, logiciel, version, https_actif, certificat_expire_le, taille_octets,
            derniere_modif, releve_le, module_mise_a_jour) VALUES (:i, :d, :l, :v, :h, :c, :t, :m, :r, :mm)');
        foreach ($releve as $r) {
            $ligne->execute([':i' => Lecture::texte($r['identifiant'] ?? null), ':d' => Lecture::texte($r['dossier'] ?? null), ':l' => Lecture::texte($r['logiciel'] ?? null),
                ':v' => Lecture::texte($r['version'] ?? null), ':h' => Lecture::entier($r['https_actif'] ?? null), ':c' => Lecture::texte($r['certificat_expire_le'] ?? null),
                ':t' => Lecture::entier($r['taille_octets'] ?? null), ':m' => Lecture::texte($r['derniere_modif'] ?? null),
                ':r' => Lecture::texte($r['releve_le'] ?? null) ?? gmdate('Y-m-d\TH:i:s\Z'), ':mm' => Lecture::entier($r['module_mise_a_jour'] ?? null)]);
        }
    }

    private function etudiantPresent(string $identifiant): bool
    {
        $requete = $this->pdo->prepare('SELECT 1 FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);

        return $requete->fetchColumn() !== false;
    }
}
