<?php
declare(strict_types=1);
/* =============================================================================
   Drivly — adaptateur de cotation (hébergement PHP classique)

   Installation :
   1. Déposez ce fichier dans api/, à côté de cote.config.example.php.
   2. Copiez cote.config.example.php en cote.config.php, remplissez-le,
      et NE LE COMMITEZ JAMAIS sur GitHub (voir ce fichier pour le détail).
   3. Renseignez l'adresse relative "/api/cote.php" dans Réglages > Source
      des données > Adresse du service de cotation.

   La clé du fournisseur reste sur le serveur : elle ne part jamais dans le
   navigateur. Tant que cote.config.php n'existe pas ou n'est pas rempli,
   cet adaptateur refuse toute requête (erreur 501) plutôt que d'appeler le
   fournisseur sans discernement ou de renvoyer des chiffres inventés.

   PHP 8.0 minimum (utilise str_starts_with). Nécessite l'extension curl.
   ============================================================================= */

/* ------------------------- 1. CONFIGURATION ------------------------- */

$CONFIG_DEFAUT = [
    'fournisseur' => 'demo',
    'url'         => '',
    'cle'         => '',
    'origines'    => [],
    'jeton_pro'   => '',
];

$fichierConfig = __DIR__ . '/cote.config.php';
$config = is_file($fichierConfig) ? include $fichierConfig : null;
if (!is_array($config)) { $config = []; }
$config = array_merge($CONFIG_DEFAUT, $config);

/* Une vraie variable d'environnement, si l'hébergeur en propose, a priorité
   sur le fichier de configuration (pratique pour un passage à un hébergeur
   qui gère les env vars sans retoucher le fichier). */
$FOURNISSEUR      = getenv('COTE_FOURNISSEUR') ?: $config['fournisseur'];
$URL_FOURNISSEUR  = getenv('COTE_URL') ?: $config['url'];
$CLE_FOURNISSEUR  = getenv('COTE_API_KEY') ?: $config['cle'];
$JETON_PRO        = getenv('COTE_TOKEN_PRO') ?: $config['jeton_pro'];
$origines_env     = getenv('COTE_ORIGINES');
$ORIGINES_AUTORISEES = $origines_env !== false
    ? array_values(array_filter(array_map('trim', explode(',', $origines_env))))
    : (array)$config['origines'];

const LIMITE_FENETRE = 3600;   // 1 heure
const LIMITE_MAX     = 60;     // appels par heure et par IP (hors jeton pro)
const CACHE_SECONDES = 6 * 3600;

/* ------------------------- 2. RÉPERTOIRE DE TRAVAIL ------------------------- */
/* Fichiers de verrou/limite/cache : dans un sous-dossier dédié plutôt que le
   répertoire temporaire système (souvent partagé entre tous les sites d'un
   hébergement mutualisé), avec un .htaccess qui en bloque l'accès direct.
   Si votre hébergeur tourne sous Nginx plutôt qu'Apache, le .htaccess n'a
   aucun effet : vérifiez après déploiement qu'une adresse comme
   /api/cache/ renvoie bien une erreur et pas une liste de fichiers. */
const DOSSIER_CACHE = __DIR__ . '/cache';

function preparerDossierCache(): bool {
    if (!is_dir(DOSSIER_CACHE)) {
        if (!@mkdir(DOSSIER_CACHE, 0775, true) && !is_dir(DOSSIER_CACHE)) return false;
    }
    $htaccess = DOSSIER_CACHE . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    return true;
}

/* ------------------------- 3. UTILITAIRES ------------------------- */

function repondre(int $code, array $corps): never {
    http_response_code($code);
    echo json_encode($corps, JSON_UNESCAPED_UNICODE);
    exit;
}

function texte($x, int $max): string {
    if (!is_string($x)) return '';
    /* substr() plutôt que mb_substr() : l'extension mbstring n'est pas
       garantie sur tous les hébergements, et ces champs ne servent qu'à de
       l'affichage borné en longueur, pas à un traitement texte fin. */
    return substr(trim($x), 0, $max);
}
function entier($x, int $min, int $max): int {
    if (!is_numeric($x)) return 0;
    $n = (int) round((float) $x);
    return ($n >= $min && $n <= $max) ? $n : 0;
}
function montant($x): int {
    if (!is_numeric($x)) return 0;
    $n = (int) round((float) $x);
    return $n > 0 ? $n : 0;
}
/* n'accepte qu'un lien http(s) absolu : un « javascript: » envoyé par une
   source externe ne doit jamais devenir cliquable côté navigateur. */
function lienSur($u): string {
    if (!is_string($u) || $u === '') return '#';
    $p = parse_url($u);
    if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
        return '#';
    }
    return $u;
}

/* Normalise et valide la fiche reçue du navigateur : seuls ces champs,
   dans ces bornes, sont transmis à l'adaptateur puis au fournisseur. */
function nettoyerFiche(array $b): ?array {
    $carburants = ['diesel', 'essence', 'hybride', 'electrique', 'gpl'];
    $boites     = ['manuelle', 'auto'];
    $v = [
        'marque'    => texte($b['marque']    ?? '', 40),
        'modele'    => texte($b['modele']    ?? '', 60),
        'version'   => texte($b['version']   ?? '', 80),
        'annee'     => entier($b['annee']    ?? null, 1980, (int) date('Y') + 1),
        'mec'       => (is_string($b['mise_en_circulation'] ?? null)
                        && preg_match('/^\d{4}-\d{2}$/', $b['mise_en_circulation'])) ? $b['mise_en_circulation'] : '',
        'km'        => entier($b['kilometrage'] ?? null, 1, 999999),
        'carburant' => in_array($b['carburant'] ?? '', $carburants, true) ? $b['carburant'] : '',
        'boite'     => in_array($b['boite'] ?? '', $boites, true) ? $b['boite'] : '',
        'puissance' => entier($b['puissance'] ?? null, 0, 1500),
        'portes'    => entier($b['portes']   ?? null, 2, 5) ?: 5,
    ];
    return ($v['marque'] !== '' && $v['modele'] !== '' && $v['annee'] > 0 && $v['km'] > 0) ? $v : null;
}

/* Verrou de fichier simple : lit, modifie, réécrit sous flock() pour éviter
   qu'un pic de requêtes simultanées de la même IP ne contourne la limite. */
function sousVerrou(string $fichier, callable $f) {
    $poignee = @fopen($fichier, 'c+');
    if (!$poignee) return $f([]);
    flock($poignee, LOCK_EX);
    $taille = filesize($fichier) ?: 0;
    $brut = $taille > 0 ? fread($poignee, $taille) : '';
    $donnees = json_decode($brut ?: '[]', true);
    if (!is_array($donnees)) $donnees = [];
    $resultat = $f($donnees);
    ftruncate($poignee, 0);
    rewind($poignee);
    fwrite($poignee, json_encode($resultat['donnees'] ?? $donnees));
    fflush($poignee);
    flock($poignee, LOCK_UN);
    fclose($poignee);
    return $resultat['retour'] ?? null;
}

function depasseLimite(string $ip): bool {
    preparerDossierCache();
    $fichier = DOSSIER_CACHE . '/limite_' . md5($ip) . '.json';
    return (bool) sousVerrou($fichier, function (array $appels) {
        $maintenant = time();
        $appels = array_values(array_filter($appels, fn($t) => $t > $maintenant - LIMITE_FENETRE));
        $plein = count($appels) >= LIMITE_MAX;
        if (!$plein) $appels[] = $maintenant;
        return ['donnees' => $appels, 'retour' => $plein];
    });
}

function lireCache(string $cle): ?array {
    $fichier = DOSSIER_CACHE . '/reponse_' . md5($cle) . '.json';
    if (!is_file($fichier)) return null;
    if (time() - filemtime($fichier) > CACHE_SECONDES) return null;
    $d = json_decode((string) @file_get_contents($fichier), true);
    return is_array($d) ? $d : null;
}
function ecrireCache(string $cle, array $reponse): void {
    preparerDossierCache();
    $fichier = DOSSIER_CACHE . '/reponse_' . md5($cle) . '.json';
    @file_put_contents($fichier, json_encode($reponse));
}

/* ------------------------- 4. GARDE-FOUS ------------------------- */

header('Vary: Origin');
header('Cache-Control: no-store');

$origine = $_SERVER['HTTP_ORIGIN'] ?? '';
$autorisee = $origine !== '' && in_array($origine, $ORIGINES_AUTORISEES, true);
if ($autorisee) {
    header('Access-Control-Allow-Origin: ' . $origine);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
}

$methode = $_SERVER['REQUEST_METHOD'] ?? '';
if ($methode === 'OPTIONS') {
    http_response_code($autorisee ? 204 : 403);
    exit;
}
if ($methode !== 'POST') repondre(405, ['erreur' => 'Méthode non autorisée']);

/* rien ne part chez le fournisseur tant que la configuration est incomplète */
if ($FOURNISSEUR === 'demo' || !str_starts_with($URL_FOURNISSEUR, 'https://') || $CLE_FOURNISSEUR === '' || !$ORIGINES_AUTORISEES) {
    repondre(501, ['erreur' => "Service non configuré : copiez api/cote.config.example.php en api/cote.config.php et remplissez-le (voir le fichier pour les instructions)."]);
}
/* refus explicite côté serveur : l'absence d'en-tête CORS n'arrête pas un appel curl */
if (!$autorisee) repondre(403, ['erreur' => 'Origine non autorisée']);

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$jetonRecu = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$pro = $JETON_PRO !== '' && hash_equals('Bearer ' . $JETON_PRO, $jetonRecu);
if (!$pro && depasseLimite($ip)) {
    header('Retry-After: 60');
    repondre(429, ['erreur' => 'Trop de requêtes, réessayez dans une minute.']);
}

$brutEntree = json_decode((string) file_get_contents('php://input'), true);
$v = is_array($brutEntree) ? nettoyerFiche($brutEntree) : null;
if ($v === null) repondre(400, ['erreur' => 'Fiche véhicule invalide']);

/* kilométrage arrondi au millier : mêmes chiffres, mêmes clés de cache,
   et un fournisseur facturé à la requête épargné sur les doublons proches */
$kmArrondi = (int) (round($v['km'] / 1000) * 1000) ?: 1000;
$cle = strtolower(implode('|', [$v['marque'], $v['modele'], $v['version'], $v['annee'], $v['carburant'], $v['boite'], $v['puissance'], $kmArrondi]));
if ($reponseCache = lireCache($cle)) {
    repondre(200, $reponseCache);
}

/* ------------------------- 5. APPEL DU FOURNISSEUR ------------------------- */

function appeler(string $url, array $corps, string $cle): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($corps),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,   /* la clé ne part jamais en clair */
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cle,
        ],
    ]);
    $rep  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($rep === false || $code >= 400) {
        error_log('cote.php: appel fournisseur échoué — ' . ($err ?: ('HTTP ' . $code)));
        return null;
    }
    $d = json_decode($rep, true);
    return is_array($d) ? $d : null;
}

/* ------------------------- 6. ADAPTATION ------------------------- */
/* C'est la SEULE partie à réécrire selon le fournisseur retenu :
   il s'agit de faire correspondre ses noms de champs aux nôtres.      */

$brut = appeler($URL_FOURNISSEUR, [
    'make'               => $v['marque'],
    'model'              => $v['modele'],
    'version'            => $v['version'],
    'year'               => $v['annee'],
    'first_registration' => $v['mec'],
    'mileage'            => $kmArrondi,
    'fuel'               => $v['carburant'],
    'gearbox'            => $v['boite'],
    'power_hp'           => $v['puissance'],
    'doors'              => $v['portes'],
    'country'            => 'FR',
], $CLE_FOURNISSEUR);

if ($brut === null) repondre(502, ['erreur' => 'Fournisseur indisponible']);

/* Correspondance des champs — à ajuster d'après la documentation du fournisseur */
$annonces = [];
foreach (($brut['listings'] ?? $brut['annonces'] ?? []) as $a) {
    if (!is_array($a)) continue;
    $prix = montant($a['price'] ?? $a['prix'] ?? 0);
    if ($prix <= 0) continue;
    $annonces[] = [
        'titre'       => texte($a['title'] ?? '', 120) ?: trim($v['marque'] . ' ' . $v['modele']),
        'prix'        => $prix,
        'km'          => montant($a['mileage'] ?? $a['km'] ?? 0),
        'annee'       => montant($a['year'] ?? $a['annee'] ?? 0),
        'departement' => texte($a['department'] ?? $a['departement'] ?? '', 40) ?: '—',
        'source'      => texte($a['source'] ?? '', 40) ?: 'annonce',
        'lien'        => lienSur($a['url'] ?? $a['lien'] ?? ''),
    ];
    if (count($annonces) >= 10) break;
}

$reponse = [
    'valeurExcellent' => montant($brut['retail_price']   ?? $brut['valeurExcellent'] ?? 0),
    'valeurMoyen'     => montant($brut['average_price']  ?? $brut['valeurMoyen']     ?? 0),
    'valeurMediane'   => montant($brut['median_price']   ?? $brut['valeurMediane']   ?? 0),
    'nbTotal'         => montant($brut['listings_count'] ?? 0) ?: count($annonces),
    'source'          => texte($brut['provider'] ?? '', 40) ?: 'fournisseur',
    'annonces'        => $annonces,
];
if ($reponse['valeurExcellent'] <= 0 && $reponse['valeurMediane'] <= 0) {
    repondre(502, ['erreur' => 'Réponse du fournisseur inexploitable']);
}

ecrireCache($cle, $reponse);
repondre(200, $reponse);
