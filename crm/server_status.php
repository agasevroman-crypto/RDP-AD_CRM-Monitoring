<?php
/**
 * server_status.php — Статус активності серверів
 */
require_once 'config.php';
require_login();

$user = get_logged_in_user();
$allowedServers = get_user_servers();
$pdo = get_db_connection();

// SQL запит для отримання останнього часу зв'язку з кожним сервером
$sql = "SELECT name, MAX(last_seen) as last_active, type FROM (
            SELECT dc_name AS name, MAX(timestamp) AS last_seen, 'Контролер AD' AS type FROM ad_events GROUP BY dc_name
            UNION
            SELECT server_name AS name, MAX(timestamp) AS last_seen, 'RDP Сервер' AS type FROM rdp_events GROUP BY server_name
        ) combined GROUP BY name, type ORDER BY name";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$allServers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Фільтруємо за дозволеними серверами для поточного користувача (для звичайних адмінів)
$servers = [];
foreach ($allServers as $srv) {
    if (is_super_admin() || in_array($srv['name'], $allowedServers)) {
        $servers[] = $srv;
    }
}

$pageTitle = 'Статус серверів'; $activePage = 'status';
require_once 'includes/header.php';
?>

<main>
    <!-- Заголовок сторінки -->
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2>🖥️ Статус активності серверів</h2>
        <a href="server_status.php" class="btn-action" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
            🔄 Оновити дані
        </a>
    </div>

    <!-- Інформаційне повідомлення -->
    <div class="card" style="padding: 16px 20px; margin-bottom: 20px; font-size: 14px; background: #fff; color: var(--secondary-text); border: 1px solid var(--border-color); border-radius: 12px;">
        Статус визначається автоматично: якщо сервер відправляв події в CRM через API менше ніж <strong>10 хвилин тому</strong>, він вважається активним.
    </div>

    <!-- Таблиця статусів -->
    <div class="card" style="border-radius: 12px; overflow: hidden; padding: 0;">
        <?php if (empty($servers)): ?>
            <div class="no-data-msg" style="padding: 40px; text-align: center; color: var(--secondary-text);">
                Немає зареєстрованих серверів в системі.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
                    <thead>
                        <tr style="background: var(--bg-color); border-bottom: 1px solid var(--border-color);">
                            <th style="padding: 14px 20px; font-weight: 600; color: var(--secondary-text); font-size: 12px; text-transform: uppercase;">Назва сервера / DC</th>
                            <th style="padding: 14px 20px; font-weight: 600; color: var(--secondary-text); font-size: 12px; text-transform: uppercase;">Тип монітора</th>
                            <th style="padding: 14px 20px; font-weight: 600; color: var(--secondary-text); font-size: 12px; text-transform: uppercase;">Останній зв'язок (API)</th>
                            <th style="padding: 14px 20px; font-weight: 600; color: var(--secondary-text); font-size: 12px; text-transform: uppercase;">Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servers as $srv): 
                            $lastActiveTime = strtotime($srv['last_active']);
                            $timeDiff = time() - $lastActiveTime;
                            $isOnline = $timeDiff <= 600; // 10 хвилин (600 секунд)
                        ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 16px 20px;">
                                <strong><?= htmlspecialchars($srv['name']) ?></strong>
                            </td>
                            <td style="padding: 16px 20px;">
                                <span class="srv-badge <?= $srv['type'] === 'Контролер AD' ? 'super' : '' ?>">
                                    <?= htmlspecialchars($srv['type']) ?>
                                </span>
                            </td>
                            <td style="padding: 16px 20px; color: var(--text-color);">
                                <?= date('d.m.Y H:i:s', $lastActiveTime) ?>
                                <span style="font-size: 12px; color: var(--secondary-text); margin-left: 8px;">
                                    (<?= format_time_ago($timeDiff) ?>)
                                </span>
                            </td>
                            <td style="padding: 16px 20px;">
                                <?php if ($isOnline): ?>
                                    <span class="event-badge success" style="padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size: 12px; background: rgba(52, 199, 89, 0.1); color: #34C759; display: inline-flex; align-items: center; gap: 6px;">
                                        <span class="active-dot" style="width: 8px; height: 8px; background: #34C759; border-radius: 50%; display: inline-block;"></span>
                                        Активний
                                    </span>
                                <?php else: ?>
                                    <span class="event-badge error" style="padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size: 12px; background: rgba(142, 142, 147, 0.1); color: #8E8E93; display: inline-flex; align-items: center; gap: 6px;">
                                        <span style="width: 8px; height: 8px; background: #8E8E93; border-radius: 50%; display: inline-block;"></span>
                                        Неактивний
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php 
require_once 'includes/footer.php'; 

// Допоміжна функція виводу відносного часу
function format_time_ago(int $seconds): string {
    if ($seconds < 60) {
        return "щойно";
    }
    $minutes = round($seconds / 60);
    if ($minutes < 60) {
        return "$minutes хв. тому";
    }
    $hours = round($minutes / 60);
    if ($hours < 24) {
        return "$hours год. тому";
    }
    $days = round($hours / 24);
    return "$days дн. тому";
}
?>
