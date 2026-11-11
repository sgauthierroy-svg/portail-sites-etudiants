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
    private const SECTIONS = [
        'prestashop' => ['Versions PrestaShop', 'Paquets d\'installation, un par version. Téléchargés sur prestashop.com/versions.'],
        'sujet_wordpress' => ['Sujets WordPress', 'Archives ZIP produites par Duplicator, déployées dans /WP2.'],
        'sujet_prestashop' => ['Sujets PrestaShop', 'Arrivent avec le sujet /Presta2 (lot 6).'],
    ];

    /**
     * Page unique (décision du 11/11/2026) : versions, sujets et documents au même endroit.
     * Les documents sont ouverts à tous les comptes ; les paquets aux comptes qui ont le droit « bibliothèque ».
     *
     * @param list<array{id: int, type: string, nom: string, version: ?string, version_wordpress: ?string, taille: int,
     *                   depose_le: string, depose_par: ?int, depose_par_nom: string, sessions: list<int>}> $paquets
     * @param list<array{id: int, titre: string, taille: int, depose_le: string, depose_par: ?int, depose_par_nom: string}> $documents
     * @param array{acceptes: list<array{nom: string, taille: int, extension: string}>, ignores: int} $depot
     */
    public static function bibliotheque(string $jeton, Compte $moi, Bibliotheque $bibliotheque, bool $voirPaquets, array $paquets, array $documents,
        array $depot, int $limiteEnvoi, ?string $erreur, ?string $information): string
    {
        $contenu = Pages::alerte($erreur, $information);
        $sommaire = '';
        if ($voirPaquets) {
            foreach (self::SECTIONS as $type => [$titre, $aide]) {
                $fiches = '';
                $nombre = 0;
                foreach ($paquets as $paquet) {
                    if ($paquet['type'] !== $type) {
                        continue;
                    }
                    $nombre++;
                    $version = $paquet['version'] ?? ($paquet['version_wordpress'] === null ? null : 'WordPress ' . $paquet['version_wordpress']);
                    $usage = $paquet['sessions'] === [] ? '' : '<span class="badge ok">session ' . Html::e(implode(', ', $paquet['sessions'])) . '</span>';
                    $retirer = $paquet['sessions'] === [] && $bibliotheque->peutRetirer($paquet['depose_par'], $moi)
                        ? self::bouton($jeton, 'retirer', $paquet['id']) : '';
                    $fiches .= self::fiche(Pages::icone('bibliotheque', 'i m'), $paquet['nom'],
                        ($version === null ? '' : '<span class="badge neutre">' . Html::e($version) . '</span> ') . $usage,
                        Bibliotheque::lisible($paquet['taille']) . ' · ' . $paquet['depose_par_nom'] . ', ' . Pages::heureLocale($paquet['depose_le']), $retirer);
                }
                $ajout = '';
                if ($type !== 'sujet_prestashop') {
                    $ajout = '<details class="ajout"><summary>Ajouter ' . ($type === 'prestashop' ? 'une version PrestaShop' : 'un sujet WordPress') . '</summary>'
                        . '<form method="post" action="/bibliotheque" enctype="multipart/form-data">' . Pages::jeton($jeton)
                        . '<input type="hidden" name="action" value="deposer"><input type="hidden" name="type" value="' . $type . '">'
                        . '<p><label for="nom-' . $type . '">Nom affiché</label><br><input id="nom-' . $type . '" name="nom" maxlength="100" required'
                        . ($type === 'prestashop' ? ' value="PrestaShop"' : '') . '></p>'
                        . ($type === 'prestashop' ? '<p><label for="version-' . $type . '">Numéro de version</label><br>'
                            . '<input id="version-' . $type . '" name="version" maxlength="20" placeholder="9.1.5" required></p>' : '')
                        . '<p><label for="fichier-' . $type . '">Fichier ZIP</label><br><input type="file" id="fichier-' . $type . '" name="fichier" accept=".zip,application/zip" required></p>'
                        . '<p class="discret">Par cette page : ' . Html::e(self::limite($limiteEnvoi)) . ' au plus. Au-delà, voir « Gros fichiers » en bas de page.</p>'
                        . '<button type="submit" class="btn-main">Déposer</button></form></details>';
                }
                $sommaire .= '<a href="#' . $type . '">' . Html::e($titre) . ' <span class="compte">' . $nombre . '</span></a>';
                $contenu .= '<section class="rayon" id="' . $type . '"><h2>' . Html::e($titre) . ' <span class="compte">' . $nombre . '</span></h2>'
                    . '<p class="discret">' . Html::e($aide) . '</p>'
                    . ($fiches === '' ? '<p>Aucun pour l\'instant.</p>' : '<div class="fiches">' . $fiches . '</div>') . $ajout . '</section>';
            }
        }

        $fiches = '';
        foreach ($documents as $document) {
            $retirer = $bibliotheque->peutRetirer($document['depose_par'], $moi) ? self::bouton($jeton, 'retirer_document', $document['id']) : '';
            $fiches .= self::fiche(Pages::icone('documents', 'i m'), $document['titre'],
                '<a class="bouton" href="/documents/' . $document['id'] . '">Télécharger le PDF</a>',
                Bibliotheque::lisible($document['taille']) . ' · ' . $document['depose_par_nom'] . ', ' . Pages::heureLocale($document['depose_le']), $retirer);
        }
        $sommaire .= '<a href="#documents">Documents <span class="compte">' . count($documents) . '</span></a>';
        $contenu .= '<section class="rayon" id="documents"><h2>Documents <span class="compte">' . count($documents) . '</span></h2>'
            . '<p class="discret">Référentiel, consignes… en PDF. Visibles par tous les comptes du portail, jamais par les étudiants.</p>'
            . ($fiches === '' ? '<p>Aucun document pour l\'instant.</p>' : '<div class="fiches">' . $fiches . '</div>')
            . '<details class="ajout"><summary>Ajouter un document</summary>'
            . '<form method="post" action="/bibliotheque" enctype="multipart/form-data">' . Pages::jeton($jeton)
            . '<input type="hidden" name="action" value="deposer_document">'
            . '<p><label for="titre">Titre</label><br><input id="titre" name="titre" maxlength="150" required></p>'
            . '<p><label for="fichier-document">Fichier PDF</label><br><input type="file" id="fichier-document" name="fichier" accept=".pdf,application/pdf" required></p>'
            . '<p class="discret">30 Mo au plus.</p><button type="submit" class="btn-main">Déposer</button></form></details></section>';

        $champsPaquet = static fn (string $suffixe): string => '<p><label for="type' . $suffixe . '">Type</label><br><select id="type' . $suffixe . '" name="type">'
            . '<option value="prestashop">Version PrestaShop</option><option value="sujet_wordpress">Sujet WordPress</option></select></p>'
            . '<p><label for="nom' . $suffixe . '">Nom affiché</label><br><input id="nom' . $suffixe . '" name="nom" maxlength="100" required></p>'
            . '<p><label for="version' . $suffixe . '">Numéro de version (PrestaShop seulement)</label><br>'
            . '<input id="version' . $suffixe . '" name="version" maxlength="20" placeholder="9.1.5"></p>';
        $champsDocument = static fn (string $suffixe): string => '<p><label for="titre' . $suffixe . '">Titre</label><br><input id="titre' . $suffixe . '" name="titre" maxlength="150" required></p>';
        $enAttente = ($voirPaquets ? self::depot($jeton, 'enregistrer_depot', 'zip', $depot, $champsPaquet) : '')
            . self::depot($jeton, 'enregistrer_depot_document', 'pdf', $depot, $champsDocument);
        $contenu .= '<section class="rayon" id="depot"><h2>Gros fichiers</h2>'
            . '<p>Un fichier trop gros pour la page se dépose avec le gestionnaire de fichiers de CPanel, dans <code>portail/data/depot</code>. '
            . 'Rechargez ensuite cette page : il apparaît ici, prêt à être enregistré.</p>'
            . ($enAttente === '' ? '<p>Aucun fichier en attente.</p>' : $enAttente)
            . ($depot['ignores'] > 0 ? '<p class="discret">' . $depot['ignores'] . ' élément(s) du dossier ignoré(s) : nom avec des caractères spéciaux, ou ni ZIP ni PDF.</p>' : '')
            . '</section>';

        return Pages::page('Bibliothèque', '<nav class="sommaire" aria-label="Rayons de la bibliothèque">' . $sommaire . '</nav>' . $contenu);
    }

    private static function fiche(string $icone, string $titre, string $etiquettes, string $details, string $actions): string
    {
        return '<article class="fiche"><span class="ico">' . $icone . '</span><div class="fiche-texte">'
            . '<strong class="fiche-titre">' . Html::e($titre) . '</strong>'
            . ($etiquettes === '' ? '' : '<span>' . $etiquettes . '</span>')
            . '<span class="discret">' . Html::e($details) . '</span>' . $actions . '</div></article>';
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
            $fichiers .= '<form method="post" action="/bibliotheque" class="carte-depot">' . Pages::jeton($jeton)
                . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="fichier_depot" value="' . Html::e($fichier['nom']) . '">'
                . '<p><strong>' . Html::e($fichier['nom']) . '</strong> (' . Html::e(Bibliotheque::lisible($fichier['taille'])) . ')</p>'
                . $champs('-' . $extension . $rang) . '<button type="submit">Enregistrer ce fichier</button></form>';
        }

        return $fichiers;
    }

    private static function bouton(string $jeton, string $action, int $id): string
    {
        return '<form method="post" action="/bibliotheque" class="retrait">' . Pages::jeton($jeton) . '<input type="hidden" name="action" value="' . $action . '">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<label class="discret"><input type="checkbox" name="confirmer" value="1" required> définitivement</label> '
            . '<button type="submit">Retirer</button></form>';
    }

    private static function limite(int $octets): string
    {
        return $octets <= 0 ? 'pas de limite' : Bibliotheque::lisible($octets);
    }
}
