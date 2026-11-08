/*
 * Tri des tableaux par clic sur l'en-tête de colonne (tableaux marqués class="triable").
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
}());
