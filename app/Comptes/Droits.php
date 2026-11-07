<?php

declare(strict_types=1);

namespace Portail\Comptes;

/**
 * Qui peut faire quoi (cahier des charges, section « Utilisateurs, rôles et droits »,
 * décisions des 29/10 et 07/11/2026 : l'administratif crée régimes
 * et sessions et change la session d'un étudiant).
 *
 * Chaque droit est vérifié par le serveur à chaque requête : masquer un bouton
 * ne suffit jamais. Un droit absent du tableau est refusé à tous.
 */
final class Droits
{
    public const CONSULTER = 'consulter';
    /** Journal des actions (étudiants, sites, comptes). */
    public const JOURNAL = 'journal';
    /** Journal des connexions (adresses IP, échecs) : informaticien seulement (décision du 07/11/2026). */
    public const JOURNAL_CONNEXIONS = 'journal_connexions';
    /** Fiche détaillée d'un étudiant (identifiant, e-mail, parcours). */
    public const VOIR_FICHE = 'voir_fiche';
    public const IMPORTER = 'importer';
    public const CREER_ETUDIANT = 'creer_etudiant';
    public const MODIFIER_ETUDIANT = 'modifier_etudiant';
    public const CHANGER_SESSION = 'changer_session';
    public const DEMANDER = 'demander';
    public const REINITIALISER_SITE = 'reinitialiser_site';
    public const BIBLIOTHEQUE = 'bibliotheque';
    public const SUPPRIMER = 'supprimer';
    public const TRAITER_DEMANDES = 'traiter_demandes';
    public const REGIMES_SESSIONS = 'regimes_sessions';
    public const GERER_COMPTES = 'gerer_comptes';
    public const PARAMETRES = 'parametres';

    /** @var array<string, list<string>> */
    private const MATRICE = [
        'administrateur' => [
            self::CONSULTER, self::VOIR_FICHE, self::JOURNAL, self::JOURNAL_CONNEXIONS, self::IMPORTER, self::CREER_ETUDIANT, self::MODIFIER_ETUDIANT,
            self::CHANGER_SESSION, self::DEMANDER, self::REINITIALISER_SITE, self::BIBLIOTHEQUE, self::SUPPRIMER,
            self::TRAITER_DEMANDES, self::REGIMES_SESSIONS, self::GERER_COMPTES, self::PARAMETRES,
        ],
        'enseignant' => [
            self::CONSULTER, self::VOIR_FICHE, self::JOURNAL, self::IMPORTER, self::CREER_ETUDIANT, self::MODIFIER_ETUDIANT,
            self::CHANGER_SESSION, self::DEMANDER, self::REINITIALISER_SITE, self::BIBLIOTHEQUE, self::REGIMES_SESSIONS,
        ],
        'administratif' => [
            self::CONSULTER, self::VOIR_FICHE, self::JOURNAL, self::IMPORTER, self::CREER_ETUDIANT, self::MODIFIER_ETUDIANT, self::DEMANDER,
            self::REGIMES_SESSIONS, self::CHANGER_SESSION, // décisions du 07/11/2026
        ],
        // Direction adjointe (libellé « Direction ») : suivi à distance (classes ; sites en
        // activité et alertes en V2, affichés dans le portail, jamais envoyés par e-mail), pas
        // de fiche détaillée. Secours (décision du 07/11/2026) : tous les comptes, y compris
        // créer un administrateur, et le journal complet. Chaque action de secours est signalée
        // aux administrateurs (GestionComptes).
        'consultation' => [
            self::CONSULTER, self::JOURNAL, self::JOURNAL_CONNEXIONS, self::GERER_COMPTES,
        ],
    ];

    public static function peut(string $role, string $droit): bool
    {
        return in_array($droit, self::MATRICE[$role] ?? [], true);
    }

    /** @return list<string> */
    public static function de(string $role): array
    {
        return self::MATRICE[$role] ?? [];
    }
}
