<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Securite\Base32;
use Portail\Securite\MotsDePasse;
use Portail\Securite\QrCode;
use Portail\Securite\Totp;

/**
 * Pages HTML du portail. Tout texte variable passe par Html::e().
 * (Habillage aux couleurs de Sup'Sallanches : lot 6.)
 */
final class Pages
{
    /** @var array{compte: Compte, jeton: string, chemin: string}|null Utilisateur connecté de la requête en cours (menu et bandeau) */
    private static ?array $cadre = null;

    /** Pages d'un utilisateur connecté : menu latéral et bandeau (maquettes du 05/11/2026). */
    public static function cadre(Compte $compte, string $jeton, string $chemin): void
    {
        self::$cadre = ['compte' => $compte, 'jeton' => $jeton, 'chemin' => $chemin];
    }

    /**
     * Une phrase d'aide en tête de chaque page (décision du 17/11/2026), dans les mots de tous les jours.
     * Une page qui a sa propre phrase d'aide (Pages::aide) garde la sienne.
     */
    private const AIDES = [
        'Liste des étudiants' => 'Tous les étudiants, par régime et session. Cliquez un nom pour ouvrir sa fiche : ses sites et ce qu\'on peut faire pour lui.',
        'Liste des sites' => 'Une ligne par étudiant, une colonne par site. La liste se met à jour seule chaque lundi ; le bouton la met à jour tout de suite.',
        'Créer des WordPress' => 'Pour un nouvel étudiant : son adresse et son site WordPress (cours). Il reçoit ses accès par e-mail.',
        'Créer des boutiques PrestaShop' => 'La boutique (cours) des étudiants d\'une session, en 2e année. Chacun reçoit ses accès par e-mail.',
        'Demandes' => 'Les demandes de suppression ou de réinitialisation adressées à l\'administrateur, et leur réponse.',
        'Travaux en cours' => 'Ce que le serveur est en train de faire, étudiant par étudiant. Vous pouvez fermer la page : le travail continue.',
        'Régimes et sessions' => 'Les classes (FI, ALT) et les promotions. À chaque rentrée, créez la session de la nouvelle promotion avant d\'ajouter ses étudiants.',
        'Certificats' => 'Le cadenas https de chaque site. Un certificat non reconnu fait afficher « site non sécurisé » : relancez-le ici.',
        'Comptes du portail' => 'Les personnes qui ont accès au portail. Un départ : désactivez le compte, puis supprimez-le si besoin.',
        'Journal' => 'Qui a fait quoi, quand, et avec quel résultat.',
    ];

    /** Demandes en attente, affichées à côté de « Demandes » dans le menu de l'administrateur (lot 5, paquet B). */
    public static int $demandesEnAttente = 0;

    /** Rechargement automatique de la page, en secondes (0 : jamais). Sert au suivi des travaux. */
    public static int $rafraichir = 0;

    public static function sansCadre(): void
    {
        self::$cadre = null;
        self::$rafraichir = 0;
        self::$demandesEnAttente = 0;
    }

    public static function page(string $titre, string $contenu): string
    {
        // Tableaux dans un cadre qui défile sur téléphone (le contenu variable est déjà échappé : aucun « <table » ne vient d'un visiteur).
        $contenu = str_replace(['<table', '</table>'], ['<div class="tableau"><table', '</table></div>'], $contenu);
        $tete = '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . (self::$rafraichir > 0 ? '<meta http-equiv="refresh" content="' . self::$rafraichir . '">' : '')
            . '<link rel="icon" type="image/png" href="/favicon.png">'
            . '<link rel="stylesheet" href="/style.css">'
            . '<script src="/tri.js" defer></script>'
            . '<title>' . Html::e($titre) . ' – Portail Sup\'Sallanches</title></head>';
        $marque = '<a class="marque" href="/"><img src="/logo.png" alt="Enseignement Catholique de Sallanches – Sup’Sallanches" width="240" height="160">'
            . '<span class="marque-sous">Portail des sites étudiants</span></a>';
        if (self::$cadre === null) {
            return $tete . '<body class="seul"><main class="carte">' . $marque . '<h1>' . Html::e($titre) . '</h1>' . $contenu . '</main></body></html>';
        }
        $compte = self::$cadre['compte'];
        $initiales = '';
        foreach (array_slice(preg_split('/[\s\-]+/u', trim($compte->nomAffiche)) ?: [], 0, 2) as $mot) {
            $initiales .= mb_strtoupper(mb_substr($mot, 0, 1, 'UTF-8'), 'UTF-8');
        }
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')));
        $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $aujourdhui = ucfirst($jours[(int) $date->format('w')]) . ' ' . $date->format('j') . ' ' . $mois[(int) $date->format('n') - 1] . ' ' . $date->format('Y');

        return $tete . '<body><div class="cadre"><aside class="menu">' . $marque . self::menu($compte, self::$cadre['chemin']) . '</aside>'
            . '<div class="principal"><header class="bandeau"><span class="date">' . Html::e($aujourdhui) . '</span>'
            . '<div class="qui"><span class="avatar" aria-hidden="true">' . Html::e($initiales) . '</span>'
            . '<span class="qui-texte"><strong>' . Html::e($compte->nomAffiche) . '</strong><span>' . Html::e($compte->libelleRole()) . '</span></span>'
            . self::boutonDeconnexion(self::$cadre['jeton']) . '</div></header>'
            . '<main class="contenu"><h1>' . Html::e($titre) . '</h1>'
            . (isset(self::AIDES[$titre]) && !str_contains($contenu, 'class="aide"') ? self::aide(self::AIDES[$titre]) : '')
            . $contenu . '</main></div></div></body></html>';
    }

    private const ICONES = [
        'accueil' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'etudiants' => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6"/><path d="M16 4a4 4 0 010 8"/><path d="M22 21c0-3-2-5-4-5.5"/>',
        'import' => '<path d="M12 15V3"/><path d="M7 8l5-5 5 5"/><path d="M4 15v5h16v-5"/>',
        'ajout' => '<circle cx="10" cy="8" r="4"/><path d="M3 21c0-4 3-6 7-6 1.5 0 2.8.3 3.9.8"/><path d="M19 14v6M16 17h6"/>',
        'sessions' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'comptes' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/>',
        'journal' => '<path d="M6 3h10l4 4v14H6z"/><path d="M10 11h6M10 15h6"/>',
        'sites' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>',
        'travaux' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'boutique' => '<path d="M3 9l2-5h14l2 5"/><path d="M4 9v11h16V9"/><path d="M3 9h18M9 20v-6h6v6"/>',
        'bibliotheque' => '<path d="M4 4h4v16H4zM10 4h4v16h-4z"/><path d="M16 5l4 1-3 14-4-1z"/>',
        'documents' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/>',
        'motdepasse' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3"/>',
        'sujet' => '<path d="M6 3h9l3 3v15H6z"/><path d="M9 13l2 2 4-4"/>',
        'certificat' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'corbeille' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
        'demandes' => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
        'wordpress' => '<circle cx="12" cy="12" r="9"/><path d="M3.6 8.5h16.8M6 9l3.5 10L12 12l2.5 7L18 9"/>',
    ];

    public static function icone(string $nom, string $classe = 'i'): string
    {
        return '<svg class="' . Html::e($classe) . '" viewBox="0 0 24 24" aria-hidden="true">' . (self::ICONES[$nom] ?? '') . '</svg>';
    }

    /**
     * Noms de tous les jours des sites d'un étudiant (décision du 17/11/2026), le dossier technique en petit à côté.
     */
    public const NOMS_SITES = ['/WP' => 'Site WordPress (cours)', '/WP2' => 'Site d\'examen', '/Presta' => 'Boutique (cours)', '/Presta2' => 'Boutique d\'examen'];

    /** Nom d'un site en HTML : « Site d'examen » avec « /WP2 » en petit ; un autre dossier reste tel quel. */
    public static function nomSite(string $dossier): string
    {
        $nom = self::NOMS_SITES[$dossier] ?? null;

        return $nom === null ? '<code>' . Html::e($dossier) . '</code>' : Html::e($nom) . ' <span class="tech">' . Html::e($dossier) . '</span>';
    }

    /** Nom d'un site en texte simple (listes déroulantes, e-mails) : « Site d'examen (/WP2) ». */
    public static function nomSiteTexte(string $dossier): string
    {
        $nom = self::NOMS_SITES[$dossier] ?? null;

        return $nom === null ? $dossier : $nom . ' (' . $dossier . ')';
    }

    /**
     * Pages accessibles, rangées en rubriques (décision du 17/11/2026) : chacun ne voit que ce qu'il peut faire.
     *
     * @return list<array{string, string, string, string, string}> adresse, icône, libellé, description, rubrique ('' : hors rubrique)
     */
    public static function destinations(Compte $compte): array
    {
        $peut = static fn (string $droit): bool => Droits::peut($compte->role, $droit);
        $liste = [['/', 'accueil', 'Accueil', '', '']];
        if ($peut(Droits::CONSULTER)) {
            $liste[] = ['/etudiants', 'etudiants', 'Liste des étudiants', 'Les étudiants par régime et session, avec leur fiche.', 'Étudiants'];
        }
        // « Ajouter des étudiants » (décision du 17/11/2026) : une entrée, deux étiquettes « Par liste » et « Un seul étudiant ».
        if ($peut(Droits::IMPORTER) || $peut(Droits::CREER_ETUDIANT)) {
            $liste[] = [$peut(Droits::IMPORTER) ? '/etudiants/import' : '/etudiants/nouveau', 'ajout', 'Ajouter des étudiants',
                'Par liste (Google Sheets) ou un seul étudiant arrivé en cours d’année.', 'Étudiants'];
        }
        if ($peut(Droits::CONSULTER)) {
            $liste[] = ['/sites', 'sites', 'Liste des sites', 'Une ligne par étudiant : son site WordPress, son site d’examen, ses boutiques.', 'Sites'];
        }
        if ($peut(Droits::CREER_SITE)) {
            $liste[] = ['/sites/wordpress', 'wordpress', 'Créer des WordPress', 'L’adresse et le site WordPress (cours) d’un nouvel étudiant, accès envoyés par e-mail.', 'Sites'];
            $liste[] = ['/sites/prestashop', 'boutique', 'Créer des boutiques', 'La boutique (cours) PrestaShop d’une session, accès envoyés par e-mail.', 'Sites'];
        }
        if ($peut(Droits::SUPPRIMER)) {
            $liste[] = ['/sites/supprimer', 'corbeille', 'Supprimer', 'Une liste (par session) ou un étudiant : sites ou tout, corbeille de 8 jours.', 'Sites'];
        }
        if ($peut(Droits::SUJETS_EXAMEN)) {
            $liste[] = ['/sites/sujet', 'sujet', 'Site modèle d’examen', 'Charger le sujet d’examen, le déployer chez les étudiants, clôturer l’épreuve.', 'Examens'];
        }
        if ($peut(Droits::DOCUMENTS)) {
            $liste[] = ['/bibliotheque', 'bibliotheque', 'Bibliothèque', $peut(Droits::BIBLIOTHEQUE)
                ? 'Documents de l’équipe, sujets d’examen, versions PrestaShop.' : 'Référentiel et documents de l’équipe, en PDF.', 'Documents'];
        }
        if ($peut(Droits::DEMANDER)) {
            $liste[] = ['/demandes', 'demandes', 'Demandes', $peut(Droits::TRAITER_DEMANDES)
                ? 'Valider ou refuser les demandes de suppression et de réinitialisation ; restaurer depuis la corbeille.'
                : 'Suivre vos demandes de suppression ou de réinitialisation à l’administrateur.', 'Suivi'];
        }
        if ($peut(Droits::CONSULTER)) {
            $liste[] = ['/travaux', 'travaux', 'Travaux en cours', 'Ce que le serveur est en train de faire, et son avancement.', 'Suivi'];
        }
        if ($peut(Droits::REGIMES_SESSIONS)) {
            $liste[] = ['/regimes-sessions', 'sessions', 'Régimes et sessions', 'Les classes (FI, ALT) et les promotions (sessions d’examen).', 'Administration'];
        }
        if ($peut(Droits::CERTIFICATS)) {
            $liste[] = ['/sites/certificats', 'certificat', 'Certificats', 'Vérifier les certificats https des sites et relancer ceux qui manquent.', 'Administration'];
        }
        if ($peut(Droits::GERER_COMPTES)) {
            $liste[] = ['/comptes', 'comptes', 'Comptes du portail', 'Créer, réinitialiser, désactiver ou supprimer un accès au portail.', 'Administration'];
        }
        if ($peut(Droits::JOURNAL)) {
            $liste[] = ['/journal', 'journal', 'Journal', 'Qui a fait quoi, quand, et avec quel résultat.', 'Administration'];
        }
        $liste[] = ['/mot-de-passe', 'motdepasse', 'Mon mot de passe', 'Changer le mot de passe de votre compte.', 'Mon compte'];

        return $liste;
    }

    /**
     * Étiquettes cliquables en tête de page (décision du 17/11/2026) : une étiquette = une action, l'active est mise en avant.
     *
     * @param list<array{adresse: string, titre: string, texte: string, numero?: string, bientot?: bool}> $etiquettes
     */
    public static function etiquettes(array $etiquettes, string $actif, string $libelle): string
    {
        $html = '';
        foreach ($etiquettes as $e) {
            $contenu = (isset($e['numero']) ? '<span class="etiq-num" aria-hidden="true">' . Html::e($e['numero']) . '</span>' : '')
                . '<strong>' . Html::e($e['titre']) . ($e['bientot'] ?? false ? ' <span class="badge neutre">bientôt</span>' : '') . '</strong>'
                . '<span>' . Html::e($e['texte']) . '</span>';
            $html .= ($e['bientot'] ?? false)
                ? '<div class="etiq inactive">' . $contenu . '</div>'
                : '<a class="etiq' . ($e['adresse'] === $actif ? ' actif" aria-current="page' : '') . '" href="' . Html::e($e['adresse']) . '">' . $contenu . '</a>';
        }

        return '<nav class="etiquettes" aria-label="' . Html::e($libelle) . '">' . $html . '</nav>';
    }

    /** Phrase d'aide en tête de page, avec un « ? » qui déplie l'explication détaillée (décision du 17/11/2026). */
    public static function aide(string $phrase, string $detail = ''): string
    {
        return $detail === ''
            ? '<p class="aide">' . Html::e($phrase) . '</p>'
            : '<details class="aide"><summary>' . Html::e($phrase) . ' <span class="aide-q" aria-label="Plus d’explications">?</span></summary><div class="aide-detail">' . $detail . '</div></details>';
    }

    private static function menu(Compte $compte, string $chemin): string
    {
        $blocs = [];
        foreach (self::destinations($compte) as [$adresse, $icone, $libelle, , $rubrique]) {
            $actif = $adresse === $chemin || ($adresse === '/etudiants' && preg_match('#^/etudiants/[0-9]+$#', $chemin) === 1)
                || ($adresse === '/comptes' && str_starts_with($chemin, '/comptes/'))
                || ($adresse === '/travaux' && str_starts_with($chemin, '/travaux/'))
                || ($adresse !== '/' && $adresse !== '/sites' && $adresse !== '/etudiants' && str_starts_with($chemin, $adresse . '/'))
                || (in_array($adresse, ['/etudiants/import', '/etudiants/nouveau'], true) && in_array($chemin, ['/etudiants/import', '/etudiants/nouveau'], true));
            $compteur = $adresse === '/demandes' && self::$demandesEnAttente > 0
                ? ' <span class="compteur" aria-label="' . self::$demandesEnAttente . ' en attente">' . self::$demandesEnAttente . '</span>' : '';
            $blocs[$rubrique][] = '<a href="' . $adresse . '"' . ($actif ? ' aria-current="page"' : '') . '>' . self::icone($icone) . Html::e($libelle) . $compteur . '</a>';
        }
        $html = '';
        foreach ($blocs as $rubrique => $liens) {
            $html .= '<div class="rubrique">' . ($rubrique === '' ? '' : '<h2 class="rubrique-titre">' . Html::e($rubrique) . '</h2>') . implode('', $liens) . '</div>';
        }

        return '<nav class="nav" aria-label="Menu principal">' . $html . '</nav>';
    }

    public static function message(string $titre, string $texte): string
    {
        return self::page($titre, '<p>' . Html::e($texte) . '</p>');
    }

    public static function connexion(string $jeton, ?string $erreur, ?string $information): string
    {
        return self::page('Connexion', self::alerte($erreur, $information)
            . '<form method="post" action="/connexion">' . self::jeton($jeton)
            . self::champ('identifiant', 'Identifiant', 'text', 'username', true)
            . self::champ('mot_de_passe', 'Mot de passe', 'password', 'current-password')
            . '<button type="submit" class="btn-main">Continuer</button></form>');
    }

    public static function code(string $jeton, ?string $erreur): string
    {
        return self::page('Code de vérification', self::alerte($erreur, null)
            . '<p>Saisissez le code à 6 chiffres affiché par l\'application d\'authentification de votre téléphone.</p>'
            . '<form method="post" action="/connexion/code">' . self::jeton($jeton)
            . self::champCode()
            . '<button type="submit" class="btn-main">Se connecter</button></form>');
    }

    public static function activation(string $jeton, string $cleLisible, string $identifiant, ?string $erreur): string
    {
        $cle = implode(' ', str_split($cleLisible, 4));
        // QR code dessiné sur le serveur (aucun service extérieur) : il contient la même clé que le texte.
        $qr = QrCode::svg(Totp::adresse(Base32::decoder($cleLisible), $identifiant), 'QR code d\'activation du compte ' . $identifiant);

        return self::page('Activer la double authentification', self::alerte($erreur, null)
            . '<p>Première connexion : reliez votre compte à une application d\'authentification sur <strong>votre</strong> téléphone '
            . '(par exemple Google Authenticator, Microsoft Authenticator ou FreeOTP).</p>'
            . '<ol><li>Dans l\'application, choisissez d\'ajouter un compte en <strong>scannant un QR code</strong>, puis visez celui-ci :</li></ol>'
            . '<p>' . $qr . '</p>'
            . '<p>Impossible de scanner ? Ajoutez le compte en <strong>saisissant une clé</strong> : nom <strong>Portail Sup\'Sallanches</strong>, '
            . 'type « basé sur l\'heure », clé :</p>'
            . '<p><code>' . Html::e($cle) . '</code></p>'
            . '<ol start="2"><li>Saisissez ci-dessous le code à 6 chiffres affiché par l\'application.</li></ol>'
            . '<p>Ce QR code et cette clé ne seront plus jamais affichés. Ne les montrez et ne les communiquez à personne.</p>'
            . '<form method="post" action="/connexion/activation">' . self::jeton($jeton)
            . self::champCode()
            . '<button type="submit" class="btn-main">Activer et se connecter</button></form>');
    }

    public static function changementMotDePasse(string $jeton, ?string $erreur, bool $impose): string
    {
        return self::page('Choisir un nouveau mot de passe', self::alerte($erreur, null)
            . ($impose ? '<p>Le mot de passe que vous avez reçu est provisoire : choisissez le vôtre pour continuer.</p>' : '')
            . '<p>Trois formes possibles, au choix (recommandation de la CNIL) :</p><ul>'
            . '<li>12 caractères au moins, avec majuscules, minuscules, chiffres et caractères spéciaux (! ? - _ …) ;</li>'
            . '<li>14 caractères au moins, avec majuscules, minuscules et chiffres ;</li>'
            . '<li>une phrase d\'au moins 7 mots, facile à retenir pour vous seul, séparés par des espaces.</li></ul>'
            . '<p>Jamais votre identifiant, ni un mot de passe déjà utilisé ailleurs.</p>'
            . '<form method="post" action="/mot-de-passe">' . self::jeton($jeton)
            . self::champ('actuel', 'Mot de passe actuel', 'password', 'current-password', true)
            . self::champ('nouveau', 'Nouveau mot de passe', 'password', 'new-password')
            . self::champ('confirmation', 'Nouveau mot de passe, à nouveau', 'password', 'new-password')
            . '<button type="submit" class="btn-main">Enregistrer</button></form>');
    }

    /**
     * @param list<array{id: int, identifiant: string, nom: string, depuis: string}> $aRevoir
     * @param list<array{id: int, date: string, auteur: string, cible: string, detail: string}> $secours
     */
    public static function accueil(string $jeton, Compte $compte, array $aRevoir = [], array $secours = []): string
    {
        $alertes = '';
        if ($secours !== []) {
            $lignes = '';
            foreach ($secours as $trace) {
                $lignes .= '<li>' . Html::e(self::jour($trace['date'])) . ' – ' . Html::e($trace['auteur']) . ' : '
                    . Html::e($trace['detail']) . ' – compte ' . Html::e($trace['cible']) . '</li>';
            }
            $jusqua = max(array_column($secours, 'id'));
            $alertes .= '<section role="alert"><h2>Actions de secours de la direction (30 derniers jours)</h2>'
                . '<p>Vérifiez qu\'elles étaient attendues. Sinon, désactivez le compte concerné et prévenez la direction.</p>'
                . '<ul>' . $lignes . '</ul>'
                . '<form method="post" action="/">' . self::jeton($jeton) . '<input type="hidden" name="action" value="secours_verifies">'
                . '<input type="hidden" name="jusqua" value="' . $jusqua . '">'
                . '<button type="submit">Vérification effectuée</button> <span>Le message disparaît pour tous les administrateurs ; la trace reste au journal.</span></form></section>';
        }
        if ($aRevoir !== []) {
            $lignes = '';
            foreach ($aRevoir as $revue) {
                $lignes .= '<li><a href="/comptes/' . $revue['id'] . '">' . Html::e($revue['nom']) . ' (' . Html::e($revue['identifiant']) . ')</a>'
                    . ' – non confirmé depuis le ' . Html::e(self::jour($revue['depuis'])) . '</li>';
            }
            $alertes .= '<section role="alert"><h2>Comptes à revoir (plus de 6 mois)</h2>'
                . '<p>Pour chacun : la personne en a-t-elle toujours besoin ? Ouvrez le compte, puis confirmez-le ou désactivez-le.</p>'
                . '<ul>' . $lignes . '</ul></section>';
        }
        $tuiles = '';
        foreach (array_slice(self::destinations($compte), 1) as [$adresse, $icone, $libelle, $description]) {
            $compteur = $adresse === '/demandes' && self::$demandesEnAttente > 0 ? ' <span class="compteur">' . self::$demandesEnAttente . ' en attente</span>' : '';
            $tuiles .= '<a class="tuile" href="' . $adresse . '"><span class="ico">' . self::icone($icone, 'i m') . '</span>'
                . '<span class="tuile-texte"><span class="tuile-titre">' . Html::e($libelle) . $compteur . '</span><span>' . Html::e($description) . '</span></span></a>';
        }

        return self::page('Portail des sites étudiants',
            '<p class="intro">Bonjour ' . Html::e($compte->nomAffiche) . ' (' . Html::e($compte->libelleRole()) . ').</p>'
            . $alertes
            . '<section aria-labelledby="t-actions"><h2 id="t-actions">Que voulez-vous faire ?</h2><div class="tuiles">' . $tuiles . '</div></section>'
            . '<p class="discret">La création et la gestion des sites arrivent lot par lot avec la version 2.</p>');
    }

    public static function refus(): string
    {
        return self::message('Accès refusé', 'Votre rôle ne permet pas cette action.');
    }

    /** @param list<Compte> $comptes */
    public static function comptes(string $jeton, array $comptes, ?string $information): string
    {
        $lignes = '';
        foreach ($comptes as $compte) {
            $lignes .= '<tr><td><a href="/comptes/' . $compte->id . '">' . Html::e($compte->nomAffiche) . '</a></td>'
                . '<td>' . Html::e($compte->identifiant) . '</td><td>' . Html::e($compte->libelleRole()) . '</td>'
                . '<td>' . Html::e($compte->email) . '</td>'
                . '<td>' . ($compte->doubleAuthActive ? 'activée' : 'à activer') . '</td>'
                . '<td>' . ($compte->actif ? 'actif' : 'désactivé') . '</td></tr>';
        }

        return self::page('Comptes du portail', self::alerte(null, $information)
            . '<p><a href="/comptes/nouveau">Créer un compte</a> · <a href="/">Accueil</a></p>'
            . '<table class="triable"><thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>E-mail</th>'
            . '<th>Double authentification</th><th>État</th></tr></thead><tbody>' . $lignes . '</tbody></table>');
    }

    /** @param array<string, string> $valeurs */
    public static function nouveauCompte(string $jeton, ?string $erreur, array $valeurs): string
    {
        return self::page('Créer un compte', self::alerte($erreur, null)
            . '<p>Un mot de passe provisoire sera affiché une seule fois, à transmettre à la personne. '
            . 'Elle reliera son téléphone et choisira son mot de passe à sa première connexion.</p>'
            . '<form method="post" action="/comptes/nouveau">' . self::jeton($jeton)
            . self::saisie('identifiant', 'Identifiant de connexion (ex. cpetit)', $valeurs['identifiant'] ?? '')
            . self::saisie('nom', 'Nom affiché (ex. Claire PETIT)', $valeurs['nom'] ?? '')
            . self::saisie('email', 'Adresse e-mail', $valeurs['email'] ?? '', 'email')
            . self::choixRole($valeurs['role'] ?? 'enseignant')
            . '<button type="submit" class="btn-main">Créer le compte</button></form><p><a href="/comptes">Retour aux comptes</a></p>');
    }

    public static function ficheCompte(string $jeton, Compte $compte, Compte $moi, ?string $erreur, ?string $information, ?string $revuLe = null): string
    {
        $soi = $compte->id === $moi->id;
        $action = '/comptes/' . $compte->id;
        $bouton = static fn (string $valeur, string $libelle): string => '<form method="post" action="' . $action . '">'
            . self::jeton($jeton) . '<input type="hidden" name="action" value="' . Html::e($valeur) . '">'
            . '<button type="submit">' . Html::e($libelle) . '</button></form>';

        return self::page('Compte ' . $compte->identifiant, self::alerte($erreur, $information)
            . '<form method="post" action="' . $action . '">' . self::jeton($jeton)
            . '<input type="hidden" name="action" value="modifier">'
            . self::saisie('nom', 'Nom affiché', $compte->nomAffiche)
            . self::saisie('email', 'Adresse e-mail', $compte->email, 'email')
            . self::choixRole($compte->role)
            . '<button type="submit">Enregistrer</button></form>'
            . '<h2>Actions</h2>'
            . ($compte->actif
                ? '<p>Double authentification : ' . ($compte->doubleAuthActive
                    ? 'activée. Si la personne n\'a plus le code sur son téléphone (application supprimée, téléphone perdu ou changé), '
                        . 'cliquez « Réinitialiser le code à 6 chiffres » : elle reliera de nouveau son téléphone à sa prochaine connexion'
                    : 'à relier à la prochaine connexion (un code QR s\'affichera après le mot de passe)') . '.</p>'
                    . $bouton('mot_de_passe', 'Réinitialiser le mot de passe')
                    . $bouton('double_auth', 'Réinitialiser le code à 6 chiffres (téléphone perdu ou changé)')
                : '<p role="status"><strong>Ce compte est désactivé : il ne peut pas se connecter.</strong> '
                    . 'Réactivez-le avant de réinitialiser son mot de passe ou son code à 6 chiffres.</p>')
            . ($compte->actif && $revuLe !== null
                ? '<p>Dernière confirmation que ce compte est nécessaire : ' . Html::e(self::jour($revuLe))
                    . ' (création ou revue). Le portail le signale au bout de 6 mois.</p>'
                    . $bouton('confirmer', 'Confirmer : ce compte est toujours nécessaire')
                : '')
            . ($compte->actif ? $bouton('desactiver', $soi ? 'Désactiver mon propre compte' : 'Désactiver le compte')
                : $bouton('reactiver', 'Réactiver le compte'))
            . (!$compte->actif && !$soi && $moi->role === 'administrateur'
                ? '<h2>Supprimer le compte (administrateur)</h2>'
                    . '<p>Départ définitif : le compte disparaît de la liste et ne pourra plus être réactivé. '
                    . 'Ses actions restent dans le journal sous son nom ; ses demandes et ses dépôts restent, sans lien vers lui.</p>'
                    . '<form method="post" action="' . $action . '">' . self::jeton($jeton) . '<input type="hidden" name="action" value="supprimer">'
                    . '<p><label for="confirmation">Pour confirmer, tapez l\'identifiant du compte (<code>' . Html::e($compte->identifiant) . '</code>)</label><br>'
                    . '<input id="confirmation" name="confirmation" type="text" autocomplete="off" required maxlength="32"></p>'
                    . '<button type="submit" class="btn-danger">Supprimer définitivement</button></form>'
                : '')
            . '<p>Toute modification du rôle, toute réinitialisation et toute désactivation ferment immédiatement '
            . 'les sessions ouvertes de ce compte.</p>'
            . '<p><a href="/comptes">Retour aux comptes</a></p>');
    }

    public static function motDePasseProvisoire(string $identifiant, string $provisoire): string
    {
        return self::page('Mot de passe provisoire',
            '<p>Mot de passe provisoire du compte <strong>' . Html::e($identifiant) . '</strong> :</p>'
            . '<p><code>' . Html::e($provisoire) . '</code></p>'
            . '<p>Il ne sera <strong>plus jamais affiché</strong>. Transmettez-le à la personne par un moyen sûr '
            . '(de vive voix, ou par un autre canal que l\'identifiant). Elle devra le changer à sa première connexion.</p>'
            . '<p><a href="/comptes">Retour aux comptes</a></p>');
    }

    public static function alerte(?string $erreur, ?string $information): string
    {
        return ($erreur === null ? '' : '<p role="alert"><strong>' . Html::e($erreur) . '</strong></p>')
            . ($information === null ? '' : '<p role="status">' . Html::e($information) . '</p>');
    }

    public static function boutonDeconnexion(string $jeton): string
    {
        return '<form method="post" action="/deconnexion">' . self::jeton($jeton) . '<button type="submit">Se déconnecter</button></form>';
    }

    public static function saisie(string $nom, string $libelle, string $valeur, string $type = 'text'): string
    {
        return '<p><label for="' . Html::e($nom) . '">' . Html::e($libelle) . '</label><br>'
            . '<input id="' . Html::e($nom) . '" name="' . Html::e($nom) . '" type="' . Html::e($type) . '"'
            . ' value="' . Html::e($valeur) . '" required maxlength="254"></p>';
    }

    private static function choixRole(string $actuel): string
    {
        $options = '';
        foreach (Compte::LIBELLES_ROLES as $role => $libelle) {
            $options .= '<option value="' . Html::e($role) . '"' . ($role === $actuel ? ' selected' : '') . '>' . Html::e($libelle) . '</option>';
        }

        return '<p><label for="role">Rôle</label><br><select id="role" name="role">' . $options . '</select></p>';
    }

    public static function jeton(string $jeton): string
    {
        return '<input type="hidden" name="jeton" value="' . Html::e($jeton) . '">';
    }

    private static function champ(string $nom, string $libelle, string $type, string $saisieAuto, bool $focus = false): string
    {
        return '<p><label for="' . Html::e($nom) . '">' . Html::e($libelle) . '</label><br>'
            . '<input id="' . Html::e($nom) . '" name="' . Html::e($nom) . '" type="' . Html::e($type) . '"'
            . ' autocomplete="' . Html::e($saisieAuto) . '" required maxlength="' . MotsDePasse::LONGUEUR_MAX . '"'
            . ($focus ? ' autofocus' : '') . '></p>';
    }

    private static function champCode(): string
    {
        return '<p><label for="code">Code à 6 chiffres</label><br>'
            . '<input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"'
            . ' autocomplete="one-time-code" required autofocus></p>';
    }

    /**
     * Journal : filtre par catégorie, entrées les plus récentes d'abord, 100 par page.
     *
     * @param array<string, string> $categories Catégories que ce lecteur a le droit de voir (clé => libellé)
     * @param list<array{id: int, date: string, categorie: string, auteur: string, ip: string, action: string, cible: string, resultat: string, detail: string}> $entrees
     */
    public static function journal(string $jeton, array $categories, string $choisie, array $entrees, bool $voirIp, bool $suite): string
    {
        $options = '<option value="">Toutes</option>';
        foreach ($categories as $cle => $libelle) {
            $options .= '<option value="' . Html::e($cle) . '"' . ($cle === $choisie ? ' selected' : '') . '>' . Html::e($libelle) . '</option>';
        }
        $resultats = ['succes' => 'réussi', 'echec' => 'échec', 'refus' => 'refusé'];
        $lignes = '';
        foreach ($entrees as $e) {
            $lignes .= '<tr><td>' . Html::e(self::heureLocale($e['date'])) . '</td><td>' . Html::e($categories[$e['categorie']] ?? $e['categorie']) . '</td>'
                . '<td>' . Html::e($e['auteur']) . '</td><td>' . Html::e($e['action']) . '</td><td>' . Html::e($e['cible']) . '</td>'
                . '<td>' . Html::e($resultats[$e['resultat']] ?? $e['resultat']) . '</td><td>' . Html::e($e['detail']) . '</td>'
                . ($voirIp ? '<td>' . Html::e($e['ip']) . '</td>' : '') . '</tr>';
        }
        $derniere = $entrees === [] ? null : $entrees[count($entrees) - 1]['id'];

        return self::page('Journal', '<p>Heures de Paris. Les connexions sont conservées 1 an, les actions sur les sites 2 ans.</p>'
            . '<form method="post" action="/journal">' . self::jeton($jeton) . '<input type="hidden" name="action" value="filtrer">'
            . '<label for="categorie">Catégorie</label> <select id="categorie" name="categorie">' . $options . '</select> '
            . '<button type="submit">Afficher</button></form>'
            . ($entrees === [] ? '<p>Aucune entrée.</p>'
                : '<table><thead><tr><th>Date</th><th>Catégorie</th><th>Par</th><th>Action</th><th>Sur</th><th>Résultat</th><th>Détail</th>'
                    . ($voirIp ? '<th>Adresse IP</th>' : '') . '</tr></thead><tbody>' . $lignes . '</tbody></table>')
            . ($suite && $derniere !== null
                ? '<form method="post" action="/journal">' . self::jeton($jeton) . '<input type="hidden" name="action" value="filtrer">'
                    . '<input type="hidden" name="categorie" value="' . Html::e($choisie) . '"><input type="hidden" name="avant" value="' . $derniere . '">'
                    . '<button type="submit">Entrées plus anciennes</button></form>'
                : '')
            . '<p><a href="/">Accueil</a></p>');
    }

    /** « 2026-11-08T09:12:37Z » → « 08/11/2026 10:12:37 » (heure de Paris) */
    public static function heureLocale(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i:s');
        } catch (\Exception) {
            return $date;
        }
    }

    /** « 2026-11-07T13:45:00Z » → « 07/11/2026 » */
    private static function jour(string $date): string
    {
        $instant = strtotime($date);

        return $instant === false ? $date : gmdate('d/m/Y', $instant);
    }
}
