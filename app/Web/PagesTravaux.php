<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Etudiants\Etudiant;
use Portail\Sites\Demandes;
use Portail\Sites\Suppressions;
use Portail\Travaux\FileTravaux;

/**
 * Pages de la V2, lot 1 : « Voir tout » et « Travaux en cours ».
 */
final class PagesTravaux
{
    /** Colonnes toujours affichées dans « Voir tout » (cahier des charges, section Fonctionnalités). */
    private const COLONNES = ['/WP', '/WP2', '/Presta', '/Presta2'];

    private const BADGES = [
        'en_attente' => 'neutre', 'en_cours' => 'warn encours', 'termine' => 'ok', 'annule' => 'neutre',
        'reussi' => 'ok', 'echec' => 'err', 'ignore' => 'neutre',
    ];

    /**
     * @param list<Etudiant> $etudiants
     * @param array<string, array<string, array{logiciel: ?string, version: ?string}>> $sites
     * @param list<string> $sousDomaines
     * @param array<string, string> $horsGel identifiant => raison
     * @param array<string, list<string>> $etiquettes identifiant => demandes en attente
     * @param array<string, string> $supprimes identifiant => date d'effacement (étudiants supprimés, restaurables)
     * @param array<string, array<string, string>> $horsGelWordPress identifiant => dossier => raison (/WP, /WP2)
     */
    public static function voirTout(string $jeton, Compte $moi, array $etudiants, array $sites, array $sousDomaines, ?string $dernierReleve,
        ?int $relevePrevu, ?string $information, array $horsGel = [],
        array $etiquettes = [], array $supprimes = [], array $horsGelWordPress = [], ?string $erreur = null): string
    {
        // Actions groupées (amélioration C3) : pour l'administrateur seulement.
        $groupe = Droits::peut($moi->role, Droits::CERTIFICATS);
        $colonnes = self::COLONNES;
        foreach ($etudiants as $etudiant) {
            foreach (array_keys($sites[$etudiant->identifiant] ?? []) as $dossier) {
                if (!in_array($dossier, $colonnes, true)) {
                    $colonnes[] = $dossier;
                }
            }
        }
        $fiches = Droits::peut($moi->role, Droits::VOIR_FICHE);
        $existants = array_flip($sousDomaines);
        $toutCocher = '<th><input type="checkbox" data-tout-cocher aria-label="Tout cocher" title="Tout cocher"></th>';
        $entete = ($groupe ? $toutCocher : '')
            . '<th>Étudiant</th><th>Classe</th><th>Adresse du site</th>';
        foreach ($colonnes as $dossier) {
            $entete .= '<th>' . Pages::nomSite($dossier) . '</th>';
        }
        $lignes = '';
        foreach ($etudiants as $etudiant) {
            $nom = $fiches ? '<a href="/etudiants/' . $etudiant->id . '">' . Html::e($etudiant->nomComplet) . '</a>' : Html::e($etudiant->nomComplet);
            // Demandes en attente (décision du 05/11/2026) : « Suppression demandée », « Réinitialisation demandée ».
            foreach ($etiquettes[$etudiant->identifiant] ?? [] as $etiquette) {
                $nom .= ' <span class="badge warn">' . Html::e($etiquette) . '</span>';
            }
            $case = $groupe ? '<td><input type="checkbox" name="coches[]" value="' . Html::e($etudiant->identifiant)
                . '" aria-label="' . Html::e($etudiant->nomComplet) . '"></td>' : '';
            $lignes .= '<tr>' . $case . '<td>' . $nom . '</td><td>' . Html::e($etudiant->classe()) . '</td><td>'
                . (isset($existants[$etudiant->identifiant]) ? '<span class="badge ok">existe</span>' : '<span class="badge neutre">pas encore créée</span>') . '</td>';
            foreach ($colonnes as $dossier) {
                $site = $sites[$etudiant->identifiant][$dossier] ?? null;
                $texte = $site === null ? '—' : Html::e(self::nomLogiciel($site['logiciel']) . ($site['version'] === null ? '' : ' ' . $site['version']));
                if ($dossier === '/Presta' && isset($horsGel[$etudiant->identifiant])) {
                    $texte .= ' <span class="badge err">' . Html::e('hors gel : ' . $horsGel[$etudiant->identifiant]) . '</span>';
                }
                $raison = $horsGelWordPress[$etudiant->identifiant][$dossier] ?? null;
                if ($raison !== null) {
                    $texte .= ' <span class="badge err">' . Html::e('hors gel : ' . $raison) . '</span>';
                }
                $lignes .= '<td>' . $texte . '</td>';
            }
            $lignes .= '</tr>';
        }

        // Sous-domaines du serveur sans étudiant dans le portail (anciens étudiants, outils) : identifiants, donc fiche requise.
        $connus = [];
        foreach ($etudiants as $etudiant) {
            $connus[$etudiant->identifiant] = true;
        }
        $orphelins = '';
        if ($fiches) {
            // Le site modèle d'examen a sa propre page : il n'est pas un « sous-domaine sans étudiant ».
            $sans = array_values(array_filter($sousDomaines, static fn (string $i): bool => !isset($connus[$i]) && !isset($supprimes[$i])
                && $i !== \Portail\Sites\SiteModele::IDENTIFIANT));
            if (in_array(\Portail\Sites\SiteModele::IDENTIFIANT, $sousDomaines, true)) {
                $orphelins .= '<p>Site modèle d\'examen : <code>' . \Portail\Sites\SiteModele::IDENTIFIANT . '</code>'
                    . (Droits::peut($moi->role, Droits::SUJETS_EXAMEN) ? ' (<a href="/sites/sujet">sa page</a>)' : '') . '.</p>';
            }
            if ($supprimes !== []) {
                $orphelins .= '<p>Étudiants supprimés, restaurables (page <a href="/demandes">Demandes</a>) : '
                    . implode(', ', array_map(static fn (string $i, string $fin): string => '<code>' . Html::e($i) . '</code> jusqu\'au '
                        . Html::e(gmdate('d/m/Y', (int) strtotime($fin))), array_keys($supprimes), array_values($supprimes))) . '.</p>';
            }
            if ($sans !== []) {
                $orphelins .= '<h2>Sous-domaines sans étudiant dans le portail (' . count($sans) . ')</h2>'
                    . '<p>Anciens étudiants, étudiants pas encore importés, ou outils de l\'établissement. Rien n\'est fait sur eux.</p>'
                    . '<p>' . implode(', ', array_map(static fn (string $i): string => '<code>' . Html::e($i) . '</code>', $sans)) . '</p>';
            }
        }

        $tableau = '<table class="triable"><thead><tr>' . $entete . '</tr></thead><tbody>' . $lignes
            . '</tbody></table>';
        if ($groupe) {
            $tableau = '<form method="post" action="/sites">' . Pages::jeton($jeton)
                // Bouton inactif en premier : la touche Entrée dans le filtre n'envoie aucune action.
                . '<button type="submit" name="action" value="" disabled hidden>Rien</button>'
                . $tableau . self::actionsGroupees($moi) . '</form>';
        }

        return Pages::page('Liste des sites',
            Pages::alerte($erreur, $information)
            . self::blocReleve($jeton, $moi, $dernierReleve, $relevePrevu)
            . '<p>' . count($etudiants) . ' étudiant' . (count($etudiants) > 1 ? 's' : '') . '.</p>'
            . '<p class="discret">Sélectionnez votre liste ou cliquez sur le titre d\'une colonne pour trier.'
            . ($groupe ? ' Cochez des étudiants pour agir sur eux en une fois (en bas de la liste).' : '') . '</p>'
            . $tableau . $orphelins);
    }

    /** Boutons des actions groupées, sous la liste : seulement ceux que le compte a le droit de lancer. */
    private static function actionsGroupees(Compte $moi): string
    {
        $html = '';
        if (Droits::peut($moi->role, Droits::CERTIFICATS)) {
            $html .= '<fieldset><legend>Certificats https</legend><p>Relance le certificat des étudiants cochés.</p>'
                . '<button type="submit" name="action" value="groupe_certificats">Relancer les certificats</button>'
                . '</fieldset>';
        }
        if (Droits::peut($moi->role, Droits::CREER_SITE)) {
            $html .= '<fieldset><legend>Site WordPress /WP</legend>'
                . '<p>Crée le site de ceux qui n\'en ont pas ; accès envoyés par e-mail.</p>'
                . '<button type="submit" name="action" value="groupe_wordpress">Créer les WordPress manquants</button>'
                . '</fieldset>';
        }
        if (Droits::peut($moi->role, Droits::SUPPRIMER)) {
            $html .= '<fieldset><legend>Suppression</legend>'
                . '<p>Page de confirmation d\'abord ; corbeille de 8 jours.</p>'
                . '<button type="submit" name="action" value="groupe_supprimer" class="btn-danger">'
                . 'Supprimer les cochés…</button></fieldset>';
        }

        return '<h2>Actions sur les étudiants cochés</h2><div class="actions-groupees">' . $html . '</div>';
    }

    /**
     * Confirmation d'une suppression lancée depuis la Liste des sites : qui sera supprimé, quoi, puis SUPPRIMER à
     * taper.
     *
     * @param list<array{identifiant: string, nom: string, regime: string, sites: list<string>,
     *                   empechement: ?string}> $etudiants
     */
    public static function confirmerSuppressionGroupee(string $jeton, array $etudiants, ?string $erreur): string
    {
        $lignes = '';
        $caches = '';
        foreach ($etudiants as $etudiant) {
            $sites = implode(', ', array_map(static fn (string $d): string => Pages::nomSiteTexte($d),
                $etudiant['sites']));
            $lignes .= '<tr><td>' . Html::e($etudiant['nom']) . '</td><td>' . Html::e($etudiant['regime']) . '</td>'
                . '<td>' . Html::e($sites === '' ? 'aucun site' : $sites) . '</td><td>'
                . ($etudiant['empechement'] === null ? '<span class="badge warn">sera supprimé</span>'
                    : '<span class="badge neutre">' . Html::e('passé : ' . $etudiant['empechement']) . '</span>')
                . '</td></tr>';
            $caches .= '<input type="hidden" name="coches[]" value="' . Html::e($etudiant['identifiant']) . '">';
        }
        $choix = '<option value="etudiant">L\'étudiant entier : fiche, tous ses sites et son adresse</option>';
        foreach (Suppressions::DOSSIERS as $dossier) {
            $choix .= '<option value="site:' . Html::e($dossier) . '">Seulement : '
                . Html::e(Pages::nomSiteTexte($dossier)) . '</option>';
        }

        return Pages::page('Supprimer les étudiants cochés', Pages::alerte($erreur, null)
            . '<p>' . count($etudiants) . ' étudiant(s) coché(s) dans la Liste des sites. '
            . 'Tout reste restaurable 8 jours (page « Demandes »).</p>'
            . '<table><thead><tr><th>Étudiant</th><th>Régime</th><th>Sites</th><th>Suppression</th></tr></thead>'
            . '<tbody>' . $lignes . '</tbody></table>'
            . '<form method="post" action="/sites">' . Pages::jeton($jeton) . $caches
            . '<input type="hidden" name="action" value="groupe_supprimer_confirme">'
            . '<p><label for="choix">Que supprimer ?</label><br>'
            . '<select id="choix" name="choix">' . $choix . '</select></p>'
            . '<p><label for="motif">Motif (facultatif, gardé avec la sauvegarde)</label><br>'
            . '<input id="motif" name="motif" type="text" maxlength="200"></p>'
            . Pages::saisie('confirmation', 'Pour confirmer, tapez ' . Demandes::MOT_CONFIRMATION . ' en majuscules',
                '')
            . '<button type="submit" class="btn-danger">Supprimer</button></form>'
            . '<p><a href="/sites">Annuler et revenir à la Liste des sites</a></p>');
    }

    /**
     * Avancement d'un travail en langage simple, avec une barre (amélioration B7) : « En cours : 12 sur 33 faits ».
     * $faits compte les étapes finies, réussies ou non.
     */
    public static function avancement(string $etat, int $etapes, int $faits, int $enEchec): string
    {
        $total = max(1, $etapes);
        $faits = min($faits, $total);
        $sur = $faits . ' sur ' . $etapes;
        $echecs = $enEchec > 0 ? ', ' . $enEchec . ' en échec' : '';
        $fait = $sur . ' fait' . ($faits > 1 ? 's' : '');
        $phrase = match ($etat) {
            'en_attente' => 'Pas encore commencé : il démarre dès que le serveur est libre.',
            'en_cours' => 'En cours : ' . $fait . $echecs . '.',
            'annule' => 'Arrêté : ' . $fait . ' avant l’arrêt' . $echecs . '.',
            default => $enEchec > 0
                ? 'Terminé, ' . $enEchec . ' en échec sur ' . $etapes . ' : voir le détail.'
                : 'Terminé : ' . $fait . '.',
        };

        return '<span class="avancement"><progress max="' . $total . '" value="' . $faits . '" aria-hidden="true">'
            . '</progress><span>' . Html::e($phrase) . '</span></span>';
    }

    /**
     * Avancement d'une ligne de la liste des travaux (FileTravaux::lister).
     *
     * @param array{id: int, type: string, etat: string, demande_le: string, demande_par_nom: string, total: int,
     *              faits: int, echecs: int} $travail
     */
    public static function avancementDe(array $travail): string
    {
        return self::avancement($travail['etat'], $travail['total'], $travail['faits'], $travail['echecs']);
    }

    /** @param list<array{id: int, type: string, etat: string, demande_le: string, demande_par_nom: string, total: int, faits: int, echecs: int}> $travaux */
    public static function liste(string $jeton, Compte $moi, array $travaux, ?string $dernierReleve, ?int $relevePrevu, ?string $erreur, ?string $information): string
    {
        $lignes = '';
        foreach ($travaux as $travail) {
            $lignes .= '<tr><td><a href="/travaux/' . $travail['id'] . '">n° ' . $travail['id'] . '</a></td>'
                . '<td>' . Html::e(FileTravaux::LIBELLES[$travail['type']] ?? $travail['type']) . '</td>'
                . '<td>' . Html::e($travail['demande_par_nom']) . '</td><td>' . Html::e(Pages::heureLocale($travail['demande_le'])) . '</td>'
                . '<td>' . self::badge($travail['etat']) . '</td><td>' . self::avancementDe($travail) . '</td></tr>';
        }
        $tableau = $lignes === '' ? '<p>Aucun travail pour l\'instant.</p>'
            : '<table><thead><tr><th>Travail</th><th>Type</th><th>Demandé par</th><th>Le</th><th>État</th><th>Avancement</th></tr></thead><tbody>' . $lignes . '</tbody></table>';

        return Pages::page('Travaux en cours',
            Pages::alerte($erreur, $information)
            . '<p>Le portail confie chaque travail au serveur, qui traite les étudiants un par un, avec une pause entre chacun. '
            . 'Vous pouvez fermer cette page : le travail continue. Cette page se met à jour toute seule tant qu\'un travail est en cours.</p>'
            . self::blocReleve($jeton, $moi, $dernierReleve, $relevePrevu)
            . $tableau);
    }

    /**
     * @param array{id: int, type: string, etat: string, demande_le: string, demande_par: ?int, demande_par_nom: string,
     *              commence_le: ?string, termine_le: ?string,
     *              etapes: list<array{rang: int, libelle: string, etat: string, message: ?string, termine_le: ?string}>} $travail
     */
    public static function detail(string $jeton, array $travail, bool $peutAnnuler, ?string $erreur, ?string $information): string
    {
        $lignes = '';
        foreach ($travail['etapes'] as $etape) {
            $lignes .= '<tr><td>' . $etape['rang'] . '</td><td>' . Html::e($etape['libelle']) . '</td><td>' . self::badge($etape['etat']) . '</td>'
                . '<td>' . Html::e($etape['message'] ?? '') . '</td><td>' . Html::e($etape['termine_le'] === null ? '' : Pages::heureLocale($etape['termine_le'])) . '</td></tr>';
        }
        $etats = array_column($travail['etapes'], 'etat');
        $finis = array_filter($etats,
            static fn (string $e): bool => in_array($e, ['reussi', 'echec', 'ignore', 'annule'], true));
        $echecs = count(array_keys($etats, 'echec', true));
        $avancement = self::avancement($travail['etat'], count($etats), count($finis), $echecs);
        $infos = '<p>Demandé par ' . Html::e($travail['demande_par_nom']) . ' le ' . Html::e(Pages::heureLocale($travail['demande_le'])) . '. État : ' . self::badge($travail['etat'])
            . ($travail['termine_le'] === null ? ''
                : ' (le ' . Html::e(Pages::heureLocale($travail['termine_le'])) . ')')
            . '</p><p>' . $avancement . '</p>';
        $annuler = $peutAnnuler
            ? '<form method="post" action="/travaux/' . $travail['id'] . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="annuler">'
                . '<p>Arrêter annule les étapes pas encore commencées. L\'étape en cours, s\'il y en a une, va jusqu\'au bout.</p>'
                . '<button type="submit">Arrêter ce travail</button></form>'
            : '';

        return Pages::page('Travail n° ' . $travail['id'] . ' : ' . (FileTravaux::LIBELLES[$travail['type']] ?? $travail['type']),
            Pages::alerte($erreur, $information) . $infos
            . '<table><thead><tr><th>N°</th><th>Étape</th><th>État</th><th>Résultat</th><th>Terminée le</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . $annuler . '<p><a href="/travaux">Retour aux travaux</a> · <a href="/">Accueil</a></p>');
    }

    private static function blocReleve(string $jeton, Compte $moi, ?string $dernierReleve, ?int $relevePrevu): string
    {
        $texte = '<p>Liste des sites mise à jour le : ' . ($dernierReleve === null ? 'aucun' : Html::e(Pages::heureLocale($dernierReleve))) . '.';
        if ($relevePrevu !== null) {
            return $texte . ' Un nouveau relevé est prévu : <a href="/travaux/' . $relevePrevu . '">travail n° ' . $relevePrevu . '</a>.</p>';
        }
        if (!Droits::peut($moi->role, Droits::ACTUALISER_RELEVE)) {
            return $texte . '</p>';
        }

        return $texte . '</p><form method="post" action="/travaux">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="actualiser_releve">'
            . '<button type="submit">Mettre à jour la liste des sites</button> <span class="discret">Lecture seule : rien n\'est modifié sur le serveur. Une à deux minutes.</span></form>';
    }

    private static function badge(string $etat): string
    {
        return '<span class="badge ' . (self::BADGES[$etat] ?? 'neutre') . '">' . Html::e(FileTravaux::ETATS[$etat] ?? $etat) . '</span>';
    }

    private static function nomLogiciel(?string $logiciel): string
    {
        return match ($logiciel) {
            'wordpress' => 'WordPress',
            'prestashop' => 'PrestaShop',
            default => 'autre',
        };
    }
}
