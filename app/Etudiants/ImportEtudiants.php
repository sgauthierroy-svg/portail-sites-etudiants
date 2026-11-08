<?php

declare(strict_types=1);

namespace Portail\Etudiants;

use PDO;
use Portail\Base\Installation;
use Portail\Base\Lecture;
use Portail\Comptes\Compte;
use Portail\Journal\Journal;
use Portail\Noyau\ErreurSaisie;
use Portail\Sites\Releve;

/**
 * Import des étudiants depuis l'onglet « Export portail » de la Google Sheets
 * (décisions du 08/11/2026).
 *
 * Le texte est collé dans le portail : il n'est jamais écrit sur le disque du
 * serveur. L'aperçu ne modifie rien ; à la validation, le texte est analysé de
 * nouveau (rien n'est cru de l'aperçu) et les lignes retenues sont enregistrées
 * en une seule opération.
 *
 * Statut de chaque ligne :
 * - vert   : nouvel étudiant conforme, importé ;
 * - orange : à vérifier, importé seulement si sa case est cochée ;
 * - gris   : étudiant déjà présent, jamais modifié par l'import ;
 * - rouge  : refusé, avec la raison.
 */
final class ImportEtudiants
{
    public const OCTETS_MAX = 1_000_000;
    public const LIGNES_MAX = 500;

    public const MESSAGE_EXISTANT = 'Étudiant déjà présent dans le portail : cette ligne n\'est pas importée. '
        . 'Pour modifier son e-mail ou sa classe, passez par sa fiche.';

    /** Titres de colonnes reconnus (en minuscules, sans accent ni espace). */
    private const COLONNES = [
        'nom' => ['nomprenom', 'nomcomplet', 'nometprenom'],
        'classe' => ['classe', 'regime'],
        'session' => ['session'],
        'identifiant' => ['identifiant', 'sousdomaine', 'identifiantsousdomaine'],
        'wp' => ['identifiantwordpress', 'identifiantwp', 'loginwordpress'],
        'email' => ['email', 'mail', 'emaildeletudiant', 'emailetudiant', 'adresseemail', 'courriel'],
    ];

    /** Une colonne dont le titre contient l'un de ces mots fait refuser le fichier entier. */
    private const COLONNES_INTERDITES = ['motdepasse', 'mdp', 'password', 'passwd', 'naissance', 'codechiffre'];

    private readonly RegimesSessions $referentiel;

    public function __construct(private readonly PDO $pdo, private readonly ?Compte $auteur = null, private readonly ?string $adresseIp = null)
    {
        $this->referentiel = new RegimesSessions($pdo);
    }

    /**
     * Analyse le texte collé, sans rien enregistrer.
     *
     * @return list<array{numero: int, nom: string, classe: string, session: string, identifiant: string, wp: string, email: string,
     *     statut: string, raisons: list<string>, notes: list<string>, regimeId: ?int, sessionId: ?int, existantId: ?int}>
     */
    public function analyser(string $texte): array
    {
        if (strlen($texte) > self::OCTETS_MAX) {
            throw new ErreurSaisie('Texte trop long (1 Mo au plus).');
        }
        if (!mb_check_encoding($texte, 'UTF-8')) {
            throw new ErreurSaisie('Le texte n\'est pas en UTF-8 : copiez-le directement depuis la Google Sheets.');
        }
        $texte = (string) preg_replace('/^\x{FEFF}/u', '', $texte);
        $lignes = array_values(array_filter(preg_split('/\R/u', $texte) ?: [], static fn (string $l): bool => trim($l) !== ''));
        if (count($lignes) < 2) {
            throw new ErreurSaisie('Collez la ligne des titres de colonnes puis au moins une ligne d\'étudiant.');
        }
        $separateur = self::separateur($lignes[0]);
        $positions = self::colonnes(str_getcsv($lignes[0], $separateur, '"', ''));
        if (count($lignes) - 1 > self::LIGNES_MAX) {
            throw new ErreurSaisie('Trop de lignes (' . self::LIGNES_MAX . ' étudiants au plus par import).');
        }

        $resultat = [];
        $vus = [];
        foreach (array_slice($lignes, 1) as $rang => $brute) {
            $cellules = str_getcsv($brute, $separateur, '"', '');
            $valeur = static function (string $cle) use ($cellules, $positions): string {
                $cellule = $cellules[$positions[$cle]] ?? null;

                return is_string($cellule) ? trim((string) preg_replace('/\s+/u', ' ', $cellule)) : '';
            };
            $ligne = $this->examiner($rang + 2, $valeur('nom'), $valeur('classe'), $valeur('session'), $valeur('identifiant'), $valeur('wp'), $valeur('email'));
            if ($ligne['identifiant'] !== '') {
                $vus[$ligne['identifiant']][] = count($resultat);
            }
            $resultat[] = $ligne;
        }
        // Doublons dans le texte : toutes les occurrences sont refusées.
        foreach ($vus as $identifiant => $rangs) {
            if (count($rangs) > 1) {
                foreach ($rangs as $r) {
                    $l = $resultat[$r];
                    $raisons = $l['statut'] === 'rouge' ? $l['raisons'] : [];
                    $raisons[] = 'Identifiant « ' . $identifiant . ' » présent plusieurs fois dans le texte collé.';
                    $resultat[$r] = self::ligne($l['numero'], $l['nom'], $l['classe'], $l['session'], $l['identifiant'], $l['wp'], $l['email'],
                        'rouge', $raisons, [], $l['regimeId'], $l['sessionId'], null);
                }
            }
        }

        return $resultat;
    }

    /**
     * Enregistre les lignes vertes et les lignes orange cochées, après une nouvelle analyse.
     *
     * @param list<int> $orangesCoches Numéros des lignes orange cochées
     * @return array{importes: int, presents: int, refuses: int, non_coches: int}
     */
    public function importer(string $texte, array $orangesCoches): array
    {
        $lignes = $this->analyser($texte);
        $bilan = ['importes' => 0, 'presents' => 0, 'refuses' => 0, 'non_coches' => 0];
        $journal = new Journal($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $ajout = $this->pdo->prepare('INSERT INTO etudiants (nom_complet, identifiant, identifiant_wp, email, regime_id, session_id, ajoute_par, origine)
                VALUES (:nom, :identifiant, :wp, :email, :regime, :session, :par, \'import\')');
            foreach ($lignes as $ligne) {
                $retenue = $ligne['statut'] === 'vert' || ($ligne['statut'] === 'orange' && in_array($ligne['numero'], $orangesCoches, true));
                if (!$retenue) {
                    $cle = match ($ligne['statut']) { 'gris' => 'presents', 'rouge' => 'refuses', default => 'non_coches' };
                    $bilan[$cle]++;
                    continue;
                }
                $ajout->execute([
                    ':nom' => $ligne['nom'], ':identifiant' => $ligne['identifiant'], ':wp' => $ligne['wp'], ':email' => $ligne['email'],
                    ':regime' => $ligne['regimeId'], ':session' => $ligne['sessionId'], ':par' => $this->auteur?->id,
                ]);
                $journal->ecrire('administration', 'ajout d\'étudiant', 'succes', $this->auteur?->id, $this->auteur?->nomAffiche, $this->adresseIp,
                    $ligne['identifiant'], 'import – ' . $ligne['nom'] . ' – ' . $ligne['classe'] . ' – session ' . $ligne['session']
                    . ($ligne['statut'] === 'orange' ? ' (vérifié : ' . implode(' ', $ligne['raisons']) . ')' : ''));
                $bilan['importes']++;
            }
            $journal->ecrire('administration', 'import d\'étudiants', 'succes', $this->auteur?->id, $this->auteur?->nomAffiche, $this->adresseIp, null,
                $bilan['importes'] . ' importés, ' . $bilan['presents'] . ' déjà présents, ' . $bilan['refuses'] . ' refusés, '
                . $bilan['non_coches'] . ' à vérifier non cochés');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $bilan;
    }

    /**
     * @return array{numero: int, nom: string, classe: string, session: string, identifiant: string, wp: string, email: string,
     *     statut: string, raisons: list<string>, notes: list<string>, regimeId: ?int, sessionId: ?int, existantId: ?int}
     */
    private function examiner(int $numero, string $nom, string $classe, string $session, string $identifiant, string $wp, string $email): array
    {
        $rouge = [];
        $orange = [];
        $notes = [];
        foreach (['nom complet' => $nom, 'classe' => $classe, 'session' => $session, 'identifiant' => $identifiant,
            'identifiant WordPress' => $wp, 'e-mail' => $email] as $libelle => $contenu) {
            if ($contenu === '') {
                $rouge[] = 'Champ vide : ' . $libelle . '.';
            }
        }
        if (mb_strlen($nom, 'UTF-8') > 150) {
            $rouge[] = 'Nom complet trop long (150 caractères au plus).';
        }
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254)) {
            $rouge[] = 'E-mail mal formé.';
        }

        $regimeId = $classe === '' ? null : $this->referentiel->idRegime($classe);
        if ($classe !== '' && ($regimeId === null || !$this->referentiel->regimeActif($regimeId))) {
            $rouge[] = 'Classe « ' . $classe . ' » inexistante ou désactivée : créez-la ou réactivez-la d\'abord'
                . (str_contains($classe, '-') ? ' (tiret à la place d\'une espace ?).' : '.');
        }
        $sessionId = preg_match('/^20[0-9]{2}$/D', $session) === 1 ? $this->referentiel->idSession((int) $session) : null;
        if ($session !== '' && ($sessionId === null || !$this->referentiel->sessionActive($sessionId))) {
            $rouge[] = 'Session « ' . $session . ' » inexistante ou archivée : créez-la ou réactivez-la d\'abord.';
        }

        $formeSousDomaine = preg_match('/^[a-z][a-z0-9]{1,15}$/D', $identifiant) === 1;
        if ($identifiant !== '' && !$formeSousDomaine) {
            $rouge[] = 'Identifiant invalide pour un sous-domaine : lettres minuscules sans accent et chiffres, 16 au plus, commençant par une lettre.';
        }
        if ($wp !== '' && preg_match('/^[A-Za-z0-9]{2,16}$/D', $wp) !== 1) {
            $rouge[] = 'Identifiant WordPress invalide : lettres sans accent et chiffres, 2 à 16.';
        }
        if (in_array($identifiant, Installation::SITES_PROTEGES, true)) {
            $rouge[] = 'Identifiant réservé à un site protégé.';
        }

        $existant = $formeSousDomaine ? $this->existant($identifiant) : null;
        $existantId = null;
        if ($existant !== null) {
            if (self::memeNom($existant['nom'], $nom)) {
                $existantId = $existant['id'];
            } else {
                $rouge[] = 'Identifiant déjà pris par un autre étudiant (' . $existant['nom'] . ').';
            }
        }

        if ($rouge === [] && $existantId !== null) {
            $ecarts = [];
            if (strcasecmp($existant['email'] ?? '', $email) !== 0) {
                $ecarts[] = 'e-mail';
            }
            if ($existant !== null && ($existant['regime_id'] !== $regimeId || $existant['session_id'] !== $sessionId)) {
                $ecarts[] = 'classe';
            }
            $notes[] = self::MESSAGE_EXISTANT . ($ecarts === [] ? '' : ' Différence avec la fiche : ' . implode(', ', $ecarts) . '.');

            return self::ligne($numero, $nom, $classe, $session, $identifiant, $wp, $email, 'gris', [], $notes, $regimeId, $sessionId, $existantId);
        }

        if ($rouge === []) {
            $surServeur = $this->sousDomaineExiste($identifiant);
            $lettres = (string) preg_replace('/[2-9]$/', '', $identifiant);
            try {
                $regle = Identifiant::depuisNomComplet($nom);
            } catch (ErreurSaisie) {
                $regle = null;
            }
            $horsRegle = !Identifiant::formatValide($identifiant) || $regle !== $lettres;
            if ($horsRegle) {
                $explication = $regle === null ? 'la règle de la feuille ne s\'applique pas à ce nom'
                    : 'la règle de la feuille donne « ' . $regle . ' »';
                if ($surServeur) {
                    $notes[] = 'Identifiant hors règle (' . $explication . '), mais sous-domaine trouvé sur le serveur ✓';
                } elseif (Releve::dernier($this->pdo) === null) {
                    $rouge[] = 'Identifiant hors règle (' . $explication . ') : aucun relevé des sous-domaines n\'a encore été fait pour le vérifier.';
                } else {
                    $rouge[] = 'Identifiant hors règle (' . $explication . ') et aucun sous-domaine à ce nom sur le serveur.';
                }
            } elseif ($surServeur) {
                $notes[] = 'Sous-domaine déjà présent sur le serveur ✓';
            }
            if (preg_match('/^[a-z]+[0-9]?$/D', $identifiant) === 1 && $wp !== Identifiant::wordpress($identifiant)) {
                $orange[] = 'Identifiant WordPress différent de celui attendu (« ' . Identifiant::wordpress($identifiant) . ' »).';
            }
        }

        $statut = $rouge !== [] ? 'rouge' : ($orange !== [] ? 'orange' : 'vert');

        return self::ligne($numero, $nom, $classe, $session, $identifiant, $wp, $email, $statut,
            $rouge !== [] ? $rouge : $orange, $notes, $regimeId, $sessionId, null);
    }

    /**
     * @param list<string> $raisons
     * @param list<string> $notes
     * @return array{numero: int, nom: string, classe: string, session: string, identifiant: string, wp: string, email: string,
     *     statut: string, raisons: list<string>, notes: list<string>, regimeId: ?int, sessionId: ?int, existantId: ?int}
     */
    private static function ligne(int $numero, string $nom, string $classe, string $session, string $identifiant, string $wp, string $email,
        string $statut, array $raisons, array $notes, ?int $regimeId, ?int $sessionId, ?int $existantId): array
    {
        return ['numero' => $numero, 'nom' => $nom, 'classe' => $classe, 'session' => $session, 'identifiant' => $identifiant, 'wp' => $wp,
            'email' => $email, 'statut' => $statut, 'raisons' => $raisons, 'notes' => $notes, 'regimeId' => $regimeId,
            'sessionId' => $sessionId, 'existantId' => $existantId];
    }

    /** @return array{id: int, nom: string, email: string, regime_id: int, session_id: int}|null */
    private function existant(string $identifiant): ?array
    {
        $requete = $this->pdo->prepare('SELECT id, nom_complet, email, regime_id, session_id FROM etudiants WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);
        $ligne = Lecture::ligne($requete);
        if ($ligne === null) {
            return null;
        }
        $id = Lecture::entier($ligne['id'] ?? null);
        $nom = Lecture::texte($ligne['nom_complet'] ?? null);
        $email = Lecture::texte($ligne['email'] ?? null);
        $regime = Lecture::entier($ligne['regime_id'] ?? null);
        $session = Lecture::entier($ligne['session_id'] ?? null);

        return $id === null || $nom === null || $email === null || $regime === null || $session === null ? null
            : ['id' => $id, 'nom' => $nom, 'email' => $email, 'regime_id' => $regime, 'session_id' => $session];
    }

    private function sousDomaineExiste(string $identifiant): bool
    {
        $requete = $this->pdo->prepare('SELECT 1 FROM sous_domaines WHERE identifiant = :i');
        $requete->execute([':i' => $identifiant]);

        return $requete->fetchColumn() !== false;
    }

    private static function memeNom(string $a, string $b): bool
    {
        $normaliser = static fn (string $n): string => mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $n)), 'UTF-8');

        return $normaliser($a) === $normaliser($b);
    }

    private static function separateur(string $titres): string
    {
        if (str_contains($titres, "\t")) {
            return "\t";
        }

        return substr_count($titres, ';') >= substr_count($titres, ',') ? ';' : ',';
    }

    /**
     * @param array<int, string|null> $titres
     * @return array<string, int> colonne => position
     */
    private static function colonnes(array $titres): array
    {
        $positions = [];
        foreach ($titres as $position => $titre) {
            $cle = self::normaliser((string) $titre);
            foreach (self::COLONNES_INTERDITES as $interdit) {
                if (str_contains($cle, $interdit)) {
                    throw new ErreurSaisie('Texte refusé en entier : il contient une colonne « ' . trim((string) $titre)
                        . ' ». Le portail ne reçoit ni mot de passe, ni date de naissance, ni code chiffré : collez seulement l\'onglet « Export portail ».');
                }
            }
            foreach (self::COLONNES as $colonne => $noms) {
                if (in_array($cle, $noms, true) && !isset($positions[$colonne])) {
                    $positions[$colonne] = $position;
                }
            }
        }
        $manquantes = array_diff(array_keys(self::COLONNES), array_keys($positions));
        if ($manquantes !== []) {
            $libelles = ['nom' => 'NomPrénom', 'classe' => 'Classe', 'session' => 'Session', 'identifiant' => 'Identifiant',
                'wp' => 'Identifiant WordPress', 'email' => 'E-mail'];
            throw new ErreurSaisie('Colonnes manquantes : ' . implode(', ', array_map(static fn (string $c): string => $libelles[$c], $manquantes))
                . '. Collez l\'onglet « Export portail » avec sa ligne de titres.');
        }

        return $positions;
    }

    private static function normaliser(string $titre): string
    {
        $titre = strtr(mb_strtolower($titre, 'UTF-8'), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c']);

        return (string) preg_replace('/[^a-z]/', '', $titre);
    }
}
