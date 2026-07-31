// ============================================================
// Cote Réelle — content.js
// Tourne dans le contexte de la page (Leboncoin, La Centrale,
// AutoScout24, ParuVendu...) quand l'utilisateur clique sur
// l'icône de l'extension. Lit le texte visible de la page —
// ne fait AUCUNE requête réseau, ne contourne aucune protection,
// se contente de lire ce que l'utilisateur voit déjà à l'écran.
//
// ⚠️ Les sélecteurs / heuristiques ci-dessous sont écrits sans
// accès en direct aux pages réelles (environnement de build
// sans accès à leboncoin.fr etc.). Il faudra très probablement
// les ajuster une fois testés sur les vraies pages — voir
// README.md de l'extension pour comment les corriger.
// ============================================================

const MARQUES = ['Peugeot','Renault','Citroën','Citroen','Volkswagen','VW','Audi','BMW','Mercedes','Mercedes-Benz','Opel',
  'Ford','Toyota','Nissan','Fiat','Seat','Skoda','Škoda','Dacia','Hyundai','Kia','Mini','Volvo','Mazda','Honda',
  'Suzuki','Alfa Romeo','Jeep','Land Rover','Range Rover','Porsche','Tesla','DS','Smart','Lexus','Subaru','Mitsubishi','Chevrolet','Jaguar'];

function detectMarqueModele(titre) {
  for (const m of MARQUES) {
    const re = new RegExp('\\b' + m.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'i');
    const match = titre.match(re);
    if (match) {
      const after = titre.slice(match.index + match[0].length).trim();
      const modele = after.split(/[-–,(]/)[0].trim().split(/\s+/).slice(0, 2).join(' ');
      return { marque: m, modele: modele || '' };
    }
  }
  return { marque: '', modele: '' };
}

function extractFromPage() {
  // On lit le texte visible du <body> — c'est l'équivalent d'un
  // Ctrl+A / Ctrl+C fait automatiquement, pas un accès à des
  // données cachées ou protégées.
  const text = document.body ? document.body.innerText : '';
  const clean = text.replace(/\u00a0/g, ' ');

  const priceMatches = [...clean.matchAll(/(\d[\d ]{2,7})\s?(?:€|EUR)/gi)]
    .map(m => parseInt(m[1].replace(/\s/g, ''), 10))
    .filter(n => n >= 300 && n <= 300000);
  const prix = priceMatches.length ? priceMatches[0] : null;

  const kmMatch = clean.match(/(\d[\d ]{2,6})\s?km\b/i) || clean.match(/(?:km|kilom[ée]trage)\s*[:\-]?\s*(\d[\d ]{2,6})/i);
  const km = kmMatch ? parseInt(kmMatch[1].replace(/\s/g, ''), 10) : null;

  const yearMatches = [...clean.matchAll(/\b(19[9]\d|20[0-2]\d)\b/g)].map(m => parseInt(m[1], 10));
  const annee = yearMatches.length ? yearMatches[0] : null;

  const carburant = /hybride/i.test(clean) ? 'Hybride' : /électrique/i.test(clean) ? 'Électrique' : /essence/i.test(clean) ? 'Essence' : /diesel/i.test(clean) ? 'Diesel' : null;
  const boite = /automatique/i.test(clean) ? 'Automatique' : /manuelle/i.test(clean) ? 'Manuelle' : null;

  const titre = document.title || (clean.split('\n').map(l => l.trim()).filter(Boolean)[0] || '');
  const { marque, modele } = detectMarqueModele(titre + ' ' + clean.slice(0, 300));

  return {
    prix, km, annee, carburant, boite, marque, modele,
    titre, url: location.href, extraitLe: new Date().toISOString()
  };
}

// Répond à la demande d'extraction envoyée par popup.js
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg && msg.type === 'COTE_REELLE_EXTRACT') {
    try {
      sendResponse({ ok: true, data: extractFromPage() });
    } catch (e) {
      sendResponse({ ok: false, error: String(e) });
    }
  }
  return true; // réponse asynchrone autorisée
});
