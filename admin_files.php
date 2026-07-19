<?php
// ==================================================
// ФАЙЛ: admin_files.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Управление файлами (админ-панель)
// ИСПРАВЛЕНИЯ:
//   1. Проблема 5: Убран дублирующий include header.php
//      (был вызов до <!DOCTYPE html> и внутри body)
// ==================================================
require_once 'config.php';

// Только админ или редактор
if (($_SESSION['role'] ?? '') != 'admin' && ($_SESSION['role'] ?? '') != 'editor') {
    die('Доступ запрещён');
}

$user_id = (int)$_SESSION['user_id'];

// ==========================================================
// ОБРАБОТКА AJAX-ЗАПРОСА НА УДАЛЕНИЕ
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'delete_file_admin') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo json_encode(['error' => 'Ошибка безопасности']);
        exit;
    }
    
    $file_id = (int)($_POST['file_id'] ?? 0);
    if (!$file_id) {
        echo json_encode(['error' => 'Не указан файл']);
        exit;
    }
    
    // Получаем файл через prepared statement
    $stmt = $mysqli->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$file) {
        echo json_encode(['error' => 'Файл не найден']);
        exit;
    }
    
    // Защита от Path Traversal
    $filepath = __DIR__ . $file['filepath'];
    if (!isPathSafe($filepath, __DIR__)) {
        echo json_encode(['error' => 'Небезопасный путь']);
        exit;
    }
    
    // Удаляем физический файл
    if (file_exists($filepath)) {
        @unlink($filepath);
    }
    
    // Удаляем запись из БД через prepared statement
    $stmt = $mysqli->prepare("DELETE FROM files WHERE id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $stmt->close();
    
    writeLog($user_id, 'delete_file_admin', "Удалён файл ID $file_id ({$file['original_name']})");
    
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================================
// ПОЛУЧАЕМ СПИСОК ВСЕХ ФАЙЛОВ через prepared statement
// ==========================================================
$stmt = $mysqli->prepare("
    SELECT f.*, c.name as company_name
    FROM files f
    JOIN companies c ON c.id = f.company_id
    ORDER BY f.company_id, f.folder, f.filename
");
$stmt->execute();
$files = $stmt->get_result();
$stmt->close();

// Для фильтра по компаниям
$stmt = $mysqli->prepare("SELECT id, name FROM companies ORDER BY name");
$stmt->execute();
$companies = $stmt->get_result();
$stmt->close();

// ✅ ИСПРАВЛЕНО (проблема 5): устанавливаем флаг поиска
// но НЕ подключаем header.php здесь — это будет сделано внутри <body>
$show_search = true;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление файлами</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .files-container { max-width: 1200px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .files-table { width: 100%; border-collapse: collapse; }
        .files-table th, .files-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; }
        .files-table th { background: #f8f9fa; }
        .files-table .actions { display: flex; gap: 8px; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .filter-bar { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; align-items: center; }
    </style>
</head>
<body>
<div class="container">
    <?php include 'header.php'; ?>
    
    <div class="files-container">
        <h1>📁 Все файлы</h1>
        
        <div class="filter-bar">
            <label>Фильтр по компании:</label>
            <select id="company-filter" onchange="applyFilter()">
                <option value="">Все компании</option>
                <?php while ($c = $companies->fetch_assoc()): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endwhile; ?>
            </select>
            <button class="btn btn-sm btn-outline" onclick="document.getElementById('company-filter').value=''; applyFilter();">Сбросить</button>
        </div>
        
        <table class="files-table" id="files-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Компания</th>
                    <th>Папка</th>
                    <th>Имя файла</th>
                    <th>Оригинальное имя</th>
                    <th>Размер</th>
                    <th>Дата загрузки</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($file = $files->fetch_assoc()): ?>
                    <tr data-company="<?= (int)$file['company_id'] ?>">
                        <td><?= (int)$file['id'] ?></td>
                        <td><?= htmlspecialchars($file['company_name']) ?></td>
                        <td><?= htmlspecialchars($file['folder'] ?: 'Корень') ?></td>
                        <td><?= htmlspecialchars($file['filename']) ?></td>
                        <td><?= htmlspecialchars($file['original_name']) ?></td>
                        <td><?= round($file['size']/1024, 1) ?> КБ</td>
                        <td><?= date('d.m.Y H:i', strtotime($file['uploaded_at'])) ?></td>
                        <td>
                            <div class="actions">
                                <a href="download.php?file_id=<?= (int)$file['id'] ?>" target="_blank" class="btn btn-sm btn-primary">📥 Скачать</a>
                                <button class="btn btn-sm btn-danger" onclick="deleteFile(<?= (int)$file['id'] ?>)">🗑️</button>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
        <?php if ($files->num_rows == 0): ?>
            <p style="color:#999; text-align:center; padding:20px 0;">Нет загруженных файлов</p>
        <?php endif; ?>
    </div>
</div>

<script>
// CSRF-токен
var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

function applyFilter() {
    const filter = document.getElementById('company-filter').value;
    const rows = document.querySelectorAll('#files-table tbody tr');
    rows.forEach(row => {
        if (!filter || row.dataset.company == filter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function deleteFile(fileId) {
    if (!confirm('Удалить этот файл?')) return;
    
    const formData = new FormData();
    formData.append('ajax_action', 'delete_file_admin');
    formData.append('file_id', fileId);
    formData.append('csrf_token', csrfToken);
    
    fetch('admin_files.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Файл удалён');
            location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'неизвестная'));
        }
    })
    .catch(err => alert('Ошибка: ' + err));
}
</script>
</body>
</html>