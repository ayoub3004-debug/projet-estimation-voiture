<?php
// ============================================================
// Cote Réelle — API (comptes + historique persistant)
//
// Endpoints (JSON en entrée/sortie) :
//   POST ?action=register   { email, password }
//   POST ?action=login      { email, password }
//   POST ?action=logout
//   GET  ?action=me
//   GET  ?action=list
//   POST ?action=save       { vehicule, prix_achat, estimation, marge_nette, verdict, payload }
//   POST ?action=delete     { id }
//
// Toutes les routes sauf register/login/me exigent une session
// active (cookie de session PHP, envoyer credentials:'include'
// côté front-end).
// ============================================================

require_once __DIR__ . '/config.php';

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function require_auth() {
    if (empty($_SESSION['user_id'])) {
        respond(['error' => 'Non authentifié.'], 401);
    }
    return (int) $_SESSION['user_id'];
}

$action = $_GET['action'] ?? '';
$pdo = get_pdo();

switch ($action) {

    case 'register': {
        $in = json_input();
        $email = trim(strtolower($in['email'] ?? ''));
        $password = (string) ($in['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Adresse e-mail invalide.'], 422);
        }
        if (strlen($password) < 8) {
            respond(['error' => 'Le mot de passe doit faire au moins 8 caractères.'], 422);
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            respond(['error' => 'Un compte existe déjà avec cet e-mail.'], 409);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, $hash]);

        $_SESSION['user_id'] = (int) $pdo->lastInsertId();
        respond(['ok' => true, 'email' => $email]);
        break;
    }

    case 'login': {
        $in = json_input();
        $email = trim(strtolower($in['email'] ?? ''));
        $password = (string) ($in['password'] ?? '');

        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            respond(['error' => 'E-mail ou mot de passe incorrect.'], 401);
        }

        $_SESSION['user_id'] = (int) $user['id'];
        respond(['ok' => true, 'email' => $email]);
        break;
    }

    case 'logout': {
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true]);
        break;
    }

    case 'me': {
        if (empty($_SESSION['user_id'])) {
            respond(['authenticated' => false]);
        }
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        respond(['authenticated' => true, 'email' => $user['email'] ?? null]);
        break;
    }

    case 'list': {
        $userId = require_auth();
        $stmt = $pdo->prepare('SELECT id, vehicule, prix_achat, estimation, marge_nette, verdict, payload_json, created_at
                                FROM estimations WHERE user_id = ? ORDER BY created_at DESC LIMIT 200');
        $stmt->execute([$userId]);
        respond(['ok' => true, 'items' => $stmt->fetchAll()]);
        break;
    }

    case 'save': {
        $userId = require_auth();
        $in = json_input();
        $stmt = $pdo->prepare('INSERT INTO estimations (user_id, vehicule, prix_achat, estimation, marge_nette, verdict, payload_json)
                                VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $userId,
            substr((string) ($in['vehicule'] ?? ''), 0, 190),
            isset($in['prix_achat']) ? (float) $in['prix_achat'] : null,
            isset($in['estimation']) ? (float) $in['estimation'] : null,
            isset($in['marge_nette']) ? (float) $in['marge_nette'] : null,
            substr((string) ($in['verdict'] ?? ''), 0, 60),
            isset($in['payload']) ? json_encode($in['payload']) : null,
        ]);
        respond(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        break;
    }

    case 'delete': {
        $userId = require_auth();
        $in = json_input();
        $id = (int) ($in['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM estimations WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        respond(['ok' => true]);
        break;
    }

    default:
        respond(['error' => 'Action inconnue.'], 404);
}
