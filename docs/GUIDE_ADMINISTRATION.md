# Guide d'administration du portail

Mode opératoire de l'administrateur. Document complété au fil du développement.

## Chaque rentrée

### Créer la session de la nouvelle promotion

À chaque rentrée, une nouvelle promotion de 1re année arrive. Avant d'importer les nouveaux étudiants, créer dans le portail **la session** de cette promotion, par exemple « 2029 » pour la rentrée 2027 : page **Régimes et sessions** (administrateur, enseignant ou administratif).

Les régimes (NDRC FI, NDRC ALT) existent déjà : la classe affichée, par exemple « NDRC ALT – session 2029 », combine régime et session. Cela prend une minute. La 1re ou la 2e année se calcule toute seule à partir de la session : rien d'autre à mettre à jour.

Un fichier d'import qui cite une session ou un régime absent du portail est refusé, ligne par ligne.

### Relever les sous-domaines du serveur (avant chaque import)

En SSH : `php ~/portail/bin/releve.php`. La commande demande à CPanel la liste réelle des sous-domaines de `bts-sup-sallanches.com`, repère les sites WordPress et PrestaShop de chacun, et remplace le relevé précédent. Elle ne modifie rien sur le serveur. Le portail s'en sert pour vérifier les identifiants hors règle à l'import, et pour ne jamais proposer à un nouvel étudiant un identifiant déjà utilisé par un sous-domaine.

### Importer les étudiants

Page **Étudiants → Importer des étudiants** (administrateur, enseignant, administratif). Dans la Google Sheets, onglet « Export portail » : tout sélectionner, copier, coller dans le portail, ligne des titres comprise. Le texte collé n'est jamais enregistré sur le serveur. L'aperçu classe chaque ligne :

- **À importer** : nouvel étudiant conforme ;
- **À vérifier** (case à cocher, décochée par défaut) : importé seulement si la case est cochée après vérification ;
- **Déjà présent** : jamais modifié par l'import ; les corrections se font sur la fiche ;
- **Refusé** : avec la raison (classe ou session inexistante, e-mail mal formé, identifiant déjà pris, doublon, etc.).

Un identifiant hors de la règle de la feuille (étudiants de la session 2027, ancien format) n'est accepté que si son sous-domaine figure dans le dernier relevé. Avant toute réinitialisation ou suppression d'un site de ces étudiants (V2), le portail vérifiera en plus que le site leur appartient. Un texte qui contient une colonne de mot de passe, de date de naissance ou de code chiffré est refusé en entier.

**E-mails manquants.** Une ligne sans e-mail est refusée. On peut donc coller l'onglet entier : seuls les étudiants complets entrent. Une fois les e-mails récupérés et reportés dans la feuille, on recolle l'onglet entier : les étudiants déjà importés sont ignorés, les nouveaux complets sont importés. Tant que des étudiants sont en attente, faire entrer les nouveaux étudiants par l'import plutôt que par le formulaire « Ajouter un étudiant », pour que leur identifiant reste celui de la feuille.

**Étudiants dont les sites existent déjà** (session 2028, créés hors du portail). Ils entrent par l'import, après un relevé : leur ligne indique « Sous-domaine déjà présent sur le serveur ✓ ». Le formulaire « Ajouter un étudiant » refuse leur identifiant, puisqu'il est déjà utilisé sur le serveur.

### Archiver les sessions terminées

Une session dont tous les étudiants sont partis (sous-domaines supprimés) est archivée, sur la même page. Un régime ou une session n'est jamais supprimé : on le désactive ou on l'archive, et on peut le réactiver. On ne peut plus y placer d'étudiant tant qu'il est désactivé ou archivé.

La même page permet d'indiquer la **version WordPress gelée** de chaque session (par exemple 6.8) ; elle servira à la création des sites (V2).

### Redoublants et changements de régime

Ils se modifient sur la fiche de l'étudiant (session et/ou régime, en une seule fois si besoin). Le régime et la session (redoublement) peuvent être changés par un enseignant, un administratif ou l'administrateur. Chaque changement est inscrit dans le **parcours** de l'étudiant (date, avant → après, auteur) et au journal. L'identifiant, lui, ne change jamais.

À partir de la V2, le portail proposera alors les actions sur les sites : réinitialisation de `/WP` pour un redoublant de 1re année, de `/Presta` pour un redoublant de 2e année. Rien ne sera fait sans confirmation. En V1, aucun site n'est touché.

### Ajouter un étudiant arrivé en cours d'année

Page **Étudiants**, puis **Ajouter un étudiant** : saisir le nom de famille et le prénom séparément, l'e-mail, le régime et la session. Le portail calcule l'identifiant avec la même règle que la Google Sheets et l'affiche à côté du nom complet : le vérifier, le corriger si besoin, puis enregistrer. Un homonyme reçoit automatiquement un chiffre (`henrije2`). Un identifiant déjà pris par un étudiant, par un site présent sur le serveur ou par un site protégé est refusé.

Comme la feuille, le portail prend le **dernier mot** du prénom (« Jean Pierre » donne `pi`) : corriger à la main si besoin, et reporter le même identifiant dans la Google Sheets.

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

### Vérifications faites sur le serveur (08/11/2026)

- Le portail voit l'adresse IP réelle des visiteurs, et non celle du relais d'O2switch : le blocage par adresse et le journal fonctionnent.
- Les mots de passe sont chiffrés en Argon2id (le repli bcrypt ne sert pas).
- Ne pas remettre `Referrer-Policy: no-referrer` : avec cette valeur, les navigateurs envoient « Origin: null » sur les formulaires du portail, qui sont alors tous refusés.

### Mise à jour

Copier les nouveaux fichiers, puis relancer `php ~/portail/bin/installer.php`. Il applique les nouvelles versions de la base sans rien écraser.

## Comptes du portail

### Premiers administrateurs (une seule fois, en SSH)

Aucune inscription n'est possible depuis le web : les premiers comptes se créent en SSH.

```
php ~/portail/bin/comptes.php creer-admin mzanelli "Marc ZANELLI" informatique@ecs-sallanches.fr
php ~/portail/bin/comptes.php creer-admin sgauthierroy "Stéphanie GAUTHIER-ROY" s.gauthier.roy@gmail.com
```

La commande affiche **une seule fois** un mot de passe provisoire, à transmettre à la personne. À sa première connexion, elle relie l'application d'authentification de son téléphone, puis choisit son mot de passe, sous l'une des trois formes recommandées par la CNIL (délibération n° 2022-100) : 12 caractères au moins avec majuscules, minuscules, chiffres et caractères spéciaux ; 14 caractères au moins avec majuscules, minuscules et chiffres ; ou une phrase d'au moins 7 mots.

Le compte `sgauthierroy` est temporaire (développement et tests) : à la remise du portail, le passer en enseignant ou le désactiver.

### Gérer les comptes (dans le portail : administrateur ou direction)

Menu « Comptes du portail » : créer un compte, changer le nom, l'e-mail ou le rôle, réinitialiser le mot de passe (nouveau mot de passe provisoire affiché une fois), réinitialiser le code à 6 chiffres (téléphone perdu ou changé), désactiver ou réactiver.

- Un compte n'est jamais supprimé, seulement désactivé : le journal garde ses actions.
- Changer le rôle, réinitialiser ou désactiver ferme **immédiatement** les sessions ouvertes de la personne.
- Le dernier administrateur actif ne peut être ni désactivé ni rétrogradé.
- Chaque action est inscrite au journal (catégorie « administration », conservée 1 an).
- **Revue tous les 6 mois** : un compte actif qui n'a pas été confirmé depuis 6 mois (création ou dernière revue) apparaît sur la page d'accueil, rubrique « Comptes à revoir ». Ouvrir le compte, puis **Confirmer** s'il est toujours nécessaire, ou **Désactiver** sinon. C'est la revue régulière des droits demandée par la CNIL (« Gérer les utilisateurs »).

### Remplacement ou formation d'un enseignant

Pas de compte partagé : la CNIL demande d'« éviter les comptes génériques partagés entre plusieurs personnes ». Pour un arrêt maladie, un remplacement ou un enseignant à former :

1. Comptes du portail → Créer un compte, rôle **Enseignant**, au nom de la personne.
2. Lui transmettre de vive voix le mot de passe provisoire affiché.
3. À sa première connexion, elle relie son propre téléphone et choisit son mot de passe.
4. À son départ : **Désactiver** le compte. Un oubli sera rattrapé par la revue des 6 mois.

## Continuité : si personne n'est là

Le rôle **Direction (suivi et secours)** est attribué à la direction adjointe. En temps normal, il sert au suivi : liste des étudiants et classes (en V2 : sites en activité et alertes, affichés dans le portail, jamais envoyés par e-mail). Il ne donne accès ni aux fiches détaillées, ni aux sites, ni aux réglages.

En cas d'absence de l'informaticien et de l'enseignante référente, il donne les **pleins pouvoirs sur les comptes** :

- créer un compte de n'importe quel rôle, y compris **administrateur** (nouvel informaticien, prestataire, remplaçant) ;
- désactiver un compte, redonner un mot de passe provisoire, réinitialiser le code à 6 chiffres ;
- lire le journal complet, connexions comprises.

Chaque action de la direction sur les comptes est inscrite deux fois au journal, dont une fois comme « action de secours de la direction ». Elle s'affiche pendant 30 jours sur la page d'accueil de chaque administrateur ; l'envoi d'un e-mail viendra avec le lot des alertes. Un administrateur qui découvre une action inattendue désactive le compte concerné et prévient la direction.

Si **plus personne ne peut se connecter** (tous les administrateurs et la direction partis, ou téléphones perdus), le dernier recours est l'accès au compte O2switch : **Commande de secours** ci-dessus. Les identifiants O2switch, du domaine et des autres outils sont recensés dans le **dossier de continuité**, détenu par la direction.

### Commande de secours

Si plus aucun administrateur ne peut se connecter (départ brutal, téléphone perdu), toute personne qui a l'accès SSH au compte O2switch peut reprendre la main :

```
php ~/portail/bin/comptes.php liste
php ~/portail/bin/comptes.php reinitialiser mzanelli
```

`reinitialiser` donne un nouveau mot de passe provisoire, efface la double authentification, débloque et réactive le compte. On peut aussi créer un nouvel administrateur avec `creer-admin`. Ces actions sont inscrites au journal sous « commande de secours (SSH) ».

L'accès SSH au compte O2switch est donc la clé de secours du portail : ses identifiants doivent rester détenus par l'établissement.

### Départ de l'administrateur

1. L'administrateur sortant (ou, à défaut, la direction) crée le compte de son successeur, qui active sa double authentification.
2. Le sortant désactive son propre compte (sans le supprimer).
3. Changer les mots de passe partagés qu'il connaissait : porte d'entrée (`supsallanches`), boîte `portail@bts-sup-sallanches.com`.
4. Mettre à jour le dossier de continuité (nom du nouvel administrateur, propriétaires des outils).
