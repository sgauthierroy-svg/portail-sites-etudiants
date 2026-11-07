<?php

declare(strict_types=1);

namespace Portail\Comptes;

use PDO;

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
        $requete = $this->pdo->prepare('UPDATE comptes SET mot_de_passe_hache = :hache, doit_changer_mdp = 0 WHERE id = :id');
        $requete->execute([':hache' => $hache, ':id' => $id]);
    }

    /** Remplace l'empreinte par une plus robuste, sans changer le mot de passe. */
    public function rehacher(int $id, string $hache): void
    {
        $requete = $this->pdo->prepare('UPDATE comptes SET mot_de_passe_hache = :hache WHERE id = :id');
        $requete->execute([':hache' => $hache, ':id' => $id]);
    }

    private function compte(mixed $ligne): ?Compte
    {
        return is_array($ligne) ? Compte::depuisLigne($ligne) : null;
    }
}
