<?php
/**
 * index.php — Login page
 */
require_once 'config.php';

if (is_logged_in()) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Будь ласка, введіть логін та пароль.';
    } else {
        $pdo  = get_db_connection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password_hash'])) {
            $_SESSION['user_id']  = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role']     = $row['role'];
            $_SESSION['show_rdp_actions'] = (int)($row['show_rdp_actions'] ?? 0);
            cleanup_old_data();                     // purge records > 3 months on every login
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Невірне ім\'я користувача або пароль.';
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вхід — RDP & AD CRM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=1.4">
</head>
<body style="display:flex;justify-content:center;align-items:center;">
    <div class="login-container">
        <div class="logo-area">
            <div class="logo-icon">⬡</div>
            <h1>RDP & AD CRM</h1>
            <p>Моніторинг та аналітика серверів</p>
        </div>

        <div class="card" style="margin-bottom:0;">
            <?php if ($error): ?>
                <div class="error-message"><span>⚠️</span><span><?= e($error) ?></span></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Логін</label>
                    <input type="text" name="username" class="input-control" placeholder="Введіть логін" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label>Пароль</label>
                    <input type="password" name="password" class="input-control" placeholder="Введіть пароль" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn-submit">Увійти</button>
            </form>
        </div>

        <div class="footer-note">HestiaCP &bull; Cupertino Light Theme &bull; v2.0</div>
    </div>
</body>
</html>
