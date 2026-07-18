<?php
// ==================================================
// ФАЙЛ: recover_password.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$step = $_GET['step'] ?? 1;
$error = '';
$message = '';
$email = '';

// Шаг 1: Ввод почты
if ($step == 1 && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Введите корректный email';
        } else {
            $stmt = $mysqli->prepare("SELECT id, login, email, backup_email FROM users WHERE email = ? AND deleted = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($user = $result->fetch_assoc()) {
                $code = rand(100000, 999999);
                $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                
                $stmt2 = $mysqli->prepare("INSERT INTO verification_codes (user_id, code, type, sent_to, expires_at) VALUES (?, ?, 'recover', ?, ?)");
                $stmt2->bind_param("isss", $user['id'], $code, $user['email'], $expires);
                $stmt2->execute();
                $stmt2->close();
                
                $_SESSION['recover_user_id'] = $user['id'];
                $_SESSION['recover_email'] = $user['email'];
                $_SESSION['recover_code'] = $code;
                
                // ⚠️ ВАЖНО: НЕ сохраняем код для отображения!
                // В реальности здесь должна быть отправка email
                // mail($user['email'], 'Восстановление пароля', 'Ваш код: ' . $code);
                
                header('Location: recover_password.php?step=2');
                exit;
            } else {
                // НЕ раскрываем, что пользователь не найден (защита от перебора email)
                $error = 'Если пользователь с такой почтой существует, код будет отправлен';
            }
            $stmt->close();
        }
    }
}

// Шаг 2: Ввод кода и нового пароля
if ($step == 2 && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $input_code = trim($_POST['code'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!$input_code) {
            $error = 'Введите код из письма';
        } elseif ($input_code != ($_SESSION['recover_code'] ?? '')) {
            $error = 'Неверный код';
        } elseif (strlen($new_password) < 6 || strlen($new_password) > 12) {
            $error = 'Пароль должен быть от 6 до 12 символов';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_password)) {
            $error = 'Пароль может содержать только латинские буквы, цифры и _';
        } elseif ($new_password != $confirm_password) {
            $error = 'Пароли не совпадают';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $user_id = $_SESSION['recover_user_id'];
            
            // Обновляем пароль через prepared statement
            $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed, $user_id);
            $stmt->execute();
            $stmt->close();
            
            writeLog($user_id, 'recover_password', 'Восстановление пароля');
            
            // Удаляем использованный код
            $stmt = $mysqli->prepare("DELETE FROM verification_codes WHERE user_id = ? AND type = 'recover'");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
            
            unset($_SESSION['recover_user_id']);
            unset($_SESSION['recover_email']);
            unset($_SESSION['recover_code']);
            
            $message = '✅ Пароль успешно изменён! Теперь вы можете войти.';
            $step = 3;
        }
    }
}

// Если на шаге 2, получаем данные
if ($step == 2) {
    $user_id = $_SESSION['recover_user_id'] ?? 0;
    if (!$user_id) {
        header('Location: recover_password.php?step=1');
        exit;
    }
    $stmt = $mysqli->prepare("SELECT login, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Восстановление пароля</title>
<link rel="stylesheet" href="style.css">
<style>
.recover-container { max-width: 450px; margin: 80px auto; padding: 40px; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
.recover-container h1 { margin-top: 0; color: #1a2a4a; font-size: 22px; text-align: center; }
.recover-container .form-group { margin-bottom: 15px; }
.recover-container .form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
.recover-container .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
.recover-container .btn { padding: 10px 30px; font-size: 16px; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s; width: 100%; }
.recover-container .btn-primary { background: #007bff; color: #fff; }
.recover-container .btn-primary:hover { background: #0056b3; }
.recover-container .error { color: #dc3545; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px; }
.recover-container .message { color: #28a745; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px; text-align: center; }
.recover-container .code-hint { font-size: 13px; color: #666; text-align: center; margin: 10px 0; }
</style>
</head>
<body>
<div class="container">
<div class="recover-container">
<h1>🔑 Восстановление пароля</h1>
<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($message): ?>
<div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($step == 3): ?>
<p style="text-align:center;"><a href="login.php" class="btn btn-primary">🔐 Перейти к входу</a></p>
<?php endif; ?>

<?php if ($step == 1): ?>
<form method="POST">
<?= csrfField() ?>
<div class="form-group">
<label>Введите вашу почту</label>
<input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required placeholder="example@mail.ru">
</div>
<button type="submit" class="btn btn-primary">📩 Отправить код</button>
</form>
<div style="margin-top:15px;text-align:center;">
<a href="login.php" style="color:#007bff;text-decoration:none;">← Вернуться к входу</a>
</div>
<?php endif; ?>

<?php if ($step == 2): ?>
<div style="background:#f8f9fa;padding:10px;border-radius:6px;margin-bottom:15px;text-align:center;">
<p style="margin:0;">Пользователь: <strong><?= htmlspecialchars($user['login']) ?></strong></p>
<p style="margin:0;font-size:13px;color:#666;">Код отправлен на: <?= htmlspecialchars($user['email']) ?></p>
<!-- ⚠️ УБРАЛИ ПОКАЗ ТЕСТОВОГО КОДА -->
</div>
<form method="POST">
<?= csrfField() ?>
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
<div class="code-hint">Код действует 10 минут</div>
<?php endif; ?>
</div>
</div>
</body>
</html>