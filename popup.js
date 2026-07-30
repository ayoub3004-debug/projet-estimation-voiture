// ============================================================
// Cote Réelle — popup.js
// ============================================================

const btnExtract = document.getElementById('btn-extract');
const btnCopy = document.getElementById('btn-copy');
const statusEl = document.getElementById('status');
const resultsEl = document.getElementById('results');

let lastData = null;

function eur(n) { return (n === null || n === undefined) ? '—' : n.toLocaleString('fr-FR') + ' €'; }
function km(n) { return (n === null || n === undefined) ? '—' : n.toLocaleString('fr-FR') + ' km'; }

btnExtract.addEventListener('click', async () => {
  statusEl.textContent = 'Analyse en cours…';
  resultsEl.style.display = 'none';
  btnExtract.disabled = true;

  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab || !tab.id) throw new Error('Onglet introuvable.');

    // Injecte content.js à la demande (pas d'injection automatique
    // partout — seulement quand l'utilisateur clique explicitement).
    await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ['content.js'] });

    const response = await chrome.tabs.sendMessage(tab.id, { type: 'COTE_REELLE_EXTRACT' });

    if (!response || !response.ok) {
      throw new Error((response && response.error) || 'Extraction impossible sur cette page.');
    }

    lastData = response.data;
    document.getElementById('r-prix').textContent = eur(lastData.prix);
    document.getElementById('r-km').textContent = km(lastData.km);
    document.getElementById('r-annee').textContent = lastData.annee || '—';
    document.getElementById('r-marque').textContent = [lastData.marque, lastData.modele].filter(Boolean).join(' ') || '—';
    document.getElementById('r-meta').textContent = [lastData.carburant, lastData.boite].filter(Boolean).join(' / ') || '—';
    resultsEl.style.display = 'block';

    if (!lastData.prix && !lastData.km && !lastData.annee) {
      statusEl.textContent = "Rien de détecté automatiquement sur cette page — les sélecteurs de l'extension devront probablement être ajustés pour ce site (voir README).";
    } else {
      statusEl.textContent = 'Vérifie les valeurs, puis copie-les vers Cote Réelle.';
    }
  } catch (e) {
    statusEl.textContent = "Erreur : " + e.message + " — assure-toi d'être sur une page d'annonce (Leboncoin, La Centrale, AutoScout24…) et réessaie.";
  } finally {
    btnExtract.disabled = false;
  }
});

btnCopy.addEventListener('click', async () => {
  if (!lastData) return;
  const lines = [
    [lastData.marque, lastData.modele].filter(Boolean).join(' '),
    lastData.prix ? `Prix : ${lastData.prix} €` : '',
    lastData.km ? `Kilométrage : ${lastData.km} km` : '',
    lastData.annee ? `Année : ${lastData.annee}` : '',
    lastData.carburant ? `Carburant : ${lastData.carburant}` : '',
    lastData.boite ? `Boîte : ${lastData.boite}` : '',
    lastData.url ? `Lien : ${lastData.url}` : ''
  ].filter(Boolean).join('\n');

  try {
    await navigator.clipboard.writeText(lines);
    statusEl.textContent = "✅ Copié ! Colle (Ctrl+V) dans le bloc \"⚡ Analyse rapide\" de Cote Réelle.";
  } catch (e) {
    statusEl.textContent = "Impossible de copier automatiquement — sélectionne et copie le texte ci-dessus manuellement.";
  }
});
