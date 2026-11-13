<?php

declare(strict_types=1);

namespace Portail\Base;

use PDO;
use Portail\Noyau\Config;
use Portail\Noyau\ErreurConfiguration;

/**
 * Installation et mise à jour de la base, lancées en SSH par bin/installer.php.
 * Peut être relancée sans risque : rien n'est écrasé.
 */
final class Installation
{
    /** Sites jamais modifiables par le portail (cahier des charges, section Périmètre). */
    public const SITES_PROTEGES = [
        'portail', 'peda', 'prova', 'supsallanches.com', 'suitecrm.supsallanches.com',
        'bachelor-sup-sallanches.com', 'new-ecs.jafl3067.odns.fr', 'public_html',
        'sandrine', 'sandrine2', 'alt-event.fr',
        // Site modèle d'examen (13/11/2026) : seuls ses propres travaux le touchent, jamais ceux des étudiants.
        'sujet',
    ];

    /** Bases jamais modifiables par le portail. */
    public const BASES_PROTEGEES = [
        'jafl3067_peda', 'jafl3067_prestashop1', 'jafl3067_prova', 'jafl3067_wp169',
        'jafl3067_wp295', 'jafl3067_wp804', 'jafl3067_wp960', 'jafl3067_suit612',
        'jafl3067_new-ecs', 'jafl3067_test', 'jafl3067_supsallanches', 'jafl3067_bts_sup_sallanches',
    ];

    /** Dossiers privés à créer, en droits 700. */
    public const DOSSIERS_PRIVES = ['data', 'data/sessions', 'data/tmp', 'data/bibliotheque', 'data/documents', 'data/depot', 'logs'];

    /** @return list<string> Compte rendu, ligne par ligne */
    public static function installer(string $racine): array
    {
        $compteRendu = [];
        foreach (self::DOSSIERS_PRIVES as $relatif) {
            $dossier = $racine . '/' . $relatif;
            if (!is_dir($dossier) && !mkdir($dossier, 0700, true)) {
                throw new ErreurConfiguration('Impossible de créer ' . $relatif . '.');
            }
            if (!chmod($dossier, 0700)) {
                throw new ErreurConfiguration('Impossible de protéger ' . $relatif . ' (droits 700).');
            }
            $compteRendu[] = 'Dossier ' . $relatif . ' : droits 700 ✓';
        }

        $config = Config::charger($racine . '/data/config.php');
        $compteRendu[] = 'Configuration lue et vérifiée ✓';
        // Bibliothèque (V2, lot 2) : contrôle des archives ZIP déposées.
        $compteRendu[] = class_exists(\ZipArchive::class)
            ? 'Extension zip de PHP : présente ✓'
            : 'Extension zip de PHP : ABSENTE ✗ (la bibliothèque refusera les dépôts ; à activer dans CPanel, « Sélectionner une version de PHP »)';

        $pdo = Connexion::ouvrir($config->dossierDonnees . '/portail.sqlite');
        $versions = Schema::mettreAJour($pdo);
        $compteRendu[] = $versions === []
            ? 'Base déjà à jour ✓'
            : 'Base : version(s) ' . implode(', ', $versions) . ' appliquée(s) ✓';

        $compteRendu[] = self::parametreParDefaut($pdo, 'adresse_alertes', $config->adresseAlertes);
        $compteRendu[] = self::parametreParDefaut($pdo, 'sites_proteges', self::json(self::SITES_PROTEGES));
        $compteRendu[] = self::parametreParDefaut($pdo, 'bases_protegees', self::json(self::BASES_PROTEGEES));
        $compteRendu[] = self::ajouterProtege($pdo, \Portail\Sites\SiteModele::IDENTIFIANT);

        $effacees = Schema::purger($pdo);
        $compteRendu[] = 'Traces arrivées à échéance effacées : ' . $effacees;

        return $compteRendu;
    }

    /** Ajoute un sous-domaine à la liste des sites protégés d'une installation existante (sans rien retirer). */
    private static function ajouterProtege(PDO $pdo, string $identifiant): string
    {
        $lecture = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'sites_proteges'");
        $lecture->execute();
        $liste = Lecture::tableau(json_decode((string) Lecture::texte($lecture->fetchColumn()), true)) ?? [];
        if (in_array($identifiant, $liste, true)) {
            return 'Site protégé ' . $identifiant . ' : déjà dans la liste ✓';
        }
        $liste[] = $identifiant;
        $pdo->prepare("UPDATE parametres SET valeur = :v WHERE cle = 'sites_proteges'")
            ->execute([':v' => json_encode(array_values($liste), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

        return 'Site protégé ' . $identifiant . ' : ajouté à la liste ✓';
    }

    private static function parametreParDefaut(PDO $pdo, string $cle, string $valeur): string
    {
        $ajout = $pdo->prepare('INSERT OR IGNORE INTO parametres (cle, valeur) VALUES (:cle, :valeur)');
        $ajout->execute([':cle' => $cle, ':valeur' => $valeur]);

        return 'Réglage ' . $cle . ($ajout->rowCount() === 1 ? ' : valeur par défaut posée ✓' : ' : déjà présent, conservé ✓');
    }

    /** @param list<string> $liste */
    private static function json(array $liste): string
    {
        return json_encode($liste, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
