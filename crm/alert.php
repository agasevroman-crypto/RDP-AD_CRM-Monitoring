<?php
/**
 * alert.php — Скрипт для очищення бази даних від залишків таблиці вхідних логів
 */
require_once 'config.php';
require_super_admin();

$user = get_logged_in_user();
$pdo = get_db_connection();
$message = '';
$error = '';

try {
    // Видаляємо таблицю ad_logons, яка забивала базу даних
    $pdo->exec("DROP TABLE IF EXISTS ad_logons");
    $message = "Таблицю ad_logons успішно видалено з бази даних MySQL, застарілі логи входу очищено!";
} catch (Exception $e) {
    $error = "Помилка при очищенні бази даних: " . $e->getMessage();
}

$pageTitle = 'Очищення БД';
$activePage = 'dashboard';
require_once 'includes/header.php';
?>

<main>
    <div style="max-width: 600px; margin: 60px auto; padding: 0 20px;">
        <?php if ($message): ?>
            <div class="card" style="text-align: center; padding: 40px; border-radius: 16px;">
                <div style="width: 64px; height: 64px; background: rgba(52, 199, 89, 0.1); color: #34C759; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 20px; font-weight: bold;">
                    ✓
                </div>
                <h2 style="margin-top: 0; color: #1C1C1E; font-weight: 700; letter-spacing: -0.5px;">Очищення виконано!</h2>
                <p style="color: #48484A; font-size: 15px; line-height: 1.5; margin-bottom: 24px;">
                    <?= htmlspecialchars($message) ?>
                </p>
                <div class="alert alert-success" style="text-align: left; margin-bottom: 24px; border: 1px solid rgba(52, 199, 89, 0.2); background: rgba(52, 199, 89, 0.05); color: #243527; padding: 12px; border-radius: 8px;">
                    <strong>Рекомендація з безпеки:</strong> Будь ласка, видаліть файл <code>alert.php</code> з вашого сервера після успішного виконання очищення.
                </div>
                <a href="dashboard.php" class="btn-action" style="text-decoration: none; display: inline-block;">Повернутися на панель</a>
            </div>
        <?php else: ?>
            <div class="card" style="text-align: center; padding: 40px; border-radius: 16px;">
                <div style="width: 64px; height: 64px; background: rgba(255, 59, 48, 0.1); color: #FF3B30; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 20px; font-weight: bold;">
                    ⚠️
                </div>
                <h2 style="margin-top: 0; color: #1C1C1E; font-weight: 700; letter-spacing: -0.5px;">Помилка очищення</h2>
                <p style="color: #FF3B30; font-size: 15px; line-height: 1.5; margin-bottom: 24px;">
                    <?= htmlspecialchars($error) ?>
                </p>
                <a href="dashboard.php" class="btn-action btn-secondary" style="text-decoration: none; display: inline-block; background: #fff; color: #1C1C1E; border: 1px solid #E5E5EA;">Повернутися на панель</a>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>
