<?php
/**
 * install.php — Майстер встановлення RDP & AD CRM
 *
 * Автоматично створює базу даних, таблиці, адміністратора та перевіряє
 * підключення до MySQL. Після успішного встановлення файл рекомендується
 * видалити або захистити через .htaccess.
 */
session_start();

$step = (int)($_GET['step'] ?? 1);

// ── Константи ─────────────────────────────────────────
$defaultDbHost = 'localhost';
$defaultDbName = 'apgk_rdp';
$defaultDbUser = 'apgk_rdp';
$defaultDbPass = 'orrz20054Q+';

// ── Обробка форм ──────────────────────────────────────
$errors   = [];
$success  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // КРОК 1: Перевірка підключення до БД
    if ($step === 1) {
        $dbHost = trim($_POST['db_host'] ?? $defaultDbHost);
        $dbName = trim($_POST['db_name'] ?? $defaultDbName);
        $dbUser = trim($_POST['db_user'] ?? $defaultDbUser);
        $dbPass = trim($_POST['db_pass'] ?? $defaultDbPass);

        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Перевіряємо чи база існує, якщо ні — створюємо
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // Зберігаємо налаштування в сесію для кроку 2
            $_SESSION['install_db'] = [
                'host' => $dbHost,
                'name' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
            ];

            header('Location: install.php?step=2');
            exit;

        } catch (PDOException $e) {
            $errors[] = "Помилка підключення до MySQL: " . $e->getMessage();
        }
    }

    // КРОК 2: Створення таблиць + адміністратора
    if ($step === 2) {
        $db = $_SESSION['install_db'] ?? null;
        if (!$db) { header('Location: install.php?step=1'); exit; }

        $adminUser = trim($_POST['admin_user'] ?? 'admin');
        $adminPass = trim($_POST['admin_pass'] ?? '');

        if ($adminUser === '' || $adminPass === '') {
            $errors[] = "Логін та пароль адміністратора обов'язкові.";
        } elseif (strlen($adminPass) < 4) {
            $errors[] = "Пароль має бути не менше 4 символів.";
        } else {
            try {
                $pdo = new PDO(
                    "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                    $db['user'], $db['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                // ── Створення таблиць ────────────────────────
                $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    role ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS admin_servers (
                    admin_id INT NOT NULL,
                    server_name VARCHAR(100) NOT NULL,
                    PRIMARY KEY (admin_id, server_name),
                    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    token VARCHAR(255) UNIQUE NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    permissions VARCHAR(255) NOT NULL DEFAULT 'rdp,ad',
                    created_by INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS ad_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    dc_name VARCHAR(100) NOT NULL,
                    event_id INT NOT NULL,
                    action_type VARCHAR(100) NOT NULL,
                    target_user VARCHAR(255) NOT NULL,
                    caller_user VARCHAR(255) NOT NULL,
                    details TEXT,
                    INDEX idx_ad_ts (timestamp),
                    INDEX idx_ad_dc (dc_name),
                    INDEX idx_ad_target (target_user)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS rdp_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    server_name VARCHAR(100) NOT NULL,
                    username VARCHAR(100) NOT NULL,
                    session_id INT NOT NULL,
                    event_type VARCHAR(100) NOT NULL,
                    ip_address VARCHAR(100) DEFAULT 'Unknown',
                    INDEX idx_rdp_ts (timestamp),
                    INDEX idx_rdp_srv (server_name),
                    INDEX idx_rdp_user (username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS rdp_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    server_name VARCHAR(100) NOT NULL,
                    username VARCHAR(100) NOT NULL,
                    session_id INT NOT NULL,
                    ip_address VARCHAR(100) DEFAULT 'Unknown',
                    start_time TIMESTAMP NOT NULL,
                    end_time TIMESTAMP NULL,
                    duration_seconds INT DEFAULT 0,
                    INDEX idx_sess_start (start_time),
                    INDEX idx_sess_end (end_time),
                    INDEX idx_sess_srv (server_name),
                    INDEX idx_sess_user (username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // ── Супер-адміністратор ───────────────────────
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT IGNORE INTO users (username, password_hash, role) VALUES (?, ?, 'super_admin')")
                    ->execute([$adminUser, $hash]);

                // ── Зберегти конфігурацію в db.php ───────────
                $dbPhpContent = <<<PHP
<?php
/**
 * db.php — Підключення до бази даних та ініціалізація схеми
 * Згенеровано автоматично: install.php
 */

define('DB_HOST', '{$db['host']}');
define('DB_NAME', '{$db['name']}');
define('DB_USER', '{$db['user']}');
define('DB_PASS', '{$db['pass']}');

function get_db_connection(): PDO {
    static \$pdo = null;
    if (\$pdo !== null) return \$pdo;

    \$pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    return \$pdo;
}
PHP;

                file_put_contents(__DIR__ . '/includes/db.php', $dbPhpContent);

                $_SESSION['install_done'] = true;
                header('Location: install.php?step=3');
                exit;

            } catch (PDOException $e) {
                $errors[] = "Помилка створення таблиць: " . $e->getMessage();
            }
        }
    }
}

// ── Перевірка фінального кроку ────────────────────────
if ($step === 3 && empty($_SESSION['install_done'])) {
    header('Location: install.php?step=1');
    exit;
}

$totalSteps = 3;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Встановлення — RDP & AD CRM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            background: var(--bg-color);
        }
        .install-container {
            width: 100%;
            max-width: 540px;
            padding: 24px;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
            margin-bottom: 28px;
        }
        .step-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            border: 2px solid var(--border-color);
            color: var(--secondary-text);
            background: var(--card-bg);
            position: relative;
            z-index: 1;
        }
        .step-dot.active {
            border-color: var(--accent-color);
            color: #fff;
            background: var(--accent-color);
            box-shadow: 0 2px 8px rgba(0,122,255,.3);
        }
        .step-dot.done {
            border-color: var(--success-color);
            color: #fff;
            background: var(--success-color);
        }
        .step-line {
            width: 48px;
            height: 2px;
            background: var(--border-color);
        }
        .step-line.done {
            background: var(--success-color);
        }
        .step-labels {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .step-label {
            font-size: 11px;
            color: var(--secondary-text);
            text-align: center;
            flex: 1;
        }
        .step-label.active {
            color: var(--accent-color);
            font-weight: 600;
        }
        .check-list {
            list-style: none;
            padding: 0;
            margin: 0 0 20px;
        }
        .check-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            font-size: 14px;
            border-bottom: 1px solid var(--border-color);
        }
        .check-list li:last-child { border-bottom: none; }
        .check-icon { font-size: 18px; }
    </style>
</head>
<body>
    <div class="install-container">
        <!-- Лого -->
        <div class="logo-area">
            <div class="logo-icon">⬡</div>
            <h1>Встановлення CRM</h1>
            <p>Налаштування RDP & AD CRM системи</p>
        </div>

        <!-- Прогрес-бар -->
        <div class="step-indicator">
            <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">1</div>
            <div class="step-line <?= $step > 1 ? 'done' : '' ?>"></div>
            <div class="step-dot <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">2</div>
            <div class="step-line <?= $step > 2 ? 'done' : '' ?>"></div>
            <div class="step-dot <?= $step >= 3 ? 'active' : '' ?>">3</div>
        </div>
        <div class="step-labels">
            <div class="step-label <?= $step === 1 ? 'active' : '' ?>">База даних</div>
            <div class="step-label <?= $step === 2 ? 'active' : '' ?>">Адміністратор</div>
            <div class="step-label <?= $step === 3 ? 'active' : '' ?>">Готово</div>
        </div>

        <!-- Помилки -->
        <?php foreach ($errors as $e): ?>
            <div class="error-message"><span>⚠️</span><span><?= htmlspecialchars($e) ?></span></div>
        <?php endforeach; ?>

        <!-- КРОК 1: Підключення до БД -->
        <?php if ($step === 1): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">Крок 1: Підключення до бази даних</div>
            </div>
            <form method="POST" action="install.php?step=1">
                <div class="form-group">
                    <label>Хост MySQL</label>
                    <input type="text" name="db_host" class="input-control" value="<?= htmlspecialchars($defaultDbHost) ?>" required>
                </div>
                <div class="form-group">
                    <label>Назва бази даних</label>
                    <input type="text" name="db_name" class="input-control" value="<?= htmlspecialchars($defaultDbName) ?>" required>
                    <small style="color:var(--secondary-text);font-size:12px;margin-top:4px;display:block">Буде створена автоматично, якщо не існує</small>
                </div>
                <div class="form-group">
                    <label>Користувач MySQL</label>
                    <input type="text" name="db_user" class="input-control" value="<?= htmlspecialchars($defaultDbUser) ?>" required>
                </div>
                <div class="form-group">
                    <label>Пароль MySQL</label>
                    <input type="password" name="db_pass" class="input-control" value="<?= htmlspecialchars($defaultDbPass) ?>">
                </div>
                <button type="submit" class="btn-submit">Перевірити та продовжити →</button>
            </form>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="card-header">
                <div class="card-title">Перевірка середовища</div>
            </div>
            <ul class="check-list">
                <li>
                    <span class="check-icon"><?= version_compare(PHP_VERSION, '7.4', '>=') ? '✅' : '❌' ?></span>
                    PHP <?= PHP_VERSION ?> (потрібно ≥ 7.4)
                </li>
                <li>
                    <span class="check-icon"><?= extension_loaded('pdo_mysql') ? '✅' : '❌' ?></span>
                    Розширення PDO MySQL
                </li>
                <li>
                    <span class="check-icon"><?= extension_loaded('json') ? '✅' : '❌' ?></span>
                    Розширення JSON
                </li>
                <li>
                    <span class="check-icon"><?= extension_loaded('mbstring') ? '✅' : '❌' ?></span>
                    Розширення mbstring
                </li>
                <li>
                    <span class="check-icon"><?= is_writable(__DIR__ . '/includes/') ? '✅' : '❌' ?></span>
                    Директорія includes/ доступна для запису
                </li>
            </ul>
        </div>

        <!-- КРОК 2: Створення адміністратора -->
        <?php elseif ($step === 2): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">Крок 2: Створення Супер Адміністратора</div>
            </div>
            <form method="POST" action="install.php?step=2">
                <div class="form-group">
                    <label>Логін адміністратора</label>
                    <input type="text" name="admin_user" class="input-control" value="admin" required autofocus>
                </div>
                <div class="form-group">
                    <label>Пароль</label>
                    <input type="password" name="admin_pass" class="input-control" placeholder="Мінімум 4 символи" required>
                </div>
                <button type="submit" class="btn-submit">Створити та завершити →</button>
            </form>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="card-header"><div class="card-title">Структура бази даних</div></div>
            <ul class="check-list">
                <li><span class="check-icon">📦</span><strong>users</strong> — Адміністратори CRM</li>
                <li><span class="check-icon">📦</span><strong>admin_servers</strong> — Права доступу до серверів</li>
                <li><span class="check-icon">📦</span><strong>api_tokens</strong> — Токени для служб моніторингу</li>
                <li><span class="check-icon">📦</span><strong>rdp_events</strong> — Журнал подій RDP</li>
                <li><span class="check-icon">📦</span><strong>rdp_sessions</strong> — Агреговані сеанси RDP</li>
                <li><span class="check-icon">📦</span><strong>ad_events</strong> — Журнал подій Active Directory</li>
            </ul>
        </div>

        <!-- КРОК 3: Завершення -->
        <?php elseif ($step === 3): ?>
        <div class="card" style="text-align:center;padding:32px 24px;">
            <div style="font-size:56px;margin-bottom:16px;">🎉</div>
            <h2 style="font-size:22px;font-weight:700;margin-bottom:8px;">Встановлення завершено!</h2>
            <p style="color:var(--secondary-text);font-size:14px;margin-bottom:24px;">
                Усі таблиці створено, Супер Адміністратор налаштований.<br>
                Система готова до роботи.
            </p>

            <div class="alert alert-success" style="text-align:left;margin-bottom:20px;">
                <strong>✅ Рекомендація з безпеки:</strong><br>
                Видаліть або перейменуйте файл <code>install.php</code> після встановлення для захисту системи.
            </div>

            <div style="text-align:left;margin-bottom:20px;">
                <div class="card-header"><div class="card-title">Наступні кроки</div></div>
                <ul class="check-list">
                    <li><span class="check-icon">1️⃣</span> Увійдіть в CRM під створеним обліковим записом</li>
                    <li><span class="check-icon">2️⃣</span> Створіть API токени в розділі «Токени API»</li>
                    <li><span class="check-icon">3️⃣</span> Налаштуйте CRM URL та токен у RDP Monitor і AD Monitor</li>
                    <li><span class="check-icon">4️⃣</span> Додайте адміністраторів та призначте їм сервери</li>
                </ul>
            </div>

            <a href="index.php" class="btn-submit" style="display:inline-block;text-decoration:none;padding:14px 40px;">
                Перейти до входу →
            </a>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="card-header"><div class="card-title">Структура проекту</div></div>
            <pre style="font-size:13px;line-height:1.7;margin:0;">
crm/
├── index.php              — Сторінка входу
├── install.php            — Майстер встановлення (видаліть після)
├── dashboard.php          — Головна панель
├── rdp_analytics.php      — Аналітика RDP та облік часу
├── ad_monitoring.php      — Журнал моніторингу AD
├── admin_management.php   — Керування адміністраторами
├── tokens.php             — Керування API токенами
├── logout.php             — Вихід із системи
├── config.php             — Завантажувач конфігурації
├── .htaccess              — Захист Apache
│
├── includes/
│   ├── db.php             — Підключення до БД
│   ├── auth.php           — Авторизація та ролі
│   ├── helpers.php        — Допоміжні функції
│   ├── header.php         — Шаблон шапки та навігації
│   └── footer.php         — Шаблон підвалу
│
├── assets/
│   └── css/
│       └── app.css        — Єдиний файл стилів (Cupertino)
│
└── api/
    ├── auth.php           — Перевірка токена
    ├── rdp_event.php      — Прийом подій RDP
    ├── ad_event.php       — Прийом подій AD
    └── .htaccess          — Захист API
</pre>
        </div>
        <?php endif; ?>

        <div class="footer-note">RDP & AD CRM v2.0 — HestiaCP</div>
    </div>
</body>
</html>
