<?php

declare(strict_types=1);

namespace Portail\Comptes;

use PDO;
use Portail\Alertes\Alertes;
use Portail\Noyau\ErreurSaisie;

/**
 * Commande de secours, en SSH (bin/comptes.php). Elle sert à :
 * - créer le premier administrateur (aucune inscription n'est possible depuis le web) ;
 * - reprendre la main si plus aucun administrateur ne peut se connecter
 *   (départ brutal, téléphone perdu) : quiconque a l'accès SSH au compte
 *   O2switch peut créer un administrateur ou réinitialiser un compte.
 */
final class CommandeComptes
{
    public const AIDE = [
        'Utilisation :',
        '  php ~/portail/bin/comptes.php liste',
        '  php ~/portail/bin/comptes.php creer-admin <identifiant> "<Nom affiché>" <e-mail>',
        '  php ~/portail/bin/comptes.php reinitialiser <identifiant>',
        '',
        '« reinitialiser » donne un nouveau mot de passe provisoire, efface la double',
        'authentification (téléphone à relier de nouveau), débloque et réactive le compte.',
    ];

    public function __construct(private readonly PDO $pdo, private readonly ?Alertes $alertes = null)
    {
    }

    /**
     * @param list<string> $arguments
     * @return list<string> Lignes à afficher
     */
    public function executer(array $arguments): array
    {
        $gestion = new GestionComptes($this->pdo, null, null, $this->alertes);
        $depot = new DepotComptes($this->pdo);

        switch ($arguments[0] ?? '') {
            case 'liste':
                $lignes = [];
                foreach ($depot->liste() as $compte) {
                    $lignes[] = sprintf('%-16s %-15s %-10s %s', $compte->identifiant, $compte->libelleRole(),
                        $compte->actif ? 'actif' : 'désactivé', $compte->nomAffiche);
                }

                return $lignes === [] ? ['Aucun compte.'] : $lignes;

            case 'creer-admin':
                if (count($arguments) !== 4) {
                    throw new ErreurSaisie(implode(PHP_EOL, self::AIDE));
                }
                $cree = $gestion->creer($arguments[1], $arguments[2], $arguments[3], 'administrateur');

                return self::aTransmettre($arguments[1], $cree['provisoire']);

            case 'reinitialiser':
                $compte = $depot->parIdentifiant($arguments[1] ?? '');
                if ($compte === null) {
                    throw new ErreurSaisie('Compte introuvable : ' . ($arguments[1] ?? ''));
                }
                $provisoire = $gestion->reinitialiserMotDePasse($compte->id);
                $gestion->reinitialiserDoubleAuth($compte->id);
                if (!$compte->actif) {
                    $gestion->reactiver($compte->id);
                }

                return self::aTransmettre($compte->identifiant, $provisoire);

            default:
                return self::AIDE;
        }
    }

    /** @return list<string> */
    private static function aTransmettre(string $identifiant, string $provisoire): array
    {
        return [
            'Compte ' . $identifiant . ' prêt.',
            'Mot de passe provisoire (affiché une seule fois) : ' . $provisoire,
            'À la première connexion : relier le téléphone, puis choisir un mot de passe.',
        ];
    }
}
