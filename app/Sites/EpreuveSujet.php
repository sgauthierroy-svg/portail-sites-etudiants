<?php

declare(strict_types=1);

namespace Portail\Sites;

use Portail\Comptes\Compte;
use Portail\Noyau\ErreurSaisie;
use Portail\Travaux\FileTravaux;
use PDO;

/**
 * Côté web de la clôture de l'épreuve et du gel des /WP2 (V2, lot 4, paquet 3 ; décisions des
 * 13 et 14/11/2026). La préconnexion des back-offices reste faite à la main. Rien n'est fait sur
 * le serveur ici : chaque demande devient un travail, un étudiant par étape.
 */
final class EpreuveSujet
{
    public const CLOTURER = 'cloturer_epreuve';
    public const GEL = 'lever_gel_epreuve';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<string> $coches */
    public function cloturer(int $sessionId, array $coches, bool $confirme, Compte $auteur, ?string $adresseIp): int
    {
        $etapes = $this->etapes($sessionId, $coches,
            static fn (array $e): bool => $e['deploye_le'] !== null && !$e['lecture_seule'],
            'ne peut pas être clos (pas de /WP2 déployé par le portail, ou déjà figé)');
        if (!$confirme) {
            throw new ErreurSaisie('Cochez la case de confirmation : les étudiants cochés seront déconnectés et leur /WP2 figé.');
        }

        return (new FileTravaux($this->pdo))->demander(self::CLOTURER, ['demande_par_nom' => $auteur->nomAffiche], $etapes, $auteur, $adresseIp);
    }

    /**
     * Lever le gel (figer = false) ou refiger (figer = true) des /WP2 clos.
     *
     * @param list<string> $coches
     */
    public function gel(int $sessionId, array $coches, bool $figer, Compte $auteur, ?string $adresseIp): int
    {
        $etapes = $figer
            ? $this->etapes($sessionId, $coches, static fn (array $e): bool => $e['cloture_le'] !== null && !$e['lecture_seule'],
                'ne peut pas être refigé (épreuve pas close, ou déjà figé)')
            : $this->etapes($sessionId, $coches, static fn (array $e): bool => $e['lecture_seule'],
                'n\'a pas de /WP2 figé');

        return (new FileTravaux($this->pdo))->demander(self::GEL, ['figer' => $figer, 'demande_par_nom' => $auteur->nomAffiche], $etapes, $auteur, $adresseIp);
    }

    /**
     * Étapes du travail (ordre alphabétique des noms) ; refus si un étudiant coché ne convient pas.
     *
     * @param list<string> $coches
     * @param callable(array{identifiant: string, nom: string, regime: string, empechement: ?string, remplace: bool,
     *                       deploye_le: ?string, cloture_le: ?string, lecture_seule: bool, epreuve_prevue: bool}): bool $possible
     * @return list<array{cible: string, libelle: string}>
     */
    private function etapes(int $sessionId, array $coches, callable $possible, string $refus): array
    {
        $deploiement = new DeploiementSujet($this->pdo);
        if (!in_array($sessionId, array_column($deploiement->sessions(), 'id'), true)) {
            throw new ErreurSaisie('Session inconnue ou archivée.');
        }
        if ($coches === []) {
            throw new ErreurSaisie('Cochez au moins un étudiant.');
        }
        $etapes = [];
        $refuses = [];
        $connus = [];
        foreach ($deploiement->etudiants($sessionId) as $etudiant) {
            if (!in_array($etudiant['identifiant'], $coches, true)) {
                continue;
            }
            $connus[] = $etudiant['identifiant'];
            if ($etudiant['epreuve_prevue'] || $etudiant['empechement'] === 'déploiement déjà prévu' || !$possible($etudiant)) {
                $refuses[] = $etudiant['nom'];
            } else {
                $etapes[] = ['cible' => $etudiant['identifiant'], 'libelle' => $etudiant['nom']];
            }
        }
        if (count($connus) !== count(array_unique($coches))) {
            throw new ErreurSaisie('Un étudiant coché n\'est pas dans cette session. Rechargez la page.');
        }
        if ($refuses !== []) {
            throw new ErreurSaisie(count($refuses) . ' étudiant(s) coché(s) : ' . $refus . ', ou travail déjà prévu — '
                . implode(', ', $refuses) . '. Décochez-les ; rien n\'a été demandé.');
        }

        return $etapes;
    }
}
