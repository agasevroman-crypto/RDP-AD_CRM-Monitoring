<?php
/**
 * tokens.php — Керування API токенами
 */
require_once 'config.php';
require_super_admin();

$user = get_logged_in_user();
$pdo  = get_db_connection();
$successToken = ''; $error = '';

// ── Обробка POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'generate') {
        $tokenName = trim($_POST['token_name'] ?? '');
        if ($tokenName === '') {
            $error = 'Вкажіть назву токена.';
        } else {
            $rawToken = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO api_tokens (name, token, permissions, created_by) VALUES (?,?,?,?)")
                ->execute([$tokenName, $rawToken, 'rdp,ad', $user['id']]);
            $successToken = $rawToken;
        }
    }

    if ($_POST['action'] === 'revoke') {
        $tokenId = (int)($_POST['token_id'] ?? 0);
        $pdo->prepare("DELETE FROM api_tokens WHERE id = ?")->execute([$tokenId]);
        header('Location: tokens.php');
        exit;
    }

    if ($_POST['action'] === 'update_name') {
        $tokenId   = (int)($_POST['token_id'] ?? 0);
        $tokenName = trim($_POST['token_name'] ?? '');
        if ($tokenName === '') {
            $error = 'Назва не може бути порожньою.';
        } else {
            $pdo->prepare("UPDATE api_tokens SET name = ? WHERE id = ?")->execute([$tokenName, $tokenId]);
            header('Location: tokens.php');
            exit;
        }
    }
}

// ── Завантаження токенів ──────────────────────────────
$tokens = $pdo->query("
    SELECT t.*, u.username AS creator_username
    FROM api_tokens t
    LEFT JOIN users u ON t.created_by = u.id
    ORDER BY t.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Токени API';
$activePage = 'tokens';
require 'includes/header.php';
?>

<main>

    <div class="page-header-full">
        <h2>Керування API токенами</h2>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
    </div>

    <!-- Верхній ряд: Генерація та Довідка -->
    <div class="content-row">
        <!-- Ліва колонка: Генерація -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header"><div class="card-title">Згенерувати новий токен</div></div>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="generate">
                <div class="form-group">
                    <label>Назва / Призначення</label>
                    <input type="text" name="token_name" class="form-control" required placeholder="Наприклад: RDP Monitor Офіс" style="width: 100%;">
                </div>
                <button type="submit" class="btn-action">Згенерувати</button>
            </form>
        </div>

        <!-- Права колонка: Довідка -->
        <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div class="card-header"><div class="card-title">📌 Як використовувати</div></div>
                <div style="font-size:13px; color:var(--secondary-color); line-height:1.6; padding-top: 8px;">
                    Скопіюйте токен та вставте його в налаштування CRM клієнтських служб <strong>AD Monitor</strong> та <strong>RDP Monitor</strong> на кожному сервері.
                </div>
            </div>
        </div>
    </div>

    <!-- Нижній ряд: Список (на всю ширину) -->
    <div class="card" style="margin-top: 20px;">
        <?php if ($successToken !== ''): ?>
            <div class="token-display-box">
                <strong>✅ Токен успішно створено!</strong><br>
                <span style="font-size:13px;">Збережіть цей токен — він більше не буде показаний:</span>
                <div class="token-string"><?= e($successToken) ?></div>
            </div>
        <?php endif; ?>

        <div class="card-header"><div class="card-title">Існуючі токени</div></div>
        <?php if (empty($tokens)): ?>
            <div class="no-data-msg">Токени ще не створені</div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="white-space: nowrap;">Назва</th>
                        <th style="white-space: nowrap;">Частина токена</th>
                        <th style="white-space: nowrap;">Створено</th>
                        <th style="white-space: nowrap;">Створив</th>
                        <th style="white-space: nowrap;">Дозволи</th>
                        <th style="white-space: nowrap;">Дія</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $tok): ?>
                    <tr>
                        <td style="white-space: nowrap;"><strong><?= e($tok['name']) ?></strong></td>
                        <td style="white-space: nowrap;">
                            <code class="token-value" id="tok_val_<?= $tok['id'] ?>" data-raw="<?= e($tok['token']) ?>" data-masked="<?= e(substr($tok['token'], 0, 8)) ?>...<?= e(substr($tok['token'], -8)) ?>"><?= e(substr($tok['token'], 0, 8)) ?>...<?= e(substr($tok['token'], -8)) ?></code>
                            <button type="button" class="btn-edit-perm" onclick="toggleToken(<?= $tok['id'] ?>)" style="padding: 2px 6px; font-size: 11px; margin-left: 6px;">👁️</button>
                            <button type="button" class="btn-edit-perm" onclick="copyToClipboard('<?= e($tok['token']) ?>')" style="padding: 2px 6px; font-size: 11px; margin-left: 2px;">📋</button>
                        </td>
                        <td style="white-space: nowrap;"><?= date('d.m.Y H:i', strtotime($tok['created_at'])) ?></td>
                        <td style="white-space: nowrap;"><?= e($tok['creator_username'] ?? '—') ?></td>
                        <td style="white-space: nowrap;">
                            <?php foreach (explode(',', $tok['permissions'] ?? '') as $p):
                                $p = trim($p);
                                if ($p !== ''): ?>
                                    <span class="srv-badge"><?= e(strtoupper($p)) ?></span>
                                <?php endif;
                            endforeach; ?>
                        </td>
                        <td style="white-space: nowrap;">
                            <button type="button" class="btn-edit-perm" onclick="openEditModal(<?= $tok['id'] ?>, '<?= e(addslashes($tok['name'])) ?>')" style="margin-right: 4px;">Редагувати</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Ви впевнені що хочете анулювати цей токен?')">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="token_id" value="<?= $tok['id'] ?>">
                                <button type="submit" class="btn-delete">Анулювати</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- Модальне вікно для редагування назви токена (сервера) -->
<div id="editTokenModal" class="modal-overlay" onclick="closeEditModal(event)">
    <div class="modal-container" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">Редагувати назву токена (сервера)</div>
            <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_name">
            <input type="hidden" name="token_id" id="edit_token_id" value="">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="edit_token_name" style="display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--secondary-text); margin-bottom: 6px;">Назва / Призначення токена</label>
                    <input type="text" name="token_name" id="edit_token_name" class="form-control" required style="width: 100%; box-sizing: border-box;">
                </div>
                <div style="margin-top: 20px; text-align: right; display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn-action btn-secondary" onclick="closeEditModal()">Скасувати</button>
                    <button type="submit" class="btn-action">Зберегти</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, name) {
    document.getElementById('edit_token_id').value = id;
    document.getElementById('edit_token_name').value = name;
    document.getElementById('editTokenModal').style.display = 'flex';
}

function closeEditModal(event) {
    if (!event || event.target === document.getElementById('editTokenModal') || event.target.classList.contains('modal-close') || event.target.classList.contains('btn-secondary')) {
        document.getElementById('editTokenModal').style.display = 'none';
    }
}

function toggleToken(id) {
    var el = document.getElementById('tok_val_' + id);
    if (el.textContent.trim() === el.dataset.masked) {
        el.textContent = el.dataset.raw;
    } else {
        el.textContent = el.dataset.masked;
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert('Токен скопійовано в буфер обміну!');
    }, function(err) {
        alert('Не вдалося скопіювати: ' + err);
    });
}
</script>

<?php require 'includes/footer.php'; ?>
