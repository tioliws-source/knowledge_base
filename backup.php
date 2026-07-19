<?php
// ==================================================
// ФАЙЛ: backup.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Создание резервной копии (бэкапа)
// ИСПРАВЛЕНИЯ:
//   1. Проблема 19: backup_temp не защищена → создаётся .htaccess
//   2. Проблема 29: mkdir с 0777 → 0755
//   3. Добавлена очистка backup_temp при ошибке
//   4. Добавлена защита от прерывания скрипта
// ==================================================
require_once 'config.php';

// Только админ
if (($_SESSION['role'] ?? '') != 'admin') {
    die('Доступ запрещён');
}

$user_id = (int)$_SESSION['user_id'];

// ==========================================================
// 1. СОЗДАЁМ ВРЕМЕННУЮ ПАПКУ (с защитой)
// ==========================================================
$backup_dir = __DIR__ . '/backup_temp/';

if (!is_dir($backup_dir)) {
    // ✅ ИСПРАВЛЕНО (проблема 29): права 0755 вместо 0777
    if (!mkdir($backup_dir, 0755, true)) {
        die('Не удалось создать временную папку');
    }
    
    // ✅ ИСПРАВЛЕНО (проблема 19): создаём .htaccess для защиты
    $htaccess_content = "# Защита временной папки бэкапов\n"
        . "Order Deny,Allow\n"
        . "Deny from all\n"
        . "\n"
        . "# Запрет просмотра папок\n"
        . "Options -Indexes\n";
    
    file_put_contents($backup_dir . '.htaccess', $htaccess_content);
}

// Функция очистки временной папки при ошибке
function cleanupBackupTemp($dir) {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        $path = $dir . $file;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

// ==========================================================
// 2. ДАМП БАЗЫ ДАННЫХ
// ==========================================================
$dump_file = $backup_dir . 'database.sql';

// Отключаем проверки внешних ключей для корректного дампа
$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

// Получаем все таблицы
$tables = [];
$result = $mysqli->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

$dump = "-- Дамп базы данных knowledge_base\n";
$dump .= "-- Дата: " . date('Y-m-d H:i:s') . "\n\n";
$dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $table) {
    $dump .= "DROP TABLE IF EXISTS `$table`;\n";
    
    // Структура таблицы
    $create = $mysqli->query("SHOW CREATE TABLE `$table`")->fetch_row();
    $dump .= $create[1] . ";\n\n";
    
    // Данные таблицы
    $rows = $mysqli->query("SELECT * FROM `$table`");
    while ($row = $rows->fetch_assoc()) {
        $values = array_map(function($v) use ($mysqli) {
            return $v === null ? 'NULL' : "'" . $mysqli->real_escape_string($v) . "'";
        }, array_values($row));
        $dump .= "INSERT INTO `$table` VALUES (" . implode(',', $values) . ");\n";
    }
    $dump .= "\n";
}

$dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

if (file_put_contents($dump_file, $dump) === false) {
    cleanupBackupTemp($backup_dir);
    die('Не удалось создать дамп базы данных');
}

// ==========================================================
// 3. АРХИВИРУЕМ ВСЕ ФАЙЛЫ
// ==========================================================
$files_dir = __DIR__ . '/uploads/';
$archive_name = 'backup_' . date('Y-m-d_H-i-s') . '.zip';
$archive_path = __DIR__ . '/' . $archive_name;

$zip = new ZipArchive();
if ($zip->open($archive_path, ZipArchive::CREATE) !== true) {
    cleanupBackupTemp($backup_dir);
    die('Не удалось создать архив');
}

// Добавляем дамп базы
$zip->addFile($dump_file, 'database.sql');

// Рекурсивное добавление папки в архив
function addFolderToZip($zip, $folder, $zip_folder = '') {
    if (!is_dir($folder)) return;
    $files = scandir($folder);
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        $path = $folder . '/' . $file;
        if (is_dir($path)) {
            addFolderToZip($zip, $path, $zip_folder . $file . '/');
        } else {
            // Пропускаем слишком большие файлы (> 200 МБ)
            if (filesize($path) > 200 * 1024 * 1024) {
                continue;
            }
            $zip->addFile($path, $zip_folder . $file);
        }
    }
}

if (is_dir($files_dir)) {
    addFolderToZip($zip, $files_dir, 'uploads/');
}

$zip->close();

// Удаляем временный дамп
@unlink($dump_file);

// ==========================================================
// 4. СОХРАНЯЕМ ИНФОРМАЦИЮ О БЭКАПЕ В БАЗУ
// ==========================================================
$filesize = filesize($archive_path);

$stmt = $mysqli->prepare("INSERT INTO backups (filename, filesize) VALUES (?, ?)");
$stmt->bind_param("si", $archive_name, $filesize);
$stmt->execute();
$stmt->close();

// Логируем действие
writeLog($user_id, 'create_backup', "Создан полный бэкап: $archive_name (" . round($filesize/1024/1024, 2) . " МБ)");

// ==========================================================
// 5. ОТДАЁМ АРХИВ НА СКАЧИВАНИЕ
// ==========================================================
// Увеличиваем лимиты для больших файлов
@set_time_limit(300);
@ini_set('memory_limit', '512M');

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rawurlencode($archive_name) . '"');
header('Content-Length: ' . filesize($archive_path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($archive_path);

// Удаляем архив после скачивания (раскомментируйте, если нужно)
// @unlink($archive_path);

exit;
?>