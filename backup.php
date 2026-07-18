<?php
// ==================================================
// ФАЙЛ: backup.php
// НАЗНАЧЕНИЕ: Создание резервной копии (бэкапа)
// ==================================================

require_once 'config.php';

// Только админ
if (($_SESSION['role'] ?? '') != 'admin') {
    die('Доступ запрещён');
}

// Создаём временную папку
$backup_dir = __DIR__ . '/backup_temp/';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0777, true);
}

// 1. ДАМП БАЗЫ ДАННЫХ
$dump_file = $backup_dir . 'database.sql';
$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

// Получаем все таблицы
$tables = [];
$result = $mysqli->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

$dump = "-- Дамп базы данных " . $dbname . "\n-- Дата: " . date('Y-m-d H:i:s') . "\n\n";
foreach ($tables as $table) {
    $dump .= "DROP TABLE IF EXISTS `$table`;\n";
    $create = $mysqli->query("SHOW CREATE TABLE `$table`")->fetch_row();
    $dump .= $create[1] . ";\n\n";
    
    $rows = $mysqli->query("SELECT * FROM `$table`");
    while ($row = $rows->fetch_assoc()) {
        $values = array_map(function($v) use ($mysqli) {
            return $v === null ? 'NULL' : "'" . $mysqli->real_escape_string($v) . "'";
        }, array_values($row));
        $dump .= "INSERT INTO `$table` VALUES (" . implode(',', $values) . ");\n";
    }
    $dump .= "\n";
}

file_put_contents($dump_file, $dump);

// 2. АРХИВИРУЕМ ВСЕ ФАЙЛЫ
$files_dir = __DIR__ . '/uploads/';
$archive_name = 'backup_' . date('Y-m-d_H-i-s') . '.zip';
$archive_path = __DIR__ . '/' . $archive_name;

$zip = new ZipArchive();
if ($zip->open($archive_path, ZipArchive::CREATE) !== true) {
    die('Не удалось создать архив');
}

// Добавляем дамп базы
$zip->addFile($dump_file, 'database.sql');

// Добавляем папку uploads
function addFolderToZip($zip, $folder, $zip_folder = '') {
    if (!is_dir($folder)) return;
    $files = scandir($folder);
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        $path = $folder . '/' . $file;
        if (is_dir($path)) {
            addFolderToZip($zip, $path, $zip_folder . $file . '/');
        } else {
            $zip->addFile($path, $zip_folder . $file);
        }
    }
}

if (is_dir($files_dir)) {
    addFolderToZip($zip, $files_dir, 'uploads/');
}

$zip->close();

// Удаляем временный дамп
unlink($dump_file);
rmdir($backup_dir);

// 3. СОХРАНЯЕМ ИНФОРМАЦИЮ О БЭКАПЕ В БАЗУ
$filesize = filesize($archive_path);
$stmt = $mysqli->prepare("INSERT INTO backups (filename, filesize) VALUES (?, ?)");
$stmt->bind_param("si", $archive_name, $filesize);
$stmt->execute();
$stmt->close();

// 4. ОТДАЁМ АРХИВ НА СКАЧИВАНИЕ
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $archive_name . '"');
header('Content-Length: ' . filesize($archive_path));
readfile($archive_path);

// Удаляем архив после скачивания (по желанию можно оставить)
// unlink($archive_path);
exit;
?>