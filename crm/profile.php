<?php
/**
 * profile.php — Профіль користувача та зміна пароля
 */
require_once 'config.php';
require_login();

$user = get_logged_in_user();
$pdo  = get_db_connection();
$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPass = trim($_POST['old_password'] ?? '');
    $newPass = trim($_POST['new_password'] ?? '');
    $confirmPass = trim($_POST['confirm_password'] ?? '');
    $showRdpActions = isset($_POST['show_rdp_actions']) ? 1 : 0;

    $updatePassword = ($oldPass !== '' || $newPass !== '' || $confirmPass !== '');
    $valid = true;

    if ($updatePassword) {
        if ($oldPass === '' || $newPass === '' || $confirmPass === '') {
            $error = 'Будь ласка, заповніть усі поля для зміни пароля.';
            $valid = false;
        } elseif ($newPass !== $confirmPass) {
            $error = 'Новий пароль та підтвердження не співпадають.';
            $valid = false;
        } elseif (strlen($newPass) < 4) {
            $error = 'Новий пароль має бути не менше 4 символів.';
            $valid = false;
        } else {
            // Отримуємо поточний пароль з БД
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $currHash = $stmt->fetchColumn();

            if ($currHash && password_verify($oldPass, $currHash)) {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $update->execute([$newHash, $user['id']]);
                $success = 'Пароль успішно змінено! ';
            } else {
                $error = 'Неправильний старий пароль.';
                $valid = false;
            }
        }
    }

    if ($valid) {
        // Оновлюємо налаштування в БД
        $updateSettings = $pdo->prepare("UPDATE users SET show_rdp_actions = ? WHERE id = ?");
        $updateSettings->execute([$showRdpActions, $user['id']]);
        
        // Оновлюємо сесію
        $_SESSION['show_rdp_actions'] = $showRdpActions;
        
        $success .= 'Налаштування профілю збережено!';
    }
}

// Завантажуємо поточні налаштування з бази даних
$stmt = $pdo->prepare("SELECT show_rdp_actions FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$dbShowRdpActions = (int)$stmt->fetchColumn();

$pageTitle  = 'Профіль користувача';
$activePage = 'profile';
require 'includes/header.php';
?>

<main style="max-width: 600px; margin: 40px auto; padding: 0 20px;">
    <div class="page-header">
        <h2>Мій профіль</h2>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title">Зміна пароля для користувача: <strong><?= e($user['username']) ?></strong></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 15px;"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom: 15px;"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <!-- Налаштування -->
            <div class="form-group" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; background: var(--bg-color); padding: 12px 16px; border-radius: 8px;">
                <input type="checkbox" id="show_rdp_actions" name="show_rdp_actions" value="1" <?= $dbShowRdpActions ? 'checked' : '' ?> style="width: 18px; height: 18px; cursor: pointer; margin: 0;">
                <label for="show_rdp_actions" style="font-weight: 600; font-size: 13px; cursor: pointer; text-transform: none; color: var(--text-color); margin: 0;">Показувати стовпчик дій в аналітиці RDP (завершити/видалити)</label>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 20px 0;">

            <div style="font-size: 13px; font-weight: 600; margin-bottom: 12px; color: var(--secondary-color); text-transform: uppercase; letter-spacing: 0.5px;">Зміна пароля (залиште порожнім, якщо не змінюєте)</div>

            <div class="form-group">
                <label>Поточний пароль</label>
                <input type="password" name="old_password" class="form-control" placeholder="Введіть старий пароль">
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label>Новий пароль</label>
                <input type="password" name="new_password" class="form-control" placeholder="Не менше 4 символів">
            </div>

            <div class="form-group" style="margin-top: 15px; margin-bottom: 20px;">
                <label>Підтвердження нового пароля</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Повторіть новий пароль">
            </div>

            <button type="submit" class="btn-action" style="width: 100%;">💾 Зберегти зміни профілю</button>
        </form>
    </div>
</main>

<?php require 'includes/footer.php'; ?>
