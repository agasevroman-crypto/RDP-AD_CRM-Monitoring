<?php
/**
 * dashboard.php — Головна панель (Облік робочого часу та моніторинг)
 */
require_once 'config.php';
require_login();

$user            = get_logged_in_user();
$allowedServers  = get_user_servers();
$pdo             = get_db_connection();
$selectedServer  = trim($_GET['server'] ?? '');
if ($selectedServer !== '' && !in_array($selectedServer, $allowedServers)) $selectedServer = '';

$isSA   = is_super_admin();
$rdpF   = build_server_filter('server_name', $allowedServers, $selectedServer, $isSA);
$adF    = build_server_filter('dc_name',     $allowedServers, $selectedServer, $isSA);

// ── 1. KPI Метрики (Поточний місяць) ───────────────────
$activeSessionsCount = 0;
$totalHoursMonth     = 0;
$avgHoursDay         = 0;
$activeUsersMonth    = 0;

if (!empty($allowedServers) || $isSA) {
    // Активні сесії (зараз на серверах)
    $s = $pdo->prepare("SELECT COUNT(*) FROM rdp_sessions WHERE end_time IS NULL {$rdpF['sql']}");
    $s->execute($rdpF['params']);
    $activeSessionsCount = (int)$s->fetchColumn();

    // Загальний час за поточний місяць
    $s = $pdo->prepare("SELECT COALESCE(SUM(duration_seconds), 0) FROM rdp_sessions WHERE start_time >= DATE_FORMAT(NOW(),'%Y-%m-01') {$rdpF['sql']}");
    $s->execute($rdpF['params']);
    $totalSecMonth = (int)$s->fetchColumn();
    $totalHoursMonth = round($totalSecMonth / 3600, 1);

    // Кількість активних співробітників за місяць
    $s = $pdo->prepare("SELECT COUNT(DISTINCT username) FROM rdp_sessions WHERE start_time >= DATE_FORMAT(NOW(),'%Y-%m-01') {$rdpF['sql']}");
    $s->execute($rdpF['params']);
    $activeUsersMonth = (int)$s->fetchColumn();

    // Середній час роботи за день (серед днів, коли була активність)
    $s = $pdo->prepare("
        SELECT AVG(daily_seconds) FROM (
            SELECT DATE(start_time) as d, SUM(duration_seconds) as daily_seconds
            FROM rdp_sessions 
            WHERE start_time >= DATE_FORMAT(NOW(),'%Y-%m-01') {$rdpF['sql']}
            GROUP BY DATE(start_time)
        ) as daily_totals
    ");
    $s->execute($rdpF['params']);
    $avgSecDay = (float)$s->fetchColumn();
    $avgHoursDay = round($avgSecDay / 3600, 1);
}

// ── 2. Графік робочого часу за останні 7 днів ─────────
$chartDays = []; $chartHours = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-{$i} days"));
    $label = date('d.m',   strtotime("-{$i} days"));
    $chartDays[] = $label;
    $hours = 0;
    if (!empty($allowedServers) || $isSA) {
        $p = array_merge([$date], $rdpF['params']);
        $s = $pdo->prepare("SELECT COALESCE(SUM(duration_seconds), 0) FROM rdp_sessions WHERE DATE(start_time)=? {$rdpF['sql']}");
        $s->execute($p);
        $hours = round((int)$s->fetchColumn() / 3600, 1);
    }
    $chartHours[] = $hours;
}
$maxH = max($chartHours) ?: 8;

// ── 3. Облік часу по ВСІХ користувачах за місяць ──────
$monthlyWorkTime = [];
if (!empty($allowedServers) || $isSA) {
    $s = $pdo->prepare("
        SELECT username, 
               COUNT(*) as sessions_count,
               SUM(duration_seconds) AS total_sec 
        FROM rdp_sessions 
        WHERE start_time >= DATE_FORMAT(NOW(),'%Y-%m-01') {$rdpF['sql']}
        GROUP BY username 
        ORDER BY total_sec DESC
    ");
    $s->execute($rdpF['params']);
    $monthlyWorkTime = $s->fetchAll();
}

// ── 4. Стрічка останніх подій RDP та AD ───────────────
$recentRdp = $recentAd = [];
if (!empty($allowedServers) || $isSA) {
    $s = $pdo->prepare("SELECT timestamp, server_name, username, event_type, ip_address FROM rdp_events WHERE 1=1 {$rdpF['sql']} ORDER BY timestamp DESC LIMIT 5");
    $s->execute($rdpF['params']);
    $recentRdp = $s->fetchAll();

    $s = $pdo->prepare("SELECT timestamp, dc_name, event_id, action_type, target_user, caller_user FROM ad_events WHERE 1=1 {$adF['sql']} ORDER BY timestamp DESC LIMIT 5");
    $s->execute($adF['params']);
    $recentAd = $s->fetchAll();
}

// ── Рендеринг ─────────────────────────────────────────
$pageTitle  = 'Панель управління';
$activePage = 'dashboard';
require 'includes/header.php';
?>

<main>
    <!-- Заголовок сторінки та фільтр -->
    <div class="page-title-section">
        <h2>Облік робочого часу RDP</h2>
        <form class="filter-form">
            <select name="server" onchange="this.form.submit()">
                <option value="">Всі доступні сервери</option>
                <?php foreach ($allowedServers as $sv): ?>
                    <option value="<?= e($sv) ?>" <?= $selectedServer === $sv ? 'selected' : '' ?>><?= e($sv) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (empty($allowedServers) && !$isSA): ?>
        <div class="card" style="text-align:center;padding:48px 20px;">
            <span style="font-size:48px">🖥️</span>
            <h3 style="margin-top:16px;font-weight:600">Немає призначених серверів</h3>
            <p style="color:var(--secondary-text);margin-top:8px;font-size:14px">Супер Адмін ще не призначив вам сервери для моніторингу.</p>
        </div>
    <?php else: ?>

    <!-- KPI Метрики -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-title">Працюють зараз (сесій)</div>
            <div class="metric-value-row">
                <div class="metric-value">
                    <?php if ($activeSessionsCount > 0): ?>
                        <span class="active-dot"></span>
                    <?php endif; ?>
                    <?= $activeSessionsCount ?>
                </div>
                <span class="metric-badge green">Online</span>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Напрацьовано за місяць</div>
            <div class="metric-value-row">
                <div class="metric-value"><?= $totalHoursMonth ?> <span style="font-size:16px;font-weight:500">год</span></div>
                <span class="metric-badge blue">Цей місяць</span>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Працівників цього місяця</div>
            <div class="metric-value-row">
                <div class="metric-value"><?= $activeUsersMonth ?></div>
                <span class="metric-badge blue">Активні</span>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-title">Середній час / день</div>
            <div class="metric-value-row">
                <div class="metric-value"><?= $avgHoursDay ?> <span style="font-size:16px;font-weight:500">год</span></div>
                <span class="metric-badge blue">Середнє</span>
            </div>
        </div>
    </div>

    <!-- Основний блок графіків та таблиці обліку часу -->
    <div class="content-row" style="grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px;">
        
        <!-- Картка обліку робочого часу за поточний місяць по всіх співробітниках -->
        <div class="card" style="display: flex; flex-direction: column;">
            <div class="card-header">
                <div class="card-title">Облік напрацьованого часу за місяць (всього користувачів: <?= count($monthlyWorkTime) ?>)</div>
            </div>
            <div style="flex-grow: 1; overflow-y: auto; max-height: 380px; padding-right: 4px;">
                <?php if (empty($monthlyWorkTime)): ?>
                    <div class="no-data-msg">Даних за поточний місяць немає</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="padding: 10px 12px; font-size: 11px;">Співробітник</th>
                                    <th style="padding: 10px 12px; font-size: 11px; text-align: center;">Кількість сесій</th>
                                    <th style="padding: 10px 12px; font-size: 11px;">Прогрес (норма 160 год)</th>
                                    <th style="padding: 10px 12px; font-size: 11px; text-align: right;">Загальний час</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthlyWorkTime as $row): 
                                    $hours = round($row['total_sec'] / 3600, 1);
                                    // Відсоток від норми у 160 робочих годин
                                    $pct = min(100, round(($hours / 160) * 100));
                                    $barColor = 'var(--accent-color)';
                                    if ($pct >= 100) $barColor = 'var(--success-color)';
                                    elseif ($pct < 40) $barColor = '#FF9500'; // Помаранчевий для малої кількості годин
                                ?>
                                    <tr>
                                        <td style="padding: 12px; font-weight: 600; color: var(--text-color);">
                                            <?= e($row['username']) ?>
                                        </td>
                                        <td style="padding: 12px; text-align: center; color: var(--secondary-text);">
                                            <?= $row['sessions_count'] ?>
                                        </td>
                                        <td style="padding: 12px; width: 40%;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <div style="flex-grow: 1; height: 8px; background: var(--bg-color); border-radius: 4px; overflow: hidden;">
                                                    <div style="width: <?= $pct ?>%; height: 100%; background: <?= $barColor ?>; border-radius: 4px; transition: width 0.3s;"></div>
                                                </div>
                                                <span style="font-size: 11px; color: var(--secondary-text); width: 30px; text-align: right;"><?= $pct ?>%</span>
                                            </div>
                                        </td>
                                        <td style="padding: 12px; text-align: right; font-weight: 700; color: var(--text-color);">
                                            <?= $hours ?> год
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Картка: Навантаження (Години роботи за 7 днів) -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">Динаміка роботи за 7 днів (год)</div>
            </div>
            <div style="padding-top: 10px;">
                <svg class="chart-svg" viewBox="0 0 500 200">
                    <line x1="40" y1="160" x2="480" y2="160" class="chart-axis-line"/>
                    <line x1="40" y1="100" x2="480" y2="100" class="chart-axis-line" stroke-dasharray="4"/>
                    <line x1="40" y1="40"  x2="480" y2="40"  class="chart-axis-line" stroke-dasharray="4"/>
                    <?php $w=40; $sp=20; $sx=60;
                    foreach ($chartHours as $i => $hVal):
                        $h = ($hVal / $maxH) * 110; if ($h < 4 && $hVal > 0) $h = 4;
                        $x = $sx + $i * ($w + $sp); $y = 160 - $h; ?>
                        <rect x="<?=$x?>" y="<?=$y?>" width="<?=$w?>" height="<?=$h?>" class="chart-bar" style="fill: var(--accent-color);"/>
                        <text x="<?=$x+$w/2?>" y="<?=$y-6?>" class="chart-value-label" style="font-size: 10px; font-weight: 600; text-anchor: middle;"><?= $hVal ?></text>
                        <text x="<?=$x+$w/2?>" y="176" class="chart-label" style="font-size: 10px; fill: var(--secondary-text); text-anchor: middle;"><?= $chartDays[$i] ?></text>
                    <?php endforeach; ?>
                </svg>
            </div>
        </div>
    </div>

    <!-- Стрічка останніх подій RDP та AD -->
    <div class="content-row">
        <div class="card">
            <div class="card-header">
                <div class="card-title">Останні події RDP</div>
                <a href="rdp_analytics.php" style="color:var(--accent-color);font-size:13px;text-decoration:none;font-weight:500">Усі підключення →</a>
            </div>
            <div class="feed-list">
                <?php if (empty($recentRdp)): ?>
                    <div class="no-data-msg">Подій RDP не зафіксовано</div>
                <?php else: foreach ($recentRdp as $ev): ?>
                    <div class="feed-item">
                        <div class="feed-meta">
                            <div class="feed-indicator <?= rdp_event_indicator($ev['event_type']) ?>"></div>
                            <div>
                                <div class="feed-text-primary"><?= e($ev['username']) ?></div>
                                <div class="feed-text-secondary"><?= e($ev['event_type']) ?> &bull; <?= e($ev['server_name']) ?> &bull; <?= e($ev['ip_address']) ?></div>
                            </div>
                        </div>
                        <div class="feed-time"><?= date('H:i d.m', strtotime($ev['timestamp'])) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">Останні події AD</div>
                <a href="ad_monitoring.php" style="color:var(--accent-color);font-size:13px;text-decoration:none;font-weight:500">Журнал змін →</a>
            </div>
            <div class="feed-list">
                <?php if (empty($recentAd)): ?>
                    <div class="no-data-msg">Подій AD не зафіксовано</div>
                <?php else: foreach ($recentAd as $ev): ?>
                    <div class="feed-item">
                        <div class="feed-meta">
                            <div class="feed-indicator <?= ad_event_badge($ev['action_type']) === 'success' ? 'active' : (ad_event_badge($ev['action_type']) === 'error' ? 'danger' : 'warning') ?>"></div>
                            <div>
                                <div class="feed-text-primary"><?= e($ev['action_type']) ?></div>
                                <div class="feed-text-secondary">Об'єкт: <code><?= e($ev['target_user']) ?></code> &bull; Адмін: <?= e($ev['caller_user']) ?></div>
                            </div>
                        </div>
                        <div class="feed-time"><?= date('H:i d.m', strtotime($ev['timestamp'])) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>
</main>

<?php require 'includes/footer.php'; ?>
