# Drivly

Outil d'estimation de valeur automobile et d'aide à la décision d'achat.
Application web autonome, noir et blanc, utilisable sur téléphone devant le véhicule.

Deux accès au démarrage :

- **Professionnel** — cote de revente, frais de remise en état, marge nette, TVA,
  coût de portage, prix de négociation, comparateur, tableau de bord.
- **Particulier** — valeur du véhicule, cohérence du prix affiché, délai de vente,
  comparatif « vendre soi-même / faire reprendre », demande d'offre de rachat.
  Aucune donnée marchande n'y apparaît.

---

## Mise en ligne

### GitHub Pages

1. Poussez ce dépôt sur GitHub.
2. *Settings → Pages → Source : Deploy from a branch → `main` / `root`*.
3. Le site est en ligne sur `https://<utilisateur>.github.io/<dépôt>/`.

Tous les chemins sont relatifs : l'application fonctionne aussi bien à la racine
d'un domaine que dans un sous-dossier.

### Hébergement classique

Déposez à la racine du domaine, en gardant les noms exacts :

```
index.html
manifest.webmanifest
sw.js
icon-192.png
icon-512.png
icon-maskable.png
apple-touch-icon.png
```

Le HTTPS est indispensable : sans lui, l'installation sur l'écran d'accueil et
le fonctionnement hors réseau ne marchent pas.

---

## Structure

```
index.html                site complet — HTML, CSS et JS dans un seul fichier
demo.html                 même chose, pré-remplie pour démonstration
manifest.webmanifest      installation sur l'écran d'accueil
sw.js                     service worker, fonctionnement hors réseau
icon-*.png                icônes de l'application
api/cote.php              adaptateur de cotation (hébergement classique)
api/cote.js               adaptateur de cotation (Vercel, Netlify)
docs/BRANCHEMENT.md       comment obtenir de vrais chiffres de marché
docs/LISEZ-MOI.txt        notice d'installation
```

Aucune dépendance, aucun processus de build, aucune base de données.
Les seules ressources externes sont deux polices Google Fonts ; le logo est
intégré au fichier en base64.

---

## D'où viennent les chiffres

Un bandeau en haut de chaque résultat indique toujours la provenance :

| Bandeau | Source | Confiance |
|---|---|---|
| Ocre | Modèle de décote interne — **valeurs simulées** | plafonnée à 70 % |
| Gris | Relevé de marché saisi à la main | jusqu'à 88 % |
| Vert | Service de cotation connecté | jusqu'à 97 % |

**Relevé de marché** — gratuit et immédiat. On recopie six à dix annonces vues
sur les plateformes, un prix par ligne, avec le kilométrage et l'année si
possible. Chaque annonce est ramenée aux caractéristiques du véhicule étudié
avant le calcul de la médiane.

**Service connecté** — voir `docs/BRANCHEMENT.md`. L'adaptateur fourni garde la
clé du fournisseur côté serveur. Contrat de réponse attendu :

```json
{
  "valeurExcellent": 11200,
  "valeurMoyen": 10100,
  "valeurMediane": 10750,
  "nbTotal": 63,
  "source": "nom du fournisseur",
  "annonces": [
    { "titre": "", "prix": 0, "km": 0, "annee": 0,
      "departement": "", "source": "", "lien": "" }
  ]
}
```

Tout le reste — marge, TVA, portage, score, comparateur, PDF — se recalcule
automatiquement sur ces valeurs.

---

## Données et vie privée

Tout est stocké dans le navigateur de l'utilisateur (`localStorage`).
Aucun serveur, aucun compte, aucune donnée transmise à un tiers, hormis l'appel
au service de cotation lorsqu'il est configuré. Les historiques professionnel et
particulier sont séparés.

---

## À faire avant une ouverture au public

- [ ] Mentions légales — obligatoires pour un site commercial français
- [ ] Politique de confidentialité et case de consentement sur le formulaire de
      reprise, qui collecte nom, téléphone et e-mail
- [ ] Renseigner l'e-mail et le WhatsApp dans *Réglages → Reprise*
- [ ] Protéger l'accès professionnel : aujourd'hui un simple bouton sépare les
      deux espaces, n'importe qui peut voir les marges et les seuils
- [ ] Point d'envoi fiable pour les demandes de reprise : le bouton e-mail ouvre
      la messagerie du visiteur, sans garantie de réception

---

© Drivly. Tous droits réservés.
