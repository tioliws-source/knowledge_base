<?php
// ==================================================
// ФАЙЛ: search.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Результаты поиска с защитой от SQL-инъекций
// ИСПРАВЛЕНИЯ:
//   1. Проблема 13: Добавлена проверка таблицы section_access
//      (ранее разделы с deny для роли гостя/сотрудника/редактора
//      могли попадать в результаты поиска)
//   2. Усиленная проверка доступа с учётом всех правил
// ==================================================
require_once 'config.php';

$query = trim($_GET['q'] ?? '');
if (empty($query)) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? 'guest';
$is_admin = ($role == 'admin');
$is_editor = ($role == 'editor');

// ==========================================================
// ФУНКЦИЯ ПРОВЕРКИ ДОСТУПА К РАЗДЕЛУ
// ✅ ИСПРАВЛЕНО (проблема 13): теперь проверяет section_access
// ==========================================================
function hasAccessToSection($section_id, $user_id, $role, $mysqli, $is_admin, $is_editor) {
    // Админ и редактор имеют полный доступ
    if ($is_admin || $is_editor) return true;
    
    // Получаем данные раздела
    $stmt = $mysqli->prepare("
        SELECT is_public, is_published, company_id 
        FROM sections 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $section = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$section) return false;
    
    // Неопубликованные разделы недоступны
    if (!$section['is_published']) return false;
    
    // ✅ ИСПРАВЛЕНО: проверяем таблицу section_access
    $stmt = $mysqli->prepare("
        SELECT access_type, except_users 
        FROM section_access 
        WHERE section_id = ? AND role = ?
    ");
    $stmt->bind_param("is", $section_id, $role);
    $stmt->execute();
    $access = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Если есть запись в section_access
    if ($access) {
        // deny — доступ запрещён
        if ($access['access_type'] == 'deny') return false;
        
        // except — доступ разрешён, кроме указанных пользователей
        if ($access['access_type'] == 'except') {
            if ($user_id > 0 && !empty($access['except_users'])) {
                $except_ids = array_map('intval', explode(',', $access['except_users']));
                if (in_array($user_id, $except_ids)) {
                    return false; // Пользователь в списке исключений
                }
            }
            return true; // Доступ разрешён
        }
        
        // allow — доступ разрешён
        if ($access['access_type'] == 'allow') return true;
    }
    
    // Если записи нет — используем is_public
    if ($section['is_public'] == 1) return true;
    
    // Проверка доступа через таблицу access (для авторизованных)
    if ($user_id > 0) {
        $stmt = $mysqli->prepare("
            SELECT id FROM access 
            WHERE user_id = ? AND company_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $section['company_id']);
        $stmt->execute();
        $has = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $has;
    }
    
    return false;
}

// Поиск по статьям (с учётом прав доступа) — через prepared statement
$search_param = '%' . $query . '%';
$stmt = $mysqli->prepare("
    SELECT s.id, s.title, s.content, s.company_id, c.name as company_name
    FROM sections s
    JOIN companies c ON c.id = s.company_id
    WHERE (s.title LIKE ? OR s.content LIKE ?)
    AND c.deleted = 0
    ORDER BY s.title
");
$stmt->bind_param("ss", $search_param, $search_param);
$stmt->execute();
$sections = $stmt->get_result();
$stmt->close();

// Поиск по компаниям — через prepared statement
$stmt = $mysqli->prepare("
    SELECT id, name, description
    FROM companies
    WHERE (name LIKE ? OR description LIKE ?)
    AND deleted = 0
    ORDER BY name
");
$stmt->bind_param("ss", $search_param, $search_param);
$stmt->execute();
$companies = $stmt->get_result();
$stmt->close();

// Поиск по файлам (только по оригинальному имени) — через prepared statement
$stmt = $mysqli->prepare("
    SELECT f.id, f.original_name, f.filepath, f.company_id, c.name as company_name
    FROM files f
    JOIN companies c ON c.id = f.company_id
    WHERE f.original_name LIKE ?
    AND c.deleted = 0
    ORDER BY f.original_name
");
$stmt->bind_param("s", $search_param);
$stmt->execute();
$files = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результаты поиска</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .search-container { max-width: 900px; margin: 30px auto; padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .search-result { padding: 12px 0; border-bottom: 1px solid #eee; }
        .search-result .title { font-size: 18px; font-weight: 600; }
        .search-result .title a { color: #007bff; text-decoration: none; }
        .search-result .title a:hover { text-decoration: underline; }
        .search-result .meta { font-size: 14px; color: #666; margin-top: 4px; }
        .search-result .snippet { color: #333; margin-top: 4px; }
        .no-results { color: #999; text-align: center; padding: 40px 0; }
        .category-header { font-size: 20px; font-weight: 600; margin: 20px 0 10px; color: #1a2a4a; border-bottom: 2px solid #e9ecef; padding-bottom: 5px; }
    </style>
</head>
<body>
<div class="container">
    <?php
    $show_search = true;
    include 'header.php';
    ?>
    
    <div class="search-container">
        <h1>Результаты поиска: "<?= htmlspecialchars($query) ?>"</h1>
        
        <!-- Статьи -->
        <?php if ($sections && $sections->num_rows > 0): ?>
            <div class="category-header">📄 Статьи</div>
            <?php 
            $articles_found = 0;
            while ($s = $sections->fetch_assoc()):
                // ✅ ИСПРАВЛЕНО (проблема 13): теперь проверяет section_access
                if (!hasAccessToSection($s['id'], $user_id, $role, $mysqli, $is_admin, $is_editor)) continue;
                
                $articles_found++;
                $snippet = strip_tags($s['content']);
                $snippet = mb_strlen($snippet) > 150 ? mb_substr($snippet, 0, 150) . '...' : $snippet;
            ?>
                <div class="search-result">
                    <div class="title"><a href="view.php?company=<?= (int)$s['company_id'] ?>&section=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['title']) ?></a></div>
                    <div class="meta">🏢 <?= htmlspecialchars($s['company_name']) ?></div>
                    <div class="snippet"><?= htmlspecialchars($snippet) ?></div>
                </div>
            <?php endwhile; ?>
            
            <?php if ($articles_found == 0): ?>
                <p style="color:#999;">Нет доступных статей по вашему запросу</p>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Компании -->
        <?php if ($companies && $companies->num_rows > 0): ?>
            <div class="category-header">🏢 Компании</div>
            <?php while ($c = $companies->fetch_assoc()): ?>
                <div class="search-result">
                    <div class="title"><a href="view.php?company=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a></div>
                    <div class="meta"><?= htmlspecialchars($c['description'] ?? '') ?></div>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
        
        <!-- Файлы -->
        <?php if ($files && $files->num_rows > 0): ?>
            <div class="category-header">📁 Файлы</div>
            <?php while ($f = $files->fetch_assoc()): ?>
                <div class="search-result">
                    <div class="title"><a href="download.php?file_id=<?= (int)$f['id'] ?>" target="_blank"><?= htmlspecialchars($f['original_name']) ?></a></div>
                    <div class="meta">🏢 <?= htmlspecialchars($f['company_name']) ?></div>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
        
        <?php if ($sections->num_rows == 0 && $companies->num_rows == 0 && $files->num_rows == 0): ?>
            <div class="no-results">Ничего не найдено</div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>