<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Etudiants\Etudiant;
use Portail\Travaux\FileTravaux;

/**
 * Pages de la V2, lot 1 : « Voir tout » et « Travaux en cours ».
 */
final class PagesTravaux
{
    /** Colonnes toujours affichées dans « Voir tout » (cahier des charges, section Fonctionnalités). */
    private const COLONNES = ['/WP', '/WP2', '/Presta', '/Presta2'];

    private const BADGES = [
        'en_attente' => 'neutre', 'en_cours' => 'warn', 'termine' => 'ok', 'annule' => 'neutre',
        'reussi' => 'ok', 'echec' => 'err', 'ignore' => 'neutre',
    ];

    /**
     * @param list<Etudiant> $etudiants
     * @param array<string, array<string, array{logiciel: ?string, version: ?string}>> $sites
     * @param list<string> $sousDomaines
     * @param list<array{id: int, nom: string, actif: bool}> $regimes
     * @param list<array{id: int, annee: int, version_wp: ?string, active: bool}> $sessions
     * @param array<string, string> $horsGel identifiant => raison
     */
    public static function voirTout(string $jeton, Compte $moi, array $etudiants, array $sites, array $sousDomaines, ?string $dernierReleve,
        ?int $relevePrevu, array $regimes, array $sessions, ?int $regimeChoisi, ?int $sessionChoisie, ?string $information, array $horsGel = []): string
    {
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
        $entete = '<th>Étudiant</th><th>Classe</th><th>Sous-domaine</th>';
        foreach ($colonnes as $dossier) {
            $entete .= '<th>' . Html::e($dossier) . '</th>';
        }
        $lignes = '';
        foreach ($etudiants as $etudiant) {
            $nom = $fiches ? '<a href="/etudiants/' . $etudiant->id . '">' . Html::e($etudiant->nomComplet) . '</a>' : Html::e($etudiant->nomComplet);
            $lignes .= '<tr><td>' . $nom . '</td><td>' . Html::e($etudiant->classe()) . '</td><td>'
                . (isset($existants[$etudiant->identifiant]) ? '<span class="badge ok">oui</span>' : '<span class="badge neutre">absent</span>') . '</td>';
            foreach ($colonnes as $dossier) {
                $site = $sites[$etudiant->identifiant][$dossier] ?? null;
                $texte = $site === null ? '—' : Html::e(self::nomLogiciel($site['logiciel']) . ($site['version'] === null ? '' : ' ' . $site['version']));
                if ($dossier === '/Presta' && isset($horsGel[$etudiant->identifiant])) {
                    $texte .= ' <span class="badge err">' . Html::e('hors gel : ' . $horsGel[$etudiant->identifiant]) . '</span>';
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
        if ($fiches && $regimeChoisi === null && $sessionChoisie === null) {
            $sans = array_values(array_filter($sousDomaines, static fn (string $i): bool => !isset($connus[$i])));
            if ($sans !== []) {
                $orphelins = '<h2>Sous-domaines sans étudiant dans le portail (' . count($sans) . ')</h2>'
                    . '<p>Anciens étudiants, étudiants pas encore importés, ou outils de l\'établissement. Rien n\'est fait sur eux.</p>'
                    . '<p>' . implode(', ', array_map(static fn (string $i): string => '<code>' . Html::e($i) . '</code>', $sans)) . '</p>';
            }
        }

        return Pages::page('Voir tout',
            Pages::alerte(null, $information)
            . self::blocReleve($jeton, $moi, $dernierReleve, $relevePrevu)
            . '<form method="post" action="/sites">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="filtrer">'
            . '<label for="regime">Régime</label> <select id="regime" name="regime">' . self::options(array_column($regimes, 'nom', 'id'), $regimeChoisi, 'Tous les régimes') . '</select> '
            . '<label for="session">Session</label> <select id="session" name="session">' . self::options(array_map(static fn (int $a): string => 'Session ' . $a, array_column($sessions, 'annee', 'id')), $sessionChoisie, 'Toutes les sessions') . '</select> '
            . '<button type="submit">Afficher</button></form>'
            . '<p>' . count($etudiants) . ' étudiant' . (count($etudiants) > 1 ? 's' : '') . '. Même filtre que la liste des étudiants.</p>'
            . '<table class="triable"><thead><tr>' . $entete . '</tr></thead><tbody>' . $lignes . '</tbody></table>'
            . $orphelins);
    }

    /** @param list<array{id: int, type: string, etat: string, demande_le: string, demande_par_nom: string, total: int, faits: int, echecs: int}> $travaux */
    public static function liste(string $jeton, Compte $moi, array $travaux, ?string $dernierReleve, ?int $relevePrevu, ?string $erreur, ?string $information): string
    {
        $lignes = '';
        foreach ($travaux as $travail) {
            $avancement = $travail['faits'] . ' / ' . $travail['total'] . ($travail['echecs'] > 0 ? ' – ' . $travail['echecs'] . ' échec(s)' : '');
            $lignes .= '<tr><td><a href="/travaux/' . $travail['id'] . '">n° ' . $travail['id'] . '</a></td>'
                . '<td>' . Html::e(FileTravaux::LIBELLES[$travail['type']] ?? $travail['type']) . '</td>'
                . '<td>' . Html::e($travail['demande_par_nom']) . '</td><td>' . Html::e(Pages::heureLocale($travail['demande_le'])) . '</td>'
                . '<td>' . self::badge($travail['etat']) . '</td><td>' . Html::e($avancement) . '</td></tr>';
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
        $infos = '<p>Demandé par ' . Html::e($travail['demande_par_nom']) . ' le ' . Html::e(Pages::heureLocale($travail['demande_le'])) . '. État : ' . self::badge($travail['etat'])
            . ($travail['termine_le'] === null ? '' : ' (le ' . Html::e(Pages::heureLocale($travail['termine_le'])) . ')') . '</p>';
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
        $texte = '<p>Dernier relevé du serveur : ' . ($dernierReleve === null ? 'aucun' : Html::e(Pages::heureLocale($dernierReleve))) . '.';
        if ($relevePrevu !== null) {
            return $texte . ' Un nouveau relevé est prévu : <a href="/travaux/' . $relevePrevu . '">travail n° ' . $relevePrevu . '</a>.</p>';
        }
        if (!Droits::peut($moi->role, Droits::ACTUALISER_RELEVE)) {
            return $texte . '</p>';
        }

        return $texte . '</p><form method="post" action="/travaux">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="actualiser_releve">'
            . '<button type="submit">Actualiser le relevé</button> <span class="discret">Lecture seule : rien n\'est modifié sur le serveur. Une à deux minutes.</span></form>';
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

    /** @param array<int, string> $choix identifiant => libellé */
    private static function options(array $choix, ?int $choisi, string $tous): string
    {
        $options = '<option value="">' . Html::e($tous) . '</option>';
        foreach ($choix as $id => $libelle) {
            $options .= '<option value="' . $id . '"' . ($id === $choisi ? ' selected' : '') . '>' . Html::e($libelle) . '</option>';
        }

        return $options;
    }
}
