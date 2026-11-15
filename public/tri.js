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
   * Filtre des listes (décision du 11/11/2026, demandée le 15/11/2026) : une zone de recherche au-dessus
   * de chaque liste triable de plus de 5 lignes ; ne garde que les lignes qui contiennent le texte tapé
   * (sans tenir compte des majuscules ni des accents). Le tri par en-tête reste possible.
   */
  function sansAccents(texte) {
    return texte.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  }
  document.querySelectorAll('table.triable').forEach(function (tableau, rang) {
    var corps = tableau.tBodies[0];
    if (!corps || corps.rows.length <= 5) { return; }
    var zone = document.createElement('p');
    zone.className = 'filtre';
    var etiquette = document.createElement('label');
    etiquette.htmlFor = 'filtre-' + rang;
    etiquette.textContent = 'Filtrer : ';
    var saisie = document.createElement('input');
    saisie.type = 'search';
    saisie.id = 'filtre-' + rang;
    saisie.autocomplete = 'off';
    saisie.placeholder = 'nom, identifiant, session…';
    var compte = document.createElement('span');
    compte.className = 'discret';
    compte.setAttribute('aria-live', 'polite');
    zone.appendChild(etiquette);
    zone.appendChild(saisie);
    zone.appendChild(document.createTextNode(' '));
    zone.appendChild(compte);
    tableau.parentNode.insertBefore(zone, tableau);
    saisie.addEventListener('input', function () {
      var cherche = sansAccents(saisie.value.trim());
      var vues = 0;
      Array.prototype.forEach.call(corps.rows, function (ligne) {
        var garde = cherche === '' || sansAccents(ligne.textContent).indexOf(cherche) !== -1;
        ligne.hidden = !garde;
        vues += garde ? 1 : 0;
      });
      compte.textContent = cherche === '' ? '' : vues + ' ligne(s) sur ' + corps.rows.length;
    });
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
