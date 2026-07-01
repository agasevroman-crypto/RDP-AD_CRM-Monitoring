<?php
/**
 * ad_monitoring.php — Журнал моніторингу Active Directory (Гнучкий пошук та пагінація)
 */
require_once 'config.php';
require_login();

$user = get_logged_in_user();
$allowedServers = get_user_servers();
$pdo = get_db_connection();

// ── 1. Фільтрація, пошук та пагінація ──────────────────
$filterDc     = trim($_GET['dc'] ?? '');
if ($filterDc !== '' && !in_array($filterDc, $allowedServers)) $filterDc = '';

$filterAction = trim($_GET['action_type'] ?? '');
$filterTarget = trim($_GET['target'] ?? '');
$filterCaller = trim($_GET['caller'] ?? '');
$filterFrom   = trim($_GET['date_from'] ?? '');
$filterTo     = trim($_GET['date_to'] ?? '');
$search       = trim($_GET['search'] ?? '');

// Сортування (тільки дозволені колонки для безпеки)
$allowedSort = ['timestamp', 'dc_name', 'event_id', 'action_type', 'target_user', 'caller_user'];
$sortBy = in_array($_GET['sort_by'] ?? '', $allowedSort) ? $_GET['sort_by'] : 'timestamp';
$sortDir = strtoupper($_GET['sort_dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// Пагінація
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 100;
$offset = ($page - 1) * $limit;

// Побудова WHERE клаузи
$whereClauses = [];
$params = [];

$isSA = is_super_admin();
if (!$isSA) {
    if (empty($allowedServers)) {
        $whereClauses[] = '1=0';
    } elseif ($filterDc !== '') {
        $whereClauses[] = 'dc_name = ?';
        $params[] = $filterDc;
    } else {
        $ph = implode(',', array_fill(0, count($allowedServers), '?'));
        $whereClauses[] = "dc_name IN ($ph)";
        $params = array_merge($params, $allowedServers);
    }
} else {
    if ($filterDc !== '') {
        $whereClauses[] = 'dc_name = ?';
        $params[] = $filterDc;
    }
}

if ($filterAction !== '') {
    $whereClauses[] = 'action_type = ?';
    $params[] = $filterAction;
}
if ($filterTarget !== '') {
    $whereClauses[] = 'target_user LIKE ?';
    $params[] = "%$filterTarget%";
}
if ($filterCaller !== '') {
    $whereClauses[] = 'caller_user LIKE ?';
    $params[] = "%$filterCaller%";
}
if ($filterFrom !== '') {
    $whereClauses[] = 'timestamp >= ?';
    $params[] = "$filterFrom 00:00:00";
}
if ($filterTo !== '') {
    $whereClauses[] = 'timestamp <= ?';
    $params[] = "$filterTo 23:59:59";
}

// Загальний текстовий пошук
if ($search !== '') {
    $whereClauses[] = '(target_user LIKE ? OR caller_user LIKE ? OR dc_name LIKE ? OR details LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// ── 2. Отримання даних з БД ───────────────────────────
// Кількість записів
$countSql = "SELECT COUNT(*) FROM ad_events $whereSql";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalRecords = (int)$stmt->fetchColumn();

$totalPages = max(1, ceil($totalRecords / $limit));

// Отримання записів для поточної сторінки
$sql = "SELECT id, dc_name, event_id, action_type, target_user, caller_user, timestamp, details
        FROM ad_events $whereSql 
        ORDER BY $sortBy $sortDir 
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Отримання унікальних типів дій для випадаючого списку фільтрації
$actionTypes = [];
try {
    $actionTypes = $pdo->query("SELECT DISTINCT action_type FROM ad_events ORDER BY action_type")
                       ->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $actionTypes = [];
}

// ── 3. Рендеринг ──────────────────────────────────────
$pageTitle = 'AD Моніторинг'; $activePage = 'ad';
require 'includes/header.php';

// Допоміжні посилання для пагінації
$qs = $_GET;
function build_qs(array $override = []): string {
    global $qs;
    return '?' . http_build_query(array_merge($qs, $override));
}

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
    <!-- Заголовок сторінки -->
    <div class="page-header">
        <h2>🛡️ Дії адміністраторів в Active Directory</h2>
    </div>

    <!-- Картка фільтрації та пошуку -->
    <div class="card">
        <form method="get" class="filter-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) 120px; gap: 16px; align-items: end;">
            <div class="filter-group">
                <label>Пошук (Об'єкт, Адмін, Опис)</label>
                <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Введіть фразу...">
            </div>

            <div class="filter-group">
                <label>Контролер (DC)</label>
                <select name="dc" class="form-control">
                    <option value="">— Всі контролери —</option>
                    <?php foreach ($allowedServers as $srv): ?>
                        <option value="<?= e($srv) ?>" <?= $filterDc === $srv ? 'selected' : '' ?>><?= e($srv) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Дія / Подія</label>
                <select name="action_type" class="form-control">
                    <option value="">— Всі події —</option>
                    <?php foreach ($actionTypes as $at): ?>
                        <option value="<?= e($at) ?>" <?= $filterAction === $at ? 'selected' : '' ?>><?= e($at) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Цільовий об'єкт</label>
                <input type="text" name="target" class="form-control" value="<?= e($filterTarget) ?>" placeholder="Користувач / Група">
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

    <!-- Загальний статус кількості подій -->
    <div class="card" style="padding: 12px 20px; margin-bottom: 20px; font-size: 14px; background:#fff;">
        Знайдено подій AD за вказаними фільтрами: <strong><?= $totalRecords ?></strong>
    </div>

    <!-- Таблиця подій -->
    <div class="card">
        <?php if (empty($rows)): ?>
            <div class="no-data-msg">Подій Active Directory не знайдено за вказаними параметрами</div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="white-space: nowrap;"><?php render_sort_link('timestamp', 'Час події'); ?></th>
                            <th style="white-space: nowrap;"><?php render_sort_link('dc_name', 'Контролер'); ?></th>
                            <th style="white-space: nowrap;"><?php render_sort_link('event_id', 'ID'); ?></th>
                            <th style="white-space: nowrap;"><?php render_sort_link('action_type', 'Дія / Подія'); ?></th>
                            <th style="white-space: nowrap;"><?php render_sort_link('target_user', 'Цільовий об\'єкт'); ?></th>
                            <th style="white-space: nowrap;"><?php render_sort_link('caller_user', 'Ініціатор (Адмін)'); ?></th>
                            <th style="white-space: nowrap; text-align: right;">Деталі</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?= date('d.m.Y H:i:s', strtotime($r['timestamp'])) ?></td>
                            <td style="white-space: nowrap;"><span class="srv-badge"><?= e($r['dc_name']) ?></span></td>
                            <td style="white-space: nowrap;"><code><?= e($r['event_id']) ?></code></td>
                            <td style="white-space: nowrap;">
                                <span class="event-badge <?= ad_event_badge($r['action_type']) ?>">
                                    <?= e($r['action_type']) ?>
                                </span>
                            </td>
                            <td style="white-space: nowrap;"><strong><?= e($r['target_user']) ?></strong></td>
                            <td style="white-space: nowrap;"><code><?= e($r['caller_user']) ?></code></td>
                            <td style="white-space: nowrap; text-align: right;">
                                <!-- Приховані дані для модального вікна -->
                                <div id="details-data-<?= $r['id'] ?>" style="display:none;"><?= e($r['details']) ?></div>
                                <button type="button" class="btn-details" onclick="showEventDetails(<?= $r['id'] ?>)">Показати</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Пагінація -->
            <?php if ($totalPages > 1): ?>
                <div style="margin-top: 24px; display: flex; justify-content: space-between; align-items: center; font-size: 13px;">
                    <div style="color: var(--secondary-text);">
                        Сторінка <strong><?= $page ?></strong> з <strong><?= $totalPages ?></strong> (всього записів: <?= $totalRecords ?>)
                    </div>
                    <div style="display: inline-flex; gap: 4px;">
                        <?php if ($page > 1): ?>
                            <a href="<?= e(build_qs(['page' => $page - 1])) ?>" class="btn-action btn-secondary" style="padding: 6px 12px; font-size: 12px; text-decoration: none; display: inline-block; background:#fff; color:var(--text-color); border:1px solid var(--border-color);">Попередня</a>
                        <?php endif; ?>

                        <?php 
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

    <!-- Модальне вікно для відображення JSON деталей події -->
    <div class="modal-overlay" id="eventModal" style="display:none;">
        <div class="modal-container">
            <div class="modal-header">
                <span class="modal-title">Детальна інформація про подію AD</span>
                <button type="button" class="modal-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body" style="background: #f8f9fa;">
                <div id="modalEventContent"></div>
            </div>
            <div class="modal-footer" style="padding:16px 20px; border-top:1px solid var(--border-color); display:flex; justify-content:flex-end; background:#fff;">
                <button type="button" class="btn-action" onclick="closeModal()">Закрити</button>
            </div>
        </div>
    </div>
</main>

<script>
function showEventDetails(id) {
    var rawText = document.getElementById('details-data-' + id).textContent;
    var container = document.getElementById('modalEventContent');
    container.innerHTML = ''; // Очистити

    try {
        var data = JSON.parse(rawText);
        var table = '<table style="width:100%; font-size:13px; border-collapse:collapse;">';
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                var val = data[key];
                if (typeof val === 'object') val = JSON.stringify(val);
                table += '<tr style="border-bottom:1px solid #e5e5ea;">' +
                         '<td style="padding:8px; font-weight:600; color:var(--secondary-text); width:30%; vertical-align:top;">' + escapeHtml(key) + '</td>' +
                         '<td style="padding:8px; font-family:monospace; word-break:break-all; color:var(--text-color);">' + escapeHtml(val) + '</td>' +
                         '</tr>';
            }
        }
        table += '</table>';
        container.innerHTML = table;
    } catch (e) {
        // Якщо це не JSON, вивести як звичайний текст
        container.innerHTML = '<pre style="margin:0; white-space:pre-wrap; font-family:monospace; font-size:13px;">' + escapeHtml(rawText) + '</pre>';
    }

    document.getElementById('eventModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('eventModal').style.display = 'none';
}

function escapeHtml(string) {
    return String(string).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Закриття по кліку поза вікном або клавіші ESC
document.getElementById('eventModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php require 'includes/footer.php'; ?>
