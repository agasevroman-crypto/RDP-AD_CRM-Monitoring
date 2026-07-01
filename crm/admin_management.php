<?php
/**
 * admin_management.php — Керування адміністраторами та правами доступу
 */
require_once 'config.php';
require_super_admin();

$user = get_logged_in_user();
$pdo  = get_db_connection();
$error = ''; $success = '';

// --- POST handlers ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Add new user
    if ($_POST['action'] === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = $_POST['role'] ?? 'admin';

        if ($username === '' || $password === '') {
            $error = 'Заповніть усі поля.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)");
                $stmt->execute([
                    ':username'      => $username,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':role'          => $role,
                ]);
                $success = 'Користувача успішно створено.';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Цей логін вже зайнятий.';
                } else {
                    $error = 'Помилка бази даних: ' . $e->getMessage();
                }
            }
        }
    }

    // Delete user
    if ($_POST['action'] === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId === (int)$user['id']) {
            $error = 'Ви не можете видалити свій обліковий запис.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            $success = 'Користувача видалено.';
        }
    }

    // Update permissions
    if ($_POST['action'] === 'update_permissions') {
        $adminId      = (int)($_POST['admin_id'] ?? 0);
        $servers      = $_POST['servers'] ?? [];
        $customServer = trim($_POST['custom_server'] ?? '');

        $pdo->prepare("DELETE FROM admin_servers WHERE admin_id = :admin_id")
            ->execute([':admin_id' => $adminId]);

        $insert = $pdo->prepare("INSERT INTO admin_servers (admin_id, server_name) VALUES (:admin_id, :server_name)");
        foreach ($servers as $srv) {
            $insert->execute([':admin_id' => $adminId, ':server_name' => $srv]);
        }

        if ($customServer !== '') {
            $insert->execute([':admin_id' => $adminId, ':server_name' => $customServer]);
        }

        $success = 'Права доступу оновлено.';
    }
}

// --- Fetch existing servers ---

$serversStmt = $pdo->query("
    SELECT DISTINCT server_name FROM (
        SELECT server_name FROM rdp_events
        UNION
        SELECT dc_name AS server_name FROM ad_events
    ) AS combined
    ORDER BY server_name
");
$allServers = $serversStmt->fetchAll(PDO::FETCH_COLUMN);

// --- Fetch all admins with their server permissions ---

$adminsStmt = $pdo->query("SELECT id, username, role FROM users ORDER BY username");
$admins = $adminsStmt->fetchAll(PDO::FETCH_ASSOC);

$permStmt = $pdo->prepare("SELECT server_name FROM admin_servers WHERE admin_id = :admin_id");
foreach ($admins as &$admin) {
    $permStmt->execute([':admin_id' => $admin['id']]);
    $admin['servers'] = $permStmt->fetchAll(PDO::FETCH_COLUMN);
}
unset($admin);

// --- Render ---

$pageTitle  = 'Адміністратори';
$activePage = 'admins';
require 'includes/header.php';
?>

<main>

    <div class="page-header-full">
        <h2>Керування адміністраторами та правами</h2>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
    </div>

    <!-- Верхній ряд: Створення та Пояснення ролей -->
    <div class="content-row">
        <!-- Ліва колонка: Створення адміністратора -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header"><div class="card-title">Створити адміністратора</div></div>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="add_user">

                <div class="form-group">
                    <label for="username">Логін</label>
                    <input type="text" id="username" name="username" class="form-control" required style="width: 100%;">
                </div>

                <div class="form-group">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" name="password" class="form-control" required style="width: 100%;">
                </div>

                <div class="form-group">
                    <label for="role">Роль</label>
                    <select id="role" name="role" class="form-control" style="width: 100%;">
                        <option value="admin">Звичайний Адмін</option>
                        <option value="super_admin">Супер Адмін</option>
                    </select>
                </div>

                <button type="submit" class="btn-action">Створити</button>
            </form>
        </div>

        <!-- Права колонка: Пояснення ролей -->
        <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div class="card-header"><div class="card-title">📌 Ролі та права доступу</div></div>
                <div style="font-size:13px; line-height:1.6; padding-top: 8px; color: var(--text-color);">
                    <div style="margin-bottom: 12px;">
                        <strong>👑 Супер Адмін:</strong>
                        <div style="color: var(--secondary-color); margin-top: 2px;">Має повний доступ до CRM, включаючи створення інших адміністраторів, налаштування їх доступу до конкретних серверів та керування API токенами.</div>
                    </div>
                    <div>
                        <strong>👤 Звичайний Адмін:</strong>
                        <div style="color: var(--secondary-color); margin-top: 2px;">Має доступ лише до перегляду розділів «Панель», «Аналітика RDP» та «AD Моніторинг» для тих серверів, які йому призначив Супер Адмін.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Нижній ряд: Список (на всю ширину) -->
    <div class="card" style="margin-top: 20px;">
        <div class="card-header"><div class="card-title">Список адміністраторів</div></div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="white-space: nowrap;">Логін</th>
                        <th style="white-space: nowrap;">Роль</th>
                        <th>Доступні сервери</th>
                        <th style="white-space: nowrap;">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                    <tr>
                        <td style="white-space: nowrap;"><strong><?= htmlspecialchars($admin['username']) ?></strong></td>
                        <td style="white-space: nowrap;">
                            <span class="srv-badge"><?= $admin['role'] === 'super_admin' ? 'Супер Адмін' : 'Адмін' ?></span>
                        </td>
                        <td>
                            <?php if ($admin['role'] === 'super_admin'): ?>
                                <span class="srv-badge">Усі сервери</span>
                            <?php elseif (!empty($admin['servers'])): ?>
                                <?php foreach ($admin['servers'] as $srv): ?>
                                    <span class="srv-badge"><?= htmlspecialchars($srv) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-error" style="font-size: 13px;">Немає доступних серверів</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space: nowrap;">
                            <?php if ($admin['role'] !== 'super_admin'): ?>
                                <button type="button" class="btn-edit-perm"
                                    onclick="openPermissionsModal(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($admin['servers']), ENT_QUOTES) ?>)" style="margin-right: 4px;">
                                    Права доступу
                                </button>
                            <?php endif; ?>

                            <?php if ($admin['id'] !== (int)$user['id']): ?>
                                <form method="post" style="display:inline"
                                    onsubmit="return confirm('Ви впевнені що хочете видалити цього користувача?')">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= $admin['id'] ?>">
                                    <button type="submit" class="btn-delete">Видалити</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Permissions -->
    <div class="modal-overlay" id="permissionsModal" style="display:none">
        <div class="modal-container">
            <div class="modal-header">
                <span class="modal-title" id="modalTitle">Налаштування доступу</span>
                <button type="button" class="modal-close" onclick="closeModal()">×</button>
            </div>
            <form method="post" id="permissionsForm">
                <div class="modal-body" style="background:#fff;">
                    <input type="hidden" name="action" value="update_permissions">
                    <input type="hidden" name="admin_id" id="modalAdminId" value="">

                    <div class="form-group">
                        <label style="font-size:12px; font-weight:600; color:var(--secondary-text); text-transform:uppercase; margin-bottom:8px; display:block;">Доступні сервери:</label>
                        <div id="serverCheckboxes" class="checkbox-list">
                            <?php foreach ($allServers as $srv): ?>
                            <label class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px 0; font-size:14px;">
                                <input type="checkbox" name="servers[]" value="<?= htmlspecialchars($srv) ?>">
                                <span><?= htmlspecialchars($srv) ?></span>
                            </label>
                            <?php endforeach; ?>
                            <?php if (empty($allServers)): ?>
                                <div style="color:var(--secondary-text); font-size:13px; font-style:italic;">Немає виявлених серверів у базі даних</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label for="customServer" style="font-size:12px; font-weight:600; color:var(--secondary-text); text-transform:uppercase; margin-bottom:8px; display:block;">Додати новий сервер вручну</label>
                        <input type="text" id="customServer" name="custom_server" class="form-control" placeholder="Наприклад: server2.domain.com" style="width:100%;">
                    </div>
                </div>
                <div class="modal-footer" style="padding:16px 20px; border-top:1px solid var(--border-color); display:flex; justify-content:flex-end; gap:10px; background:#fff;">
                    <button type="button" class="btn-action btn-secondary" style="background:var(--bg-color); color:var(--text-color); border:1px solid var(--border-color);" onclick="closeModal()">Скасувати</button>
                    <button type="submit" class="btn-action">Зберегти</button>
                </div>
            </form>
        </div>
    </div>

</main>

<script>
function openPermissionsModal(id, name, assignedServers) {
    document.getElementById('modalAdminId').value = id;
    document.getElementById('modalTitle').textContent = 'Налаштування доступу для ' + name;

    var checkboxes = document.querySelectorAll('#serverCheckboxes input[type="checkbox"]');
    checkboxes.forEach(function(cb) {
        cb.checked = assignedServers.indexOf(cb.value) !== -1;
    });

    document.getElementById('customServer').value = '';
    document.getElementById('permissionsModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('permissionsModal').style.display = 'none';
}
</script>

<?php require 'includes/footer.php'; ?>
