<?php
// ==================================================
// ФАЙЛ: includes/functions.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Общие защитные функции системы
// ИСПРАВЛЕНИЯ:
//   1. rand() → random_int() (криптографическая безопасность)
//   2. sanitizeHtml — добавлена фильтрация style-атрибутов
// ==================================================

// ==========================================================
// CSRF-ЗАЩИТА (защита от подделки межсайтовых запросов)
// ==========================================================

// Генерация CSRF-токена
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Проверка CSRF-токена
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Вывод скрытого поля CSRF в форме (использовать внутри <form>)
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}

// ==========================================================
// ЗАЩИТА ОТ XSS (очистка HTML от опасных тегов)
// ИСПРАВЛЕНО: добавлена фильтрация style-атрибутов
// ==========================================================
function sanitizeHtml($html) {
    if (empty($html)) return '';
    
    // Разрешённые теги для TinyMCE
    $allowed_tags = '<p><br><strong><em><u><s><ul><ol><li><h1><h2><h3><h4><h5><h6>'
        . '<a><img><table><tbody><tr><td><th><thead><blockquote><pre><code>'
        . '<span><div><hr><sub><sup><font><b><i>';
    
    $clean = strip_tags($html, $allowed_tags);
    
    // Удаляем опасные атрибуты (on* события, javascript:)
    $clean = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);
    $clean = preg_replace('/\s+on\w+\s*=\s*[^\s>]*/i', '', $clean);
    $clean = preg_replace('/href\s*=\s*["\']?\s*javascript:/i', 'href="', $clean);
    $clean = preg_replace('/src\s*=\s*["\']?\s*javascript:/i', 'src="', $clean);
    
    // Удаляем опасные теги
    $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $clean);
    $clean = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $clean);
    $clean = preg_replace('/<object\b[^>]*>.*?<\/object>/is', '', $clean);
    $clean = preg_replace('/<embed\b[^>]*>/is', '', $clean);
    $clean = preg_replace('/<form\b[^>]*>.*?<\/form>/is', '', $clean);
    $clean = preg_replace('/<input\b[^>]*>/is', '', $clean);
    $clean = preg_replace('/<button\b[^>]*>.*?<\/button>/is', '', $clean);
    
    // ИСПРАВЛЕНО: Фильтрация style-атрибутов (защита от XSS через CSS)
    // Удаляем style с опасными значениями
    $clean = preg_replace_callback('/style\s*=\s*["\']([^"\']*)["\']/i', function($matches) {
        $style = $matches[1];
        
        // Удаляем опасные CSS-свойства
        $dangerous_patterns = [
            '/expression\s*\(/i',           // IE expression()
            '/javascript\s*:/i',            // javascript: URL
            '/url\s*\(\s*["\']?\s*javascript:/i',  // url(javascript:)
            '/behavior\s*:/i',              // CSS behavior
            '/-moz-binding\s*:/i',          // Firefox binding
            '/import\s+url/i',              // @import
            '/data\s*:\s*text\/html/i',     // data: URL
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $style)) {
                return ''; // Удаляем весь style-атрибут
            }
        }
        
        return $matches[0]; // Возвращаем безопасный style
    }, $clean);
    
    return $clean;
}

// ==========================================================
// ЗАЩИТА ОТ PATH TRAVERSAL (защита от скачивания чужих файлов)
// ==========================================================
function isPathSafe($filepath, $base_dir) {
    $real_base = realpath($base_dir);
    $real_path = realpath($filepath);
    
    if ($real_path === false || $real_base === false) return false;
    
    return strpos($real_path, $real_base) === 0;
}

// ==========================================================
// RATE LIMITING (защита от подбора паролей)
// ==========================================================

// Проверка, не превышено ли число попыток входа
function checkLoginAttempts($ip, $max_attempts = 5, $window_minutes = 15) {
    global $mysqli;
    
    // Если таблица login_attempts ещё не создана — пропускаем проверку
    $check_table = $mysqli->query("SHOW TABLES LIKE 'login_attempts'");
    if ($check_table->num_rows == 0) return true;
    
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) as cnt 
        FROM login_attempts 
        WHERE ip = ? AND attempted_at > NOW() - INTERVAL ? MINUTE
    ");
    $minutes = (int)$window_minutes;
    $stmt->bind_param("si", $ip, $minutes);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result['cnt'] < $max_attempts;
}

// Запись неудачной попытки входа
function recordLoginAttempt($ip, $login) {
    global $mysqli;
    
    $check_table = $mysqli->query("SHOW TABLES LIKE 'login_attempts'");
    if ($check_table->num_rows == 0) return;
    
    $stmt = $mysqli->prepare("INSERT INTO login_attempts (ip, login) VALUES (?, ?)");
    $stmt->bind_param("ss", $ip, $login);
    $stmt->execute();
    $stmt->close();
}

// Очистка попыток после успешного входа
function clearLoginAttempts($ip) {
    global $mysqli;
    
    $check_table = $mysqli->query("SHOW TABLES LIKE 'login_attempts'");
    if ($check_table->num_rows == 0) return;
    
    $stmt = $mysqli->prepare("DELETE FROM login_attempts WHERE ip = ?");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $stmt->close();
}

// ==========================================================
// ГЕНЕРАЦИЯ БЕЗОПАСНЫХ СЛУЧАЙНЫХ ЧИСЕЛ
// ИСПРАВЛЕНО: используем random_int() вместо rand()
// ==========================================================
function generateSecureCode($length = 6) {
    $min = pow(10, $length - 1);
    $max = pow(10, $length) - 1;
    return random_int($min, $max);
}

// ==========================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ПРОВЕРКИ РОЛЕЙ
// ==========================================================
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isEditor() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'editor';
}

function canEdit() {
    return isAdmin() || isEditor();
}

// ==========================================================
// БЕЗОПАСНАЯ ПРОВЕРКА ЦЕЛОЧИСЛЕННОГО ЗНАЧЕНИЯ
// ==========================================================
function safeInt($value, $default = 0) {
    return is_numeric($value) ? (int)$value : $default;
}
?>