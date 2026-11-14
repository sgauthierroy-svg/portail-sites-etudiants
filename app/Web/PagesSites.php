<?php

declare(strict_types=1);

namespace Portail\Web;

/**
 * Pages de la V2, lot 3 : création des boutiques PrestaShop d'entraînement.
 */
final class PagesSites
{
    /**
     * @param list<array{id: int, annee: int, paquet_id: ?int, version: ?string}> $sessions
     * @param list<array{id: int, version: string}> $paquets
     * @param list<array{identifiant: string, nom: string, email: string, empechement: ?string}>|null $etudiants
     */
    public static function creerPrestaShop(string $jeton, array $sessions, array $paquets, ?int $sessionChoisie, ?array $etudiants,
        ?string $dernierReleve, ?string $erreur): string
    {
        $options = '';
        $session = null;
        foreach ($sessions as $candidate) {
            $options .= '<option value="' . $candidate['id'] . '"' . ($candidate['id'] === $sessionChoisie ? ' selected' : '') . '>Session ' . $candidate['annee']
                . ($candidate['version'] === null ? '' : ' (PrestaShop ' . Html::e($candidate['version']) . ')') . '</option>';
            if ($candidate['id'] === $sessionChoisie) {
                $session = $candidate;
            }
        }
        $contenu = Pages::alerte($erreur, null)
            . '<p>Boutique par défaut de PrestaShop, en https, dans le dossier <code>/Presta</code> de chaque étudiant coché. '
            . 'Chaque étudiant reçoit ses accès par e-mail ; l\'administrateur reçoit à la fin un récapitulatif à coller dans la feuille des accès. '
            . 'Le travail se fait en arrière-plan, un étudiant après l\'autre (une à deux minutes chacun).</p>'
            . '<p>Dernier relevé du serveur : ' . ($dernierReleve === null ? 'aucun' : Html::e(Pages::heureLocale($dernierReleve)))
            . '. Un sous-domaine créé depuis n\'est pas connu : <a href="/travaux">actualisez le relevé</a> d\'abord.</p>'
            . '<form method="post" action="/sites/prestashop">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="afficher">'
            . '<label for="session">Session</label> <select id="session" name="session">' . $options . '</select> '
            . '<button type="submit">Afficher les étudiants</button></form>';

        if ($session !== null && $etudiants !== null) {
            $lignes = '';
            $possibles = 0;
            foreach ($etudiants as $etudiant) {
                $case = $etudiant['empechement'] === null
                    ? '<input type="checkbox" name="coches[]" value="' . Html::e($etudiant['identifiant']) . '" checked aria-label="' . Html::e($etudiant['nom']) . '">'
                    : '';
                $possibles += $etudiant['empechement'] === null ? 1 : 0;
                $lignes .= '<tr><td>' . $case . '</td><td>' . Html::e($etudiant['nom']) . '</td><td>' . Html::e($etudiant['email']) . '</td><td>'
                    . ($etudiant['empechement'] === null ? '<span class="badge ok">possible</span>' : '<span class="badge neutre">' . Html::e($etudiant['empechement']) . '</span>')
                    . '</td></tr>';
            }
            if ($session['paquet_id'] === null) {
                $choix = '';
                foreach ($paquets as $paquet) {
                    $choix .= '<option value="' . $paquet['id'] . '">PrestaShop ' . Html::e($paquet['version']) . '</option>';
                }
                $version = $paquets === []
                    ? '<p role="alert">Aucun paquet PrestaShop dans la bibliothèque : déposez-en un d\'abord.</p>'
                    : '<p><label for="paquet">Version PrestaShop de la session ' . $session['annee'] . '</label><br><select id="paquet" name="paquet">' . $choix . '</select><br>'
                        . '<span class="discret">Toutes les boutiques de la session auront cette version, gelée jusqu\'à l\'examen.</span></p>';
            } else {
                $version = '<p>Version de la session : <strong>PrestaShop ' . Html::e((string) $session['version']) . '</strong> (gelée).</p>';
            }
            $contenu .= '<h2>Session ' . $session['annee'] . '</h2>'
                . '<form method="post" action="/sites/prestashop">' . Pages::jeton($jeton)
                . '<input type="hidden" name="action" value="lancer"><input type="hidden" name="session" value="' . $session['id'] . '">'
                . $version
                . '<p>Nom de la boutique : <strong>' . Html::e(\Portail\Sites\PreparationPrestaShop::NOM_BOUTIQUE) . '</strong> (chaque étudiant pourra le changer).</p>'
                . '<table class="triable"><thead><tr><th>Créer</th><th>Étudiant</th><th>E-mail (identifiant de la boutique)</th><th>État</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
                . ($possibles > 0 ? '<button type="submit" class="btn-main">Créer les boutiques cochées</button>' : '<p>Aucun étudiant de cette session ne peut recevoir de boutique.</p>')
                . '</form>';
        }

        return Pages::page('Créer des boutiques PrestaShop', $contenu . '<p><a href="/sites">Voir tout</a> · <a href="/">Accueil</a></p>');
    }

    /**
     * Création des WordPress /WP (lot 5) : choix de la session, puis des étudiants (aucun coché d'avance).
     *
     * @param list<array{id: int, annee: int, version: ?string}> $sessions
     * @param list<array{identifiant: string, nom: string, identifiant_wp: string, email: string, empechement: ?string, sous_domaine: bool}>|null $etudiants
     */
    public static function creerWordPress(string $jeton, array $sessions, ?int $sessionChoisie, ?array $etudiants, ?string $dernierReleve, ?string $erreur): string
    {
        $options = '';
        $session = null;
        foreach ($sessions as $candidate) {
            $options .= '<option value="' . $candidate['id'] . '"' . ($candidate['id'] === $sessionChoisie ? ' selected' : '') . '>Session ' . $candidate['annee'] . '</option>';
            $session = $candidate['id'] === $sessionChoisie ? $candidate : $session;
        }
        $contenu = Pages::alerte($erreur, null)
            . '<p>WordPress en français dans le dossier <code>/WP</code> de chaque étudiant coché, comme les sites des 1re année : titre « Sup », '
            . 'thème et extensions par défaut, en https. Si l\'étudiant n\'a pas encore de sous-domaine, il est créé avec son certificat. '
            . 'Chaque étudiant reçoit ses accès par e-mail (son identifiant WordPress et un mot de passe tiré au hasard) ; l\'administrateur reçoit à la fin '
            . 'un récapitulatif à coller dans la feuille des accès. Le travail se fait en arrière-plan, un étudiant après l\'autre.</p>'
            . '<p>Dernier relevé du serveur : ' . ($dernierReleve === null ? 'aucun' : Html::e(Pages::heureLocale($dernierReleve)))
            . '. Un site créé hors du portail depuis n\'est pas connu : <a href="/travaux">actualisez le relevé</a> d\'abord.</p>'
            . '<form method="post" action="/sites/wordpress">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="afficher">'
            . '<label for="session">Session</label> <select id="session" name="session">' . $options . '</select> '
            . '<button type="submit">Afficher les étudiants</button></form>';
        if ($session === null || $etudiants === null) {
            return Pages::page('Créer des WordPress', $contenu . '<p><a href="/sites">Voir tout</a> · <a href="/">Accueil</a></p>');
        }
        $lignes = '';
        $possibles = 0;
        foreach ($etudiants as $etudiant) {
            $possible = $etudiant['empechement'] === null;
            $possibles += $possible ? 1 : 0;
            $case = $possible ? '<input type="checkbox" name="coches[]" value="' . Html::e($etudiant['identifiant']) . '" aria-label="' . Html::e($etudiant['nom']) . '">' : '';
            $etat = !$possible
                ? '<span class="badge neutre">' . Html::e((string) $etudiant['empechement']) . '</span>'
                : ($etudiant['sous_domaine'] ? '<span class="badge ok">possible</span>' : '<span class="badge warn">possible — sous-domaine à créer</span>');
            $lignes .= '<tr><td>' . $case . '</td><td>' . Html::e($etudiant['nom']) . '</td><td><code>' . Html::e($etudiant['identifiant']) . '</code></td><td>'
                . Html::e($etudiant['identifiant_wp']) . '</td><td>' . Html::e($etudiant['email']) . '</td><td>' . $etat . '</td></tr>';
        }
        $version = $session['version'] === null
            ? '<p>Version de la session : <strong>pas encore fixée</strong> — la dernière version de WordPress est installée, et elle se met à jour toute seule.</p>'
            : '<p>Version de la session : <strong>WordPress ' . Html::e($session['version']) . '</strong>, gelée.</p>';

        return Pages::page('Créer des WordPress', $contenu . '<h2>Session ' . $session['annee'] . '</h2>' . $version
            . '<form method="post" action="/sites/wordpress">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="lancer"><input type="hidden" name="session" value="' . $session['id'] . '">'
            . '<table class="triable"><thead><tr><th><input type="checkbox" data-tout-cocher aria-label="Tout cocher" title="Tout cocher"></th>'
            . '<th>Étudiant</th><th>Identifiant</th><th>Identifiant WordPress</th><th>E-mail</th><th>État</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . ($possibles > 0 ? '<button type="submit" class="btn-main">Créer les WordPress cochés</button>' : '<p>Aucun étudiant de cette session ne peut recevoir de WordPress.</p>')
            . '</form><p><a href="/sites">Voir tout</a> · <a href="/">Accueil</a></p>');
    }

    /**
     * Certificats https (administrateur, demande du 14/11/2026) : état vu par le travailleur, vérification, relance.
     *
     * @param list<array{identifiant: string, protege: bool, verifie_le: ?string, reconnu: ?bool, expire_le: ?string, detail: ?string,
     *                    probleme: bool, relance: ?array{essais: int, prochain_essai: string, derniere_erreur: ?string, abandonne_le: ?string}}> $liste
     * @param array{non_reconnus: int, expirent: int, abandons: int, relances: int, jamais: int, derniere: ?string, demande: ?string} $bilan
     */
    public static function certificats(string $jeton, array $liste, array $bilan, ?string $erreur, ?string $information): string
    {
        $lignes = '';
        foreach ($liste as $ligne) {
            $etat = match (true) {
                $ligne['reconnu'] === null => '<span class="badge neutre">pas encore vérifié</span>',
                $ligne['reconnu'] === false => '<span class="badge warn">non reconnu</span>',
                $ligne['probleme'] => '<span class="badge warn">expire bientôt</span>',
                default => '<span class="badge ok">reconnu</span>',
            };
            $relance = '';
            if ($ligne['relance'] !== null) {
                $relance = $ligne['relance']['abandonne_le'] !== null
                    ? '<span class="badge warn">abandonnée après ' . $ligne['relance']['essais'] . ' essais</span>'
                    : 'en cours (' . $ligne['relance']['essais'] . ' essai(s), prochain le ' . Html::e(Pages::heureLocale($ligne['relance']['prochain_essai'])) . ')';
                $relance .= $ligne['relance']['derniere_erreur'] === null ? '' : '<br><span class="discret">' . Html::e($ligne['relance']['derniere_erreur']) . '</span>';
            }
            $case = $ligne['protege']
                ? '<span class="discret" title="Site protégé : certificat à poser avec certificats.sh">protégé</span>'
                : '<input type="checkbox" name="coches[]" value="' . Html::e($ligne['identifiant']) . '" aria-label="' . Html::e($ligne['identifiant']) . '">';
            $lignes .= '<tr><td>' . $case . '</td><td><code>' . Html::e($ligne['identifiant']) . '.bts-sup-sallanches.com</code></td><td>' . $etat
                . ($ligne['detail'] === null || $ligne['reconnu'] === true ? '' : '<br><span class="discret">' . Html::e($ligne['detail']) . '</span>') . '</td><td>'
                . ($ligne['expire_le'] === null ? '—' : Html::e(Pages::heureLocale($ligne['expire_le']))) . '</td><td>'
                . ($ligne['verifie_le'] === null ? '—' : Html::e(Pages::heureLocale($ligne['verifie_le']))) . '</td><td>' . $relance . '</td></tr>';
        }
        $resume = '<ul><li>Non reconnus : <strong>' . $bilan['non_reconnus'] . '</strong></li>'
            . '<li>Expirent dans moins de ' . \Portail\Sites\SuiviCertificats::SEUIL_JOURS . ' jours : <strong>' . $bilan['expirent'] . '</strong></li>'
            . '<li>Relances en cours : <strong>' . $bilan['relances'] . '</strong> ; abandonnées : <strong>' . $bilan['abandons'] . '</strong></li>'
            . '<li>Pas encore vérifiés : <strong>' . $bilan['jamais'] . '</strong></li></ul>';
        $contenu = Pages::alerte($erreur, $information)
            . '<p>Le travailleur vérifie chaque jour le certificat https de chaque sous-domaine du relevé, comme un navigateur '
            . '(dix sites par minute, quand il n\'a pas d\'autre travail). acme.sh renouvelle les certificats de lui-même ; '
            . 'un certificat qui expire dans moins de ' . \Portail\Sites\SuiviCertificats::SEUIL_JOURS . ' jours signale un renouvellement en panne. '
            . 'L\'e-mail du lundi signale les problèmes, sans nom d\'étudiant.</p>'
            . '<p>Dernière vérification complète : <strong>' . ($bilan['derniere'] === null ? 'jamais' : Html::e(Pages::heureLocale($bilan['derniere']))) . '</strong>'
            . ($bilan['demande'] === null ? '' : ' — vérification en cours ou demandée le ' . Html::e(Pages::heureLocale($bilan['demande'])) . ' (rechargez la page dans quelques minutes)')
            . '.</p>' . $resume
            . '<form method="post" action="/sites/certificats">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="verifier">'
            . '<button type="submit">Vérifier tous les certificats maintenant</button></form>';
        if ($liste === []) {
            return Pages::page('Certificats', $contenu . '<p>Aucun sous-domaine dans le dernier relevé.</p>');
        }

        return Pages::page('Certificats', $contenu
            . '<h2>Sous-domaines</h2><p class="discret">Les problèmes d\'abord. Relancer : un essai dès que le travailleur est libre, puis toutes les 20 minutes, '
            . '6 au plus (limite de Let\'s Encrypt : 5 échecs par sous-domaine et par heure). Un certificat posé est revu à la vérification suivante '
            . '(CPanel l\'active en 30 minutes au plus).</p>'
            . '<form method="post" action="/sites/certificats">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="relancer">'
            . '<table class="triable"><thead><tr><th><input type="checkbox" data-tout-cocher aria-label="Tout cocher" title="Tout cocher"></th>'
            . '<th>Sous-domaine</th><th>État</th><th>Expire le</th><th>Vérifié le</th><th>Relance</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . '<button type="submit" class="btn-main">Relancer le certificat des sous-domaines cochés</button></form>'
            . '<p><a href="/sites">Voir tout</a> · <a href="/">Accueil</a></p>');
    }
}
