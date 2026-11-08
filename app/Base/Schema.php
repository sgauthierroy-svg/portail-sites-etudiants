<?php

declare(strict_types=1);

namespace Portail\Base;

use PDO;

/**
 * Structure de la base, par versions successives.
 *
 * Une version déjà appliquée n'est jamais modifiée : toute évolution
 * s'ajoute comme une nouvelle version. Modèle de données décidé le 07/11/2026
 * (cahier des charges, section « Modèle de données »).
 *
 * Dates : texte UTC au format 2026-11-07T13:45:00Z.
 */
final class Schema
{
    /** Durées de conservation (CNIL, délibération n° 2021-122 : 6 mois à 1 an). */
    public const CONSERVATION_CONNEXIONS = '-1 year';
    public const CONSERVATION_HISTORIQUE_SITES = '-2 years';

    private const MAINTENANT = "strftime('%Y-%m-%dT%H:%M:%SZ', 'now')";

    /** @return array<int, string> */
    public static function versions(): array
    {
        $maintenant = self::MAINTENANT;
        $limiteConnexions = "strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '" . self::CONSERVATION_CONNEXIONS . "')";
        $limiteSites = "strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '" . self::CONSERVATION_HISTORIQUE_SITES . "')";

        return [
            1 => <<<SQL
                -- ===== Comptes des personnes qui se connectent =====
                CREATE TABLE comptes (
                    id                  INTEGER PRIMARY KEY,
                    identifiant         TEXT NOT NULL UNIQUE
                                        CHECK (length(identifiant) BETWEEN 3 AND 32
                                               AND identifiant NOT GLOB '*[^a-z0-9]*'
                                               AND identifiant GLOB '[a-z]*'),
                    nom_affiche         TEXT NOT NULL CHECK (length(nom_affiche) BETWEEN 1 AND 100),
                    email               TEXT NOT NULL CHECK (length(email) BETWEEN 6 AND 254 AND email LIKE '%_@_%._%'),
                    role                TEXT NOT NULL CHECK (role IN ('administrateur', 'enseignant', 'administratif', 'consultation')),
                    mot_de_passe_hache  TEXT NOT NULL CHECK (length(mot_de_passe_hache) >= 60),
                    secret_2fa_chiffre  TEXT NULL,
                    double_auth_active  INTEGER NOT NULL DEFAULT 0 CHECK (double_auth_active IN (0, 1)),
                    dernier_pas_2fa     INTEGER NULL,
                    echecs_consecutifs  INTEGER NOT NULL DEFAULT 0 CHECK (echecs_consecutifs >= 0),
                    bloque_jusqu_a      TEXT NULL,
                    actif               INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
                    doit_changer_mdp    INTEGER NOT NULL DEFAULT 1 CHECK (doit_changer_mdp IN (0, 1)),
                    dernier_filtre      TEXT NULL CHECK (dernier_filtre IS NULL OR length(dernier_filtre) <= 500),
                    cree_le             TEXT NOT NULL DEFAULT ({$maintenant}),
                    derniere_connexion  TEXT NULL,
                    CHECK (double_auth_active = 0 OR secret_2fa_chiffre IS NOT NULL)
                );

                -- Le dernier administrateur actif ne peut être ni désactivé, ni rétrogradé, ni supprimé.
                CREATE TRIGGER comptes_dernier_admin_modif
                BEFORE UPDATE OF actif, role ON comptes
                WHEN OLD.role = 'administrateur' AND OLD.actif = 1
                     AND (NEW.actif = 0 OR NEW.role <> 'administrateur')
                     AND (SELECT count(*) FROM comptes WHERE role = 'administrateur' AND actif = 1) = 1
                BEGIN
                    SELECT RAISE(ABORT, 'dernier_administrateur');
                END;

                CREATE TRIGGER comptes_dernier_admin_suppr
                BEFORE DELETE ON comptes
                WHEN OLD.role = 'administrateur' AND OLD.actif = 1
                     AND (SELECT count(*) FROM comptes WHERE role = 'administrateur' AND actif = 1) = 1
                BEGIN
                    SELECT RAISE(ABORT, 'dernier_administrateur');
                END;

                -- ===== Tentatives de connexion (blocage par compte et par adresse) =====
                CREATE TABLE tentatives_connexion (
                    id                  INTEGER PRIMARY KEY,
                    date                TEXT NOT NULL DEFAULT ({$maintenant}),
                    adresse_ip          TEXT NOT NULL CHECK (length(adresse_ip) BETWEEN 2 AND 45),
                    identifiant_saisi   TEXT NOT NULL CHECK (length(identifiant_saisi) <= 64),
                    reussie             INTEGER NOT NULL CHECK (reussie IN (0, 1))
                );
                CREATE INDEX tentatives_par_ip ON tentatives_connexion (adresse_ip, date);
                CREATE INDEX tentatives_par_identifiant ON tentatives_connexion (identifiant_saisi, date);

                CREATE TRIGGER tentatives_non_modifiables
                BEFORE UPDATE ON tentatives_connexion
                BEGIN
                    SELECT RAISE(ABORT, 'trace_non_modifiable');
                END;

                CREATE TRIGGER tentatives_purge_seulement
                BEFORE DELETE ON tentatives_connexion
                WHEN OLD.date >= {$limiteConnexions}
                BEGIN
                    SELECT RAISE(ABORT, 'trace_non_supprimable');
                END;

                -- ===== Régimes (NDRC FI, NDRC ALT) et sessions (2027, 2028) =====
                CREATE TABLE regimes (
                    id      INTEGER PRIMARY KEY,
                    nom     TEXT NOT NULL UNIQUE COLLATE NOCASE
                            CHECK (length(nom) BETWEEN 1 AND 40 AND nom = trim(nom) AND nom NOT LIKE '%  %'),
                    actif   INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1))
                );

                CREATE TABLE sessions (
                    id                  INTEGER PRIMARY KEY,
                    annee               INTEGER NOT NULL UNIQUE CHECK (annee BETWEEN 2020 AND 2100),
                    version_wp_gelee    TEXT NULL CHECK (version_wp_gelee IS NULL OR version_wp_gelee GLOB '[0-9]*.[0-9]*'),
                    active              INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
                );

                -- ===== Étudiants =====
                CREATE TABLE etudiants (
                    id              INTEGER PRIMARY KEY,
                    nom_complet     TEXT NOT NULL CHECK (length(nom_complet) BETWEEN 1 AND 150),
                    identifiant     TEXT NOT NULL UNIQUE
                                    CHECK (length(identifiant) BETWEEN 2 AND 16
                                           AND identifiant NOT GLOB '*[^a-z0-9]*'
                                           AND identifiant GLOB '[a-z]*'),
                    identifiant_wp  TEXT NOT NULL CHECK (length(identifiant_wp) BETWEEN 2 AND 16
                                                         AND identifiant_wp NOT GLOB '*[^A-Za-z0-9]*'),
                    email           TEXT NOT NULL CHECK (length(email) BETWEEN 6 AND 254 AND email LIKE '%_@_%._%'),
                    regime_id       INTEGER NOT NULL REFERENCES regimes (id) ON DELETE RESTRICT,
                    session_id      INTEGER NOT NULL REFERENCES sessions (id) ON DELETE RESTRICT,
                    ajoute_le       TEXT NOT NULL DEFAULT ({$maintenant}),
                    ajoute_par      INTEGER NULL REFERENCES comptes (id) ON DELETE SET NULL,
                    origine         TEXT NOT NULL CHECK (origine IN ('import', 'saisie'))
                );
                CREATE INDEX etudiants_par_regime_session ON etudiants (regime_id, session_id);

                -- Historique du parcours (passage FI/ALT, redoublement, corrections).
                -- L'identifiant est recopié, et non relié : l'historique survit à la
                -- suppression de l'étudiant, jusqu'à sa purge (2 ans).
                CREATE TABLE historique_etudiants (
                    id                      INTEGER PRIMARY KEY,
                    date                    TEXT NOT NULL DEFAULT ({$maintenant}),
                    etudiant_identifiant    TEXT NOT NULL,
                    champ                   TEXT NOT NULL CHECK (champ IN ('regime', 'session', 'nom_complet', 'email')),
                    ancienne_valeur         TEXT NULL,
                    nouvelle_valeur         TEXT NULL,
                    auteur_compte_id        INTEGER NULL,
                    auteur_nom              TEXT NULL
                );
                CREATE INDEX historique_par_etudiant ON historique_etudiants (etudiant_identifiant, date);

                CREATE TRIGGER historique_non_modifiable
                BEFORE UPDATE ON historique_etudiants
                BEGIN
                    SELECT RAISE(ABORT, 'trace_non_modifiable');
                END;

                CREATE TRIGGER historique_purge_seulement
                BEFORE DELETE ON historique_etudiants
                WHEN OLD.date >= {$limiteSites}
                BEGIN
                    SELECT RAISE(ABORT, 'trace_non_supprimable');
                END;

                -- ===== Relevé de l'état réel des sites sur le serveur =====
                CREATE TABLE releve_sites (
                    id                      INTEGER PRIMARY KEY,
                    identifiant             TEXT NOT NULL,
                    dossier                 TEXT NOT NULL CHECK (dossier GLOB '/[A-Za-z]*' AND dossier NOT GLOB '*[^A-Za-z0-9/]*'),
                    logiciel                TEXT NULL CHECK (logiciel IS NULL OR logiciel IN ('wordpress', 'prestashop')),
                    version                 TEXT NULL,
                    https_actif             INTEGER NULL CHECK (https_actif IS NULL OR https_actif IN (0, 1)),
                    certificat_expire_le    TEXT NULL,
                    taille_octets           INTEGER NULL CHECK (taille_octets IS NULL OR taille_octets >= 0),
                    derniere_modif          TEXT NULL,
                    releve_le               TEXT NOT NULL,
                    UNIQUE (identifiant, dossier)
                );

                -- ===== Journal : qui a fait quoi =====
                -- connexion et administration : 1 an ; site : 2 ans.
                CREATE TABLE journal (
                    id          INTEGER PRIMARY KEY,
                    date        TEXT NOT NULL DEFAULT ({$maintenant}),
                    categorie   TEXT NOT NULL CHECK (categorie IN ('connexion', 'administration', 'site')),
                    compte_id   INTEGER NULL,
                    compte_nom  TEXT NULL,
                    adresse_ip  TEXT NULL CHECK (adresse_ip IS NULL OR length(adresse_ip) BETWEEN 2 AND 45),
                    action      TEXT NOT NULL CHECK (length(action) BETWEEN 1 AND 60),
                    cible       TEXT NULL CHECK (cible IS NULL OR length(cible) <= 200),
                    resultat    TEXT NOT NULL CHECK (resultat IN ('succes', 'echec', 'refus')),
                    detail      TEXT NULL CHECK (detail IS NULL OR length(detail) <= 1000)
                );
                CREATE INDEX journal_par_date ON journal (date);

                CREATE TRIGGER journal_non_modifiable
                BEFORE UPDATE ON journal
                BEGIN
                    SELECT RAISE(ABORT, 'trace_non_modifiable');
                END;

                CREATE TRIGGER journal_purge_seulement
                BEFORE DELETE ON journal
                WHEN (OLD.categorie IN ('connexion', 'administration') AND OLD.date >= {$limiteConnexions})
                  OR (OLD.categorie = 'site' AND OLD.date >= {$limiteSites})
                BEGIN
                    SELECT RAISE(ABORT, 'trace_non_supprimable');
                END;

                -- ===== Réglages de l'administrateur =====
                CREATE TABLE parametres (
                    cle     TEXT PRIMARY KEY CHECK (cle IN ('adresse_alertes', 'sites_proteges', 'bases_protegees')),
                    valeur  TEXT NOT NULL CHECK (length(valeur) <= 5000)
                );
                SQL,
            // Lot 3 : numéro de « génération » du compte. Il augmente à chaque
            // réinitialisation, désactivation ou changement de rôle : toutes les
            // sessions ouvertes avec l'ancien numéro sont aussitôt fermées.
            2 => <<<SQL
                ALTER TABLE comptes ADD COLUMN generation INTEGER NOT NULL DEFAULT 0 CHECK (generation >= 0);
                SQL,
            // Revue des comptes (décision du 07/11/2026) : alerte quand un compte actif
            // n'a pas été confirmé depuis 6 mois (CNIL, « Gérer les utilisateurs » :
            // revue régulière des droits accordés).
            3 => <<<SQL
                ALTER TABLE comptes ADD COLUMN revu_le TEXT;
                SQL,
            // Relevé des sous-domaines réellement présents sur le serveur (08/11/2026) :
            // l'import n'accepte un identifiant hors règle que s'il existe ici.
            4 => <<<SQL
                CREATE TABLE sous_domaines (
                    identifiant TEXT PRIMARY KEY
                                CHECK (length(identifiant) BETWEEN 2 AND 63
                                       AND identifiant NOT GLOB '*[^a-z0-9]*'
                                       AND identifiant GLOB '[a-z]*'),
                    releve_le   TEXT NOT NULL
                );
                SQL,
            // V2, lot 1 (08/11/2026) : file d'attente des travaux sur le serveur.
            // La page web ne fait qu'inscrire une demande ; le travailleur
            // (bin/travaux.php, tâche planifiée chaque minute) l'exécute, une étape
            // (un étudiant) à la fois. Liste fermée des types : un nouveau type
            // demande une nouvelle version de la base.
            5 => <<<SQL
                CREATE TABLE travaux (
                    id              INTEGER PRIMARY KEY,
                    type            TEXT NOT NULL CHECK (type IN (
                                        'releve', 'creer_prestashop', 'deployer_sujet_wp', 'creer_wordpress',
                                        'reinitialiser_wordpress', 'reinitialiser_prestashop', 'supprimer_site',
                                        'supprimer_etudiant', 'restaurer', 'deployer_sujet_prestashop')),
                    parametres      TEXT NOT NULL DEFAULT '{}' CHECK (length(parametres) <= 2000),
                    etat            TEXT NOT NULL DEFAULT 'en_attente'
                                    CHECK (etat IN ('en_attente', 'en_cours', 'termine', 'annule')),
                    demande_le      TEXT NOT NULL DEFAULT ({$maintenant}),
                    demande_par     INTEGER NULL REFERENCES comptes (id) ON DELETE SET NULL,
                    demande_par_nom TEXT NOT NULL CHECK (length(demande_par_nom) BETWEEN 1 AND 100),
                    commence_le     TEXT NULL,
                    termine_le      TEXT NULL
                );
                CREATE INDEX travaux_par_etat ON travaux (etat, id);

                CREATE TABLE travaux_etapes (
                    id          INTEGER PRIMARY KEY,
                    travail_id  INTEGER NOT NULL REFERENCES travaux (id) ON DELETE CASCADE,
                    rang        INTEGER NOT NULL CHECK (rang >= 1),
                    cible       TEXT NOT NULL CHECK (length(cible) BETWEEN 1 AND 63 AND cible NOT GLOB '*[^a-z0-9]*'),
                    libelle     TEXT NOT NULL CHECK (length(libelle) BETWEEN 1 AND 150),
                    etat        TEXT NOT NULL DEFAULT 'en_attente'
                                CHECK (etat IN ('en_attente', 'en_cours', 'reussi', 'echec', 'ignore', 'annule')),
                    message     TEXT NULL CHECK (message IS NULL OR length(message) <= 1000),
                    commence_le TEXT NULL,
                    termine_le  TEXT NULL,
                    UNIQUE (travail_id, rang),
                    UNIQUE (travail_id, cible)
                );
                SQL,
            // V2, lot 2 (08/11/2026) : bibliothèque (paquets PrestaShop, sujets) et
            // espace documentaire (PDF). Les fichiers sont rangés sous le nom de leur
            // empreinte SHA-256 dans data/bibliotheque et data/documents, hors d'atteinte du web.
            6 => <<<SQL
                CREATE TABLE paquets (
                    id                  INTEGER PRIMARY KEY,
                    type                TEXT NOT NULL CHECK (type IN ('prestashop', 'sujet_wordpress', 'sujet_prestashop')),
                    nom                 TEXT NOT NULL CHECK (length(nom) BETWEEN 1 AND 100),
                    version             TEXT NULL CHECK (version IS NULL OR (length(version) <= 20
                                            AND version GLOB '[0-9]*.[0-9]*' AND version NOT GLOB '*[^0-9.]*')),
                    version_wordpress   TEXT NULL CHECK (version_wordpress IS NULL OR (length(version_wordpress) <= 20
                                            AND version_wordpress NOT GLOB '*[^0-9.]*')),
                    empreinte           TEXT NOT NULL UNIQUE CHECK (length(empreinte) = 64 AND empreinte NOT GLOB '*[^0-9a-f]*'),
                    taille              INTEGER NOT NULL CHECK (taille > 0),
                    depose_le           TEXT NOT NULL DEFAULT ({$maintenant}),
                    depose_par          INTEGER NULL REFERENCES comptes (id) ON DELETE SET NULL,
                    depose_par_nom      TEXT NOT NULL CHECK (length(depose_par_nom) BETWEEN 1 AND 100),
                    CHECK (type <> 'prestashop' OR version IS NOT NULL)
                );
                CREATE UNIQUE INDEX paquets_une_version_prestashop ON paquets (version) WHERE type = 'prestashop';

                -- La session porte sa version PrestaShop (décision du 08/11/2026) :
                -- un paquet utilisé par une session ne peut pas être retiré.
                ALTER TABLE sessions ADD COLUMN paquet_prestashop_id INTEGER NULL REFERENCES paquets (id) ON DELETE RESTRICT;

                CREATE TABLE documents (
                    id              INTEGER PRIMARY KEY,
                    titre           TEXT NOT NULL CHECK (length(titre) BETWEEN 1 AND 150),
                    empreinte       TEXT NOT NULL UNIQUE CHECK (length(empreinte) = 64 AND empreinte NOT GLOB '*[^0-9a-f]*'),
                    taille          INTEGER NOT NULL CHECK (taille > 0),
                    depose_le       TEXT NOT NULL DEFAULT ({$maintenant}),
                    depose_par      INTEGER NULL REFERENCES comptes (id) ON DELETE SET NULL,
                    depose_par_nom  TEXT NOT NULL CHECK (length(depose_par_nom) BETWEEN 1 AND 100)
                );
                SQL,
            // V2, lot 3 (08/11/2026) : sites créés par le portail, et secret chiffré
            // d'une étape (mot de passe de l'étudiant) gardé le temps d'envoyer le
            // récapitulatif à l'administrateur, puis effacé.
            7 => <<<SQL
                ALTER TABLE travaux_etapes ADD COLUMN secret TEXT NULL CHECK (secret IS NULL OR length(secret) <= 1000);

                CREATE TABLE sites (
                    id              INTEGER PRIMARY KEY,
                    identifiant     TEXT NOT NULL CHECK (length(identifiant) BETWEEN 2 AND 63 AND identifiant NOT GLOB '*[^a-z0-9]*'),
                    dossier         TEXT NOT NULL CHECK (dossier GLOB '/[A-Za-z]*' AND dossier NOT GLOB '*[^A-Za-z0-9/]*'),
                    logiciel        TEXT NOT NULL CHECK (logiciel IN ('wordpress', 'prestashop')),
                    version         TEXT NULL CHECK (version IS NULL OR (length(version) <= 20 AND version NOT GLOB '*[^0-9.]*')),
                    dossier_admin   TEXT NULL CHECK (dossier_admin IS NULL OR (length(dossier_admin) <= 90
                                        AND dossier_admin GLOB 'admin-*' AND dossier_admin NOT GLOB '*[^a-z0-9-]*')),
                    base            TEXT NOT NULL CHECK (length(base) <= 64 AND base NOT GLOB '*[^A-Za-z0-9_]*'),
                    utilisateur_bdd TEXT NOT NULL CHECK (length(utilisateur_bdd) <= 47 AND utilisateur_bdd NOT GLOB '*[^a-z0-9_]*'),
                    cree_le         TEXT NOT NULL DEFAULT ({$maintenant}),
                    travail_id      INTEGER NULL REFERENCES travaux (id) ON DELETE SET NULL,
                    UNIQUE (identifiant, dossier)
                );
                SQL,
            // Gel de PrestaShop par surveillance (décision du 08/11/2026) : le relevé note
            // si le module « Assistant de mise à jour » (autoupgrade) est apparu dans une boutique.
            8 => <<<SQL
                ALTER TABLE releve_sites ADD COLUMN module_mise_a_jour INTEGER NULL CHECK (module_mise_a_jour IS NULL OR module_mise_a_jour IN (0, 1));
                SQL,
            // Actions de secours de la direction vérifiées par un administrateur (décision du 08/11/2026) :
            // elles quittent alors l'accueil. L'entrée du journal elle-même ne change jamais.
            9 => <<<SQL
                CREATE TABLE secours_verifies (
                    journal_id      INTEGER PRIMARY KEY,
                    verifie_par_nom TEXT NOT NULL CHECK (length(verifie_par_nom) BETWEEN 1 AND 100),
                    verifie_le      TEXT NOT NULL DEFAULT ({$maintenant})
                );
                SQL,
        ];
    }

    /**
     * Applique les versions manquantes, chacune dans une transaction.
     *
     * @return list<int> Versions appliquées lors de cet appel
     */
    public static function mettreAJour(PDO $pdo): array
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_versions (
            version     INTEGER PRIMARY KEY,
            appliquee_le TEXT NOT NULL DEFAULT (' . self::MAINTENANT . ')
        )');

        $appliquees = [];
        foreach (self::versions() as $version => $sql) {
            $existe = $pdo->prepare('SELECT 1 FROM schema_versions WHERE version = :v');
            $existe->execute([':v' => $version]);
            if ($existe->fetchColumn() !== false) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                // Texte SQL écrit dans ce fichier, sans aucune donnée extérieure.
                $pdo->exec($sql); // nosemgrep: outils.semgrep.requete-sql-construite-par-concatenation
                $ajout = $pdo->prepare('INSERT INTO schema_versions (version) VALUES (:v)');
                $ajout->execute([':v' => $version]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $appliquees[] = $version;
        }

        return $appliquees;
    }

    /**
     * Efface les traces arrivées au terme de leur durée de conservation.
     * Seule suppression permise par la base sur ces tables.
     *
     * @return int Nombre de lignes effacées
     */
    public static function purger(PDO $pdo): int
    {
        $limiteConnexions = "strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '" . self::CONSERVATION_CONNEXIONS . "')";
        $limiteSites = "strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '" . self::CONSERVATION_HISTORIQUE_SITES . "')";

        $total = 0;
        foreach ([
            "DELETE FROM journal WHERE categorie IN ('connexion', 'administration') AND date < {$limiteConnexions}",
            "DELETE FROM journal WHERE categorie = 'site' AND date < {$limiteSites}",
            "DELETE FROM tentatives_connexion WHERE date < {$limiteConnexions}",
            "DELETE FROM historique_etudiants WHERE date < {$limiteSites}",
            "DELETE FROM travaux WHERE etat IN ('termine', 'annule') AND demande_le < {$limiteSites}",
        ] as $requete) {
            // Requêtes fixes écrites ci-dessus, sans aucune donnée extérieure.
            $effacees = $pdo->exec($requete); // nosemgrep: outils.semgrep.requete-sql-construite-par-concatenation
            $total += $effacees === false ? 0 : $effacees;
        }

        return $total;
    }
}
