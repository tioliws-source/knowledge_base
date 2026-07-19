<?php
// ==================================================
// ФАЙЛ: config.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Конфигурация сайта
// ИСПРАВЛЕНИЯ:
//   1. Проблема 18: TinyMCE API-ключ вынесен в константу
//   2. Проблема 25: Добавлена общая функция hasAccessToSection()
//      (убирает дублирование в view.php, get_tree.php, get_section.php, search.php)
//   3. Проблема 26: has_access() теперь используется (не мёртвый код)
// ==================================================

// --- Настройки базы данных ---
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'knowledge_base';

// --- Подключение к MySQL с UTF-8 ---
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysqli = new mysqli($host, $user, $pass, $dbname);
    if ($mysqli->connect_error) {
        error_log('DB connection error: ' . $mysqli->connect_error);
        die('Ошибка подключения к базе данных');
    }
    $mysqli->set_charset('utf8mb4');
} catch (Exception $e) {
    error_log('DB connection exception: ' . $e->getMessage());
    die('Ошибка подключения к базе данных');
}

// --- Настройки сайта ---
$site_name = 'База знаний';
$site_url = 'http://localhost/knowledge_base/';

// --- Настройки загрузки файлов ---
$upload_dir = __DIR__ . '/uploads/';
$max_file_size = 50 * 1024 * 1024;

// ✅ ИСПРАВЛЕНО (проблема 18): TinyMCE API-ключ вынесен в константу
// Теперь его можно легко изменить в одном месте или вынести в .env
if (!defined('TINY_MCE_API_KEY')) {
    define('TINY_MCE_API_KEY', 'pjqumadfpz04wpbwx9l38knboh2zjv707edlllzdfrfdfck6');
}

// --- Подключаем общие защитные функции ---
require_once __DIR__ . '/includes/functions.php';

// ==========================================================
// БЕЗОПАСНЫЕ НАСТРОЙКИ СЕССИЙ
// ==========================================================
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_trans_sid', 0);
// ini_set('session.cookie_secure', 1);

if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    $session_lifetime = 14400;
} else {
    $session_lifetime = 28800;
}
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Strict'
]);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ==========================================================
// ОБНОВЛЕНИЕ last_activity (не чаще раза в минуту)
// ==========================================================
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
    if (!isset($_SESSION['last_db_update']) || time() - $_SESSION['last_db_update'] > 60) {
        $stmt = $mysqli->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
        $uid = (int)$_SESSION['user_id'];
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
        $_SESSION['last_db_update'] = time();
    }
}

// ==========================================================
// ФУНКЦИЯ ЗАПИСИ ЛОГА
// ==========================================================
function writeLog($user_id, $action, $details = '', $old_data = '', $new_data = '') {
    global $mysqli;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt = $mysqli->prepare("INSERT INTO logs (user_id, action, details, old_data, new_data, ip) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $user_id, $action, $details, $old_data, $new_data, $ip);
    $stmt->execute();
    $stmt->close();
}

// ==========================================================
// ✅ ИСПРАВЛЕНО (проблема 26): has_access() ТЕПЕРЬ ИСПОЛЬЗУЕТСЯ
// Функция проверки прав доступа к компании
// ==========================================================
function has_access($user_id, $company_id, $action = 'read') {
    global $mysqli;
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') return true;
    $stmt = $mysqli->prepare("SELECT id FROM access WHERE user_id = ? AND company_id = ? AND action = ?");
    $stmt->bind_param("iis", $user_id, $company_id, $action);
    $stmt->execute();
    $result = $stmt->get_result();
    $has = $result->num_rows > 0;
    $stmt->close();
    return $has;
}

// ==========================================================
// ✅ ИСПРАВЛЕНО (проблема 25): ОБЩАЯ ФУНКЦИЯ ПРОВЕРКИ ДОСТУПА
// К РАЗДЕЛУ — заменяет дублирующиеся функции в:
//   - view.php (hasSectionAccess)
//   - get_tree.php (hasAccess)
//   - get_section.php (hasAccess)
//   - search.php (hasAccessToSection)
// ==========================================================
function hasAccessToSection($section_id, $user_id, $role, $mysqli, $is_admin = false, $is_editor = false) {
    // Админ и редактор имеют полный доступ
    if ($is_admin || $is_editor) return true;
    
    // Получаем данные раздела через prepared statement
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
    
    // Проверяем таблицу section_access
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

// ==========================================================
// ФУНКЦИЯ ПОЛУЧЕНИЯ НАЗВАНИЯ РОЛИ
// ==========================================================
function getRoleName($role) {
    $roles = [
        'admin' => 'Администратор',
        'editor' => 'Редактор',
        'employee' => 'Сотрудник',
        'guest' => 'Гость'
    ];
    return $roles[$role] ?? $role;
}

// ==========================================================
// ПРОВЕРКА СЕССИИ (для страниц с ограниченным временем)
// ==========================================================
function checkSession() {
    global $session_lifetime;
    if (!isset($_SESSION['user_id'])) return false;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $session_lifetime) {
        session_destroy();
        return false;
    }
    return true;
}
?>