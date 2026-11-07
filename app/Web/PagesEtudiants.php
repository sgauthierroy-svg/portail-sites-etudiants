<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Etudiants\Etudiant;
use Portail\Etudiants\RegimesSessions;

/**
 * Pages des étudiants, des régimes et des sessions. Tout texte variable passe par Html::e().
 */
final class PagesEtudiants
{
    /**
     * @param list<Etudiant> $etudiants
     * @param list<array{id: int, nom: string, actif: bool}> $regimes
     * @param list<array{id: int, annee: int, version_wp: ?string, active: bool}> $sessions
     */
    public static function liste(string $jeton, Compte $moi, array $etudiants, array $regimes, array $sessions,
        ?int $regimeChoisi, ?int $sessionChoisie, int $maintenant): string
    {
        $optionsRegimes = '<option value="">Tous les régimes</option>';
        foreach ($regimes as $regime) {
            $optionsRegimes .= self::option((string) $regime['id'], $regime['nom'] . ($regime['actif'] ? '' : ' (désactivé)'), $regime['id'] === $regimeChoisi);
        }
        $optionsSessions = '<option value="">Toutes les sessions</option>';
        foreach ($sessions as $session) {
            $optionsSessions .= self::option((string) $session['id'], 'Session ' . $session['annee'] . ($session['active'] ? '' : ' (archivée)'), $session['id'] === $sessionChoisie);
        }
        $lignes = '';
        $fiches = Droits::peut($moi->role, Droits::VOIR_FICHE);
        foreach ($etudiants as $etudiant) {
            $lignes .= '<tr><td>' . ($fiches ? '<a href="/etudiants/' . $etudiant->id . '">' . Html::e($etudiant->nomComplet) . '</a>' : Html::e($etudiant->nomComplet)) . '</td>'
                . '<td>' . Html::e($etudiant->regime) . '</td><td>' . $etudiant->session . '</td>'
                . '<td>' . Html::e(RegimesSessions::anneeDeFormation($etudiant->session, $maintenant)) . '</td></tr>';
        }
        $actions = [];
        if (Droits::peut($moi->role, Droits::CREER_ETUDIANT)) {
            $actions[] = '<a href="/etudiants/nouveau">Ajouter un étudiant</a>';
        }
        if (Droits::peut($moi->role, Droits::REGIMES_SESSIONS)) {
            $actions[] = '<a href="/regimes-sessions">Régimes et sessions</a>';
        }
        $actions[] = '<a href="/">Accueil</a>';

        return Pages::page('Étudiants',
            '<p>' . implode(' · ', $actions) . '</p>'
            . '<form method="post" action="/etudiants">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="filtrer">'
            . '<label for="regime">Régime</label> <select id="regime" name="regime">' . $optionsRegimes . '</select> '
            . '<label for="session">Session</label> <select id="session" name="session">' . $optionsSessions . '</select> '
            . '<button type="submit">Afficher</button></form>'
            . '<p>' . count($etudiants) . ' étudiant' . (count($etudiants) > 1 ? 's' : '') . '. Ce filtre est gardé pour votre prochaine connexion.</p>'
            . '<table><thead><tr><th>Étudiant</th><th>Régime</th><th>Session</th><th>Année</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . Pages::boutonDeconnexion($jeton));
    }

    /**
     * @param list<array{id: int, nom: string, actif: bool}> $regimes
     * @param list<array{id: int, annee: int, version_wp: ?string, active: bool}> $sessions
     * @param array<string, string> $valeurs
     */
    public static function nouveau(string $jeton, array $regimes, array $sessions, array $valeurs, ?string $erreur): string
    {
        if ($regimes === [] || $sessions === []) {
            return Pages::page('Ajouter un étudiant', '<p>Créez d\'abord au moins un régime et une session actifs '
                . '(<a href="/regimes-sessions">Régimes et sessions</a>).</p><p><a href="/etudiants">Retour aux étudiants</a></p>');
        }

        return Pages::page('Ajouter un étudiant', Pages::alerte($erreur, null)
            . '<p>Le nom et le prénom sont saisis séparément ; le portail propose ensuite l\'identifiant, que vous vérifiez avant d\'enregistrer.</p>'
            . '<form method="post" action="/etudiants/nouveau">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="apercu">'
            . self::champsEtudiant($regimes, $sessions, $valeurs)
            . '<button type="submit">Calculer l\'identifiant</button></form><p><a href="/etudiants">Retour aux étudiants</a></p>');
    }

    /**
     * Étape de vérification : l'identifiant proposé peut être corrigé avant l'enregistrement.
     *
     * @param array<string, string> $valeurs
     */
    public static function apercu(string $jeton, array $valeurs, string $nomComplet, string $classe, string $identifiantWp, ?string $erreur): string
    {
        $caches = '';
        foreach (['nom', 'prenom', 'email', 'regime', 'session'] as $champ) {
            $caches .= '<input type="hidden" name="' . $champ . '" value="' . Html::e($valeurs[$champ] ?? '') . '">';
        }

        return Pages::page('Vérifier l\'identifiant', Pages::alerte($erreur, null)
            . '<table><tbody>'
            . '<tr><th>Nom complet</th><td>' . Html::e($nomComplet) . '</td></tr>'
            . '<tr><th>Classe</th><td>' . Html::e($classe) . '</td></tr>'
            . '<tr><th>E-mail</th><td>' . Html::e($valeurs['email'] ?? '') . '</td></tr>'
            . '<tr><th>Identifiant WordPress</th><td>' . Html::e($identifiantWp) . '</td></tr>'
            . '</tbody></table>'
            . '<p>L\'identifiant devient le nom du sous-domaine et <strong>ne pourra plus être modifié</strong> une fois les sites créés. '
            . 'Vérifiez qu\'il correspond bien au nom (même règle que la Google Sheets).</p>'
            . '<form method="post" action="/etudiants/nouveau">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="enregistrer">' . $caches
            . '<p><label for="identifiant">Identifiant</label><br><input id="identifiant" name="identifiant" type="text" required'
            . ' maxlength="14" pattern="[a-z]{2,13}[2-9]?" value="' . Html::e($valeurs['identifiant'] ?? '') . '"></p>'
            . '<button type="submit">Enregistrer l\'étudiant</button></form>'
            . '<form method="post" action="/etudiants/nouveau">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="corriger">' . $caches
            . '<button type="submit">Corriger la saisie</button></form>');
    }

    /**
     * @param list<array{id: int, nom: string, actif: bool}> $regimes
     * @param list<array{id: int, annee: int, version_wp: ?string, active: bool}> $sessions
     * @param list<array{date: string, champ: string, ancienne: ?string, nouvelle: ?string, auteur: ?string}> $historique
     */
    public static function fiche(string $jeton, Compte $moi, Etudiant $etudiant, array $regimes, array $sessions, array $historique,
        int $maintenant, ?string $erreur, ?string $information): string
    {
        $action = '/etudiants/' . $etudiant->id;
        $contenu = Pages::alerte($erreur, $information)
            . '<table><tbody>'
            . '<tr><th>Nom complet</th><td>' . Html::e($etudiant->nomComplet) . '</td></tr>'
            . '<tr><th>Classe</th><td>' . Html::e($etudiant->classe()) . ' (' . Html::e(RegimesSessions::anneeDeFormation($etudiant->session, $maintenant)) . ')</td></tr>'
            . '<tr><th>Identifiant (sous-domaine)</th><td>' . Html::e($etudiant->identifiant) . '</td></tr>'
            . '<tr><th>Identifiant WordPress</th><td>' . Html::e($etudiant->identifiantWp) . '</td></tr>'
            . '<tr><th>E-mail</th><td>' . Html::e($etudiant->email) . '</td></tr>'
            . '<tr><th>Origine</th><td>' . ($etudiant->origine === 'import' ? 'import' : 'ajout manuel') . ', le ' . Html::e(self::date($etudiant->ajouteLe)) . '</td></tr>'
            . '</tbody></table>';

        if (Droits::peut($moi->role, Droits::MODIFIER_ETUDIANT)) {
            $contenu .= '<h2>Corriger le nom ou l\'e-mail</h2>'
                . '<form method="post" action="' . $action . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="coordonnees">'
                . Pages::saisie('nom_complet', 'Nom complet (tel qu\'écrit dans EcoleDirecte)', $etudiant->nomComplet)
                . Pages::saisie('email', 'E-mail de l\'étudiant', $etudiant->email, 'email')
                . '<button type="submit">Enregistrer</button></form>';

            $peutSession = Droits::peut($moi->role, Droits::CHANGER_SESSION);
            $optionsRegimes = '';
            foreach ($regimes as $regime) {
                if ($regime['actif'] || $regime['id'] === $etudiant->regimeId) {
                    $optionsRegimes .= self::option((string) $regime['id'], $regime['nom'], $regime['id'] === $etudiant->regimeId);
                }
            }
            $optionsSessions = '';
            foreach ($sessions as $session) {
                if ($session['active'] || $session['id'] === $etudiant->sessionId) {
                    $optionsSessions .= self::option((string) $session['id'], 'Session ' . $session['annee'], $session['id'] === $etudiant->sessionId);
                }
            }
            $contenu .= '<h2>Changer de régime' . ($peutSession ? ' ou de session' : '') . '</h2>'
                . '<p>Passage FI ↔ ALT : changez le régime. Redoublement : changez la session'
                . ($peutSession ? '' : ' (droit non attribué à ce compte)') . '. Les deux peuvent changer en une seule fois. '
                . 'Les sites ne sont pas modifiés ici.</p>'
                . '<form method="post" action="' . $action . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="parcours">'
                . '<p><label for="regime">Régime</label><br><select id="regime" name="regime">' . $optionsRegimes . '</select></p>'
                . ($peutSession
                    ? '<p><label for="session">Session</label><br><select id="session" name="session">' . $optionsSessions . '</select></p>'
                    : '<input type="hidden" name="session" value="' . $etudiant->sessionId . '">')
                . '<button type="submit">Enregistrer le changement</button></form>';
        }

        $lignes = '';
        $libelles = ['regime' => 'Régime', 'session' => 'Session', 'nom_complet' => 'Nom', 'email' => 'E-mail'];
        foreach ($historique as $trace) {
            $lignes .= '<tr><td>' . Html::e(self::date($trace['date'])) . '</td><td>' . Html::e($libelles[$trace['champ']] ?? $trace['champ']) . '</td>'
                . '<td>' . Html::e($trace['ancienne'] ?? '') . ' → ' . Html::e($trace['nouvelle'] ?? '') . '</td>'
                . '<td>' . Html::e($trace['auteur'] ?? '') . '</td></tr>';
        }
        $contenu .= '<h2>Parcours</h2>'
            . ($lignes === '' ? '<p>Aucun changement depuis l\'ajout.</p>'
                : '<table><thead><tr><th>Date</th><th>Changement</th><th>Avant → après</th><th>Par</th></tr></thead><tbody>' . $lignes . '</tbody></table>')
            . '<p><a href="/etudiants">Retour aux étudiants</a></p>';

        return Pages::page($etudiant->nomComplet, $contenu);
    }

    /**
     * @param list<array{id: int, nom: string, actif: bool}> $regimes
     * @param list<array{id: int, annee: int, version_wp: ?string, active: bool}> $sessions
     */
    public static function regimesSessions(string $jeton, array $regimes, array $sessions, ?string $erreur, ?string $information): string
    {
        $bouton = static fn (string $action, string $id, string $libelle, string $autres = ''): string =>
            '<form method="post" action="/regimes-sessions">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="' . Html::e($action) . '"><input type="hidden" name="id" value="' . Html::e($id) . '">'
            . $autres . '<button type="submit">' . Html::e($libelle) . '</button></form>';

        $lignesRegimes = '';
        foreach ($regimes as $regime) {
            $lignesRegimes .= '<tr><td>' . Html::e($regime['nom']) . '</td><td>' . ($regime['actif'] ? 'actif' : 'désactivé') . '</td><td>'
                . ($regime['actif'] ? $bouton('desactiver_regime', (string) $regime['id'], 'Désactiver')
                    : $bouton('reactiver_regime', (string) $regime['id'], 'Réactiver'))
                . '</td></tr>';
        }
        $lignesSessions = '';
        foreach ($sessions as $session) {
            $id = (string) $session['id'];
            $lignesSessions .= '<tr><td>' . $session['annee'] . '</td><td>' . ($session['active'] ? 'active' : 'archivée') . '</td>'
                . '<td>' . $bouton('version', $id, 'Enregistrer',
                    '<label for="version' . $id . '">Version</label> <input id="version' . $id . '" name="version" type="text" maxlength="8"'
                    . ' pattern="[0-9]{1,2}\.[0-9]{1,2}(\.[0-9]{1,2})?" value="' . Html::e($session['version_wp'] ?? '') . '"> ')
                . '</td><td>'
                . ($session['active'] ? $bouton('archiver_session', $id, 'Archiver') : $bouton('reactiver_session', $id, 'Réactiver'))
                . '</td></tr>';
        }

        return Pages::page('Régimes et sessions', Pages::alerte($erreur, $information)
            . '<p>Un régime ou une session n\'est jamais supprimé : on le désactive ou on l\'archive. '
            . 'Un étudiant ne peut être placé que dans un régime actif et une session active.</p>'
            . '<h2>Régimes</h2>'
            . '<table><thead><tr><th>Nom</th><th>État</th><th></th></tr></thead><tbody>' . $lignesRegimes . '</tbody></table>'
            . '<form method="post" action="/regimes-sessions">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="creer_regime">'
            . Pages::saisie('nom', 'Nouveau régime (ex. NDRC FI)', '') . '<button type="submit">Créer le régime</button></form>'
            . '<h2>Sessions</h2>'
            . '<p>La session est l\'année de l\'examen : à la rentrée 2026, la promotion qui arrive est la session 2028. '
            . 'Créez une session à chaque rentrée. La version WordPress gelée servira à la création des sites (V2) ; laissez vide si aucune.</p>'
            . '<table><thead><tr><th>Session</th><th>État</th><th>Version WordPress gelée</th><th></th></tr></thead><tbody>' . $lignesSessions . '</tbody></table>'
            . '<form method="post" action="/regimes-sessions">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="creer_session">'
            . Pages::saisie('annee', 'Nouvelle session (année de l\'examen, ex. 2028)', '') . '<button type="submit">Créer la session</button></form>'
            . '<p><a href="/etudiants">Étudiants</a> · <a href="/">Accueil</a></p>');
    }

    /**
     * @param list<array{id: int, nom: string, actif: bool}> $regimes
     * @param list<array{id: int, annee: int, version_wp: ?string, active: bool}> $sessions
     * @param array<string, string> $valeurs
     */
    private static function champsEtudiant(array $regimes, array $sessions, array $valeurs): string
    {
        $optionsRegimes = '';
        foreach ($regimes as $regime) {
            $optionsRegimes .= self::option((string) $regime['id'], $regime['nom'], (string) $regime['id'] === ($valeurs['regime'] ?? ''));
        }
        $optionsSessions = '';
        foreach ($sessions as $session) {
            $optionsSessions .= self::option((string) $session['id'], 'Session ' . $session['annee'], (string) $session['id'] === ($valeurs['session'] ?? ''));
        }

        return Pages::saisie('nom', 'Nom de famille (ex. DE LA FONTAINE)', $valeurs['nom'] ?? '')
            . Pages::saisie('prenom', 'Prénom (ex. Jean)', $valeurs['prenom'] ?? '')
            . Pages::saisie('email', 'E-mail de l\'étudiant', $valeurs['email'] ?? '', 'email')
            . '<p><label for="regime">Régime</label><br><select id="regime" name="regime">' . $optionsRegimes . '</select></p>'
            . '<p><label for="session">Session</label><br><select id="session" name="session">' . $optionsSessions . '</select></p>';
    }

    private static function option(string $valeur, string $libelle, bool $choisi): string
    {
        return '<option value="' . Html::e($valeur) . '"' . ($choisi ? ' selected' : '') . '>' . Html::e($libelle) . '</option>';
    }

    /** « 2026-11-07T13:45:00Z » → « 07/11/2026 » */
    private static function date(string $date): string
    {
        $instant = strtotime($date);

        return $instant === false ? $date : gmdate('d/m/Y', $instant);
    }
}
