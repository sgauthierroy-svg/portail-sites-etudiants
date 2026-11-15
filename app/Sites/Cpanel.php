<?php

declare(strict_types=1);

namespace Portail\Sites;

use Portail\Base\Lecture;
use Portail\Systeme\Commande;
use RuntimeException;

/**
 * Appels à l'API de CPanel par la commande uapi (méthodes validées par nos scripts
 * depuis octobre 2026). Réservé au travailleur : Commande refuse toute exécution web.
 */
final class Cpanel implements ServeurCpanel, NettoyageCpanel
{
    /** Compte CPanel d'O2switch (préfixe des bases et des utilisateurs MySQL). */
    private const COMPTE = 'jafl3067';

    /** @var array<string, string>|null */
    private ?array $sousDomaines = null;

    public function sousDomaines(): array
    {
        if ($this->sousDomaines === null) {
            $this->sousDomaines = Releve::sousDomaines($this->appeler(['DomainInfo', 'domains_data'], false));
        }

        return $this->sousDomaines;
    }

    public function creerSousDomaine(string $identifiant): void
    {
        if (preg_match('/^[a-z0-9]{2,63}$/D', $identifiant) !== 1) {
            throw new RuntimeException('Identifiant de sous-domaine refusé.');
        }
        $this->appeler(['SubDomain', 'addsubdomain', 'domain=' . $identifiant, 'rootdomain=' . Releve::DOMAINE,
            'dir=' . $identifiant . '.' . Releve::DOMAINE]);
        $this->sousDomaines = null;
    }

    public function creerBase(string $base): void
    {
        $this->appeler(['Mysql', 'create_database', 'name=' . self::nom($base)]);
    }

    public function creerUtilisateur(string $utilisateur, #[\SensitiveParameter] string $motDePasse): void
    {
        $this->appeler(['Mysql', 'create_user', 'name=' . self::nom($utilisateur), 'password=' . $motDePasse]);
    }

    public function donnerDroits(string $utilisateur, string $base): void
    {
        $this->appeler(['Mysql', 'set_privileges_on_database', 'user=' . self::nom($utilisateur), 'database=' . self::nom($base), 'privileges=ALL']);
    }

    public function supprimerBase(string $base): void
    {
        $this->appeler(['Mysql', 'delete_database', 'name=' . self::nom($base)]);
    }

    public function supprimerUtilisateur(string $utilisateur): void
    {
        $this->appeler(['Mysql', 'delete_user', 'name=' . self::nom($utilisateur)]);
    }

    public function bases(): array
    {
        return $this->noms($this->appeler(['Mysql', 'list_databases']), 'database');
    }

    public function utilisateurs(): array
    {
        return $this->noms($this->appeler(['Mysql', 'list_users']), 'user');
    }

    /**
     * UAPI ne sait pas retirer un sous-domaine (documentation CPanel : « no equivalent UAPI function exists ») : fonction
     * SubDomain::delsubdomain de l'API 2, au format exigé pour un domaine supplémentaire, identifiant_bts-sup-sallanches.com
     * (nettoyage_etudiants.sh, testé le 24/10/2026). D'abord par la commande cpapi2 du serveur ; sinon comme le script, avec
     * un jeton temporaire détruit aussitôt après. Le résultat est vérifié en relisant la liste des sous-domaines.
     */
    public function supprimerSousDomaine(string $identifiant): void
    {
        if (preg_match('/^[a-z0-9]{2,63}$/D', $identifiant) !== 1) {
            throw new RuntimeException('Identifiant de sous-domaine refusé.');
        }
        if (!isset($this->sousDomaines()[$identifiant])) {
            return;
        }
        $domaine = $identifiant . '_' . Releve::DOMAINE;
        $raisons = [];
        try {
            $this->delsubdomainCommande($domaine);
        } catch (RuntimeException $e) {
            $raisons[] = $e->getMessage();
        }
        $this->sousDomaines = null;
        if (!isset($this->sousDomaines()[$identifiant])) {
            return;
        }
        try {
            $this->delsubdomainJeton($domaine);
        } catch (RuntimeException $e) {
            $raisons[] = $e->getMessage();
        }
        $this->sousDomaines = null;
        if (isset($this->sousDomaines()[$identifiant])) {
            throw new RuntimeException('Sous-domaine toujours présent dans CPanel (' . mb_substr(implode(' / ', $raisons), 0, 300, 'UTF-8')
                . ') : le retirer à la main (CPanel, Domaines, Gérer, Supprimer).');
        }
    }

    /** Commande cpapi2 ; sous CloudLinux, la documentation de CPanel demande son chemin complet. */
    private function delsubdomainCommande(string $domaine): void
    {
        $raisons = [];
        foreach (['cpapi2', '/usr/local/cpanel/bin/cpapi2'] as $programme) {
            try {
                $resultat = Commande::lancer([$programme, '--output=json', 'SubDomain', 'delsubdomain', 'domain=' . $domaine], 120);
                self::verifierApi2($resultat['sortie'], basename($programme) . ' (code ' . $resultat['code'] . ')');

                return;
            } catch (RuntimeException $e) {
                $raisons[] = $e->getMessage();
            }
        }
        throw new RuntimeException(implode(' / ', $raisons));
    }

    private function delsubdomainJeton(string $domaine): void
    {
        $nom = 'portail_' . bin2hex(random_bytes(4));
        $creation = Lecture::tableau(json_decode($this->appeler(['Tokens', 'create_full_access', 'name=' . $nom]), true)) ?? [];
        $jeton = Lecture::texte(Lecture::tableau(Lecture::tableau($creation['result'] ?? null)['data'] ?? null)['token'] ?? null);
        $config = null;
        try {
            if ($jeton === null || preg_match('/^[A-Z0-9]{20,64}$/D', $jeton) !== 1) {
                throw new RuntimeException('jeton temporaire non créé');
            }
            $hote = trim(Commande::lancer(['hostname', '-f'], 10)['sortie']);
            if (preg_match('/^[a-z0-9][a-z0-9.-]{1,252}$/D', $hote) !== 1) {
                throw new RuntimeException('nom du serveur inconnu');
            }
            // Le jeton passe par un fichier de réglages de curl (droits 600), jamais en argument visible des autres programmes.
            $config = tempnam(sys_get_temp_dir(), 'portail-');
            if ($config === false || !chmod($config, 0600)
                || file_put_contents($config, 'header = "Authorization: cpanel ' . self::COMPTE . ':' . $jeton . "\"\n") === false) {
                throw new RuntimeException('fichier temporaire impossible');
            }
            $adresse = 'https://' . $hote . ':2083/json-api/cpanel?cpanel_jsonapi_user=' . self::COMPTE
                . '&cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=SubDomain&cpanel_jsonapi_func=delsubdomain&domain=' . rawurlencode($domaine);
            $resultat = Commande::lancer(['curl', '-sS', '--max-time', '60', '-K', $config, $adresse], 90);
            self::verifierApi2($resultat['sortie'], 'API 2 par jeton (code ' . $resultat['code'] . ')');
        } finally {
            if (is_string($config) && is_file($config)) {
                // Fichier créé juste au-dessus par tempnam(), jamais un chemin venu de l'extérieur.
                unlink($config); // nosemgrep: outils..cache.semgrep-rules.php.lang.security.unlink-use
            }
            $this->appeler(['Tokens', 'revoke', 'name=' . $nom], false);
        }
    }

    /** Réponse de l'API 2 : {"cpanelresult": {"data": [{"result": 1, "reason": "…"}]}} (documentation CPanel, SubDomain::delsubdomain). */
    private static function verifierApi2(string $sortie, string $moyen): void
    {
        $donnees = Lecture::tableau(Lecture::tableau(Lecture::tableau(json_decode($sortie, true))['cpanelresult'] ?? null)['data'] ?? null) ?? [];
        $premier = Lecture::tableau($donnees[0] ?? null) ?? [];
        if (Lecture::entier($premier['result'] ?? null) !== 1) {
            throw new RuntimeException($moyen . ' : ' . mb_substr(Lecture::texte($premier['reason'] ?? null) ?? 'pas de réponse lisible', 0, 150, 'UTF-8'));
        }
    }

    /** @return list<string> */
    private function noms(string $json, string $champ): array
    {
        $donnees = Lecture::tableau(Lecture::tableau(Lecture::tableau(json_decode($json, true))['result'] ?? null)['data'] ?? null) ?? [];
        $noms = [];
        foreach (array_keys($donnees) as $cle) {
            $nom = Lecture::texte(Lecture::tableau($donnees[$cle])[$champ] ?? null);
            if ($nom !== null) {
                $noms[] = $nom;
            }
        }

        return $noms;
    }

    /**
     * @param list<string> $arguments Module, fonction, puis paramètres nom=valeur
     * @return string Réponse JSON brute
     */
    private function appeler(array $arguments, bool $verifier = true): string
    {
        $resultat = Commande::lancer(array_merge(['uapi', '--output=json'], $arguments), 120);
        $reponse = Lecture::tableau(Lecture::tableau(json_decode($resultat['sortie'], true))['result'] ?? null);
        if ($reponse === null) {
            throw new RuntimeException('CPanel n\'a pas répondu à ' . $arguments[0] . '::' . $arguments[1] . ' (code ' . $resultat['code'] . ').');
        }
        if ($verifier && ($reponse['status'] ?? null) !== 1) {
            $erreurs = [];
            $liste = Lecture::tableau($reponse['errors'] ?? null) ?? [];
            foreach (array_keys($liste) as $rang) {
                $erreur = Lecture::texte($liste[$rang]);
                if ($erreur !== null) {
                    $erreurs[] = mb_substr($erreur, 0, 200, 'UTF-8');
                }
            }
            throw new RuntimeException('CPanel a refusé ' . $arguments[1] . ($erreurs === [] ? '' : ' : ' . implode(' ', $erreurs)) . '.');
        }

        return $resultat['sortie'];
    }

    /** Noms de bases et d'utilisateurs : lettres, chiffres et _ seulement (refus de tout autre caractère). */
    private static function nom(string $nom): string
    {
        if (preg_match('/^jafl3067_[A-Za-z0-9_]{1,54}$/D', $nom) !== 1) {
            throw new RuntimeException('Nom de base ou d\'utilisateur refusé : ' . $nom . '.');
        }

        return $nom;
    }
}
