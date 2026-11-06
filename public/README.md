# public/

Seule partie du portail visible depuis Internet (`~/portail/public` sur le serveur).

Elle contiendra une page d'entrée unique, `index.php`, qui ne fait qu'appliquer les réglages de sécurité puis appeler le code de `app/`. Aucune logique, aucune donnée et aucun secret ici.

Les fichiers `.htaccess` (porte d'entrée, renvoi vers https, HSTS) et `index.html` présents sur le serveur ne sont pas dans ce dépôt : ils dépendent de la configuration CPanel.
