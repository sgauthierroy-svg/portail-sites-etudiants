<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Comptes\Compte;
use Portail\Comptes\Droits;
use Portail\Securite\MotsDePasse;

/**
 * Pages HTML du portail. Tout texte variable passe par Html::e().
 * (Habillage aux couleurs de Sup'Sallanches : lot 6.)
 */
final class Pages
{
    public static function page(string $titre, string $contenu): string
    {
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . Html::e($titre) . ' – Portail Sup\'Sallanches</title></head>'
            . '<body><main><h1>' . Html::e($titre) . '</h1>' . $contenu . '</main></body></html>';
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
            . '<button type="submit">Continuer</button></form>');
    }

    public static function code(string $jeton, ?string $erreur): string
    {
        return self::page('Code de vérification', self::alerte($erreur, null)
            . '<p>Saisissez le code à 6 chiffres affiché par l\'application d\'authentification de votre téléphone.</p>'
            . '<form method="post" action="/connexion/code">' . self::jeton($jeton)
            . self::champCode()
            . '<button type="submit">Se connecter</button></form>');
    }

    public static function activation(string $jeton, string $cleLisible, ?string $erreur): string
    {
        $cle = implode(' ', str_split($cleLisible, 4));

        return self::page('Activer la double authentification', self::alerte($erreur, null)
            . '<p>Première connexion : reliez votre compte à une application d\'authentification sur <strong>votre</strong> téléphone '
            . '(par exemple Google Authenticator, Microsoft Authenticator ou FreeOTP).</p>'
            . '<ol><li>Dans l\'application, choisissez d\'ajouter un compte en <strong>saisissant une clé</strong>.</li>'
            . '<li>Nom du compte : <strong>Portail Sup\'Sallanches</strong>. Clé :</li></ol>'
            . '<p><code>' . Html::e($cle) . '</code></p>'
            . '<ol start="3"><li>Choisissez le type « basé sur l\'heure », puis saisissez ci-dessous le code à 6 chiffres affiché.</li></ol>'
            . '<p>Cette clé ne sera plus jamais affichée. Ne la communiquez à personne.</p>'
            . '<form method="post" action="/connexion/activation">' . self::jeton($jeton)
            . self::champCode()
            . '<button type="submit">Activer et se connecter</button></form>');
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
            . '<button type="submit">Enregistrer</button></form>');
    }

    /**
     * @param list<array{id: int, identifiant: string, nom: string, depuis: string}> $aRevoir
     * @param list<array{date: string, auteur: string, cible: string, detail: string}> $secours
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
            $alertes .= '<section role="alert"><h2>Actions de secours de la direction (30 derniers jours)</h2>'
                . '<p>Vérifiez qu\'elles étaient attendues. Sinon, désactivez le compte concerné et prévenez la direction.</p>'
                . '<ul>' . $lignes . '</ul></section>';
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
        $liens = [];
        if (Droits::peut($compte->role, Droits::CONSULTER)) {
            $liens[] = '<li><a href="/etudiants">Étudiants</a></li>';
        }
        if (Droits::peut($compte->role, Droits::REGIMES_SESSIONS)) {
            $liens[] = '<li><a href="/regimes-sessions">Régimes et sessions</a></li>';
        }
        if (Droits::peut($compte->role, Droits::GERER_COMPTES)) {
            $liens[] = '<li><a href="/comptes">Comptes du portail</a></li>';
        }
        $liens[] = '<li><a href="/mot-de-passe">Changer mon mot de passe</a></li>';

        return self::page('Portail des sites étudiants',
            '<p>Bonjour ' . Html::e($compte->nomAffiche) . ' (' . Html::e($compte->libelleRole()) . ').</p>'
            . $alertes
            . '<ul>' . implode('', $liens) . '</ul>'
            . '<p>Les autres fonctions du portail arrivent avec les prochains lots.</p>'
            . self::boutonDeconnexion($jeton));
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
            . '<table><thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>E-mail</th>'
            . '<th>Double authentification</th><th>État</th></tr></thead><tbody>' . $lignes . '</tbody></table>'
            . self::boutonDeconnexion($jeton));
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
            . '<button type="submit">Créer le compte</button></form><p><a href="/comptes">Retour aux comptes</a></p>');
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
            . '<p>Double authentification : ' . ($compte->doubleAuthActive ? 'activée' : 'à activer à la prochaine connexion') . '.</p>'
            . $bouton('mot_de_passe', 'Réinitialiser le mot de passe')
            . $bouton('double_auth', 'Réinitialiser le code à 6 chiffres (téléphone perdu ou changé)')
            . ($compte->actif && $revuLe !== null
                ? '<p>Dernière confirmation que ce compte est nécessaire : ' . Html::e(self::jour($revuLe))
                    . ' (création ou revue). Le portail le signale au bout de 6 mois.</p>'
                    . $bouton('confirmer', 'Confirmer : ce compte est toujours nécessaire')
                : '')
            . ($compte->actif ? $bouton('desactiver', $soi ? 'Désactiver mon propre compte' : 'Désactiver le compte')
                : $bouton('reactiver', 'Réactiver le compte'))
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

    /** « 2026-11-07T13:45:00Z » → « 07/11/2026 » */
    private static function jour(string $date): string
    {
        $instant = strtotime($date);

        return $instant === false ? $date : gmdate('d/m/Y', $instant);
    }
}
