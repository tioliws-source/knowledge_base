<?php
// ==================================================
// ФАЙЛ: logout.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Безопасный выход из системы
// ==================================================

// Запускаем сессию (если ещё не запущена)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Логируем выход (если пользователь был авторизован)
if (isset($_SESSION['user_id'])) {
    // Подключаем БД для логирования
    if (!isset($mysqli)) {
        require_once __DIR__ . '/config.php';
    }
    if (isset($mysqli) && function_exists('writeLog')) {
        writeLog($_SESSION['user_id'], 'logout', 'Выход из системы');
    }
}

// Полная очистка сессии
$_SESSION = [];

// Удаляем куку сессии
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Уничтожаем сессию
session_destroy();

// Перенаправляем на главную
header('Location: index.php');
exit;
?>