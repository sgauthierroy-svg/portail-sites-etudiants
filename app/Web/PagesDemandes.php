<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Sites\Demandes;
use Portail\Sites\MiseALecart;

/**
 * Page « Demandes » (lot 5, paquet B, 15/11/2026) : demandes en attente pour l'administrateur (validation par lot ou une
 * par une, refus commenté), suppressions restaurables pendant 8 jours, et « Mes demandes » pour chaque demandeur.
 *
 * @phpstan-import-type Demande from Demandes
 * @psalm-import-type Demande from Demandes
 */
final class PagesDemandes
{
    /** Motifs proposés à la saisie (cahier des charges : départ, abandon, fin de formation, arrivée…) ; texte libre accepté. */
    public const MOTIFS = ['Départ de l\'établissement', 'Abandon de la formation', 'Fin de formation', 'Redoublement', 'Changement de régime',
        'Site cassé, à refaire', 'Erreur à la création'];

    /**
     * @param list<Demande> $enAttente
     * @param list<Demande> $miennes
     * @param list<array{chemin: string, identifiant: string, dossier: string, motif: string, mis_le: string, expire_le: string, nom: string}> $restaurables
     */
    public static function page(string $jeton, Compte $moi, array $enAttente, array $miennes, array $restaurables, ?string $erreur, ?string $information): string
    {
        $contenu = self::etiquettesDemandes($moi, '/demandes') . Pages::alerte($erreur, $information);
        if (Droits::peut($moi->role, Droits::TRAITER_DEMANDES)) {
            $contenu .= self::aTraiter($jeton, $enAttente);
        }
        if (Droits::peut($moi->role, Droits::SUPPRIMER)) {
            $contenu .= self::restaurables($jeton, $restaurables);
        }
        if (Droits::peut($moi->role, Droits::DEMANDER)) {
            $contenu .= self::miennes($jeton, $miennes, Droits::peut($moi->role, Droits::TRAITER_DEMANDES));
        }

        return Pages::page('Demandes', $contenu);
    }

    /**
     * Étiquettes de la page « Demandes » (demande du 18/11/2026) pour ceux qui demandent ; l'administrateur, qui traite, n'en a pas.
     */
    private static function etiquettesDemandes(Compte $moi, string $actif): string
    {
        if (!Droits::peut($moi->role, Droits::DEMANDER) || Droits::peut($moi->role, Droits::TRAITER_DEMANDES)) {
            return '';
        }

        return Pages::etiquettes([
            ['adresse' => '/demandes', 'titre' => 'Voir les demandes en cours', 'texte' => 'Vos demandes et la réponse du service informatique.'],
            ['adresse' => '/demandes/nouvelle', 'titre' => 'Faire une demande au service informatique', 'texte' => 'Suppression ou problème (SAV), pour un étudiant ou une classe.'],
        ], $actif, 'Demandes');
    }

    /**
     * « Faire une demande au service informatique » (demande du 18/11/2026) : objet (suppression ou problème), étudiant ou
     * classe concernés, site, motif ou description.
     *
     * @param list<array{id: int, nom: string, classe: string}> $etudiants
     * @param list<array{valeur: string, libelle: string, nombre: int}> $classes
     * @param array<string, string> $valeurs
     */
    public static function nouvelle(string $jeton, Compte $moi, array $etudiants, array $classes, array $valeurs, ?string $erreur): string
    {
        $valeur = static fn (string $cle, string $defaut = ''): string => $valeurs[$cle] ?? $defaut;
        $radio = static fn (string $nom, string $val, string $libelle, string $choisi): string => '<label class="choix"><input type="radio" name="' . $nom
            . '" value="' . $val . '"' . ($val === $choisi ? ' checked' : '') . ' required> ' . Html::e($libelle) . '</label> ';
        $optionsEtudiants = '<option value="">— choisir l\'étudiant —</option>';
        foreach ($etudiants as $etudiant) {
            $optionsEtudiants .= '<option value="' . $etudiant['id'] . '"' . ((string) $etudiant['id'] === $valeur('etudiant') ? ' selected' : '') . '>'
                . Html::e($etudiant['nom'] . ' (' . $etudiant['classe'] . ')') . '</option>';
        }
        $optionsClasses = '<option value="">— choisir la classe —</option>';
        foreach ($classes as $classe) {
            $optionsClasses .= '<option value="' . Html::e($classe['valeur']) . '"' . ($classe['valeur'] === $valeur('classe') ? ' selected' : '') . '>'
                . Html::e($classe['libelle'] . ' (' . $classe['nombre'] . ' étudiant' . ($classe['nombre'] > 1 ? 's' : '') . ')') . '</option>';
        }
        $optionsSites = '<option value="">Tous ses sites (suppression : l\'étudiant entier) / aucun en particulier</option>';
        foreach (Pages::NOMS_SITES as $dossier => $nom) {
            $optionsSites .= '<option value="' . $dossier . '"' . ($dossier === $valeur('site') ? ' selected' : '') . '>' . Html::e($nom . ' ' . $dossier) . '</option>';
        }

        return Pages::page('Demandes', self::etiquettesDemandes($moi, '/demandes/nouvelle') . Pages::alerte($erreur, null)
            . Pages::aide('Le service informatique reçoit votre demande, la traite ou la refuse avec un commentaire ; vous êtes prévenu par e-mail.')
            . '<form method="post" action="/demandes/nouvelle" class="demande">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="demander">'
            . '<fieldset><legend>1. Objet de la demande</legend>'
            . $radio('objet', 'suppression', 'Supprimer (un site, un étudiant ou une classe)', $valeur('objet'))
            . $radio('objet', 'probleme', 'Signaler un problème (SAV)', $valeur('objet')) . '</fieldset>'
            . '<fieldset><legend>2. Qui est concerné ?</legend>'
            . '<p>' . $radio('cible', 'etudiant', 'Un étudiant', $valeur('cible', 'etudiant'))
            . '<label class="cache" for="etudiant">Étudiant</label><select id="etudiant" name="etudiant">' . $optionsEtudiants . '</select></p>'
            . '<p>' . $radio('cible', 'classe', 'Une classe', $valeur('cible', 'etudiant'))
            . '<label class="cache" for="classe">Classe</label><select id="classe" name="classe">' . $optionsClasses . '</select></p>'
            . '<p>' . $radio('cible', 'aucune', 'Personne en particulier (problème général, problème seulement)', $valeur('cible', 'etudiant')) . '</p></fieldset>'
            . '<fieldset><legend>3. Quel site ?</legend><label class="cache" for="site">Site</label><select id="site" name="site">' . $optionsSites . '</select></fieldset>'
            . '<fieldset><legend>4. Motif ou description</legend>'
            . '<p class="discret">Suppression : le motif (départ, abandon, fin de formation, redoublement…). Problème : ce qui se passe, sur quelle page, depuis quand.</p>'
            . '<label class="cache" for="texte">Motif ou description</label><textarea id="texte" name="texte" rows="5" cols="80" required minlength="3" maxlength="'
            . Demandes::DESCRIPTION_MAX . '">' . Html::e($valeur('texte')) . '</textarea></fieldset>'
            . '<button type="submit" class="btn-main">Envoyer au service informatique</button></form>');
    }

    /**
     * Formulaire de demande sur la fiche d'un étudiant (enseignant, administratif).
     *
     * @param array<string, string> $choix valeur => libellé
     */
    public static function formulaireFiche(string $jeton, string $action, array $choix): string
    {
        if ($choix === []) {
            return '';
        }
        $options = '<option value="">— choisir —</option>';
        foreach ($choix as $valeur => $libelle) {
            $options .= '<option value="' . Html::e($valeur) . '">' . Html::e($libelle) . '</option>';
        }

        return '<h2>Demander au service informatique</h2>'
            . '<p>Vous êtes prévenu par e-mail de la réponse, et vous la suivez dans « Demandes ».</p>'
            . '<form method="post" action="' . Html::e($action) . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="demander">'
            . '<p><label for="choix">Ce que vous demandez</label><br><select id="choix" name="choix" required>' . $options . '</select></p>'
            . self::champMotif(true, true)
            . '<button type="submit">Envoyer la demande</button></form>';
    }

    /**
     * Suppression directe sur la fiche (administrateur) : choix du site ou de l'étudiant, confirmation en tapant l'identifiant.
     *
     * @param list<array{dossier: string, logiciel: string, version: ?string, dossier_admin: ?string, cree_par_portail: bool, date: string}> $sites
     */
    public static function suppressionFiche(string $jeton, string $action, string $identifiant, array $sites, bool $prevu, bool $avecTitre = true): string
    {
        $html = $avecTitre ? '<h2>Supprimer (administrateur)</h2>' : '';
        if ($prevu) {
            return $html . '<p><span class="badge neutre">un travail est déjà prévu pour cet étudiant</span> — voir <a href="/travaux">Travaux en cours</a>.</p>';
        }
        $options = '';
        foreach ($sites as $site) {
            $options .= '<label class="choix"><input type="radio" name="choix" value="site:' . Html::e($site['dossier']) . '" required> '
                . Pages::nomSite($site['dossier']) . ' (' . Html::e(($site['logiciel'] === 'prestashop' ? 'PrestaShop' : 'WordPress') . ($site['version'] === null ? '' : ' ' . $site['version']))
                . ') et sa base</label><br>';
        }
        $options .= '<label class="choix"><input type="radio" name="choix" value="etudiant" required> <strong>l\'étudiant</strong> : sa fiche, tous ses sites, '
            . 'toutes ses bases et son sous-domaine <code>' . Html::e($identifiant) . '.bts-sup-sallanches.com</code></label>';

        return $html
            . '<p>Rien n\'est effacé tout de suite : tout est gardé <strong>' . MiseALecart::JOURS . ' jours</strong> (bouton « Restaurer » de la page '
            . '<a href="/demandes">Demandes</a>), puis effacé définitivement, sous-domaine compris pour un étudiant.</p>'
            . '<form method="post" action="' . Html::e($action) . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="supprimer">'
            . '<input type="hidden" name="identifiant" value="' . Html::e($identifiant) . '">'
            . '<fieldset><legend>Supprimer</legend>' . $options . '</fieldset>'
            . '<p><label for="confirmation">Pour confirmer, tapez l\'identifiant de l\'étudiant (<code>' . Html::e($identifiant) . '</code>)</label><br>'
            . '<input id="confirmation" name="confirmation" type="text" autocomplete="off" required maxlength="63"></p>'
            . '<button type="submit" class="btn-danger">Supprimer</button></form>';
    }

    /**
     * Suppression en masse par session (administrateur, demande du 16/11/2026).
     *
     * @param list<array{id: int, annee: int, etudiants: int}> $sessions
     * @param ?list<array{identifiant: string, nom: string, regime: string, sites: list<string>, empechement: ?string}> $etudiants
     */
    public static function masse(string $jeton, array $sessions, ?int $sessionChoisie, ?array $etudiants, ?string $erreur): string
    {
        $options = '';
        $annee = null;
        foreach ($sessions as $session) {
            $choisie = $session['id'] === $sessionChoisie;
            $annee = $choisie ? $session['annee'] : $annee;
            $options .= '<option value="' . $session['id'] . '"' . ($choisie ? ' selected' : '') . '>Session ' . $session['annee']
                . ' (' . $session['etudiants'] . ' étudiant' . ($session['etudiants'] > 1 ? 's' : '') . ')</option>';
        }
        $contenu = self::etiquettesSupprimer('/sites/supprimer') . Pages::alerte($erreur, null)
            . Pages::aide('Choisissez la session, cochez les étudiants, puis ce qu\'il faut supprimer. Tout reste dans la corbeille ' . MiseALecart::JOURS . ' jours.')
            . '<p class="discret">Pour une session entière (par exemple une promotion partie en juillet) ou une partie de ses étudiants : '
            . 'choisissez la session, cochez les étudiants, puis ce qu\'il faut supprimer. Rien n\'est effacé tout de suite : tout est gardé '
            . MiseALecart::JOURS . ' jours (bouton « Restaurer » de la page <a href="/demandes">Demandes</a>), puis effacé définitivement, '
            . 'sous-domaines compris pour une suppression complète. Le travail se fait en arrière-plan, un étudiant après l\'autre.</p>'
            . '<form method="post" action="/sites/supprimer">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="afficher">'
            . '<label for="session">Session</label> <select id="session" name="session">' . $options . '</select> '
            . '<button type="submit">Afficher les étudiants</button></form>';
        if ($etudiants === null || $sessionChoisie === null) {
            return Pages::page('Supprimer', $contenu);
        }
        $lignes = '';
        $possibles = 0;
        foreach ($etudiants as $etudiant) {
            $possible = $etudiant['empechement'] === null;
            $possibles += $possible ? 1 : 0;
            $lignes .= '<tr><td>' . ($possible ? '<input type="checkbox" name="coches[]" value="' . Html::e($etudiant['identifiant']) . '" aria-label="'
                    . Html::e($etudiant['nom']) . '">' : '') . '</td>'
                . '<td>' . Html::e($etudiant['nom']) . '</td><td><code>' . Html::e($etudiant['identifiant']) . '</code></td><td>' . Html::e($etudiant['regime']) . '</td>'
                . '<td>' . ($etudiant['sites'] === [] ? '<span class="discret">aucun</span>' : implode(', ', array_map([Pages::class, 'nomSite'], $etudiant['sites']))) . '</td>'
                . '<td>' . ($possible ? '' : '<span class="badge neutre">' . Html::e((string) $etudiant['empechement']) . '</span>') . '</td></tr>';
        }
        if ($etudiants === []) {
            return Pages::page('Supprimer', $contenu . '<p>Aucun étudiant dans cette session.</p>');
        }
        $choix = '<label class="choix"><input type="radio" name="choix" value="etudiant" required> <strong>tout</strong> : les étudiants cochés, '
            . 'tous leurs sites, toutes leurs bases et leurs sous-domaines</label><br>';
        foreach (array_keys(Pages::NOMS_SITES) as $dossier) {
            $choix .= '<label class="choix"><input type="radio" name="choix" value="site:' . $dossier . '" required> seulement : '
                . Pages::nomSite($dossier) . ' et sa base ; les étudiants restent</label><br>';
        }

        return Pages::page('Supprimer', $contenu . '<h2>Session ' . (int) $annee . '</h2>'
            . '<form method="post" action="/sites/supprimer">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="supprimer"><input type="hidden" name="session" value="' . $sessionChoisie . '">'
            . '<table class="triable"><thead><tr><th><input type="checkbox" data-tout-cocher aria-label="Tout cocher" title="Tout cocher"></th>'
            . '<th>Étudiant</th><th>Identifiant</th><th>Régime</th><th>Sites</th><th>État</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . ($possibles === 0 ? '<p>Aucun étudiant de cette session ne peut être supprimé pour l\'instant.</p>' : '<fieldset><legend>Supprimer</legend>' . $choix . '</fieldset>'
                . self::champMotif(false)
                . '<p><label for="confirmation">Pour confirmer, tapez <code>' . Demandes::MOT_CONFIRMATION . '</code></label><br>'
                . '<input id="confirmation" name="confirmation" type="text" autocomplete="off" required maxlength="20"></p>'
                . '<button type="submit" class="btn-danger">Supprimer pour les étudiants cochés</button>')
            . '</form>');
    }

    /**
     * Supprimer un étudiant (administrateur, demande du 17/11/2026) : choisir l'étudiant, puis un de ses sites ou tout.
     *
     * @param list<array{identifiant: string, nom: string, classe: string}> $etudiants
     * @param ?array{identifiant: string, nom: string, sites: list<array{dossier: string, logiciel: string, version: ?string, dossier_admin: ?string, cree_par_portail: bool, date: string}>, prevu: bool} $choisi
     */
    public static function supprimerUnEtudiant(string $jeton, array $etudiants, ?array $choisi, ?string $erreur): string
    {
        $options = '<option value="">— choisir un étudiant —</option>';
        foreach ($etudiants as $etudiant) {
            $options .= '<option value="' . Html::e($etudiant['identifiant']) . '"' . ($choisi !== null && $choisi['identifiant'] === $etudiant['identifiant'] ? ' selected' : '')
                . '>' . Html::e($etudiant['nom'] . ' — ' . $etudiant['classe'] . ' (' . $etudiant['identifiant'] . ')') . '</option>';
        }
        $contenu = self::etiquettesSupprimer('/sites/supprimer/etudiant') . Pages::alerte($erreur, null)
            . Pages::aide('Choisissez l\'étudiant, puis ce qu\'il faut supprimer : un de ses sites, ou tout (fiche, sites, bases, adresse). '
                . 'Tout reste dans la corbeille ' . MiseALecart::JOURS . ' jours. On peut aussi supprimer depuis la fiche de l\'étudiant.')
            . '<form method="post" action="/sites/supprimer/etudiant">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="afficher">'
            . '<p><label for="etudiant">Étudiant</label><br><select id="etudiant" name="identifiant" required>' . $options . '</select></p>'
            . '<button type="submit">Afficher ses sites</button></form>';
        if ($choisi !== null) {
            $contenu .= '<h2>' . Html::e($choisi['nom']) . '</h2>'
                . self::suppressionFiche($jeton, '/sites/supprimer/etudiant', $choisi['identifiant'],
                    array_values(array_filter($choisi['sites'], static fn (array $s): bool => in_array($s['dossier'], \Portail\Sites\Suppressions::DOSSIERS, true))),
                    $choisi['prevu'], false);
        }

        return Pages::page('Supprimer', $contenu);
    }

    private static function etiquettesSupprimer(string $actif): string
    {
        return Pages::etiquettes([
            ['adresse' => '/sites/supprimer', 'titre' => 'Supprimer une liste', 'texte' => 'Les étudiants d\'une session, ou un de leurs sites : par exemple une promotion partie en juillet.'],
            ['adresse' => '/sites/supprimer/etudiant', 'titre' => 'Supprimer un étudiant', 'texte' => 'Un seul étudiant : un de ses sites, ou tout.'],
        ], $actif, 'Supprimer');
    }

    /** @param list<Demande> $demandes */
    public static function enAttenteFiche(array $demandes, bool $traiter): string
    {
        if ($demandes === []) {
            return '';
        }
        $lignes = '';
        foreach ($demandes as $demande) {
            $lignes .= '<li><span class="badge warn">' . Html::e(Demandes::ETIQUETTES[$demande['type']] ?? $demande['type']) . '</span> '
                . Html::e((Demandes::LIBELLES[$demande['type']] ?? $demande['type']) . ($demande['dossier'] === null ? '' : ' ' . $demande['dossier']))
                . ' — par ' . Html::e($demande['demande_par_nom']) . ' le ' . Html::e(Pages::heureLocale($demande['demande_le']))
                . ', motif : « ' . Html::e($demande['motif']) . ' »</li>';
        }

        // « Demander » n'est pas « exécuter » (amélioration D18) : rien n'est fait tant que le service informatique
        // ne valide pas.
        $suite = $traiter
            ? '<p>Demandées par l\'équipe : rien n\'est fait tant que vous ne les validez pas. '
                . '<a href="/demandes">Les traiter</a></p>'
            : '<p>Rien n\'est fait tant que le service informatique ne les a pas validées.</p>';

        return '<h2>Demandes en attente</h2><ul class="demandees">' . $lignes . '</ul>' . $suite;
    }

    /** @param list<Demande> $demandes */
    private static function aTraiter(string $jeton, array $demandes): string
    {
        $html = '<h2>À traiter (' . count($demandes) . ')</h2>';
        if ($demandes === []) {
            return $html . '<p>Aucune demande en attente.</p>';
        }
        $html .= '<p>Demandes de l\'équipe : rien n\'est fait tant que vous ne les validez pas.</p>';
        $groupes = [];
        foreach ($demandes as $demande) {
            $groupes[$demande['type']][] = $demande;
        }
        $tableaux = '';
        foreach ($groupes as $type => $liste) {
            $lignes = '';
            foreach ($liste as $demande) {
                $nom = $demande['nom'] ?? ($demande['identifiant'] === '' ? ($demande['classe'] === null ? '—' : 'Toute la classe')
                    : $demande['identifiant'] . ' (plus dans le portail)');
                $lien = $demande['etudiant_id'] === null ? Html::e($nom) : '<a href="/etudiants/' . $demande['etudiant_id'] . '">' . Html::e($nom) . '</a>';
                $lignes .= '<tr><td><input type="checkbox" name="coches[]" value="' . $demande['id'] . '" aria-label="Choisir"></td>'
                    . '<td>' . $lien . '</td><td>' . Html::e($demande['classe'] ?? '') . '</td>'
                    . '<td>' . ($demande['dossier'] !== null ? Pages::nomSite($demande['dossier']) : Html::e($type === 'supprimer_etudiant' ? 'tout, adresse comprise' : '')) . '</td>'
                    . '<td>' . Html::e($demande['motif']) . '</td>'
                    . '<td>' . Html::e($demande['demande_par_nom']) . '</td><td>' . Html::e(Pages::heureLocale($demande['demande_le'])) . '</td></tr>';
            }
            $tableaux .= '<h3>' . Html::e(Demandes::LIBELLES[$type] ?? $type) . ' (' . count($liste) . ')</h3>'
                . '<table class="triable"><thead><tr><th><input type="checkbox" data-tout-cocher aria-label="Tout cocher" title="Tout cocher"></th>'
                . '<th>Étudiant</th><th>Classe</th><th>Site</th><th>' . ($type === 'probleme' ? 'Description' : 'Motif') . '</th><th>Demandé par</th><th>Le</th></tr></thead><tbody>' . $lignes . '</tbody></table>';
        }

        return $html
            . '<p>Cochez une demande (une par une) ou plusieurs (par lot, par exemple toute une session en fin de 2e année), puis validez ou refusez. '
            . 'Une demande validée lance le travail habituel ; une suppression reste restaurable ' . MiseALecart::JOURS . ' jours.</p>'
            . '<form method="post" action="/demandes">' . Pages::jeton($jeton)
            . $tableaux
            . '<p><label for="confirmation">Suppressions cochées : tapez <code>' . Demandes::MOT_CONFIRMATION . '</code> pour confirmer</label><br>'
            . '<input id="confirmation" name="confirmation" type="text" autocomplete="off" maxlength="20"></p>'
            . '<p><label for="commentaire">Commentaire transmis au demandeur (obligatoire pour un refus ; pour un problème traité, votre réponse)</label><br>'
            . '<input id="commentaire" name="commentaire" type="text" maxlength="300"></p>'
            . '<p class="boutons"><button type="submit" name="action" value="valider" class="btn-main">Valider la sélection (problèmes : traités)</button> '
            . '<button type="submit" name="action" value="refuser">Refuser la sélection</button></p></form>';
    }

    /** @param list<array{chemin: string, identifiant: string, dossier: string, motif: string, mis_le: string, expire_le: string, nom: string}> $restaurables */
    private static function restaurables(string $jeton, array $restaurables): string
    {
        $html = '<h2>Corbeille : suppressions restaurables (' . MiseALecart::JOURS . ' jours)</h2>';
        if ($restaurables === []) {
            return $html . '<p>Aucune suppression récente.</p>';
        }
        $lignes = '';
        foreach ($restaurables as $ecart) {
            $quoi = $ecart['dossier'] === MiseALecart::SOUS_DOMAINE ? 'l\'étudiant (tout)' : Pages::nomSiteTexte($ecart['dossier']);
            $lignes .= '<tr><td>' . Html::e($ecart['nom'] === '' ? $ecart['identifiant'] : $ecart['nom']) . ' <code>' . Html::e($ecart['identifiant']) . '</code></td>'
                . '<td>' . Html::e($quoi) . '</td><td>' . Html::e($ecart['motif']) . '</td>'
                . '<td>' . Html::e(Pages::heureLocale($ecart['mis_le'])) . '</td><td>' . Html::e(Pages::heureLocale($ecart['expire_le'])) . '</td>'
                . '<td><form method="post" action="/demandes">' . Pages::jeton($jeton)
                . '<input type="hidden" name="action" value="restaurer"><input type="hidden" name="chemin" value="' . Html::e($ecart['chemin']) . '">'
                . '<button type="submit">Restaurer</button></form></td></tr>';
        }

        return $html
            . '<p>« Restaurer » remet le dossier à sa place avec ses bases (et, pour un étudiant, sa fiche et ses sites). '
            . 'Après la date d\'effacement, plus rien n\'est récupérable.</p>'
            . '<table><thead><tr><th>Étudiant</th><th>Supprimé</th><th>Motif</th><th>Le</th><th>Effacé le</th><th></th></tr></thead><tbody>' . $lignes . '</tbody></table>';
    }

    /** @param list<Demande> $miennes */
    private static function miennes(string $jeton, array $miennes, bool $administrateur): string
    {
        if ($miennes === [] && $administrateur) {
            return '';
        }
        $html = '<h2>Mes demandes</h2>';
        if ($miennes === []) {
            return $html . '<p>Aucune demande. Pour en faire une : <a href="/demandes/nouvelle">Faire une demande au service informatique</a>.</p>';
        }
        $lignes = '';
        foreach ($miennes as $demande) {
            $etat = '<span class="badge ' . match ($demande['etat']) { 'validee' => 'ok', 'refusee' => 'err', 'en_attente' => 'warn', default => 'neutre' } . '">'
                . Html::e($demande['type'] === 'probleme' && $demande['etat'] === 'validee' ? 'Traité' : (Demandes::ETATS[$demande['etat']] ?? $demande['etat'])) . '</span>';
            $suite = match (true) {
                $demande['etat'] === 'en_attente' => '<form method="post" action="/demandes">' . Pages::jeton($jeton)
                    . '<input type="hidden" name="action" value="annuler"><input type="hidden" name="id" value="' . $demande['id'] . '">'
                    . '<button type="submit">Annuler</button></form>',
                $demande['travail_id'] !== null => '<a href="/travaux/' . $demande['travail_id'] . '">travail n° ' . $demande['travail_id'] . '</a>',
                default => Html::e($demande['commentaire'] ?? ''),
            };
            $lignes .= '<tr><td>' . Html::e($demande['nom'] ?? ($demande['identifiant'] !== '' ? $demande['identifiant'] : ($demande['classe'] ?? '—'))) . '</td>'
                . '<td>' . Html::e((Demandes::LIBELLES[$demande['type']] ?? $demande['type']) . ($demande['dossier'] === null ? '' : ' ' . $demande['dossier'])) . '</td>'
                . '<td>' . Html::e($demande['motif']) . '</td><td>' . Html::e(Pages::heureLocale($demande['demande_le'])) . '</td>'
                . '<td>' . $etat . ($demande['traitee_par_nom'] === null ? '' : ' <span class="discret">' . Html::e($demande['traitee_par_nom']) . '</span>') . '</td>'
                . '<td>' . $suite . '</td></tr>';
        }

        return $html . '<table class="triable"><thead><tr><th>Étudiant</th><th>Demande</th><th>Motif</th><th>Le</th><th>État</th><th>Suite</th></tr></thead>'
            . '<tbody>' . $lignes . '</tbody></table>';
    }

    /** Champ du motif ; avec $probleme, il sert aussi à décrire un problème (SAV), jusqu'à 1 000 caractères. */
    private static function champMotif(bool $obligatoire = true, bool $probleme = false): string
    {
        $options = '';
        foreach (self::MOTIFS as $motif) {
            $options .= '<option value="' . Html::e($motif) . '"></option>';
        }
        $libelle = $probleme ? 'Motif, ou description du problème (ce qui se passe, sur quelle page, depuis quand)'
            : 'Motif' . ($obligatoire ? ' (obligatoire)' : ' (facultatif, gardé au journal)');

        return '<p><label for="motif">' . Html::e($libelle) . '</label><br>'
            . '<input id="motif" name="motif" type="text" list="motifs"' . ($obligatoire ? ' required minlength="3"' : '')
            . ' maxlength="' . ($probleme ? Demandes::DESCRIPTION_MAX : 300) . '" autocomplete="off">'
            . '<datalist id="motifs">' . $options . '</datalist></p>';
    }
}
