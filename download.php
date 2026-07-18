<?php
// ==================================================
// ФАЙЛ: download.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Скачивание файлов с проверкой прав и защитой от Path Traversal
// ==================================================
require_once 'config.php';

$file_id = (int)($_GET['file_id'] ?? 0);
if (!$file_id) {
    die('Файл не найден');
}

// Получаем информацию о файле через prepared statement
$stmt = $mysqli->prepare("SELECT * FROM files WHERE id = ?");
$stmt->bind_param("i", $file_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    die('Файл не найден');
}

// Проверяем, что пользователь авторизован
if (!isset($_SESSION['user_id'])) {
    die('Доступ запрещён. Авторизуйтесь.');
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$company_id = $file['company_id'];

// Проверка прав доступа к компании
$has_access = false;

if ($role == 'admin') {
    $has_access = true;
} else {
    // Проверяем доступ через prepared statement
    $stmt = $mysqli->prepare("SELECT id FROM access WHERE user_id = ? AND company_id = ? AND action IN ('write', 'read')");
    $stmt->bind_param("ii", $user_id, $company_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $has_access = true;
    }
    $stmt->close();
    
    // Если нет доступа — проверяем публичность компании
    if (!$has_access) {
        $stmt = $mysqli->prepare("SELECT is_public FROM companies WHERE id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $company = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($company && $company['is_public'] == 1) {
            $has_access = true;
        }
    }
}

if (!$has_access) {
    die('У вас нет доступа к этому файлу');
}

// ==========================================================
// ЗАЩИТА ОТ PATH TRAVERSAL
// ==========================================================
$filepath = __DIR__ . $file['filepath'];

// Проверяем, что путь находится внутри корня проекта
if (!isPathSafe($filepath, __DIR__)) {
    die('Небезопасный путь к файлу');
}

if (!file_exists($filepath)) {
    die('Файл не найден на сервере');
}

// Отдаём файл
$original_name = $file['original_name'];
$mime = mime_content_type($filepath);

// Безопасные заголовки
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
// Защита от XSS в имени файла через rawurlencode
header('Content-Disposition: attachment; filename="' . rawurlencode($original_name) . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($filepath);
exit;
?>