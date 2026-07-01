<?php
/**
 * rdp_analytics.php — Детальна аналітика RDP підключень (Таблиця з гнучким пошуком, сортуванням та пагінацією)
 */
require_once 'config.php';
require_login();

$user = get_logged_in_user();
$allowedServers = get_user_servers();
$pdo = get_db_connection();

// Кешування графіків користувачів для перевірки роботи поза графіком
$userSchedules = [];
$schedStmt = $pdo->query("SELECT us.username,
                                 COALESCE(st.schedule_type, us.schedule_type) as schedule_type,
                                 COALESCE(st.work_start, us.work_start) as work_start,
                                 COALESCE(st.work_end, us.work_end) as work_end,
                                 COALESCE(st.work_days, us.work_days) as work_days,
                                 COALESCE(st.ref_date, us.ref_date) as ref_date
                          FROM user_schedules us
                          LEFT JOIN schedule_templates st ON us.template_id = st.id");
while ($row = $schedStmt->fetch()) {
    $userSchedules[$row['username']] = $row;
}

// Допоміжні посилання для збереження GET параметрів (визначено раніше для використання в редіректах)
$qs = $_GET;
unset($qs['export']);
if (!function_exists('build_qs')) {
    function build_qs(array $override = []): string {
        global $qs;
        return '?' . http_build_query(array_merge($qs, $override));
    }
}

// ── Обробка дій над сесіями (Завершення / Видалення) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (is_super_admin() && ($_SESSION['show_rdp_actions'] ?? 0) === 1) {
        $targetId = (int)($_POST['session_id'] ?? 0);
        if ($targetId > 0) {
            // Отримуємо сервер для перевірки доступу
            $stmt = $pdo->prepare("SELECT server_name, end_time, start_time FROM rdp_sessions WHERE id = ?");
            $stmt->execute([$targetId]);
            $sessionData = $stmt->fetch();

            if ($sessionData) {
                if ($_POST['action'] === 'delete_session') {
                    $pdo->prepare("DELETE FROM rdp_sessions WHERE id = ?")->execute([$targetId]);
                    header('Location: rdp_analytics.php' . build_qs());
                    exit;
                }
                if ($_POST['action'] === 'force_end') {
                    if ($sessionData['end_time'] === null) {
                        $now = date('Y-m-d H:i:s');
                        $duration = strtotime($now) - strtotime($sessionData['start_time']);
                        if ($duration < 0) $duration = 0;
                        $pdo->prepare("UPDATE rdp_sessions SET end_time = ?, duration_seconds = ? WHERE id = ?")
                            ->execute([$now, $duration, $targetId]);
                    }
                    header('Location: rdp_analytics.php' . build_qs());
                    exit;
                }
            }
        }
    }
}

// ── 1. Фільтрація, пошук та сортування ──────────────────
$filterServer = trim($_GET['server'] ?? '');
if ($filterServer !== '' && !in_array($filterServer, $allowedServers)) $filterServer = '';

$filterUser = trim($_GET['username'] ?? '');
$filterFrom = trim($_GET['date_from'] ?? '');
$filterTo   = trim($_GET['date_to'] ?? '');
$search     = trim($_GET['search'] ?? '');

$viewType   = trim($_GET['view'] ?? 'detailed');     // 'detailed' або 'summary'
$summaryGroup = trim($_GET['group_by'] ?? 'day');     // 'day', 'week', 'month'

// Сортування (тільки дозволені колонки для безпеки)
$allowedSort = ['username', 'server_name', 'session_id', 'start_time', 'end_time', 'duration_seconds', 'ip_address', 'period', 'session_count', 'total_duration'];
$sortBy = in_array($_GET['sort_by'] ?? '', $allowedSort) ? $_GET['sort_by'] : 'start_time';
$sortDir = strtoupper($_GET['sort_dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// Пагінація
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 100; // Кількість записів на сторінку
$offset = ($page - 1) * $limit;

// Побудова WHERE клаузи
$whereClauses = [];
$params = [];

$isSA = is_super_admin();
if (!$isSA) {
    if (empty($allowedServers)) {
        $whereClauses[] = '1=0';
    } elseif ($filterServer !== '') {
        $whereClauses[] = 'server_name = ?';
        $params[] = $filterServer;
    } else {
        $ph = implode(',', array_fill(0, count($allowedServers), '?'));
        $whereClauses[] = "server_name IN ($ph)";
        $params = array_merge($params, $allowedServers);
    }
} else {
    if ($filterServer !== '') {
        $whereClauses[] = 'server_name = ?';
        $params[] = $filterServer;
    }
}

if ($filterUser !== '') {
    $whereClauses[] = 'username LIKE ?';
    $params[] = "%$filterUser%";
}
if ($filterFrom !== '') {
    $whereClauses[] = 'start_time >= ?';
    $params[] = "$filterFrom 00:00:00";
}
if ($filterTo !== '') {
    $whereClauses[] = 'start_time <= ?';
    $params[] = "$filterTo 23:59:59";
}

// Загальний текстовий пошук по ключових полях (для обробки великої кількості даних)
if ($search !== '') {
    $whereClauses[] = '(username LIKE ? OR server_name LIKE ? OR ip_address LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// ── 2. Експорт у CSV (з урахуванням усіх фільтрів та пошуку) ──
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rdp_analytics_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    if ($viewType === 'summary') {
        fputcsv($out, ['Користувач', 'Період', 'Кількість сеансів', 'Загальний час', 'Час поза графіком'], ';');

        switch ($summaryGroup) {
            case 'week':  $periodExpr = "DATE_FORMAT(start_time,'%Y-W%u')"; break;
            case 'month': $periodExpr = "DATE_FORMAT(start_time,'%Y-%m')"; break;
            default:      $periodExpr = "DATE(start_time)"; break;
        }
        $sql = "SELECT username, $periodExpr AS period, COUNT(*) AS session_count,
                       SUM(duration_seconds) AS total_duration
                FROM rdp_sessions $whereSql
                GROUP BY username, period ORDER BY period DESC, username";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $subStmt = $pdo->prepare("SELECT start_time, end_time FROM rdp_sessions WHERE username = ? AND $periodExpr = ?");
        
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userSched = $userSchedules[$r['username']] ?? null;
            $outsideSeconds = 0;
            
            $subStmt->execute([$r['username'], $r['period']]);
            while ($subRow = $subStmt->fetch()) {
                $outsideSeconds += get_session_outside_duration($subRow['start_time'], $subRow['end_time'], $userSched);
            }
            
            fputcsv($out, [
                $r['username'],
                $r['period'],
                $r['session_count'],
                format_duration((int)$r['total_duration']),
                format_duration($outsideSeconds)
            ], ';');
        }
    } else {
        fputcsv($out, ['Сервер', 'Користувач', 'Сесія ID', 'Початок', 'Кінець', 'Тривалість', 'IP-адреса', 'Позначка'], ';');

        $sql = "SELECT server_name, username, session_id, start_time, end_time,
                       duration_seconds, ip_address
                FROM rdp_sessions $whereSql ORDER BY $sortBy $sortDir";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userSched = $userSchedules[$r['username']] ?? null;
            $isOutside = is_outside_schedule($r['start_time'], $userSched);
            $startHour = (int)date('H', strtotime($r['start_time']));
            $tag = '';
            
            if ($isOutside) {
                $tag = 'Поза графіком';
            } elseif ($startHour >= 22 || $startHour < 6) {
                $tag = 'Нічна';
            } elseif ($startHour >= 18 && $startHour < 22) {
                $tag = 'Вечірня';
            }

            fputcsv($out, [
                $r['server_name'],
                $r['username'],
                $r['session_id'],
                $r['start_time'],
                $r['end_time'] ?? '',
                format_duration((int)$r['duration_seconds']),
                $r['ip_address'] ?? '',
                $tag
            ], ';');
        }
    }
    fclose($out);
    exit;
}

// ── 3. Запит до БД (Пагінація та Агрегація) ───────────
$totalRecords = 0;
$totalSessions = 0;
$totalSeconds  = 0;
$rows = [];

if ($viewType === 'summary') {
    switch ($summaryGroup) {
        case 'week':  $periodExpr = "DATE_FORMAT(start_time,'%Y-W%u')"; break;
        case 'month': $periodExpr = "DATE_FORMAT(start_time,'%Y-%m')"; break;
        default:      $periodExpr = "DATE(start_time)"; break;
    }
    // Рахуємо загальну кількість для пагінації у зведеному режимі
    $countSql = "SELECT COUNT(DISTINCT username, $periodExpr) FROM rdp_sessions $whereSql";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalRecords = (int)$stmt->fetchColumn();

    // Запит даних
    // Визначити поле сортування для зведеного режиму
    $summaryAllowed = ['username' => 'username', 'period' => 'period', 'session_count' => 'session_count', 'total_duration' => 'total_duration'];
    $summarySort = isset($summaryAllowed[$sortBy]) ? $summaryAllowed[$sortBy] : 'period';
    $summarySortDir = $sortDir;

    $sql = "SELECT username, $periodExpr AS period, COUNT(*) AS session_count,
                   SUM(duration_seconds) AS total_duration
            FROM rdp_sessions $whereSql
            GROUP BY username, period 
            ORDER BY $summarySort $summarySortDir 
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Розрахунок KPI підсумків (по всьому відфільтрованому набору)
    $sumSql = "SELECT COUNT(*) as sess_count, SUM(duration_seconds) as total_dur FROM rdp_sessions $whereSql";
    $stmt = $pdo->prepare($sumSql);
    $stmt->execute($params);
    $sumRow = $stmt->fetch();
    $totalSessions = (int)$sumRow['sess_count'];
    $totalSeconds  = (int)$sumRow['total_dur'];
} else {
    // Рахуємо загальну кількість записів
    $countSql = "SELECT COUNT(*) FROM rdp_sessions $whereSql";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalRecords = (int)$stmt->fetchColumn();

    // Запит даних
    $sql = "SELECT id, server_name, username, session_id, start_time, end_time, duration_seconds, ip_address
            FROM rdp_sessions $whereSql 
            ORDER BY $sortBy $sortDir 
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // KPI підсумки
    $sumSql = "SELECT COUNT(*), SUM(duration_seconds) FROM rdp_sessions $whereSql";
    $stmt = $pdo->prepare($sumSql);
    $stmt->execute($params);
    $sumRow = $stmt->fetch(PDO::FETCH_NUM);
    $totalSessions = (int)$sumRow[0];
    $totalSeconds  = (int)$sumRow[1];
}

$totalPages = max(1, ceil($totalRecords / $limit));
$totalHours = round($totalSeconds / 3600, 1);

// ── 4. Шаблон і допоміжні лінки ────────────────────────
$pageTitle = 'Аналітика RDP'; $activePage = 'rdp';
require 'includes/header.php';



// Функція генерації заголовка таблиці з посиланням на сортування
function render_sort_link(string $colName, string $title) {
    global $sortBy, $sortDir;
    $isCurrent = ($sortBy === $colName);
    $nextDir = ($isCurrent && $sortDir === 'DESC') ? 'ASC' : 'DESC';
    $arrow = '';
    if ($isCurrent) {
        $arrow = ($sortDir === 'ASC') ? ' ▴' : ' ▾';
    }
    echo '<a href="' . e(build_qs(['sort_by' => $colName, 'sort_dir' => $nextDir, 'page' => 1])) . '" style="text-decoration:none; color:inherit; font-weight:600;">' . e($title) . $arrow . '</a>';
}
?>

<main>
    <!-- Заголовок та експорт -->
    <div class="page-header">
        <h2>Журнал сеансів та облік часу RDP</h2>
        <a href="<?= e(build_qs(['export' => 'csv'])) ?>" class="btn-action btn-secondary" style="background:#fff; color:var(--text-color); border: 1px solid var(--border-color); text-decoration:none; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
            <span>⬇</span> Експорт у CSV
        </a>
    </div>

    <!-- Картка фільтрації та пошуку -->
    <div class="card">
        <form method="get" class="filter-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) 120px; gap: 16px; align-items: end;">
            <input type="hidden" name="view" value="<?= e($viewType) ?>">
            <input type="hidden" name="group_by" value="<?= e($summaryGroup) ?>">
            <input type="hidden" name="sort_by" value="<?= e($sortBy) ?>">
            <input type="hidden" name="sort_dir" value="<?= e($sortDir) ?>">

            <div class="filter-group">
                <label>Пошук (Користувач, Сервер, IP)</label>
                <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Введіть фразу...">
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
                <label>Користувач (фільтр)</label>
                <input type="text" name="username" class="form-control" value="<?= e($filterUser) ?>" placeholder="Ім'я">
            </div>

            <div class="filter-group">
                <label>Дата з</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($filterFrom) ?>">
            </div>

            <div class="filter-group">
                <label>Дата по</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($filterTo) ?>">
            </div>

            <button type="submit" class="btn-action" style="height: 38px; width: 100%;">Пошук</button>
        </form>
    </div>

    <!-- Cupertino вкладки для режимів перегляду -->
    <div class="tabs-container">
        <a href="<?= e(build_qs(['view' => 'detailed', 'page' => 1])) ?>" class="tab-btn <?= $viewType === 'detailed' ? 'active' : '' ?>" style="text-decoration:none; display:inline-block;">Детальний журнал сесій</a>
        <a href="<?= e(build_qs(['view' => 'summary', 'group_by' => $summaryGroup, 'page' => 1])) ?>" class="tab-btn <?= $viewType === 'summary' ? 'active' : '' ?>" style="text-decoration:none; display:inline-block;">Зведений облік часу</a>
    </div>

    <!-- Якщо зведений режим, то показуємо другий рівень групування -->
    <?php if ($viewType === 'summary'): ?>
        <div class="tabs-container" style="margin-top: -10px; margin-bottom: 20px; background: rgba(118,118,128,0.06);">
            <a href="<?= e(build_qs(['group_by' => 'day', 'page' => 1])) ?>" class="tab-btn <?= $summaryGroup === 'day' ? 'active' : '' ?>" style="text-decoration:none; font-size:11px; padding: 4px 12px;">По днях</a>
            <a href="<?= e(build_qs(['group_by' => 'week', 'page' => 1])) ?>" class="tab-btn <?= $summaryGroup === 'week' ? 'active' : '' ?>" style="text-decoration:none; font-size:11px; padding: 4px 12px;">По тижнях</a>
            <a href="<?= e(build_qs(['group_by' => 'month', 'page' => 1])) ?>" class="tab-btn <?= $summaryGroup === 'month' ? 'active' : '' ?>" style="text-decoration:none; font-size:11px; padding: 4px 12px;">По місяцях</a>
        </div>
    <?php endif; ?>

    <!-- Зведена статистика під фільтрами -->
    <div class="card" style="padding: 12px 20px; margin-bottom: 20px; display: flex; gap: 32px; font-size: 14px; background: #fff;">
        <div>Всього знайдено записів: <strong><?= $totalRecords ?></strong></div>
        <div>Всього сесій: <strong><?= $totalSessions ?></strong></div>
        <div>Сумарний час: <strong style="color:var(--accent-color);"><?= $totalHours ?> год</strong></div>
    </div>

    <!-- Головна таблиця даних -->
    <div class="card">
        <?php if (empty($rows)): ?>
            <div class="no-data-msg">Записи відсутні за вказаними параметрами пошуку</div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <?php if ($viewType === 'summary'): ?>
                            <tr>
                                <th style="white-space: nowrap;"><?php render_sort_link('username', 'Користувач'); ?></th>
                                <th style="white-space: nowrap;"><?php render_sort_link('period', 'Період'); ?></th>
                                <th style="white-space: nowrap; text-align: center;"><?php render_sort_link('session_count', 'Кількість сесій'); ?></th>
                                <th style="white-space: nowrap; text-align: right;"><?php render_sort_link('total_duration', 'Напрацьований час'); ?></th>
                                <th style="white-space: nowrap; text-align: right;">Час поза графіком</th>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <th style="white-space: nowrap;"><?php render_sort_link('username', 'Користувач'); ?></th>
                                <th style="white-space: nowrap;"><?php render_sort_link('server_name', 'Сервер'); ?></th>
                                <th style="white-space: nowrap;"><?php render_sort_link('session_id', 'ID сесії'); ?></th>
                                <th style="white-space: nowrap;"><?php render_sort_link('start_time', 'Час підключення'); ?></th>
                                <th style="white-space: nowrap;"><?php render_sort_link('end_time', 'Час відключення'); ?></th>
                                <th style="white-space: nowrap; text-align: right;"><?php render_sort_link('duration_seconds', 'Тривалість'); ?></th>
                                <th style="white-space: nowrap;"><?php render_sort_link('ip_address', 'IP-адреса'); ?></th>
                                <th style="white-space: nowrap;">Позначка</th>
                                <?php if (is_super_admin() && ($_SESSION['show_rdp_actions'] ?? 0) === 1): ?>
                                    <th style="white-space: nowrap;">Дія</th>
                                <?php endif; ?>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php if ($viewType === 'summary'): ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $outsideSeconds = 0;
                                $userSched = $userSchedules[$r['username']] ?? null;
                                $subStmt = $pdo->prepare("SELECT start_time, end_time FROM rdp_sessions WHERE username = ? AND $periodExpr = ?");
                                $subStmt->execute([$r['username'], $r['period']]);
                                while ($subRow = $subStmt->fetch()) {
                                    $outsideSeconds += get_session_outside_duration($subRow['start_time'], $subRow['end_time'], $userSched);
                                }
                                ?>
                                <tr>
                                    <td style="white-space: nowrap;"><a href="user_analytics.php?username=<?= urlencode($r['username']) ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 700;" title="Дивитись аналітику користувача"><?= e($r['username']) ?> 👤</a></td>
                                    <td style="white-space: nowrap;"><code><?= e($r['period']) ?></code></td>
                                    <td style="white-space: nowrap; text-align: center;"><?= $r['session_count'] ?></td>
                                    <td style="white-space: nowrap; text-align: right; font-weight: 700;"><?= format_duration((int)$r['total_duration']) ?></td>
                                    <td style="white-space: nowrap; text-align: right; font-weight: 700; color: <?= $outsideSeconds > 0 ? 'var(--error-color)' : 'var(--text-color)' ?>;"><?= format_duration($outsideSeconds) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $userSched = $userSchedules[$r['username']] ?? null;
                                $isOutside = is_outside_schedule($r['start_time'], $userSched);
                                $startHour = (int)date('H', strtotime($r['start_time']));
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
                                <tr class="<?= $rowClass ?>">
                                    <td style="white-space: nowrap;"><a href="user_analytics.php?username=<?= urlencode($r['username']) ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 700;" title="Дивитись аналітику користувача"><?= e($r['username']) ?> 👤</a></td>
                                    <td style="white-space: nowrap;"><span class="srv-badge"><?= e($r['server_name']) ?></span></td>
                                    <td style="white-space: nowrap;"><code><?= e($r['session_id']) ?></code></td>
                                    <td style="white-space: nowrap;"><?= date('d.m.Y H:i:s', strtotime($r['start_time'])) ?></td>
                                    <td style="white-space: nowrap;">
                                        <?php if ($r['end_time'] === null): ?>
                                            <span style="display:inline-flex; align-items:center; color: var(--success-color); font-weight: 600;">
                                                <span class="active-dot" style="margin-right: 6px;"></span> Активна зараз
                                            </span>
                                        <?php else: ?>
                                            <?= date('d.m.Y H:i:s', strtotime($r['end_time'])) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap; text-align: right; font-weight: 600; color: var(--text-color);">
                                        <?= format_duration($r['end_time'] === null ? null : (int)$r['duration_seconds']) ?>
                                    </td>
                                    <td style="white-space: nowrap;"><small style="color:var(--secondary-text);"><?= e($r['ip_address'] ?? 'Unknown') ?></small></td>
                                    <td style="white-space: nowrap;"><?= $badge ?></td>
                                    <?php if (is_super_admin() && ($_SESSION['show_rdp_actions'] ?? 0) === 1): ?>
                                        <td style="white-space: nowrap;">
                                            <?php if ($r['end_time'] === null): ?>
                                                <form method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm('Ви впевнені, що хочете завершити цей сеанс вручну?')">
                                                    <input type="hidden" name="action" value="force_end">
                                                    <input type="hidden" name="session_id" value="<?= $r['id'] ?>">
                                                    <button type="submit" class="btn-edit-perm" style="padding: 2px 6px; font-size: 11px; margin-right: 4px;">Завершити</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm('Ви впевнені, що хочете видалити цей запис про сеанс?')">
                                                <input type="hidden" name="action" value="delete_session">
                                                <input type="hidden" name="session_id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="btn-delete" style="padding: 2px 6px; font-size: 11px;">Видалити</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Блок пагінації (Cupertino стилістика) -->
            <?php if ($totalPages > 1): ?>
                <div style="margin-top: 24px; display: flex; justify-content: space-between; align-items: center; font-size: 13px;">
                    <div style="color: var(--secondary-text);">
                        Показано сторінку <strong><?= $page ?></strong> з <strong><?= $totalPages ?></strong> (всього записів: <?= $totalRecords ?>)
                    </div>
                    <div style="display: inline-flex; gap: 4px;">
                        <?php if ($page > 1): ?>
                            <a href="<?= e(build_qs(['page' => $page - 1])) ?>" class="btn-action btn-secondary" style="padding: 6px 12px; font-size: 12px; text-decoration: none; display: inline-block; background:#fff; color:var(--text-color); border:1px solid var(--border-color);">Попередня</a>
                        <?php endif; ?>

                        <?php 
                        // Розрахунок діапазону сторінок для відображення
                        $range = 2;
                        $startPage = max(1, $page - $range);
                        $endPage = min($totalPages, $page + $range);

                        if ($startPage > 1) {
                            echo '<a href="' . e(build_qs(['page' => 1])) . '" style="padding: 6px 10px; text-decoration:none; color:var(--secondary-text);">1</a>';
                            if ($startPage > 2) echo '<span style="padding: 6px 4px; color:var(--secondary-text);">...</span>';
                        }

                        for ($i = $startPage; $i <= $endPage; $i++) {
                            if ($i === $page) {
                                echo '<span style="padding: 6px 12px; background: var(--accent-color); color: #fff; border-radius: 6px; font-weight: 600;">' . $i . '</span>';
                            } else {
                                echo '<a href="' . e(build_qs(['page' => $i])) . '" style="padding: 6px 10px; text-decoration:none; color:var(--text-color);">' . $i . '</a>';
                            }
                        }

                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span style="padding: 6px 4px; color:var(--secondary-text);">...</span>';
                            echo '<a href="' . e(build_qs(['page' => $totalPages])) . '" style="padding: 6px 10px; text-decoration:none; color:var(--secondary-text);">' . $totalPages . '</a>';
                        }
                        ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= e(build_qs(['page' => $page + 1])) ?>" class="btn-action btn-secondary" style="padding: 6px 12px; font-size: 12px; text-decoration: none; display: inline-block; background:#fff; color:var(--text-color); border:1px solid var(--border-color);">Наступна</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php require 'includes/footer.php'; ?>
