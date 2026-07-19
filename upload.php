<?php
// ==================================================
// ФАЙЛ: upload.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Загрузка файлов с CSRF-защитой
// ИСПРАВЛЕНИЯ:
//   1. Проблема 10: Добавлена CSRF-проверка для всех запросов
//   2. Проблема 29: mkdir с 0777 → 0755
//   3. Улучшена защита от path traversal в имени папки
// ==================================================
require_once 'config.php';

// Всегда возвращаем JSON
header('Content-Type: application/json');

// Функция для отправки JSON-ошибки
function sendJsonError($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ==========================================================
// ✅ ИСПРАВЛЕНО (проблема 10): CSRF-ПРОВЕРКА
// ==========================================================
// CSRF-токен может быть в POST-данных или в HTTP-заголовке
$csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrf_token)) {
    http_response_code(403);
    sendJsonError('Ошибка безопасности. Обновите страницу.');
}

// Проверяем, что пользователь авторизован
if (!isset($_SESSION['user_id'])) {
    sendJsonError('Не авторизован');
}

// Только админ или редактор
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'editor') {
    sendJsonError('Доступ запрещён');
}

$user_id = (int)$_SESSION['user_id'];

// Получаем параметры
$company_id = (int)($_POST['company_id'] ?? 0);
$folder = trim($_POST['folder'] ?? '');
$custom_name = trim($_POST['custom_name'] ?? '');

if (!$company_id) {
    sendJsonError('company_required');
}

// Проверка прав доступа к компании
$role = $_SESSION['role'];
if ($role != 'admin') {
    $stmt = $mysqli->prepare("SELECT id FROM access WHERE user_id = ? AND company_id = ? AND action = 'write'");
    $stmt->bind_param("ii", $user_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        $stmt->close();
        sendJsonError('access_denied');
    }
    $stmt->close();
}

// Проверяем, есть ли файл
if (!isset($_FILES['file']) || $_FILES['file']['error'] != 0) {
    sendJsonError('no_file');
}

$file = $_FILES['file'];
$original_name = $file['name'];
$tmp_path = $file['tmp_name'];
$size = $file['size'];

// Ограничение по размеру (50 МБ)
if ($size > 50 * 1024 * 1024) {
    sendJsonError('Файл слишком большой (максимум 50 МБ)');
}

// Проверяем реальный MIME-тип
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $tmp_path);
finfo_close($finfo);

// Разрешённые MIME-типы
$allowed_mime = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'text/plain' => 'txt',
    'application/zip' => 'zip',
    'application/x-rar-compressed' => 'rar',
    'application/x-7z-compressed' => '7z'
];

if (!isset($allowed_mime[$mime_type])) {
    sendJsonError('Недопустимый тип файла (MIME: ' . htmlspecialchars($mime_type) . ')');
}

$ext = $allowed_mime[$mime_type];

// ==========================================================
// ФОРМИРУЕМ ПУТЬ (с защитой от path traversal)
// ==========================================================
$base_dir = __DIR__ . '/uploads/files/';
$company_dir = 'company_' . $company_id . '/';
$full_company_dir = $base_dir . $company_dir;

if (!is_dir($full_company_dir)) {
    // ✅ ИСПРАВЛЕНО (проблема 29): права 0755 вместо 0777
    if (!mkdir($full_company_dir, 0755, true)) {
        sendJsonError('Не удалось создать папку компании');
    }
}

$target_dir = $full_company_dir;

if (!empty($folder)) {
    // ✅ ИСПРАВЛЕНО: усиленная защита от path traversal
    // Удаляем опасные символы и последовательности
    $folder = str_replace(['..', '/', '\\', "\0"], '', $folder);
    // Разрешаем только буквы, цифры, пробелы, дефис, подчёркивание и точки
    $folder = preg_replace('/[^\p{L}\p{N}_\-\s\.]/u', '_', $folder);
    $folder = trim($folder);
    
    if (empty($folder)) {
        sendJsonError('Недопустимое имя папки');
    }
    
    $target_dir .= $folder . '/';
    if (!is_dir($target_dir)) {
        if (!mkdir($target_dir, 0755, true)) {
            sendJsonError('Не удалось создать папку');
        }
    }
    
    // Дополнительная проверка: путь должен быть внутри base_dir
    $real_target = realpath($target_dir);
    $real_base = realpath($base_dir);
    if ($real_target === false || $real_base === false || strpos($real_target, $real_base) !== 0) {
        sendJsonError('Небезопасный путь к папке');
    }
}

// Генерация имени файла с версионированием
$base_name = pathinfo($original_name, PATHINFO_FILENAME);
// Ограничиваем длину имени файла
if (mb_strlen($base_name) > 100) {
    $base_name = mb_substr($base_name, 0, 100);
}

$new_filename = $base_name . '.' . $ext;
$counter = 1;
while (file_exists($target_dir . $new_filename)) {
    $new_filename = $base_name . '_' . date('Y-m-d_H-i-s') . '_' . $counter . '.' . $ext;
    $counter++;
    if ($counter > 100) {
        sendJsonError('Слишком много файлов с таким именем');
    }
}

$filepath = $target_dir . $new_filename;

if (!move_uploaded_file($tmp_path, $filepath)) {
    sendJsonError('upload_error');
}

// Сохраняем информацию в БД
$relative_path = '/uploads/files/' . $company_dir . (empty($folder) ? '' : $folder . '/') . $new_filename;
$folder_db = empty($folder) ? '' : $folder;

$stmt = $mysqli->prepare("INSERT INTO files (company_id, folder, filename, original_name, filepath, size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("issssii", $company_id, $folder_db, $new_filename, $original_name, $relative_path, $size, $user_id);

if (!$stmt->execute()) {
    $stmt->close();
    @unlink($filepath); // Удаляем файл, если не удалось сохранить в БД
    sendJsonError('db_error');
}

$file_id = $stmt->insert_id;
$stmt->close();

// Возвращаем JSON
$display_name = !empty($custom_name) ? $custom_name : $original_name;
$download_url = 'download.php?file_id=' . $file_id;

writeLog($user_id, 'upload_file', "Загружен файл $original_name в компанию ID $company_id");

echo json_encode([
    'success' => true,
    'file_id' => $file_id,
    'original_name' => $original_name,
    'display_name' => $display_name,
    'download_url' => $download_url,
    'filepath' => $relative_path
]);
exit;
?>