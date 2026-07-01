<?php
/**
 * user_analytics.php — Детальна аналітика та облік RDP по користувачу
 */
require_once 'config.php';
require_login();

// ── Функції перевірки роботи поза графіком завантажуються з includes/helpers.php ──

$user = get_logged_in_user();
$allowedServers = get_user_servers();
$pdo = get_db_connection();

// ── Обробка оновлення графіка роботи або додавання типу графіка ──
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_schedule') {
        $targetUser = trim($_POST['username'] ?? '');
        $selectedTemplate = trim($_POST['selected_template'] ?? 'custom');
        
        if ($targetUser !== '') {
            try {
                if ($selectedTemplate === 'custom') {
                    $scheduleType = trim($_POST['schedule_type'] ?? 'mon_fri');
                    $workStart = trim($_POST['work_start'] ?? '08:30:00');
                    $workEnd = trim($_POST['work_end'] ?? '17:30:00');
                    $refDate = trim($_POST['ref_date'] ?? '');
                    $workDaysArr = $_POST['work_days'] ?? [];
                    
                    if ($scheduleType === 'custom') {
                        $workDays = implode(',', array_map('intval', $workDaysArr));
                    } else {
                        $workDays = '1,2,3,4,5';
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO user_schedules (username, template_id, schedule_type, work_start, work_end, work_days, ref_date) 
                                           VALUES (?, NULL, ?, ?, ?, ?, ?) 
                                           ON DUPLICATE KEY UPDATE 
                                               template_id = NULL,
                                               schedule_type = VALUES(schedule_type), 
                                               work_start = VALUES(work_start), 
                                               work_end = VALUES(work_end), 
                                               work_days = VALUES(work_days), 
                                               ref_date = VALUES(ref_date)");
                    $stmt->execute([
                        $targetUser,
                        $scheduleType,
                        $workStart,
                        $workEnd,
                        $workDays,
                        $refDate !== '' ? $refDate : null
                    ]);
                } else {
                    $tmplId = (int)$selectedTemplate;
                    $stmt = $pdo->prepare("INSERT INTO user_schedules (username, template_id) 
                                           VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE 
                                               template_id = VALUES(template_id)");
                    $stmt->execute([$targetUser, $tmplId]);
                }
                $successMsg = 'Графік роботи успішно збережено!';
            } catch (PDOException $e) {
                $errorMsg = 'Помилка збереження графіка: ' . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'add_template') {
        $templateName = trim($_POST['template_name'] ?? '');
        $schedType = trim($_POST['schedule_type'] ?? 'mon_fri');
        $wStart = trim($_POST['work_start'] ?? '08:30:00');
        $wEnd = trim($_POST['work_end'] ?? '17:30:00');
        $refDate = trim($_POST['ref_date'] ?? '');
        $workDaysArr = $_POST['work_days'] ?? [];
        
        if ($schedType === 'custom') {
            $wDays = implode(',', array_map('intval', $workDaysArr));
        } else {
            $wDays = '1,2,3,4,5';
        }
        
        if ($templateName !== '') {
            try {
                $stmt = $pdo->prepare("INSERT INTO schedule_templates (name, schedule_type, work_start, work_end, work_days, ref_date) 
                                       VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $templateName,
                    $schedType,
                    $wStart,
                    $wEnd,
                    $wDays,
                    $refDate !== '' ? $refDate : null
                ]);
                $successMsg = 'Новий тип графіка "' . $templateName . '" успішно додано!';
            } catch (PDOException $e) {
                $errorMsg = 'Помилка додавання типу графіка: ' . $e->getMessage();
            }
        } else {
            $errorMsg = 'Назва типу графіка не може бути порожньою.';
        }
    } elseif ($_POST['action'] === 'delete_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId > 4) {
            try {
                $pdo->prepare("UPDATE user_schedules SET template_id = NULL WHERE template_id = ?")->execute([$templateId]);
                $stmt = $pdo->prepare("DELETE FROM schedule_templates WHERE id = ?");
                $stmt->execute([$templateId]);
                $successMsg = 'Тип графіка успішно видалено!';
            } catch (PDOException $e) {
                $errorMsg = 'Помилка видалення типу графіка: ' . $e->getMessage();
            }
        } else {
            $errorMsg = 'Неможливо видалити стандартний тип графіка.';
        }
    }
}

// Завантаження всіх шаблонів графіків
$templates = $pdo->query("SELECT * FROM schedule_templates ORDER BY name ASC")->fetchAll();

// Допоміжні посилання для збереження GET параметрів
$qs = $_GET;
unset($qs['export']);
if (!function_exists('build_qs')) {
    function build_qs(array $override = []): string {
        global $qs;
        return '?' . http_build_query(array_merge($qs, $override));
    }
}

// 1. Отримання списку унікальних користувачів відповідно до дозволених серверів
$userList = [];
if (is_super_admin()) {
    $stmt = $pdo->query("SELECT DISTINCT username FROM rdp_sessions WHERE username != '' ORDER BY username");
    $userList = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    if (!empty($allowedServers)) {
        $ph = implode(',', array_fill(0, count($allowedServers), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT username FROM rdp_sessions WHERE username != '' AND server_name IN ($ph) ORDER BY username");
        $stmt->execute($allowedServers);
        $userList = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// 2. Отримання фільтрів
$filterUser = trim($_GET['username'] ?? '');
if ($filterUser === '' && !empty($userList)) {
    $filterUser = $userList[0];
}

$userSchedule = null;
if ($filterUser !== '') {
    $stmt = $pdo->prepare("SELECT us.*, st.name as template_name,
                                  COALESCE(st.schedule_type, us.schedule_type) as schedule_type,
                                  COALESCE(st.work_start, us.work_start) as work_start,
                                  COALESCE(st.work_end, us.work_end) as work_end,
                                  COALESCE(st.work_days, us.work_days) as work_days,
                                  COALESCE(st.ref_date, us.ref_date) as ref_date
                           FROM user_schedules us
                           LEFT JOIN schedule_templates st ON us.template_id = st.id
                           WHERE us.username = ?");
    $stmt->execute([$filterUser]);
    $userSchedule = $stmt->fetch();
    if (!$userSchedule) {
        $userSchedule = null;
    }
}

$filterServer = trim($_GET['server'] ?? '');
if ($filterServer !== '' && !in_array($filterServer, $allowedServers)) {
    $filterServer = '';
}

$filterFrom = trim($_GET['date_from'] ?? '');
$filterTo   = trim($_GET['date_to'] ?? '');

$hourFrom = isset($_GET['hour_from']) && $_GET['hour_from'] !== '' ? (int)$_GET['hour_from'] : null;
$hourTo   = isset($_GET['hour_to']) && $_GET['hour_to'] !== '' ? (int)$_GET['hour_to'] : null;

// 3. Побудова WHERE клаузи для аналітики
$whereClauses = [];
$params = [];

if ($filterUser !== '') {
    $whereClauses[] = "username = ?";
    $params[] = $filterUser;
} else {
    $whereClauses[] = "1=0";
}

if (!is_super_admin()) {
    if (empty($allowedServers)) {
        $whereClauses[] = "1=0";
    } elseif ($filterServer !== '') {
        $whereClauses[] = "server_name = ?";
        $params[] = $filterServer;
    } else {
        $ph = implode(',', array_fill(0, count($allowedServers), '?'));
        $whereClauses[] = "server_name IN ($ph)";
        $params = array_merge($params, $allowedServers);
    }
} else {
    if ($filterServer !== '') {
        $whereClauses[] = "server_name = ?";
        $params[] = $filterServer;
    }
}

if ($filterFrom !== '') {
    $whereClauses[] = "start_time >= ?";
    $params[] = "$filterFrom 00:00:00";
}
if ($filterTo !== '') {
    $whereClauses[] = "start_time <= ?";
    $params[] = "$filterTo 23:59:59";
}

if ($hourFrom !== null && $hourTo !== null) {
    if ($hourFrom <= $hourTo) {
        $whereClauses[] = "HOUR(start_time) >= ? AND HOUR(start_time) <= ?";
        $params[] = $hourFrom;
        $params[] = $hourTo;
    } else {
        $whereClauses[] = "(HOUR(start_time) >= ? OR HOUR(start_time) <= ?)";
        $params[] = $hourFrom;
        $params[] = $hourTo;
    }
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// 4. Експорт у CSV (зведений розподіл по днях)
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $filterUser !== '') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="user_' . urlencode($filterUser) . '_analytics_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($out, ['Дата', 'Сервер', 'Кількість сесій', 'Загальний час', 'Перше підключення', 'Останнє відключення', 'Позначка'], ';');

    $sql = "SELECT 
                DATE(start_time) as session_date,
                server_name,
                COUNT(*) as session_count,
                SUM(duration_seconds) as total_duration,
                MIN(TIME(start_time)) as first_connect,
                MAX(TIME(end_time)) as last_disconnect,
                SUM(CASE WHEN HOUR(start_time) >= 22 OR HOUR(start_time) < 6 THEN 1 ELSE 0 END) as night_count,
                SUM(CASE WHEN HOUR(start_time) >= 18 AND HOUR(start_time) < 22 THEN 1 ELSE 0 END) as evening_count
            FROM rdp_sessions
            $whereSql
            GROUP BY session_date, server_name
            ORDER BY session_date DESC, server_name ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tags = [];
        if ($r['night_count'] > 0) $tags[] = 'Нічна';
        if ($r['evening_count'] > 0) $tags[] = 'Вечірня';
        $tagStr = implode(', ', $tags);

        fputcsv($out, [
            $r['session_date'],
            $r['server_name'],
            $r['session_count'],
            format_duration((int)$r['total_duration']),
            $r['first_connect'],
            $r['last_disconnect'] ?? 'Активна',
            $tagStr
        ], ';');
    }
    fclose($out);
    exit;
}

// 5. Розрахунок KPI показників
$totalSessions = 0;
$totalSeconds  = 0;
$activeDays    = 0;
$nightSessions = 0;
$eveningSessions = 0;
$avgTimePerDay = 0;

if ($filterUser !== '') {
    $kpiSql = "SELECT 
                    COUNT(*) as total_sessions,
                    SUM(duration_seconds) as total_duration,
                    COUNT(DISTINCT DATE(start_time)) as active_days,
                    SUM(CASE WHEN HOUR(start_time) >= 22 OR HOUR(start_time) < 6 THEN 1 ELSE 0 END) as night_sessions,
                    SUM(CASE WHEN HOUR(start_time) >= 18 AND HOUR(start_time) < 22 THEN 1 ELSE 0 END) as evening_sessions
                FROM rdp_sessions
                $whereSql";
    $stmt = $pdo->prepare($kpiSql);
    $stmt->execute($params);
    $kpi = $stmt->fetch();
    if ($kpi) {
        $totalSessions = (int)$kpi['total_sessions'];
        $totalSeconds  = (int)$kpi['total_duration'];
        $activeDays    = (int)$kpi['active_days'];
        $nightSessions = (int)$kpi['night_sessions'];
        $eveningSessions = (int)$kpi['evening_sessions'];
        if ($activeDays > 0) {
            $avgTimePerDay = (int)($totalSeconds / $activeDays);
        }
    }

    $outsideScheduleSessions = 0;
    $outsideScheduleSeconds = 0;
    $dailyStats = []; // Date -> ['inside' => sec, 'outside' => sec]
    
    $checkSql = "SELECT start_time, end_time, duration_seconds FROM rdp_sessions $whereSql";
    $stmt = $pdo->prepare($checkSql);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $startStr = $row['start_time'];
        $endStr = $row['end_time'];
        $totalSec = (int)($row['duration_seconds'] ?? 0);
        
        if ($endStr === null) {
            $totalSec = time() - strtotime($startStr);
            if ($totalSec < 0) $totalSec = 0;
        }
        
        $outsideSec = get_session_outside_duration($startStr, $endStr, $userSchedule);
        $insideSec = max(0, $totalSec - $outsideSec);
        
        $dateStr = date('Y-m-d', strtotime($startStr));
        if (!isset($dailyStats[$dateStr])) {
            $dailyStats[$dateStr] = [
                'inside' => 0,
                'outside' => 0
            ];
        }
        $dailyStats[$dateStr]['inside'] += $insideSec;
        $dailyStats[$dateStr]['outside'] += $outsideSec;
        
        if (is_outside_schedule($startStr, $userSchedule)) {
            $outsideScheduleSessions++;
        }
        $outsideScheduleSeconds += $outsideSec;
    }
}

// 6. Отримання годинного розподілу активності
$hourlyConnections = array_fill(0, 24, 0);
if ($filterUser !== '') {
    $hourlySql = "SELECT HOUR(start_time) as hr, COUNT(*) as cnt 
                  FROM rdp_sessions 
                  $whereSql 
                  GROUP BY HOUR(start_time)";
    $stmt = $pdo->prepare($hourlySql);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $hourlyConnections[(int)$row['hr']] = (int)$row['cnt'];
    }
}

// 7. Сортування та пагінація для таблиці розподілу по днях
$allowedSort = ['session_date', 'server_name', 'session_count', 'total_duration', 'first_connect', 'last_disconnect'];
$sortBy = in_array($_GET['sort_by'] ?? '', $allowedSort) ? $_GET['sort_by'] : 'session_date';
$sortDir = strtoupper($_GET['sort_dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 100;
$offset = ($page - 1) * $limit;

$totalRecords = 0;
$daysRows = [];

if ($filterUser !== '') {
    $countSql = "SELECT COUNT(*) FROM (
                    SELECT 1 FROM rdp_sessions
                    $whereSql
                    GROUP BY DATE(start_time), server_name
                 ) as tmp";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalRecords = (int)$stmt->fetchColumn();

    $sql = "SELECT 
                DATE(start_time) as session_date,
                server_name,
                COUNT(*) as session_count,
                SUM(duration_seconds) as total_duration,
                MIN(TIME(start_time)) as first_connect,
                MAX(TIME(end_time)) as last_disconnect,
                SUM(CASE WHEN HOUR(start_time) >= 22 OR HOUR(start_time) < 6 THEN 1 ELSE 0 END) as night_count,
                SUM(CASE WHEN HOUR(start_time) >= 18 AND HOUR(start_time) < 22 THEN 1 ELSE 0 END) as evening_count,
                SUM(CASE WHEN end_time IS NULL THEN 1 ELSE 0 END) as active_count
            FROM rdp_sessions
            $whereSql
            GROUP BY session_date, server_name
            ORDER BY $sortBy $sortDir
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $daysRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalPages = max(1, ceil($totalRecords / $limit));

// 8. Отримання детальних сесій (останні 200 записів) з розрахунком позначок для JS
$detailedRows = [];
if ($filterUser !== '') {
    $sql = "SELECT id, server_name, username, session_id, start_time, end_time, duration_seconds, ip_address
            FROM rdp_sessions
            $whereSql
            ORDER BY start_time DESC
            LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $isOutside = is_outside_schedule($row['start_time'], $userSchedule);
        $startHour = (int)date('H', strtotime($row['start_time']));
        $badgeType = '';
        if ($isOutside) {
            $badgeType = 'outside';
        } elseif ($startHour >= 22 || $startHour < 6) {
            $badgeType = 'night';
        } elseif ($startHour >= 18 && $startHour < 22) {
            $badgeType = 'evening';
        }
        
        $row['is_outside'] = $isOutside;
        $row['badge_type'] = $badgeType;
        $detailedRows[] = $row;
    }
}

// 8.5 Розрахунок статистики по серверах за обраний період
$serverDurations = [];
$totalWorkSecondsForServers = 0;
if ($filterUser !== '') {
    $srvSql = "SELECT server_name, SUM(duration_seconds) as total_duration 
               FROM rdp_sessions 
               $whereSql 
               GROUP BY server_name 
               ORDER BY total_duration DESC";
    $stmt = $pdo->prepare($srvSql);
    $stmt->execute($params);
    $srvRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($srvRows as $row) {
        $serverDurations[$row['server_name']] = (int)$row['total_duration'];
        $totalWorkSecondsForServers += (int)$row['total_duration'];
    }
}

// Отримання списку унікальних серверів для легенди таймлайну
$uniqueServersInLog = [];
if ($filterUser !== '' && !empty($detailedRows)) {
    foreach ($detailedRows as $session) {
        $uniqueServersInLog[$session['server_name']] = true;
    }
}

// 9. Допоміжні функції відображення
if (!function_exists('get_server_color')) {
    function get_server_color(string $server): string {
        static $colors = [
            '#007AFF', // Cupertino Blue
            '#34C759', // Cupertino Green
            '#FF9500', // Cupertino Orange
            '#AF52DE', // Cupertino Purple
            '#FF3B30', // Cupertino Red
            '#5856D6', // Indigo
            '#FF2D55', // Pink
            '#30B0C7', // Teal
            '#FFCC00', // Yellow
        ];
        static $assigned = [];
        if (!isset($assigned[$server])) {
            $idx = count($assigned) % count($colors);
            $assigned[$server] = $colors[$idx];
        }
        return $assigned[$server];
    }
}



if (!function_exists('get_day_timeline_intervals')) {
    function get_day_timeline_intervals(string $dateStr, array $sessions): array {
        $dayStart = strtotime("$dateStr 00:00:00");
        $dayEnd   = strtotime("$dateStr 23:59:59");
        $intervals = [];
        
        foreach ($sessions as $session) {
            $s = strtotime($session['start_time']);
            $e = $session['end_time'] ? strtotime($session['end_time']) : time();
            
            $oStart = max($s, $dayStart);
            $oEnd   = min($e, $dayEnd);
            
            if ($oStart < $oEnd) {
                $left = (($oStart - $dayStart) / 86400) * 100;
                $width = (($oEnd - $oStart) / 86400) * 100;
                
                if ($width < 0.8) {
                    $width = 0.8;
                }
                
                $startTimeStr = date('H:i:s', $s);
                $endTimeStr = $session['end_time'] ? date('H:i:s', $e) : 'активна';
                
                $isOutside = is_outside_schedule($session['start_time'], $GLOBALS['userSchedule']);
                $color = $isOutside ? 'var(--error-color)' : get_server_color($session['server_name']);
                $class = $isOutside ? 'dvr-segment outside-schedule' : 'dvr-segment';
                $titlePrefix = $isOutside ? '[ПОЗА ГРАФІКОМ] ' : '';
                
                $intervals[] = [
                    'left' => round($left, 2),
                    'width' => round($width, 2),
                    'server' => $session['server_name'],
                    'color' => $color,
                    'class' => $class,
                    'title' => sprintf(
                        "%sСервер: %s | Час: %s - %s (%s)",
                        $titlePrefix,
                        $session['server_name'],
                        $startTimeStr,
                        $endTimeStr,
                        format_duration($session['duration_seconds'])
                    )
                ];
            }
        }
        return $intervals;
    }
}

if (!function_exists('get_day_timeline_intervals_for_server')) {
    function get_day_timeline_intervals_for_server(string $dateStr, string $serverName, array $sessions): array {
        $dayStart = strtotime("$dateStr 00:00:00");
        $dayEnd   = strtotime("$dateStr 23:59:59");
        $intervals = [];
        
        foreach ($sessions as $session) {
            if ($session['server_name'] !== $serverName) {
                continue;
            }
            $s = strtotime($session['start_time']);
            $e = $session['end_time'] ? strtotime($session['end_time']) : time();
            
            $oStart = max($s, $dayStart);
            $oEnd   = min($e, $dayEnd);
            
            if ($oStart < $oEnd) {
                $left = (($oStart - $dayStart) / 86400) * 100;
                $width = (($oEnd - $oStart) / 86400) * 100;
                
                if ($width < 0.8) {
                    $width = 0.8;
                }
                
                $isOutside = is_outside_schedule($session['start_time'], $GLOBALS['userSchedule']);
                $color = $isOutside ? 'var(--error-color)' : get_server_color($session['server_name']);
                $class = $isOutside ? 'dvr-segment outside-schedule' : 'dvr-segment';
                $titlePrefix = $isOutside ? '[ПОЗА ГРАФІКОМ] ' : '';
                
                $intervals[] = [
                    'left' => round($left, 2),
                    'width' => round($width, 2),
                    'color' => $color,
                    'class' => $class,
                    'title' => sprintf(
                        "%sЧас: %s - %s (%s)",
                        $titlePrefix,
                        date('H:i', $s),
                        $session['end_time'] ? date('H:i', $e) : 'активна',
                        format_duration($session['duration_seconds'])
                    )
                ];
            }
        }
        return $intervals;
    }
}

if (!function_exists('render_sort_link_local')) {
    function render_sort_link_local(string $colName, string $title) {
        global $sortBy, $sortDir;
        $isCurrent = ($sortBy === $colName);
        $nextDir = ($isCurrent && $sortDir === 'DESC') ? 'ASC' : 'DESC';
        $arrow = '';
        if ($isCurrent) {
            $arrow = ($sortDir === 'ASC') ? ' ▴' : ' ▾';
        }
        echo '<a href="' . e(build_qs(['sort_by' => $colName, 'sort_dir' => $nextDir, 'page' => 1])) . '" style="text-decoration:none; color:inherit; font-weight:600;">' . e($title) . $arrow . '</a>';
    }
}

$pageTitle = 'Аналітика користувача'; $activePage = 'user_profile';
require 'includes/header.php';
?>

<main>
    <!-- Заголовок та експорт -->
    <div class="page-header">
        <h2>👤 Аналітика користувача: <?= $filterUser !== '' ? e($filterUser) : 'не обрано' ?></h2>
        <div style="display: flex; gap: 8px;">
            <?php if ($filterUser !== ''): ?>
            <button type="button" class="btn-action btn-secondary" onclick="openScheduleModal()" style="background:#fff; color:var(--text-color); border: 1px solid var(--border-color); font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                <span>⚙️</span> Налаштувати графік
            </button>
            <a href="<?= e(build_qs(['export' => 'csv'])) ?>" class="btn-action btn-secondary" style="background:#fff; color:var(--text-color); border: 1px solid var(--border-color); text-decoration:none; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                <span>⬇</span> Експорт у CSV
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success"><?= e($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-error"><?= e($errorMsg) ?></div>
    <?php endif; ?>

    <!-- Картка фільтрації -->
    <div class="card">
        <form method="get" class="filter-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)) 120px; gap: 16px; align-items: end;">
            <input type="hidden" name="sort_by" value="<?= e($sortBy) ?>">
            <input type="hidden" name="sort_dir" value="<?= e($sortDir) ?>">

            <div class="filter-group">
                <label>Користувач</label>
                <select name="username" class="form-control" onchange="this.form.submit()">
                    <?php if (empty($userList)): ?>
                        <option value="">— Немає даних —</option>
                    <?php else: ?>
                        <?php foreach ($userList as $u): ?>
                            <option value="<?= e($u) ?>" <?= $filterUser === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Сервер</label>
                <select name="server" class="form-control">
                    <option value="">— Всі сервери —</option>
                    <?php foreach ($allowedServers as $srv): ?>
                        <option value="<?= e($srv) ?>" <?= $filterServer === $srv ? 'selected' : '' ?>><?= e($srv) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Дата з</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($filterFrom) ?>">
            </div>

            <div class="filter-group">
                <label>Дата по</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($filterTo) ?>">
            </div>

            <div class="filter-group">
                <label>Година з</label>
                <select name="hour_from" class="form-control">
                    <option value="">— Будь-яка —</option>
                    <?php for ($i = 0; $i < 24; $i++): ?>
                        <option value="<?= $i ?>" <?= $hourFrom !== null && $hourFrom === $i ? 'selected' : '' ?>><?= sprintf("%02d:00", $i) ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Година по</label>
                <select name="hour_to" class="form-control">
                    <option value="">— Будь-яка —</option>
                    <?php for ($i = 0; $i < 24; $i++): ?>
                        <option value="<?= $i ?>" <?= $hourTo !== null && $hourTo === $i ? 'selected' : '' ?>><?= sprintf("%02d:00", $i) ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <button type="submit" class="btn-action" style="height: 38px; width: 100%;">Пошук</button>
        </form>
   </div>

   <?php if ($filterUser === ''): ?>
        <div class="card" style="text-align: center; padding: 40px; margin-top: 20px;">
            <span style="font-size: 48px;">👤</span>
            <h3 style="margin-top: 16px;">Користувача не обрано або дані відсутні</h3>
            <p style="color: var(--secondary-color); margin-top: 8px;">Будь ласка, оберіть користувача зі списку вище для перегляду аналітики.</p>
        </div>
   <?php else: ?>
        <!-- KPI Картки -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-title">🕐 Загальний час</div>
                <div class="metric-value-row">
                    <div class="metric-value" style="font-size: 20px;"><?= format_duration($totalSeconds) ?></div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-title">📊 Сесій за період</div>
                <div class="metric-value-row">
                    <div class="metric-value"><?= $totalSessions ?></div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-title">📅 Активних днів</div>
                <div class="metric-value-row">
                    <div class="metric-value"><?= $activeDays ?></div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-title">⚠️ Сесій поза графіком</div>
                <div class="metric-value-row">
                    <div class="metric-value"><?= $outsideScheduleSessions ?></div>
                    <?php if ($outsideScheduleSessions > 0): ?>
                        <span class="metric-badge red">Поза часом</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-title">⏱ Час поза графіком</div>
                <div class="metric-value-row">
                    <div class="metric-value" style="font-size: 20px;"><?= format_duration($outsideScheduleSeconds) ?></div>
                    <?php if ($outsideScheduleSeconds > 0): ?>
                        <span class="metric-badge red">Відхилення</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-title">⚠️ Нічних підключень</div>
                <div class="metric-value-row">
                    <div class="metric-value"><?= $nightSessions ?></div>
                    <?php if ($nightSessions > 0): ?>
                        <span class="metric-badge red">Ніч</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-title">🌆 Вечірніх підключень</div>
                <div class="metric-value-row">
                    <div class="metric-value"><?= $eveningSessions ?></div>
                    <?php if ($eveningSessions > 0): ?>
                        <span class="metric-badge warning" style="background-color: rgba(255, 149, 0, 0.1); color: #FF9500;">Вечір</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-title">⏱ Середній час / день</div>
                <div class="metric-value-row">
                    <div class="metric-value" style="font-size: 20px;"><?= format_duration($avgTimePerDay) ?></div>
                </div>
            </div>
        </div>

        <!-- Блок прогресу норми та статистики по серверах -->
        <div class="content-row">
            <!-- Картка 1: Прогрес норми годин -->
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header">
                    <div class="card-title">📊 Виконання норми робочого часу за період</div>
                </div>
                <?php
                // Розрахунок норми годин
                $dailyWorkSeconds = 9 * 3600; // 08:30 - 17:30 = 9 годин
                if ($userSchedule) {
                    $sTime = strtotime($userSchedule['work_start'] ?? '08:30:00');
                    $eTime = strtotime($userSchedule['work_end'] ?? '17:30:00');
                    $dailyWorkSeconds = max(0, $eTime - $sTime);
                }

                $workDaysCount = 0;
                $normSeconds = 0;
                
                // Визначаємо дні для розрахунку норми
                $chartFrom = $filterFrom;
                $chartTo = $filterTo;
                if ($chartFrom === '') {
                    $chartFrom = date('Y-m-d', strtotime('-30 days'));
                }
                if ($chartTo === '') {
                    $chartTo = date('Y-m-d');
                }
                
                $startTs = strtotime($chartFrom);
                $endTs = strtotime($chartTo);
                
                // Обмежуємо розрахунок норми діапазоном до 31 дня
                if (($endTs - $startTs) / 86400 > 31) {
                    $startTs = $endTs - 30 * 86400;
                }
                
                $calcDates = [];
                $currTs = $startTs;
                while ($currTs <= $endTs) {
                    $calcDates[] = date('Y-m-d', $currTs);
                    $currTs += 86400;
                }

                if ($userSchedule && $userSchedule['schedule_type'] === 'always_off') {
                    $normSeconds = 0;
                } else {
                    foreach ($calcDates as $d) {
                        $dow = (int)date('N', strtotime($d));
                        $type = $userSchedule['schedule_type'] ?? 'mon_fri';
                        $workDays = ($type === 'mon_fri') ? [1,2,3,4,5] : explode(',', $userSchedule['work_days'] ?? '1,2,3,4,5');
                        if (in_array($dow, $workDays) && ($type === 'mon_fri' || $type === 'custom')) {
                            $workDaysCount++;
                        }
                    }
                    if ($userSchedule && ($userSchedule['schedule_type'] === 'shift_24' || $userSchedule['schedule_type'] === 'shift_12')) {
                        $normSeconds = round((160 / 30) * count($calcDates) * 3600);
                    } else {
                        $normSeconds = $workDaysCount * $dailyWorkSeconds;
                    }
                }
                $normHours = round($normSeconds / 3600, 1);
                $actualHours = round($totalSeconds / 3600, 1);
                
                $percentage = 0;
                if ($normSeconds > 0) {
                    $percentage = min(100, round(($totalSeconds / $normSeconds) * 100));
                }
                ?>
                <div style="padding: 10px 0;">
                    <div style="display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 8px;">
                        <span>Норма часу: <?= $normHours ?> год (за період)</span>
                        <span style="color: var(--accent-color);"><?= $percentage ?>% виконано</span>
                    </div>
                    
                    <div style="width: 100%; height: 20px; background-color: var(--bg-color); border-radius: 10px; overflow: hidden; margin-bottom: 16px; border: 1px solid var(--border-color);">
                        <div style="width: <?= $percentage ?>%; height: 100%; background: linear-gradient(90deg, var(--accent-color), #5856D6); transition: width 0.5s ease-in-out; border-radius: 10px;"></div>
                    </div>
                    
                    <div style="font-size: 13px; color: var(--secondary-color); line-height: 1.6; display: flex; flex-direction: column; gap: 6px;">
                        <div>⏱ Відпрацьовано всього: <strong><?= $actualHours ?> год</strong></div>
                        <div>✅ В межах графіка: <strong><?= round(($totalSeconds - $outsideScheduleSeconds) / 3600, 1) ?> год</strong></div>
                        <div>⚠️ Поза графіком (переробіток): <strong style="color: <?= $outsideScheduleSeconds > 0 ? 'var(--error-color)' : 'inherit' ?>;"><?= round($outsideScheduleSeconds / 3600, 1) ?> год</strong></div>
                    </div>
                </div>
            </div>
            
            <!-- Картка 2: Статистика по серверах -->
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header">
                    <div class="card-title">🖥️ Статистика підключень по серверах</div>
                </div>
                <div style="padding: 10px 0; max-height: 156px; overflow-y: auto;">
                    <?php if (empty($serverDurations)): ?>
                        <div class="no-data-msg" style="padding: 20px 0;">Немає даних підключень</div>
                    <?php else: ?>
                        <?php foreach ($serverDurations as $srv => $dur): 
                            $pct = $totalWorkSecondsForServers > 0 ? round(($dur / $totalWorkSecondsForServers) * 100) : 0;
                        ?>
                            <div class="user-bar-row" style="margin-bottom: 12px;">
                                <div class="user-bar-info" style="font-size: 12px; margin-bottom: 2px;">
                                    <span class="user-bar-name" style="font-weight: 600;"><?= e($srv) ?></span>
                                    <span class="user-bar-value"><?= round($dur / 3600, 1) ?> год (<?= $pct ?>%)</span>
                                </div>
                                <div class="user-bar-container" style="height: 6px; background-color: var(--bg-color);">
                                    <div class="user-bar-fill" style="width: <?= $pct ?>%; height: 100%; background-color: <?= get_server_color($srv) ?>;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- DVR Хронологія підключень (Timeline) -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">📼 Смуги активності підключень (DVR Хронологія 24 год)</div>
            </div>
            
            <?php if (empty($daysRows)): ?>
                <div class="no-data-msg">Дані для таймлайну відсутні</div>
            <?php else: ?>
                <!-- Шкала часу -->
                <div class="dvr-timeline-header">
                    <div class="dvr-timeline-scale">
                        <?php for ($i = 0; $i <= 24; $i += 2): ?>
                            <span><?= sprintf("%02d", $i) ?></span>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="dvr-timeline-container" style="border: none; padding: 0; margin-top: 0; background: transparent;">
                    <?php
                    // Групуємо дні для виведення унікальних смуг дат
                    $activeDates = [];
                    foreach ($daysRows as $r) {
                        $activeDates[$r['session_date']] = true;
                    }
                    $activeDates = array_keys($activeDates);
                    
                    foreach ($activeDates as $dateStr):
                        $intervals = get_day_timeline_intervals($dateStr, $detailedRows);
                    ?>
                        <div class="dvr-timeline-row">
                            <div class="dvr-date-label">
                                <?= date('d.m.Y', strtotime($dateStr)) ?>
                            </div>
                            <div class="dvr-bar">
                                <?php foreach ($intervals as $interval): ?>
                                    <div class="dvr-segment" style="left: <?= $interval['left'] ?>%; width: <?= $interval['width'] ?>%; background-color: <?= $interval['color'] ?>;" title="<?= e($interval['title']) ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Легенда кольорів серверів -->
                <?php if (!empty($uniqueServersInLog)): ?>
                    <div class="dvr-legend-container">
                        <?php foreach (array_keys($uniqueServersInLog) as $srvName): ?>
                            <div class="dvr-legend-item">
                                <span class="dvr-legend-color" style="background-color: <?= get_server_color($srvName) ?>;"></span>
                                <strong><?= e($srvName) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Стовпчиковий графік напрацьованого часу за днями місяця -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">📊 Графік напрацьованого та понаднормового часу за днями (год)</div>
            </div>
            <div style="padding: 10px 0;">
                <?php
                // Визначаємо період для графіка (до 31 дня)
                $chartFrom = $filterFrom;
                $chartTo = $filterTo;
                if ($chartFrom === '') {
                    $chartFrom = date('Y-m-d', strtotime('-30 days'));
                }
                if ($chartTo === '') {
                    $chartTo = date('Y-m-d');
                }

                $startTs = strtotime($chartFrom);
                $endTs = strtotime($chartTo);
                
                // Якщо обрано завеликий інтервал, обмежуємо останніми 31 днями
                if (($endTs - $startTs) / 86400 > 31) {
                    $startTs = $endTs - 30 * 86400;
                }

                $chartDates = [];
                $currTs = $startTs;
                while ($currTs <= $endTs) {
                    $chartDates[] = date('Y-m-d', $currTs);
                    $currTs += 86400;
                }

                $maxDailyHours = 0;
                foreach ($chartDates as $d) {
                    $inSec = $dailyStats[$d]['inside'] ?? 0;
                    $outSec = $dailyStats[$d]['outside'] ?? 0;
                    $totH = ($inSec + $outSec) / 3600;
                    if ($totH > $maxDailyHours) {
                        $maxDailyHours = $totH;
                    }
                }
                $maxDailyHours = max(1, ceil($maxDailyHours));

                $chartHeight = 130;
                $paddingLeft = 40;
                $paddingTop = 15;
                $barWidth = 26;
                $barGap = 8;
                
                $numDays = count($chartDates);
                $chartWidth = $numDays * ($barWidth + $barGap) + 10;
                $viewBoxWidth = $chartWidth + $paddingLeft + 20;
                ?>
                <div class="table-responsive" style="border: none; padding: 0; overflow-y: hidden;">
                    <svg viewBox="0 0 <?= $viewBoxWidth ?> 180" style="width: 100%; height: auto; min-width: 800px;">
                        <!-- Horizontal Grid Lines -->
                        <?php for ($i = 0; $i <= 4; $i++): ?>
                            <?php 
                            $y = $paddingTop + ($chartHeight / 4) * $i; 
                            $val = round($maxDailyHours * (4 - $i) / 4, 1);
                            ?>
                            <line x1="<?= $paddingLeft ?>" y1="<?= $y ?>" x2="<?= $paddingLeft + $chartWidth ?>" y2="<?= $y ?>" stroke="var(--border-color)" stroke-dasharray="3,3" stroke-width="0.5" />
                            <text x="<?= $paddingLeft - 8 ?>" y="<?= $y + 3 ?>" text-anchor="end" font-size="9px" fill="var(--secondary-color)"><?= $val ?> год</text>
                        <?php endfor; ?>

                        <!-- Bottom Axis -->
                        <line x1="<?= $paddingLeft ?>" y1="<?= $paddingTop + $chartHeight ?>" x2="<?= $paddingLeft + $chartWidth ?>" y2="<?= $paddingTop + $chartHeight ?>" stroke="var(--secondary-color)" stroke-width="1" />

                        <!-- Stacked Bars -->
                        <?php
                        foreach ($chartDates as $idx => $d) {
                            $insideSec = $dailyStats[$d]['inside'] ?? 0;
                            $outsideSec = $dailyStats[$d]['outside'] ?? 0;
                            
                            $insideHours = round($insideSec / 3600, 1);
                            $outsideHours = round($outsideSec / 3600, 1);
                            
                            $hInside = ($insideHours / $maxDailyHours) * $chartHeight;
                            $hOutside = ($outsideHours / $maxDailyHours) * $chartHeight;
                            
                            $x = $paddingLeft + $idx * ($barWidth + $barGap) + 5;
                            
                            // Draw normal hours bar (inside schedule) - Cupertino Blue
                            $yInside = $paddingTop + $chartHeight - $hInside;
                            if ($hInside > 0) {
                                echo "<rect x=\"$x\" y=\"$yInside\" width=\"$barWidth\" height=\"$hInside\" fill=\"var(--accent-color)\" rx=\"2\">";
                                echo "<title>Дата: " . date('d.m.Y', strtotime($d)) . "\nВ межах графіка: $insideHours год</title>";
                                echo "</rect>";
                            }
                            
                            // Draw overtime hours bar (outside schedule) - Cupertino Red (stacked on top)
                            $yOutside = $yInside - $hOutside;
                            if ($hOutside > 0) {
                                echo "<rect x=\"$x\" y=\"$yOutside\" width=\"$barWidth\" height=\"$hOutside\" fill=\"var(--error-color)\" rx=\"2\">";
                                echo "<title>Дата: " . date('d.m.Y', strtotime($d)) . "\nПоза графіком (понаднормово): $outsideHours год</title>";
                                echo "</rect>";
                            }
                            
                            // Labels for date (day number)
                            $dayLabel = date('d', strtotime($d));
                            $lx = $x + $barWidth / 2;
                            $ly = $paddingTop + $chartHeight + 14;
                            echo "<text x=\"$lx\" y=\"$ly\" text-anchor=\"middle\" font-size=\"9px\" fill=\"var(--secondary-color)\">$dayLabel</text>";
                        }
                        ?>
                    </svg>
                </div>
            </div>
            
            <div style="display: flex; gap: 16px; justify-content: center; font-size: 11px; margin-top: 10px; border-top: 1px dashed var(--border-color); padding-top: 10px;">
                <div style="display: flex; align-items: center; gap: 4px;">
                    <span style="display: inline-block; width: 10px; height: 10px; background-color: var(--accent-color); border-radius: 2px;"></span>
                    <span>Відпрацьовано в межах графіка (год)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 4px;">
                    <span style="display: inline-block; width: 10px; height: 10px; background-color: var(--error-color); border-radius: 2px;"></span>
                    <span>Поза робочим графіком / Понаднормово (год)</span>
                </div>
            </div>
        </div>

        <!-- Таблиця: Розподіл по днях -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">📅 Розподіл активності по днях (100 рядків)</div>
            </div>
            
            <?php if (empty($daysRows)): ?>
                <div class="no-data-msg">Записи відсутні за вказаними фільтрами</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="white-space: nowrap;"><?php render_sort_link_local('session_date', 'Дата'); ?></th>
                                <th style="white-space: nowrap;"><?php render_sort_link_local('server_name', 'Сервер'); ?></th>
                                <th style="white-space: nowrap; text-align: center;"><?php render_sort_link_local('session_count', 'Сесій'); ?></th>
                                <th style="white-space: nowrap; text-align: right;"><?php render_sort_link_local('total_duration', 'Час роботи'); ?></th>
                                <th style="white-space: nowrap;"><?php render_sort_link_local('first_connect', 'Перший вхід'); ?></th>
                                <th style="white-space: nowrap;"><?php render_sort_link_local('last_disconnect', 'Останній вихід'); ?></th>
                                <th style="white-space: nowrap;">Хронологія (24 год)</th>
                                <th style="white-space: nowrap;">Позначка</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daysRows as $row): ?>
                                <?php
                                $rowIntervals = get_day_timeline_intervals_for_server($row['session_date'], $row['server_name'], $detailedRows);
                                $hasOutside = false;
                                foreach ($rowIntervals as $interval) {
                                    if (strpos($interval['class'], 'outside-schedule') !== false) {
                                        $hasOutside = true;
                                        break;
                                    }
                                }
                                
                                $rowClass = '';
                                $badge = '';
                                if ($hasOutside) {
                                    $rowClass = 'row-outside-schedule';
                                    $badge = '<span class="badge-outside">⚠️ Поза графіком</span>';
                                } elseif ($row['night_count'] > 0) {
                                    $rowClass = 'row-night';
                                    $badge = '<span class="badge-night">🌙 Нічна</span>';
                                } elseif ($row['evening_count'] > 0) {
                                    $rowClass = 'row-evening';
                                    $badge = '<span class="badge-evening">🌆 Вечірня</span>';
                                }
                                ?>
                                <tr class="<?= $rowClass ?>">
                                    <td><?= date('d.m.Y', strtotime($row['session_date'])) ?></td>
                                    <td><span class="srv-badge"><?= e($row['server_name']) ?></span></td>
                                    <td style="text-align: center;"><?= $row['session_count'] ?></td>
                                    <td style="text-align: right; font-weight: 600;"><?= format_duration((int)$row['total_duration']) ?></td>
                                    <td><?= date('H:i:s', strtotime($row['first_connect'])) ?></td>
                                    <td>
                                        <?php if ($row['active_count'] > 0): ?>
                                            <span class="active-dot" title="Активна сесія зараз"></span> <span style="font-weight: 600; color: var(--success-color);">Активна зараз</span>
                                        <?php else: ?>
                                            <?= date('H:i:s', strtotime($row['last_disconnect'])) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="dvr-bar" style="height: 12px; width: 140px; margin: 0; display: inline-block; vertical-align: middle;">
                                            <?php foreach ($rowIntervals as $interval): ?>
                                                <div class="<?= $interval['class'] ?>" style="left: <?= $interval['left'] ?>%; width: <?= $interval['width'] ?>%; background-color: <?= $interval['color'] ?>;" title="<?= e($interval['title']) ?>"></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td><?= $badge ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Пагінація -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination" style="margin-top: 20px; display: flex; justify-content: center; gap: 8px;">
                        <?php if ($page > 1): ?>
                            <a href="<?= e(build_qs(['page' => $page - 1])) ?>" class="btn-action btn-secondary" style="padding: 6px 12px; font-size: 13px; text-decoration: none;">« Попередня</a>
                        <?php endif; ?>
                        
                        <span style="align-self: center; font-size: 13px; color: var(--secondary-color);">
                            Сторінка <strong><?= $page ?></strong> з <strong><?= $totalPages ?></strong> (Всього: <?= $totalRecords ?>)
                        </span>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= e(build_qs(['page' => $page + 1])) ?>" class="btn-action btn-secondary" style="padding: 6px 12px; font-size: 13px; text-decoration: none;">Наступна »</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Таблиця: Детальні сесії -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">📋 Детальний журнал сесій (Останні 200 записів)</div>
            </div>
            
            <?php if (empty($detailedRows)): ?>
                <div class="no-data-msg">Сесії за вказаними фільтрами відсутні</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Час підключення</th>
                                <th>Час відключення</th>
                                <th>Сервер</th>
                                <th>ID сесії</th>
                                <th>IP-адреса</th>
                                <th style="text-align: right;">Тривалість</th>
                                <th>Позначка</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detailedRows as $row): ?>
                                <?php
                                $isOutside = is_outside_schedule($row['start_time'], $userSchedule);
                                $startHour = (int)date('H', strtotime($row['start_time']));
                                $rowClass = '';
                                $badge = '';
                                
                                if ($isOutside) {
                                    $rowClass = 'row-outside-schedule';
                                    $badge = '<span class="badge-outside">⚠️ Поза графіком</span>';
                                } elseif ($startHour >= 22 || $startHour < 6) {
                                    $rowClass = 'row-night';
                                    $badge = '<span class="badge-night">🌙 Нічна</span>';
                                } elseif ($startHour >= 18 && $startHour < 22) {
                                    $rowClass = 'row-evening';
                                    $badge = '<span class="badge-evening">🌆 Вечірня</span>';
                                }
                                ?>
                                <tr class="<?= $rowClass ?>" style="cursor: pointer;" onclick="viewSessionDetails(<?= $row['id'] ?>)" title="Клікніть для перегляду деталей сесії">
                                    <td><?= date('d.m.Y H:i:s', strtotime($row['start_time'])) ?></td>
                                    <td>
                                        <?php if ($row['end_time'] === null): ?>
                                            <span class="active-dot" title="Активна зараз"></span> <span style="font-weight: 600; color: var(--success-color);">Активна зараз</span>
                                        <?php else: ?>
                                            <?= date('d.m.Y H:i:s', strtotime($row['end_time'])) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="srv-badge"><?= e($row['server_name']) ?></span></td>
                                    <td><?= e($row['session_id']) ?></td>
                                    <td><code><?= e($row['ip_address'] ?? 'Unknown') ?></code></td>
                                    <td style="text-align: right; font-weight: 600;"><?= format_duration($row['duration_seconds']) ?></td>
                                    <td><?= $badge ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <!-- Модальне вікно налаштування графіка роботи -->
    <div id="scheduleModal" class="modal-overlay">
        <div class="modal-container" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title">⚙️ Графік роботи: <?= e($filterUser) ?></h3>
                <button class="modal-close" onclick="closeScheduleModal()">&times;</button>
            </div>
            
            <div class="modal-body" style="padding: 20px; background-color: var(--card-color);">
                
                <!-- Кнопки швидкого перемикання між формою призначення та формою додавання шаблону -->
                <div style="display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
                    <button type="button" id="tab_assign_btn" class="tab-btn active" style="padding: 6px 12px; font-size: 13px;" onclick="showModalTab('assign')">Призначити графік</button>
                    <button type="button" id="tab_create_btn" class="tab-btn" style="padding: 6px 12px; font-size: 13px;" onclick="showModalTab('create')">➕ Створити тип графіка</button>
                </div>
                
                <!-- ВКЛАДКА 1: Призначення графіка користувачу -->
                <div id="tab_assign_content">
                    <form method="POST" action="user_analytics.php?username=<?= urlencode($filterUser) ?>" style="margin: 0;">
                        <input type="hidden" name="action" value="save_schedule">
                        <input type="hidden" name="username" value="<?= e($filterUser) ?>">
                        
                        <div class="form-group">
                            <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 13px; color: var(--secondary-color);">ОБЕРІТЬ ШАБЛОН ГРАФІКА</label>
                            <select name="selected_template" id="selected_template" class="form-control" style="width: 100%;" onchange="toggleTemplateOrCustom()">
                                <option value="custom" <?= ($userSchedule && $userSchedule['template_id'] === null) ? 'selected' : '' ?>>— Індивідуальні налаштування —</option>
                                <?php foreach ($templates as $tmpl): ?>
                                    <option value="<?= $tmpl['id'] ?>" <?= ($userSchedule && (int)$userSchedule['template_id'] === (int)$tmpl['id']) ? 'selected' : '' ?>>
                                        <?= e($tmpl['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Блок індивідуальних налаштувань (показується тільки якщо обрано custom) -->
                        <div id="custom_schedule_fields" style="display: <?= ($userSchedule && $userSchedule['template_id'] !== null) ? 'none' : 'block' ?>; margin-top: 15px; border-top: 1px dashed var(--border-color); padding-top: 15px;">
                            
                            <div class="form-group">
                                <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 13px; color: var(--secondary-color);">ТИП ІНДИВІДУАЛЬНОГО ГРАФІКА</label>
                                <select name="schedule_type" id="schedule_type" class="form-control" style="width: 100%;" onchange="toggleScheduleFields()">
                                    <option value="mon_fri" <?= ($userSchedule && $userSchedule['schedule_type'] === 'mon_fri') ? 'selected' : '' ?>>Стандартний (Пн-Пт)</option>
                                    <option value="custom" <?= ($userSchedule && $userSchedule['schedule_type'] === 'custom') ? 'selected' : '' ?>>Індивідуальні дні та години</option>
                                    <option value="shift_24" <?= ($userSchedule && $userSchedule['schedule_type'] === 'shift_24') ? 'selected' : '' ?>>Змінний (Доба через три / 24-72)</option>
                                    <option value="shift_12" <?= ($userSchedule && $userSchedule['schedule_type'] === 'shift_12') ? 'selected' : '' ?>>Змінний (День-Ніч-48 / 12-12-48)</option>
                                    <option value="always_off" <?= ($userSchedule && $userSchedule['schedule_type'] === 'always_off') ? 'selected' : '' ?>>Всі години позаробочі (Always Off)</option>
                                </select>
                            </div>

                            <!-- Робочі години -->
                            <div id="hours_fields" class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 15px;">
                                <div>
                                    <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 12px; color: var(--secondary-color);">ЧАС ПОЧАТКУ</label>
                                    <input type="time" name="work_start" class="form-control" style="width: 100%;" value="<?= $userSchedule ? e($userSchedule['work_start']) : '08:30' ?>">
                                </div>
                                <div>
                                    <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 12px; color: var(--secondary-color);">ЧАС ЗАКІНЧЕННЯ</label>
                                    <input type="time" name="work_end" class="form-control" style="width: 100%;" value="<?= $userSchedule ? e($userSchedule['work_end']) : '17:30' ?>">
                                </div>
                            </div>

                            <!-- Вибір робочих днів -->
                            <div id="days_fields" class="form-group" style="margin-top: 15px;">
                                <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 12px; color: var(--secondary-color);">РОБОЧІ ДНІ</label>
                                <div class="checkbox-list" style="max-height: 120px; border: 1px solid var(--border-color); border-radius: 6px; padding: 8px; background: rgba(0,0,0,0.01);">
                                    <?php
                                    $daysMap = [1 => 'Понеділок', 2 => 'Вівторок', 3 => 'Середа', 4 => 'Четвер', 5 => 'П\'ятниця', 6 => 'Субота', 7 => 'Неділя'];
                                    $selectedDays = $userSchedule ? explode(',', $userSchedule['work_days']) : [1, 2, 3, 4, 5];
                                    foreach ($daysMap as $num => $dayName):
                                    ?>
                                        <label class="checkbox-item" style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 13px; cursor: pointer;">
                                            <input type="checkbox" name="work_days[]" value="<?= $num ?>" <?= in_array($num, $selectedDays) ? 'checked' : '' ?>>
                                            <span><?= $dayName ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Дата відліку змін -->
                            <div id="shift_fields" class="form-group" style="margin-top: 15px;">
                                <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 12px; color: var(--secondary-color);">ДАТА ПОЧАТКУ ВІДЛІКУ ЗМІН</label>
                                <input type="date" name="ref_date" class="form-control" style="width: 100%;" value="<?= $userSchedule ? e($userSchedule['ref_date']) : date('Y-m-d') ?>">
                                <span style="font-size: 11px; color: var(--secondary-color); display: block; margin-top: 4px;">Від цієї дати буде відраховуватися цикл робочих та вихідних змін.</span>
                            </div>
                        </div>
                        
                        <div class="modal-footer" style="padding: 16px 0 0 0; margin-top: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px; background-color: transparent;">
                            <button type="button" class="btn-action btn-secondary" style="padding: 8px 14px; font-size: 13px;" onclick="closeScheduleModal()">Скасувати</button>
                            <button type="submit" class="btn-action" style="padding: 8px 16px; font-size: 13px;">Зберегти зміни</button>
                        </div>
                    </form>
                </div>
                
                <!-- ВКЛАДКА 2: Створення нового шаблону графіка -->
                <div id="tab_create_content" style="display: none;">
                    <form method="POST" action="user_analytics.php?username=<?= urlencode($filterUser) ?>" style="margin: 0;">
                        <input type="hidden" name="action" value="add_template">
                        
                        <div class="form-group">
                            <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 13px; color: var(--secondary-color);">НАЗВА ТИПУ ГРАФІКА</label>
                            <input type="text" name="template_name" class="form-control" style="width: 100%;" placeholder="Наприклад: Зміна охорони (12-36)" required>
                        </div>
                        
                        <div class="form-group" style="margin-top: 15px;">
                            <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 13px; color: var(--secondary-color);">БАЗОВИЙ ТИП</label>
                            <select name="schedule_type" id="add_schedule_type" class="form-control" style="width: 100%;" onchange="toggleAddScheduleFields()">
                                <option value="mon_fri">Стандартний (Пн-Пт)</option>
                                <option value="custom">Індивідуальні дні та години</option>
                                <option value="shift_24">Змінний (Доба через три / 24-72)</option>
                                <option value="shift_12">Змінний (День-Ніч-48 / 12-12-48)</option>
                                <option value="always_off">Всі години позаробочі (Always Off)</option>
                            </select>
                        </div>

                        <!-- Робочі години для шаблону -->
                        <div id="add_hours_fields" class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 15px;">
                            <div>
                                <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 12px; color: var(--secondary-color);">ЧАС ПОЧАТКУ</label>
                                <input type="time" name="work_start" class="form-control" style="width: 100%;" value="08:30">
                            </div>
                            <div>
                                <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 12px; color: var(--secondary-color);">ЧАС ЗАКІНЧЕННЯ</label>
                                <input type="time" name="work_end" class="form-control" style="width: 100%;" value="17:30">
                            </div>
                        </div>

                        <!-- Робочі дні для шаблону -->
                        <div id="add_days_fields" class="form-group" style="margin-top: 15px; display: none;">
                            <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 12px; color: var(--secondary-color);">РОБОЧІ ДНІ</label>
                            <div class="checkbox-list" style="max-height: 120px; border: 1px solid var(--border-color); border-radius: 6px; padding: 8px; background: rgba(0,0,0,0.01);">
                                <?php foreach ($daysMap as $num => $dayName): ?>
                                    <label class="checkbox-item" style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 13px; cursor: pointer;">
                                        <input type="checkbox" name="work_days[]" value="<?= $num ?>" <?= ($num <= 5) ? 'checked' : '' ?>>
                                        <span><?= $dayName ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Дата відліку змін для шаблону -->
                        <div id="add_shift_fields" class="form-group" style="margin-top: 15px; display: none;">
                            <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 12px; color: var(--secondary-color);">ДАТА ПОЧАТКУ ВІДЛІКУ ЗМІН</label>
                            <input type="date" name="ref_date" class="form-control" style="width: 100%;" value="<?= date('Y-m-d') ?>">
                            <span style="font-size: 11px; color: var(--secondary-color); display: block; margin-top: 4px;">Від цієї дати буде відраховуватися цикл робочих та вихідних змін.</span>
                        </div>
                        
                        <div class="modal-footer" style="padding: 16px 0 0 0; margin-top: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px; background-color: transparent;">
                            <button type="button" class="btn-action btn-secondary" style="padding: 8px 14px; font-size: 13px;" onclick="closeScheduleModal()">Скасувати</button>
                            <button type="submit" class="btn-action" style="padding: 8px 16px; font-size: 13px;">➕ Додати тип</button>
                        </div>
                    </form>

                    <!-- Існуючі типи графіків -->
                    <div style="margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 15px;">
                        <h4 style="font-size: 11px; font-weight: 700; margin-bottom: 8px; color: var(--secondary-color); text-transform: uppercase;">ІСНУЮЧІ ТИПИ ГРАФІКІВ:</h4>
                        <div class="table-responsive" style="max-height: 150px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; background: rgba(0,0,0,0.01);">
                            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                                <tbody>
                                    <?php foreach ($templates as $tmpl): ?>
                                        <tr style="border-bottom: 1px solid var(--border-color);">
                                            <td style="padding: 8px 12px; font-weight: 500; color: var(--text-color);"><?= e($tmpl['name']) ?></td>
                                            <td style="padding: 8px 12px; text-align: right; white-space: nowrap;">
                                                <?php if ((int)$tmpl['id'] > 4): ?>
                                                    <form method="POST" action="user_analytics.php?username=<?= urlencode($filterUser) ?>" style="display: inline;" onsubmit="return confirm('Ви впевнені, що хочете видалити цей тип графіка? Користувачі з цим графіком будуть переведені на індивідуальні налаштування.')">
                                                        <input type="hidden" name="action" value="delete_template">
                                                        <input type="hidden" name="template_id" value="<?= $tmpl['id'] ?>">
                                                        <button type="submit" style="background: none; border: none; color: var(--error-color); cursor: pointer; font-size: 12px; font-weight: 600; padding: 0; text-decoration: none;">Видалити</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="color: var(--secondary-color); font-size: 11px;">системний</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <!-- Модальне вікно деталей сесії -->
    <div id="sessionDetailsModal" class="modal-overlay">
        <div class="modal-container" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">🔍 Деталі RDP сеансу</h3>
                <button class="modal-close" onclick="closeSessionDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 20px; background-color: var(--card-color);">
                <div id="session_details_content">
                    <!-- Заповнюється динамічно через JS -->
                </div>
                <div style="padding: 16px 0 0 0; margin-top: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; background-color: transparent;">
                    <button type="button" class="btn-action" style="padding: 8px 16px; font-size: 13px;" onclick="closeSessionDetailsModal()">Закрити</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JS Логіка для модального вікна -->
    <script>
    function openScheduleModal() {
        document.getElementById('scheduleModal').style.display = 'flex';
        toggleTemplateOrCustom();
    }
    
    function closeScheduleModal() {
        document.getElementById('scheduleModal').style.display = 'none';
    }
    
    function showModalTab(tabName) {
        var assignContent = document.getElementById('tab_assign_content');
        var createContent = document.getElementById('tab_create_content');
        var assignBtn = document.getElementById('tab_assign_btn');
        var createBtn = document.getElementById('tab_create_btn');
        
        if (tabName === 'assign') {
            assignContent.style.display = 'block';
            createContent.style.display = 'none';
            assignBtn.classList.add('active');
            createBtn.classList.remove('active');
        } else {
            assignContent.style.display = 'none';
            createContent.style.display = 'block';
            assignBtn.classList.remove('active');
            createBtn.classList.add('active');
            toggleAddScheduleFields();
        }
    }
    
    function toggleTemplateOrCustom() {
        var selected = document.getElementById('selected_template').value;
        var customFields = document.getElementById('custom_schedule_fields');
        if (selected === 'custom') {
            customFields.style.display = 'block';
            toggleScheduleFields();
        } else {
            customFields.style.display = 'none';
        }
    }
    
    function toggleScheduleFields() {
        var type = document.getElementById('schedule_type').value;
        var hoursFields = document.getElementById('hours_fields');
        var daysFields = document.getElementById('days_fields');
        var shiftFields = document.getElementById('shift_fields');
        
        if (type === 'mon_fri') {
            hoursFields.style.display = 'grid';
            daysFields.style.display = 'none';
            shiftFields.style.display = 'none';
        } else if (type === 'custom') {
            hoursFields.style.display = 'grid';
            daysFields.style.display = 'block';
            shiftFields.style.display = 'none';
        } else if (type === 'shift_24' || type === 'shift_12') {
            hoursFields.style.display = 'none';
            daysFields.style.display = 'none';
            shiftFields.style.display = 'block';
        } else if (type === 'always_off') {
            hoursFields.style.display = 'none';
            daysFields.style.display = 'none';
            shiftFields.style.display = 'none';
        }
    }
    
    function toggleAddScheduleFields() {
        var type = document.getElementById('add_schedule_type').value;
        var hoursFields = document.getElementById('add_hours_fields');
        var daysFields = document.getElementById('add_days_fields');
        var shiftFields = document.getElementById('add_shift_fields');
        
        if (type === 'mon_fri') {
            hoursFields.style.display = 'grid';
            daysFields.style.display = 'none';
            shiftFields.style.display = 'none';
        } else if (type === 'custom') {
            hoursFields.style.display = 'grid';
            daysFields.style.display = 'block';
            shiftFields.style.display = 'none';
        } else if (type === 'shift_24' || type === 'shift_12') {
            hoursFields.style.display = 'none';
            daysFields.style.display = 'none';
            shiftFields.style.display = 'block';
        } else if (type === 'always_off') {
            hoursFields.style.display = 'none';
            daysFields.style.display = 'none';
            shiftFields.style.display = 'none';
        }
    }
    
    // Close modal if clicked outside modal-container
    window.onclick = function(event) {
        var modalSched = document.getElementById('scheduleModal');
        var modalSess = document.getElementById('sessionDetailsModal');
        if (event.target == modalSched) {
            modalSched.style.display = "none";
        }
        if (event.target == modalSess) {
            modalSess.style.display = "none";
        }
    }

    const detailedSessions = <?= json_encode($detailedRows) ?>;
    
    function viewSessionDetails(id) {
        const sess = detailedSessions.find(s => s.id == id);
        if (!sess) return;
        
        let endTimeStr = sess.end_time ? formatDate(sess.end_time) : '<span style="color: var(--success-color); font-weight: 600; display: inline-flex; align-items: center;"><span class="active-dot" style="margin-right: 6px;"></span> Активна зараз</span>';
        let durationStr = formatDuration(sess.duration_seconds, sess.end_time === null);
        
        let alertBox = '';
        if (sess.is_outside) {
            alertBox = `
                <div class="alert alert-error" style="margin-bottom: 16px; display: flex; align-items: center; gap: 8px; font-weight: 600;">
                    <span>⚠️ Поза графіком роботи</span>
                </div>`;
        } else if (sess.badge_type === 'night') {
            alertBox = `
                <div class="alert" style="background-color: rgba(175, 82, 222, 0.1); color: #AF52DE; border: 1px solid rgba(175, 82, 222, 0.2); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; font-weight: 600;">
                    <span>🌙 Нічне підключення</span>
                </div>`;
        } else if (sess.badge_type === 'evening') {
            alertBox = `
                <div class="alert" style="background-color: rgba(255, 149, 0, 0.1); color: #FF9500; border: 1px solid rgba(255, 149, 0, 0.2); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; font-weight: 600;">
                    <span>🌆 Вечірнє підключення</span>
                </div>`;
        }
        
        document.getElementById('session_details_content').innerHTML = `
            ${alertBox}
            <div style="display: flex; flex-direction: column; gap: 12px; font-size: 14px;">
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                    <span style="color: var(--secondary-color);">Користувач:</span>
                    <strong style="color: var(--text-color);">${escapeHtml(sess.username)}</strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                    <span style="color: var(--secondary-color);">Сервер:</span>
                    <strong style="color: var(--text-color);">${escapeHtml(sess.server_name)}</strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                    <span style="color: var(--secondary-color);">ID Сесії:</span>
                    <code style="font-weight: 600;">${sess.session_id}</code>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                    <span style="color: var(--secondary-color);">IP-адреса:</span>
                    <code style="font-weight: 600;">${escapeHtml(sess.ip_address || 'Unknown')}</code>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                    <span style="color: var(--secondary-color);">Час підключення:</span>
                    <strong style="color: var(--text-color);">${formatDate(sess.start_time)}</strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                    <span style="color: var(--secondary-color);">Час відключення:</span>
                    <strong>${endTimeStr}</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding-bottom: 8px;">
                    <span style="color: var(--secondary-color);">Тривалість роботи:</span>
                    <strong style="color: var(--accent-color); font-size: 15px;">${durationStr}</strong>
                </div>
            </div>
        `;
        
        document.getElementById('sessionDetailsModal').style.display = 'flex';
    }
    
    function closeSessionDetailsModal() {
        document.getElementById('sessionDetailsModal').style.display = 'none';
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    
    function formatDate(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split(' ');
        if (parts.length !== 2) return dateStr;
        const dParts = parts[0].split('-');
        if (dParts.length !== 3) return dateStr;
        return `${dParts[2]}.${dParts[1]}.${dParts[0]} ${parts[1]}`;
    }
    
    function formatDuration(seconds, isActive) {
        if (isActive || seconds === null || seconds === undefined) return 'Активна';
        if (seconds < 60) return seconds + ' сек';
        const mins = Math.floor(seconds / 60);
        if (mins < 60) return mins + ' хв';
        const hours = Math.floor(mins / 60);
        const remMins = mins % 60;
        return hours + ' год ' + remMins + ' хв';
    }
    </script>
   <?php endif; ?>
</main>

<?php require 'includes/footer.php'; ?>
