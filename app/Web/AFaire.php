<?php

declare(strict_types=1);

namespace Portail\Web;

use PDO;
use Portail\Sites\Demandes;
use Portail\Sites\Releve;
use Portail\Sites\SuiviCertificats;
use Portail\Travaux\FileTravaux;

/**
 * Bloc « À faire » de l'accueil de l'administrateur (améliorations C1 et D17) : ce qui attend une action de sa part,
 * en compteurs cliquables. Les problèmes signalés (SAV) passent en premier, en couleur d'alerte. Rien ne s'affiche
 * quand tout va bien. Juste en dessous : les travaux en cours avec leur barre d'avancement (B7 et D16).
 *
 * @psalm-type Ligne = array{urgent: bool, nombre: int, titre: string, texte: string, adresse: string}
 */
final class AFaire
{
    /** Durée pendant laquelle un travail en échec reste signalé. */
    private const JOURS_ECHECS = 7;

    /**
     * Lignes du bloc, dans l'ordre d'affichage ; seules celles qui ont quelque chose à faire.
     *
     * @return list<Ligne>
     */
    public static function lignes(PDO $pdo, int $maintenant): array
    {
        $demandes = (new Demandes($pdo))->enAttenteParNature();
        $bilan = (new SuiviCertificats($pdo))->bilan($maintenant);
        $certificats = $bilan['non_reconnus'] + $bilan['expirent'] + $bilan['abandons'];
        $horsGel = count(Releve::horsGel($pdo)) + array_sum(array_map('count', Releve::wordpressHorsGel($pdo)));
        $depuis = gmdate('Y-m-d\TH:i:s\Z', $maintenant - self::JOURS_ECHECS * 86400);
        $echecs = (new FileTravaux($pdo))->enEchecDepuis($depuis);

        $lignes = [
            ['urgent' => true, 'nombre' => $demandes['problemes'], 'adresse' => '/demandes',
                'titre' => self::pluriel($demandes['problemes'], 'problème signalé', 'problèmes signalés')
                    . ' (SAV)',
                'texte' => 'Probablement urgent : un site ou le portail ne marche pas pour quelqu’un.'],
            ['urgent' => false, 'nombre' => $demandes['autres'], 'adresse' => '/demandes',
                'titre' => self::pluriel($demandes['autres'], 'demande à valider ou refuser',
                    'demandes à valider ou refuser'),
                'texte' => 'Suppressions et réinitialisations demandées par l’équipe.'],
            ['urgent' => false, 'nombre' => $echecs, 'adresse' => '/travaux',
                'titre' => self::pluriel($echecs, 'travail avec des échecs', 'travaux avec des échecs')
                    . ' (' . self::JOURS_ECHECS . ' derniers jours)',
                'texte' => 'Ouvrez le travail : la colonne « Résultat » dit ce qui n’a pas marché.'],
            ['urgent' => false, 'nombre' => $certificats, 'adresse' => '/sites/certificats',
                'titre' => self::pluriel($certificats, 'certificat à revoir', 'certificats à revoir'),
                'texte' => 'Non reconnu, bientôt expiré ou relance abandonnée : '
                    . '« site non sécurisé » pour les visiteurs.'],
            ['urgent' => false, 'nombre' => $horsGel, 'adresse' => '/sites',
                'titre' => self::pluriel($horsGel, 'site hors du gel', 'sites hors du gel'),
                'texte' => 'Version ou gel à vérifier : en rouge dans la Liste des sites.'],
        ];

        return array_values(array_filter($lignes, static fn (array $l): bool => $l['nombre'] > 0));
    }

    /**
     * HTML du bloc « À faire » puis des travaux en cours ; chaîne vide quand il n'y a ni l'un ni l'autre.
     *
     * @param list<Ligne> $lignes
     * @param list<array{id: int, type: string, etat: string, demande_le: string, demande_par_nom: string, total: int,
     *                   faits: int, echecs: int}> $travaux
     */
    public static function html(array $lignes, array $travaux): string
    {
        $html = '';
        if ($lignes !== []) {
            $liens = '';
            foreach ($lignes as $ligne) {
                $liens .= '<a class="a-faire-ligne' . ($ligne['urgent'] ? ' urgent' : '') . '" href="'
                    . Html::e($ligne['adresse']) . '">'
                    . ($ligne['urgent'] ? '<span class="badge err">Urgent</span>'
                        : '<span class="badge warn">À faire</span>')
                    . '<span class="a-faire-texte"><strong>' . Html::e($ligne['titre']) . '</strong>'
                    . '<span>' . Html::e($ligne['texte']) . '</span></span></a>';
            }
            $html .= '<section class="a-faire" aria-labelledby="t-a-faire"><h2 id="t-a-faire">À faire</h2>'
                . $liens . '</section>';
        }
        if ($travaux !== []) {
            $suivi = '';
            foreach ($travaux as $travail) {
                $libelle = FileTravaux::LIBELLES[$travail['type']] ?? $travail['type'];
                $suivi .= '<li><a href="/travaux/' . $travail['id'] . '">' . Html::e($libelle) . '</a> '
                    . PagesTravaux::avancementDe($travail) . '</li>';
            }
            $html .= '<section class="suivi-travaux" aria-labelledby="t-suivi"><h2 id="t-suivi">Travaux en cours</h2>'
                . '<ul class="avancements">' . $suivi . '</ul>'
                . '<p class="discret">Pas de mise à jour du portail tant qu’un travail tourne.</p></section>';
        }

        return $html;
    }

    /** « 1 problème signalé » ou « 3 problèmes signalés ». */
    private static function pluriel(int $nombre, string $singulier, string $pluriel): string
    {
        return $nombre . ' ' . ($nombre > 1 ? $pluriel : $singulier);
    }
}
