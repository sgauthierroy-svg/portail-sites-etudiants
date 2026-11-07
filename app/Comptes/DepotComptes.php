<?php

declare(strict_types=1);

namespace Portail\Comptes;

use PDO;
use Portail\Base\Lecture;

/**
 * Lecture et écriture des comptes en base (requêtes préparées uniquement).
 */
final class DepotComptes
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function parIdentifiant(string $identifiant): ?Compte
    {
        $requete = $this->pdo->prepare('SELECT * FROM comptes WHERE identifiant = :identifiant');
        $requete->execute([':identifiant' => $identifiant]);

        return $this->compte($requete->fetch());
    }

    public function parId(int $id): ?Compte
    {
        $requete = $this->pdo->prepare('SELECT * FROM comptes WHERE id = :id');
        $requete->execute([':id' => $id]);

        return $this->compte($requete->fetch());
    }

    /**
     * Ajoute un compte (mot de passe provisoire, à changer à la première connexion).
     *
     * @param 'administrateur'|'enseignant'|'administratif'|'consultation' $role
     */
    public function creer(string $identifiant, string $nomAffiche, string $email, string $role, string $motDePasseHache): int
    {
        $requete = $this->pdo->prepare(
            'INSERT INTO comptes (identifiant, nom_affiche, email, role, mot_de_passe_hache, doit_changer_mdp)
             VALUES (:identifiant, :nom, :email, :role, :hache, 1)'
        );
        $requete->execute([':identifiant' => $identifiant, ':nom' => $nomAffiche, ':email' => $email,
            ':role' => $role, ':hache' => $motDePasseHache]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Un échec de plus ; blocage jusqu'à $bloqueJusqua si le seuil est atteint. */
    public function enregistrerEchec(int $id, ?int $bloqueJusqua): void
    {
        $requete = $this->pdo->prepare(
            'UPDATE comptes SET echecs_consecutifs = CASE WHEN :bloque IS NULL THEN echecs_consecutifs + 1 ELSE 0 END,
                                bloque_jusqu_a = COALESCE(:bloque2, bloque_jusqu_a)
             WHERE id = :id'
        );
        $date = $bloqueJusqua === null ? null : Compte::date($bloqueJusqua);
        $requete->execute([':bloque' => $date, ':bloque2' => $date, ':id' => $id]);
    }

    public function enregistrerConnexion(int $id, int $pas, int $maintenant): void
    {
        $requete = $this->pdo->prepare(
            'UPDATE comptes SET echecs_consecutifs = 0, bloque_jusqu_a = NULL, dernier_pas_2fa = :pas,
                                derniere_connexion = :date WHERE id = :id'
        );
        $requete->execute([':pas' => $pas, ':date' => Compte::date($maintenant), ':id' => $id]);
    }

    public function activerDoubleAuth(int $id, string $secretChiffre): void
    {
        $requete = $this->pdo->prepare(
            'UPDATE comptes SET secret_2fa_chiffre = :secret, double_auth_active = 1 WHERE id = :id AND double_auth_active = 0'
        );
        $requete->execute([':secret' => $secretChiffre, ':id' => $id]);
    }

    public function changerMotDePasse(int $id, string $hache): void
    {
        // Génération +1 : les autres sessions ouvertes avec l'ancien mot de passe sont fermées.
        $requete = $this->pdo->prepare('UPDATE comptes SET mot_de_passe_hache = :hache, doit_changer_mdp = 0, generation = generation + 1 WHERE id = :id');
        $requete->execute([':hache' => $hache, ':id' => $id]);
    }

    /** Remplace l'empreinte par une plus robuste, sans changer le mot de passe. */
    public function rehacher(int $id, string $hache): void
    {
        $requete = $this->pdo->prepare('UPDATE comptes SET mot_de_passe_hache = :hache WHERE id = :id');
        $requete->execute([':hache' => $hache, ':id' => $id]);
    }

    /** @return list<Compte> Tous les comptes, administrateurs d'abord */
    public function liste(): array
    {
        $comptes = [];
        $resultat = $this->pdo->query(
            "SELECT * FROM comptes ORDER BY actif DESC, CASE role WHEN 'administrateur' THEN 0 WHEN 'enseignant' THEN 1
             WHEN 'administratif' THEN 2 ELSE 3 END, nom_affiche"
        );
        /** @var list<array<string, mixed>> $lignes Mode de lecture : tableau associatif (Connexion) */
        $lignes = $resultat === false ? [] : $resultat->fetchAll();
        foreach ($lignes as $ligne) {
            $comptes[] = Compte::depuisLigne($ligne);
        }

        return $comptes;
    }

    /** @param 'administrateur'|'enseignant'|'administratif'|'consultation' $role */
    public function modifier(int $id, string $nomAffiche, string $email, string $role): void
    {
        $requete = $this->pdo->prepare(
            'UPDATE comptes SET nom_affiche = :nom, email = :email,
                    generation = generation + CASE WHEN role <> :role THEN 1 ELSE 0 END, role = :role2
             WHERE id = :id'
        );
        $requete->execute([':nom' => $nomAffiche, ':email' => $email, ':role' => $role, ':role2' => $role, ':id' => $id]);
    }

    public function changerActif(int $id, bool $actif): void
    {
        $requete = $this->pdo->prepare('UPDATE comptes SET actif = :actif, generation = generation + 1 WHERE id = :id');
        $requete->execute([':actif' => $actif ? 1 : 0, ':id' => $id]);
    }

    /** Mot de passe provisoire : à changer à la prochaine connexion ; le compte est débloqué. */
    public function motDePasseProvisoire(int $id, string $hache): void
    {
        $requete = $this->pdo->prepare(
            'UPDATE comptes SET mot_de_passe_hache = :hache, doit_changer_mdp = 1, echecs_consecutifs = 0,
                    bloque_jusqu_a = NULL, generation = generation + 1 WHERE id = :id'
        );
        $requete->execute([':hache' => $hache, ':id' => $id]);
    }

    /** Le téléphone sera à relier de nouveau à la prochaine connexion. */
    public function reinitialiserDoubleAuth(int $id): void
    {
        $requete = $this->pdo->prepare(
            'UPDATE comptes SET secret_2fa_chiffre = NULL, double_auth_active = 0, dernier_pas_2fa = NULL,
                    echecs_consecutifs = 0, bloque_jusqu_a = NULL, generation = generation + 1 WHERE id = :id'
        );
        $requete->execute([':id' => $id]);
    }

    /** Dernier filtre de la liste des étudiants (« regime=3;session=2 »), retrouvé à la connexion suivante. */
    public function dernierFiltre(int $id): ?string
    {
        $requete = $this->pdo->prepare('SELECT dernier_filtre FROM comptes WHERE id = :id');
        $requete->execute([':id' => $id]);
        return Lecture::texte($requete->fetchColumn());
    }

    public function enregistrerFiltre(int $id, ?string $filtre): void
    {
        $requete = $this->pdo->prepare('UPDATE comptes SET dernier_filtre = :filtre WHERE id = :id');
        $requete->execute([':filtre' => $filtre, ':id' => $id]);
    }

    /**
     * Comptes actifs non confirmés depuis la limite (création ou dernière revue).
     *
     * @return list<array{id: int, identifiant: string, nom: string, depuis: string}>
     */
    public function aRevoir(string $limite): array
    {
        $requete = $this->pdo->prepare('SELECT id, identifiant, nom_affiche, coalesce(revu_le, cree_le) AS depuis FROM comptes
            WHERE actif = 1 AND coalesce(revu_le, cree_le) < :limite ORDER BY depuis');
        $requete->execute([':limite' => $limite]);
        $liste = [];
        foreach (Lecture::lignes($requete) as $ligne) {
            $id = Lecture::entier($ligne['id'] ?? null);
            $identifiant = Lecture::texte($ligne['identifiant'] ?? null);
            $nom = Lecture::texte($ligne['nom_affiche'] ?? null);
            $depuis = Lecture::texte($ligne['depuis'] ?? null);
            if ($id !== null && $identifiant !== null && $nom !== null && $depuis !== null) {
                $liste[] = ['id' => $id, 'identifiant' => $identifiant, 'nom' => $nom, 'depuis' => $depuis];
            }
        }

        return $liste;
    }

    public function marquerRevu(int $id, string $date): void
    {
        $requete = $this->pdo->prepare('UPDATE comptes SET revu_le = :date WHERE id = :id');
        $requete->execute([':date' => $date, ':id' => $id]);
    }

    public function revuLe(int $id): ?string
    {
        $requete = $this->pdo->prepare('SELECT coalesce(revu_le, cree_le) FROM comptes WHERE id = :id');
        $requete->execute([':id' => $id]);

        return Lecture::texte($requete->fetchColumn());
    }

    private function compte(mixed $ligne): ?Compte
    {
        return is_array($ligne) ? Compte::depuisLigne($ligne) : null;
    }
}
