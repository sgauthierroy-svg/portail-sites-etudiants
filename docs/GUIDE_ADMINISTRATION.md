# Guide d'administration du portail

Mode opératoire de l'administrateur. Document complété au fil du développement.

## Chaque rentrée

### Créer la session de la nouvelle promotion

À chaque rentrée, une nouvelle promotion de 1re année arrive. Avant d'importer les nouveaux étudiants, créer dans le portail **la session** de cette promotion, par exemple « 2029 » pour la rentrée 2027.

Les régimes (NDRC FI, NDRC ALT) existent déjà : la classe affichée, par exemple « NDRC ALT – session 2029 », combine régime et session. Cela prend une minute. La 1re ou la 2e année se calcule toute seule à partir de la session : rien d'autre à mettre à jour.

Un fichier d'import qui cite une session ou un régime absent du portail est refusé, ligne par ligne.

### Archiver les sessions terminées

Une session dont tous les étudiants sont partis (sous-domaines supprimés) est archivée. Une session qui contient encore des étudiants ne peut pas être supprimée.

### Redoublants et changements de régime

Ils se modifient sur la fiche de l'étudiant (session et/ou régime). Le portail propose alors les actions sur les sites : réinitialisation de `/WP` pour un redoublant de 1re année, de `/Presta` pour un redoublant de 2e année. Rien n'est fait sans confirmation.

## Installation et mise à jour

### Arborescence sur le serveur

| Dossier | Contenu | Droits |
| --- | --- | --- |
| `~/portail/public` | `index.php` (copié du dépôt), `.htaccess` et `index.html` (gérés sur le serveur) | lisible par le serveur web |
| `~/portail/app` | code du portail (copié du dépôt) | 700 |
| `~/portail/bin` | commandes SSH (copiées du dépôt) | 700 |
| `~/portail/data` | `config.php`, `portail.sqlite`, `sessions/`, `tmp/` | 700 (fichiers 600) |
| `~/portail/logs` | `php_errors.log` | 700 |

### Première installation

1. Copier `app`, `bin` et `public/index.php` du dépôt vers `~/portail`.
2. Lancer `php ~/portail/bin/installer.php` : il crée `data`, `data/sessions`, `data/tmp` et `logs` en droits 700, puis s'arrête car la configuration manque.
3. Copier `config/config.exemple.php` vers `~/portail/data/config.php`, le remplir, puis `chmod 600 ~/portail/data/config.php`. La clé de chiffrement se génère **sur le serveur** :
   `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`
4. Relancer `php ~/portail/bin/installer.php` : il vérifie la configuration, crée la base et pose les réglages par défaut.

Le portail refuse de démarrer si `config.php` est lisible par un autre compte, si une valeur manque ou si la base n'est pas installée. Le visiteur voit alors une page d'erreur neutre avec un numéro de référence ; le détail est dans `~/portail/logs/php_errors.log`.

### Mise à jour

Copier les nouveaux fichiers, puis relancer `php ~/portail/bin/installer.php`. Il applique les nouvelles versions de la base sans rien écraser.

## Comptes du portail

### Premiers administrateurs (une seule fois, en SSH)

Aucune inscription n'est possible depuis le web : les premiers comptes se créent en SSH.

```
php ~/portail/bin/comptes.php creer-admin mzanelli "Marc ZANELLI" informatique@ecs-sallanches.fr
php ~/portail/bin/comptes.php creer-admin sgauthierroy "Stéphanie GAUTHIER-ROY" s.gauthier.roy@gmail.com
```

La commande affiche **une seule fois** un mot de passe provisoire, à transmettre à la personne. À sa première connexion, elle relie l'application d'authentification de son téléphone, puis choisit son mot de passe (12 caractères au moins).

Le compte `sgauthierroy` est temporaire (développement et tests) : à la remise du portail, le passer en enseignant ou le désactiver.

### Gérer les comptes (dans le portail, administrateur seulement)

Menu « Comptes du portail » : créer un compte, changer le nom, l'e-mail ou le rôle, réinitialiser le mot de passe (nouveau mot de passe provisoire affiché une fois), réinitialiser le code à 6 chiffres (téléphone perdu ou changé), désactiver ou réactiver.

- Un compte n'est jamais supprimé, seulement désactivé : le journal garde ses actions.
- Changer le rôle, réinitialiser ou désactiver ferme **immédiatement** les sessions ouvertes de la personne.
- Le dernier administrateur actif ne peut être ni désactivé ni rétrogradé.
- Chaque action est inscrite au journal (catégorie « administration », conservée 1 an).

### Commande de secours

Si plus aucun administrateur ne peut se connecter (départ brutal, téléphone perdu), toute personne qui a l'accès SSH au compte O2switch peut reprendre la main :

```
php ~/portail/bin/comptes.php liste
php ~/portail/bin/comptes.php reinitialiser mzanelli
```

`reinitialiser` donne un nouveau mot de passe provisoire, efface la double authentification, débloque et réactive le compte. On peut aussi créer un nouvel administrateur avec `creer-admin`. Ces actions sont inscrites au journal sous « commande de secours (SSH) ».

L'accès SSH au compte O2switch est donc la clé de secours du portail : ses identifiants doivent rester détenus par l'établissement.

### Départ de l'administrateur

1. L'administrateur sortant crée le compte de son successeur, qui active sa double authentification.
2. Le sortant désactive son propre compte (sans le supprimer).
3. Changer les mots de passe partagés qu'il connaissait : porte d'entrée (`supsallanches`), boîte `portail@bts-sup-sallanches.com`.
