/* =============================================================================
   Drivly — adaptateur de cotation (Vercel / Netlify / Cloudflare Workers)

   Déposer dans /api/cote.js. La clé du fournisseur se met dans les variables
   d'environnement de l'hébergeur (COTE_API_KEY), jamais dans le code.

   Adresse à renseigner dans Réglages : https://votre-domaine.fr/api/cote
   ============================================================================= */

const FOURNISSEUR       = process.env.COTE_FOURNISSEUR || "demo";
const URL_FOURNISSEUR   = process.env.COTE_URL || "https://api.exemple.fr/v1/valuation";
const CLE               = process.env.COTE_API_KEY || "";
const ORIGINES          = (process.env.COTE_ORIGINES || "").split(",").filter(Boolean);

function entetes(origine){
  const h = {
    "Content-Type": "application/json; charset=utf-8",
    "Access-Control-Allow-Headers": "Content-Type, Authorization",
    "Access-Control-Allow-Methods": "POST, OPTIONS"
  };
  if(!ORIGINES.length || ORIGINES.includes(origine)) h["Access-Control-Allow-Origin"] = origine || "*";
  return h;
}

export default async function handler(req, res){
  const origine = req.headers.origin || "";
  const h = entetes(origine);
  Object.entries(h).forEach(([k,v]) => res.setHeader(k, v));

  if(req.method === "OPTIONS") return res.status(204).end();
  if(req.method !== "POST")    return res.status(405).json({erreur:"Méthode non autorisée"});

  const v = typeof req.body === "string" ? JSON.parse(req.body || "{}") : (req.body || {});
  if(!v.marque) return res.status(400).json({erreur:"Fiche véhicule invalide"});

  if(FOURNISSEUR === "demo"){
    return res.status(501).json({
      erreur: "Aucun fournisseur configuré. Renseignez COTE_FOURNISSEUR, COTE_URL et COTE_API_KEY."
    });
  }

  try{
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 10000);
    const rep = await fetch(URL_FOURNISSEUR, {
      method:"POST",
      headers:{"Content-Type":"application/json", "Authorization":"Bearer " + CLE},
      signal: ctrl.signal,
      /* ---- correspondance des champs : à ajuster selon le fournisseur ---- */
      body: JSON.stringify({
        make: v.marque, model: v.modele, version: v.version,
        year: v.annee, first_registration: v.mise_en_circulation,
        mileage: v.kilometrage, fuel: v.carburant, gearbox: v.boite,
        power_hp: v.puissance, doors: v.portes, country: "FR"
      })
    });
    clearTimeout(t);
    if(!rep.ok) return res.status(502).json({erreur:"Fournisseur indisponible (" + rep.status + ")"});
    const d = await rep.json();

    /* ---- correspondance de la réponse : à ajuster selon le fournisseur ---- */
    const annonces = (d.listings || d.annonces || []).slice(0, 10).map(a => ({
      titre: a.title || [v.marque, v.modele].filter(Boolean).join(" "),
      prix: Number(a.price ?? a.prix ?? 0),
      km: Number(a.mileage ?? a.km ?? 0),
      annee: Number(a.year ?? a.annee ?? 0),
      departement: a.department || a.departement || "—",
      source: a.source || "annonce",
      lien: a.url || a.lien || "#"
    }));

    return res.status(200).json({
      valeurExcellent: Number(d.retail_price  ?? d.valeurExcellent ?? 0),
      valeurMoyen:     Number(d.average_price ?? d.valeurMoyen     ?? 0),
      valeurMediane:   Number(d.median_price  ?? d.valeurMediane   ?? 0),
      nbTotal:         Number(d.listings_count ?? annonces.length),
      source:          d.provider || "fournisseur",
      annonces
    });
  }catch(e){
    return res.status(502).json({erreur:"Appel impossible : " + (e.message || "inconnu")});
  }
}
