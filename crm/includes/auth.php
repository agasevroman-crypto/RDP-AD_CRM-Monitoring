<?php
/**
 * auth.php — Session management, role checks, API token validation
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

/* ── Session Helpers ───────────────────────────── */

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function get_logged_in_user(): ?array {
    if (!is_logged_in()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role'     => $_SESSION['role'],
    ];
}

function require_super_admin(): void {
    require_login();
    if ($_SESSION['role'] !== 'super_admin') {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1>';
        exit;
    }
}

function is_super_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'super_admin';
}

/* ── Server Access Control ─────────────────────── */

function user_has_server_access(string $serverName): bool {
    $user = get_logged_in_user();
    if (!$user) return false;
    if ($user['role'] === 'super_admin') return true;

    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT 1 FROM admin_servers WHERE admin_id = ? AND server_name = ? LIMIT 1");
    $stmt->execute([$user['id'], $serverName]);
    return (bool)$stmt->fetch();
}

function get_user_servers(): array {
    $user = get_logged_in_user();
    if (!$user) return [];

    $pdo = get_db_connection();
    if ($user['role'] === 'super_admin') {
        return $pdo->query(
            "SELECT DISTINCT name FROM (
                SELECT server_name AS name FROM rdp_events
                UNION
                SELECT server_name AS name FROM rdp_sessions
                UNION
                SELECT dc_name AS name FROM ad_events
                UNION
                SELECT dc_name AS name FROM ad_logons
            ) combined ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    $stmt = $pdo->prepare("SELECT server_name FROM admin_servers WHERE admin_id = ? ORDER BY server_name");
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/* ── Data Retention ────────────────────────────── */

function cleanup_old_data(): void {
    $pdo = get_db_connection();
    $pdo->exec("DELETE FROM ad_events    WHERE timestamp  < DATE_SUB(NOW(), INTERVAL 3 MONTH)");
    $pdo->exec("DELETE FROM ad_logons    WHERE timestamp  < DATE_SUB(NOW(), INTERVAL 3 MONTH)");
    $pdo->exec("DELETE FROM rdp_events   WHERE timestamp  < DATE_SUB(NOW(), INTERVAL 3 MONTH)");
    $pdo->exec("DELETE FROM rdp_sessions WHERE start_time < DATE_SUB(NOW(), INTERVAL 3 MONTH)");
}

/* ── API Token Validation ──────────────────────── */

function validate_api_token(): array {
    // Gather Authorization header from any server API
    $authHeader = '';
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
        }
    }
    if (empty($authHeader)) {
        foreach ($_SERVER as $k => $v) {
            if ($k === 'HTTP_AUTHORIZATION' || $k === 'REDIRECT_HTTP_AUTHORIZATION') { $authHeader = $v; break; }
        }
    }

    if (empty($authHeader) || !preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Missing or malformed Authorization header']);
        exit;
    }

    $pdo  = get_db_connection();
    $rawToken = $m[1];
    $hashedToken = hash('sha256', $rawToken);
    $stmt = $pdo->prepare("SELECT id, permissions FROM api_tokens WHERE token = ? OR token = ?");
    $stmt->execute([$rawToken, $hashedToken]);
    $row  = $stmt->fetch();

    if (!$row) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid API token']);
        exit;
    }

    return ['token_id' => $row['id'], 'permissions' => explode(',', $row['permissions'])];
}
