<?php

declare(strict_types=1);

namespace Portail\Web;

use Portail\Bibliotheque\Bibliotheque;
use Portail\Comptes\Compte;

/**
 * Pages de la V2, lot 2 : bibliothèque et espace documentaire.
 */
final class PagesBibliotheque
{
    /**
     * @param list<array{id: int, type: string, nom: string, version: ?string, version_wordpress: ?string, taille: int,
     *                   depose_le: string, depose_par: ?int, depose_par_nom: string, sessions: list<int>}> $paquets
     * @param array{acceptes: list<array{nom: string, taille: int, extension: string}>, ignores: int} $depot
     */
    public static function bibliotheque(string $jeton, Compte $moi, Bibliotheque $bibliotheque, array $paquets, array $depot,
        int $limiteEnvoi, ?string $erreur, ?string $information): string
    {
        $sections = '';
        foreach (Bibliotheque::TYPES as $type => $libelle) {
            $lignes = '';
            foreach ($paquets as $paquet) {
                if ($paquet['type'] !== $type) {
                    continue;
                }
                $usage = $paquet['sessions'] === [] ? '—' : 'session ' . implode(', ', $paquet['sessions']);
                $retirer = $paquet['sessions'] === [] && $bibliotheque->peutRetirer($paquet['depose_par'], $moi)
                    ? self::bouton($jeton, '/bibliotheque', 'retirer', $paquet['id'], 'Retirer') : '';
                $lignes .= '<tr><td>' . Html::e($paquet['nom']) . '</td>'
                    . '<td>' . Html::e($paquet['version'] ?? ($paquet['version_wordpress'] === null ? '—' : 'WordPress ' . $paquet['version_wordpress'])) . '</td>'
                    . '<td>' . Html::e(Bibliotheque::lisible($paquet['taille'])) . '</td>'
                    . '<td>' . Html::e($paquet['depose_par_nom']) . ', ' . Html::e(Pages::heureLocale($paquet['depose_le'])) . '</td>'
                    . '<td>' . Html::e($usage) . '</td><td>' . $retirer . '</td></tr>';
            }
            $sections .= '<h2>' . Html::e($libelle) . '</h2>'
                . ($type === 'sujet_prestashop' ? '<p class="discret">Arrive avec le lot 6 (sujet /Presta2).</p>' : '')
                . ($lignes === '' ? '<p>Aucun pour l\'instant.</p>'
                    : '<table><thead><tr><th>Nom</th><th>Version</th><th>Taille</th><th>Déposé par</th><th>Utilisé par</th><th></th></tr></thead><tbody>'
                        . $lignes . '</tbody></table>');
        }

        $choixType = '';
        foreach (Bibliotheque::TYPES as $type => $libelle) {
            if ($type !== 'sujet_prestashop') {
                $choixType .= '<option value="' . $type . '">' . Html::e($libelle) . '</option>';
            }
        }
        $champsCommuns = static fn (string $suffixe): string => '<p><label for="type' . $suffixe . '">Type</label><br><select id="type' . $suffixe . '" name="type">' . $choixType . '</select></p>'
            . '<p><label for="nom' . $suffixe . '">Nom affiché</label><br><input id="nom' . $suffixe . '" name="nom" maxlength="100" required></p>'
            . '<p><label for="version' . $suffixe . '">Version PrestaShop (paquet d\'installation seulement)</label><br>'
            . '<input id="version' . $suffixe . '" name="version" maxlength="20" placeholder="9.1.5"></p>';

        $formulaire = '<h2>Ajouter un paquet</h2>'
            . '<p>PrestaShop : téléchargez le ZIP de la version voulue sur <strong>prestashop.com/versions</strong>, puis déposez-le ici. '
            . 'Sujet WordPress : le ZIP « archive » produit par Duplicator. Le portail vérifie le contenu, calcule l\'empreinte du fichier '
            . 'et la revérifie avant chaque installation.</p>'
            . '<form method="post" action="/bibliotheque" enctype="multipart/form-data">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="deposer">' . $champsCommuns('')
            . '<p><label for="fichier">Fichier ZIP</label><br><input type="file" id="fichier" name="fichier" accept=".zip,application/zip" required></p>'
            . '<p class="discret">Par cette page : ' . Html::e(self::limite($limiteEnvoi)) . ' au plus (réglage du serveur). Au-delà, utilisez le dépôt par CPanel ci-dessous.</p>'
            . '<button type="submit" class="btn-main">Déposer</button></form>';

        return Pages::page('Bibliothèque', Pages::alerte($erreur, $information) . $sections . $formulaire
            . self::depot($jeton, '/bibliotheque', 'zip', $depot, $champsCommuns));
    }

    /**
     * @param list<array{id: int, titre: string, taille: int, depose_le: string, depose_par: ?int, depose_par_nom: string}> $documents
     * @param array{acceptes: list<array{nom: string, taille: int, extension: string}>, ignores: int} $depot
     */
    public static function documents(string $jeton, Compte $moi, Bibliotheque $bibliotheque, array $documents, array $depot,
        int $limiteEnvoi, ?string $erreur, ?string $information): string
    {
        $lignes = '';
        foreach ($documents as $document) {
            $retirer = $bibliotheque->peutRetirer($document['depose_par'], $moi) ? self::bouton($jeton, '/documents', 'retirer', $document['id'], 'Retirer') : '';
            $lignes .= '<tr><td><a href="/documents/' . $document['id'] . '">' . Html::e($document['titre']) . '</a></td>'
                . '<td>' . Html::e(Bibliotheque::lisible($document['taille'])) . '</td>'
                . '<td>' . Html::e($document['depose_par_nom']) . ', ' . Html::e(Pages::heureLocale($document['depose_le'])) . '</td>'
                . '<td>' . $retirer . '</td></tr>';
        }
        $tableau = $lignes === '' ? '<p>Aucun document pour l\'instant.</p>'
            : '<table><thead><tr><th>Document (cliquer pour télécharger)</th><th>Taille</th><th>Déposé par</th><th></th></tr></thead><tbody>' . $lignes . '</tbody></table>';
        $champs = static fn (string $suffixe): string => '<p><label for="titre' . $suffixe . '">Titre</label><br><input id="titre' . $suffixe . '" name="titre" maxlength="150" required></p>';

        return Pages::page('Documents',
            Pages::alerte($erreur, $information)
            . '<p>Documents de référence de l\'équipe (référentiel, consignes…), en PDF. Visibles par tous les comptes du portail, jamais par les étudiants.</p>'
            . $tableau
            . '<h2>Ajouter un document</h2><form method="post" action="/documents" enctype="multipart/form-data">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="deposer">' . $champs('')
            . '<p><label for="fichier">Fichier PDF</label><br><input type="file" id="fichier" name="fichier" accept=".pdf,application/pdf" required></p>'
            . '<p class="discret">30 Mo au plus' . ($limiteEnvoi > 0 && $limiteEnvoi < Bibliotheque::TAILLE_MAX_PDF ? ' (par cette page : ' . Html::e(self::limite($limiteEnvoi)) . ')' : '') . '.</p>'
            . '<button type="submit" class="btn-main">Déposer</button></form>'
            . self::depot($jeton, '/documents', 'pdf', $depot, $champs));
    }

    /**
     * @param array{acceptes: list<array{nom: string, taille: int, extension: string}>, ignores: int} $depot
     * @param callable(string): string $champs
     */
    private static function depot(string $jeton, string $action, string $extension, array $depot, callable $champs): string
    {
        $fichiers = '';
        foreach ($depot['acceptes'] as $rang => $fichier) {
            if ($fichier['extension'] !== $extension) {
                continue;
            }
            $fichiers .= '<form method="post" action="' . $action . '" class="carte-depot">' . Pages::jeton($jeton)
                . '<input type="hidden" name="action" value="enregistrer_depot"><input type="hidden" name="fichier_depot" value="' . Html::e($fichier['nom']) . '">'
                . '<p><strong>' . Html::e($fichier['nom']) . '</strong> (' . Html::e(Bibliotheque::lisible($fichier['taille'])) . ')</p>'
                . $champs('-' . $rang) . '<button type="submit">Enregistrer ce fichier</button></form>';
        }

        return '<h2>Dépôt par CPanel (gros fichiers)</h2>'
            . '<p>Avec le gestionnaire de fichiers de CPanel, déposez le fichier dans <code>portail/data/depot</code>, puis rechargez cette page. '
            . 'Une fois enregistré, il quitte ce dossier.</p>'
            . ($fichiers === '' ? '<p>Aucun fichier ' . strtoupper($extension) . ' en attente dans ce dossier.</p>' : $fichiers)
            . ($depot['ignores'] > 0 ? '<p class="discret">' . $depot['ignores'] . ' élément(s) du dossier ignoré(s) : nom avec des caractères spéciaux, ou ni ZIP ni PDF.</p>' : '');
    }

    private static function bouton(string $jeton, string $action, string $nom, int $id, string $libelle): string
    {
        return '<form method="post" action="' . $action . '">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="' . $nom . '">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<label class="discret"><input type="checkbox" name="confirmer" value="1" required> définitivement</label> '
            . '<button type="submit">' . Html::e($libelle) . '</button></form>';
    }

    private static function limite(int $octets): string
    {
        return $octets <= 0 ? 'pas de limite' : Bibliotheque::lisible($octets);
    }
}
