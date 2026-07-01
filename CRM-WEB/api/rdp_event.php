<?php
/**
 * API: Запис подій RDP та агрегація сеансів
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

$tokenInfo = validate_api_token();
if (!in_array('rdp', $tokenInfo['permissions'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token does not have RDP permission']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$srv = trim($data['server_name'] ?? '');
$usr = trim($data['username'] ?? '');
$sid = isset($data['session_id']) ? (int)$data['session_id'] : -1;
$evt = trim($data['event_type'] ?? '');
$ip  = trim($data['ip_address'] ?? 'Unknown');

// Translate Russian events to Ukrainian to match API expectations and Ukrainian dashboard
$translations = [
    'Подключение к RDP' => 'Підключення до RDP',
    'Переподключение к RDP' => 'Перепідключення до RDP',
    'Отключение от RDP (Свернуто)' => 'Відключення від RDP (Згорнуто)',
    'Завершение RDP-сеанса (Выход)' => 'Завершення RDP-сеансу (Вихід)'
];
if (isset($translations[$evt])) {
    $evt = $translations[$evt];
}

if ($srv === '' || $usr === '' || $sid === -1 || $evt === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: server_name, username, session_id, event_type']);
    exit;
}

try {
    $pdo = get_db_connection();
    
    // 1. Log raw event
    $pdo->prepare("INSERT INTO rdp_events (server_name, username, session_id, event_type, ip_address) VALUES (?,?,?,?,?)")
        ->execute([$srv, $usr, $sid, $evt, $ip]);
    
    // 2. Session aggregation
    $isStart = in_array($evt, ['Підключення до RDP', 'Перепідключення до RDP']);
    $isEnd   = in_array($evt, ['Відключення від RDP (Згорнуто)', 'Завершення RDP-сеансу (Вихід)']);
    
    if ($isStart) {
        // Close any orphan session first
        $orphan = $pdo->prepare("SELECT id FROM rdp_sessions WHERE server_name=? AND username=? AND session_id=? AND end_time IS NULL");
        $orphan->execute([$srv, $usr, $sid]);
        if ($row = $orphan->fetch()) {
            $pdo->prepare("UPDATE rdp_sessions SET end_time=NOW(), duration_seconds=TIMESTAMPDIFF(SECOND,start_time,NOW()) WHERE id=?")
                ->execute([$row['id']]);
        }
        // Open new session
        $pdo->prepare("INSERT INTO rdp_sessions (server_name, username, session_id, ip_address, start_time) VALUES (?,?,?,?,NOW())")
            ->execute([$srv, $usr, $sid, $ip]);
    } elseif ($isEnd) {
        $active = $pdo->prepare("SELECT id FROM rdp_sessions WHERE server_name=? AND username=? AND session_id=? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
        $active->execute([$srv, $usr, $sid]);
        if ($row = $active->fetch()) {
            $pdo->prepare("UPDATE rdp_sessions SET end_time=NOW(), duration_seconds=TIMESTAMPDIFF(SECOND,start_time,NOW()) WHERE id=?")
                ->execute([$row['id']]);
        } else {
            // No matching open session — record a zero-duration placeholder
            $pdo->prepare("INSERT INTO rdp_sessions (server_name, username, session_id, ip_address, start_time, end_time, duration_seconds) VALUES (?,?,?,?,NOW(),NOW(),0)")
                ->execute([$srv, $usr, $sid, $ip]);
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'RDP event logged']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
