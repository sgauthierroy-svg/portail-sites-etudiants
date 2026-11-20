<?php

declare(strict_types=1);

namespace Portail\Sites;

use Portail\Securite\MotsDePasse;
use Portail\Systeme\Fichiers;
use RuntimeException;
use Throwable;

/**
 * Construit un WordPress neuf dans un dossier de préparation, comme les 1re année (creation_wp.sh,
 * validé le 29/10/2026 ; état relevé le 14/11/2026) : français, titre « Sup », thème et extensions par
 * défaut, permaliens simples, fuseau de Paris, un seul compte administrateur, redirection https.
 * Sert à la création (lot 5) et à la réinitialisation (15/11/2026) : mêmes étapes, même résultat.
 *
 * Base et utilisateur MySQL propres au site (6 caractères au hasard dans le nom) ; mot de passe de
 * l'étudiant jamais en ligne de commande (compte créé avec un mot de passe jetable, puis le vrai, lu
 * dans l'environnement). En cas d'échec, ce qui a été créé (préparation, base, utilisateur) est effacé.
 */
final class ConstructionWordPress
{
    private const PREFIXE = 'jafl3067_';

    /** Redirection vers https des sites étudiants (passage_https.sh, 25/10/2026). */
    public const REDIRECTION_HTTPS = "# BEGIN HTTPS-PORTAIL\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{HTTPS} off\n"
        . "RewriteCond %{HTTP:X-Forwarded-Proto} !https\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n</IfModule>\n# END HTTPS-PORTAIL\n";

    /** Mot de passe posé sans passer par la ligne de commande (lu dans l'environnement de cette seule commande). */
    public const POSER_MOT_DE_PASSE = '$u = get_user_by("login", (string) getenv("PORTAIL_IDENTIFIANT")); '
        . 'if (!$u) { fwrite(STDERR, "compte introuvable"); exit(1); } wp_set_password((string) getenv("PORTAIL_MDP"), $u->ID);';

    public function __construct(
        private readonly ServeurCpanel $cpanel,
        private readonly LigneWordPress $wp,
        private readonly string $dossierTemporaire,
    ) {
    }

    /**
     * @param array{nom?: string, email: string, identifiant_wp: string} $etudiant
     * @return array{preparation: string, base: string, utilisateur: string, version: string}
     */
    public function construire(string $cible, array $etudiant, ?string $version, string $titre, string $adresse,
        #[\SensitiveParameter] string $motDePasse, ?string $adresseAlertes): array
    {
        if ($version !== null && preg_match('/^[0-9]{1,2}\.[0-9]{1,2}(\.[0-9]{1,2})?$/D', $version) !== 1) {
            throw new RuntimeException('Version WordPress de la session illisible (' . $version . ') : rien n\'a été fait.');
        }
        $suffixe = OutilsWordPress::suffixe();
        $base = self::PREFIXE . $cible . '_WP_' . $suffixe;
        $utilisateur = self::PREFIXE . $cible . '_wp' . $suffixe;
        $motDePasseBdd = str_replace('-', '', MotsDePasse::provisoire() . MotsDePasse::provisoire());
        $preparation = $this->dossierTemporaire . '/wp-' . $cible . '-' . $suffixe;
        $crees = ['base' => false, 'utilisateur' => false];
        try {
            if (!mkdir($preparation, 0755)) {
                throw new RuntimeException('Création du dossier de préparation impossible.');
            }
            $this->wp->lancer($preparation, array_merge(['core', 'download', '--locale=fr_FR'], $version === null ? [] : ['--version=' . $version]), 600);
            $installee = OutilsWordPress::versionWordPress($preparation);
            if ($installee === null) {
                throw new RuntimeException('WordPress téléchargé incomplet (version illisible).');
            }
            $this->cpanel->creerBase($base);
            $crees['base'] = true;
            $this->cpanel->creerUtilisateur($utilisateur, $motDePasseBdd);
            $crees['utilisateur'] = true;
            $this->cpanel->donnerDroits($utilisateur, $base);
            self::ecrire($preparation . '/wp-config.php', ConfigWordPress::contenu(
                ['base' => $base, 'utilisateur' => $utilisateur, 'mot_de_passe' => $motDePasseBdd, 'prefixe' => 'wp_'],
                // Version de la session : gelée avec ses correctifs (décision du 07/11/2026, rappelée le 20/11/2026).
                ['gele' => false, 'correctifs' => $version !== null]), 0600);
            GelWordPress::poserModule($preparation);
            $this->wp->lancer($preparation, ['core', 'install', '--url=' . $adresse, '--title=' . $titre, '--admin_user=' . $etudiant['identifiant_wp'],
                '--admin_password=' . bin2hex(random_bytes(24)), '--admin_email=' . $etudiant['email'], '--locale=fr_FR', '--skip-email']);
            $this->wp->lancer($preparation, ['eval', self::POSER_MOT_DE_PASSE], 120,
                ['PORTAIL_IDENTIFIANT' => $etudiant['identifiant_wp'], 'PORTAIL_MDP' => $motDePasse]);
            if ($adresseAlertes !== null) {
                $this->wp->lancer($preparation, ['option', 'update', 'admin_email', $adresseAlertes]);
            }
            $this->wp->lancer($preparation, ['option', 'update', 'timezone_string', 'Europe/Paris']);
            $this->wp->lancer($preparation, ['option', 'update', 'WPLANG', 'fr_FR']);
            self::ecrire($preparation . '/.htaccess', self::REDIRECTION_HTTPS, 0644);
        } catch (Throwable $e) {
            try {
                $this->defaire($crees, $preparation, $base, $utilisateur);
            } catch (Throwable $nettoyage) {
                throw new RuntimeException($e->getMessage() . ' ' . $nettoyage->getMessage(), 0, $e);
            }
            throw $e;
        }

        return ['preparation' => $preparation, 'base' => $base, 'utilisateur' => $utilisateur, 'version' => $installee];
    }

    /**
     * Efface une construction qui ne sera pas mise en place (échec de l'étape suivante).
     *
     * @param array{preparation: string, base: string, utilisateur: string, version: string} $construit
     */
    public function abandonner(array $construit): void
    {
        $this->defaire(['base' => true, 'utilisateur' => true], $construit['preparation'], $construit['base'], $construit['utilisateur']);
    }

    /** @param array{base: bool, utilisateur: bool} $crees */
    private function defaire(array $crees, string $preparation, string $base, string $utilisateur): void
    {
        $erreurs = [];
        if (is_dir($preparation) && !is_link($preparation)) {
            try {
                Fichiers::effacerDossier($preparation, $this->dossierTemporaire);
            } catch (Throwable $e) {
                $erreurs[] = $e->getMessage();
            }
        }
        if ($crees['utilisateur']) {
            try {
                $this->cpanel->supprimerUtilisateur($utilisateur);
            } catch (Throwable $e) {
                $erreurs[] = $e->getMessage();
            }
        }
        if ($crees['base']) {
            try {
                $this->cpanel->supprimerBase($base);
            } catch (Throwable $e) {
                $erreurs[] = $e->getMessage();
            }
        }
        if ($erreurs !== []) {
            throw new RuntimeException('Nettoyage incomplet après échec (' . $base . ') : ' . mb_substr(implode(' ; ', $erreurs), 0, 250, 'UTF-8'));
        }
    }

    private static function ecrire(string $fichier, string $contenu, int $droits): void
    {
        if (file_put_contents($fichier, $contenu, LOCK_EX) === false || !chmod($fichier, $droits)) {
            throw new RuntimeException('Écriture de ' . basename($fichier) . ' impossible.');
        }
    }
}
