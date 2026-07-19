<?php
// ==================================================
// ФАЙЛ: admin_backups.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Управление бэкапами файлов
// ИСПРАВЛЕНИЯ:
//   1. Проблема 12: CSRF через GET → заменён на POST-формы
//   2. Проблема 21: backup_files.php может сломать header()
//      → используется output buffering
//   3. Добавлена защита от path traversal при скачивании
// ==================================================
require_once 'config.php';

// Только админ
if (($_SESSION['role'] ?? '') != 'admin') {
    die('Доступ запрещён. Только администратор.');
}

$backup_dir = __DIR__ . '/backups/files/';
$backup_files = [];

if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) == 'zip') {
            $backup_files[] = [
                'name' => $file,
                'size' => filesize($backup_dir . $file),
                'date' => filemtime($backup_dir . $file),
                'path' => $backup_dir . $file
            ];
        }
    }
    // Сортировка по дате (новые сверху)
    usort($backup_files, function($a, $b) {
        return $b['date'] - $a['date'];
    });
}

// ==========================================================
// ОБРАБОТКА ДЕЙСТВИЙ (через POST для безопасности)
// ==========================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// --- СОЗДАНИЕ БЭКАПА (POST) ---
if ($action == 'create' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        die('Ошибка безопасности. Обновите страницу.');
    }
    
    // ✅ ИСПРАВЛЕНО (проблема 21): используем output buffering,
    // чтобы backup_files.php не сломал header()
    ob_start();
    
    // ВАЖНО: передаём флаг, что вызов из админки
    $called_from_admin = true;
    include 'backup_files.php';
    
    // Очищаем буфер (если backup_files.php что-то вывел)
    ob_end_clean();
    
    // Безопасный редирект
    header('Location: admin_backups.php?msg=created');
    exit;
}

// --- СКАЧИВАНИЕ БЭКАПА (GET — безопасно, т.к. только чтение) ---
if ($action == 'download' && isset($_GET['file'])) {
    // Защита от path traversal — берём только basename
    $file = basename($_GET['file']);
    
    // Дополнительная проверка: имя должно соответствовать шаблону
    if (!preg_match('/^files_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $file) &&
        !preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $file)) {
        die('Недопустимое имя файла');
    }
    
    $filepath = $backup_dir . $file;
    
    // ✅ ИСПРАВЛЕНО: проверка, что путь находится внутри backup_dir
    if (!isPathSafe($filepath, $backup_dir)) {
        die('Небезопасный путь');
    }
    
    if (file_exists($filepath) && is_file($filepath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . rawurlencode($file) . '"');
        header('Content-Length: ' . filesize($filepath));
        header('X-Content-Type-Options: nosniff');
        readfile($filepath);
        exit;
    } else {
        die('Файл не найден');
    }
}

// --- УДАЛЕНИЕ БЭКАПА (POST) ---
if ($action == 'delete' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        die('Ошибка безопасности. Обновите страницу.');
    }
    
    $file = basename($_POST['file'] ?? '');
    
    // Дополнительная проверка имени файла
    if (!preg_match('/^files_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $file) &&
        !preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $file)) {
        die('Недопустимое имя файла');
    }
    
    $filepath = $backup_dir . $file;
    
    // Защита от path traversal
    if (!isPathSafe($filepath, $backup_dir)) {
        die('Небезопасный путь');
    }
    
    if (file_exists($filepath)) {
        unlink($filepath);
        writeLog($_SESSION['user_id'], 'delete_backup', "Удалён бэкап: $file");
    }
    
    header('Location: admin_backups.php?msg=deleted');
    exit;
}

$message = $_GET['msg'] ?? '';
$show_search = true;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление бэкапами</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .backups-container { max-width: 900px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .backups-container h1 { margin-top: 0; }
        .backups-list { list-style: none; padding: 0; }
        .backups-list li { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #eee; flex-wrap: wrap; gap: 10px; }
        .backups-list .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .backups-list .file-info { font-size: 14px; }
        .backups-list .file-info .date { color: #999; font-size: 12px; }
        .backups-list .file-info .size { color: #666; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .msg { padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; }
        .msg.success { background: #d4edda; color: #155724; }
        .msg.error { background: #f8d7da; color: #721c24; }
        .create-form { display: inline; }
    </style>
</head>
<body>
<div class="container">
    <?php include 'header.php'; ?>
    
    <div class="backups-container">
        <h1>💾 Бэкапы файлов</h1>
        
        <?php if ($message == 'created'): ?>
            <div class="msg success">✅ Бэкап создан успешно!</div>
        <?php elseif ($message == 'deleted'): ?>
            <div class="msg success">🗑️ Бэкап удалён</div>
        <?php endif; ?>
        
        <div style="margin-bottom:20px;">
            <!-- ✅ ИСПРАВЛЕНО (проблема 12): CSRF-токен передаётся через POST-форму, а не через GET -->
            <form method="POST" action="admin_backups.php" class="create-form" onsubmit="return confirm('Создать бэкап файлов? Это может занять некоторое время.');">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <button type="submit" class="btn btn-success">📦 Создать бэкап сейчас</button>
            </form>
        </div>
        
        <?php if (!empty($backup_files)): ?>
            <ul class="backups-list">
                <?php foreach ($backup_files as $b): ?>
                    <li>
                        <div class="file-info">
                            <strong><?= htmlspecialchars($b['name']) ?></strong>
                            <span class="size">(<?= round($b['size']/1024/1024, 2) ?> МБ)</span>
                            <span class="date">— <?= date('d.m.Y H:i', $b['date']) ?></span>
                        </div>
                        <div class="actions">
                            <!-- Скачивание — GET, безопасно (только чтение) -->
                            <a href="?action=download&file=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-primary">⬇️ Скачать</a>
                            
                            <!-- ✅ ИСПРАВЛЕНО (проблема 12): Удаление через POST-форму с CSRF -->
                            <form method="POST" action="admin_backups.php" style="display:inline;" onsubmit="return confirm('Удалить этот бэкап?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="file" value="<?= htmlspecialchars($b['name']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p style="color:#999; text-align:center; padding:20px 0;">Нет созданных бэкапов</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>