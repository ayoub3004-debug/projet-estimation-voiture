# Brancher Drivly sur des chiffres réels

Tant que rien n'est branché, Drivly affiche un bandeau ocre **« Estimation simulée »**
sur chaque résultat, et plafonne la confiance à 70 %. C'est volontaire : aucune décision
d'achat ne doit être prise sur ces chiffres.

---

## 0. Sans rien payer, tout de suite : le relevé de marché

Bloc **Relevé de marché** dans le formulaire. Vous ouvrez Leboncoin ou La Centrale sur
votre téléphone, vous cherchez le même modèle, et vous recopiez ce que vous voyez :

```
10 500 € 88 000 km 2019
9 900 € 112 000 km 2018
11 200 € 76 000 km 2020
10 800
```

Un prix par ligne suffit. Le kilométrage et l'année sont facultatifs mais recommandés :
avec eux, chaque annonce est **ramenée aux caractéristiques du véhicule que vous étudiez**
avant le calcul de la médiane. Une Clio de 2018 à 112 000 km affichée 9 900 € vaut
l'équivalent de 10 979 € pour votre Clio de 2019 à 96 000 km — c'est ce calcul que fait
l'outil, celui qu'un bon marchand fait de tête.

Formats acceptés indifféremment : `10500`, `10 500 €`, `10 500 € — 88 000 km — 2019`,
`Clio V 2019 | 9 490 € | 121 000 km`. Les lignes sans prix reconnaissable sont ignorées
et signalées.

Six à dix annonces suffisent. Le bandeau du résultat passe alors au gris foncé
**« Chiffres réels — votre relevé »**, la confiance monte jusqu'à 88 %, et les annonces
comparables affichées sont les vôtres.

**Avantages :** gratuit, légal, disponible immédiatement, et vous voyez de vos yeux ce qui
entre dans le calcul.
**Limite :** deux minutes de saisie par véhicule, et la qualité dépend des annonces que
vous choisissez. C'est le prix à payer pour ne rien payer.

---

## 1. Passer à l'automatique : choisir une source

Trois familles, à comparer sur le prix et la couverture France.

| Piste | Ce que ça donne | À vérifier auprès d'eux |
|---|---|---|
| **Autobiz** (autobizAPI) | Cotes B2C et B2B, valeurs de reprise, délais de rotation, identification par plaque ou VIN. Analyse quotidienne d'annonces du marché français. | Tarif à l'appel ou à l'abonnement, volume minimum, droit d'affichage des cotes à un particulier |
| **Autovista / JD Power** (Autovista API) | Cotes, fiches techniques, coûts d'entretien, valeurs résiduelles, couverture européenne | Idem, plus la granularité par finition |
| **L'Argus** | La référence historique des professionnels français ; cote personnalisée selon mise en circulation, kilométrage et options | Existence d'un accès API et conditions de réutilisation de la marque « Cote Argus » |

Autres acteurs à consulter selon les devis : Indicata, DAT, Cotemaat.

**Le scraping de Leboncoin, La Centrale ou AutoScout24 est à écarter** : c'est contraire à
leurs conditions d'utilisation, et une estimation bâtie dessus serait juridiquement fragile.

**Séparément, pour la saisie par plaque :** des API SIV existent à partir d'une dizaine
d'euros par mois et renvoient plus de cent champs techniques (marque, version, VIN,
motorisation, Crit'Air). C'est peu cher et ça supprime la saisie manuelle — mais ça ne donne
**aucun prix**. Les deux abonnements sont complémentaires, pas interchangeables.

Questions à poser dans tous les cas :

- Prix : au forfait mensuel ou à l'appel ? Y a-t-il un volume minimum ?
- Ai-je le droit d'afficher la valeur à un **particulier**, ou seulement en interne ?
- La réponse contient-elle des **annonces comparables** avec lien, ou seulement une cote ?
- Puis-je tester avant de signer ?

---

## 2. Installer l'adaptateur

Le navigateur ne doit jamais contenir votre clé fournisseur. Elle reste sur votre serveur,
dans l'un des deux fichiers fournis.

**Hébergement classique (OVH, o2switch, Ionos…)** → `api/cote.php`

1. Déposez `api/cote.php` à côté de `index.html`.
2. Ouvrez le fichier et renseignez `$FOURNISSEUR`, `$URL_FOURNISSEUR`, `$CLE_FOURNISSEUR`
   et `$ORIGINES_AUTORISEES`.
3. Adaptez la section **4. ADAPTATION** : il s'agit uniquement de faire correspondre les
   noms de champs du fournisseur aux nôtres.

**Hébergement serverless (Vercel, Netlify, Cloudflare)** → `api/cote.js`

Mêmes réglages, mais par variables d'environnement : `COTE_FOURNISSEUR`, `COTE_URL`,
`COTE_API_KEY`, `COTE_ORIGINES`.

Les deux fichiers limitent déjà les appels à 60 par heure et par visiteur, et filtrent
les origines autorisées.

---

## 3. Connecter dans l'application

Onglet **Réglages > Source des estimations** :

1. Origine des valeurs → *Source de marché connectée*
2. Adresse → `https://votre-domaine.fr/api/cote.php`
3. Clé → à laisser **vide** (elle est côté serveur)
4. **Tester la connexion**

En cas de succès, le message indique la source, le nombre d'annonces et la valeur médiane
reçues. Le bandeau des résultats passe au vert, la confiance retrouve son plafond de 97 %,
et les annonces affichées deviennent de vraies annonces cliquables.

---

## 4. Le contrat de réponse

Votre adaptateur reçoit la fiche véhicule en JSON et doit répondre ceci :

```json
{
  "valeurExcellent": 11200,
  "valeurMoyen":     10100,
  "valeurMediane":   10750,
  "nbTotal":         63,
  "source":          "autobiz",
  "annonces": [
    {
      "titre":       "Renault Clio 1.5 dCi 90 Business",
      "prix":        10200,
      "km":          88000,
      "annee":       2019,
      "departement": "69 Rhône",
      "source":      "Leboncoin",
      "lien":        "https://..."
    }
  ]
}
```

- `valeurMediane` suffit si le fournisseur ne distingue pas les états : Drivly reconstruit
  alors les quatre niveaux (excellent, bon, moyen, à rafraîchir) autour de cette valeur.
- `annonces` est facultatif. Sans lui, le bloc comparables reste vide mais l'estimation
  est réelle.
- Tout le reste de l'application — marge, TVA, portage, score, comparateur, PDF — se
  recalcule automatiquement sur ces valeurs.

---

## 5. Après le branchement

Notez vos prix de vente réels et comparez-les aux estimations. L'écart moyen se corrige
dans **Réglages > Correction globale de la cote**. C'est ce réglage, alimenté par vos
propres ventes, qui fera passer l'outil de « correct » à « fiable ».
