<?php

declare(strict_types=1);

namespace Portail\Noyau;

/**
 * Configuration du portail, lue dans ~/portail/data/config.php (droits 600).
 *
 * Le fichier est refusé s'il est lisible par un autre compte que le propriétaire,
 * s'il manque une valeur ou si une valeur n'a pas le format attendu.
 * Modèle : config/config.exemple.php.
 */
final class Config
{
    private function __construct(
        public readonly string $urlPortail,
        public readonly string $dossierDonnees,
        public readonly string $dossierJournaux,
        public readonly string $adresseAlertes,
        public readonly string $smtpHote,
        public readonly int $smtpPort,
        public readonly string $smtpUtilisateur,
        public readonly string $smtpMotDePasse,
        public readonly string $cleChiffrement,
    ) {
    }

    public static function charger(string $chemin): self
    {
        if (!is_file($chemin)) {
            throw new ErreurConfiguration('Fichier de configuration introuvable.');
        }
        $droits = fileperms($chemin);
        if ($droits === false || ($droits & 0o077) !== 0) {
            throw new ErreurConfiguration('Le fichier de configuration doit être en droits 600 (lisible par son seul propriétaire).');
        }

        /** @psalm-suppress UnresolvableInclude Le chemin est fourni par le code, jamais par un visiteur. */
        $valeurs = require $chemin;
        if (!is_array($valeurs)) {
            throw new ErreurConfiguration('Le fichier de configuration doit renvoyer un tableau.');
        }

        $smtp = $valeurs['smtp'] ?? null;
        if (!is_array($smtp)) {
            throw new ErreurConfiguration('Réglage manquant : smtp.');
        }

        $config = new self(
            self::texte($valeurs, 'url_portail'),
            self::texte($valeurs, 'dossier_donnees'),
            self::texte($valeurs, 'dossier_journaux'),
            self::texte($valeurs, 'adresse_alertes'),
            self::texte($smtp, 'hote', 'smtp.hote'),
            self::entier($smtp, 'port', 'smtp.port'),
            self::texte($smtp, 'utilisateur', 'smtp.utilisateur'),
            self::texte($smtp, 'mot_de_passe', 'smtp.mot_de_passe'),
            self::texte($valeurs, 'cle_chiffrement'),
        );
        $config->verifier();

        return $config;
    }

    /** Clé de chiffrement sous forme binaire (32 octets). */
    public function cleBinaire(): string
    {
        $cle = hex2bin($this->cleChiffrement);
        if ($cle === false) {
            throw new ErreurConfiguration('cle_chiffrement illisible.');
        }

        return $cle;
    }

    private function verifier(): void
    {
        if (!str_starts_with($this->urlPortail, 'https://') || filter_var($this->urlPortail, FILTER_VALIDATE_URL) === false) {
            throw new ErreurConfiguration('url_portail doit être une adresse https://.');
        }
        foreach (['dossier_donnees' => $this->dossierDonnees, 'dossier_journaux' => $this->dossierJournaux] as $nom => $dossier) {
            if (!str_starts_with($dossier, '/') || !is_dir($dossier)) {
                throw new ErreurConfiguration($nom . ' doit être un dossier existant, en chemin absolu.');
            }
        }
        foreach (['adresse_alertes' => $this->adresseAlertes, 'smtp.utilisateur' => $this->smtpUtilisateur] as $nom => $adresse) {
            if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
                throw new ErreurConfiguration($nom . ' doit être une adresse e-mail valide.');
            }
        }
        if ($this->smtpHote === '' || $this->smtpMotDePasse === '') {
            throw new ErreurConfiguration('smtp.hote et smtp.mot_de_passe doivent être remplis.');
        }
        if ($this->smtpPort < 1 || $this->smtpPort > 65535) {
            throw new ErreurConfiguration('smtp.port invalide.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $this->cleChiffrement) !== 1) {
            throw new ErreurConfiguration('cle_chiffrement doit compter 64 caractères hexadécimaux (32 octets), générés sur le serveur.');
        }
    }

    /** @param array<array-key, mixed> $tableau */
    private static function texte(array $tableau, string $cle, ?string $nom = null): string
    {
        $valeur = $tableau[$cle] ?? null;
        if (!is_string($valeur)) {
            throw new ErreurConfiguration('Réglage manquant ou invalide : ' . ($nom ?? $cle) . '.');
        }

        return $valeur;
    }

    /** @param array<array-key, mixed> $tableau */
    private static function entier(array $tableau, string $cle, string $nom): int
    {
        $valeur = $tableau[$cle] ?? null;
        if (!is_int($valeur)) {
            throw new ErreurConfiguration('Réglage manquant ou invalide : ' . $nom . '.');
        }

        return $valeur;
    }
}
