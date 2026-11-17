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
     * @param array{sessions: list<array{id: int, annee: int}>, session: ?int,
     *              etudiants: ?list<array{identifiant: string, nom: string, regime: string, empechement: ?string, remplace: bool,
     *                                      deploye_le: ?string, cloture_le: ?string, lecture_seule: bool, epreuve_prevue: bool}>} $deploiement
     */
    public static function page(string $jeton, array $etat, array $sujets, array $ecarts, array $deploiement, ?string $erreur): string
    {
        $adresse = SiteModele::adresse();
        $contenu = Pages::alerte($erreur, null)
            . '<p>Le site modèle est le sujet d\'examen tel que les étudiants le recevront dans leur <code>/WP2</code>. '
            . 'Un enseignant y charge le sujet (paquet Duplicator déposé dans la <a href="/bibliotheque">bibliothèque</a>), l\'adapte si besoin dans WordPress, '
            . 'puis le déploie chez les étudiants. L\'administrateur reçoit à chaque chargement une liste de contrôle (bon fonctionnement, sécurité), sans rien bloquer.</p>';

        $contenu .= '<h2>État</h2>';
        if (!$etat['connu']) {
            $contenu .= '<p role="alert">Le sous-domaine <code>' . SiteModele::IDENTIFIANT . '.bts-sup-sallanches.com</code> n\'est pas dans le dernier relevé. '
                . 'Créez-le dans CPanel (avec son certificat), puis <a href="/travaux">mettez à jour la liste des sites</a>.</p>';
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

        if ($etat['connu'] && $etat['installe'] && $etat['travail'] === null) {
            $contenu .= self::deploiement($jeton, $etat, $deploiement);
        }
        if ($etat['connu'] && $etat['installe']) {
            // Pendant un travail sur le modèle, le choix de la session du déploiement est masqué : l'épreuve a le sien.
            $contenu .= self::epreuve($jeton, $deploiement, $etat['travail'] !== null);
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

        return Pages::page('Site modèle d\'examen', $contenu . '<p><a href="/sites">Liste des sites</a> · <a href="/">Accueil</a></p>');
    }

    /**
     * Déploiement du modèle dans les /WP2 (paquet 2) : choix de la session, puis des étudiants.
     *
     * @param array{connu: bool, installe: bool, version: ?string, gele: bool, installe_le: ?string, travail: ?int} $etat
     * @param array{sessions: list<array{id: int, annee: int}>, session: ?int,
     *              etudiants: ?list<array{identifiant: string, nom: string, regime: string, empechement: ?string, remplace: bool,
     *                                      deploye_le: ?string, cloture_le: ?string, lecture_seule: bool, epreuve_prevue: bool}>} $deploiement
     */
    private static function deploiement(string $jeton, array $etat, array $deploiement): string
    {
        $options = '';
        $annee = null;
        foreach ($deploiement['sessions'] as $session) {
            $choisie = $session['id'] === $deploiement['session'];
            $options .= '<option value="' . $session['id'] . '"' . ($choisie ? ' selected' : '') . '>Session ' . $session['annee'] . '</option>';
            $annee = $choisie ? $session['annee'] : $annee;
        }
        $contenu = '<h2>Déployer chez les étudiants</h2>'
            . '<p>Le sujet du site modèle est copié dans le <code>/WP2</code> de chaque étudiant coché : une photo du modèle est prise au début, '
            . 'tous reçoivent donc exactement le même sujet. Chaque <code>/WP2</code> a sa propre base de données, sa version est gelée, '
            . 'et il garde les comptes et mots de passe du modèle. Un <code>/WP2</code> existant est gardé 8 jours à l\'écart. '
            . 'Aucun e-mail aux étudiants ; l\'administrateur et les enseignants reçoivent la liste des sites à la fin.</p>'
            . ($etat['gele'] ? '' : '<p class="discret">La version du modèle n\'est pas gelée : les <code>/WP2</code> déployés le seront quand même.</p>')
            . '<p class="discret">Conseil : essayez d\'abord avec un seul étudiant.</p>'
            . '<form method="post" action="/sites/sujet">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="afficher_deploiement">'
            . '<label for="session">Session</label> <select id="session" name="session">' . $options . '</select> '
            . '<button type="submit">Afficher les étudiants</button></form>';
        if ($annee === null || $deploiement['etudiants'] === null) {
            return $contenu;
        }
        $lignes = '';
        $possibles = 0;
        $remplaces = 0;
        foreach ($deploiement['etudiants'] as $etudiant) {
            $possible = $etudiant['empechement'] === null;
            $possibles += $possible ? 1 : 0;
            $remplaces += $possible && $etudiant['remplace'] ? 1 : 0;
            $case = $possible
                ? '<input type="checkbox" name="coches[]" value="' . Html::e($etudiant['identifiant']) . '" aria-label="' . Html::e($etudiant['nom']) . '">'
                : '';
            $etatLigne = !$possible
                ? '<span class="badge neutre">' . Html::e((string) $etudiant['empechement']) . '</span>'
                : ($etudiant['remplace'] ? '<span class="badge warn">remplace le /WP2 actuel (gardé 8 jours)</span>' : '<span class="badge ok">possible</span>');
            $lignes .= '<tr><td>' . $case . '</td><td>' . Html::e($etudiant['nom']) . '</td><td>' . Html::e($etudiant['regime']) . '</td><td>' . $etatLigne . '</td></tr>';
        }

        return $contenu . '<h3>Session ' . $annee . '</h3>'
            . '<form method="post" action="/sites/sujet">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="deployer"><input type="hidden" name="session" value="' . (int) $deploiement['session'] . '">'
            . '<table class="triable"><thead><tr><th>' . self::toutCocher() . '</th><th>Étudiant</th><th>Régime</th><th>État</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . ($remplaces > 0
                ? '<p><label><input type="checkbox" name="confirmer" value="1"> Les <code>/WP2</code> existants des étudiants cochés seront remplacés. Ils sont gardés 8 jours à l\'écart, puis effacés.</label></p>'
                : '')
            . ($possibles > 0 ? '<button type="submit" class="btn-main">Déployer le sujet chez les étudiants cochés</button>' : '<p>Aucun étudiant de cette session ne peut recevoir le sujet.</p>')
            . '</form>';
    }

    /** Case « Tout cocher » d'un tableau (tri.js) : coche les cases actives de la colonne, jamais les autres. */
    private static function toutCocher(): string
    {
        return '<input type="checkbox" data-tout-cocher aria-label="Tout cocher" title="Tout cocher">';
    }

    /**
     * Épreuve (paquet 3) : clôture (sessions fermées, copie datée 8 jours, lecture seule), gel levé ou refigé.
     *
     * @param array{sessions: list<array{id: int, annee: int}>, session: ?int,
     *              etudiants: ?list<array{identifiant: string, nom: string, regime: string, empechement: ?string, remplace: bool,
     *                                      deploye_le: ?string, cloture_le: ?string, lecture_seule: bool, epreuve_prevue: bool}>} $deploiement
     */
    private static function epreuve(string $jeton, array $deploiement, bool $selecteur): string
    {
        $annee = null;
        $options = '';
        foreach ($deploiement['sessions'] as $session) {
            $choisie = $session['id'] === $deploiement['session'];
            $annee = $choisie ? $session['annee'] : $annee;
            $options .= '<option value="' . $session['id'] . '"' . ($choisie ? ' selected' : '') . '>Session ' . $session['annee'] . '</option>';
        }
        $contenu = '<h2>Épreuve : clôture et gel</h2>'
            . '<p>La préconnexion des back-offices se fait à la main avant l\'épreuve (cochez « Se souvenir de moi » si elle a lieu plus de 2 jours avant). '
            . 'À la fin de l\'épreuve, <strong>Clôturer</strong> : pour chaque étudiant coché, une copie datée de son <code>/WP2</code> est gardée 8 jours, '
            . 'les sessions ouvertes sont fermées (l\'étudiant est déconnecté), puis le site passe en <strong>lecture seule</strong> : '
            . 'les correcteurs se connectent avec le compte administrateur du modèle, voient tout, et ne peuvent rien enregistrer. '
            . 'Déconnexion après 1 h d\'inactivité. Un enseignant peut lever le gel d\'un site, puis le refiger.</p>';
        if ($selecteur) {
            $contenu .= '<form method="post" action="/sites/sujet">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="afficher_deploiement">'
                . '<label for="session-epreuve">Session</label> <select id="session-epreuve" name="session">' . $options . '</select> '
                . '<button type="submit">Afficher les étudiants</button></form>';
        }
        if ($annee === null || $deploiement['etudiants'] === null) {
            return $contenu . ($selecteur ? '' : '<p class="discret">Choisissez une session ci-dessus (« Afficher les étudiants »).</p>');
        }
        $lignes = '';
        $avecWp2 = 0;
        foreach ($deploiement['etudiants'] as $etudiant) {
            if ($etudiant['deploye_le'] === null) {
                continue;
            }
            $avecWp2++;
            $case = $etudiant['epreuve_prevue']
                ? ''
                : '<input type="checkbox" name="coches[]" value="' . Html::e($etudiant['identifiant']) . '" aria-label="' . Html::e($etudiant['nom']) . '">';
            $etat = match (true) {
                $etudiant['epreuve_prevue'] => '<span class="badge neutre">clôture ou gel prévu</span>',
                $etudiant['lecture_seule'] => '<span class="badge neutre">clos le ' . Html::e(Pages::heureLocale((string) $etudiant['cloture_le'])) . ' — figé</span>',
                $etudiant['cloture_le'] !== null => '<span class="badge warn">clos le ' . Html::e(Pages::heureLocale($etudiant['cloture_le'])) . ' — gel levé</span>',
                default => '<span class="badge ok">déployé le ' . Html::e(Pages::heureLocale($etudiant['deploye_le'] ?? '')) . ' — épreuve ouverte</span>',
            };
            $adresse = 'https://' . $etudiant['identifiant'] . '.bts-sup-sallanches.com/WP2/wp-admin/';
            $lignes .= '<tr><td>' . $case . '</td><td>' . Html::e($etudiant['nom']) . '</td><td>' . $etat . '</td>'
                . '<td><a href="' . Html::e($adresse) . '" target="_blank" rel="noopener noreferrer">administration</a></td></tr>';
        }
        if ($avecWp2 === 0) {
            return $contenu . '<p>Aucun <code>/WP2</code> déployé par le portail dans la session ' . $annee . '.</p>';
        }

        return $contenu . '<h3>Session ' . $annee . '</h3>'
            . '<form method="post" action="/sites/sujet">' . Pages::jeton($jeton)
            . '<input type="hidden" name="session" value="' . (int) $deploiement['session'] . '">'
            . '<table class="triable"><thead><tr><th>' . self::toutCocher() . '</th><th>Étudiant</th><th>Épreuve</th><th>Site</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . '<p><label><input type="checkbox" name="confirmer_cloture" value="1"> Je confirme la fin de l\'épreuve pour les étudiants cochés : '
            . 'ils seront déconnectés et leur <code>/WP2</code> figé.</label></p>'
            . '<p><button type="submit" name="action" value="cloturer" class="btn-main">Clôturer l\'épreuve pour les étudiants cochés</button></p>'
            . '<p><button type="submit" name="action" value="lever_gel">Lever le gel des étudiants cochés</button> '
            . '<button type="submit" name="action" value="refiger">Refiger les étudiants cochés</button></p>'
            . '</form>';
    }
}
