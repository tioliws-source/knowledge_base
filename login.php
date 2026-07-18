<?php
// ==================================================
// ФАЙЛ: login.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

// Если уже залогинены — перенаправляем на главную
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. CSRF-проверка
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } 
    // 2. Rate limiting — защита от подбора паролей
    elseif (!checkLoginAttempts($ip, 5, 15)) {
        $error = 'Слишком много неудачных попыток. Подождите 15 минут.';
    } 
    else {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if ($login && $password) {
            // Используем prepared statement (защита от SQL-инъекций)
            $stmt = $mysqli->prepare("
                SELECT id, login, password, role, full_name, two_factor_enabled 
                FROM users 
                WHERE login = ? AND deleted = 0
            ");
            $stmt->bind_param("s", $login);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($user = $result->fetch_assoc()) {
                if (password_verify($password, $user['password'])) {
                    // ✅ Успешный вход — очищаем попытки
                    clearLoginAttempts($ip);
                    
                    // ⚠️ ВАЖНО: перегенерация ID сессии (защита от session fixation)
                    session_regenerate_id(true);
                    
                    // Проверяем, нужна ли 2FA
                    if ($user['two_factor_enabled']) {
                        $_SESSION['2fa_user_id'] = $user['id'];
                        $_SESSION['2fa_login'] = $user['login'];
                        $_SESSION['2fa_role'] = $user['role'];
                        $_SESSION['2fa_full_name'] = $user['full_name'] ?? $user['login'];
                        
                        // НЕ устанавливаем user_id до проверки 2FA
                        header('Location: 2fa_verify.php');
                        exit;
                    }
                    
                    // Устанавливаем сессию
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['login'] = $user['login'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'] ?? $user['login'];
                    $_SESSION['last_activity'] = time();
                    
                    writeLog($user['id'], 'login', 'Успешный вход');
                    
                    // Защита от open redirect — разрешаем только безопасные URL
                    $redirect = $_GET['redirect'] ?? 'index.php';
                    if (!preg_match('/^[a-zA-Z0-9_\-\.\/\?=&]+$/', $redirect)) {
                        $redirect = 'index.php';
                    }
                    
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    // ❌ Неверный пароль — записываем попытку
                    recordLoginAttempt($ip, $login);
                    // НЕ раскрываем, что именно неверно (логин или пароль)
                    $error = 'Неверный логин или пароль';
                }
            } else {
                // ❌ Неверный логин — записываем попытку
                recordLoginAttempt($ip, $login);
                // НЕ раскрываем, что именно неверно
                $error = 'Неверный логин или пароль';
            }
            $stmt->close();
        } else {
            $error = 'Заполните все поля';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход — <?= htmlspecialchars($site_name) ?></title>
<link rel="stylesheet" href="style.css">
<link rel="icon" href="favicon.ico" type="image/x-icon">
<style>
.login-container {
    max-width: 400px;
    margin: 80px auto;
    padding: 40px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
}
.login-container h1 {
    text-align: center;
    margin-bottom: 30px;
    font-size: 24px;
    color: #1a2a4a;
}
.login-container label {
    display: block;
    font-weight: 500;
    margin: 12px 0 4px;
}
.login-container input[type="text"],
.login-container input[type="password"] {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 16px;
    box-sizing: border-box;
}
.login-container .btn {
    width: 100%;
    padding: 12px;
    font-size: 16px;
    margin-top: 20px;
}
.login-container .error {
    color: #dc3545;
    text-align: center;
    padding: 10px;
    background: #f8d7da;
    border-radius: 6px;
    margin-bottom: 15px;
}
.login-container .links {
    text-align: center;
    margin-top: 20px;
    font-size: 14px;
    color: #666;
}
.login-container .links a {
    color: #007bff;
    text-decoration: none;
}
</style>
</head>
<body>
<div class="container">
<div class="login-container">
<h1>🔐 Вход в систему</h1>
<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<form method="POST">
<!-- CSRF-токен (защита от подделки запросов) -->
<?= csrfField() ?>
<label for="login">Логин</label>
<input type="text" name="login" id="login" placeholder="Введите логин" required autocomplete="username">
<label for="password">Пароль</label>
<input type="password" name="password" id="password" placeholder="Введите пароль" required autocomplete="current-password">
<button type="submit" class="btn btn-primary">Войти</button>
</form>
<div class="links">
<p><a href="recover_password.php">Забыли пароль?</a></p>
<p><a href="index.php">← Вернуться на главную</a></p>
</div>
</div>
</div>
</body>
</html>