/* =============================================================================
   Drivly — adaptateur de cotation (Vercel, runtime Node.js)

   À déposer dans api/cote.js : Vercel n'expose que le dossier api/.
   Si le déploiement signale une erreur sur « import / export », ajouter à la
   racine un package.json contenant { "type": "module" }.

   Variables d'environnement (à saisir chez l'hébergeur, jamais dans le code) :
     COTE_URL         adresse du fournisseur, en https
     COTE_API_KEY     clé du fournisseur
     COTE_ORIGINES    origines autorisées, séparées par des virgules
                      ex. https://projet-estimation-voiture.vercel.app
     COTE_TOKEN_PRO   facultatif : jeton à saisir dans Réglages > Clé d'accès,
                      qui dispense le professionnel de la limite de débit

   Depuis le site, appeler l'adresse relative /api/cote (même origine).

   Limites connues : la limite de débit et le cache vivent en mémoire d'une
   instance, ce sont des garde-fous, pas une garantie. Pour un vrai plafond de
   dépenses, utiliser un stockage partagé (Upstash, Vercel KV) et fixer un
   plafond de facturation chez le fournisseur.
   ============================================================================= */

import { timingSafeEqual } from "node:crypto";

const URL_FOURNISSEUR = process.env.COTE_URL || "";
const CLE             = process.env.COTE_API_KEY || "";
const TOKEN_PRO       = process.env.COTE_TOKEN_PRO || "";
const ORIGINES        = (process.env.COTE_ORIGINES || "").split(",").map(s => s.trim()).filter(Boolean);

const LIMITE   = { fenetre: 60_000, max: 8 };   // 8 appels par minute et par IP
const CACHE_MS = 6 * 3600 * 1000;               // 6 h

const compteurs = new Map();   // ip -> horodatages récents
const cache     = new Map();   // fiche normalisée -> { t, reponse }

/* ---------- utilitaires ---------- */
const texte  = (x, max) => typeof x === "string" ? x.trim().slice(0, max) : "";
const entier = (x, min, max) => { const n = Number(x); return Number.isFinite(n) && n >= min && n <= max ? Math.round(n) : 0; };
const montant = x => { const n = Number(x); return Number.isFinite(n) && n > 0 ? Math.round(n) : 0; };

function lienSur(u){
  try{ const p = new URL(String(u)); return /^https?:$/.test(p.protocol) ? p.href : "#"; }
  catch{ return "#"; }
}
function egal(a, b){
  const x = Buffer.from(a), y = Buffer.from(b);
  return x.length === y.length && timingSafeEqual(x, y);
}
function ipDe(req){
  return String(req.headers["x-forwarded-for"] || "").split(",")[0].trim()
      || req.socket?.remoteAddress || "?";
}
function depasseLimite(ip){
  const now = Date.now();
  const recents = (compteurs.get(ip) || []).filter(t => now - t < LIMITE.fenetre);
  recents.push(now);
  compteurs.set(ip, recents);
  if(compteurs.size > 5000) compteurs.clear();
  return recents.length > LIMITE.max;
}

/* La fiche est transmise à un service payant : on ne garde que les champs
   attendus, avec des bornes, et on rejette le reste. */
function nettoyer(b){
  const v = {
    marque:    texte(b.marque, 40),
    modele:    texte(b.modele, 60),
    version:   texte(b.version, 80),
    annee:     entier(b.annee, 1980, new Date().getFullYear() + 1),
    mec:       /^\d{4}-\d{2}$/.test(b.mise_en_circulation || "") ? b.mise_en_circulation : "",
    km:        entier(b.kilometrage, 1, 999_999),
    carburant: ["diesel", "essence", "hybride", "electrique", "gpl"].includes(b.carburant) ? b.carburant : "",
    boite:     ["manuelle", "auto"].includes(b.boite) ? b.boite : "",
    puissance: entier(b.puissance, 0, 1500),
    portes:    entier(b.portes, 2, 5)
  };
  return (v.marque && v.modele && v.annee && v.km) ? v : null;
}

/* ---------- point d'entrée ---------- */
export default async function handler(req, res){
  const origine   = req.headers.origin || "";
  const autorisee = ORIGINES.includes(origine);

  res.setHeader("Vary", "Origin");
  res.setHeader("Cache-Control", "no-store");
  if(autorisee){
    res.setHeader("Access-Control-Allow-Origin", origine);
    res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization");
    res.setHeader("Access-Control-Allow-Methods", "POST, OPTIONS");
  }

  if(req.method === "OPTIONS") return res.status(autorisee ? 204 : 403).end();
  if(req.method !== "POST")    return res.status(405).json({ erreur: "Méthode non autorisée" });

  /* rien ne part chez le fournisseur tant que la configuration est incomplète */
  if(!URL_FOURNISSEUR.startsWith("https://") || !CLE || !ORIGINES.length){
    return res.status(501).json({ erreur: "Service non configuré (COTE_URL, COTE_API_KEY, COTE_ORIGINES)." });
  }
  /* refus côté serveur : l'absence d'en-tête CORS n'arrête pas un appel curl */
  if(!autorisee) return res.status(403).json({ erreur: "Origine non autorisée" });

  const jeton = String(req.headers.authorization || "");
  const pro   = TOKEN_PRO !== "" && egal(jeton, "Bearer " + TOKEN_PRO);
  if(!pro && depasseLimite(ipDe(req))){
    res.setHeader("Retry-After", "60");
    return res.status(429).json({ erreur: "Trop de demandes, réessayez dans une minute." });
  }

  let corps = req.body;
  if(typeof corps === "string"){ try{ corps = JSON.parse(corps); }catch{ corps = null; } }
  const v = (corps && typeof corps === "object") ? nettoyer(corps) : null;
  if(!v) return res.status(400).json({ erreur: "Fiche véhicule invalide" });

  /* le kilométrage est arrondi au millier : mêmes chiffres, mêmes clés de cache */
  const kmArrondi = Math.round(v.km / 1000) * 1000 || 1000;
  const cle = [v.marque, v.modele, v.version, v.annee, v.carburant, v.boite, v.puissance, kmArrondi]
    .join("|").toLowerCase();
  const memo = cache.get(cle);
  if(memo && Date.now() - memo.t < CACHE_MS) return res.status(200).json(memo.reponse);

  const ctrl = new AbortController();
  const minuteur = setTimeout(() => ctrl.abort(), 10_000);
  try{
    const rep = await fetch(URL_FOURNISSEUR, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Authorization": "Bearer " + CLE },
      signal: ctrl.signal,
      /* ---- correspondance des champs : à ajuster selon le fournisseur ---- */
      body: JSON.stringify({
        make: v.marque, model: v.modele, version: v.version,
        year: v.annee, first_registration: v.mec,
        mileage: kmArrondi, fuel: v.carburant, gearbox: v.boite,
        power_hp: v.puissance, doors: v.portes, country: "FR"
      })
    });
    if(!rep.ok){
      console.error("cote: fournisseur a répondu", rep.status);
      return res.status(502).json({ erreur: "Fournisseur indisponible" });
    }
    const d = await rep.json();

    /* ---- correspondance de la réponse : à ajuster selon le fournisseur ---- */
    const brutes = Array.isArray(d.listings) ? d.listings : Array.isArray(d.annonces) ? d.annonces : [];
    const annonces = brutes.slice(0, 10).map(a => ({
      titre:       texte(a.title ?? a.titre, 120) || [v.marque, v.modele].join(" "),
      prix:        montant(a.price ?? a.prix),
      km:          montant(a.mileage ?? a.km),
      annee:       montant(a.year ?? a.annee),
      departement: texte(a.department ?? a.departement, 40) || "—",
      source:      texte(a.source, 40) || "annonce",
      lien:        lienSur(a.url ?? a.lien)
    })).filter(a => a.prix > 0);

    const reponse = {
      valeurExcellent: montant(d.retail_price  ?? d.valeurExcellent),
      valeurMoyen:     montant(d.average_price ?? d.valeurMoyen),
      valeurMediane:   montant(d.median_price  ?? d.valeurMediane),
      nbTotal:         montant(d.listings_count) || annonces.length,
      source:          texte(d.provider, 40) || "fournisseur",
      annonces
    };
    if(!reponse.valeurExcellent && !reponse.valeurMediane){
      return res.status(502).json({ erreur: "Réponse du fournisseur inexploitable" });
    }

    if(cache.size > 500) cache.clear();
    cache.set(cle, { t: Date.now(), reponse });
    return res.status(200).json(reponse);
  }catch(e){
    console.error("cote:", e);
    return res.status(502).json({ erreur: "Fournisseur injoignable" });
  }finally{
    clearTimeout(minuteur);
  }
}
