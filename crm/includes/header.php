<?php
/**
 * CRM Header Include
 *
 * Expected variables:
 *   $pageTitle  (string) — page title
 *   $activePage (string) — active nav identifier: 'dashboard', 'rdp', 'ad', 'admins', 'tokens'
 *   $user       (array)  — ['username' => string, 'role' => string]
 */
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - RDP &amp; AD CRM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=1.4">
</head>
<body>
    <header>
        <div class="nav-container">
            <a href="dashboard.php" class="nav-brand">
                <span class="nav-brand-logo">⬡</span> RDP &amp; AD CRM
            </a>
            <nav>
                <ul>
                    <li><a href="dashboard.php" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>">Панель</a></li>
                    <li><a href="rdp_analytics.php" class="<?= $activePage === 'rdp' ? 'active' : '' ?>">Аналітика RDP</a></li>
                    <li><a href="user_analytics.php" class="<?= $activePage === 'user_profile' ? 'active' : '' ?>">👤 Аналітика користувачів</a></li>
                    <li><a href="ad_monitoring.php" class="<?= $activePage === 'ad' ? 'active' : '' ?>">AD Моніторинг</a></li>
                    <li><a href="server_status.php" class="<?= $activePage === 'status' ? 'active' : '' ?>">Статус серверів</a></li>
                    <?php if ($user['role'] === 'super_admin'): ?>
                        <li><a href="admin_management.php" class="<?= $activePage === 'admins' ? 'active' : '' ?>">Адміністратори</a></li>
                        <li><a href="tokens.php" class="<?= $activePage === 'tokens' ? 'active' : '' ?>">Токени API</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <div class="user-profile">
                <div class="user-info">
                    <div class="user-name">
                        <a href="profile.php" style="color: inherit; text-decoration: none; font-weight: 600;" title="Редагувати профіль">
                            <?= htmlspecialchars($user['username']) ?> ⚙️
                        </a>
                    </div>
                    <div class="user-role"><?= $user['role'] === 'super_admin' ? 'Супер Адмін' : 'Адміністратор' ?></div>
                </div>
                <a href="profile.php" class="btn-logout" style="color: var(--accent-color); border-color: var(--accent-color); margin-right: 8px; text-decoration: none;">Профіль</a>
                <a href="logout.php" class="btn-logout">Вихід</a>
            </div>
        </div>
    </header>
