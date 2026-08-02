<?php
/* =============================================================================
   Drivly — adaptateur de cotation
   Reçoit la fiche véhicule depuis le navigateur, interroge votre fournisseur
   de cotation, et renvoie la réponse au format attendu par l'application.

   La clé du fournisseur reste ICI, sur le serveur. Elle ne part jamais
   dans le navigateur.

   Installation : déposer ce fichier dans /api/ à côté de index.html,
   puis renseigner l'adresse https://votre-domaine.fr/api/cote.php
   dans Réglages > Source des données.
   ============================================================================= */

/* ------------------------- 1. CONFIGURATION ------------------------- */

$FOURNISSEUR   = 'demo';                       // 'demo' | 'generique'
$URL_FOURNISSEUR = 'https://api.exemple.fr/v1/valuation';
$CLE_FOURNISSEUR = getenv('COTE_API_KEY') ?: '';   // ou collez la clé entre les quotes
$ORIGINES_AUTORISEES = ['https://votre-domaine.fr']; // adresses de votre site

/* ------------------------- 2. GARDE-FOUS ------------------------- */

$origine = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origine, $ORIGINES_AUTORISEES, true)) {
    header('Access-Control-Allow-Origin: ' . $origine);
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['erreur' => 'Méthode non autorisée']); exit;
}

$v = json_decode(file_get_contents('php://input'), true);
if (!is_array($v) || empty($v['marque'])) {
    http_response_code(400);
    echo json_encode(['erreur' => 'Fiche véhicule invalide']); exit;
}

/* Limitation simple : 60 appels par heure et par adresse IP */
$compteur = sys_get_temp_dir() . '/drivly_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x') . '.txt';
$appels = file_exists($compteur) ? json_decode(file_get_contents($compteur), true) : [];
$appels = array_values(array_filter((array)$appels, fn($t) => $t > time() - 3600));
if (count($appels) >= 60) {
    http_response_code(429);
    echo json_encode(['erreur' => 'Trop de requêtes, réessayez plus tard']); exit;
}
$appels[] = time();
file_put_contents($compteur, json_encode($appels));

/* ------------------------- 3. APPEL DU FOURNISSEUR ------------------------- */

function appeler(string $url, array $corps, string $cle): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($corps),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cle,
        ],
    ]);
    $rep  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($rep === false || $code >= 400) return null;
    $d = json_decode($rep, true);
    return is_array($d) ? $d : null;
}

/* ------------------------- 4. ADAPTATION ------------------------- */
/* C'est la SEULE partie à réécrire selon le fournisseur retenu :
   il s'agit de faire correspondre ses noms de champs aux nôtres.      */

if ($FOURNISSEUR === 'demo') {
    /* Mode démonstration : renvoie une erreur explicite plutôt que
       de faire croire à des chiffres réels. */
    http_response_code(501);
    echo json_encode([
        'erreur' => "Aucun fournisseur configuré. Renseignez \$FOURNISSEUR, \$URL_FOURNISSEUR et \$CLE_FOURNISSEUR dans api/cote.php."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$brut = appeler($URL_FOURNISSEUR, [
    'make'         => $v['marque']       ?? '',
    'model'        => $v['modele']       ?? '',
    'version'      => $v['version']      ?? '',
    'year'         => $v['annee']        ?? null,
    'first_registration' => $v['mise_en_circulation'] ?? '',
    'mileage'      => $v['kilometrage']  ?? 0,
    'fuel'         => $v['carburant']    ?? '',
    'gearbox'      => $v['boite']        ?? '',
    'power_hp'     => $v['puissance']    ?? 0,
    'doors'        => $v['portes']       ?? 5,
    'country'      => 'FR',
], $CLE_FOURNISSEUR);

if ($brut === null) {
    http_response_code(502);
    echo json_encode(['erreur' => 'Le fournisseur de cotation ne répond pas'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Correspondance des champs — à ajuster d'après la documentation du fournisseur */
$annonces = [];
foreach (($brut['listings'] ?? $brut['annonces'] ?? []) as $a) {
    $annonces[] = [
        'titre'       => $a['title']    ?? trim(($v['marque'] ?? '') . ' ' . ($v['modele'] ?? '')),
        'prix'        => (int)($a['price']      ?? $a['prix'] ?? 0),
        'km'          => (int)($a['mileage']    ?? $a['km'] ?? 0),
        'annee'       => (int)($a['year']       ?? $a['annee'] ?? 0),
        'departement' => $a['department'] ?? $a['departement'] ?? '—',
        'source'      => $a['source']     ?? 'annonce',
        'lien'        => $a['url']        ?? $a['lien'] ?? '#',
    ];
}

echo json_encode([
    'valeurExcellent' => (int)($brut['retail_price']    ?? $brut['valeurExcellent'] ?? 0),
    'valeurMoyen'     => (int)($brut['average_price']   ?? $brut['valeurMoyen']     ?? 0),
    'valeurMediane'   => (int)($brut['median_price']    ?? $brut['valeurMediane']   ?? 0),
    'nbTotal'         => (int)($brut['listings_count']  ?? count($annonces)),
    'source'          => $brut['provider'] ?? 'fournisseur',
    'annonces'        => array_slice($annonces, 0, 10),
], JSON_UNESCAPED_UNICODE);
