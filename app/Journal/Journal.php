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

    /**
     * Entrées récentes pour une action donnée (alertes de la page d'accueil).
     *
     * @return list<array{date: string, auteur: string, cible: string, detail: string}>
     */
    public function recentes(string $action, string $depuis): array
    {
        $requete = $this->pdo->prepare('SELECT date, compte_nom, cible, detail FROM journal
            WHERE action = :action AND date >= :depuis ORDER BY date DESC, id DESC LIMIT 50');
        $requete->execute([':action' => $action, ':depuis' => $depuis]);
        $liste = [];
        foreach (\Portail\Base\Lecture::lignes($requete) as $ligne) {
            $liste[] = [
                'date' => \Portail\Base\Lecture::texte($ligne['date'] ?? null) ?? '',
                'auteur' => \Portail\Base\Lecture::texte($ligne['compte_nom'] ?? null) ?? '',
                'cible' => \Portail\Base\Lecture::texte($ligne['cible'] ?? null) ?? '',
                'detail' => \Portail\Base\Lecture::texte($ligne['detail'] ?? null) ?? '',
            ];
        }

        return $liste;
    }

    /** Catégories du journal, dans l'ordre d'affichage du filtre. */
    public const LIBELLES = ['administration' => 'Actions', 'site' => 'Sites', 'connexion' => 'Connexions'];

    /**
     * Entrées du journal, les plus récentes d'abord.
     *
     * @param list<string> $categories Catégories autorisées pour le lecteur (liste fermée, jamais reçue telle quelle du visiteur)
     * @param int|null $avant Identifiant de la dernière entrée déjà affichée (page suivante)
     * @return list<array{id: int, date: string, categorie: string, auteur: string, ip: string, action: string, cible: string, resultat: string, detail: string}>
     */
    public function lister(array $categories, ?int $avant, int $limite): array
    {
        $categories = array_values(array_intersect(array_keys(self::LIBELLES), $categories));
        if ($categories === []) {
            return [];
        }
        $marques = [];
        $parametres = [];
        foreach ($categories as $rang => $categorie) {
            $marques[] = ':c' . $rang;
            $parametres[':c' . $rang] = $categorie;
        }
        $parametres[':avant'] = $avant ?? PHP_INT_MAX;
        $parametres[':limite'] = max(1, min($limite, 500));
        // Les marques :c0, :c1… sont fabriquées ici, à partir d'une liste fermée : aucune donnée du visiteur dans le SQL.
        $sql = 'SELECT id, date, categorie, compte_nom, adresse_ip, action, cible, resultat, detail FROM journal WHERE categorie IN ('
            . implode(', ', $marques) . ') AND id < :avant ORDER BY id DESC LIMIT :limite';
        $requete = $this->pdo->prepare($sql); // nosemgrep: outils.semgrep.requete-sql-construite-par-concatenation
        $requete->execute($parametres);
        $liste = [];
        foreach (\Portail\Base\Lecture::lignes($requete) as $ligne) {
            $id = \Portail\Base\Lecture::entier($ligne['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $texte = static fn (string $cle): string => \Portail\Base\Lecture::texte($ligne[$cle] ?? null) ?? '';
            $liste[] = ['id' => $id, 'date' => $texte('date'), 'categorie' => $texte('categorie'), 'auteur' => $texte('compte_nom'),
                'ip' => $texte('adresse_ip'), 'action' => $texte('action'), 'cible' => $texte('cible'), 'resultat' => $texte('resultat'),
                'detail' => $texte('detail')];
        }

        return $liste;
    }
}
