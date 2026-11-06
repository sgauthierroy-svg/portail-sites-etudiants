# bin/

Commandes lancées en SSH ou par une tâche planifiée, jamais depuis le web :

- commande de secours pour créer un administrateur (aucune inscription possible depuis le web) ;
- relevé régulier de l'état réel des sites, affiché par la page « Voir tout ».

C'est le seul dossier où les commandes système sont permises, avec `escapeshellarg()` sur chaque argument (règle Semgrep `commande-systeme-hors-bin`).
