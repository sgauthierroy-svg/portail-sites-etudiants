<?php

declare(strict_types=1);

namespace Portail\Travaux;

use PDO;
use Portail\Base\Lecture;
use Portail\Courriel\Expediteur;
use Portail\Journal\Journal;
use Portail\Sites\ConfigWordPress;
use Portail\Sites\DeploiementSujet;
use Portail\Sites\EpreuveWordPress;
use Portail\Sites\LigneWordPress;
use Portail\Sites\MiseALecart;
use Portail\Sites\OutilsWordPress;
use Portail\Sites\Releve;
use Portail\Sites\ServeurCpanel;
use Portail\Systeme\Fichiers;
use RuntimeException;
use Throwable;

/**
 * Clôture de l'épreuve dans le /WP2 d'un étudiant (V2, lot 4, paquet 3 ; décisions des 13 et 14/11/2026) :
 *  1. copie datée du /WP2 (base et fichiers), gardée 8 jours à l'écart comme les sites remplacés ;
 *  2. sessions WordPress fermées : les étudiants préconnectés sont déconnectés (ils n'ont pas le mot de passe) ;
 *  3. lecture seule : module du portail dans wp-content/mu-plugins + réglages du bloc de wp-config.php.
 * Seuls les /WP2 déployés par le portail sont clos. Si la copie échoue, rien n'est changé dans le /WP2.
 */
final class ExecutantCloturerEpreuve implements Executant, ApresTravail
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ServeurCpanel $cpanel,
        private readonly LigneWordPress $wp,
        private readonly MiseALecart $ecart,
        private readonly ?Expediteur $expediteur,
        private readonly string $dossierPersonnel,
        private readonly string $dossierTemporaire,
    ) {
    }

    public function executer(string $cible, array $parametres): array
    {
        $masque = umask(0022);
        try {
            return $this->cloturer($cible);
        } finally {
            umask($masque);
        }
    }

    /** @return array{etat: 'reussi', message: string} */
    private function cloturer(string $cible): array
    {
        $existe = $this->pdo->prepare('SELECT 1 FROM sites WHERE identifiant = :i AND dossier = :d');
        $existe->execute([':i' => $cible, ':d' => DeploiementSujet::DOSSIER]);
        if ($existe->fetchColumn() === false) {
            throw new RuntimeException('Ce /WP2 n\'a pas été déployé par le portail : rien n\'a été fait.');
        }
        $site = EpreuveWordPress::racine($this->cpanel, $this->dossierPersonnel, $cible) . DeploiementSujet::DOSSIER;
        $fichierConfig = $site . '/wp-config.php';
        $config = is_file($fichierConfig) && !is_link($fichierConfig) ? file_get_contents($fichierConfig) : false;
        if ($config === false) {
            throw new RuntimeException('wp-config.php du /WP2 introuvable : rien n\'a été fait.');
        }
        $prefixe = EpreuveWordPress::prefixe($config);

        // 1. Copie datée, mise à l'écart 8 jours (décision du 14/11/2026).
        $racineCopies = $this->dossierTemporaire . '/copies';
        if (!is_dir($racineCopies) && !mkdir($racineCopies, 0700)) {
            throw new RuntimeException('Création du dossier des copies impossible : rien n\'a été fait.');
        }
        $copie = $racineCopies . '/copie-' . $cible . '-' . OutilsWordPress::suffixe();
        try {
            if (!mkdir($copie, 0700) || !mkdir($copie . '/fichiers', 0755)) {
                throw new RuntimeException('Création du dossier de la copie impossible.');
            }
            $this->wp->lancer($site, ['db', 'export', $copie . '/base.sql'], 900);
            $nombre = Fichiers::copierDossier($site, $copie . '/fichiers');
            $infos = json_encode(['identifiant' => $cible, 'copie_le' => gmdate('Y-m-d\TH:i:s\Z'), 'fichiers' => $nombre], JSON_THROW_ON_ERROR);
            if (file_put_contents($copie . '/copie.json', $infos) === false) {
                throw new RuntimeException('Écriture de la copie impossible.');
            }
            $this->ecart->mettre($copie, $cible, DeploiementSujet::DOSSIER, null, null, 'copie datée du /WP2 à la clôture de l\'épreuve');
        } catch (Throwable $e) {
            if (is_dir($copie) && !is_link($copie)) {
                Fichiers::effacerDossier($copie, $racineCopies);
            }
            throw new RuntimeException($e->getMessage() . ' L\'épreuve n\'est pas close pour cet étudiant : rien n\'a été changé dans son /WP2.', 0, $e);
        }

        // 2. et 3. Sessions fermées, puis lecture seule.
        try {
            $this->wp->lancer($site, ['db', 'query', 'DELETE FROM `' . $prefixe . "usermeta` WHERE meta_key IN ('session_tokens', 'portail_activite')"]);
            EpreuveWordPress::poserModule($site);
            EpreuveWordPress::ecrireReglages($fichierConfig, ['gele' => ConfigWordPress::estGele($config) ?? true, 'lecture_seule' => true]);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage() . ' Copie datée faite ; le /WP2 n\'est peut-être pas figé : relancez la clôture pour cet étudiant.', 0, $e);
        }
        $this->pdo->prepare("UPDATE sites SET cloture_le = strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), lecture_seule = 1 WHERE identifiant = :i AND dossier = :d")
            ->execute([':i' => $cible, ':d' => DeploiementSujet::DOSSIER]);

        return ['etat' => 'reussi', 'message' => 'Épreuve close : sessions fermées, copie datée gardée 8 jours (' . $nombre . ' fichiers), site en lecture seule.'];
    }

    public function apresTravail(int $travailId, array $parametres): void
    {
        $requete = $this->pdo->prepare('SELECT cible, libelle, etat FROM travaux_etapes WHERE travail_id = :t ORDER BY rang');
        $requete->execute([':t' => $travailId]);
        $closes = '';
        $echecs = 0;
        $total = 0;
        foreach (Lecture::lignes($requete) as $etape) {
            $total++;
            $identifiant = (string) Lecture::texte($etape['cible']);
            if (Lecture::texte($etape['etat']) === 'reussi') {
                $adresse = 'https://' . $identifiant . '.' . Releve::DOMAINE . DeploiementSujet::DOSSIER . '/';
                $closes .= '   - ' . (Lecture::texte($etape['libelle']) ?? $identifiant) . ' : ' . $adresse . "wp-admin/\n";
            } else {
                $echecs++;
            }
        }
        if ($total === 0) {
            return;
        }
        $auteur = Lecture::texte($parametres['demande_par_nom'] ?? null) ?? 'un compte du portail';
        $texte = "Bonjour,\n\n" . 'L\'épreuve a été close par ' . $auteur . ' (travail n° ' . $travailId . ') : '
            . ($total - $echecs) . ' /WP2 sur ' . $total . ".\n\n"
            . ($closes === '' ? "Aucun /WP2 n'a été clos.\n" : "/WP2 clos (sessions fermées, copie datée gardée 8 jours, lecture seule) :\n" . $closes)
            . ($echecs > 0 ? "\n" . $echecs . " étudiant(s) en échec : voir le détail du travail dans le portail, puis relancer la clôture pour eux.\n" : '')
            . "\nPour corriger : se connecter avec le compte administrateur du site modèle (aucun mot de passe dans ce message)."
            . "\nTout se consulte ; rien ne peut être enregistré. Déconnexion après 1 h d'inactivité."
            . "\nUn enseignant peut lever le gel d'un /WP2 depuis la page « Site modèle d'examen » du portail.\n\n-- \nMessage automatique du portail des sites étudiants.";
        $destinataires = $this->destinataires();
        $journal = new Journal($this->pdo);
        try {
            if ($this->expediteur === null || $destinataires === []) {
                throw new RuntimeException('aucun expéditeur ou destinataire');
            }
            $this->expediteur->envoyer($destinataires, 'Épreuve close dans les /WP2 (travail n° ' . $travailId . ')', $texte);
            $journal->ecrire('site', 'e-mail envoyé', 'succes', null, 'travailleur', null, 'travail n° ' . $travailId, 'clôture de l\'épreuve → ' . count($destinataires) . ' destinataire(s)');
        } catch (Throwable $e) {
            $journal->ecrire('site', 'échec d\'envoi d\'e-mail', 'echec', null, 'travailleur', null, 'travail n° ' . $travailId, mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
        }
    }

    /** @return list<string> Adresse d'alerte et comptes enseignants actifs (sans doublon) */
    private function destinataires(): array
    {
        $liste = [];
        $lecture = $this->pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'adresse_alertes'");
        $lecture->execute();
        $alerte = Lecture::texte($lecture->fetchColumn());
        if ($alerte !== null) {
            $liste[] = $alerte;
        }
        foreach (Lecture::lignes($this->pdo->query("SELECT email FROM comptes WHERE role = 'enseignant' AND actif = 1 ORDER BY id")) as $ligne) {
            $email = Lecture::texte($ligne['email']);
            if ($email !== null && !in_array(strtolower($email), array_map('strtolower', $liste), true)) {
                $liste[] = $email;
            }
        }

        return $liste;
    }
}
