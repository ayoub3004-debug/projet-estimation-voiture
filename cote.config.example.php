<?php
/* =============================================================================
   Drivly — modèle de configuration pour api/cote.php

   MARCHE À SUIVRE :
   1. Copiez ce fichier sous le nom cote.config.php, dans le même dossier api/.
   2. Remplissez les vraies valeurs ci-dessous (adresse du fournisseur, clé,
      domaine du site).
   3. Déposez cote.config.php sur votre hébergeur par FTP, mais ne le
      commitez JAMAIS sur GitHub : ajoutez cette ligne à votre .gitignore :

        api/cote.config.php

      Sinon votre clé de fournisseur se retrouve en clair dans un dépôt
      public, visible par n'importe qui.

   Beaucoup d'hébergeurs mutualisés ne donnent pas un accès simple aux
   variables d'environnement (contrairement à Vercel) : ce fichier PHP classique
   est la façon la plus fiable de garder un secret hors du dépôt Git, quel
   que soit l'hébergeur.
   ============================================================================= */

return [

    /* 'demo' tant que rien n'est configuré : l'adaptateur refuse toute
       requête et répond une erreur claire plutôt que de faire croire à des
       chiffres réels. Passez à 'generique' une fois les champs ci-dessous
       renseignés. */
    'fournisseur' => 'demo',

    /* Adresse de l'API du fournisseur de cotation. Doit être en https. */
    'url' => 'https://api.exemple.fr/v1/valuation',

    /* Clé fournie par le fournisseur de cotation. */
    'cle' => '',

    /* Adresse(s) exacte(s) de votre site, celles qui ont le droit d'appeler
       cet adaptateur. Sans « https:// » à la fin, ex. SANS slash final. */
    'origines' => [
        'https://votre-domaine.fr',
    ],

    /* Facultatif : jeton à coller dans Réglages > Source des données > Clé
       d'accès, côté professionnel, pour ne pas être limité par le quota de
       requêtes public. Laissez vide pour ne pas activer cette option. */
    'jeton_pro' => '',

];
