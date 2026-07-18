<?php
// ==================================================
// ФАЙЛ: save.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Универсальный обработчик AJAX-запросов
// ==================================================
require_once 'config.php';

// Проверяем, залогинен ли пользователь
if (!isset($_SESSION['user_id'])) {
    die('not_logged');
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'guest';

// CSRF-проверка для всех POST-запросов (кроме get_comments и get_favorites)
$is_read_action = (
    (isset($_GET['action']) && $_GET['action'] == 'get_comments') ||
    (isset($_POST['action']) && in_array($_POST['action'], ['get_favorites', 'get_comments']))
);

if (!$is_read_action && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        http_response_code(403);
        die(json_encode(['error' => 'Неверный CSRF-токен. Обновите страницу.']));
    }
}

// Проверяем, есть ли права на редактирование (админ или редактор)
$can_edit = ($role == 'admin' || $role == 'editor');

// ------------------------------------------------------------------
// 1. СОХРАНЕНИЕ ПОЛНЫХ ДАННЫХ (контент, название, права доступа)
// ------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] == 'save_full') {
    if (!$can_edit) die('access_denied');
    
    $section_id = (int)($_POST['section_id'] ?? 0);
    $content = sanitizeHtml($_POST['content'] ?? ''); // XSS-защита
    $title = trim($_POST['title'] ?? '');
    $roles_raw = $_POST['roles'] ?? '';
    $roles = !empty($roles_raw) ? explode(',', $roles_raw) : [];
    
    if (!$section_id) die('no_section');
    if (empty($title)) die('empty_title');
    
    // Проверяем доступ к разделу (через prepared statement)
    $stmt = $mysqli->prepare("
        SELECT s.id FROM sections s
        JOIN companies c ON c.id = s.company_id
        LEFT JOIN access a ON a.company_id = c.id AND a.user_id = ?
        WHERE s.id = ? AND c.deleted = 0 AND (a.action = 'write' OR ? = 'admin')
    ");
    $action_type = $role;
    $stmt->bind_param("iis", $user_id, $section_id, $action_type);
    $stmt->execute();
    $check = $stmt->get_result();
    $stmt->close();
    
    if ($check->num_rows == 0) {
        die('access_denied');
    }
    
    // Обновляем контент и название через prepared statement
    $stmt = $mysqli->prepare("UPDATE sections SET content = ?, title = ? WHERE id = ?");
    $stmt->bind_param("ssi", $content, $title, $section_id);
    if (!$stmt->execute()) {
        echo 'db_error';
        $stmt->close();
        exit;
    }
    $stmt->close();
    
    // Обновляем права доступа
    $stmt = $mysqli->prepare("DELETE FROM section_access WHERE section_id = ?");
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $stmt->close();
    
    $all_roles = ['editor', 'employee', 'guest'];
    foreach ($all_roles as $r) {
        $access_type = 'deny';
        if (in_array('all', $roles)) {
            $access_type = 'allow';
        } elseif (in_array('none', $roles)) {
            $access_type = 'deny';
        } else {
            $access_type = in_array($r, $roles) ? 'allow' : 'deny';
        }
        $stmt = $mysqli->prepare("INSERT INTO section_access (section_id, role, access_type) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $section_id, $r, $access_type);
        $stmt->execute();
        $stmt->close();
    }
    
    // Логируем
    writeLog($user_id, 'edit_section', "Отредактирован раздел ID $section_id");
    
    echo 'ok';
    exit;
}

// ------------------------------------------------------------------
// 2. СОХРАНЕНИЕ ТОЛЬКО ДОСТУПА
// ------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] == 'save_access_only') {
    if (!$can_edit) die('access_denied');
    
    $section_id = (int)($_POST['section_id'] ?? 0);
    $roles_raw = $_POST['roles'] ?? '';
    $roles = !empty($roles_raw) ? explode(',', $roles_raw) : [];
    
    if (!$section_id) die('no_section');
    
    // Проверяем доступ
    $stmt = $mysqli->prepare("
        SELECT s.id FROM sections s
        JOIN companies c ON c.id = s.company_id
        LEFT JOIN access a ON a.company_id = c.id AND a.user_id = ?
        WHERE s.id = ? AND c.deleted = 0 AND (a.action = 'write' OR ? = 'admin')
    ");
    $action_type = $role;
    $stmt->bind_param("iis", $user_id, $section_id, $action_type);
    $stmt->execute();
    $check = $stmt->get_result();
    $stmt->close();
    
    if ($check->num_rows == 0) die('access_denied');
    
    $stmt = $mysqli->prepare("DELETE FROM section_access WHERE section_id = ?");
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $stmt->close();
    
    $all_roles = ['editor', 'employee', 'guest'];
    foreach ($all_roles as $r) {
        $access_type = 'deny';
        if (in_array('all', $roles)) {
            $access_type = 'allow';
        } elseif (in_array('none', $roles)) {
            $access_type = 'deny';
        } else {
            $access_type = in_array($r, $roles) ? 'allow' : 'deny';
        }
        $stmt = $mysqli->prepare("INSERT INTO section_access (section_id, role, access_type) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $section_id, $r, $access_type);
        $stmt->execute();
        $stmt->close();
    }
    
    echo 'ok';
    exit;
}

// ------------------------------------------------------------------
// 3. УСТАНОВКА СТАТУСА ПУБЛИКАЦИИ
// ------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] == 'set_publish') {
    if (!$can_edit) die('access_denied');
    
    $section_id = (int)($_POST['section_id'] ?? 0);
    $status = (int)($_POST['status'] ?? 0);
    
    if (!$section_id) die('no_section');
    
    // Проверяем доступ
    $stmt = $mysqli->prepare("
        SELECT s.id FROM sections s
        JOIN companies c ON c.id = s.company_id
        LEFT JOIN access a ON a.company_id = c.id AND a.user_id = ?
        WHERE s.id = ? AND c.deleted = 0 AND (a.action = 'write' OR ? = 'admin')
    ");
    $action_type = $role;
    $stmt->bind_param("iis", $user_id, $section_id, $action_type);
    $stmt->execute();
    $check = $stmt->get_result();
    $stmt->close();
    
    if ($check->num_rows == 0) die('access_denied');
    
    $stmt = $mysqli->prepare("UPDATE sections SET is_published = ? WHERE id = ?");
    $stmt->bind_param("ii", $status, $section_id);
    if ($stmt->execute()) {
        writeLog($user_id, $status ? 'publish_section' : 'unpublish_section', "Раздел ID $section_id");
        echo 'ok';
    } else {
        echo 'db_error';
    }
    $stmt->close();
    exit;
}

// ------------------------------------------------------------------
// 4. ПРОВЕРКА НАЛИЧИЯ ДОЧЕРНИХ РАЗДЕЛОВ
// ------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] == 'check_children') {
    $section_id = (int)($_POST['section_id'] ?? 0);
    if (!$section_id) {
        echo 'error';
        exit;
    }
    
    $stmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM sections WHERE parent_id = ?");
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($row['cnt'] > 0) {
        echo 'has_children';
    } else {
        echo 'no_children';
    }
    exit;
}

// ------------------------------------------------------------------
// 5. УДАЛЕНИЕ РАЗДЕЛА
// ------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] == 'delete_section') {
    if (!$can_edit) die('access_denied');
    
    $section_id = (int)($_POST['section_id'] ?? 0);
    if (!$section_id) {
        echo 'no_section';
        exit;
    }
    
    // Проверяем права доступа
    $stmt = $mysqli->prepare("
        SELECT s.id FROM sections s
        JOIN companies c ON c.id = s.company_id
        LEFT JOIN access a ON a.company_id = c.id AND a.user_id = ?
        WHERE s.id = ? AND c.deleted = 0 AND (a.action = 'write' OR ? = 'admin')
    ");
    $action_type = $role;
    $stmt->bind_param("iis", $user_id, $section_id, $action_type);
    $stmt->execute();
    $check = $stmt->get_result();
    $stmt->close();
    
    if ($check->num_rows == 0) {
        echo 'access_denied';
        exit;
    }
    
    // Проверка на дочерние разделы
    $stmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM sections WHERE parent_id = ?");
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($row['cnt'] > 0) {
        echo 'has_children';
        exit;
    }
    
    // Удаляем через prepared statement
    $stmt = $mysqli->prepare("DELETE FROM sections WHERE id = ?");
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $stmt->close();
    
    writeLog($user_id, 'delete_section', "Удалён раздел ID $section_id");
    echo 'ok';
    exit;
}

// ------------------------------------------------------------------
// 6. СОЗДАНИЕ НОВОГО РАЗДЕЛА
// ------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] == 'create_section') {
    if (!$can_edit) die('access_denied');
    
    $company_id = (int)($_POST['company_id'] ?? 0);
    $parent_id = (int)($_POST['parent_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    
    if (!$company_id) die('no_company');
    if (empty($title)) $title = 'Новая статья';
    
    // Проверяем доступ к компании
    $stmt = $mysqli->prepare("
        SELECT c.id FROM companies c
        LEFT JOIN access a ON a.company_id = c.id AND a.user_id = ?
        WHERE c.id = ? AND c.deleted = 0 AND (a.action = 'write' OR ? = 'admin')
    ");
    $action_type = $role;
    $stmt->bind_param("iis", $user_id, $company_id, $action_type);
    $stmt->execute();
    $check = $stmt->get_result();
    $stmt->close();
    
    if ($check->num_rows == 0) die('access_denied');
    
    // Если parent_id == 0, делаем NULL (корневой раздел)
    $parent_id_sql = ($parent_id > 0) ? $parent_id : null;
    
    // Вставляем новый раздел
    $stmt = $mysqli->prepare("INSERT INTO sections (company_id, parent_id, title, content, is_public, is_published, sort_order) VALUES (?, ?, ?, '', 0, 0, 0)");
    if ($parent_id_sql === null) {
        $stmt->bind_param("is", $company_id, $parent_id_sql, $title);
    } else {
        $stmt->bind_param("iis", $company_id, $parent_id_sql, $title);
    }
    
    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        writeLog($user_id, 'add_section', "Создан раздел '$title' (ID: $new_id) в компании ID $company_id");
        echo 'ok';
    } else {
        echo 'db_error';
    }
    $stmt->close();
    exit;
}

// ------------------------------------------------------------------
// 7. ИЗБРАННОЕ (добавить/удалить)
// ------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] == 'toggle_favorite') {
    $section_id = (int)($_POST['section_id'] ?? 0);
    
    if (!$user_id) {
        echo json_encode(['error' => 'Не авторизован']);
        exit;
    }
    if (!$section_id) {
        echo json_encode(['error' => 'Не указан раздел']);
        exit;
    }
    
    // Проверяем, есть ли уже в избранном
    $stmt = $mysqli->prepare("SELECT id FROM favorites WHERE user_id = ? AND section_id = ?");
    $stmt->bind_param("ii", $user_id, $section_id);
    $stmt->execute();
    $check = $stmt->get_result();
    
    if ($check->num_rows > 0) {
        // Удаляем
        $stmt->close();
        $stmt = $mysqli->prepare("DELETE FROM favorites WHERE user_id = ? AND section_id = ?");
        $stmt->bind_param("ii", $user_id, $section_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'removed']);
    } else {
        // Добавляем
        $stmt->close();
        $stmt = $mysqli->prepare("INSERT INTO favorites (user_id, section_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $section_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'added']);
    }
    exit;
}

// ------------------------------------------------------------------
// 8. ПОЛУЧИТЬ СПИСОК ИЗБРАННОГО
// ------------------------------------------------------------------
if ((isset($_POST['action']) && $_POST['action'] == 'get_favorites') || 
    (isset($_GET['action']) && $_GET['action'] == 'get_favorites')) {
    
    if (!$user_id) {
        echo json_encode(['error' => 'Не авторизован']);
        exit;
    }
    
    $stmt = $mysqli->prepare("
        SELECT s.id, s.title, s.company_id, c.name as company_name, f.created_at
        FROM favorites f
        JOIN sections s ON s.id = f.section_id
        JOIN companies c ON c.id = s.company_id
        WHERE f.user_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $favorites = $stmt->get_result();
    $result = [];
    while ($row = $favorites->fetch_assoc()) {
        $result[] = $row;
    }
    $stmt->close();
    echo json_encode($result);
    exit;
}

// ------------------------------------------------------------------
// 9. ЗАПИСЬ ПРОСМОТРА (уникальный, с возвратом нового счётчика)
// ------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] == 'record_view') {
    $section_id = (int)($_POST['section_id'] ?? 0);
    if (!$section_id) {
        echo json_encode(['error' => 'Не указан раздел']);
        exit;
    }
    
    $session_id = session_id();
    
    // Проверяем, был ли просмотр за последние 30 секунд (через prepared statement)
    $stmt = $mysqli->prepare("
        SELECT id FROM view_log 
        WHERE section_id = ? AND session_id = ? 
        AND viewed_at > NOW() - INTERVAL 30 SECOND
    ");
    $stmt->bind_param("is", $section_id, $session_id);
    $stmt->execute();
    $check = $stmt->get_result();
    
    if ($check->num_rows == 0) {
        $stmt->close();
        
        // Новый уникальный просмотр
        $stmt = $mysqli->prepare("INSERT INTO view_log (section_id, session_id) VALUES (?, ?)");
        $stmt->bind_param("is", $section_id, $session_id);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $mysqli->prepare("UPDATE sections SET views_count = views_count + 1 WHERE id = ?");
        $stmt->bind_param("i", $section_id);
        $stmt->execute();
        $stmt->close();
        
        // Возвращаем новое количество просмотров
        $stmt = $mysqli->prepare("SELECT views_count FROM sections WHERE id = ?");
        $stmt->bind_param("i", $section_id);
        $stmt->execute();
        $new_count = $stmt->get_result()->fetch_assoc()['views_count'] ?? 0;
        $stmt->close();
        
        echo json_encode(['status' => 'recorded', 'new_count' => $new_count]);
    } else {
        $stmt->close();
        // Возвращаем текущее количество просмотров
        $stmt = $mysqli->prepare("SELECT views_count FROM sections WHERE id = ?");
        $stmt->bind_param("i", $section_id);
        $stmt->execute();
        $current_count = $stmt->get_result()->fetch_assoc()['views_count'] ?? 0;
        $stmt->close();
        
        echo json_encode(['status' => 'ignored', 'new_count' => $current_count]);
    }
    exit;
}

// ------------------------------------------------------------------
// 10. ПОЛУЧЕНИЕ КОММЕНТАРИЕВ (если таблицы существуют)
// ------------------------------------------------------------------
if ((isset($_GET['action']) && $_GET['action'] == 'get_comments') || 
    (isset($_POST['action']) && $_POST['action'] == 'get_comments')) {
    
    $section_id = (int)($_GET['section_id'] ?? $_POST['section_id'] ?? 0);
    if (!$section_id) { 
        echo '<p style="color:#999;">Нет комментариев</p>'; 
        exit; 
    }
    
    // Проверяем, существуют ли таблицы комментариев
    $check_table = $mysqli->query("SHOW TABLES LIKE 'comments'");
    if ($check_table->num_rows == 0) {
        echo '<p style="color:#999;">Комментарии отключены</p>';
        exit;
    }
    
    $is_moderator = ($role == 'admin' || $role == 'editor');
    $status_condition = $is_moderator ? '' : "AND c.status = 'approved'";
    
    $stmt = $mysqli->prepare("
        SELECT c.*, u.login, u.full_name, u.role
        FROM comments c
        JOIN users u ON u.id = c.user_id
        WHERE c.section_id = ? $status_condition
        ORDER BY c.created_at ASC
    ");
    $stmt->bind_param("i", $section_id);
    $stmt->execute();
    $comments = $stmt->get_result();
    $stmt->close();
    
    if ($comments->num_rows == 0) {
        echo '<p style="color:#999;">Нет комментариев</p>';
    } else {
        while ($c = $comments->fetch_assoc()) {
            $role_label = $c['role'] == 'admin' ? 'Администратор' : ($c['role'] == 'editor' ? 'Редактор' : ($c['role'] == 'employee' ? 'Сотрудник' : 'Гость'));
            
            echo '<div style="border-bottom:1px solid #f0f0f0;padding:10px 0;">';
            echo '<div style="font-weight:600;">' . htmlspecialchars($c['login']) . ' <span style="font-weight:normal;color:#666;font-size:13px;">(' . htmlspecialchars($role_label) . ')</span> <span style="color:#999;font-size:12px;">' . date('d.m.Y H:i', strtotime($c['created_at'])) . '</span></div>';
            echo '<div>' . nl2br(htmlspecialchars($c['content'])) . '</div>';
            if ($c['status'] == 'pending' && $is_moderator) {
                echo '<span style="color:#ffc107;font-size:12px;">⏳ На модерации</span>';
            }
            
            // Вложения (если таблица существует)
            $check_att = $mysqli->query("SHOW TABLES LIKE 'comment_attachments'");
            if ($check_att->num_rows > 0) {
                $stmt_att = $mysqli->prepare("SELECT * FROM comment_attachments WHERE comment_id = ?");
                $stmt_att->bind_param("i", $c['id']);
                $stmt_att->execute();
                $attachments = $stmt_att->get_result();
                while ($att = $attachments->fetch_assoc()) {
                    echo '<div style="margin-top:4px;">📎 <a href="' . htmlspecialchars($att['filepath']) . '" target="_blank">' . htmlspecialchars($att['original_name']) . '</a></div>';
                }
                $stmt_att->close();
            }
            
            echo '</div>';
        }
    }
    exit;
}

// ------------------------------------------------------------------
// Если действие не распознано
// ------------------------------------------------------------------
echo 'no_action';
?>