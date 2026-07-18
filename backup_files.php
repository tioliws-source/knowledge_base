<?php
// ==================================================
// ФАЙЛ: backup_files.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Создание бэкапа папки uploads/files/
// ЗАПУСК:
//   - Из админ-панели (admin_backups.php) — через $called_from_admin = true
//   - Через cron (командная строка)
//   - Прямой доступ через браузер — ЗАПРЕЩЁН
// ==================================================

// ==========================================================
// ПРОВЕРКА ДОСТУПА (только из админки или из CLI)
// ==========================================================
$is_cli = (php_sapi_name() === 'cli');
$is_from_admin = isset($called_from_admin) && $called_from_admin === true;
$is_admin_user = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Разрешаем запуск только если:
// 1. Это командная строка (cron), ИЛИ
// 2. Это вызов из админ-панели И пользователь — админ
if (!$is_cli && !($is_from_admin && $is_admin_user)) {
    http_response_code(403);
    die('Доступ запрещён');
}

// ==========================================================
// ПОДКЛЮЧАЕМ КОНФИГ (если ещё не подключён)
// ==========================================================
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    require_once __DIR__ . '/config.php';
}

// ==========================================================
// ПУТИ И НАСТРОЙКИ
// ==========================================================
$source_dir = __DIR__ . '/uploads/files/';
$backup_base = __DIR__ . '/backups/files/';
$date = date('Y-m-d_H-i-s'); // Добавляем время, чтобы бэкапы не перезаписывались
$backup_file = $backup_base . 'files_' . $date . '.zip';
$keep_days = 15;

// ==========================================================
// ПРОВЕРКА СУЩЕСТВОВАНИЯ ПАПКИ С ФАЙЛАМИ
// ==========================================================
if (!is_dir($source_dir)) {
    if ($is_from_admin) {
        // Если вызов из админки — просто сообщаем, что файлов нет
        $_SESSION['backup_result'] = 'empty';
        return;
    }
    die('Папка uploads/files/ не найдена');
}

// ==========================================================
// СОЗДАЁМ ПАПКУ ДЛЯ БЭКАПОВ (если её нет)
// ==========================================================
if (!is_dir($backup_base)) {
    if (!mkdir($backup_base, 0777, true)) {
        if ($is_from_admin) {
            $_SESSION['backup_result'] = 'error_mkdir';
            return;
        }
        die('Не удалось создать папку для бэкапов');
    }
}

// ==========================================================
// СОЗДАЁМ ZIP-АРХИВ
// ==========================================================
if (!class_exists('ZipArchive')) {
    if ($is_from_admin) {
        $_SESSION['backup_result'] = 'error_zip';
        return;
    }
    die('Модуль ZipArchive не установлен на сервере');
}

$zip = new ZipArchive();
$zip_result = $zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

if ($zip_result !== true) {
    if ($is_from_admin) {
        $_SESSION['backup_result'] = 'error_create';
        return;
    }
    die('Не удалось создать архив (код ошибки: ' . $zip_result . ')');
}

// Рекурсивно добавляем файлы из папки uploads/files/
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$files_added = 0;
foreach ($iterator as $file) {
    $relative_path = substr($file->getPathname(), strlen($source_dir));
    // Нормализуем слэши для ZIP
    $relative_path = str_replace('\\', '/', $relative_path);
    
    if ($file->isDir()) {
        $zip->addEmptyDir($relative_path);
    } else {
        // Пропускаем слишком большие файлы (> 100 МБ), чтобы не зависнуть
        if ($file->getSize() > 100 * 1024 * 1024) {
            continue;
        }
        if ($zip->addFile($file->getPathname(), $relative_path)) {
            $files_added++;
        }
    }
}

$zip->close();

// ==========================================================
// УДАЛЯЕМ СТАРЫЕ БЭКАПЫ (старше 15 дней)
// ==========================================================
$deleted_old = 0;
$files = glob($backup_base . 'files_*.zip');
if ($files) {
    foreach ($files as $f) {
        // Извлекаем дату из имени файла (формат: files_YYYY-MM-DD_HH-MM-SS.zip)
        $basename = basename($f);
        if (preg_match('/files_(\d{4}-\d{2}-\d{2})_\d{2}-\d{2}-\d{2}\.zip/', $basename, $matches)) {
            $file_date = strtotime($matches[1]);
            if ($file_date !== false && $file_date < time() - ($keep_days * 86400)) {
                if (unlink($f)) {
                    $deleted_old++;
                }
            }
        }
    }
}

// ==========================================================
// СОХРАНЯЕМ ИНФОРМАЦИЮ О БЭКАПЕ В БАЗУ (если есть таблица)
// ==========================================================
$check_table = $mysqli->query("SHOW TABLES LIKE 'backups'");
if ($check_table && $check_table->num_rows > 0) {
    $filesize = file_exists($backup_file) ? filesize($backup_file) : 0;
    $filename = basename($backup_file);
    $stmt = $mysqli->prepare("INSERT INTO backups (filename, filesize) VALUES (?, ?)");
    $stmt->bind_param("si", $filename, $filesize);
    $stmt->execute();
    $stmt->close();
}

// ==========================================================
// ЛОГИРУЕМ ДЕЙСТВИЕ (если вызов из админки)
// ==========================================================
if ($is_from_admin && isset($_SESSION['user_id'])) {
    $user_id_log = (int)$_SESSION['user_id'];
    writeLog(
        $user_id_log, 
        'create_backup', 
        "Создан бэкап файлов: " . basename($backup_file) . " (файлов: $files_added, удалено старых: $deleted_old)"
    );
}

// ==========================================================
// СООБЩАЕМ О РЕЗУЛЬТАТЕ (для админки)
// ==========================================================
if ($is_from_admin) {
    $_SESSION['backup_result'] = 'success';
    $_SESSION['backup_stats'] = [
        'files_added' => $files_added,
        'deleted_old' => $deleted_old,
        'filename' => basename($backup_file),
        'size' => file_exists($backup_file) ? filesize($backup_file) : 0
    ];
    return;
}

// ==========================================================
// ВЫВОД ДЛЯ CLI (cron)
// ==========================================================
if ($is_cli) {
    echo "[" . date('Y-m-d H:i:s') . "] Бэкап создан: $backup_file\n";
    echo "Добавлено файлов: $files_added\n";
    echo "Удалено старых бэкапов: $deleted_old\n";
}
?>