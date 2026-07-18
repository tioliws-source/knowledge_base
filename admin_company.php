<?php
// ==================================================
// ФАЙЛ: admin_company.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

// Только админ
if (($_SESSION['role'] ?? '') != 'admin') {
    die('Доступ запрещён');
}

// ==========================================================
// ОБРАБОТКА ВОССТАНОВЛЕНИЯ КОМПАНИИ (AJAX)
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'restore_company') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    $company_id = (int)($_POST['company_id'] ?? 0);
    if ($company_id) {
        $stmt = $mysqli->prepare("UPDATE companies SET deleted = 0, deleted_at = NULL WHERE id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $stmt->close();
        writeLog($_SESSION['user_id'], 'restore_company', "Восстановлена компания ID $company_id");
        echo 'ok';
    } else {
        echo 'invalid_id';
    }
    exit;
}

// ==========================================================
// ОБРАБОТКА ДОБАВЛЕНИЯ КОМПАНИИ (AJAX)
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'add_company') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Ошибка безопасности']);
        exit;
    }
    
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_public = (int)($_POST['is_public'] ?? 0);
    $is_editor = (int)($_POST['is_editor'] ?? 0);
    $is_employee = (int)($_POST['is_employee'] ?? 0);
    
    if (empty($name)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Название компании обязательно']);
        exit;
    }
    
    if (mb_strlen($name) > 100) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Название слишком длинное (макс. 100 символов)']);
        exit;
    }
    
    // Создаём компанию через prepared statement
    $stmt = $mysqli->prepare("INSERT INTO companies (name, description, is_public, allow_editors, allow_employees) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiii", $name, $description, $is_public, $is_editor, $is_employee);
    $stmt->execute();
    $company_id = $stmt->insert_id;
    $stmt->close();
    
    writeLog($_SESSION['user_id'], 'add_company', "Создана компания: $name (ID: $company_id)");
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Компания создана!']);
    exit;
}

// ==========================================================
// ОБНОВЛЕНИЕ ДОСТУПА КОМПАНИИ (из edit_company.php)
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'update_company_access') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    $company_id = (int)($_POST['company_id'] ?? 0);
    $is_public = (int)($_POST['is_public'] ?? 0);
    $is_editor = (int)($_POST['is_editor'] ?? 0);
    $is_employee = (int)($_POST['is_employee'] ?? 0);
    
    if (!$company_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Компания не найдена']);
        exit;
    }
    
    // Обновляем поля напрямую в таблице companies (без маркерной системы!)
    $stmt = $mysqli->prepare("UPDATE companies SET is_public = ?, allow_editors = ?, allow_employees = ? WHERE id = ?");
    $stmt->bind_param("iiii", $is_public, $is_editor, $is_employee, $company_id);
    $stmt->execute();
    $stmt->close();
    
    writeLog($_SESSION['user_id'], 'update_company_access', "Обновлён доступ компании ID $company_id (guest: $is_public, editor: $is_editor, employee: $is_employee)");
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Настройки доступа сохранены']);
    exit;
}

// ==========================================================
// ПОЛУЧИТЬ ДАННЫЕ ДОСТУПА КОМПАНИИ (для edit_company.php)
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'get_company_access') {
    $company_id = (int)($_POST['company_id'] ?? 0);
    if (!$company_id) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Компания не найдена']);
        exit;
    }
    
    // Читаем напрямую из таблицы companies
    $stmt = $mysqli->prepare("SELECT is_public, allow_editors, allow_employees FROM companies WHERE id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $company = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$company) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Компания не найдена']);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'is_public' => (int)$company['is_public'],
        'is_editor' => (int)$company['allow_editors'],
        'is_employee' => (int)$company['allow_employees']
    ]);
    exit;
}

// ==========================================================
// ПОЛУЧАЕМ ПАРАМЕТРЫ ФИЛЬТРА
// ==========================================================
$filter_deleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';
$is_hidden = $_SESSION['company_section_hidden'] ?? true;
$toggle_label = $is_hidden ? 'Раскрыть' : 'Скрыть';
$toggle_icon = $is_hidden ? '▶' : '▼';

// Получаем компании с учётом фильтра через prepared statement
if ($filter_deleted) {
    $stmt = $mysqli->prepare("SELECT * FROM companies WHERE deleted = 1 ORDER BY deleted_at DESC");
} else {
    $stmt = $mysqli->prepare("SELECT * FROM companies WHERE deleted = 0 ORDER BY name");
}
$stmt->execute();
$companies = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Управление компаниями</title>
<link rel="stylesheet" href="style.css">
<style>
.company-section {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.company-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
    flex-wrap: wrap;
    gap: 10px;
}
.company-section-header h2 {
    margin: 0;
    color: #1a2a4a;
    font-size: 22px;
}
.company-section-header .actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.company-section-content {
    margin-top: 20px;
    transition: all 0.3s ease;
}
.company-section-content.hidden {
    display: none;
}
.company-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}
.company-table th, .company-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
}
.company-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #1a2a4a;
}
.company-table tr:hover {
    background: #f8f9fa;
}
.btn-edit {
    background: #007bff;
    color: white;
    border: none;
    padding: 5px 14px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
}
.btn-edit:hover {
    background: #0056b3;
}
.btn-add {
    background: #28a745;
    color: white;
    border: none;
    padding: 8px 18px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}
.btn-add:hover {
    background: #1e7e34;
}
.btn-restore {
    background: #ffc107;
    color: #333;
    border: none;
    padding: 5px 14px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
}
.btn-restore:hover {
    background: #e0a800;
}
.btn-filter {
    background: #f8f9fa;
    border: 1px solid #ccc;
    padding: 5px 14px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
}
.btn-filter:hover {
    background: #e9ecef;
}
.btn-filter.active {
    background: #dc3545;
    color: white;
    border-color: #dc3545;
}
.btn-sm {
    padding: 4px 12px;
    font-size: 12px;
}
.btn-outline {
    background: #f8f9fa;
    border: 1px solid #ccc;
    color: #333;
}
.btn-outline:hover {
    background: #e9ecef;
}
.trash-item td {
    background: #fff5f5;
}
.access-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    margin: 2px 4px 2px 0;
}
.access-badge.guest { background: #d1ecf1; color: #0c5460; }
.access-badge.editor { background: #cce5ff; color: #004085; }
.access-badge.employee { background: #d4edda; color: #155724; }
.access-badge.admin-only { background: #f8d7da; color: #721c24; }
/* ===== МОДАЛЬНОЕ ОКНО ДОБАВЛЕНИЯ КОМПАНИИ ===== */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.4);
    z-index: 99999;
    display: flex;
    justify-content: center;
    align-items: center;
}
.modal-box {
    background: #fff;
    border-radius: 12px;
    padding: 30px;
    max-width: 550px;
    width: 95%;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2);
    max-height: 90vh;
    overflow-y: auto;
}
.modal-box h3 {
    margin-top: 0;
    color: #1a2a4a;
}
.modal-box .form-group {
    margin-bottom: 15px;
}
.modal-box .form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
}
.modal-box .form-group input,
.modal-box .form-group textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    box-sizing: border-box;
    font-size: 14px;
}
.modal-box .form-group textarea {
    min-height: 60px;
    resize: vertical;
}
.modal-box .form-group .hint {
    color: #6c757d;
    font-size: 12px;
    margin-top: 4px;
}
.modal-box .form-group .checkbox-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
}
.modal-box .form-group .checkbox-row input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
    flex-shrink: 0;
    margin: 0;
}
.modal-box .form-group .checkbox-row label {
    margin: 0;
    cursor: pointer;
    font-weight: 500;
    font-size: 14px;
    color: #333;
}
.modal-box .buttons {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 20px;
}
.modal-box .buttons .btn {
    padding: 8px 24px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}
.modal-box .buttons .btn-success {
    background: #28a745;
    color: #fff;
}
.modal-box .buttons .btn-success:hover {
    background: #1e7e34;
}
.modal-box .buttons .btn-outline {
    background: #f8f9fa;
    border: 1px solid #ccc;
    color: #333;
}
.modal-box .buttons .btn-outline:hover {
    background: #e9ecef;
}
/* ===== УВЕДОМЛЕНИЯ ===== */
.notification {
    position: fixed;
    top: 30px;
    left: 50%;
    transform: translateX(-50%) translateY(-20px);
    padding: 16px 32px;
    border-radius: 10px;
    color: #fff;
    font-weight: 500;
    font-size: 15px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    z-index: 999999;
    opacity: 0;
    transition: all 0.4s ease;
    pointer-events: none;
    max-width: 500px;
    width: auto;
    text-align: center;
    border-left: 5px solid rgba(255,255,255,0.3);
}
.notification.show {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
    pointer-events: auto;
}
.notification.success {
    background: #28a745;
    border-left-color: #1e7e34;
}
.notification.error {
    background: #dc3545;
    border-left-color: #bd2130;
}
.notification.info {
    background: #007bff;
    border-left-color: #0056b3;
}
.notification.warning {
    background: #ffc107;
    border-left-color: #e0a800;
    color: #333;
}
</style>
</head>
<body>
<div class="company-section">
    <div class="company-section-header">
        <h2>🏢 Компании</h2>
        <div class="actions">
            <button class="btn-add" onclick="event.stopPropagation(); showAddCompanyModal()">➕ Добавить</button>
            <button class="btn-filter <?= $filter_deleted ? 'active' : '' ?>" onclick="window.location.href='admin.php?deleted=<?= $filter_deleted ? '0' : '1' ?>'">
                🗑️ Удалены
            </button>
            <button class="btn btn-sm btn-outline" id="toggle-company-btn" onclick="toggleCompanySection()">
                <?= $toggle_icon ?> <?= $toggle_label ?>
            </button>
        </div>
    </div>
    <div class="company-section-content <?= $is_hidden ? 'hidden' : '' ?>" id="company-section-content">
        <?php if ($companies && $companies->num_rows > 0): ?>
        <table class="company-table">
            <thead>
                <tr>
                    <th>Название компании</th>
                    <th>Доступ</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($company = $companies->fetch_assoc()):
                    // Читаем доступ напрямую из полей companies
                    $access_html = '';
                    if ($company['is_public']) {
                        $access_html .= '<span class="access-badge guest">👋 Гость</span>';
                    }
                    if ($company['allow_editors']) {
                        $access_html .= '<span class="access-badge editor">✏️ Редактор</span>';
                    }
                    if ($company['allow_employees']) {
                        $access_html .= '<span class="access-badge employee">👤 Сотрудник</span>';
                    }
                    if (empty($access_html)) {
                        $access_html = '<span class="access-badge admin-only">🔒 Только админ</span>';
                    }
                ?>
                <?php if ($filter_deleted): ?>
                <!-- Режим корзины -->
                <tr class="trash-item">
                    <td><strong><?= htmlspecialchars($company['name']) ?></strong></td>
                    <td><?= $company['deleted_at'] ? date('d.m.Y H:i', strtotime($company['deleted_at'])) : '—' ?></td>
                    <td>
                        <button class="btn-restore" onclick="restoreCompany(<?= (int)$company['id'] ?>)">↩️ Восстановить</button>
                    </td>
                </tr>
                <?php else: ?>
                <!-- Режим активных компаний -->
                <tr>
                    <td><strong><?= htmlspecialchars($company['name']) ?></strong></td>
                    <td><?= $access_html ?></td>
                    <td>
                        <button class="btn-edit" onclick="editCompany(<?= (int)$company['id'] ?>)">✏️ Редактировать</button>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="color:#999; padding:20px 0;">
            <?= $filter_deleted ? 'Нет удалённых компаний.' : 'Нет компаний. Нажмите «Добавить», чтобы создать первую.' ?>
        </p>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================== -->
<!-- МОДАЛЬНОЕ ОКНО ДОБАВЛЕНИЯ КОМПАНИИ -->
<!-- ========================================================== -->
<div id="add-company-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>➕ Добавить компанию</h3>
        <form id="add-company-form">
            <div class="form-group">
                <label>Название компании *</label>
                <input type="text" id="add-company-name" placeholder="Введите название компании" maxlength="100" required>
            </div>
            <div class="form-group">
                <label>Описание</label>
                <textarea id="add-company-description" placeholder="Краткое описание компании"></textarea>
            </div>
            <div class="form-group">
                <div class="checkbox-row">
                    <input type="checkbox" id="add-company-public" value="1">
                    <label for="add-company-public">👋 Компания доступна для гостей</label>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" id="add-company-editor" value="1">
                    <label for="add-company-editor">✏️ Компания доступна для редакторов</label>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" id="add-company-employee" value="1">
                    <label for="add-company-employee">👤 Компания доступна для сотрудников</label>
                </div>
                <div class="hint">Если ни одна галочка не выбрана — компания будет доступна только администратору</div>
            </div>
            <div class="buttons">
                <button type="button" class="btn btn-outline" onclick="closeAddCompanyModal()">Отмена</button>
                <button type="button" class="btn btn-success" id="add-company-submit">💾 Создать</button>
            </div>
        </form>
    </div>
</div>

<script>
// CSRF-токен из мета-тега
var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ==========================================================
// УВЕДОМЛЕНИЯ
// ==========================================================
function showNotification(message, type) {
    type = type || 'success';
    var duration = type === 'error' ? 5000 : 3000;
    var notification = document.createElement('div');
    notification.className = 'notification ' + type;
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(function() { notification.classList.add('show'); }, 20);
    setTimeout(function() {
        notification.classList.remove('show');
        setTimeout(function() { notification.remove(); }, 400);
    }, duration);
}

// ==========================================================
// ПЕРЕКЛЮЧЕНИЕ ВИДИМОСТИ РАЗДЕЛА
// ==========================================================
function toggleCompanySection() {
    var content = document.getElementById('company-section-content');
    var btn = document.getElementById('toggle-company-btn');
    var isHidden = content.classList.contains('hidden');
    if (isHidden) {
        content.classList.remove('hidden');
        btn.innerHTML = '▼ Скрыть';
    } else {
        content.classList.add('hidden');
        btn.innerHTML = '▶ Раскрыть';
    }
    fetch('admin_ajax.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=toggle_section&state=' + (isHidden ? 'show' : 'hide') + '&csrf_token=' + encodeURIComponent(csrfToken)
    });
}

// ==========================================================
// РЕДАКТИРОВАНИЕ КОМПАНИИ
// ==========================================================
function editCompany(companyId) {
    var modal = document.createElement('div');
    modal.id = 'company-modal';
    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.width = '100%';
    modal.style.height = '100%';
    modal.style.background = 'rgba(0,0,0,0.5)';
    modal.style.zIndex = '9999';
    modal.style.display = 'flex';
    modal.style.justifyContent = 'center';
    modal.style.alignItems = 'center';
    modal.style.padding = '20px';
    modal.style.boxSizing = 'border-box';
    
    var iframe = document.createElement('iframe');
    iframe.src = 'edit_company.php?company_id=' + companyId;
    iframe.style.width = '100%';
    iframe.style.maxWidth = '900px';
    iframe.style.height = '90vh';
    iframe.style.border = 'none';
    iframe.style.borderRadius = '12px';
    iframe.style.background = '#fff';
    iframe.style.boxShadow = '0 4px 24px rgba(0,0,0,0.2)';
    
    modal.appendChild(iframe);
    document.body.appendChild(modal);
    
    window.closeModal = function() {
        if (modal) modal.remove();
        location.reload();
    };
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });
}

// ==========================================================
// ВОССТАНОВЛЕНИЕ КОМПАНИИ ИЗ КОРЗИНЫ
// ==========================================================
function restoreCompany(companyId) {
    if (!confirm('Восстановить компанию?')) return;
    
    fetch('admin_company.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=restore_company&company_id=' + companyId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.text())
    .then(data => {
        if (data === 'ok') {
            showNotification('✅ Компания восстановлена', 'success');
            location.reload();
        } else {
            showNotification('Ошибка: ' + data, 'error');
        }
    });
}

// ==========================================================
// ДОБАВЛЕНИЕ КОМПАНИИ
// ==========================================================
function showAddCompanyModal() {
    document.getElementById('add-company-modal').style.display = 'flex';
}

function closeAddCompanyModal() {
    document.getElementById('add-company-modal').style.display = 'none';
    document.getElementById('add-company-form').reset();
}

document.getElementById('add-company-submit')?.addEventListener('click', function() {
    var name = document.getElementById('add-company-name').value.trim();
    var description = document.getElementById('add-company-description').value.trim();
    var is_public = document.getElementById('add-company-public').checked ? 1 : 0;
    var is_editor = document.getElementById('add-company-editor').checked ? 1 : 0;
    var is_employee = document.getElementById('add-company-employee').checked ? 1 : 0;
    
    if (!name) {
        showNotification('Введите название компании', 'error');
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'add_company');
    formData.append('name', name);
    formData.append('description', description);
    formData.append('is_public', is_public);
    formData.append('is_editor', is_editor);
    formData.append('is_employee', is_employee);
    formData.append('csrf_token', csrfToken);
    
    fetch('admin_company.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showNotification('✅ ' + data.message, 'success');
            closeAddCompanyModal();
            setTimeout(function() { location.reload(); }, 500);
        } else {
            showNotification('❌ ' + data.message, 'error');
        }
    })
    .catch(err => {
        showNotification('❌ Ошибка сети: ' + err, 'error');
    });
});
</script>
</body>
</html>