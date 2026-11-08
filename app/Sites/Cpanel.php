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
final class Cpanel implements ServeurCpanel
{
    /** @var array<string, string>|null */
    private ?array $sousDomaines = null;

    public function sousDomaines(): array
    {
        if ($this->sousDomaines === null) {
            $this->sousDomaines = Releve::sousDomaines($this->appeler(['DomainInfo', 'domains_data'], false));
        }

        return $this->sousDomaines;
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
