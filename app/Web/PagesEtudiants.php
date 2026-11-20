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
     * Tous les étudiants ; le filtre de la page (régime, session, texte) se fait dans le navigateur. Plus de filtre gardé
     * d'une connexion à l'autre (décision du 18/11/2026 : risque d'oubli).
     *
     * @param list<Etudiant> $etudiants
     */
    public static function liste(Compte $moi, array $etudiants, int $maintenant): string
    {
        $lignes = '';
        $fiches = Droits::peut($moi->role, Droits::VOIR_FICHE);
        foreach ($etudiants as $etudiant) {
            $lignes .= '<tr><td>' . ($fiches ? '<a href="/etudiants/' . $etudiant->id . '">' . Html::e($etudiant->nomComplet) . '</a>' : Html::e($etudiant->nomComplet)) . '</td>'
                . '<td>' . Html::e($etudiant->regime) . '</td><td>' . $etudiant->session . '</td>'
                . '<td>' . Html::e(RegimesSessions::anneeDeFormation($etudiant->session, $maintenant)) . '</td></tr>';
        }
        return Pages::page('Liste des étudiants',
            '<p>' . count($etudiants) . ' étudiant' . (count($etudiants) > 1 ? 's' : '') . '.</p>'
            . '<p class="discret">Sélectionnez votre liste ou cliquez sur le titre d\'une colonne pour trier.</p>'
            . '<table class="triable"><thead><tr><th>Étudiant</th><th>Régime</th><th>Session</th><th>Année</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            );
    }

    /** @param array{wordpress: bool, prestashop: bool, prevu: bool, suggestion: ?string} $etat */
    private static function reinitialisation(string $jeton, string $action, array $etat): string
    {
        if (!$etat['wordpress'] && !$etat['prestashop']) {
            return '';
        }
        $html = '<h2>Réinitialiser un site</h2>'
            . ($etat['suggestion'] === null ? '' : '<p class="alerte">' . Html::e($etat['suggestion']) . '</p>')
            . '<p>Le site repart de zéro, comme à la création. Le site actuel est gardé 8 jours. '
            . 'L\'étudiant reçoit ses nouveaux accès par e-mail ; l\'administrateur et les enseignants reçoivent le récapitulatif pour la feuille.</p>';
        if ($etat['prevu']) {
            return $html . '<p><span class="badge neutre">réinitialisation déjà prévue</span> — voir <a href="/travaux">Travaux en cours</a>.</p>';
        }
        foreach ([[$etat['wordpress'], 'reinitialiser_wp', 'le WordPress (/WP)'], [$etat['prestashop'], 'reinitialiser_presta', 'la boutique PrestaShop (/Presta)']] as [$present, $valeur, $libelle]) {
            if (!$present) {
                continue;
            }
            $html .= '<form method="post" action="' . $action . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="' . $valeur . '">'
                . '<p><label><input type="checkbox" name="confirmer" value="1"> Je confirme : ' . $libelle . ' de cet étudiant sera remplacé par un site neuf.</label></p>'
                . '<button type="submit">Réinitialiser ' . $libelle . '</button></form>';
        }

        return $html;
    }

    /** « Ajouter des étudiants » (décisions des 17 et 18/11/2026) : « Import liste Google Sheets » ou « Création d'un étudiant ». */
    private static function etiquettesAjout(string $actif): string
    {
        return Pages::etiquettes([
            ['adresse' => '/etudiants/import', 'titre' => 'Import liste Google Sheets', 'texte' => 'Toute une classe ou une liste d\'un coup.'],
            ['adresse' => '/etudiants/nouveau', 'titre' => 'Création d\'un étudiant', 'texte' => 'Un étudiant arrivé en cours d\'année.'],
        ], $actif, 'Ajouter des étudiants');
    }

    /**
     * @param list<array{id: int, nom: string, actif: bool}> $regimes
     * @param list<array{id: int, annee: int, version_wp: ?string, active: bool}> $sessions
     * @param array<string, string> $valeurs
     */
    public static function nouveau(string $jeton, array $regimes, array $sessions, array $valeurs, ?string $erreur): string
    {
        if ($regimes === [] || $sessions === []) {
            return Pages::page('Ajouter des étudiants', self::etiquettesAjout('/etudiants/nouveau') . '<p>Créez d\'abord au moins un régime et une session actifs '
                . '(<a href="/regimes-sessions">Régimes et sessions</a>).</p><p><a href="/etudiants">Retour aux étudiants</a></p>');
        }

        return Pages::page('Ajouter des étudiants', self::etiquettesAjout('/etudiants/nouveau') . Pages::alerte($erreur, null)
            . Pages::aide('Un étudiant arrivé en cours d\'année : nom et prénom séparés ; le portail propose ensuite l\'identifiant, que vous vérifiez avant d\'enregistrer.')
            . '<form method="post" action="/etudiants/nouveau">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="apercu">'
            . self::champsEtudiant($regimes, $sessions, $valeurs)
            . '<button type="submit" class="btn-main">Calculer l\'identifiant</button></form><p><a href="/etudiants">Retour aux étudiants</a></p>');
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
            . '<button type="submit" class="btn-main">Enregistrer l\'étudiant</button></form>'
            . '<form method="post" action="/etudiants/nouveau">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="corriger">' . $caches
            . '<button type="submit">Corriger la saisie</button></form>');
    }

    /**
     * @param list<array{id: int, nom: string, actif: bool}> $regimes
     * @param list<array{id: int, annee: int, version_wp: ?string, active: bool}> $sessions
     * @param list<array{date: string, champ: string, ancienne: ?string, nouvelle: ?string, auteur: ?string}> $historique
     * @param ?array{wordpress: bool, prestashop: bool, prevu: bool, suggestion: ?string} $reinitialisation Réinitialisations possibles (enseignant, administrateur)
     * @param string $blocDemandes HTML déjà échappé (PagesDemandes)
     * @param list<array{dossier: string, logiciel: string, version: ?string, dossier_admin: ?string, cree_par_portail: bool, date: string}> $sites
     * @param array<string, array{gel: ?string, base: ?string, utilisateur: ?string}> $techniques dossier => détails
     */
    public static function fiche(string $jeton, Compte $moi, Etudiant $etudiant, array $regimes, array $sessions, array $historique,
        int $maintenant, ?string $erreur, ?string $information, array $sites = [], ?array $reinitialisation = null,
        string $blocDemandes = '', array $techniques = []): string
    {
        $action = '/etudiants/' . $etudiant->id;
        // Fiche en cartes (décision du 17/11/2026) : l'étudiant, ses sites, puis les actions rares repliées en bas.
        $contenu = Pages::alerte($erreur, $information)
            . '<section class="carte-fiche"><dl class="infos">'
            . '<div><dt>Classe</dt><dd>' . Html::e($etudiant->classe()) . '</dd></div>'
            . '<div><dt>Année</dt><dd>' . Html::e(RegimesSessions::anneeDeFormation($etudiant->session, $maintenant)) . '</dd></div>'
            . '<div><dt>Identifiant</dt><dd><code>' . Html::e($etudiant->identifiant) . '</code></dd></div>'
            . '<div><dt>Identifiant WordPress</dt><dd><code>' . Html::e($etudiant->identifiantWp) . '</code></dd></div>'
            . '<div><dt>E-mail</dt><dd>' . Html::e($etudiant->email) . '</dd></div>'
            . '</dl></section>';

        $voirAcces = Droits::peut($moi->role, Droits::VOIR_ACCES_SITES);
        $cartes = '';
        foreach ($sites as $site) {
            $adresse = 'https://' . $etudiant->identifiant . '.bts-sup-sallanches.com' . $site['dossier'] . '/';
            // Administration : lien connu pour WordPress (wp-admin) et pour les boutiques créées par le portail.
            $admin = match (true) {
                $site['logiciel'] === 'wordpress' => $adresse . 'wp-admin/',
                $site['dossier_admin'] !== null => $adresse . $site['dossier_admin'] . '/',
                default => null,
            };
            $cartes .= '<article class="carte-site"><h3>' . Pages::nomSite($site['dossier']) . '</h3>'
                . self::detailsTechniques($etudiant->identifiant, $site, $techniques[$site['dossier']] ?? null,
                    Droits::peut($moi->role, Droits::TRAITER_DEMANDES))
                . '<p class="discret">' . Html::e(($site['logiciel'] === 'prestashop' ? 'PrestaShop' : 'WordPress') . ($site['version'] === null ? '' : ' ' . $site['version']))
                . ' · ' . ($site['cree_par_portail'] ? 'créé par le portail le ' : 'vu le ') . Html::e(self::date($site['date'])) . '</p>'
                . '<p class="boutons"><a class="bouton btn-main" href="' . Html::e($adresse) . '" target="_blank" rel="noopener noreferrer">Ouvrir '
                . ($site['logiciel'] === 'prestashop' ? 'la boutique' : 'le site') . '</a>'
                . ($voirAcces && $admin !== null ? ' <a class="bouton" href="' . Html::e($admin) . '" target="_blank" rel="noopener noreferrer">Administration</a>' : '')
                . '</p></article>';
        }
        $contenu .= '<h2>Ses sites</h2>'
            . ($cartes === '' ? '<p>Aucun site trouvé (liste des sites du serveur).</p>'
                : '<div class="cartes-sites">' . $cartes . '</div>'
                    . ($voirAcces ? '<p class="discret">Les liens s\'ouvrent dans un nouvel onglet. Le lien d\'administration d\'une boutique est personnel à l\'étudiant : ne le diffusez pas. '
                        . 'Pour une boutique créée avant le portail, il n\'est pas connu.</p>' : ''));

        // Actions rares, repliées : ouvertes d'office après une action (message d'erreur ou de confirmation).
        $actions = '';
        if ($reinitialisation !== null && Droits::peut($moi->role, Droits::REINITIALISER_SITE)) {
            $actions .= self::reinitialisation($jeton, $action, $reinitialisation);
        }
        if (Droits::peut($moi->role, Droits::MODIFIER_ETUDIANT)) {
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
            $actions .= '<h2>Changer de régime' . ($peutSession ? ' ou de session' : '') . '</h2>'
                . '<p>Passage FI ↔ ALT : changez le régime. Redoublement : changez la session'
                . ($peutSession ? '' : ' (droit non attribué à ce compte)') . '. Les deux peuvent changer en une seule fois. '
                . 'Les sites ne sont pas modifiés ici.</p>'
                . '<form method="post" action="' . $action . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="parcours">'
                . '<p><label for="regime">Régime</label><br><select id="regime" name="regime">' . $optionsRegimes . '</select></p>'
                . ($peutSession
                    ? '<p><label for="session">Session</label><br><select id="session" name="session">' . $optionsSessions . '</select></p>'
                    : '<input type="hidden" name="session" value="' . $etudiant->sessionId . '">')
                . '<button type="submit">Enregistrer le changement</button></form>'
                . '<h2>Corriger le nom ou l\'e-mail</h2>'
                . '<form method="post" action="' . $action . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="coordonnees">'
                . Pages::saisie('nom_complet', 'Nom complet (tel qu\'écrit dans EcoleDirecte)', $etudiant->nomComplet)
                . Pages::saisie('email', 'E-mail de l\'étudiant', $etudiant->email, 'email')
                . '<button type="submit">Enregistrer</button></form>';
        }
        // Demandes en attente, suppression (administrateur) ou demande à l'administrateur (lot 5, paquet B) : déjà échappé par PagesDemandes.
        $actions .= $blocDemandes;
        if ($actions !== '') {
            $contenu .= '<details class="bloc"' . ($erreur !== null || $information !== null || str_contains($blocDemandes, 'Demandes en attente') ? ' open' : '') . '>'
                . '<summary>Actions sur cet étudiant</summary>'
                . str_replace(['<h2>', '</h2>'], ['<h3>', '</h3>'], $actions) . '</details>';
        }

        $lignes = '';
        $libelles = ['regime' => 'Régime', 'session' => 'Session', 'nom_complet' => 'Nom', 'email' => 'E-mail'];
        foreach ($historique as $trace) {
            $lignes .= '<tr><td>' . Html::e(self::date($trace['date'])) . '</td><td>' . Html::e($libelles[$trace['champ']] ?? $trace['champ']) . '</td>'
                . '<td>' . Html::e($trace['ancienne'] ?? '') . ' → ' . Html::e($trace['nouvelle'] ?? '') . '</td>'
                . '<td>' . Html::e($trace['auteur'] ?? '') . '</td></tr>';
        }
        $contenu .= '<details class="bloc"><summary>Parcours et historique</summary>'
            . '<p>Ajouté par ' . ($etudiant->origine === 'import' ? 'import' : 'ajout manuel') . ', le ' . Html::e(self::date($etudiant->ajouteLe)) . '.</p>'
            . ($lignes === '' ? '<p>Aucun changement depuis l\'ajout.</p>'
                : '<table><thead><tr><th>Date</th><th>Changement</th><th>Avant → après</th><th>Par</th></tr></thead><tbody>' . $lignes . '</tbody></table>')
            . '</details>';
        $contenu .= '<details class="bloc"><summary>Détails techniques</summary><p class="discret">Adresse : <code>https://' . Html::e($etudiant->identifiant)
            . '.bts-sup-sallanches.com/</code> · dossier du serveur : <code>~/' . Html::e($etudiant->identifiant) . '.bts-sup-sallanches.com</code>';
        $contenu .= '</p></details><p><a href="/etudiants">Retour aux étudiants</a></p>';

        return Pages::page($etudiant->nomComplet, $contenu);
    }

    /**
     * @param list<array{id: int, nom: string, actif: bool}> $regimes
     * @param list<array{id: int, annee: int, version_wp: ?string, active: bool}> $sessions
     * @param ?array<int, array{total: int, geles: int}> $gel Gel des /WP par session (administrateur seulement, sinon null)
     */
    public static function regimesSessions(string $jeton, array $regimes, array $sessions, ?string $erreur, ?string $information,
        ?array $gel = null): string
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
                . '</td>' . ($gel === null ? '' : '<td>' . self::gelSession($session, $gel[$session['id']] ?? null, $bouton) . '</td>')
                . '<td>'
                . ($session['active'] ? $bouton('archiver_session', $id, 'Archiver') : $bouton('reactiver_session', $id, 'Réactiver'))
                . '</td></tr>';
        }

        return Pages::page('Régimes et sessions', Pages::alerte($erreur, $information)
            . '<p>Un régime ou une session n\'est jamais supprimé : on le désactive ou on l\'archive. '
            . 'Un étudiant ne peut être placé que dans un régime actif et une session active.</p>'
            . '<h2>Régimes</h2>'
            . '<table><thead><tr><th>Nom</th><th>État</th><th></th></tr></thead><tbody>' . $lignesRegimes . '</tbody></table>'
            . '<form method="post" action="/regimes-sessions">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="creer_regime">'
            . Pages::saisie('nom', 'Nouveau régime (ex. NDRC FI)', '') . '<button type="submit" class="btn-main">Créer le régime</button></form>'
            . '<h2>Sessions</h2>'
            . '<p>La session est l\'année de l\'examen : à la rentrée 2026, la promotion qui arrive est la session 2028. '
            . 'Créez une session à chaque rentrée. La version WordPress gelée servira à la création des sites (V2) ; laissez vide si aucune.</p>'
            . ($gel === null ? '' : '<p>« Geler les /WP » applique la version de la session aux sites WordPress /WP déjà en place '
                . '(correctifs de sécurité seulement, bouton « Mettre à jour WordPress » retiré). Les nombres viennent de la '
                . 'liste des sites : actualisez-la après un gel.</p>')
            . '<table><thead><tr><th>Session</th><th>État</th><th>Version WordPress gelée</th>'
            . ($gel === null ? '' : '<th>Gel des /WP</th>') . '<th></th></tr></thead><tbody>' . $lignesSessions . '</tbody></table>'
            . '<form method="post" action="/regimes-sessions">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="creer_session">'
            . Pages::saisie('annee', 'Nouvelle session (année de l\'examen, ex. 2028)', '') . '<button type="submit" class="btn-main">Créer la session</button></form>'
            . '<p><a href="/etudiants">Liste des étudiants</a> · <a href="/">Accueil</a></p>');
    }

    /**
     * Cellule « Gel des /WP » d'une session (administrateur) : nombre de /WP gelés, boutons Geler et Dégeler.
     *
     * @param array{id: int, annee: int, version_wp: ?string, active: bool} $session
     * @param ?array{total: int, geles: int} $etat
     * @param callable(string, string, string): string $bouton
     */
    private static function gelSession(array $session, ?array $etat, callable $bouton): string
    {
        if ($etat === null || $etat['total'] === 0) {
            return 'aucun /WP relevé';
        }
        $texte = Html::e($etat['geles'] . ' gelé(s) sur ' . $etat['total']) . ' ';
        if (!$session['active']) {
            return $texte;
        }
        $id = (string) $session['id'];
        if ($session['version_wp'] !== null && $etat['geles'] < $etat['total']) {
            $texte .= $bouton('geler_wordpress', $id, 'Geler les /WP');
        }

        return $etat['geles'] > 0 ? $texte . $bouton('degeler_wordpress', $id, 'Dégeler les /WP') : $texte;
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

    /**
     * Import : zone de collage, puis aperçu ligne par ligne, puis bilan.
     *
     * @param list<array{numero: int, nom: string, classe: string, session: string, identifiant: string, wp: string, email: string,
     *     statut: string, raisons: list<string>, notes: list<string>, regimeId: ?int, sessionId: ?int, existantId: ?int}>|null $lignes
     * @param array{importes: int, presents: int, refuses: int, non_coches: int}|null $bilan
     */
    public static function import(string $jeton, Compte $moi, string $texte, ?array $lignes, ?string $erreur, ?array $bilan, ?string $releveLe): string
    {
        $contenu = Pages::alerte($erreur, null);
        if ($bilan !== null) {
            return Pages::page('Import terminé', '<p role="status"><strong>' . $bilan['importes'] . ' étudiant(s) importé(s).</strong></p>'
                . '<ul><li>Déjà présents, non modifiés : ' . $bilan['presents'] . '</li>'
                . '<li>Refusés : ' . $bilan['refuses'] . '</li>'
                . '<li>À vérifier, non cochés donc non importés : ' . $bilan['non_coches'] . '</li></ul>'
                . '<p>L\'import est inscrit au journal. Aucun site n\'a été créé ni modifié.</p>'
                . '<p><a href="/etudiants">Voir les étudiants</a> · <a href="/etudiants/import">Nouvel import</a></p>');
        }
        $releve = $releveLe === null
            ? '<p><strong>Aucun relevé des sous-domaines n\'a encore été fait.</strong> Les identifiants hors règle ne pourront pas être vérifiés '
                . 'et seront refusés (commande de relevé : voir le guide d\'administration).</p>'
            : '<p>Liste des sites du serveur mise à jour le : ' . Html::e(self::date($releveLe)) . '.</p>';
        $formulaire = '<form method="post" action="/etudiants/import">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="apercu">'
            . '<p><label for="texte">Dans la Google Sheets, ouvrez l\'onglet « Export portail », copiez les en-têtes de colonnes et les données choisies, '
            . 'puis collez-les dans le cadre ci-dessous.</label></p>'
            . '<p><textarea id="texte" name="texte" rows="12" cols="100" required>' . Html::e($texte) . '</textarea></p>'
            . '<button type="submit" class="btn-main">Voir l\'aperçu (rien n\'est enregistré)</button></form>';
        if ($lignes === null) {
            return Pages::page('Ajouter des étudiants', self::etiquettesAjout('/etudiants/import')
                . $contenu . $releve
                . '<p>Le texte collé est lu en mémoire, jamais enregistré sur le serveur. Colonnes attendues : NomPrénom, Classe, Session, '
                . 'Identifiant, Identifiant WordPress, E-mail. Un texte qui contient une colonne de mot de passe, de date de naissance '
                . 'ou de code chiffré est refusé en entier.</p>' . $formulaire
                . '<p><a href="/etudiants">Retour aux étudiants</a> · <a href="/">Accueil</a></p>');
        }

        $compte = ['vert' => 0, 'orange' => 0, 'gris' => 0, 'rouge' => 0];
        $rangees = '';
        $fiches = Droits::peut($moi->role, Droits::VOIR_FICHE);
        foreach ($lignes as $ligne) {
            $compte[$ligne['statut']] = ($compte[$ligne['statut']] ?? 0) + 1;
            $etat = match ($ligne['statut']) {
                'vert' => '<span class="badge ok">À importer</span>',
                'orange' => '<label class="badge warn"><input type="checkbox" name="coches[]" value="' . $ligne['numero'] . '"> ⚠ À vérifier : cocher pour importer</label>',
                'gris' => '<span class="badge neutre">— Déjà présent</span>',
                default => '<span class="badge err">Refusé</span>',
            };
            $nom = $ligne['existantId'] !== null && $fiches
                ? '<a href="/etudiants/' . $ligne['existantId'] . '">' . Html::e($ligne['nom']) . '</a>' : Html::e($ligne['nom']);
            $remarques = '';
            foreach (array_merge($ligne['raisons'], $ligne['notes']) as $remarque) {
                $remarques .= '<li>' . Html::e($remarque) . '</li>';
            }
            $rangees .= '<tr><td>' . $ligne['numero'] . '</td><td>' . $etat . '</td><td>' . $nom . '</td>'
                . '<td><strong>' . Html::e($ligne['identifiant']) . '</strong></td><td>' . Html::e($ligne['wp']) . '</td>'
                . '<td>' . Html::e($ligne['classe']) . ' – ' . Html::e($ligne['session']) . '</td><td>' . Html::e($ligne['email']) . '</td>'
                . '<td>' . ($remarques === '' ? '' : '<ul>' . $remarques . '</ul>') . '</td></tr>';
        }

        return Pages::page('Aperçu de l\'import', $contenu . $releve
            . '<p><strong>Rien n\'est encore enregistré.</strong> Vérifiez chaque identifiant à côté du nom : '
            . $compte['vert'] . ' à importer, ' . $compte['orange'] . ' à vérifier, ' . $compte['gris'] . ' déjà présent(s), '
            . $compte['rouge'] . ' refusé(s).</p>'
            . '<form method="post" action="/etudiants/import">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="importer">'
            . '<textarea name="texte" hidden>' . Html::e($texte) . '</textarea>'
            . '<table class="triable"><thead><tr><th>Ligne</th><th>État</th><th>Nom complet</th><th>Identifiant</th><th>Identifiant WordPress</th>'
            . '<th>Classe – session</th><th>E-mail</th><th>Remarques</th></tr></thead><tbody>' . $rangees . '</tbody></table>'
            . '<p>À la validation, le texte est vérifié de nouveau, puis les lignes « À importer » et les lignes « À vérifier » cochées '
            . 'sont enregistrées toutes ensemble. Aucun site n\'est créé.</p>'
            . '<button type="submit" class="btn-main">Importer</button> '
            . '<a href="/etudiants">Annuler : quitter sans rien enregistrer</a></form>'
            . '<h2>Corriger le texte</h2>' . $formulaire
            . '<p><a href="/etudiants">Retour aux étudiants</a> · <a href="/">Accueil</a></p>');
    }

    /** Mots simples de l'état du gel WordPress relevé sur le serveur. */
    private const GELS = [
        'total' => 'gelé, sans aucune mise à jour', 'correctifs' => 'gelé, correctifs de sécurité permis',
        'incomplet' => 'gel incomplet (à vérifier)', 'aucun' => 'pas de gel',
    ];

    /**
     * Détails techniques d'un site, repliés à un clic en haut de sa carte (améliorations C5 et D19) : dossier du
     * serveur, origine, gel ; base et utilisateur MySQL pour l'administrateur seulement.
     *
     * @param array{dossier: string, logiciel: string, version: ?string, dossier_admin: ?string, cree_par_portail: bool,
     *              date: string} $site
     * @param array{gel: ?string, base: ?string, utilisateur: ?string}|null $technique
     */
    private static function detailsTechniques(string $identifiant, array $site, ?array $technique,
        bool $administrateur): string
    {
        $dossier = '~/' . $identifiant . '.bts-sup-sallanches.com' . $site['dossier'];
        $origine = $site['cree_par_portail'] ? 'créé par le portail' : 'd\'avant le portail (scripts ou à la main)';
        $lignes = ['Dossier' => '<code>' . Html::e($dossier) . '</code>', 'Origine' => Html::e($origine)];
        if ($site['logiciel'] === 'wordpress') {
            $gel = $technique['gel'] ?? null;
            $etat = $gel === null ? 'inconnu (liste des sites à mettre à jour)' : (self::GELS[$gel] ?? $gel);
            $lignes['Gel'] = Html::e($etat);
        }
        if ($administrateur && $technique !== null && $technique['base'] !== null) {
            $lignes['Base MySQL'] = '<code>' . Html::e($technique['base']) . '</code>';
            $lignes['Utilisateur MySQL'] = '<code>' . Html::e((string) $technique['utilisateur']) . '</code>';
        }
        $html = '';
        foreach ($lignes as $terme => $valeur) {
            $html .= '<dt>' . Html::e($terme) . '</dt><dd>' . $valeur . '</dd>';
        }

        return '<details class="details-tech"><summary>Détails techniques</summary><dl>' . $html . '</dl></details>';
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
