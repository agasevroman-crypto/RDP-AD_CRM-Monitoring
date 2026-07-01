<?php
/**
 * migrate.php — Database migration and session rebuild utility
 */
require_once __DIR__ . '/config.php';

// Only allow superadmin or direct admin access, but for simplicity of this automation we can run it and delete it.
$pdo = get_db_connection();

echo "<h3>Starting migration...</h3>";

try {
    // 1. Translate events in rdp_events table
    $stmt1 = $pdo->exec("UPDATE rdp_events SET event_type = 'Підключення до RDP' WHERE event_type = 'Подключение к RDP'");
    $stmt2 = $pdo->exec("UPDATE rdp_events SET event_type = 'Перепідключення до RDP' WHERE event_type = 'Переподключение к RDP'");
    $stmt3 = $pdo->exec("UPDATE rdp_events SET event_type = 'Відключення від RDP (Згорнуто)' WHERE event_type = 'Отключение от RDP (Свернуто)'");
    $stmt4 = $pdo->exec("UPDATE rdp_events SET event_type = 'Завершення RDP-сеансу (Вихід)' WHERE event_type = 'Завершение RDP-сеанса (Выход)'");
    
    echo "Translated events:<br>";
    echo "- 'Подключение к RDP' -> 'Підключення до RDP' (affected: $stmt1)<br>";
    echo "- 'Переподключение к RDP' -> 'Перепідключення до RDP' (affected: $stmt2)<br>";
    echo "- 'Отключение от RDP (Свернуто)' -> 'Відключення від RDP (Згорнуто)' (affected: $stmt3)<br>";
    echo "- 'Завершение RDP-сеанса (Выход)' -> 'Завершення RDP-сеансу (Вихід)' (affected: $stmt4)<br><br>";

    // 2. Clear old session table to rebuild cleanly
    $pdo->exec("TRUNCATE TABLE rdp_sessions");
    echo "Cleared existing rdp_sessions table.<br>";

    // 3. Fetch all RDP events sorted chronologically
    $events = $pdo->query("SELECT * FROM rdp_events ORDER BY server_name, username, session_id, timestamp ASC")->fetchAll();
    echo "Fetched " . count($events) . " raw RDP events to rebuild sessions.<br>";

    $sessionsCreated = 0;
    $sessionsClosed = 0;

    foreach ($events as $evt) {
        $srv = $evt['server_name'];
        $usr = $evt['username'];
        $sid = $evt['session_id'];
        $ip  = $evt['ip_address'];
        $ts  = $evt['timestamp'];
        $type = $evt['event_type'];

        $isStart = in_array($type, ['Підключення до RDP', 'Перепідключення до RDP']);
        $isEnd   = in_array($type, ['Відключення від RDP (Згорнуто)', 'Завершення RDP-сеансу (Вихід)']);

        if ($isStart) {
            // Close any orphan sessions
            $orphan = $pdo->prepare("SELECT id FROM rdp_sessions WHERE server_name=? AND username=? AND session_id=? AND end_time IS NULL");
            $orphan->execute([$srv, $usr, $sid]);
            if ($row = $orphan->fetch()) {
                $pdo->prepare("UPDATE rdp_sessions SET end_time=?, duration_seconds=TIMESTAMPDIFF(SECOND,start_time,?) WHERE id=?")
                    ->execute([$ts, $ts, $row['id']]);
                $sessionsClosed++;
            }
            // Open new session
            $pdo->prepare("INSERT INTO rdp_sessions (server_name, username, session_id, ip_address, start_time) VALUES (?,?,?,?,?)")
                ->execute([$srv, $usr, $sid, $ip, $ts]);
            $sessionsCreated++;
        } elseif ($isEnd) {
            $active = $pdo->prepare("SELECT id FROM rdp_sessions WHERE server_name=? AND username=? AND session_id=? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
            $active->execute([$srv, $usr, $sid]);
            if ($row = $active->fetch()) {
                $pdo->prepare("UPDATE rdp_sessions SET end_time=?, duration_seconds=TIMESTAMPDIFF(SECOND,start_time,?) WHERE id=?")
                    ->execute([$ts, $ts, $row['id']]);
                $sessionsClosed++;
            } else {
                // Placeholder session
                $pdo->prepare("INSERT INTO rdp_sessions (server_name, username, session_id, ip_address, start_time, end_time, duration_seconds) VALUES (?,?,?,?,?,?,0)")
                    ->execute([$srv, $usr, $sid, $ip, $ts, $ts]);
                $sessionsCreated++;
            }
        }
    }

    echo "<h4>Migration successful!</h4>";
    echo "Total sessions created: $sessionsCreated<br>";
    echo "Total sessions closed/updated: $sessionsClosed<br>";

} catch (Exception $e) {
    echo "<h4 style='color:red;'>Error during migration: " . $e->getMessage() . "</h4>";
}
