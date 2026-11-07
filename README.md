# Portail des sites étudiants — Sup'Sallanches

Portail web sécurisé qui permet à l'équipe pédagogique et administrative de gérer les sites WordPress et PrestaShop des étudiants de BTS NDRC, hébergés sur O2switch : liste des étudiants, création, réinitialisation et suppression des sites, certificats, alertes.

- **Adresse** : https://portail.bts-sup-sallanches.com
- **Hébergement** : O2switch (compte `jafl3067`), PHP 8.1, Apache + LiteSpeed (mod_lsapi)
- **Administrateur** : service informatique (`informatique@ecs-sallanches.fr`)

## Règles d'or

1. **Aucun secret dans ce dépôt.** Mots de passe, clé de chiffrement, base de données et configuration réelle vivent uniquement sur le serveur, dans `~/portail/data`. Le dépôt ne contient qu'un modèle vide (`config/config.exemple.php`).
2. **Aucune version sans contrôles au vert** : `bash outils/controle.sh`.
3. **Le serveur n'est jamais relié à GitHub.** Une version validée est copiée sur le serveur par une personne.
4. **Aucune dépendance en production** : PHP 8.1 seul, sans framework ni Composer. Les outils de `outils/` servent uniquement au développement.

## Organisation

| Dossier | Contenu | Sur le serveur |
| --- | --- | --- |
| `public/` | La seule page exposée au web, qui appelle le reste | `~/portail/public` |
| `app/` | Le code du portail | `~/portail/app` |
| `bin/` | Commandes lancées en SSH ou par tâche planifiée (installation, secours, relevés) | `~/portail/bin` |
| `config/` | Modèle de configuration, sans aucune valeur secrète | copie remplie dans `~/portail/data/config.php` |
| `tests/` | Tests automatiques | non copié |
| `docs/` | Guide de l'administrateur, fiche de passation | non copié |
| `outils/` | Contrôles automatiques (développement uniquement) | non copié |

Sur le serveur, `~/portail/data` (base, sessions, configuration) et `~/portail/logs` (journal des erreurs) sont en droits `700` et ne sont jamais accessibles depuis le web. Les dossiers `app`, `bin`, `data` et `logs` sont voisins de `public`, jamais à l'intérieur.

## Contrôles automatiques

```
bash outils/installer_outils.sh      # une fois : versions fixées, empreintes vérifiées
git config core.hooksPath outils/hooks   # contrôle automatique avant chaque commit
bash outils/controle.sh              # contrôle complet
```

| Contrôle | Outil | Rôle |
| --- | --- | --- |
| Syntaxe | `php -l` | Fichiers PHP lisibles |
| Tests | PHPUnit 9.6 | Comportement attendu (droits, blocages, calculs…) |
| Logique | PHPStan 2.3.5, niveau max, PHP 8.1 | Erreurs détectées sans exécuter le code |
| Analyse stricte | Psalm 5.26.1, niveau 1, PHP 8.1 | Types et cas oubliés |
| Données dangereuses | Psalm `--taint-analysis` | Suit chaque donnée du visiteur jusqu'à son affichage ou son enregistrement |
| Pièges de sécurité | Semgrep 1.184.0 + `outils/semgrep/regles-portail.yml` | 13 règles propres au portail, chacune vérifiée par un cas de test |
| Pièges de sécurité | Semgrep + règles publiques PHP (`php/lang` de [semgrep-rules](https://github.com/semgrep/semgrep-rules), commit `16b9e6d`) | 37 règles, téléchargées par `installer_outils.sh` et vérifiées par empreinte |
| Secrets | gitleaks 8.28.0 | Fichiers et historique ; bloque tout commit contenant un secret |

Les règles publiques Semgrep sont sous [Semgrep Rules License v1.0](https://semgrep.dev/legal/rules-license) : usage interne seulement, sans redistribution. Elles ne sont donc pas copiées dans ce dépôt.

## Mise à jour de PHP sur le serveur

Le code vise PHP 8.1, version du serveur au 06/11/2026. Une montée de version est prévue à terme. Avant de changer la version PHP du sous-domaine `portail` dans CPanel :

1. Vérifier que la nouvelle version est prise en charge par les outils (PHPStan, Psalm).
2. Remplacer `8.1` par la nouvelle version dans `phpstan.neon.dist` (`phpVersion`) et `psalm.xml` (`phpVersion`), puis lancer `bash outils/controle.sh` et corriger les avertissements (fonctions dépréciées, comportements modifiés).
3. Lancer les tests sur le serveur, avec la nouvelle version en ligne de commande.
4. Changer la version dans CPanel pour le seul sous-domaine `portail`, puis vérifier la connexion et le journal `~/portail/logs/php_errors.log`.

Les réglages de sécurité étant appliqués par le code (`ini_set()`), ils restent actifs quelle que soit la version.

## État

Préparation du serveur terminée le 06/11/2026 (sous-domaine, certificat, porte d'entrée, messagerie). Développement de la V1 à venir : connexion avec double authentification, comptes et rôles, classes, import des étudiants, consultation, journal.

Dépôt privé, créé sur le compte de Stéphanie Gauthier-Roy ; transfert prévu vers une organisation GitHub de l'établissement à la remise de la V1.
