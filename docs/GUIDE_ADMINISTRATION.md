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

Menu **Ajouter des étudiants**, étiquette **Import liste Google Sheets** (administrateur, enseignant, administratif). Dans la Google Sheets, onglet « Export portail » : copier les en-têtes de colonnes et les données choisies, puis les coller dans le cadre du portail. Le texte collé n'est jamais enregistré sur le serveur. L'aperçu classe chaque ligne :

- **À importer** : nouvel étudiant conforme ;
- **À vérifier** (case à cocher, décochée par défaut) : importé seulement si la case est cochée après vérification ;
- **Déjà présent** : jamais modifié par l'import ; les corrections se font sur la fiche ;
- **Refusé** : avec la raison (classe ou session inexistante, e-mail mal formé, identifiant déjà pris, doublon, etc.).

Un identifiant hors de la règle de la feuille (étudiants de la session 2027, ancien format) n'est accepté que si son sous-domaine figure dans le dernier relevé. Avant toute réinitialisation ou suppression d'un site de ces étudiants (V2), le portail vérifiera en plus que le site leur appartient. Un texte qui contient une colonne de mot de passe, de date de naissance ou de code chiffré est refusé en entier.

**E-mails manquants.** Une ligne sans e-mail est refusée. On peut donc coller l'onglet entier : seuls les étudiants complets entrent. Une fois les e-mails récupérés et reportés dans la feuille, on recolle l'onglet entier : les étudiants déjà importés sont ignorés, les nouveaux complets sont importés. Tant que des étudiants sont en attente, faire entrer les nouveaux étudiants par l'import plutôt que par le formulaire « Création d'un étudiant », pour que leur identifiant reste celui de la feuille.

**Étudiants dont les sites existent déjà** (session 2028, créés hors du portail). Ils entrent par l'import, après un relevé : leur ligne indique « Sous-domaine déjà présent sur le serveur ✓ ». Le formulaire « Création d'un étudiant » refuse leur identifiant, puisqu'il est déjà utilisé sur le serveur.

### Archiver les sessions terminées

Une session dont tous les étudiants sont partis (sous-domaines supprimés) est archivée, sur la même page. Un régime ou une session n'est jamais supprimé : on le désactive ou on l'archive, et on peut le réactiver. On ne peut plus y placer d'étudiant tant qu'il est désactivé ou archivé.

La même page permet d'indiquer la **version WordPress gelée** de chaque session (par exemple 6.8) ; elle servira à la création des sites (V2).

### Redoublants et changements de régime

Ils se modifient sur la fiche de l'étudiant (session et/ou régime, en une seule fois si besoin). Le régime et la session (redoublement) peuvent être changés par un enseignant, un administratif ou l'administrateur. Chaque changement est inscrit dans le **parcours** de l'étudiant (date, avant → après, auteur) et au journal. L'identifiant, lui, ne change jamais.

À partir de la V2, le portail proposera alors les actions sur les sites : réinitialisation de `/WP` pour un redoublant de 1re année, de `/Presta` pour un redoublant de 2e année. Rien ne sera fait sans confirmation. En V1, aucun site n'est touché.

### Ajouter un étudiant arrivé en cours d'année

Menu **Ajouter des étudiants**, étiquette **Création d'un étudiant** : saisir le nom de famille et le prénom séparément, l'e-mail, le régime et la session. Le portail calcule l'identifiant avec la même règle que la Google Sheets et l'affiche à côté du nom complet : le vérifier, le corriger si besoin, puis enregistrer. Un homonyme reçoit automatiquement un chiffre (`henrije2`). Un identifiant déjà pris par un étudiant, par un site présent sur le serveur ou par un site protégé est refusé.

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

**Installer quand aucun travail ne tourne** (« Travaux en cours » vide) : un travail en cours utiliserait un mélange d'ancien et de nouveau code.

### Base du portail en mode WAL (depuis le 14/11/2026)

Pendant un déploiement, le travailleur écrit des centaines de Mo et le disque du compte peut être saturé quelques secondes ; une page du portail tombait alors en « database is locked » (incident du 14/11/2026). Depuis, l'installateur passe la base SQLite en mode WAL : une page peut lire pendant que le travailleur écrit. Une base occupée plus de 15 secondes affiche « Le portail est occupé… réessayez dans quelques secondes » (rien n'est perdu, pas d'alerte par e-mail).

- À côté de `portail.sqlite` apparaissent `portail.sqlite-wal` et `portail.sqlite-shm` : **ne jamais les effacer** pendant que le portail tourne. Une sauvegarde de la base doit copier les trois fichiers ensemble, ou mieux se faire par `php -r '$p=new PDO("sqlite:".getenv("HOME")."/portail/data/portail.sqlite");$p->exec("VACUUM INTO \x27".getenv("HOME")."/sauvegarde_portail.sqlite\x27");'` (copie cohérente en un seul fichier ; la protéger ensuite en droits 600).
- Si l'installateur affiche « mode WAL non appliqué », le relancer quand aucun travail ne tourne.
- Revenir à l'ancien mode : `php -r '$p=new PDO("sqlite:".getenv("HOME")."/portail/data/portail.sqlite");echo $p->query("PRAGMA journal_mode=DELETE")->fetchColumn(),PHP_EOL;'` (affiche `delete`).

Si le portail s'affiche sans couleurs ni logo, le `.htaccess` de `public` empêche de servir les fichiers existants (style, logo, polices) : il doit laisser passer les fichiers réels et n'envoyer vers `index.php` que le reste.

## Travaux sur le serveur (V2)

Les pages du portail ne touchent jamais le serveur : elles inscrivent une demande dans la file des travaux. Le **travailleur** (`bin/travaux.php`) l'exécute ensuite, un étudiant à la fois, avec une pause entre chacun (blocage d'adresse IP par O2switch constaté en février 2026).

- Tâche planifiée de CPanel (Tâches Cron), **chaque minute** : `* * * * *  /usr/local/bin/php /home/jafl3067/portail/bin/travaux.php > /dev/null 2>&1`. Sans elle, les travaux restent « En attente ».
- Un seul travailleur à la fois (verrou `data/tmp/travaux.lock`) : si le précédent n'a pas fini, le suivant s'arrête aussitôt.
- Chaque passage traite de nouvelles étapes pendant 50 secondes au plus ; le passage suivant reprend la suite.
- Une étape restée « en cours » après un arrêt brutal (serveur redémarré, programme tué) n'est **jamais relancée** : elle passe en « Échec – interrompue ». Vérifier le site concerné avant de relancer.
- Un travail terminé avec au moins un échec déclenche une alerte par e-mail ; le détail est sur la page du travail.
- Lancer le travailleur à la main pour voir ce qu'il fait : `php ~/portail/bin/travaux.php --bavard`.
- Suivi dans le portail : menu « Travaux en cours » (avancement étudiant par étudiant, bouton « Arrêter » pour le demandeur et l'administrateur) et « Liste des sites » (une ligne par étudiant, une colonne par site, d'après le dernier relevé).
- « Mettre à jour la liste des sites » (administrateur, enseignant, administratif) inscrit un relevé dans la file : lecture seule, rien n'est modifié.

## Boutiques PrestaShop d'entraînement (V2)

Menu « Créer des boutiques » (administrateur, enseignants, administratif) : choisir la session, cocher les étudiants, lancer. Ne sont proposés que les étudiants dont le sous-domaine figure au dernier relevé et qui n'ont pas déjà de boutique.

- La première création d'une session lui fixe sa version PrestaShop (paquet de la bibliothèque) : toutes ses boutiques auront la même.
- Pour chaque étudiant, le travailleur : vérifie le dossier du sous-domaine auprès de CPanel (il doit être `~/identifiant.bts-sup-sallanches.com`) et que `/Presta` est vide ; crée la base `jafl3067_identifiant_Presta` et l'utilisateur `jafl3067_identifiant_presta` (mot de passe tiré au hasard, écrit seulement dans `app/config/parameters.php` de la boutique) ; décompresse le paquet après vérification de son empreinte ; installe la boutique par défaut, en français, en https ; renomme le dossier d'administration en `admin-identifiant-` + 6 caractères au hasard ; efface le dossier d'installation.
- En cas d'échec, ce que l'étape a créé (dossier, base, utilisateur) est effacé ; rien d'autre n'est touché. Un dossier `/Presta` non vide n'est jamais modifié.
- Accès : e-mail à l'étudiant (boutique, administration, identifiant = son e-mail, mot de passe) ; à la fin du travail, un e-mail à l'adresse d'alerte avec les lignes à coller dans la feuille des accès. Le mot de passe est gardé chiffré le temps de ce récapitulatif, puis effacé. Supprimer ce récapitulatif une fois la feuille à jour.
- Le lien d'administration figure sur la fiche de l'étudiant (administrateur et enseignants).
- Le récapitulatif contient un tableau aux colonnes de la feuille des accès (`Prestashopadmin`, `Identifiantprestashop`, `Mot de passe Prestashop`), dans l'ordre alphabétique des étudiants, et un second tableau de repère (étudiant, identifiant) à ne pas copier.
- L'e-mail à l'étudiant a pour objet « IMPORTANT – Sup'Sallanches (BTS NDRC) : vos accès… » et pour expéditeur « Sup'Sallanches – BTS NDRC ».
- À venir (décisions du 11/11/2026) : colonnes WordPress `Site wordpress` (lien sans l'administration), `Identifiant WP`, `Mot de passe WP` ; pour un sujet `/WP2`, aucun e-mail à l'étudiant, tout va à l'administrateur et aux enseignants.
- **Gel par surveillance** (décision du 08/11/2026) : le paquet 9.1.5 ne contient pas le module de mise à jour (« Assistant de mise à jour », `autoupgrade`). Chaque relevé (bouton ou tâche du lundi) lit la version réelle de chaque boutique (`app/AppKernel.php`) et repère ce module ; une boutique dont la version diffère de celle de sa session, ou qui a ce module, est signalée en rouge dans « Liste des sites » et déclenche une alerte (sans nom d'étudiant).

## WordPress des étudiants : création (V2, lot 5, 14/11/2026)

Menu « Créer des WordPress » (administrateur, enseignants, administratif) : choisir la session, cocher les étudiants (aucun coché d'avance ; case « Tout cocher »), lancer. L'étudiant doit d'abord être dans le portail (« Import liste Google Sheets » ou « Création d'un étudiant ») : son identifiant et son identifiant WordPress (`AzzouziK`) sont ceux de sa fiche. Un étudiant dont le `/WP` existe déjà (dernier relevé ou portail) ne peut pas être coché.

- **État produit** : celui des 1re année créés par `creation_wp.sh` (relevé du 14/11/2026 sur un site de la session 2028) — WordPress en français, titre « Sup », thème et extensions par défaut, permaliens simples, fuseau de Paris, un seul compte administrateur (identifiant WordPress de l'étudiant, avec son e-mail), adresse d'administration du site = adresse d'alerte, `.htaccess` avec la seule redirection vers https (`HTTPS-PORTAIL`).
- **Sous-domaine** : s'il manque, le travailleur le crée (`uapi SubDomain addsubdomain`, dossier `~/identifiant.bts-sup-sallanches.com`). Son **certificat** est demandé à la fin de l'étape, avec acme.sh (mêmes commandes que `certificats.sh` ; CPanel l'active en 30 minutes au plus). Un sous-domaine existant n'est pas touché.
- **Certificat pas obtenu tout de suite** (constaté au premier essai réel, 14/11/2026 : échec juste après la création du sous-domaine, réussite plus tard) : le site est quand même créé ; le message du travail donne la raison d'acme.sh ; le travailleur **réessaie seul toutes les 20 minutes**, quand il n'a pas d'autre travail, **6 fois au plus** (Let's Encrypt n'accepte que 5 échecs par sous-domaine et par heure, et en rend un toutes les 12 minutes : letsencrypt.org/docs/rate-limits). Au-delà, une alerte part à l'adresse d'alerte ; poser alors le certificat à la main : `bash ~/certificats.sh`, puis l'identifiant. Chaque essai est inscrit au journal (« certificat posé / non posé (relance) »).
- **Version** : celle de la session si elle est indiquée sur la page « Régimes et sessions » (version **gelée**) ; sinon, tant que l'Éducation nationale n'a pas fixé la version de l'examen, la **dernière version de WordPress**, qui se met à jour toute seule (décision du 14/11/2026).
- **Base et utilisateur MySQL propres au site** : `jafl3067_identifiant_WP_xxxxxx` et `jafl3067_identifiant_wpxxxxxx` (6 caractères au hasard), mot de passe tiré au hasard écrit seulement dans `wp-config.php` (droits 600, écrit par le portail).
- **Mot de passe WordPress** : tiré au hasard (forme `kQ7m-Vx3d-p9TA-hc4W`), jamais en ligne de commande (le compte est créé avec un mot de passe jetable, aussitôt remplacé par le vrai, lu dans l'environnement de la commande), jamais gardé par le portail.
- **Préparation à part** (`~/portail/data/tmp`), puis mise en place : un `/WP` non vide n'est jamais touché ; en cas d'échec, la préparation, la base et l'utilisateur créés sont effacés (un sous-domaine créé est gardé, vide).
- **E-mails** : à l'étudiant, objet « IMPORTANT – Sup'Sallanches (BTS NDRC) : vos accès à votre site WordPress, à conserver » (site, administration, identifiant, mot de passe) ; à la fin du travail, à l'adresse d'alerte, le récapitulatif aux colonnes de la feuille des accès (`Site wordpress` = lien sans l'administration, `Identifiant WP`, `Mot de passe WP`), dans l'ordre alphabétique. Supprimer ce récapitulatif une fois la feuille à jour.
- **Essais du 14/11/2026** : installation, mot de passe et réglages vérifiés avec le vrai WP-CLI sur un WordPress de test. Premier essai réel (un nouvel entrant) : sous-domaine, WordPress et e-mails conformes ; certificat posé à la main ensuite (d'où les relances automatiques ci-dessus).

## Réinitialiser un WordPress ou une boutique (V2, lot 5, 15/11/2026)

Sur la fiche de l'étudiant, rubrique « Réinitialiser un site » (**enseignants et administrateur** ; l'administratif passe par une demande à l'administrateur, voir « Demandes »). Un bouton par site présent (`/WP`, `/Presta` créée par le portail), avec une case de confirmation obligatoire.

- **Effet** : le site repart de zéro, exactement comme à la création (WordPress : mêmes réglages que « Créer des WordPress », version de la session si elle est fixée, sinon la dernière ; boutique : version PrestaShop de la session). Le site neuf est construit à part ; le site actuel n'est remplacé qu'à la fin et **mis à l'écart 8 jours** (`~/archives_suppression/portail/`, avec sa base), puis effacé par la purge. En cas d'échec, le site actuel reste (ou est remis) à sa place.
- **Accès** (décision du 15/11/2026) : nouveau mot de passe tiré au hasard ; e-mail à l'étudiant (« … a été réinitialisé, nouveaux accès à conserver ») ; à la fin du travail, récapitulatif aux colonnes de la feuille envoyé à **l'adresse d'alerte et aux enseignants actifs**. Même identifiant qu'avant.
- **Le site est-il bien celui de l'étudiant ?** Un `/WP` créé par le portail, oui. Un `/WP` plus ancien (scripts) n'est réinitialisé que s'il a un compte WordPress à l'identifiant WordPress de l'étudiant ou à son e-mail ; sinon l'étape est refusée, rien n'est touché. Une boutique n'est réinitialisée que si le portail l'a créée.
- **Ancienne base** : celle du portail, ou celle de `wp-config.php` si elle est propre à l'étudiant (`jafl3067_identifiant…`) ; l'utilisateur partagé `jafl3067_peda` des scripts n'est jamais effacé. Depuis le 15/11/2026, la purge n'efface jamais une base encore citée par **l'un des sites en place de l'étudiant** (/WP, /WP2, /Presta, /Presta2).
- **Redoublants** (décision du 07/11/2026) : après un changement de session vers une session plus tardive, la fiche le rappelle — 1re année : WordPress à réinitialiser ; 2e année : boutique à réinitialiser, WordPress gardé. Le rappel disparaît une fois le site refait.

## Demandes à l'administrateur (lot 5, paquet B, 15/11/2026)

Décision du 05/11/2026 : l'enseignant et l'administratif demandent, l'administrateur seul décide.

- **Demander** : page « Demandes », étiquette **Faire une demande au service informatique** (demande du 18/11/2026), ou fiche de l'étudiant, rubrique « Demander au service informatique ». Choix de la fiche : supprimer un de ses sites (/WP, /WP2, /Presta, /Presta2), supprimer l'étudiant (tous ses sites et son sous-domaine), ou, pour l'administratif, réinitialiser le WordPress ou la boutique. **Motif obligatoire** (3 à 300 caractères ; propositions : départ, abandon, fin de formation, redoublement…). Une demande identique déjà en attente est refusée.
- **Formulaire « Faire une demande au service informatique »** (enseignant, administratif ; 18/11/2026) : 1. objet, **supprimer** ou **signaler un problème (SAV)** ; 2. qui est concerné, un étudiant, une classe (régime + session) ou, pour un problème, personne en particulier ; 3. le site (facultatif ; pour une suppression, aucun site = l'étudiant entier) ; 4. le motif (suppression, 3 à 300 caractères) ou la description (problème, 10 à 1 000 caractères).
  - **Suppression pour une classe** : une demande par étudiant de la classe, que l'administrateur valide en une fois (un seul travail). Un étudiant qui n'a pas le site choisi, ou qui a déjà la même demande en attente, est passé ; le compte rendu le dit.
  - **Problème (SAV)** : plusieurs problèmes peuvent attendre pour un même étudiant. L'administrateur le coche puis « Valider la sélection » : il est marqué **traité** (aucun travail lancé), avec sa réponse facultative dans le champ « Commentaire », transmise au demandeur par e-mail et dans « Mes demandes » ; il peut aussi le refuser avec un commentaire. Étiquette « Problème signalé » sur la ligne de l'étudiant dans « Liste des sites ».
- **E-mails** : à l'adresse d'alerte pour chaque nouvelle demande ; au demandeur pour la décision. Ni nom d'étudiant, ni motif dans ces e-mails : le détail est dans le portail.
- **Signalement** : compteur à côté de « Demandes » dans le menu et sur la tuile d'accueil de l'administrateur ; étiquette « Suppression demandée » ou « Réinitialisation demandée » sur la ligne de l'étudiant dans « Liste des sites », et rubrique « Demandes en attente » sur sa fiche.
- **Traiter** (page « Demandes », administrateur) : demandes groupées par type, case « Tout cocher » et filtre. Cocher une demande (une par une) ou plusieurs (par lot), puis « Valider la sélection » ou « Refuser la sélection ».
  - Valider des suppressions : taper `SUPPRIMER` dans la case de confirmation. Les suppressions d'étudiants validées ensemble font **un seul travail** (exemple : toute une session en fin de 2e année), traité un étudiant à la fois.
  - Valider une réinitialisation : le travail habituel (« Réinitialiser un site ») est inscrit.
  - Une demande qui ne peut pas être lancée (travail déjà prévu, site disparu…) reste en attente, avec la raison affichée.
  - Refuser : commentaire obligatoire, transmis au demandeur.
- **Mes demandes** (étiquette **Voir les demandes en cours**) : chaque demandeur suit ses demandes (en attente, validée avec le numéro du travail, refusée avec le commentaire, annulée) et peut annuler les siennes tant qu'elles sont en attente.

## Supprimer un site ou un étudiant, restaurer (lot 5, paquet B, 15/11/2026)

**Administrateur seul** (décision du 29/10/2026), de trois façons : sur la fiche de l'étudiant (bloc « Actions sur cet étudiant »), en validant une demande, ou par le menu **Sites, Supprimer** (demande du 17/11/2026), qui propose deux étiquettes : **Supprimer une liste** (par session, voir plus bas) et **Supprimer un étudiant** (choisir l'étudiant dans la liste, puis le site ou l'étudiant entier, comme sur sa fiche).

- **Confirmation** : sur la fiche, choisir le site ou l'étudiant, puis **taper l'identifiant de l'étudiant** ; par lot, taper `SUPPRIMER`. Un étudiant qui a déjà un travail prévu ne peut pas être supprimé avant la fin de ce travail. Les sites protégés ne sont jamais proposés.
- **Rien n'est effacé tout de suite** (décision du 15/11/2026 : tout, restaurable 8 jours).
  - **Un site** : son dossier est mis à l'écart 8 jours dans `~/archives_suppression/portail/`, sa base et son utilisateur MySQL restent en place.
  - **Un étudiant** : le dossier entier du sous-domaine est mis à l'écart, la fiche quitte toutes les listes, et le portail note toutes ses bases et tous ses utilisateurs MySQL. Les bases notées sont celles du portail, celles de ses fichiers de réglages si elles sont à son nom, et toutes celles de CPanel au nom exact `jafl3067_identifiant` ou `jafl3067_identifiant_…`, comme `nettoyage_etudiants.sh`. La base partagée `jafl3067_peda` et les bases protégées ne sont jamais retenues.
  - **Le sous-domaine** reste dans CPanel pendant les 8 jours ; son adresse ne montre plus de site. L'identifiant reste réservé : ni « Création d'un étudiant », ni l'import ne peuvent le reprendre (l'import l'indique en rouge). Dans « Liste des sites », il est listé à part, avec sa date d'effacement.
- **Supprimer une liste** (menu Sites, Supprimer, étiquette « Supprimer une liste » ; administrateur seul, demande du 16/11/2026) : pour une session entière, par exemple la session 2027 en juillet 2027.
  - Choisir la session (actives et archivées, avec le nombre d'étudiants), puis « Afficher les étudiants ». Les sites connus de chacun s'affichent ; les filtres et « Tout cocher » sont disponibles.
  - Choisir ce qu'il faut supprimer : **tout** (les étudiants, leurs sites, leurs bases et leurs sous-domaines), ou **seulement un site** (/WP, /WP2, /Presta ou /Presta2) ; dans ce dernier cas, les étudiants restent.
  - Un motif facultatif est gardé au journal. Taper `SUPPRIMER`, puis valider.
  - Un seul travail est créé pour toute la sélection, traité un étudiant à la fois. Un étudiant qui n'a pas le site choisi, ou qui a déjà un travail prévu, est passé.
  - Comme pour une suppression depuis la fiche, tout reste restaurable 8 jours.
- **Restaurer** : page « Demandes », rubrique « Suppressions restaurables », bouton « Restaurer » (administrateur). Le dossier reprend sa place tel quel, avec ses bases. Pour un étudiant, sa fiche, ses sites et son relevé reviennent ; si son sous-domaine a été retiré de CPanel entre-temps (à la main), il est recréé et son certificat redemandé. La restauration est refusée si quelque chose occupe déjà la place.
- **Au bout des 8 jours**, le travailleur efface, quand il n'a pas d'autre travail, une mise à l'écart à la fois.
  - Pour un site : le dossier, la base et l'utilisateur, sauf s'ils servent encore à un site en place de l'étudiant.
  - Pour un étudiant : le dossier, toutes les bases et tous les utilisateurs notés, puis le renouvellement de son certificat (`acme.sh --remove`) et son **sous-domaine dans CPanel**.
  - **Retrait du sous-domaine** : UAPI n'a pas de fonction pour le faire (documentation de CPanel, SubDomain::delsubdomain). Le portail utilise donc la fonction de l'API 2, au format `identifiant_bts-sup-sallanches.com`. Il passe d'abord par la commande `cpapi2` du serveur ; à défaut, comme `nettoyage_etudiants.sh`, par un jeton temporaire, détruit aussitôt après. Le résultat est vérifié en relisant la liste des sous-domaines.
  - **En cas d'échec** : l'effacement est réessayé le lendemain, et l'échec est inscrit au journal (« effacement d'une mise à l'écart »). Si le sous-domaine reste, le retirer à la main : CPanel, Domaines, Gérer, Supprimer.

## Menu et mots du portail (17/11/2026)

- **Menu en rubriques** : Étudiants, Sites, Examens, Documents, Suivi, Administration, Mon compte (Documents placé entre Examens et Suivi le 18/11/2026). Chacun ne voit que les pages permises par son rôle.
- **Étiquettes** (18/11/2026) : les pages qui regroupent plusieurs façons de faire s'ouvrent sur des étiquettes cliquables.
  - **Ajouter des étudiants** : « Import liste Google Sheets » et « Création d'un étudiant » (formulaire) ; noms changés le 18/11/2026. Une seule entrée au menu.
  - **Demandes** (enseignant, administratif ; 18/11/2026) : « Voir les demandes en cours » et « Faire une demande au service informatique ».
  - **Supprimer** (administrateur) : « Supprimer une liste » et « Supprimer un étudiant ».
  - **Site modèle d'examen** : quatre étapes numérotées, 1 Charger le sujet, 2 Déployer dans les sites d'examen, 3 Clôturer l'épreuve, 4 Geler ou rouvrir ; l'étiquette PrestaShop est marquée « bientôt » (lot 6).
  - **Bibliothèque** : trois rayons, Documents, Sujets d'examen et Versions PrestaShop ; l'administratif et la direction ne voient que Documents.
- **Fiche de l'étudiant en cartes** (18/11/2026) : ses informations, puis une carte par site avec « Ouvrir le site » (ou « Ouvrir la boutique ») et « Administration » ; les actions (réinitialiser, demander, supprimer) sont repliées sous « Actions sur cet étudiant », ouvertes d'office quand un message ou une demande en attente les concerne ; viennent ensuite « Parcours et historique » et « Détails techniques ».
- **Phrases d'aide** : sous le titre de chaque page, une phrase dit à quoi elle sert ; le « ? » déplie le détail.
- **Mots de tous les jours**, avec le dossier technique en petit : Site WordPress `/WP`, Site d'examen `/WP2`, Boutique `/Presta`, Boutique d'examen `/Presta2`. Autres changements : « Voir tout » devient « Liste des sites », « sous-domaine » devient « adresse du site », « relevé » devient « liste des sites mise à jour le… », et les suppressions restaurables forment la « corbeille ».
- **États** : chaque pastille a un signe en plus de sa couleur (✓ prêt, ⚠ à vérifier, ✕ échec, ⏳ en cours).
- **Icône d'onglet** : le logo (`public/favicon.png`).

## Recherche rapide (18/11/2026)

Une seule barre de recherche, en haut de chaque page, pour tous les rôles (décisions des 17 et 18/11/2026).

- En cliquant dans la barre, la liste propose les situations courantes, selon le rôle : un étudiant arrive, un étudiant part ou redouble, préparer un BTS blanc, problème de site ou de boutique, trouver un document, faire une demande au service informatique.
- En tapant, elle propose les étudiants trouvés (nom ou classe ; seulement pour les rôles qui ouvrent les fiches : pas la direction), puis les actions et les pages du menu (« certificats », « journal »…). Majuscules et accents ne comptent pas.
- Flèches pour choisir, Entrée pour ouvrir, Échap pour fermer. Entrée sans choix ouvre la page « Recherche » avec tous les résultats (elle marche aussi sans JavaScript).
- L'identifiant des étudiants n'est pas dans la recherche : il reste sur la fiche.

## Filtre des listes (15/11/2026)

Au-dessus de chaque liste de plus de 5 lignes (étudiants, « Liste des sites », comptes, créations, déploiement, épreuve, certificats), une bande de filtre :

- un menu « Régime » et un menu « Session », dans les listes qui ont ces colonnes, avec « (tous) » par défaut (demande du 15/11/2026 : pas de menu pour l'année ni les autres colonnes) ;
- une zone « Texte » : chaque mot tapé doit se trouver quelque part dans la ligne (sans tenir compte des majuscules ni des accents) ;
- tous les critères s'ajoutent : par exemple Régime « NDRC ALT » et Session « 2026-2028 » ;
- « n ligne(s) sur N » s'affiche, et « Effacer » remet la liste entière.

Le tri par clic sur l'en-tête reste possible. Avec un filtre, « Tout cocher » ne coche que les lignes affichées.

Depuis le 18/11/2026, c'est le seul filtre de « Liste des étudiants » et de « Liste des sites » : l'ancien choix « Régime / Session / Afficher » au-dessus de la liste, gardé d'une connexion à l'autre, est retiré (risque d'oubli). Chaque page s'ouvre sur tous les étudiants. La version 15 de la base efface les filtres gardés.

## Certificats https : suivi et relance (administrateur, 14/11/2026)

Menu « Certificats », **visible de l'administrateur seulement** (demande du 14/11/2026).

- **Vérification** : le travailleur examine le certificat de chaque sous-domaine du dernier relevé **comme un navigateur** (même méthode que `certificats.sh` : `openssl s_client` avec vérification du nom, deux essais de 15 secondes), **une fois par jour d'office**, ou quand l'administrateur clique « Vérifier tous les certificats maintenant ». Dix sites par minute, seulement quand il n'a pas d'autre travail ; rien n'est modifié.
- **Page** : pour chaque sous-domaine, l'état (reconnu, non reconnu, expire bientôt, pas encore vérifié), la date de fin, la date de vérification et l'état d'une relance ; les problèmes d'abord, puis un bilan chiffré. « Expire bientôt » = moins de 14 jours : acme.sh renouvelle de lui-même environ 30 jours avant la fin (renouvellement tous les 60 jours, `certificats.sh`), donc un certificat aussi proche de sa fin signale un renouvellement en panne.
- **Relancer** : cocher les sous-domaines (case « Tout cocher »), puis « Relancer ». Premier essai dès que le travailleur est libre, puis toutes les 20 minutes, 6 au plus ; la colonne « Relance » montre les essais et la dernière raison d'acme.sh. Un certificat posé est revu à la vérification suivante (CPanel l'active en 30 minutes au plus). Les **sites protégés** (`peda`, `sujet`…) ne se relancent pas depuis le portail : `certificats.sh`.
- **Création d'un WordPress** : le certificat d'un sous-domaine neuf est demandé en fin de création ; en cas d'échec, la même relance automatique prend le relais.
- **E-mail du lundi** (tâche `alertes.php hebdo`) : un message « Certificats https à surveiller » seulement s'il y a un problème — certificats non reconnus, expirant dans moins de 14 jours, relances abandonnées, ou vérification pas faite depuis plus de 3 jours (travailleur arrêté ?). Des nombres seulement, aucun nom d'étudiant, et le lien de la page.

## Site modèle d'examen (V2, lot 4)

Décisions du 13/11/2026. Le sujet d'examen WordPress vit sur un sous-domaine dédié, `sujet.bts-sup-sallanches.com`, dans `/WP` : c'est le site tel que les étudiants le recevront dans leur `/WP2`.

- **Mise en place (une fois, à la main)** : créer le sous-domaine `sujet` dans CPanel, poser son certificat (`bash ~/certificats.sh`, puis `sujet`), actualiser le relevé. `sujet` est dans la liste des sites protégés : aucun travail d'étudiant ne le touche, et il n'apparaît pas parmi les « sous-domaines sans étudiant ».
- **Paquets Duplicator acceptés** : base en un seul fichier `dup-installer/dup-database__….sql` (paquets d'octobre 2026), ou une table par fichier compressé `dup-installer/dup_descriptors_…/db_dumps/*.sql.gz` avec `schema.sql.gz` qui crée les tables (versions récentes, relevé le 14/11/2026) ; dans ce cas le schéma est importé d'abord, puis les données, en un seul import.
- **Page « Site modèle d'examen »** (administrateur, enseignants) : l'enseignant choisit un sujet WordPress de la bibliothèque (paquet Duplicator) et l'installe sur le modèle. Un seul travail sur le modèle à la fois.
- Le travailleur prépare le nouveau modèle à part (`~/portail/data/tmp`), avec une base et un utilisateur MySQL neufs (`jafl3067_sujet_wp_xxxxxx`, `jafl3067_sujet_xxxxxx`, 6 caractères au hasard) ; écrit lui-même `wp-config.php` (droits 600 ; aucun mot de passe en ligne de commande) ; importe la base du paquet (sans les lignes d'avertissement `mysqldump:`) ; remplace les adresses du site d'origine par `https://sujet.bts-sup-sallanches.com/WP` avec WP-CLI (`/usr/local/bin/wp`, en http, en https et sous forme « échappée », puis le chemin du dossier d'origine) ; masque le modèle aux moteurs de recherche ; met l'adresse d'administration du site sur l'adresse d'alerte ; retire les fichiers de Duplicator et les paquets Duplicator rangés dans le site ; écrit le `.htaccess` standard de WordPress pour `/WP`.
- WP-CLI est toujours lancé avec `--skip-plugins --skip-themes` : le portail n'exécute jamais le code des extensions d'un site.
- **Bascule** : le modèle en place reste en ligne jusqu'au dernier moment, puis il est mis à l'écart (voir ci-dessous) et le nouveau prend sa place. En cas d'échec avant la bascule, le modèle en place n'a pas changé et ce que l'étape avait créé (dossier de préparation, base, utilisateur) est effacé.
- **Contrôle de l'administrateur (non bloquant)** : à chaque installation, un e-mail à l'adresse d'alerte donne la version de WordPress, les extensions (celles qui donnent accès aux fichiers du serveur sont signalées « à retirer »), les comptes du site (aucun compte d'étudiant ne doit rester) et le thème. L'enseignant peut déployer sans attendre.
- **Version** : tant que l'année d'examen n'est pas figée, le modèle se met à jour (réglage par défaut de WordPress : mises à jour mineures automatiques). Le bouton « Geler la version » ajoute `AUTOMATIC_UPDATER_DISABLED` et `WP_AUTO_UPDATE_CORE = false` dans le bloc « Réglages gérés par le portail » de `wp-config.php` ; « Dégeler » les retire. Seul ce bloc est réécrit. Un nouveau sujet garde l'état de gel du modèle.
- Après une installation : se connecter à l'administration du modèle avec les accès du site d'origine, supprimer le compte et le contenu personnel de l'étudiant d'origine, vérifier les extensions.

### Déploiement dans les /WP2 (paquet 2, 14/11/2026)

- **Page « Site modèle d'examen », bloc « Déployer chez les étudiants »** (administrateur, enseignants) : choisir la session, cocher les étudiants (aucun n'est coché d'avance ; essayer d'abord avec un seul), puis « Déployer ». Un étudiant dont le sous-domaine manque au dernier relevé, ou dont le déploiement est déjà prévu, ne peut pas être coché. Si un étudiant coché a déjà un `/WP2` (d'après le dernier relevé), une case de confirmation est demandée.
- **Première étape : photo du modèle**, prise une seule fois dans `~/portail/data/tmp/photos/` : fichiers (sans `wp-config.php`, `.htaccess`, journaux d'erreurs, cache, ni fichiers de Duplicator ; les liens symboliques ne sont jamais recopiés) et base (export WP-CLI, sans les lignes `mysqldump:`). Tous les étudiants reçoivent exactement ce sujet, même si le modèle change pendant le déploiement. Tant que la photo n'est pas prise, aucun autre travail sur le modèle n'est accepté. Si la photo échoue, aucun étudiant ne reçoit le sujet : relancer.
- **Puis un `/WP2` par étudiant**, dans l'ordre alphabétique : base `jafl3067_identifiant_WP2_xxxxxx` et utilisateur `jafl3067_identifiant_w2xxxxxx` neufs (6 caractères au hasard), mot de passe tiré au hasard écrit seulement dans `wp-config.php` (droits 600) ; copie des fichiers de la photo ; import de la base ; adresses `https://sujet.bts-sup-sallanches.com/WP` remplacées par `https://identifiant.bts-sup-sallanches.com/WP2` (http, https, forme échappée, puis chemin du dossier) ; site masqué aux moteurs de recherche ; adresse d'administration du site = adresse d'alerte ; sessions ouvertes du modèle effacées ; `.htaccess` standard pour `/WP2` ; **version gelée**.
- **Comptes et mots de passe** : ceux du site modèle (décision du 14/11/2026). Aucun mot de passe n'est envoyé par e-mail ; aucun e-mail aux étudiants (décision du 11/11/2026).
- **`/WP2` existant** : mis à l'écart 8 jours (voir ci-dessous), puis remplacé. Sa base est retenue pour l'effacement au bout des 8 jours si elle est connue du portail ou propre à l'étudiant (`jafl3067_identifiant_…` dans son `wp-config.php`) ; un utilisateur MySQL partagé (`jafl3067_peda`, sites des scripts) n'est jamais retenu ni effacé.
- **Échec chez un étudiant** : son `/WP2` n'a pas changé ; ce que l'étape avait créé (dossier de préparation, base, utilisateur) est effacé ; le travail passe à l'étudiant suivant. Le détail est dans « Travaux en cours ».
- **Fin du travail** : e-mail à l'adresse d'alerte et aux comptes enseignants actifs, avec la liste des `/WP2` créés (site et administration) ; la photo est mise à l'écart 8 jours (`sujet`, dossier `/photo`), puis effacée par la purge.
- **Case « Tout cocher »** en tête de colonne (paquet 3) : coche les étudiants qu'on peut cocher, jamais ceux qui ont un empêchement ; la confirmation reste demandée pour remplacer un `/WP2`.
- **Place sur le disque** : illimitée sur l'offre O2switch du compte ; chaque `/WP2` fait la taille du modèle. Alléger le modèle (images trop lourdes, anciens fichiers) raccourcit le déploiement et les copies.
- Un nouveau déploiement remet l'épreuve de l'étudiant à zéro (plus de clôture, plus de lecture seule).

### Épreuve : préconnexion, clôture et gel (paquet 3, 14/11/2026)

- **Préconnexion** : à la main, avant l'épreuve, avec le compte administrateur du modèle. Si elle se fait plus de 2 jours avant l'épreuve, cocher « Se souvenir de moi » à la connexion (sinon WordPress ferme la session au bout de 48 heures ; avec la case, 14 jours).
- **Page « Site modèle d'examen », bloc « Épreuve : clôture et gel »** (administrateur, enseignants), après « Afficher les étudiants » : un tableau des étudiants qui ont un `/WP2` déployé par le portail, avec l'état de l'épreuve (ouverte, close — figé, close — gel levé, travail prévu) et le lien d'administration. Case « Tout cocher ».
- **Clôturer l'épreuve** (case de confirmation obligatoire) : pour chaque étudiant coché, le travailleur
  1. fait une **copie datée** du `/WP2` (base exportée par WP-CLI + tous les fichiers) et la met à l'écart 8 jours dans `~/archives_suppression/portail/` (la purge n'efface que ce dossier, jamais la base du site en place) ;
  2. **ferme les sessions WordPress** (étudiants préconnectés déconnectés ; ils n'ont pas le mot de passe) ;
  3. passe le site en **lecture seule** : module du portail `wp-content/mu-plugins/portail-epreuve.php` (chargé d'office par WordPress, impossible à désactiver depuis l'administration) et, dans le bloc « Réglages gérés par le portail » de `wp-config.php`, `PORTAIL_LECTURE_SEULE` et `DISALLOW_FILE_MODS` (aucune extension ni aucun thème ajouté, modifié ou mis à jour). La version reste gelée.
  Si la copie échoue, rien n'est changé dans le `/WP2` : relancer la clôture pour cet étudiant. À la fin, e-mail à l'adresse d'alerte et aux enseignants actifs (liste des `/WP2` clos, sans mot de passe).
- **En lecture seule** : les correcteurs se connectent avec le compte administrateur du modèle et consultent tout (pages, articles, médias, réglages, extensions, éditeur). Tout enregistrement est refusé avec le message « Site figé après l'épreuve… » : formulaires de l'administration, actions par lien (activer une extension, mettre à la corbeille…), API REST (éditeur de blocs), envoi de médias, XML-RPC ; en dernier recours, toute écriture dans la base du site, sauf la connexion, la déconnexion et les données temporaires de WordPress. Un bandeau le rappelle en haut de l'administration. **Déconnexion après 1 h d'inactivité** (les appels automatiques de la page ne comptent pas comme activité). Essais faits le 14/11/2026 sur un WordPress de test (6.8.3) : lecture, refus, déconnexion et reconnexion conformes.
- **Lever le gel** (enseignants ou administrateur, décision du 14/11/2026) : retire la lecture seule du `/WP2` coché (le module reste, inactif). **Refiger** la remet. Seul le bloc du portail change dans `wp-config.php`.
- **Retrouver une copie datée** (en SSH, pendant 8 jours) : la page du site modèle ne liste que les mises à l'écart du modèle ; les copies sont dans `~/archives_suppression/portail/AAAAMMJJ-HHMMSS-identifiant-WP2-xxxxxx/` (`base.sql`, `fichiers/`, `copie.json`). Pour remettre un `/WP2` dans l'état de la clôture : mettre le `/WP2` actuel de côté, recopier `fichiers/` à sa place, puis importer la base : `wp db import ~/archives_suppression/portail/NOM/base.sql --path="$HOME/identifiant.bts-sup-sallanches.com/WP2"`.

### Mises à l'écart (8 jours)

- Un site remplacé n'est pas effacé : son dossier est déplacé tel quel dans `~/archives_suppression/portail/` (nom : date, identifiant, dossier, 6 caractères), sa base et son utilisateur MySQL restent en place. La page du site modèle les liste.
- **Retour en arrière** pendant 8 jours (en SSH) : déplacer le dossier du site actuel ailleurs, remettre le dossier mis à l'écart à sa place (`mv ~/archives_suppression/portail/NOM ~/sujet.bts-sup-sallanches.com/WP`), puis relancer le relevé. Son `wp-config.php` pointe toujours sur sa base, et la purge ne l'effacera pas : une base ou un utilisateur cité par le site en place n'est jamais effacé.
- Au-delà de 8 jours, le travailleur efface le dossier, puis l'utilisateur et la base (jamais une base protégée, ni une base ou un utilisateur encore utilisé par un site connu du portail), une mise à l'écart à la fois, quand il n'a pas d'autre travail. Un échec est inscrit au journal et réessayé le lendemain.

## Bibliothèque et documents (V2)

- Depuis le 11/11/2026, une seule page « Bibliothèque » réunit les versions PrestaShop, les sujets et les documents ; l'administratif et la direction n'y voient que les documents. Depuis le 18/11/2026, elle est rangée en trois rayons (étiquettes) : Documents, Sujets d'examen, Versions PrestaShop.
- **Paquets** (administrateur, enseignants) : paquets d'installation PrestaShop (un par version, numéro indiqué au dépôt), sujets WordPress (archive ZIP de Duplicator). Les sujets PrestaShop arrivent avec le lot 6.
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
| Certificat d'un sous-domaine créé par le portail toujours pas posé après 6 essais | adresse d'alerte |
| Certificats à surveiller (le lundi, seulement s'il y a un problème) | adresse d'alerte |
| Nouvelle demande à l'administrateur (type et demandeur seulement) | adresse d'alerte |
| Décision sur ses demandes (nombres et commentaire de refus) | le demandeur seul |

Aucun e-mail ne contient de mot de passe ni de donnée d'étudiant.

- Tester l'envoi : `php ~/portail/bin/alertes.php test`.
- Tâche du lundi : tâche planifiée de CPanel (Tâches Cron), chaque lundi à 7 h 13 : `php ~/portail/bin/alertes.php hebdo` (comptes à revoir, relevé des sites, boutiques hors gel, certificats).
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

- **Supprimer un compte** (administrateur seul, demande du 16/11/2026 ; auparavant : jamais supprimé) : départ définitif d'un enseignant, par exemple. D'abord « Désactiver le compte », puis, sur sa fiche, taper son identifiant et cliquer « Supprimer définitivement ». On ne supprime pas son propre compte, ni le dernier administrateur. Le journal garde ses actions sous son nom ; ses demandes et ses dépôts restent, sans lien vers lui. Une erreur ne se rattrape qu'en recréant le compte.
- **Téléphone à relier de nouveau** (application d'authentification supprimée, téléphone changé) : sur la fiche du compte actif, « Réinitialiser le code à 6 chiffres ». À la connexion suivante, après le mot de passe, le code QR s'affiche. Pour un compte réactivé après un long arrêt, réinitialiser à la fois le mot de passe et le code.
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
