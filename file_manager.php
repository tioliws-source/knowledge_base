<?php
// ==================================================
// ФАЙЛ: file_manager.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

// Только админ или редактор
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'editor')) {
    die('Доступ запрещён');
}

$company_id = (int)($_GET['company_id'] ?? 0);
if (!$company_id) {
    die('company_required');
}

$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];

// Проверка прав доступа через prepared statement
if ($role != 'admin') {
    $stmt = $mysqli->prepare("SELECT id FROM access WHERE user_id = ? AND company_id = ? AND action = 'write'");
    $stmt->bind_param("ii", $user_id, $company_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0) {
        $stmt->close();
        die('access_denied');
    }
    $stmt->close();
}

// ==========================================================
// ОБРАБОТКА AJAX-ЗАПРОСОВ
// ==========================================================
$ajax_action = $_POST['ajax_action'] ?? '';

// Удаление файла
if ($ajax_action == 'delete_file') {
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
    $stmt = $mysqli->prepare("SELECT * FROM files WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $file_id, $company_id);
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
    
    writeLog($user_id, 'delete_file', "Удалён файл ID $file_id из компании ID $company_id");
    
    echo json_encode(['success' => true]);
    exit;
}

// Создание папки
if ($ajax_action == 'create_folder') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo json_encode(['error' => 'Ошибка безопасности']);
        exit;
    }
    
    $folder_name = trim($_POST['folder_name'] ?? '');
    if (empty($folder_name)) {
        echo json_encode(['error' => 'Введите название папки']);
        exit;
    }
    
    // Защита от path traversal
    $folder_name = basename($folder_name);
    $folder_name = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '_', $folder_name);
    
    $base_dir = realpath(__DIR__ . '/uploads/files/company_' . $company_id . '/');
    if ($base_dir === false) {
        echo json_encode(['error' => 'Папка компании не найдена']);
        exit;
    }
    
    $target_dir = $base_dir . '/' . $folder_name . '/';
    
    // Проверяем, что путь внутри base_dir
    if (strpos(realpath(dirname($target_dir)) ?: '', $base_dir) !== 0 && realpath(dirname($target_dir)) !== $base_dir) {
        echo json_encode(['error' => 'Небезопасное имя папки']);
        exit;
    }
    
    if (is_dir($target_dir)) {
        echo json_encode(['error' => 'Папка уже существует']);
        exit;
    }
    
    if (!mkdir($target_dir, 0777, true)) {
        echo json_encode(['error' => 'Не удалось создать папку']);
        exit;
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================================
// ПОЛУЧАЕМ СПИСОК ФАЙЛОВ через prepared statement
// ==========================================================
$stmt = $mysqli->prepare("
    SELECT id, folder, filename, original_name, filepath, size, uploaded_at
    FROM files
    WHERE company_id = ?
    ORDER BY folder, filename
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$files = $stmt->get_result();
$stmt->close();

$folders = [];
while ($row = $files->fetch_assoc()) {
    $folder = $row['folder'] ?: '';
    if (!isset($folders[$folder])) {
        $folders[$folder] = [];
    }
    $folders[$folder][] = $row;
}

// Сканируем реальные папки
$base_dir = __DIR__ . '/uploads/files/company_' . $company_id . '/';
$real_folders = [];
if (is_dir($base_dir)) {
    $items = scandir($base_dir);
    foreach ($items as $item) {
        if ($item != '.' && $item != '..' && is_dir($base_dir . $item)) {
            $real_folders[] = $item;
        }
    }
}

$all_folders = array_unique(array_merge(array_keys($folders), $real_folders));
sort($all_folders);

// Определяем, может ли пользователь управлять файлами
$can_manage = ($role == 'admin' || $role == 'editor');
?>
<style>
.fm-container { display: flex; flex-direction: column; gap: 10px; max-height: 400px; overflow-y: auto; }
.fm-toolbar { display: flex; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
.fm-toolbar .btn { font-size: 12px; padding: 4px 12px; }
.fm-folder { cursor: pointer; color: #007bff; font-weight: 600; }
.fm-folder:hover { text-decoration: underline; }
.fm-file { cursor: default; padding: 4px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
.fm-file:hover { background: #f0f7ff; }
.fm-file .file-info { cursor: pointer; flex: 1; }
.fm-file .file-actions { display: flex; gap: 8px; }
.fm-file .file-actions .btn-sm { font-size: 11px; padding: 2px 8px; }
.fm-file.selected { background: #cce5ff; font-weight: 600; }
.fm-file .size { color: #999; font-size: 12px; }
.fm-file .date { color: #999; font-size: 12px; margin-left: 10px; }
</style>
<div class="fm-container">
<?php if ($can_manage): ?>
<div class="fm-toolbar">
    <button class="btn btn-sm btn-success" onclick="createFolder()">📁 Создать папку</button>
    <span style="font-size:12px;color:#999;margin-left:10px;">Выберите файл для вставки</span>
</div>
<?php endif; ?>

<?php if (!empty($all_folders)): ?>
<div style="font-weight:600; margin-bottom:5px;">Папки:</div>
<?php foreach ($all_folders as $folder): ?>
    <div class="fm-folder" data-folder="<?= htmlspecialchars($folder) ?>" onclick="toggleFolder(this)">📁 <?= htmlspecialchars($folder ?: 'Корень') ?></div>
    <div id="folder-<?= md5($folder) ?>" style="padding-left:20px; display: <?= $folder === '' ? 'block' : 'none' ?>;">
        <?php if (isset($folders[$folder])): ?>
            <?php foreach ($folders[$folder] as $file): ?>
                <div class="fm-file" data-id="<?= (int)$file['id'] ?>" data-name="<?= htmlspecialchars($file['original_name']) ?>">
                    <span class="file-info" onclick="selectFile(<?= (int)$file['id'] ?>, '<?= htmlspecialchars(addslashes($file['original_name'])) ?>')">
                        📄 <?= htmlspecialchars($file['original_name']) ?>
                        <span class="size">(<?= round($file['size']/1024, 1) ?> КБ)</span>
                        <span class="date"><?= date('d.m.Y H:i', strtotime($file['uploaded_at'])) ?></span>
                    </span>
                    <?php if ($can_manage): ?>
                        <span class="file-actions">
                            <button class="btn btn-sm btn-danger" onclick="deleteFile(<?= (int)$file['id'] ?>)">🗑️</button>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="color:#999; padding:4px 0;">Папка пуста</div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php else: ?>
    <div style="color:#999;">Нет файлов в этой компании</div>
<?php endif; ?>
</div>

<script>
// CSRF-токен
var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

function toggleFolder(el) {
    var folderName = el.dataset.folder;
    var target = document.getElementById('folder-' + md5(folderName));
    if (target) {
        target.style.display = target.style.display === 'none' ? 'block' : 'none';
    }
}

// Простая реализация md5 для ID папок
function md5(str) {
    // Используем встроенную функцию хэширования
    var hash = 0;
    for (var i = 0; i < str.length; i++) {
        var char = str.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash;
    }
    return Math.abs(hash).toString(16);
}

function selectFile(id, name) {
    document.querySelectorAll('.fm-file').forEach(el => el.classList.remove('selected'));
    const el = document.querySelector(`.fm-file[data-id="${id}"]`);
    if (el) el.classList.add('selected');
    window.selectedFileId = id;
    window.selectedFileName = name;
    if (window.parent.document.getElementById('file-custom-name')) {
        window.parent.document.getElementById('file-custom-name').value = name;
    }
    if (window.parent.onFileSelected) {
        window.parent.onFileSelected(id, name);
    }
}

function deleteFile(fileId) {
    if (!confirm('Удалить этот файл?')) return;
    const formData = new FormData();
    formData.append('ajax_action', 'delete_file');
    formData.append('file_id', fileId);
    formData.append('company_id', <?= (int)$company_id ?>);
    formData.append('csrf_token', csrfToken);
    
    fetch('file_manager.php', {
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

function createFolder() {
    const name = prompt('Введите название новой папки:');
    if (!name || name.trim() === '') return;
    
    const formData = new FormData();
    formData.append('ajax_action', 'create_folder');
    formData.append('folder_name', name.trim());
    formData.append('company_id', <?= (int)$company_id ?>);
    formData.append('csrf_token', csrfToken);
    
    fetch('file_manager.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Папка создана');
            location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'неизвестная'));
        }
    })
    .catch(err => alert('Ошибка: ' + err));
}
</script>