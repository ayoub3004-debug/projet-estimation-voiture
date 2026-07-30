# Cote Réelle — Extension navigateur (extracteur d'annonce)

Une extension Chrome/Edge qui lit la page d'annonce ouverte dans ton
onglet (Leboncoin, La Centrale, AutoScout24, ParuVendu...) et te
prépare un texte prêt à coller dans le bloc **"⚡ Analyse rapide"**
de Cote Réelle — pour éviter de faire le Ctrl+A / Ctrl+C toi-même.

## ⚠️ À savoir avant d'installer

Cette extension a été écrite **sans accès en direct** aux pages
Leboncoin / La Centrale / AutoScout24 (environnement de développement
sans accès à ces sites). Elle utilise la même détection par motifs
(regex) que le site — prix, kilométrage, année, carburant, boîte,
marque — appliquée au texte visible de la page. Ça fonctionne pour
la plupart des annonces, mais si un site a une mise en page inhabituelle,
certains champs peuvent ne rien détecter. Dans ce cas, complète à la
main dans Cote Réelle — le principe reste "je t'aide à aller vite,
tu vérifies avant de valider".

**Elle ne fait aucun scraping automatique en arrière-plan, aucune
requête réseau vers ces sites, et ne contourne aucune protection** —
elle lit uniquement ce que toi, utilisateur connecté sur la page,
vois déjà à l'écran, au moment où tu cliques sur l'icône. C'est
l'équivalent d'un Ctrl+A / Ctrl+C automatisé.

## Installation (mode développeur — l'extension n'est pas publiée sur le store)

1. Ouvre `chrome://extensions` (ou `edge://extensions` sur Edge).
2. Active le **"Mode développeur"** (interrupteur en haut à droite).
3. Clique sur **"Charger l'extension non empaquetée"**.
4. Sélectionne ce dossier `extension/`.
5. L'icône "Cote Réelle" apparaît dans la barre d'extensions.

## Utilisation

1. Ouvre une annonce (Leboncoin, La Centrale, AutoScout24...).
2. Clique sur l'icône de l'extension.
3. Clique sur **"🔍 Analyser cette page"**.
4. Vérifie les valeurs détectées.
5. Clique sur **"📋 Copier pour Cote Réelle"**.
6. Va sur le site Cote Réelle, colle (Ctrl+V) dans le champ
   "Texte collé de l'annonce" du bloc "⚡ Analyse rapide", clique
   "Analyser le texte collé".

## Si un site ne fonctionne pas bien (à ajuster toi-même)

Le fichier `content.js` contient toute la logique de détection,
dans la fonction `extractFromPage()`. Si un champ n'est jamais
détecté sur un site donné :

1. Ouvre les DevTools (F12) sur la page de l'annonce.
2. Repère où se trouve l'info dans le HTML (clic droit → Inspecter).
3. Tu peux soit ajuster les regex existantes dans `content.js`, soit
   ajouter un sélecteur CSS spécifique au site, par exemple :
   ```js
   const prixEl = document.querySelector('[data-qa-id="adview_price"]');
   if (prixEl) prix = parseInt(prixEl.textContent.replace(/\D/g,''), 10);
   ```
   (le sélecteur exact dépend du site et peut changer avec le temps —
   c'est la limite structurelle de toute extraction basée sur le DOM
   d'un site tiers que tu ne contrôles pas).

## Pourquoi une extension plutôt qu'un vrai scraper automatique ?

Parce que ça évite le problème central : une extension tourne dans
**ton** navigateur, avec **ta** session, sur une page que **tu** as
ouverte volontairement — pas de blocage CORS, pas de serveur à
maintenir, pas de risque de voir l'IP de ton serveur bannie par le
site. C'est l'option la plus proche de "un clic et c'est fait" sans
dépendre d'une API payante ou d'un scraper fragile côté serveur.
