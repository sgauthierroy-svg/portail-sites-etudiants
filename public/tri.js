/*
 * Petits conforts du portail : tri des tableaux par clic sur l'en-tête de colonne
 * (tableaux marqués class="triable") et case « Afficher le mot de passe ».
 * Fichier du portail lui-même (aucun script extérieur, aucun script écrit dans la page).
 * Sans JavaScript, le tableau reste lisible dans l'ordre donné par le serveur.
 */
(function () {
  'use strict';
  var comparer = new Intl.Collator('fr', { numeric: true, sensitivity: 'base' }).compare;

  function texte(ligne, rang) {
    var cellule = ligne.children[rang];
    return cellule ? cellule.textContent.trim() : '';
  }

  function trier(tableau, rang, bouton) {
    var corps = tableau.tBodies[0];
    if (!corps) { return; }
    var entete = bouton.parentNode;
    var croissant = entete.getAttribute('aria-sort') !== 'ascending';
    Array.prototype.forEach.call(tableau.tHead.rows[0].cells, function (th) { th.removeAttribute('aria-sort'); });
    entete.setAttribute('aria-sort', croissant ? 'ascending' : 'descending');
    var lignes = Array.prototype.slice.call(corps.rows);
    lignes.sort(function (a, b) {
      var resultat = comparer(texte(a, rang), texte(b, rang));
      return croissant ? resultat : -resultat;
    });
    lignes.forEach(function (ligne) { corps.appendChild(ligne); });
  }

  document.querySelectorAll('table.triable').forEach(function (tableau) {
    if (!tableau.tHead || !tableau.tHead.rows.length) { return; }
    Array.prototype.forEach.call(tableau.tHead.rows[0].cells, function (th, rang) {
      if (th.textContent.trim() === '') { return; }
      var bouton = document.createElement('button');
      bouton.type = 'button';
      bouton.className = 'tri';
      bouton.textContent = th.textContent;
      bouton.title = 'Trier par ' + th.textContent.trim();
      th.textContent = '';
      th.appendChild(bouton);
      bouton.addEventListener('click', function () { trier(tableau, rang, bouton); });
    });
  });

  /*
   * Filtre des listes (décision du 11/11/2026, demandée le 15/11/2026, revue le 15/11/2026 : pouvoir
   * combiner plusieurs critères, par exemple le régime et la session).
   * Au-dessus de chaque liste triable de plus de 5 lignes :
   * - un menu pour la colonne Régime et un pour la colonne Session, quand la liste en a
   *   (demande du 15/11/2026 : régime, session et texte seulement) ;
   * - une zone de texte : chaque mot tapé doit se trouver dans la ligne, dans n'importe quelle colonne
   *   (sans tenir compte des majuscules ni des accents).
   * Tous les critères s'ajoutent. Le tri par en-tête reste possible ; « Effacer » remet la liste entière.
   */
  var MAX_VALEURS = 40;
  var COLONNES_A_MENU = ['regime', 'session'];
  function sansAccents(texte) {
    return texte.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  }
  function valeursDeColonne(corps, rang) {
    var vues = {};
    var liste = [];
    for (var i = 0; i < corps.rows.length; i++) {
      var cellule = corps.rows[i].children[rang];
      if (!cellule || cellule.querySelector('input, select, button, textarea')) { return null; }
      var valeur = cellule.textContent.trim();
      if (!Object.prototype.hasOwnProperty.call(vues, valeur)) {
        vues[valeur] = true;
        liste.push(valeur);
        if (liste.length > MAX_VALEURS) { return null; }
      }
    }
    if (liste.length === 0) { return null; }
    return liste.sort(comparer);
  }
  document.querySelectorAll('table.triable').forEach(function (tableau, numero) {
    var corps = tableau.tBodies[0];
    if (!corps || corps.rows.length <= 5 || !tableau.tHead || !tableau.tHead.rows.length) { return; }
    var zone = document.createElement('div');
    zone.className = 'filtre';
    var menus = [];
    Array.prototype.forEach.call(tableau.tHead.rows[0].cells, function (th, rang) {
      var titre = th.textContent.trim();
      if (COLONNES_A_MENU.indexOf(sansAccents(titre)) === -1) { return; }
      var valeurs = valeursDeColonne(corps, rang);
      if (!valeurs) { return; }
      var etiquette = document.createElement('label');
      etiquette.textContent = titre + ' ';
      var menu = document.createElement('select');
      var tous = document.createElement('option');
      tous.value = '';
      tous.textContent = '(tous)';
      menu.appendChild(tous);
      valeurs.forEach(function (valeur) {
        var option = document.createElement('option');
        option.value = valeur;
        option.textContent = valeur === '' ? '(vide)' : valeur;
        menu.appendChild(option);
      });
      menu.dataset.rang = String(rang);
      etiquette.appendChild(menu);
      zone.appendChild(etiquette);
      menus.push(menu);
    });
    var etiquetteTexte = document.createElement('label');
    etiquetteTexte.htmlFor = 'filtre-' + numero;
    etiquetteTexte.textContent = 'Texte ';
    var saisie = document.createElement('input');
    saisie.type = 'search';
    saisie.id = 'filtre-' + numero;
    saisie.autocomplete = 'off';
    saisie.placeholder = 'un ou plusieurs mots';
    etiquetteTexte.appendChild(saisie);
    zone.appendChild(etiquetteTexte);
    var effacer = document.createElement('button');
    effacer.type = 'button';
    effacer.textContent = 'Effacer';
    zone.appendChild(effacer);
    var compte = document.createElement('span');
    compte.className = 'discret';
    compte.setAttribute('aria-live', 'polite');
    zone.appendChild(compte);
    tableau.parentNode.insertBefore(zone, tableau);

    function appliquer() {
      var mots = sansAccents(saisie.value).split(/\s+/).filter(function (mot) { return mot !== ''; });
      var choix = menus.filter(function (menu) { return menu.selectedIndex > 0; });
      var actif = mots.length > 0 || choix.length > 0;
      var vues = 0;
      Array.prototype.forEach.call(corps.rows, function (ligne) {
        var texteLigne = sansAccents(ligne.textContent);
        var garde = mots.every(function (mot) { return texteLigne.indexOf(mot) !== -1; })
          && choix.every(function (menu) { return texte(ligne, Number(menu.dataset.rang)) === menu.value; });
        ligne.hidden = !garde;
        vues += garde ? 1 : 0;
      });
      compte.textContent = actif ? vues + ' ligne(s) sur ' + corps.rows.length : '';
      effacer.hidden = !actif;
    }
    menus.forEach(function (menu) { menu.addEventListener('change', appliquer); });
    saisie.addEventListener('input', appliquer);
    effacer.addEventListener('click', function () {
      menus.forEach(function (menu) { menu.selectedIndex = 0; });
      saisie.value = '';
      appliquer();
    });
    effacer.hidden = true;
  });

  /*
   * Case « Tout cocher » (demande du 14/11/2026) : coche ou décoche les cases actives du tableau.
   * Les étudiants sans case (empêchement) ne sont jamais cochés ; avec un filtre, seulement les lignes
   * affichées. Sans JavaScript, on coche une à une.
   */
  document.querySelectorAll('input[data-tout-cocher]').forEach(function (tout) {
    var tableau = tout.closest('table');
    if (!tableau || !tableau.tBodies[0]) { return; }
    tout.addEventListener('change', function () {
      tableau.tBodies[0].querySelectorAll('input[type="checkbox"][name="coches[]"]:not(:disabled)').forEach(function (c) {
        var ligne = c.closest('tr');
        c.checked = tout.checked && !(ligne && ligne.hidden);
      });
    });
  });

  /*
   * Mots de passe : case « Afficher » sous chaque formulaire qui en contient
   * (demande du 11/11/2026 : voir ce qu'on saisit pour pouvoir l'enregistrer).
   * Les champs redeviennent masqués à l'envoi du formulaire.
   */
  document.querySelectorAll('form').forEach(function (formulaire, rang) {
    var champs = formulaire.querySelectorAll('input[type="password"]');
    if (!champs.length) { return; }
    var case_ = document.createElement('input');
    case_.type = 'checkbox';
    case_.id = 'afficher-mdp-' + rang;
    var libelle = document.createElement('label');
    libelle.htmlFor = case_.id;
    libelle.className = 'afficher-mdp';
    libelle.appendChild(case_);
    libelle.appendChild(document.createTextNode(champs.length > 1 ? ' Afficher les mots de passe' : ' Afficher le mot de passe'));
    var ligne = document.createElement('p');
    ligne.appendChild(libelle);
    var dernier = champs[champs.length - 1];
    var apres = dernier.closest('p') || dernier;
    apres.parentNode.insertBefore(ligne, apres.nextSibling);
    case_.addEventListener('change', function () {
      champs.forEach(function (champ) { champ.type = case_.checked ? 'text' : 'password'; });
    });
    formulaire.addEventListener('submit', function () {
      champs.forEach(function (champ) { champ.type = 'password'; });
    });
  });
}());
