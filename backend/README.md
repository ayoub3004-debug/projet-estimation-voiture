# Cote Réelle — Backend (comptes + historique persistant)

Optionnel : le site (`estimation-vehicule.html`) fonctionne très bien tout
seul avec l'historique en localStorage. Ce backend n'est utile que si tu
veux retrouver ton historique **sur plusieurs appareils**, avec un compte.

## Installation

1. Crée une base de données MySQL :
   ```sql
   CREATE DATABASE cotereelle CHARACTER SET utf8mb4;
   ```
2. Importe le schéma :
   ```
   mysql -u root -p cotereelle < schema.sql
   ```
3. Ouvre `config.php` et renseigne tes identifiants MySQL (`DB_HOST`,
   `DB_NAME`, `DB_USER`, `DB_PASS`).
4. Dépose ce dossier `backend/` **au même endroit** que
   `estimation-vehicule.html` sur ton hébergement (OVH, o2switch,
   Infomaniak, etc. — tout hébergeur PHP+MySQL standard convient),
   par exemple :
   ```
   ton-site/
     estimation-vehicule.html   → renomme-le en index.html si tu veux
     backend/
       api.php
       config.php
   ```
5. Ouvre le site depuis ton hébergement (pas en local en `file://` —
   il faut un vrai serveur PHP pour que `backend/api.php` réponde).
   Le bouton **"👤 Compte local"** en haut à droite devient
   automatiquement un vrai système de compte dès que le backend
   répond.

## Comportement si le backend n'est pas présent

Le site essaie d'appeler `backend/api.php`. S'il n'existe pas ou ne
répond pas (par exemple si tu ouvres juste le fichier HTML en
double-clic sur ton ordinateur), l'appel échoue silencieusement et
tout continue de fonctionner avec localStorage, comme avant. Aucune
configuration n'est nécessaire pour l'usage basique.

## Sécurité — à savoir avant de mettre en ligne

- Les mots de passe sont hashés avec `password_hash()` (bcrypt) —
  jamais stockés en clair.
- Les requêtes SQL utilisent des requêtes préparées (PDO) —
  protégées contre les injections SQL.
- Les sessions PHP servent à l'authentification (cookie de session).
  Le site et le backend doivent être sur le **même domaine** pour que
  les cookies fonctionnent simplement ; sinon il faut ajuster
  `CORS_ORIGIN` dans `config.php` et gérer les cookies cross-site
  (plus complexe, demande HTTPS).
- Mets ce site derrière **HTTPS** en production (obligatoire pour que
  les cookies de session soient fiables et que les mots de passe ne
  circulent pas en clair).
- `config.php` contient des identifiants de base de données : ne le
  rends jamais accessible publiquement en clair (normalement ton
  hébergeur ne sert que le résultat du PHP, pas le fichier source —
  vérifie quand même).

## Évolutions possibles

- Réinitialisation de mot de passe par e-mail
- Export CSV de l'historique complet depuis le backend
- Limite de requêtes (rate limiting) sur `register`/`login` pour
  éviter le bruteforce
