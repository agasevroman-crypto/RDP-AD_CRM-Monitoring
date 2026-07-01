<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = get_db_connection();
    
    echo "--- LAST 5 RDP EVENTS ---\n";
    $stmt = $pdo->query("SELECT timestamp, server_name, username, event_type, ip_address FROM rdp_events ORDER BY id DESC LIMIT 5");
    while ($row = $stmt->fetch()) {
        echo "Time: {$row['timestamp']}, Server: {$row['server_name']}, User: {$row['username']}, Event: {$row['event_type']}, IP: {$row['ip_address']}\n";
    }
    
    echo "\n--- LAST 5 AD EVENTS ---\n";
    $stmt = $pdo->query("SELECT timestamp, dc_name, event_id, action_type, target_user FROM ad_events ORDER BY id DESC LIMIT 5");
    while ($row = $stmt->fetch()) {
        echo "Time: {$row['timestamp']}, DC: {$row['dc_name']}, ID: {$row['event_id']}, Action: {$row['action_type']}, Target: {$row['target_user']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
