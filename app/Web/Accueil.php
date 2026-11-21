<?php

declare(strict_types=1);

namespace Portail\Web;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Etudiants\Etudiant;
use Portail\Sites\Demandes;
use Portail\Sites\Releve;

/**
 * Accueil propre à chaque rôle (améliorations D1, D5, D6, D11 à D14) : à la place des tuiles qui répétaient le
 * menu, l'état des classes et les gestes fréquents du rôle. Enseignant : ses classes et la création de sites ;
 * administratif : ses demandes et les dernières personnes ajoutées ; direction : tableau de bord en lecture seule et
 * entrée « Secours » ; administrateur : les classes, sous son bloc « À faire » (AFaire).
 *
 * @psalm-type Classe = array{session: int, etudiants: int, avec_wp: int, sans_wp: int, wp2: int, boutiques: int,
 *                            a_surveiller: int, demandes: int}
 * @psalm-type Geste = array{adresse: string, titre: string, texte: string}
 */
final class Accueil
{
    /** Nombre de personnes ajoutées montrées à l'administratif. */
    private const DERNIERS_AJOUTS = 5;

    /**
     * État des classes des sessions actives, d'après la liste des sites (dernier relevé et sites créés par le portail).
     *
     * @param list<Etudiant> $etudiants
     * @param list<int> $sessionsActives années des sessions actives
     * @return list<Classe>
     */
    public static function classes(PDO $pdo, array $etudiants, array $sessionsActives): array
    {
        $sites = Releve::parIdentifiant($pdo);
        $crees = $pdo->query("SELECT identifiant, dossier FROM sites WHERE identifiant <> 'sujet'");
        foreach (Lecture::lignes($crees) as $ligne) {
            $identifiant = (string) Lecture::texte($ligne['identifiant']);
            $sites[$identifiant][(string) Lecture::texte($ligne['dossier'])] ??= ['logiciel' => null, 'version' => null];
        }
        $surveiller = array_flip(array_merge(array_keys(Releve::horsGel($pdo)),
            array_keys(Releve::wordpressHorsGel($pdo))));
        $demandes = (new Demandes($pdo))->etiquettes();
        $classes = [];
        foreach ($etudiants as $etudiant) {
            if (!in_array($etudiant->session, $sessionsActives, true)) {
                continue;
            }
            $classe = $classes[$etudiant->session] ?? ['session' => $etudiant->session, 'etudiants' => 0,
                'avec_wp' => 0, 'sans_wp' => 0, 'wp2' => 0, 'boutiques' => 0, 'a_surveiller' => 0, 'demandes' => 0];
            $siens = $sites[$etudiant->identifiant] ?? [];
            $classe['etudiants']++;
            $classe[isset($siens['/WP']) ? 'avec_wp' : 'sans_wp']++;
            $classe['wp2'] += isset($siens['/WP2']) ? 1 : 0;
            $classe['boutiques'] += isset($siens['/Presta']) ? 1 : 0;
            $classe['a_surveiller'] += isset($surveiller[$etudiant->identifiant]) ? 1 : 0;
            $classe['demandes'] += isset($demandes[$etudiant->identifiant]) ? 1 : 0;
            $classes[$etudiant->session] = $classe;
        }
        ksort($classes);

        return array_values($classes);
    }

    /**
     * Bloc de l'accueil selon le rôle, placé sous le bloc « À faire » de l'administrateur.
     *
     * @param list<Classe> $classes
     * @param list<Etudiant> $derniers personnes ajoutées récemment (administratif)
     * @param int $mesDemandes demandes en attente faites par ce compte
     */
    public static function html(Compte $compte, array $classes, ?string $releve, array $derniers,
        int $mesDemandes): string
    {
        $peut = static fn (string $droit): bool => Droits::peut($compte->role, $droit);
        if ($compte->role === 'consultation') {
            return '<p class="vous-consultez"><span class="badge neutre">Vous consultez</span> '
                . 'Tableau de bord en lecture seule : rien ne peut être modifié depuis cette page.</p>'
                . self::secours()
                . self::tableauClasses($classes, $releve, false);
        }
        $html = '';
        if ($compte->role === 'administratif') {
            $html .= self::mesDemandes($mesDemandes) . self::derniersAjouts($derniers, $peut(Droits::VOIR_FICHE));
        }
        if ($compte->role === 'enseignant' && $mesDemandes > 0) {
            $html .= self::mesDemandes($mesDemandes);
        }

        return $html . self::gestes($compte) . self::tableauClasses($classes, $releve, $peut(Droits::CREER_SITE));
    }

    /**
     * Gestes fréquents du rôle, en gros boutons (D6 : créer des sites est un geste récurrent de l'enseignant).
     *
     * @return list<Geste>
     */
    public static function listeGestes(Compte $compte): array
    {
        $peut = static fn (string $droit): bool => Droits::peut($compte->role, $droit);
        $gestes = [];
        if ($compte->role === 'administratif') {
            $gestes[] = ['adresse' => $peut(Droits::IMPORTER) ? '/etudiants/import' : '/etudiants/nouveau',
                'titre' => 'Un étudiant arrive', 'texte' => 'L’ajouter au portail (liste ou un seul).'];
            $gestes[] = ['adresse' => '/etudiants', 'titre' => 'Changer de classe ou de session',
                'texte' => 'Sa fiche, « Actions sur cet étudiant » : changer de régime ou de session.'];
            $gestes[] = ['adresse' => '/regimes-sessions', 'titre' => 'Préparer la rentrée',
                'texte' => 'Régimes, sessions, puis import de la liste.'];
        }
        if ($peut(Droits::CREER_SITE)) {
            $gestes[] = ['adresse' => '/sites/wordpress', 'titre' => 'Installer des sites WordPress',
                'texte' => 'Pour les étudiants qui n’en ont pas encore.'];
        }
        if ($peut(Droits::CREER_SITE) && $compte->role !== 'administratif') {
            $gestes[] = ['adresse' => '/sites/prestashop', 'titre' => 'Installer des boutiques',
                'texte' => 'PrestaShop, en 2e année.'];
        }
        if ($peut(Droits::SUJETS_EXAMEN)) {
            $gestes[] = ['adresse' => '/sites/sujet', 'titre' => 'Préparer un BTS blanc',
                'texte' => 'Charger le sujet, le déployer, clôturer.'];
        }
        if ($peut(Droits::DEMANDER) && !$peut(Droits::TRAITER_DEMANDES)) {
            $gestes[] = ['adresse' => '/demandes/nouvelle', 'titre' => 'Demander au service informatique',
                'texte' => 'Une suppression, ou un problème (SAV).'];
        }

        return $gestes;
    }

    /** Page « Secours » de la direction (D14) : pas à pas, pour un besoin rare et urgent. */
    public static function pageSecours(): string
    {
        return Pages::page('Secours', Pages::aide('Pour un compte bloqué, ou quand le service informatique est absent.')
            . '<h2>Un compte est bloqué, ou quelqu’un a perdu son téléphone</h2><ol class="pas-a-pas">'
            . '<li>Ouvrez <a href="/comptes">Comptes du portail</a>, puis le compte de la personne.</li>'
            . '<li>Mot de passe oublié : « Réinitialiser le mot de passe ». Un mot de passe provisoire s’affiche '
            . 'une seule fois : donnez-le de vive voix, jamais par e-mail.</li>'
            . '<li>Téléphone perdu ou changé : « Réinitialiser le code à 6 chiffres ». À la connexion suivante, '
            . 'la personne relie son nouveau téléphone.</li>'
            . '<li>Départ ou doute : « Désactiver » le compte.</li></ol>'
            . '<p>Chaque action de secours prévient le service informatique par e-mail, '
            . 'qui vérifie qu’elle était attendue.</p>'
            . '<h2>Le service informatique est absent et le portail ne répond plus</h2><ol class="pas-a-pas">'
            . '<li>Ne supprimez rien et ne relancez rien sur le serveur.</li>'
            . '<li>Ouvrez le dossier de continuité, que la direction détient : il dit qui appeler et comment '
            . 'reprendre la main sur le serveur.</li>'
            . '<li>Notez l’heure et le message affiché (capture d’écran) pour le service informatique.</li></ol>'
            . '<p><a href="/journal">Journal</a> : qui a fait quoi, quand.</p>');
    }

    /** Entrée « Secours » toujours visible sur l'accueil de la direction (D14). */
    private static function secours(): string
    {
        return '<a class="a-faire-ligne secours" href="/secours"><span class="badge warn">Secours</span>'
            . '<span class="a-faire-texte"><strong>Un compte est bloqué ou le service informatique est absent</strong>'
            . '<span>Ce qu’il faut faire, pas à pas.</span></span></a>';
    }

    /** Lien vers les demandes en attente faites par ce compte. */
    private static function mesDemandes(int $nombre): string
    {
        if ($nombre === 0) {
            return '<p>Aucune demande en attente au service informatique. '
                . '<a href="/demandes">Voir mes demandes</a></p>';
        }

        return '<p><a href="/demandes"><strong>' . $nombre . ' demande' . ($nombre > 1 ? 's' : '')
            . ' en attente</strong></a> au service informatique : vous êtes prévenu par e-mail de la réponse.</p>';
    }

    /**
     * Dernières personnes ajoutées au portail (administratif, D12).
     *
     * @param list<Etudiant> $derniers
     */
    private static function derniersAjouts(array $derniers, bool $fiches): string
    {
        if ($derniers === []) {
            return '';
        }
        $lignes = '';
        foreach (array_slice($derniers, 0, self::DERNIERS_AJOUTS) as $etudiant) {
            $nom = $fiches ? '<a href="/etudiants/' . $etudiant->id . '">' . Html::e($etudiant->nomComplet) . '</a>'
                : Html::e($etudiant->nomComplet);
            $lignes .= '<li>' . $nom . ' – ' . Html::e($etudiant->classe()) . ' – ajouté le '
                . Html::e(Pages::heureLocale($etudiant->ajouteLe)) . '</li>';
        }

        return '<section aria-labelledby="t-ajouts"><h2 id="t-ajouts">Dernières personnes ajoutées</h2><ul>' . $lignes
            . '</ul></section>';
    }

    /** Gestes fréquents en gros boutons. */
    private static function gestes(Compte $compte): string
    {
        $liens = '';
        foreach (self::listeGestes($compte) as $geste) {
            $liens .= '<a class="geste" href="' . Html::e($geste['adresse']) . '"><strong>' . Html::e($geste['titre'])
                . '</strong><span>' . Html::e($geste['texte']) . '</span></a>';
        }

        return $liens === '' ? '' : '<section aria-labelledby="t-gestes"><h2 id="t-gestes">Gestes fréquents</h2>'
            . '<div class="gestes">' . $liens . '</div></section>';
    }

    /**
     * État des classes (D5, D13) : une ligne par session active.
     *
     * @param list<Classe> $classes
     */
    private static function tableauClasses(array $classes, ?string $releve, bool $creer): string
    {
        if ($classes === []) {
            return '';
        }
        $lignes = '';
        foreach ($classes as $c) {
            $lien = $creer ? ' <a href="/sites/wordpress">Créer ces sites</a>' : '';
            $sans = $c['sans_wp'] === 0 ? '0' : $c['sans_wp'] . $lien;
            $lignes .= '<tr><td>Session ' . $c['session'] . '</td><td>' . $c['etudiants'] . '</td>'
                . '<td>' . $c['avec_wp'] . '</td>'
                . '<td>' . $sans . '</td><td>' . $c['wp2'] . '</td><td>' . $c['boutiques'] . '</td>'
                . '<td>' . ($c['a_surveiller'] === 0 ? '<span class="badge ok">0</span>'
                    : '<a href="/sites"><span class="badge err">' . $c['a_surveiller'] . '</span></a>') . '</td>'
                . '<td>' . $c['demandes'] . '</td></tr>';
        }
        $date = $releve === null ? 'pas encore mise à jour' : 'mise à jour le ' . Pages::heureLocale($releve);

        return '<section aria-labelledby="t-classes"><h2 id="t-classes">État des classes</h2>'
            . '<p class="discret">Sessions en cours, d’après la liste des sites (' . Html::e($date) . ').</p>'
            . '<div class="tableau-defilant"><table><thead><tr><th>Classe</th><th>Étudiants</th><th>Site WordPress</th>'
            . '<th>Sans site WordPress</th><th>Site d’examen</th><th>Boutique</th><th>À surveiller</th>'
            . '<th>Demandes en cours</th></tr></thead><tbody>' . $lignes . '</tbody></table></div></section>';
    }
}
