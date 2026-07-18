<?php
// ==================================================
// ФАЙЛ: get_section.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

$section_id = (int)($_GET['section_id'] ?? 0);
$company_id = (int)($_GET['company_id'] ?? 0);

if (!$section_id || !$company_id) {
    echo json_encode(['error' => 'Не указан раздел или компания']);
    exit;
}

// Проверка доступа
$user_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? 'guest';
$is_admin = ($role == 'admin');
$is_editor = ($role == 'editor');

// Проверка, что раздел принадлежит компании — через prepared statement
$stmt = $mysqli->prepare("SELECT * FROM sections WHERE id = ? AND company_id = ?");
$stmt->bind_param("ii", $section_id, $company_id);
$stmt->execute();
$section = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$section) {
    echo json_encode(['error' => 'Раздел не найден']);
    exit;
}

// Функция проверки доступа к разделу — через prepared statement
function hasAccess($section, $user_id, $role, $mysqli, $is_admin, $is_editor) {
    if ($is_admin) return true;
    if ($is_editor) return true;
    if (!$section['is_published']) return false;
    
    $stmt = $mysqli->prepare("SELECT access_type FROM section_access WHERE section_id = ? AND role = ?");
    $stmt->bind_param("is", $section['id'], $role);
    $stmt->execute();
    $access = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($access && $access['access_type'] == 'deny') return false;
    if ($access && $access['access_type'] == 'allow') return true;
    return $section['is_public'] == 1;
}

if (!hasAccess($section, $user_id, $role, $mysqli, $is_admin, $is_editor)) {
    echo json_encode(['error' => 'У вас нет доступа к этому разделу']);
    exit;
}

// Получаем права доступа для этого раздела — через prepared statement
$current_roles = ['editor', 'employee', 'guest'];
$access_data = [];
foreach ($current_roles as $r) {
    $stmt = $mysqli->prepare("SELECT access_type FROM section_access WHERE section_id = ? AND role = ?");
    $stmt->bind_param("is", $section_id, $r);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $access_data[$r] = $row['access_type'] ?? 'deny';
}

// Возвращаем данные
echo json_encode([
    'title' => $section['title'],
    'content' => $section['content'] ?? '<p>Содержимое отсутствует.</p>',
    'is_published' => (int)$section['is_published'],
    'views_count' => (int)($section['views_count'] ?? 0),
    'access' => $access_data
]);
?>