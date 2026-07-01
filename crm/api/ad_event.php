<?php
/**
 * API: Запис подій Active Directory
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

$tokenInfo = validate_api_token();
if (!in_array('ad', $tokenInfo['permissions'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token does not have AD permission']);
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

$dc   = trim($data['dc_name'] ?? '');
$eid  = (int)($data['event_id'] ?? 0);
$act  = trim($data['action_type'] ?? '');
$tgt  = trim($data['target_user'] ?? '');
$clr  = trim($data['caller_user'] ?? '');
$det  = trim($data['details'] ?? '');

if ($dc === '' || $eid === 0 || $act === '' || $tgt === '' || $clr === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: dc_name, event_id, action_type, target_user, caller_user']);
    exit;
}

try {
    $pdo = get_db_connection();
    $pdo->prepare("INSERT INTO ad_events (dc_name, event_id, action_type, target_user, caller_user, details) VALUES (?,?,?,?,?,?)")
        ->execute([$dc, $eid, $act, $tgt, $clr, $det]);
    echo json_encode(['success' => true, 'message' => 'AD event logged']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
