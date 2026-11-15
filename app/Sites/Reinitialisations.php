<?php

declare(strict_types=1);

namespace Portail\Sites;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Etudiants\RegimesSessions;
use Portail\Noyau\ErreurSaisie;
use Portail\Travaux\FileTravaux;

/**
 * Côté web des réinitialisations (lot 5, 15/11/2026) : depuis la fiche de l'étudiant, par un enseignant
 * ou l'administrateur (l'administratif passe par une demande). Rien n'est fait sur le serveur ici.
 *
 * Redoublants (décision du 07/11/2026) : en 1re année, le WordPress est réinitialisé ; en 2e année, le
 * WordPress est gardé et la boutique est réinitialisée. La fiche le propose après un changement de session.
 */
final class Reinitialisations
{
    public const WORDPRESS = 'reinitialiser_wordpress';
    public const PRESTASHOP = 'reinitialiser_prestashop';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Sites que l'on peut réinitialiser pour cet étudiant, et suggestion pour un redoublant.
     *
     * @return array{wordpress: bool, prestashop: bool, prevu: bool, suggestion: ?string}
     */
    public function etat(string $identifiant, int $maintenant): array
    {
        $requete = $this->pdo->prepare(
            "SELECT
                (EXISTS (SELECT 1 FROM releve_sites WHERE identifiant = :i AND dossier = '/WP' AND logiciel = 'wordpress')
                 OR EXISTS (SELECT 1 FROM sites WHERE identifiant = :i2 AND dossier = '/WP')) AS wordpress,
                EXISTS (SELECT 1 FROM sites WHERE identifiant = :i3 AND dossier = '/Presta') AS prestashop,
                EXISTS (SELECT 1 FROM travaux_etapes t JOIN travaux w ON w.id = t.travail_id
                        WHERE t.cible = :i4 AND w.type IN (:wp, :ps) AND t.etat IN ('en_attente', 'en_cours')) AS prevu"
        );
        $requete->execute([':i' => $identifiant, ':i2' => $identifiant, ':i3' => $identifiant, ':i4' => $identifiant, ':wp' => self::WORDPRESS, ':ps' => self::PRESTASHOP]);
        $ligne = Lecture::ligne($requete) ?? [];

        return [
            'wordpress' => Lecture::entier($ligne['wordpress'] ?? null) === 1,
            'prestashop' => Lecture::entier($ligne['prestashop'] ?? null) === 1,
            'prevu' => Lecture::entier($ligne['prevu'] ?? null) === 1,
            'suggestion' => $this->suggestion($identifiant, $maintenant),
        ];
    }

    /** Inscrit la réinitialisation d'un site de l'étudiant (un travail, une étape). */
    public function lancer(string $type, string $identifiant, bool $confirme, Compte $auteur, ?string $adresseIp, int $maintenant): int
    {
        $requete = $this->pdo->prepare(
            'SELECT e.nom_complet, s.version_wp_gelee, s.paquet_prestashop_id FROM etudiants e JOIN sessions s ON s.id = e.session_id WHERE e.identifiant = :i'
        );
        $requete->execute([':i' => $identifiant]);
        $etudiant = Lecture::ligne($requete);
        if ($etudiant === null) {
            throw new ErreurSaisie('Étudiant inconnu.');
        }
        $etat = $this->etat($identifiant, $maintenant);
        if ($etat['prevu']) {
            throw new ErreurSaisie('Une réinitialisation est déjà prévue pour cet étudiant : voir « Travaux en cours ».');
        }
        if (!$confirme) {
            throw new ErreurSaisie('Cochez la case de confirmation : le site actuel sera remplacé (gardé 8 jours) et l\'étudiant recevra de nouveaux accès.');
        }
        if ($type === self::WORDPRESS) {
            if (!$etat['wordpress']) {
                throw new ErreurSaisie('Cet étudiant n\'a pas de WordPress /WP connu : utilisez « Créer des WordPress ».');
            }
            $parametres = ['version' => Lecture::texte($etudiant['version_wp_gelee']), 'titre' => PreparationWordPress::TITRE];
        } elseif ($type === self::PRESTASHOP) {
            if (!$etat['prestashop']) {
                throw new ErreurSaisie('Cet étudiant n\'a pas de boutique créée par le portail : rien à réinitialiser.');
            }
            $paquet = Lecture::entier($etudiant['paquet_prestashop_id']);
            if ($paquet === null) {
                throw new ErreurSaisie('Aucune version PrestaShop n\'est fixée pour sa session : rien n\'a été demandé.');
            }
            $parametres = ['paquet_id' => $paquet, 'nom_boutique' => 'Sup'];
        } else {
            throw new ErreurSaisie('Réinitialisation inconnue.');
        }
        $parametres['demande_par_nom'] = $auteur->nomAffiche;

        return (new FileTravaux($this->pdo))->demander($type, $parametres,
            [['cible' => $identifiant, 'libelle' => (string) Lecture::texte($etudiant['nom_complet'])]], $auteur, $adresseIp);
    }

    /**
     * Redoublant : dernier changement de session vers une session plus tardive, après lequel le site
     * concerné n'a pas encore été refait par le portail. 1re année : WordPress ; 2e année : boutique.
     */
    private function suggestion(string $identifiant, int $maintenant): ?string
    {
        $requete = $this->pdo->prepare("SELECT date, ancienne_valeur, nouvelle_valeur FROM historique_etudiants
            WHERE etudiant_identifiant = :i AND champ = 'session' ORDER BY date DESC, id DESC LIMIT 1");
        $requete->execute([':i' => $identifiant]);
        $changement = Lecture::ligne($requete);
        if ($changement === null) {
            return null;
        }
        $ancienne = (int) preg_replace('/\D/', '', (string) Lecture::texte($changement['ancienne_valeur']));
        $nouvelle = (int) preg_replace('/\D/', '', (string) Lecture::texte($changement['nouvelle_valeur']));
        if ($nouvelle <= $ancienne) {
            return null;
        }
        $annee = RegimesSessions::anneeDeFormation($nouvelle, $maintenant);
        $dossier = $annee === '1re année' ? '/WP' : ($annee === '2e année' ? '/Presta' : null);
        if ($dossier === null) {
            return null;
        }
        $refait = $this->pdo->prepare('SELECT 1 FROM sites WHERE identifiant = :i AND dossier = :d AND cree_le >= :date');
        $refait->execute([':i' => $identifiant, ':d' => $dossier, ':date' => (string) Lecture::texte($changement['date'])]);
        if ($refait->fetchColumn() !== false) {
            return null;
        }

        return $dossier === '/WP'
            ? 'Redoublant de 1re année : son WordPress est à réinitialiser (décision du 07/11/2026).'
            : 'Redoublant de 2e année : sa boutique est à réinitialiser ; son WordPress est gardé (décision du 07/11/2026).';
    }
}
