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
