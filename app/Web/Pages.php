<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Comptes\Compte;
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
            . '<p>Au moins ' . MotsDePasse::LONGUEUR_MIN . ' caractères. Une phrase facile à retenir est un bon choix, '
            . 'par exemple quatre mots sans rapport entre eux.</p>'
            . '<form method="post" action="/mot-de-passe">' . self::jeton($jeton)
            . self::champ('actuel', 'Mot de passe actuel', 'password', 'current-password', true)
            . self::champ('nouveau', 'Nouveau mot de passe', 'password', 'new-password')
            . self::champ('confirmation', 'Nouveau mot de passe, à nouveau', 'password', 'new-password')
            . '<button type="submit">Enregistrer</button></form>');
    }

    public static function accueil(string $jeton, Compte $compte): string
    {
        return self::page('Portail des sites étudiants',
            '<p>Bonjour ' . Html::e($compte->nomAffiche) . ' (' . Html::e($compte->libelleRole()) . ').</p>'
            . '<p>Les fonctions du portail arrivent avec les prochains lots.</p>'
            . '<form method="post" action="/deconnexion">' . self::jeton($jeton)
            . '<button type="submit">Se déconnecter</button></form>');
    }

    private static function alerte(?string $erreur, ?string $information): string
    {
        return ($erreur === null ? '' : '<p role="alert"><strong>' . Html::e($erreur) . '</strong></p>')
            . ($information === null ? '' : '<p role="status">' . Html::e($information) . '</p>');
    }

    private static function jeton(string $jeton): string
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
}
