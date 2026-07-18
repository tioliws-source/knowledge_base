<?php
// ==================================================
// ФАЙЛ: admin_backups.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Управление бэкапами файлов
// ==================================================
require_once 'config.php';

$show_search = true;
include 'header.php';

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

// Обработка действий
$action = $_GET['action'] ?? '';

if ($action == 'create') {
    // CSRF-проверка
    $csrf_token = $_GET['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        die('Ошибка безопасности. Обновите страницу.');
    }
    
    // ВАЖНО: передаём флаг, что вызов из админки
    $called_from_admin = true;
    include 'backup_files.php';
    header('Location: admin_backups.php?msg=created');
    exit;
}

if ($action == 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filepath = $backup_dir . $file;
    if (file_exists($filepath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

if ($action == 'delete' && isset($_GET['file'])) {
    // CSRF-проверка
    $csrf_token = $_GET['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        die('Ошибка безопасности. Обновите страницу.');
    }
    
    $file = basename($_GET['file']);
    $filepath = $backup_dir . $file;
    if (file_exists($filepath)) {
        unlink($filepath);
        writeLog($_SESSION['user_id'], 'delete_backup', "Удалён бэкап: $file");
    }
    header('Location: admin_backups.php?msg=deleted');
    exit;
}

$message = $_GET['msg'] ?? '';
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
.backups-list li { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #eee; }
.backups-list .actions { display: flex; gap: 8px; }
.backups-list .file-info { font-size: 14px; }
.backups-list .file-info .date { color: #999; font-size: 12px; }
.backups-list .file-info .size { color: #666; }
.btn-sm { padding: 4px 12px; font-size: 12px; }
.msg { padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; }
.msg.success { background: #d4edda; color: #155724; }
.msg.error { background: #f8d7da; color: #721c24; }
</style>
</head>
<body>
<div class="container">
<div class="backups-container">
<h1>💾 Бэкапы файлов</h1>
<?php if ($message == 'created'): ?>
<div class="msg success">✅ Бэкап создан успешно!</div>
<?php elseif ($message == 'deleted'): ?>
<div class="msg success">🗑️ Бэкап удалён</div>
<?php endif; ?>
<div style="margin-bottom:20px;">
<a href="?action=create&csrf_token=<?= urlencode(generateCsrfToken()) ?>" class="btn btn-success" onclick="return confirm('Создать бэкап файлов?')">📦 Создать бэкап сейчас</a>
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
<a href="?action=download&file=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-primary">⬇️ Скачать</a>
<a href="?action=delete&file=<?= urlencode($b['name']) ?>&csrf_token=<?= urlencode(generateCsrfToken()) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Удалить этот бэкап?')">🗑️</a>
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