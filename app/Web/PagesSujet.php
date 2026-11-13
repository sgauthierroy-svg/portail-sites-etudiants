<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Sites\SiteModele;

/**
 * Page du site modèle d'examen (V2, lot 4) : état, installation d'un sujet de la bibliothèque, gel.
 */
final class PagesSujet
{
    /**
     * @param array{connu: bool, installe: bool, version: ?string, gele: bool, installe_le: ?string, travail: ?int} $etat
     * @param list<array{id: int, nom: string, version_wordpress: ?string, depose_le: string, depose_par_nom: string}> $sujets
     * @param list<array{chemin: string, identifiant: string, dossier: string, motif: string, mis_le: string, expire_le: string}> $ecarts
     */
    public static function page(string $jeton, array $etat, array $sujets, array $ecarts, ?string $erreur): string
    {
        $adresse = SiteModele::adresse();
        $contenu = Pages::alerte($erreur, null)
            . '<p>Le site modèle est le sujet d\'examen tel que les étudiants le recevront dans leur <code>/WP2</code>. '
            . 'Un enseignant y charge le sujet (paquet Duplicator déposé dans la <a href="/bibliotheque">bibliothèque</a>), l\'adapte si besoin dans WordPress, '
            . 'puis le déploie chez les étudiants. L\'administrateur reçoit à chaque chargement une liste de contrôle (bon fonctionnement, sécurité), sans rien bloquer.</p>';

        $contenu .= '<h2>État</h2>';
        if (!$etat['connu']) {
            $contenu .= '<p role="alert">Le sous-domaine <code>' . SiteModele::IDENTIFIANT . '.bts-sup-sallanches.com</code> n\'est pas dans le dernier relevé. '
                . 'Créez-le dans CPanel (avec son certificat), puis <a href="/travaux">actualisez le relevé</a>.</p>';
        } elseif (!$etat['installe']) {
            $contenu .= '<p>Aucun sujet installé par le portail pour l\'instant.</p>';
        } else {
            $contenu .= '<table><tbody>'
                . '<tr><th>Site modèle</th><td><a href="' . Html::e($adresse) . '" target="_blank" rel="noopener">' . Html::e($adresse) . '</a></td></tr>'
                . '<tr><th>Administration</th><td><a href="' . Html::e($adresse . 'wp-admin/') . '" target="_blank" rel="noopener">' . Html::e($adresse . 'wp-admin/') . '</a></td></tr>'
                . '<tr><th>WordPress</th><td>' . Html::e($etat['version'] ?? '?') . ' — '
                . ($etat['gele'] ? '<span class="badge neutre">version gelée</span>' : '<span class="badge ok">mises à jour automatiques actives</span>') . '</td></tr>'
                . '<tr><th>Installé le</th><td>' . Html::e($etat['installe_le'] === null ? '?' : Pages::heureLocale($etat['installe_le'])) . '</td></tr>'
                . '</tbody></table>';
        }
        if ($etat['travail'] !== null) {
            $contenu .= '<p role="status">Un travail sur le site modèle est prévu ou en cours : <a href="/travaux/' . $etat['travail'] . '">travail n° ' . $etat['travail'] . '</a>.</p>';
        }

        if ($etat['connu'] && $etat['travail'] === null) {
            $contenu .= '<h2>Charger un sujet</h2>';
            if ($sujets === []) {
                $contenu .= '<p>Aucun sujet WordPress dans la bibliothèque : déposez d\'abord le paquet Duplicator (rayon « Sujets WordPress »).</p>';
            } else {
                $choix = '';
                foreach ($sujets as $sujet) {
                    $choix .= '<option value="' . $sujet['id'] . '">' . Html::e($sujet['nom'] . ' — WordPress ' . ($sujet['version_wordpress'] ?? '?')
                        . ' — déposé le ' . Pages::heureLocale($sujet['depose_le']) . ' par ' . $sujet['depose_par_nom']) . '</option>';
                }
                $contenu .= '<form method="post" action="/sites/sujet">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="installer">'
                    . '<p><label for="paquet">Sujet de la bibliothèque</label><br><select id="paquet" name="paquet">' . $choix . '</select></p>'
                    . ($etat['installe']
                        ? '<p><label><input type="checkbox" name="confirmer" value="1"> Le site modèle actuel sera remplacé. Il est gardé 8 jours à l\'écart, puis effacé.</label></p>'
                        : '')
                    . '<p class="discret">Après l\'installation : connectez-vous à l\'administration du modèle avec les accès du site d\'origine, '
                    . 'supprimez le compte et le contenu personnel de l\'étudiant d\'origine s\'il y en a, et vérifiez les extensions.</p>'
                    . '<button type="submit" class="btn-main">Installer ce sujet sur le site modèle</button></form>';
            }
            if ($etat['installe']) {
                $contenu .= '<h2>Version</h2><form method="post" action="/sites/sujet">' . Pages::jeton($jeton)
                    . ($etat['gele']
                        ? '<input type="hidden" name="action" value="degeler"><p>La version est gelée : WordPress ne se met plus à jour tout seul.</p>'
                            . '<button type="submit">Dégeler la version</button>'
                        : '<input type="hidden" name="action" value="geler"><p>Tant que l\'année d\'examen n\'est pas figée, WordPress se met à jour tout seul. '
                            . 'Gelez la version quand le sujet est arrêté pour l\'examen.</p><button type="submit">Geler la version</button>')
                    . '</form>';
            }
        }

        if ($ecarts !== []) {
            $lignes = '';
            foreach ($ecarts as $ecart) {
                $lignes .= '<tr><td>' . Html::e(Pages::heureLocale($ecart['mis_le'])) . '</td><td>' . Html::e($ecart['motif']) . '</td><td>'
                    . Html::e(Pages::heureLocale($ecart['expire_le'])) . '</td><td><code>' . Html::e($ecart['chemin']) . '</code></td></tr>';
            }
            $contenu .= '<h2>Anciens modèles gardés à l\'écart</h2>'
                . '<p class="discret">Dans <code>~/archives_suppression/portail/</code>, avec leur base. Pour revenir en arrière, demandez à l\'administrateur.</p>'
                . '<table><thead><tr><th>Mis à l\'écart le</th><th>Motif</th><th>Effacé après le</th><th>Dossier</th></tr></thead><tbody>' . $lignes . '</tbody></table>';
        }

        return Pages::page('Site modèle d\'examen', $contenu . '<p><a href="/sites">Voir tout</a> · <a href="/">Accueil</a></p>');
    }
}
