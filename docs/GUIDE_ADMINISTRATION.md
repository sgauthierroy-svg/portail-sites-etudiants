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

Le paquet `portail_maj.zip` contient `VERSION`, `app`, `bin` et `public` (dont `style.css`, `logo.png` et `polices/`). Il ne contient ni `data` (base, configuration), ni `.htaccess`, ni `index.html` : ils ne sont jamais écrasés.

1. Déposer `portail_maj.zip` dans `/home/jafl3067` (le dossier qui contient `portail`, pas `portail` lui-même).
2. Vérifier l'empreinte annoncée avec le paquet : `cd ~ && sha256sum portail_maj.zip`. Si elle diffère, ne rien installer.
3. Décompresser à côté : `rm -rf ~/maj_portail && mkdir ~/maj_portail && unzip -q portail_maj.zip -d ~/maj_portail && cat ~/maj_portail/VERSION` (affiche la version attendue).
4. Remplacer : `cp -r ~/maj_portail/app ~/maj_portail/bin ~/portail/ && cp -r ~/maj_portail/public/. ~/portail/public/ && php ~/portail/bin/installer.php`. L'installateur applique les nouvelles versions de la base sans rien écraser et finit par « Installation terminée. ».
5. Ouvrir le portail et recharger avec Ctrl + F5 (sinon le navigateur garde l'ancien style).
6. Ménage : `rm -rf ~/maj_portail ~/portail_maj.zip`.

Revenir en arrière : même procédure avec le paquet précédent.

Si le portail s'affiche sans couleurs ni logo, le `.htaccess` de `public` empêche de servir les fichiers existants (style, logo, polices) : il doit laisser passer les fichiers réels et n'envoyer vers `index.php` que le reste.

## Travaux sur le serveur (V2)

Les pages du portail ne touchent jamais le serveur : elles inscrivent une demande dans la file des travaux. Le **travailleur** (`bin/travaux.php`) l'exécute ensuite, un étudiant à la fois, avec une pause entre chacun (blocage d'adresse IP par O2switch constaté en février 2026).

- Tâche planifiée de CPanel (Tâches Cron), **chaque minute** : `* * * * *  /usr/local/bin/php /home/jafl3067/portail/bin/travaux.php > /dev/null 2>&1`. Sans elle, les travaux restent « En attente ».
- Un seul travailleur à la fois (verrou `data/tmp/travaux.lock`) : si le précédent n'a pas fini, le suivant s'arrête aussitôt.
- Chaque passage traite de nouvelles étapes pendant 50 secondes au plus ; le passage suivant reprend la suite.
- Une étape restée « en cours » après un arrêt brutal (serveur redémarré, programme tué) n'est **jamais relancée** : elle passe en « Échec – interrompue ». Vérifier le site concerné avant de relancer.
- Un travail terminé avec au moins un échec déclenche une alerte par e-mail ; le détail est sur la page du travail.
- Lancer le travailleur à la main pour voir ce qu'il fait : `php ~/portail/bin/travaux.php --bavard`.
- Suivi dans le portail : menu « Travaux en cours » (avancement étudiant par étudiant, bouton « Arrêter » pour le demandeur et l'administrateur) et « Voir tout » (une ligne par étudiant, une colonne par site, d'après le dernier relevé).
- « Actualiser le relevé » (administrateur, enseignant, administratif) inscrit un relevé dans la file : lecture seule, rien n'est modifié.

## Boutiques PrestaShop d'entraînement (V2)

Menu « Créer des boutiques » (administrateur, enseignants, administratif) : choisir la session, cocher les étudiants, lancer. Ne sont proposés que les étudiants dont le sous-domaine figure au dernier relevé et qui n'ont pas déjà de boutique.

- La première création d'une session lui fixe sa version PrestaShop (paquet de la bibliothèque) : toutes ses boutiques auront la même.
- Pour chaque étudiant, le travailleur : vérifie le dossier du sous-domaine auprès de CPanel (il doit être `~/identifiant.bts-sup-sallanches.com`) et que `/Presta` est vide ; crée la base `jafl3067_identifiant_Presta` et l'utilisateur `jafl3067_identifiant_presta` (mot de passe tiré au hasard, écrit seulement dans `app/config/parameters.php` de la boutique) ; décompresse le paquet après vérification de son empreinte ; installe la boutique par défaut, en français, en https ; renomme le dossier d'administration en `admin-identifiant-` + 6 caractères au hasard ; efface le dossier d'installation.
- En cas d'échec, ce que l'étape a créé (dossier, base, utilisateur) est effacé ; rien d'autre n'est touché. Un dossier `/Presta` non vide n'est jamais modifié.
- Accès : e-mail à l'étudiant (boutique, administration, identifiant = son e-mail, mot de passe) ; à la fin du travail, un e-mail à l'adresse d'alerte avec les lignes à coller dans la feuille des accès. Le mot de passe est gardé chiffré le temps de ce récapitulatif, puis effacé. Supprimer ce récapitulatif une fois la feuille à jour.
- Le lien d'administration figure sur la fiche de l'étudiant (administrateur et enseignants).
- Le récapitulatif contient un tableau : le sélectionner dans la messagerie, copier, puis coller dans la feuille des accès (une valeur par cellule).
- **Gel par surveillance** (décision du 08/11/2026) : le paquet 9.1.5 ne contient pas le module de mise à jour (« Assistant de mise à jour », `autoupgrade`). Chaque relevé (bouton ou tâche du lundi) lit la version réelle de chaque boutique (`app/AppKernel.php`) et repère ce module ; une boutique dont la version diffère de celle de sa session, ou qui a ce module, est signalée en rouge dans « Voir tout » et déclenche une alerte (sans nom d'étudiant).

## Bibliothèque et documents (V2)

- **Bibliothèque** (administrateur, enseignants) : paquets d'installation PrestaShop (un par version, numéro indiqué au dépôt), sujets WordPress (archive ZIP de Duplicator). Les sujets PrestaShop arrivent avec le lot 6.
- **Documents** (tous les comptes) : PDF seulement, 30 Mo au plus. Téléchargés, jamais affichés dans la page.
- Fichiers rangés sous le nom de leur empreinte SHA-256 dans `~/portail/data/bibliotheque` et `~/portail/data/documents` (droits 700, hors d'atteinte du web). L'empreinte d'un paquet est revérifiée avant chaque installation : un fichier modifié est refusé.
- Archives refusées au dépôt : contenu non reconnu, ou chemin qui sortirait du dossier du site (`../`, chemin absolu).
- **Gros fichiers** : la taille acceptée par la page dépend des réglages PHP du serveur (affichée sur la page). Au-delà, déposer le fichier avec le gestionnaire de fichiers de CPanel dans `~/portail/data/depot`, puis cliquer « Enregistrer ce fichier » dans le portail. Seuls les noms simples (lettres, chiffres, espace, `. _ - ( )`) finissant par `.zip` ou `.pdf` sont proposés.
- Retrait : la personne qui a déposé, ou l'administrateur, avec la case « définitivement ». Un paquet utilisé par une session ne peut pas être retiré.

## Alertes par e-mail

Le portail écrit à l'adresse d'alerte (réglage `adresse_alertes`) depuis la boîte `portail@bts-sup-sallanches.com`, par le serveur d'envoi d'O2switch (port 465, chiffré, certificat vérifié). Les e-mails partent juste après l'affichage de la page : ils ne ralentissent personne. Un échec d'envoi ne bloque jamais le portail ; il est inscrit au journal (« échec d'envoi d'alerte », avec la raison).

| Événement | Destinataires |
| --- | --- |
| Action de secours de la direction sur un compte | adresse d'alerte + personne du compte touché |
| Compte bloqué après 5 échecs | adresse d'alerte + personne du compte |
| Adresse IP bloquée après 30 échecs en 15 minutes | adresse d'alerte |
| Connexion d'un compte Direction | adresse d'alerte |
| Rôle Administrateur attribué (y compris par la commande de secours) | adresse d'alerte |
| Erreur technique du portail (une alerte par heure au plus) | adresse d'alerte |
| Travail terminé avec au moins un échec | adresse d'alerte |
| Comptes non confirmés depuis 6 mois (le lundi) | adresse d'alerte |

Aucun e-mail ne contient de mot de passe ni de donnée d'étudiant.

- Tester l'envoi : `php ~/portail/bin/alertes.php test`.
- Tâche du lundi : tâche planifiée de CPanel (Tâches Cron), chaque lundi à 7 h 13 : `php ~/portail/bin/alertes.php hebdo` (comptes à revoir, relevé des sites, boutiques hors gel).
- Mot de passe de la boîte `portail@` changé : le reporter dans `~/portail/data/config.php` (`smtp.mot_de_passe`), puis lancer le test. Attention : c'est le mot de passe **de la boîte e-mail** `portail@bts-sup-sallanches.com` (celle qui envoie les alertes), ni celui de la porte d'entrée, ni celui d'un compte du portail. Commande à lancer seule ; la saisie ne s'affiche pas et n'est pas gardée dans l'historique :

```
IFS= read -r -s -p "Mot de passe de la boîte e-mail portail@ (celle qui envoie les alertes) : " MDP && echo && MDP="$MDP" php -r '$f=$argv[1];$t=file_get_contents($f);$n=0;$t=preg_replace_callback("/(\x27mot_de_passe\x27\s*=>\s*)\x27(?:[^\x27\\\\]|\\\\.)*\x27/",fn($m)=>$m[1].var_export(getenv("MDP"),true),$t,1,$n);if($n!==1){fwrite(STDERR,"Ligne mot_de_passe introuvable, rien modifié.\n");exit(1);}file_put_contents($f,$t);echo "Mot de passe enregistré.\n";' ~/portail/data/config.php; unset MDP
```

  Résultat attendu : « Mot de passe enregistré. », puis `php ~/portail/bin/alertes.php test`. Une erreur 535 au test signifie que le mot de passe saisi n'est pas celui de la boîte.

## Ports réseau (pare-feu de l'établissement)

| Qui se connecte | Vers quoi | Port | Pare-feu de l'établissement |
| --- | --- | --- | --- |
| Navigateurs des utilisateurs | portail.bts-sup-sallanches.com | 443 (HTTPS) | à autoriser en sortie (en général déjà ouvert) |
| Navigateurs, adresse en `http://` | portail | 80 | utile seulement pour être renvoyé vers 443 |
| Administrateur | CPanel d'O2switch | 2083 (HTTPS de CPanel) | à autoriser en sortie depuis son poste ; si bloqué, adresse de secours `https://cpanel.` suivi du domaine |
| Administrateur avec un logiciel SSH | serveur O2switch | 22 | seulement s'il n'utilise pas le Terminal de CPanel |
| Serveur O2switch | son serveur d'envoi mail.bts-sup-sallanches.com | 465 | non concerné : tout se passe chez O2switch |
| Téléphones (code à 6 chiffres) | aucun | aucun | non concerné : le code est calculé sans connexion |

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
