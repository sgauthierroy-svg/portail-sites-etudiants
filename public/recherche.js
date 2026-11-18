/*
 * Recherche rapide du bandeau (décisions des 17 et 18/11/2026) : à l'entrée dans le champ, la liste propose les
 * situations courantes ; en tapant, les étudiants trouvés, puis les actions et les pages. Flèches pour choisir,
 * Entrée pour ouvrir, Échap pour fermer. Sans choix, Entrée ouvre la page de résultats (/recherche).
 * Fichier du portail lui-même ; les données viennent d'un bloc JSON de la page, jamais exécuté.
 */
(function () {
  'use strict';
  var champ = document.getElementById('recherche');
  var liste = document.getElementById('recherche-liste');
  var bloc = document.getElementById('recherche-donnees');
  if (!champ || !liste || !bloc) { return; }
  var donnees;
  try { donnees = JSON.parse(bloc.textContent || '{}'); } catch (e) { return; }
  var situations = donnees.situations || [];
  var pages = donnees.pages || [];
  var etudiants = donnees.etudiants || [];
  var choix = -1;

  function normaliser(texte) {
    return texte.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[’-]/g, function (c) { return c === '-' ? ' ' : "'"; });
  }

  function filtrer(elements, mots) {
    return elements.filter(function (e) {
      var texte = normaliser(e.titre + ' ' + e.texte);
      return mots.every(function (mot) { return texte.indexOf(mot) !== -1; });
    });
  }

  function groupe(titre, elements) {
    if (!elements.length) { return; }
    var t = document.createElement('div');
    t.className = 'recherche-groupe';
    t.textContent = titre;
    liste.appendChild(t);
    elements.forEach(function (e) {
      var a = document.createElement('a');
      a.href = e.adresse;
      a.setAttribute('role', 'option');
      a.className = 'recherche-option';
      var fort = document.createElement('strong');
      fort.textContent = e.titre;
      var petit = document.createElement('span');
      petit.textContent = e.texte;
      a.appendChild(fort);
      a.appendChild(petit);
      liste.appendChild(a);
    });
  }

  function afficher() {
    var mots = normaliser(champ.value).split(/\s+/).filter(Boolean);
    liste.textContent = '';
    choix = -1;
    if (!mots.length) {
      groupe('Que voulez-vous faire ?', situations);
    } else {
      groupe('Étudiants', filtrer(etudiants, mots).slice(0, 8));
      var vus = {};
      var actions = filtrer(situations.concat(pages), mots).filter(function (e) {
        var cle = e.titre + e.adresse;
        if (vus[cle]) { return false; }
        vus[cle] = true;
        return true;
      });
      groupe('Actions et pages', actions.slice(0, 6));
      if (!liste.children.length) {
        var rien = document.createElement('div');
        rien.className = 'recherche-rien';
        rien.textContent = 'Rien trouvé. Entrée : chercher partout.';
        liste.appendChild(rien);
      }
    }
    liste.hidden = !liste.children.length;
    champ.setAttribute('aria-expanded', liste.hidden ? 'false' : 'true');
  }

  function fermer() {
    liste.hidden = true;
    champ.setAttribute('aria-expanded', 'false');
    choix = -1;
  }

  function marquer(rang) {
    var options = liste.querySelectorAll('.recherche-option');
    if (!options.length) { return; }
    choix = (rang + options.length) % options.length;
    options.forEach(function (o, i) { o.setAttribute('aria-selected', i === choix ? 'true' : 'false'); });
    options[choix].scrollIntoView({ block: 'nearest' });
  }

  champ.addEventListener('focus', afficher);
  champ.addEventListener('input', afficher);
  champ.addEventListener('keydown', function (ev) {
    if (ev.key === 'ArrowDown') { ev.preventDefault(); if (liste.hidden) { afficher(); } marquer(choix + 1); }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); marquer(choix - 1); }
    else if (ev.key === 'Escape') { fermer(); }
    else if (ev.key === 'Enter' && choix >= 0) {
      var options = liste.querySelectorAll('.recherche-option');
      if (options[choix]) { ev.preventDefault(); window.location.href = options[choix].getAttribute('href'); }
    }
  });
  document.addEventListener('click', function (ev) {
    if (ev.target !== champ && !liste.contains(ev.target)) { fermer(); }
  });
})();
