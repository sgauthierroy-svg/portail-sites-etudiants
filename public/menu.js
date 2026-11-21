/*
 * Menu repliable sur téléphone et petite tablette (amélioration D3).
 * Sans JavaScript, le menu reste affiché en entier : la page reste utilisable.
 * Avec JavaScript, sur un écran étroit, le menu se cache derrière le bouton « Menu ».
 */
(function () {
  'use strict';

  var bouton = document.querySelector('.menu-bouton');
  var menu = document.querySelector('.menu');
  if (!bouton || !menu) {
    return;
  }
  document.documentElement.classList.add('js');
  bouton.hidden = false;

  /* Ouvre ou ferme le menu, et le dit aux lecteurs d'écran. */
  function basculer(ouvert) {
    menu.classList.toggle('menu-ouvert', ouvert);
    bouton.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
    bouton.textContent = ouvert ? 'Fermer le menu' : 'Menu';
  }

  bouton.addEventListener('click', function () {
    basculer(!menu.classList.contains('menu-ouvert'));
  });
  document.addEventListener('keydown', function (evenement) {
    if (evenement.key === 'Escape' && menu.classList.contains('menu-ouvert')) {
      basculer(false);
      bouton.focus();
    }
  });
}());
