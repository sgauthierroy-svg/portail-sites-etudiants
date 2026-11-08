<?php

declare(strict_types=1);

namespace Portail\Alertes;

use PDO;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Courriel\Expediteur;
use Portail\Journal\Journal;
use Throwable;

/**
 * Alertes par e-mail (décisions du 08/11/2026).
 *
 * Les alertes sont mises en attente pendant la requête, puis envoyées par
 * expedier() une fois la page transmise au visiteur : un envoi lent ne
 * ralentit pas la connexion. Un échec d'envoi ne bloque jamais le portail ;
 * il est inscrit au journal. Contenu minimal : jamais de mot de passe, jamais
 * de donnée d'étudiant.
 */
final class Alertes
{
    /** @var list<array{destinataires: list<string>, sujet: string, texte: string}> */
    private array $enAttente = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Expediteur $expediteur,
        private readonly string $urlPortail,
    ) {
    }

    /** Action de la direction sur un compte : à l'adresse d'alerte et à la personne du compte touché. */
    public function secoursDirection(Compte $auteur, string $action, string $compteTouche, ?string $emailTouche): void
    {
        $this->ajouter([$emailTouche], 'Action de secours de la direction',
            $auteur->nomAffiche . ' (rôle Direction) a effectué une action de secours sur le compte « ' . $compteTouche . ' » : ' . $action . '.'
            . "\n\nSi cette action n'était pas attendue, désactivez le compte concerné et prévenez la direction.");
    }

    /** Compte bloqué après 5 échecs : à l'adresse d'alerte et à la personne. */
    public function compteBloque(Compte $compte, string $ip, int $minutes): void
    {
        $this->ajouter([$compte->email], 'Compte bloqué après plusieurs échecs',
            'Le compte « ' . $compte->identifiant . ' » (' . $compte->nomAffiche . ') est bloqué pendant ' . $minutes . ' minutes '
            . 'après plusieurs mots de passe ou codes faux, depuis l\'adresse ' . $ip . '.'
            . "\n\nSi ce n'est pas vous qui avez essayé de vous connecter, prévenez l'administrateur du portail : "
            . 'quelqu\'un tente peut-être de deviner votre mot de passe.');
    }

    public function adresseBloquee(string $ip, int $echecs, int $minutes): void
    {
        $this->ajouter([], 'Adresse IP bloquée',
            'L\'adresse ' . $ip . ' est bloquée pendant ' . $minutes . ' minutes après ' . $echecs . ' échecs de connexion. '
            . "Il peut s'agir d'une tentative d'intrusion, ou d'un réseau partagé (l'école, par exemple) où quelqu'un s'acharne.");
    }

    public function connexionDirection(Compte $compte, string $ip): void
    {
        $this->ajouter([], 'Connexion d\'un compte Direction',
            $compte->nomAffiche . ' (« ' . $compte->identifiant . ' », rôle Direction) vient de se connecter au portail depuis l\'adresse ' . $ip . '.'
            . "\n\nCe rôle sert au suivi et au secours : vérifiez que cette connexion était attendue.");
    }

    public function roleAdministrateur(string $identifiant, string $nom, string $auteur): void
    {
        $this->ajouter([], 'Rôle Administrateur attribué',
            'Le compte « ' . $identifiant . ' » (' . $nom . ') a maintenant le rôle Administrateur. Attribué par : ' . $auteur . '.');
    }

    /** @param list<array{id: int, identifiant: string, nom: string, depuis: string}> $comptes */
    public function revueHebdomadaire(array $comptes): void
    {
        if ($comptes === []) {
            return;
        }
        $liste = '';
        foreach ($comptes as $compte) {
            $liste .= "\n- " . $compte['nom'] . ' (' . $compte['identifiant'] . '), non confirmé depuis le ' . self::jour($compte['depuis']);
        }
        $this->ajouter([], count($comptes) . ' compte(s) du portail à revoir',
            'Ces comptes n\'ont pas été confirmés depuis 6 mois :' . $liste
            . "\n\nPour chacun : la personne en a-t-elle encore besoin ? Dans le portail, ouvrez le compte, puis « Confirmer » ou « Désactiver ».");
    }

    public function erreurTechnique(string $reference): void
    {
        $this->ajouter([], 'Erreur technique du portail',
            'Le portail a rencontré une erreur (référence ' . $reference . ').'
            . "\n\nLe détail est dans le journal des erreurs du serveur : ~/portail/logs/php_errors.log (rechercher la référence)."
            . "\nAu plus une alerte de ce type par heure : d'autres erreurs ont pu suivre.");
    }

    /** Travail terminé avec au moins une étape en échec (V2, lot 1). */
    public function travailEnEchec(int $id, string $libelle, int $echecs, int $total): void
    {
        $this->ajouter([], 'Travail terminé avec ' . $echecs . ' échec(s)',
            'Le travail n° ' . $id . ' (« ' . $libelle . ' ») est terminé : ' . $echecs . ' étape(s) en échec sur ' . $total . '.'
            . "\n\nLe détail de chaque étape est dans le portail : " . rtrim($this->urlPortail, '/') . '/travaux/' . $id);
    }

    public function test(): void
    {
        $this->ajouter([], 'Test des alertes', 'Ce message de test confirme que le portail peut envoyer ses alertes.');
    }

    public function nombreEnAttente(): int
    {
        return count($this->enAttente);
    }

    /** Envoie les alertes en attente. Ne lève jamais d'exception. @return int Nombre d'envois réussis */
    public function expedier(): int
    {
        $reussis = 0;
        $journal = new Journal($this->pdo);
        foreach ($this->enAttente as $alerte) {
            try {
                if ($this->expediteur === null) {
                    continue;
                }
                $this->expediteur->envoyer($alerte['destinataires'], '[Portail] ' . $alerte['sujet'], $alerte['texte']);
                $journal->ecrire('administration', 'alerte envoyée', 'succes', null, 'portail', null, null,
                    $alerte['sujet'] . ' → ' . implode(', ', $alerte['destinataires']));
                $reussis++;
            } catch (Throwable $e) {
                try {
                    $journal->ecrire('administration', 'échec d\'envoi d\'alerte', 'echec', null, 'portail', null, null,
                        $alerte['sujet'] . ' : ' . $e->getMessage());
                } catch (Throwable) {
                    // Le journal lui-même est indisponible : rien de plus à faire sans risquer d'aggraver.
                }
            }
        }
        $this->enAttente = [];

        return $reussis;
    }

    /** @param list<string|null> $autres Destinataires en plus de l'adresse d'alerte */
    private function ajouter(array $autres, string $sujet, string $texte): void
    {
        $destinataires = [];
        foreach (array_merge([$this->adresseAlertes()], $autres) as $adresse) {
            if (is_string($adresse) && filter_var($adresse, FILTER_VALIDATE_EMAIL) !== false
                && !in_array(strtolower($adresse), array_map('strtolower', $destinataires), true)) {
                $destinataires[] = $adresse;
            }
        }
        if ($destinataires === []) {
            return;
        }
        $this->enAttente[] = ['destinataires' => $destinataires, 'sujet' => $sujet,
            'texte' => $texte . "\n\nJournal du portail : " . rtrim($this->urlPortail, '/') . '/journal'
                . "\n\n-- \nMessage automatique du portail des sites étudiants. Ne pas répondre."];
    }

    private function adresseAlertes(): ?string
    {
        $resultat = $this->pdo->query("SELECT valeur FROM parametres WHERE cle = 'adresse_alertes'");

        return $resultat === false ? null : Lecture::texte($resultat->fetchColumn());
    }

    private static function jour(string $date): string
    {
        $instant = strtotime($date);

        return $instant === false ? $date : gmdate('d/m/Y', $instant);
    }
}
