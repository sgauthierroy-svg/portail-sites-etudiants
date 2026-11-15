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
        $contenu = Pages::alerte($erreur, $information);
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

        return '<h2>Demander à l\'administrateur</h2>'
            . '<p>Suppression ou réinitialisation : l\'administrateur reçoit votre demande, la valide ou la refuse avec un commentaire. '
            . 'Vous êtes prévenu par e-mail, et vous la suivez dans « Demandes ».</p>'
            . '<form method="post" action="' . Html::e($action) . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="demander">'
            . '<p><label for="choix">Ce que vous demandez</label><br><select id="choix" name="choix" required>' . $options . '</select></p>'
            . self::champMotif()
            . '<button type="submit">Envoyer la demande</button></form>';
    }

    /**
     * Suppression directe sur la fiche (administrateur) : choix du site ou de l'étudiant, confirmation en tapant l'identifiant.
     *
     * @param list<array{dossier: string, logiciel: string, version: ?string, dossier_admin: ?string, cree_par_portail: bool, date: string}> $sites
     */
    public static function suppressionFiche(string $jeton, string $action, string $identifiant, array $sites, bool $prevu): string
    {
        $html = '<h2>Supprimer (administrateur)</h2>';
        if ($prevu) {
            return $html . '<p><span class="badge neutre">un travail est déjà prévu pour cet étudiant</span> — voir <a href="/travaux">Travaux en cours</a>.</p>';
        }
        $options = '';
        foreach ($sites as $site) {
            $options .= '<label class="choix"><input type="radio" name="choix" value="site:' . Html::e($site['dossier']) . '" required> le site '
                . Html::e($site['dossier']) . ' (' . Html::e(($site['logiciel'] === 'prestashop' ? 'PrestaShop' : 'WordPress') . ($site['version'] === null ? '' : ' ' . $site['version']))
                . ') et sa base</label><br>';
        }
        $options .= '<label class="choix"><input type="radio" name="choix" value="etudiant" required> <strong>l\'étudiant</strong> : sa fiche, tous ses sites, '
            . 'toutes ses bases et son sous-domaine <code>' . Html::e($identifiant) . '.bts-sup-sallanches.com</code></label>';

        return $html
            . '<p>Rien n\'est effacé tout de suite : tout est gardé <strong>' . MiseALecart::JOURS . ' jours</strong> (bouton « Restaurer » de la page '
            . '<a href="/demandes">Demandes</a>), puis effacé définitivement, sous-domaine compris pour un étudiant.</p>'
            . '<form method="post" action="' . Html::e($action) . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="supprimer">'
            . '<fieldset><legend>Supprimer</legend>' . $options . '</fieldset>'
            . '<p><label for="confirmation">Pour confirmer, tapez l\'identifiant de l\'étudiant (<code>' . Html::e($identifiant) . '</code>)</label><br>'
            . '<input id="confirmation" name="confirmation" type="text" autocomplete="off" required maxlength="63"></p>'
            . '<button type="submit" class="btn-danger">Supprimer</button></form>';
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

        return '<h2>Demandes en attente</h2><ul>' . $lignes . '</ul>'
            . ($traiter ? '<p><a href="/demandes">Les traiter</a></p>' : '');
    }

    /** @param list<Demande> $demandes */
    private static function aTraiter(string $jeton, array $demandes): string
    {
        $html = '<h2>À traiter (' . count($demandes) . ')</h2>';
        if ($demandes === []) {
            return $html . '<p>Aucune demande en attente.</p>';
        }
        $groupes = [];
        foreach ($demandes as $demande) {
            $groupes[$demande['type']][] = $demande;
        }
        $tableaux = '';
        foreach ($groupes as $type => $liste) {
            $lignes = '';
            foreach ($liste as $demande) {
                $nom = $demande['nom'] ?? $demande['identifiant'] . ' (plus dans le portail)';
                $lien = $demande['etudiant_id'] === null ? Html::e($nom) : '<a href="/etudiants/' . $demande['etudiant_id'] . '">' . Html::e($nom) . '</a>';
                $lignes .= '<tr><td><input type="checkbox" name="coches[]" value="' . $demande['id'] . '" aria-label="Choisir"></td>'
                    . '<td>' . $lien . '</td><td>' . Html::e($demande['classe'] ?? '') . '</td>'
                    . '<td>' . Html::e($demande['dossier'] ?? ($type === 'supprimer_etudiant' ? 'tout, sous-domaine compris' : '')) . '</td>'
                    . '<td>' . Html::e($demande['motif']) . '</td>'
                    . '<td>' . Html::e($demande['demande_par_nom']) . '</td><td>' . Html::e(Pages::heureLocale($demande['demande_le'])) . '</td></tr>';
            }
            $tableaux .= '<h3>' . Html::e(Demandes::LIBELLES[$type] ?? $type) . ' (' . count($liste) . ')</h3>'
                . '<table class="triable"><thead><tr><th><input type="checkbox" data-tout-cocher aria-label="Tout cocher" title="Tout cocher"></th>'
                . '<th>Étudiant</th><th>Classe</th><th>Site</th><th>Motif</th><th>Demandé par</th><th>Le</th></tr></thead><tbody>' . $lignes . '</tbody></table>';
        }

        return $html
            . '<p>Cochez une demande (une par une) ou plusieurs (par lot, par exemple toute une session en fin de 2e année), puis validez ou refusez. '
            . 'Une demande validée lance le travail habituel ; une suppression reste restaurable ' . MiseALecart::JOURS . ' jours.</p>'
            . '<form method="post" action="/demandes">' . Pages::jeton($jeton)
            . $tableaux
            . '<p><label for="confirmation">Suppressions cochées : tapez <code>' . Demandes::MOT_CONFIRMATION . '</code> pour confirmer</label><br>'
            . '<input id="confirmation" name="confirmation" type="text" autocomplete="off" maxlength="20"></p>'
            . '<p><label for="commentaire">Commentaire (obligatoire pour un refus, transmis au demandeur)</label><br>'
            . '<input id="commentaire" name="commentaire" type="text" maxlength="300"></p>'
            . '<p class="boutons"><button type="submit" name="action" value="valider" class="btn-main">Valider la sélection</button> '
            . '<button type="submit" name="action" value="refuser">Refuser la sélection</button></p></form>';
    }

    /** @param list<array{chemin: string, identifiant: string, dossier: string, motif: string, mis_le: string, expire_le: string, nom: string}> $restaurables */
    private static function restaurables(string $jeton, array $restaurables): string
    {
        $html = '<h2>Suppressions restaurables (' . MiseALecart::JOURS . ' jours)</h2>';
        if ($restaurables === []) {
            return $html . '<p>Aucune suppression récente.</p>';
        }
        $lignes = '';
        foreach ($restaurables as $ecart) {
            $quoi = $ecart['dossier'] === MiseALecart::SOUS_DOMAINE ? 'l\'étudiant (tout)' : 'le site ' . $ecart['dossier'];
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
            return $html . '<p>Aucune demande. Pour en faire une : fiche de l\'étudiant, rubrique « Demander à l\'administrateur ».</p>';
        }
        $lignes = '';
        foreach ($miennes as $demande) {
            $etat = '<span class="badge ' . match ($demande['etat']) { 'validee' => 'ok', 'refusee' => 'err', 'en_attente' => 'warn', default => 'neutre' } . '">'
                . Html::e(Demandes::ETATS[$demande['etat']] ?? $demande['etat']) . '</span>';
            $suite = match (true) {
                $demande['etat'] === 'en_attente' => '<form method="post" action="/demandes">' . Pages::jeton($jeton)
                    . '<input type="hidden" name="action" value="annuler"><input type="hidden" name="id" value="' . $demande['id'] . '">'
                    . '<button type="submit">Annuler</button></form>',
                $demande['travail_id'] !== null => '<a href="/travaux/' . $demande['travail_id'] . '">travail n° ' . $demande['travail_id'] . '</a>',
                default => Html::e($demande['commentaire'] ?? ''),
            };
            $lignes .= '<tr><td>' . Html::e($demande['nom'] ?? $demande['identifiant']) . '</td>'
                . '<td>' . Html::e((Demandes::LIBELLES[$demande['type']] ?? $demande['type']) . ($demande['dossier'] === null ? '' : ' ' . $demande['dossier'])) . '</td>'
                . '<td>' . Html::e($demande['motif']) . '</td><td>' . Html::e(Pages::heureLocale($demande['demande_le'])) . '</td>'
                . '<td>' . $etat . ($demande['traitee_par_nom'] === null ? '' : ' <span class="discret">' . Html::e($demande['traitee_par_nom']) . '</span>') . '</td>'
                . '<td>' . $suite . '</td></tr>';
        }

        return $html . '<table class="triable"><thead><tr><th>Étudiant</th><th>Demande</th><th>Motif</th><th>Le</th><th>État</th><th>Suite</th></tr></thead>'
            . '<tbody>' . $lignes . '</tbody></table>';
    }

    private static function champMotif(): string
    {
        $options = '';
        foreach (self::MOTIFS as $motif) {
            $options .= '<option value="' . Html::e($motif) . '"></option>';
        }

        return '<p><label for="motif">Motif (obligatoire)</label><br>'
            . '<input id="motif" name="motif" type="text" list="motifs" required minlength="3" maxlength="300" autocomplete="off">'
            . '<datalist id="motifs">' . $options . '</datalist></p>';
    }
}
