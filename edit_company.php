<?php
// ==================================================
// ФАЙЛ: edit_company.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

if (($_SESSION['role'] ?? '') != 'admin') {
    die('Доступ запрещён');
}

$company_id = (int)($_POST['company_id'] ?? $_GET['company_id'] ?? 0);
if (!$company_id) {
    die('Компания не найдена');
}

// Получаем компанию через prepared statement
$stmt = $mysqli->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$company) {
    die('Компания не найдена');
}

$action = $_POST['action'] ?? '';

// ==========================================================
// ЗАГРУЗКА ДАННЫХ ДОСТУПА КОМПАНИИ (читаем из полей)
// ==========================================================
if ($action == 'get_company_access') {
    header('Content-Type: application/json');
    echo json_encode([
        'is_public' => (int)$company['is_public'],
        'is_editor' => (int)$company['allow_editors'],
        'is_employee' => (int)$company['allow_employees']
    ]);
    exit;
}

// ==========================================================
// ОБНОВЛЕНИЕ ДОСТУПА КОМПАНИИ (пишем в поля)
// ==========================================================
if ($action == 'update_company_access') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    $is_public = (int)($_POST['is_public'] ?? 0);
    $is_editor = (int)($_POST['is_editor'] ?? 0);
    $is_employee = (int)($_POST['is_employee'] ?? 0);
    
    $stmt = $mysqli->prepare("UPDATE companies SET is_public = ?, allow_editors = ?, allow_employees = ? WHERE id = ?");
    $stmt->bind_param("iiii", $is_public, $is_editor, $is_employee, $company_id);
    $stmt->execute();
    $stmt->close();
    
    writeLog($_SESSION['user_id'], 'update_company_access', "Обновлён доступ компании ID $company_id");
    echo 'ok';
    exit;
}

// ==========================================================
// СОХРАНЕНИЕ НАЗВАНИЯ
// ==========================================================
if ($action == 'save_name') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    $new_name = trim($_POST['name'] ?? '');
    if ($new_name && mb_strlen($new_name) <= 100) {
        $stmt = $mysqli->prepare("UPDATE companies SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $new_name, $company_id);
        $stmt->execute();
        $stmt->close();
        writeLog($_SESSION['user_id'], 'edit_company_name', "Переименована компания ID $company_id в '$new_name'");
        echo 'ok';
    } else {
        echo 'Название не может быть пустым или слишком длинным';
    }
    exit;
}

// ==========================================================
// ЗАГРУЗКА ЛОГОТИПА (С ПРОВЕРКОЙ MIME)
// ==========================================================
if ($action == 'upload_logo') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $file = $_FILES['logo'];
        $tmp_name = $file['tmp_name'];
        $original_name = $file['name'];
        
        // Проверка размера (макс 2 МБ)
        if ($file['size'] > 2 * 1024 * 1024) {
            die('Размер файла не должен превышать 2 МБ');
        }
        
        // Проверка реального MIME-типа
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $tmp_name);
            finfo_close($finfo);
            $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($mime_type, $allowed_mime)) {
                die('Файл не является изображением (MIME: ' . htmlspecialchars($mime_type) . ')');
            }
            $ext_map = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif'
            ];
            $ext = $ext_map[$mime_type] ?? 'jpg';
        } else {
            // Fallback: проверка по расширению
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (!in_array($ext, $allowed)) {
                die('Разрешены только: JPG, PNG, WebP, GIF');
            }
        }
        
        $logo_dir = __DIR__ . '/uploads/logos/';
        if (!is_dir($logo_dir)) {
            mkdir($logo_dir, 0777, true);
        }
        
        $new_filename = 'logo_' . $company_id . '_' . time() . '.' . $ext;
        $target_path = $logo_dir . $new_filename;
        
        if (move_uploaded_file($tmp_name, $target_path)) {
            $logo_path = '/uploads/logos/' . $new_filename;
            $stmt = $mysqli->prepare("UPDATE companies SET logo = ? WHERE id = ?");
            $stmt->bind_param("si", $logo_path, $company_id);
            $stmt->execute();
            $stmt->close();
            writeLog($_SESSION['user_id'], 'upload_logo', "Загружен логотип для компании ID $company_id");
            echo $logo_path;
        } else {
            echo 'Ошибка загрузки файла';
        }
    } else {
        echo 'Файл не выбран или ошибка при загрузке';
    }
    exit;
}

// ==========================================================
// СОХРАНЕНИЕ ДОСТУПА К РАЗДЕЛАМ
// ==========================================================
if ($action == 'save_section_access') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    $section_id = (int)($_POST['section_id'] ?? 0);
    $company_id_post = (int)($_POST['company_id'] ?? 0);
    
    if (!$section_id || !$company_id_post) {
        die('Ошибка: не указаны ID');
    }
    
    // Проверяем, что раздел принадлежит этой компании
    $stmt = $mysqli->prepare("SELECT id FROM sections WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $section_id, $company_id_post);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0) {
        $stmt->close();
        die('Ошибка: раздел не принадлежит этой компании');
    }
    $stmt->close();
    
    // Удаляем старые права
    $stmt = $mysqli->prepare("DELETE FROM section_access WHERE section_id = ?");
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $stmt->close();
    
    // Добавляем новые права
    $roles = ['editor', 'employee', 'guest'];
    foreach ($roles as $role) {
        $access_type = $_POST['role_' . $role] ?? 'allow';
        $except_users = $_POST['except_' . $role] ?? '';
        
        // Валидация access_type
        if (!in_array($access_type, ['allow', 'deny', 'except'])) {
            $access_type = 'allow';
        }
        
        $stmt = $mysqli->prepare("INSERT INTO section_access (section_id, role, access_type, except_users) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $section_id, $role, $access_type, $except_users);
        $stmt->execute();
        $stmt->close();
    }
    
    writeLog($_SESSION['user_id'], 'edit_section_access', "Изменён доступ к разделу ID $section_id");
    echo 'ok';
    exit;
}

// ==========================================================
// ЗАГРУЗКА ДОСТУПА К РАЗДЕЛАМ
// ==========================================================
if ($action == 'get_section_access') {
    $section_id = (int)($_POST['section_id'] ?? 0);
    $company_id_post = (int)($_POST['company_id'] ?? 0);
    
    if (!$section_id || !$company_id_post) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Не указаны ID']);
        exit;
    }
    
    // Проверяем, что раздел принадлежит этой компании
    $stmt = $mysqli->prepare("SELECT id FROM sections WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $section_id, $company_id_post);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0) {
        $stmt->close();
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Раздел не принадлежит этой компании']);
        exit;
    }
    $stmt->close();
    
    $stmt = $mysqli->prepare("SELECT role, access_type, except_users FROM section_access WHERE section_id = ?");
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[$row['role']] = [
            'access_type' => $row['access_type'],
            'except_users' => $row['except_users']
        ];
    }
    $stmt->close();
    
    // Заполняем значения по умолчанию
    foreach (['editor', 'employee', 'guest'] as $role) {
        if (!isset($data[$role])) {
            $data[$role] = [
                'access_type' => 'allow',
                'except_users' => ''
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ==========================================================
// УДАЛЕНИЕ КОМПАНИИ (С ЗАЩИТОЙ ОТ ОБХОДА ТАЙМЕРА)
// ==========================================================
if ($action == 'delete_company') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    // ВАЖНО: проверяем timestamp из сессии, а не из POST!
    $session_ts = $_SESSION['delete_company_timestamp'] ?? 0;
    if (time() - $session_ts < 15) {
        echo 'Подождите 15 секунд перед подтверждением';
        exit;
    }
    
    // Очищаем timestamp после использования
    unset($_SESSION['delete_company_timestamp']);
    
    $stmt = $mysqli->prepare("UPDATE companies SET deleted = 1, deleted_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $stmt->close();
    
    writeLog($_SESSION['user_id'], 'delete_company', "Удалена компания ID $company_id ({$company['name']})");
    echo 'ok';
    exit;
}

// ==========================================================
// ЗАПРОС ТАЙМСТЕМПА ДЛЯ УДАЛЕНИЯ (сохраняем в сессию)
// ==========================================================
if ($action == 'request_delete_timestamp') {
    $_SESSION['delete_company_timestamp'] = time();
    echo 'ok';
    exit;
}

// ==========================================================
// ОТВЯЗКА ПОЛЬЗОВАТЕЛЯ
// ==========================================================
if ($action == 'unlink_user') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id) {
        $stmt = $mysqli->prepare("DELETE FROM access WHERE user_id = ? AND company_id = ?");
        $stmt->bind_param("ii", $user_id, $company_id);
        $stmt->execute();
        $stmt->close();
        writeLog($_SESSION['user_id'], 'unlink_user', "Отвязан пользователь ID $user_id от компании ID $company_id");
        echo 'ok';
    } else {
        echo 'Ошибка: пользователь не указан';
    }
    exit;
}

// ==========================================================
// ПОЛУЧАЕМ ДАННЫЕ ДЛЯ ОТОБРАЖЕНИЯ
// ==========================================================
$all_editors = $mysqli->query("SELECT id, login, full_name FROM users WHERE role = 'editor' AND deleted = 0");
$all_employees = $mysqli->query("SELECT id, login, full_name FROM users WHERE role = 'employee' AND deleted = 0");

$stmt = $mysqli->prepare("
    SELECT u.id, u.login, u.full_name FROM users u
    JOIN access a ON a.user_id = u.id
    WHERE a.company_id = ? AND u.role = 'editor' AND u.deleted = 0
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$editors = $stmt->get_result();
$stmt->close();

$stmt = $mysqli->prepare("
    SELECT u.id, u.login, u.full_name FROM users u
    JOIN access a ON a.user_id = u.id
    WHERE a.company_id = ? AND u.role = 'employee' AND u.deleted = 0
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$employees = $stmt->get_result();
$stmt->close();

// Получаем логотип и формируем превью
$logo_path = $company['logo'] ?? '';
if ($logo_path && file_exists(__DIR__ . $logo_path)) {
    // Защита от XSS в имени файла
    $logo_html = '<img src="' . htmlspecialchars($logo_path) . '" style="max-height:100px;max-width:200px;border:1px solid #ddd;border-radius:8px;padding:5px;">';
} else {
    $logo_html = '<span style="color:#999;">Логотип не загружен</span>';
}

// Получаем настройки доступа для чекбоксов (читаем напрямую из полей)
$is_editor_checked = (int)($company['allow_editors'] ?? 0);
$is_employee_checked = (int)($company['allow_employees'] ?? 0);

$stmt = $mysqli->prepare("
    SELECT id, parent_id, title
    FROM sections
    WHERE company_id = ?
    ORDER BY parent_id, title
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$tree_sections = $stmt->get_result();
$stmt->close();

function buildTree($items, $parent = 0) {
    $result = [];
    foreach ($items as $item) {
        if ($item['parent_id'] == $parent) {
            $children = buildTree($items, $item['id']);
            $item['children'] = $children;
            $result[] = $item;
        }
    }
    return $result;
}

$tree = buildTree($tree_sections->fetch_all(MYSQLI_ASSOC));

function renderTree($tree) {
    echo '<ul style="list-style:none;padding-left:20px;margin:0;">';
    foreach ($tree as $node) {
        echo '<li style="padding:4px 0;">';
        echo '<a href="#" onclick="openSectionAccess(' . (int)$node['id'] . '); return false;" style="cursor:pointer;color:#007bff;text-decoration:none;">';
        echo '📄 ' . htmlspecialchars($node['title']);
        echo '</a>';
        if (!empty($node['children'])) {
            renderTree($node['children']);
        }
        echo '</li>';
    }
    echo '</ul>';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Редактирование компании</title>
<style>
* { box-sizing: border-box; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: rgba(0,0,0,0.5);
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    margin: 0;
    padding: 20px;
}
.edit-container {
    max-width: 800px;
    width: 100%;
    background: #fff;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.2);
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
}
.edit-container h1 { margin-top: 0; color: #1a2a4a; border-bottom: 2px solid #e9ecef; padding-bottom: 15px; }
.field { margin-bottom: 20px; }
.field label { font-weight: 600; display: block; margin-bottom: 5px; color: #333; }
.field input[type="text"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; }
.field input[type="file"] { padding: 8px; }
.btn { padding: 8px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.2s; }
.btn-primary { background: #007bff; color: #fff; }
.btn-primary:hover { background: #0056b3; }
.btn-success { background: #28a745; color: #fff; }
.btn-success:hover { background: #1e7e34; }
.btn-danger { background: #dc3545; color: #fff; }
.btn-danger:hover { background: #bd2130; }
.btn-outline { background: #f8f9fa; border: 1px solid #ccc; color: #333; }
.btn-outline:hover { background: #e9ecef; }
.btn-sm { padding: 4px 12px; font-size: 12px; }
.checkbox-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 4px 0;
}
.checkbox-row input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
    flex-shrink: 0;
    margin: 0;
}
.checkbox-row label {
    margin: 0;
    cursor: pointer;
    font-weight: 500;
    font-size: 14px;
    color: #333;
}
.user-list { margin-top: 10px; border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; background: #f8f9fa; }
.user-item { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid #eee; }
.user-item:last-child { border-bottom: none; }
.delete-section { margin-top: 30px; padding-top: 20px; border-top: 2px solid #dc3545; }
.timer { font-size: 24px; font-weight: bold; color: #dc3545; margin: 10px 0; }
.hidden { display: none; }
.logo-preview { margin: 10px 0; }
.close-btn {
    position: sticky;
    top: 0;
    float: right;
    background: #f8f9fa;
    border: 1px solid #ccc;
    border-radius: 50%;
    width: 36px;
    height: 36px;
    font-size: 20px;
    cursor: pointer;
    line-height: 34px;
    text-align: center;
}
.close-btn:hover { background: #e9ecef; }
.btn-close-modal { margin-top: 20px; }
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
    z-index: 99999;
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
}
.notification.success { background: #28a745; border-left-color: #1e7e34; }
.notification.error { background: #dc3545; border-left-color: #bd2130; }
.notification.info { background: #007bff; border-left-color: #0056b3; }
.custom-confirm-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.3);
    z-index: 99998;
    display: flex;
    justify-content: center;
    align-items: center;
}
.custom-confirm-box {
    background: #fff;
    border-radius: 12px;
    padding: 30px 40px;
    max-width: 450px;
    width: 90%;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2);
    text-align: center;
}
.custom-confirm-box h3 { margin: 0 0 10px 0; color: #1a2a4a; font-size: 18px; }
.custom-confirm-box p { margin: 0 0 20px 0; color: #555; font-size: 15px; }
.custom-confirm-box .buttons { display: flex; gap: 12px; justify-content: center; }
.custom-confirm-box .buttons .btn { min-width: 100px; padding: 10px 20px; font-size: 15px; }
#confirm-delete-btn:disabled {
    opacity: 0.5 !important;
    cursor: not-allowed !important;
}
#tree-container {
    margin-top: 10px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 15px;
    background: #f8f9fa;
    max-height: 300px;
    overflow-y: auto;
}
#tree-container ul { list-style: none; padding-left: 20px; margin: 0; }
#tree-container li { padding: 4px 0; }
#tree-container a { cursor: pointer; color: #007bff; text-decoration: none; }
#tree-container a:hover { text-decoration: underline; }
.section-access-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.3);
    z-index: 99997;
    display: flex;
    justify-content: center;
    align-items: center;
}
.section-access-box {
    background: #fff;
    border-radius: 12px;
    padding: 30px 40px;
    max-width: 700px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2);
}
.section-access-box h3 { margin: 0 0 20px 0; color: #1a2a4a; border-bottom: 2px solid #e9ecef; padding-bottom: 15px; }
.section-access-box .role-row { display: flex; align-items: center; gap: 20px; padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
.section-access-box .role-row .role-label { font-weight: 600; width: 100px; flex-shrink: 0; }
.section-access-box .role-row select { padding: 6px 12px; border: 1px solid #ccc; border-radius: 6px; min-width: 120px; }
.section-access-box .role-row .except-container { display: none; gap: 10px; align-items: center; flex-wrap: wrap; }
.section-access-box .role-row .except-container.show { display: flex; }
.section-access-box .role-row .except-container select { min-width: 150px; max-height: 100px; }
.section-access-box .role-row .except-container .selected-users { display: flex; flex-wrap: wrap; gap: 5px; }
.section-access-box .role-row .except-container .selected-users .user-tag { background: #e9ecef; border-radius: 4px; padding: 2px 8px; font-size: 12px; display: flex; align-items: center; gap: 4px; }
.section-access-box .role-row .except-container .selected-users .user-tag .remove { cursor: pointer; color: #dc3545; font-weight: bold; }
.section-access-box .buttons { margin-top: 20px; display: flex; gap: 12px; justify-content: flex-end; }
</style>
</head>
<body>
<div class="edit-container">
    <button class="close-btn" onclick="window.parent.closeModal()">✕</button>
    <h1>✏️ Редактирование компании</h1>
    
    <!-- 1. НАЗВАНИЕ -->
    <div class="field">
        <label>Название компании</label>
        <div style="display:flex; gap:10px;">
            <input type="text" id="company-name" value="<?= htmlspecialchars($company['name']) ?>" maxlength="100" style="flex:1;">
            <button class="btn btn-primary" onclick="saveName()">Сохранить</button>
        </div>
    </div>
    
    <!-- 2. ЛОГОТИП -->
    <div class="field">
        <label>Логотип компании</label>
        <div class="logo-preview" id="logo-preview"><?= $logo_html ?></div>
        <div style="display:flex; gap:10px; align-items:center; margin-top:10px;">
            <input type="file" id="logo-file" accept=".jpg,.jpeg,.png,.webp,.gif">
            <button class="btn btn-success" onclick="uploadLogo()">Загрузить</button>
        </div>
        <div style="font-size:12px;color:#666;margin-top:4px;">Форматы: JPG, PNG, WebP, GIF. Макс. размер: 2 МБ</div>
    </div>
    
    <!-- 3. ДОСТУП КОМПАНИИ -->
    <div class="field">
        <label>🔐 Доступ к компании</label>
        <div style="margin-top:8px;">
            <div class="checkbox-row">
                <input type="checkbox" id="edit-company-public" <?= $company['is_public'] ? 'checked' : '' ?>>
                <label for="edit-company-public">👋 Компания доступна для гостей</label>
            </div>
            <div class="checkbox-row">
                <input type="checkbox" id="edit-company-editor" <?= $is_editor_checked ? 'checked' : '' ?>>
                <label for="edit-company-editor">✏️ Компания доступна для редакторов</label>
            </div>
            <div class="checkbox-row">
                <input type="checkbox" id="edit-company-employee" <?= $is_employee_checked ? 'checked' : '' ?>>
                <label for="edit-company-employee">👤 Компания доступна для сотрудников</label>
            </div>
            <button class="btn btn-primary" onclick="saveCompanyAccess()" style="margin-top:10px;">💾 Сохранить доступ</button>
        </div>
    </div>
    
    <!-- 4. ПОЛЬЗОВАТЕЛИ С ДОСТУПОМ -->
    <div class="field">
        <label>👥 Пользователи с доступом</label>
        <button class="btn btn-outline" onclick="toggleAccess()">Показать/Скрыть список</button>
        <div id="access-list" class="user-list hidden">
            <h3>Редакторы</h3>
            <div id="editors-list">
                <?php if ($editors->num_rows > 0): ?>
                    <?php while ($user = $editors->fetch_assoc()): ?>
                        <div class="user-item" id="user-<?= (int)$user['id'] ?>">
                            <span><?= htmlspecialchars($user['login']) ?> (<?= htmlspecialchars($user['full_name'] ?? '') ?>)</span>
                            <button class="btn btn-danger btn-sm" onclick="unlinkUser(<?= (int)$user['id'] ?>)">Отвязать</button>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="color:#999;">Нет привязанных редакторов</p>
                <?php endif; ?>
            </div>
            <h3 style="margin-top:20px;">Сотрудники</h3>
            <div id="employees-list">
                <?php if ($employees->num_rows > 0): ?>
                    <?php while ($user = $employees->fetch_assoc()): ?>
                        <div class="user-item" id="user-<?= (int)$user['id'] ?>">
                            <span><?= htmlspecialchars($user['login']) ?> (<?= htmlspecialchars($user['full_name'] ?? '') ?>)</span>
                            <button class="btn btn-danger btn-sm" onclick="unlinkUser(<?= (int)$user['id'] ?>)">Отвязать</button>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="color:#999;">Нет привязанных сотрудников</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 5. ДРЕВО -->
    <div class="field">
        <label>🌳 Древо содержания</label>
        <button class="btn btn-outline" onclick="toggleTree()">Показать/Скрыть древо</button>
        <div id="tree-container" class="hidden">
            <?php if (!empty($tree)): ?>
                <?php renderTree($tree); ?>
            <?php else: ?>
                <p style="color:#999;">Нет разделов в этой компании</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 6. УДАЛЕНИЕ -->
    <div class="delete-section">
        <button class="btn btn-danger" onclick="showDeleteConfirm()">🗑️ УДАЛИТЬ КОМПАНИЮ</button>
        <p style="color:#6c757d; font-size:13px; margin-top:5px;">Компания будет перемещена в корзину и автоматически удалена через 15 дней.</p>
        <div id="delete-confirm" class="hidden" style="margin-top:20px; padding:20px; border:2px solid #dc3545; border-radius:8px; background:#fff5f5;">
            <p style="font-size:18px; font-weight:600; color:#dc3545;">Вы уверены, что хотите удалить компанию?</p>
            <p>Компания будет перемещена в корзину. У вас есть <strong>15 дней</strong>, чтобы восстановить её.</p>
            <div class="timer" id="timer">15</div>
            <div style="display:flex; gap:15px; margin-top:15px;">
                <button class="btn btn-success" onclick="cancelDelete()">✅ Отмена</button>
                <button class="btn btn-danger" id="confirm-delete-btn" disabled style="opacity:0.5;cursor:not-allowed;">🗑️ ПОДТВЕРДИТЬ</button>
            </div>
        </div>
    </div>
    
    <br>
    <button class="btn btn-outline btn-close-modal" onclick="window.parent.closeModal()">Закрыть</button>
</div>

<script>
// CSRF-токен
var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

// =============================================
// УВЕДОМЛЕНИЯ
// =============================================
function showNotification(message, type) {
    type = type || 'success';
    var duration = type === 'error' ? 5000 : 2500;
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

// =============================================
// КАСТОМНОЕ ПОДТВЕРЖДЕНИЕ
// =============================================
function customConfirm(message, callback) {
    var old = document.querySelector('.custom-confirm-overlay');
    if (old) old.remove();
    var overlay = document.createElement('div');
    overlay.className = 'custom-confirm-overlay';
    var box = document.createElement('div');
    box.className = 'custom-confirm-box';
    box.innerHTML = `
        <h3>⚠️ Подтверждение</h3>
        <p>${message}</p>
        <div class="buttons">
            <button class="btn btn-success" id="confirm-yes">✅ Да</button>
            <button class="btn btn-outline" id="confirm-no">❌ Нет</button>
        </div>
    `;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    document.getElementById('confirm-yes').addEventListener('click', function() {
        overlay.remove();
        callback(true);
    });
    document.getElementById('confirm-no').addEventListener('click', function() {
        overlay.remove();
        callback(false);
    });
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.remove();
            callback(false);
        }
    });
}

// =============================================
// 1. НАЗВАНИЕ
// =============================================
function saveName() {
    var name = document.getElementById('company-name').value.trim();
    if (!name) {
        showNotification('Название не может быть пустым', 'error');
        return;
    }
    if (name.length > 100) {
        showNotification('Название слишком длинное (макс. 100 символов)', 'error');
        return;
    }
    fetch('edit_company.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=save_name&company_id=<?= (int)$company_id ?>&name=' + encodeURIComponent(name) + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.text())
    .then(data => {
        if (data === 'ok') {
            showNotification('✅ Название сохранено');
        } else {
            showNotification('❌ Ошибка: ' + data, 'error');
        }
    })
    .catch(err => {
        showNotification('❌ Ошибка сети: ' + err, 'error');
    });
}

// =============================================
// 2. ЛОГОТИП
// =============================================
function uploadLogo() {
    var file = document.getElementById('logo-file').files[0];
    if (!file) {
        showNotification('Выберите файл', 'error');
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        showNotification('Размер файла не должен превышать 2 МБ', 'error');
        return;
    }
    var fd = new FormData();
    fd.append('action', 'upload_logo');
    fd.append('company_id', '<?= (int)$company_id ?>');
    fd.append('logo', file);
    fd.append('csrf_token', csrfToken);
    fetch('edit_company.php', { 
        method: 'POST', 
        headers: { 'X-CSRF-Token': csrfToken },
        body: fd 
    })
    .then(res => res.text())
    .then(data => {
        if (data.startsWith('/uploads/')) {
            document.getElementById('logo-preview').innerHTML = '<img src="' + data + '" style="max-height:100px;max-width:200px;border:1px solid #ddd;border-radius:8px;padding:5px;">';
            showNotification('✅ Логотип загружен');
        } else {
            showNotification('❌ Ошибка: ' + data, 'error');
        }
    })
    .catch(err => {
        showNotification('❌ Ошибка сети: ' + err, 'error');
    });
}

// =============================================
// 3. СОХРАНЕНИЕ ДОСТУПА КОМПАНИИ
// =============================================
function saveCompanyAccess() {
    var is_public = document.getElementById('edit-company-public').checked ? 1 : 0;
    var is_editor = document.getElementById('edit-company-editor').checked ? 1 : 0;
    var is_employee = document.getElementById('edit-company-employee').checked ? 1 : 0;
    var formData = new FormData();
    formData.append('action', 'update_company_access');
    formData.append('company_id', '<?= (int)$company_id ?>');
    formData.append('is_public', is_public);
    formData.append('is_editor', is_editor);
    formData.append('is_employee', is_employee);
    formData.append('csrf_token', csrfToken);
    fetch('edit_company.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data === 'ok') {
            showNotification('✅ Настройки доступа сохранены');
        } else {
            showNotification('❌ Ошибка: ' + data, 'error');
        }
    })
    .catch(err => {
        showNotification('❌ Ошибка сети: ' + err, 'error');
    });
}

// =============================================
// 4. ДОСТУП (ПОЛЬЗОВАТЕЛИ)
// =============================================
function toggleAccess() {
    document.getElementById('access-list').classList.toggle('hidden');
}

function unlinkUser(userId) {
    customConfirm('Отвязать этого пользователя от компании?', function(confirmed) {
        if (!confirmed) return;
        fetch('edit_company.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'action=unlink_user&company_id=<?= (int)$company_id ?>&user_id=' + userId + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(res => res.text())
        .then(data => {
            if (data === 'ok') {
                var el = document.getElementById('user-' + userId);
                if (el) el.remove();
                showNotification('✅ Пользователь отвязан');
            } else {
                showNotification('❌ Ошибка: ' + data, 'error');
            }
        })
        .catch(err => {
            showNotification('❌ Ошибка сети: ' + err, 'error');
        });
    });
}

// =============================================
// 5. ДЕРЕВО И УПРАВЛЕНИЕ ДОСТУПОМ К РАЗДЕЛАМ
// =============================================
function toggleTree() {
    var container = document.getElementById('tree-container');
    container.classList.toggle('hidden');
}

var allEditors = <?php
$editors_list = [];
while ($row = $all_editors->fetch_assoc()) {
    $editors_list[] = $row;
}
echo json_encode($editors_list);
?>;

var allEmployees = <?php
$employees_list = [];
while ($row = $all_employees->fetch_assoc()) {
    $employees_list[] = $row;
}
echo json_encode($employees_list);
?>;

function openSectionAccess(sectionId) {
    var sectionName = 'Раздел';
    var links = document.querySelectorAll('#tree-container a');
    for (var i = 0; i < links.length; i++) {
        if (links[i].getAttribute('onclick') && links[i].getAttribute('onclick').includes(sectionId)) {
            sectionName = links[i].textContent.trim();
            break;
        }
    }
    var companyId = <?= (int)$company_id ?>;
    fetch('edit_company.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=get_section_access&section_id=' + sectionId + '&company_id=' + companyId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            showNotification('❌ ' + data.error, 'error');
            return;
        }
        showSectionAccessModal(sectionId, sectionName, data);
    })
    .catch(err => {
        showNotification('❌ Ошибка загрузки настроек: ' + err, 'error');
    });
}

function showSectionAccessModal(sectionId, sectionName, accessData) {
    var old = document.querySelector('.section-access-overlay');
    if (old) old.remove();
    var overlay = document.createElement('div');
    overlay.className = 'section-access-overlay';
    var box = document.createElement('div');
    box.className = 'section-access-box';
    box.innerHTML = `
        <h3>🔐 Управление доступом: ${escapeHtml(sectionName)}</h3>
        <div id="access-form">
            <div class="role-row">
                <span class="role-label">📝 Редактор</span>
                <select onchange="toggleExcept(this, 'editor')">
                    <option value="allow">Да</option>
                    <option value="deny">Нет</option>
                    <option value="except">Да, кроме...</option>
                </select>
                <div class="except-container" id="except-editor">
                    <select multiple size="3" id="except-editor-select">
                        ${allEditors.map(u => `<option value="${u.id}">${escapeHtml(u.login)} (${escapeHtml(u.full_name || '')})</option>`).join('')}
                    </select>
                    <div class="selected-users" id="except-editor-tags"></div>
                </div>
            </div>
            <div class="role-row">
                <span class="role-label">👤 Сотрудник</span>
                <select onchange="toggleExcept(this, 'employee')">
                    <option value="allow">Да</option>
                    <option value="deny">Нет</option>
                    <option value="except">Да, кроме...</option>
                </select>
                <div class="except-container" id="except-employee">
                    <select multiple size="3" id="except-employee-select">
                        ${allEmployees.map(u => `<option value="${u.id}">${escapeHtml(u.login)} (${escapeHtml(u.full_name || '')})</option>`).join('')}
                    </select>
                    <div class="selected-users" id="except-employee-tags"></div>
                </div>
            </div>
            <div class="role-row">
                <span class="role-label">👋 Гость</span>
                <select onchange="toggleExcept(this, 'guest')">
                    <option value="allow">Да</option>
                    <option value="deny">Нет</option>
                </select>
                <div class="except-container" id="except-guest" style="display:none;"></div>
            </div>
        </div>
        <div class="buttons">
            <button class="btn btn-outline" onclick="closeSectionAccess()">Отмена</button>
            <button class="btn btn-success" onclick="saveSectionAccess(${sectionId})">💾 Сохранить</button>
        </div>
    `;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    
    var roles = ['editor', 'employee', 'guest'];
    roles.forEach(function(role) {
        var select = box.querySelector(`select[onchange*="${role}"]`);
        if (select && accessData[role]) {
            select.value = accessData[role].access_type || 'allow';
            select.dispatchEvent(new Event('change'));
            if (accessData[role].access_type === 'except' && accessData[role].except_users) {
                var exceptIds = accessData[role].except_users.split(',').map(Number);
                var selectEl = box.querySelector(`#except-${role}-select`);
                if (selectEl) {
                    for (var i = 0; i < selectEl.options.length; i++) {
                        if (exceptIds.includes(parseInt(selectEl.options[i].value))) {
                            selectEl.options[i].selected = true;
                        }
                    }
                    updateExceptTags(role);
                }
            }
        }
    });
}

function toggleExcept(select, role) {
    var container = document.getElementById(`except-${role}`);
    if (!container) return;
    if (select.value === 'except') {
        container.classList.add('show');
        container.style.display = 'flex';
        if (role === 'guest') {
            select.value = 'allow';
            showNotification('Для гостей доступна только опция "Да" или "Нет"', 'info');
            container.classList.remove('show');
            container.style.display = 'none';
        }
    } else {
        container.classList.remove('show');
        container.style.display = 'none';
    }
}

function updateExceptTags(role) {
    var select = document.getElementById(`except-${role}-select`);
    var tagsContainer = document.getElementById(`except-${role}-tags`);
    if (!select || !tagsContainer) return;
    var selected = [];
    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].selected) {
            selected.push({
                id: parseInt(select.options[i].value),
                name: select.options[i].text
            });
        }
    }
    tagsContainer.innerHTML = selected.map(u => `
        <span class="user-tag">
            ${u.name}
            <span class="remove" onclick="removeExceptUser('${role}', ${u.id})">✕</span>
        </span>
    `).join('');
}

function removeExceptUser(role, userId) {
    var select = document.getElementById(`except-${role}-select`);
    if (!select) return;
    for (var i = 0; i < select.options.length; i++) {
        if (parseInt(select.options[i].value) === userId) {
            select.options[i].selected = false;
            break;
        }
    }
    updateExceptTags(role);
}

function closeSectionAccess() {
    var overlay = document.querySelector('.section-access-overlay');
    if (overlay) overlay.remove();
}

function saveSectionAccess(sectionId) {
    var roles = ['editor', 'employee', 'guest'];
    var formData = new FormData();
    formData.append('action', 'save_section_access');
    formData.append('section_id', sectionId);
    formData.append('company_id', <?= (int)$company_id ?>);
    formData.append('csrf_token', csrfToken);
    roles.forEach(function(role) {
        var select = document.querySelector(`.section-access-box select[onchange*="${role}"]`);
        if (!select) return;
        var accessType = select.value;
        var exceptUsers = '';
        if (accessType === 'except') {
            var selectEl = document.getElementById(`except-${role}-select`);
            if (selectEl) {
                var selected = [];
                for (var i = 0; i < selectEl.options.length; i++) {
                    if (selectEl.options[i].selected) {
                        selected.push(selectEl.options[i].value);
                    }
                }
                exceptUsers = selected.join(',');
            }
        }
        formData.append(`role_${role}`, accessType);
        formData.append(`except_${role}`, exceptUsers);
    });
    fetch('edit_company.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data === 'ok') {
            showNotification('✅ Настройки доступа сохранены');
            closeSectionAccess();
        } else {
            showNotification('❌ Ошибка: ' + data, 'error');
        }
    })
    .catch(err => {
        showNotification('❌ Ошибка сети: ' + err, 'error');
    });
}

document.addEventListener('change', function(e) {
    if (e.target && e.target.id && e.target.id.endsWith('-select')) {
        var role = e.target.id.replace('-select', '').replace('except-', '');
        updateExceptTags(role);
    }
});

// =============================================
// 6. УДАЛЕНИЕ (С ЗАЩИТОЙ ОТ ОБХОДА ТАЙМЕРА)
// =============================================
var timerInterval = null;
var timerSeconds = 15;

function showDeleteConfirm() {
    // Сначала запрашиваем timestamp на сервере (сохраняем в сессию)
    fetch('edit_company.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=request_delete_timestamp&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.text())
    .then(data => {
        if (data !== 'ok') {
            showNotification('❌ Ошибка: ' + data, 'error');
            return;
        }
        
        var box = document.getElementById('delete-confirm');
        box.classList.remove('hidden');
        timerSeconds = 15;
        document.getElementById('timer').textContent = timerSeconds;
        var confirmBtn = document.getElementById('confirm-delete-btn');
        confirmBtn.disabled = true;
        confirmBtn.style.opacity = '0.5';
        confirmBtn.style.cursor = 'not-allowed';
        
        if (timerInterval) clearInterval(timerInterval);
        timerInterval = setInterval(function() {
            timerSeconds--;
            document.getElementById('timer').textContent = timerSeconds;
            if (timerSeconds <= 0) {
                clearInterval(timerInterval);
                confirmBtn.disabled = false;
                confirmBtn.style.opacity = '1';
                confirmBtn.style.cursor = 'pointer';
                document.getElementById('timer').textContent = '✅ Готово!';
            }
        }, 1000);
    });
}

function cancelDelete() {
    if (timerInterval) clearInterval(timerInterval);
    document.getElementById('delete-confirm').classList.add('hidden');
    var confirmBtn = document.getElementById('confirm-delete-btn');
    confirmBtn.disabled = true;
    confirmBtn.style.opacity = '0.5';
    confirmBtn.style.cursor = 'not-allowed';
}

document.getElementById('confirm-delete-btn').addEventListener('click', function() {
    if (this.disabled) return;
    customConfirm('Переместить компанию в корзину?', function(confirmed) {
        if (!confirmed) return;
        // ВАЖНО: timestamp НЕ отправляем — сервер проверяет его в сессии
        fetch('edit_company.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'action=delete_company&company_id=<?= (int)$company_id ?>&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(res => res.text())
        .then(data => {
            if (data === 'ok') {
                showNotification('✅ Компания перемещена в корзину');
                setTimeout(function() {
                    window.parent.closeModal();
                    window.parent.location.reload();
                }, 1200);
            } else {
                showNotification('❌ Ошибка: ' + data, 'error');
            }
        })
        .catch(err => {
            showNotification('❌ Ошибка сети: ' + err, 'error');
        });
    });
});

// =============================================
// HTML-ESCAPE (защита от XSS)
// =============================================
function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>