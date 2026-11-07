<?php

declare(strict_types=1);

namespace Portail\Journal;

use PDO;

/**
 * Écriture dans le journal « qui a fait quoi ».
 *
 * Le journal ne peut qu'être complété : la base refuse toute modification,
 * et toute suppression avant la fin de la durée de conservation.
 */
final class Journal
{
    public const CATEGORIES = ['connexion', 'administration', 'site'];
    public const RESULTATS = ['succes', 'echec', 'refus'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param 'connexion'|'administration'|'site' $categorie
     * @param 'succes'|'echec'|'refus' $resultat
     */
    public function ecrire(
        string $categorie,
        string $action,
        string $resultat,
        ?int $compteId = null,
        ?string $compteNom = null,
        ?string $adresseIp = null,
        ?string $cible = null,
        ?string $detail = null,
    ): void {
        $requete = $this->pdo->prepare(
            'INSERT INTO journal (categorie, compte_id, compte_nom, adresse_ip, action, cible, resultat, detail)
             VALUES (:categorie, :compte_id, :compte_nom, :adresse_ip, :action, :cible, :resultat, :detail)'
        );
        $requete->execute([
            ':categorie' => $categorie,
            ':compte_id' => $compteId,
            ':compte_nom' => $compteNom === null ? null : self::couper($compteNom, 100),
            ':adresse_ip' => $adresseIp,
            ':action' => self::couper($action, 60),
            ':cible' => $cible === null ? null : self::couper($cible, 200),
            ':resultat' => $resultat,
            ':detail' => $detail === null ? null : self::couper($detail, 1000),
        ]);
    }

    private static function couper(string $texte, int $longueur): string
    {
        return mb_substr($texte, 0, $longueur, 'UTF-8');
    }
}
