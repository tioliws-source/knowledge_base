<?php
// ==================================================
// ФАЙЛ: change_password.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Смена пароля с подтверждением по email
// ИСПРАВЛЕНИЯ:
//   1. Проблема 16: rand() → random_int() (криптографическая безопасность)
//   2. Убрана передача кода в сессию (проверка через БД)
//   3. Добавлена проверка истечения срока действия кода
// ==================================================
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$error = '';
$message = '';
$step = $_GET['step'] ?? 1;

// Получаем данные пользователя через prepared statement
$stmt = $mysqli->prepare("SELECT id, login, email, backup_email, role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// ==========================================================
// ШАГ 1: ЗАПРОС КОДА
// ==========================================================
if ($step == 1 && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $send_to = $_POST['send_to'] ?? 'main';
        $target_email = $send_to == 'backup' ? $user['backup_email'] : $user['email'];
        
        if (empty($target_email)) {
            $error = 'У вас не указана эта почта';
        } else {
            // ✅ ИСПРАВЛЕНО (проблема 16): random_int() вместо rand()
            $code = random_int(100000, 999999);
            $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            
            $stmt = $mysqli->prepare("INSERT INTO verification_codes (user_id, code, type, sent_to, expires_at) VALUES (?, ?, 'change_password', ?, ?)");
            $stmt->bind_param("isss", $user_id, $code, $target_email, $expires);
            $stmt->execute();
            $stmt->close();
            
            // ✅ НЕ сохраняем код в сессии — только в БД
            $_SESSION['change_password_sent_to'] = $target_email;
            
            // ⚠️ В реальности здесь должна быть отправка email
            // mail($target_email, 'Смена пароля', 'Ваш код: ' . $code);
            
            $message = '✅ Код отправлен на ' . $target_email . '. Проверьте почту.';
            $step = 2;
        }
    }
}

// ==========================================================
// ШАГ 2: ПРОВЕРКА КОДА И СМЕНА ПАРОЛЯ
// ==========================================================
if ($step == 2 && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $input_code = trim($_POST['code'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!$input_code) {
            $error = 'Введите код из письма';
        } elseif (strlen($new_password) < 6 || strlen($new_password) > 12) {
            $error = 'Пароль должен быть от 6 до 12 символов';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_password)) {
            $error = 'Пароль может содержать только латинские буквы, цифры и _';
        } elseif ($new_password != $confirm_password) {
            $error = 'Пароли не совпадают';
        } else {
            // ✅ Проверяем код через БД (а не через сессию)
            $stmt = $mysqli->prepare("
                SELECT id, code, expires_at 
                FROM verification_codes 
                WHERE user_id = ? AND type = 'change_password' 
                AND code = ? 
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->bind_param("is", $user_id, $input_code);
            $stmt->execute();
            $code_check = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$code_check) {
                $error = '❌ Неверный код';
            } elseif (strtotime($code_check['expires_at']) < time()) {
                $error = '❌ Код истёк. Запросите новый.';
            } else {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Обновляем пароль через prepared statement
                $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed, $user_id);
                $stmt->execute();
                $stmt->close();
                
                writeLog($user_id, 'change_password', 'Смена пароля (через профиль)');
                
                // Удаляем использованный код
                $stmt = $mysqli->prepare("DELETE FROM verification_codes WHERE user_id = ? AND type = 'change_password'");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
                
                unset($_SESSION['change_password_sent_to']);
                
                $message = '✅ Пароль успешно изменён!';
                $step = 3;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Смена пароля</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .change-container { max-width: 450px; margin: 60px auto; padding: 30px; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
        .change-container h1 { margin-top: 0; color: #1a2a4a; font-size: 22px; text-align: center; }
        .change-container .form-group { margin-bottom: 15px; }
        .change-container .form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
        .change-container .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
        .change-container .btn { padding: 10px 30px; font-size: 16px; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s; width: 100%; }
        .change-container .btn-primary { background: #007bff; color: #fff; }
        .change-container .btn-primary:hover { background: #0056b3; }
        .change-container .btn-outline { background: #f8f9fa; border: 1px solid #ccc; color: #333; }
        .change-container .btn-outline:hover { background: #e9ecef; }
        .change-container .error { color: #dc3545; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px; }
        .change-container .message { color: #28a745; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px; text-align: center; }
        .change-container .choice-buttons { display: flex; gap: 10px; justify-content: center; margin: 15px 0; flex-wrap: wrap; }
        .change-container .choice-buttons .btn { flex: 1; min-width: 120px; }
        .change-container .code-hint { font-size: 13px; color: #666; text-align: center; margin: 10px 0; }
    </style>
</head>
<body>
<div class="container">
    <div class="change-container">
        <h1>🔑 Смена пароля</h1>
        <p style="text-align:center;color:#666;">Пользователь: <strong><?= htmlspecialchars($user['login']) ?></strong></p>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($step == 3): ?>
            <p style="text-align:center;"><a href="index.php" class="btn btn-primary">🏠 На главную</a></p>
        <?php endif; ?>
        
        <?php if ($step == 1): ?>
            <p style="text-align:center;color:#666;font-size:14px;">Для смены пароля необходимо подтверждение по почте</p>
            <form method="POST">
                <?= csrfField() ?>
                <div class="choice-buttons">
                    <button type="submit" name="send_code" value="1" class="btn btn-outline" <?= empty($user['email']) ? 'disabled' : '' ?>>
                        📧 Основная почта
                    </button>
                    <input type="hidden" name="send_to" value="main">
                </div>
                <div class="choice-buttons" style="margin-top:5px;">
                    <button type="submit" name="send_code" value="1" class="btn btn-outline" <?= empty($user['backup_email']) ? 'disabled' : '' ?>>
                        📧 Резервная почта
                    </button>
                    <input type="hidden" name="send_to" value="backup">
                </div>
            </form>
            <div style="text-align:center;margin-top:10px;">
                <a href="index.php" style="color:#007bff;text-decoration:none;">← Вернуться</a>
            </div>
        <?php endif; ?>
        
        <?php if ($step == 2): ?>
            <div style="background:#f8f9fa;padding:10px;border-radius:6px;margin-bottom:15px;text-align:center;">
                <p style="margin:0;font-size:13px;color:#666;">Код отправлен на: <strong><?= htmlspecialchars($_SESSION['change_password_sent_to'] ?? '') ?></strong></p>
                <!-- ✅ НЕ показываем код даже в тестовом режиме -->
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="change_password" value="1">
                <div class="form-group">
                    <label>Код из письма</label>
                    <input type="text" name="code" placeholder="Введите 6-значный код" maxlength="6" required>
                </div>
                <div class="form-group">
                    <label>Новый пароль</label>
                    <input type="password" name="new_password" placeholder="От 6 до 12 символов" required>
                </div>
                <div class="form-group">
                    <label>Подтвердите пароль</label>
                    <input type="password" name="confirm_password" placeholder="Повторите пароль" required>
                </div>
                <button type="submit" class="btn btn-primary">💾 Сохранить пароль</button>
            </form>
            <div class="code-hint">Код действует 5 минут</div>
            <div style="text-align:center;margin-top:10px;">
                <a href="change_password.php?step=1" style="color:#007bff;text-decoration:none;">← Запросить новый код</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>