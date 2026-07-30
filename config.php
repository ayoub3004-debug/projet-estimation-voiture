<?php
// ============================================================
// Cote Réelle — Configuration base de données
// À adapter à ton hébergement (OVH, o2switch, etc.)
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'cotereelle');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Origine autorisée pour les requêtes cross-origin (mets l'adresse
// exacte de ton site si le front-end n'est pas sur le même domaine
// que ce backend). '*' fonctionne uniquement sans cookies/session.
define('CORS_ORIGIN', '*');

function get_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Connexion base de données impossible. Vérifie config.php.']);
            exit;
        }
    }
    return $pdo;
}
