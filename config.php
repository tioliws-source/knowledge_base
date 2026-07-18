<?php
// ==================================================
// ФАЙЛ: config.php (БЕЗОПАСНАЯ ВЕРСИЯ)
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
$site_url = 'http://localhost/knowledge_base/'; // Замените на свой домен

// --- Настройки загрузки файлов ---
$upload_dir = __DIR__ . '/uploads/';
$max_file_size = 50 * 1024 * 1024;

// --- Подключаем общие защитные функции ---
require_once __DIR__ . '/includes/functions.php';

// ==========================================================
// БЕЗОПАСНЫЕ НАСТРОЙКИ СЕССИЙ
// ==========================================================
// Защита от XSS-кражи сессии (JS не сможет прочитать куку)
ini_set('session.cookie_httponly', 1);

// Защита от CSRF (кука отправляется только в рамках нашего сайта)
ini_set('session.cookie_samesite', 'Strict');

// Строгий режим — отклоняем неинициализированные ID сессий
ini_set('session.use_strict_mode', 1);

// Сессия только через куки (не через URL)
ini_set('session.use_only_cookies', 1);
ini_set('session.use_trans_sid', 0);

// Раскомментируйте, если сайт работает по HTTPS:
// ini_set('session.cookie_secure', 1);

// Время жизни сессии (зависит от роли)
if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    $session_lifetime = 14400; // 4 часа для админа
} else {
    $session_lifetime = 28800; // 8 часов для остальных
}

ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'secure' => false,     // Поставьте true, если у вас HTTPS
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
    
    // Обновляем в БД не чаще раза в минуту (чтобы не нагружать БД)
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
// ФУНКЦИЯ ПРОВЕРКИ ПРАВ ДОСТУПА К КОМПАНИИ (БЕЗОПАСНАЯ)
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