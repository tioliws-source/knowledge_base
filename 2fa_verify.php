<?php
// ==================================================
// ФАЙЛ: 2fa_verify.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['2fa_user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['2fa_user_id'];
$error = '';
$message = '';

// Получаем данные пользователя через prepared statement
$stmt = $mysqli->prepare("SELECT login, role, full_name, email, backup_email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: login.php');
    exit;
}

// Получаем последний отправленный код
$stmt = $mysqli->prepare("SELECT id, code, sent_to, expires_at, attempts, created_at FROM verification_codes WHERE user_id = ? AND type = '2fa' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$code_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Отправка кода
if (isset($_POST['action']) && $_POST['action'] == 'send_code') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности.';
    } else {
        $send_to = $_POST['send_to'] ?? '';
        
        if ($code_data) {
            $time_diff = time() - strtotime($code_data['created_at']);
            if ($time_diff < 60) {
                $error = 'Подождите ' . (60 - $time_diff) . ' секунд перед повторной отправкой';
            }
        }
        
        if (!$error) {
            $new_code = rand(100000, 999999);
            $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            $sent_to = ($send_to == 'backup' && $user['backup_email']) ? $user['backup_email'] : $user['email'];
            
            $stmt = $mysqli->prepare("INSERT INTO verification_codes (user_id, code, type, sent_to, expires_at) VALUES (?, ?, '2fa', ?, ?)");
            $stmt->bind_param("isss", $user_id, $new_code, $sent_to, $expires);
            $stmt->execute();
            $stmt->close();
            
            // ⚠️ ВАЖНО: НЕ показываем код!
            // В реальности здесь должна быть отправка email
            // mail($sent_to, 'Код 2FA', 'Ваш код: ' . $new_code);
            $message = '✅ Код отправлен на ' . $sent_to . '. Проверьте почту.';
            $code_data = null;
        }
    }
}

// Проверка кода
if (isset($_POST['action']) && $_POST['action'] == 'verify_code') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности.';
    } else {
        $input_code = trim($_POST['code'] ?? '');
        
        if (strlen($input_code) != 6 || !is_numeric($input_code)) {
            $error = 'Введите 6-значный код';
        } else {
            // Получаем актуальный код через prepared statement
            $stmt = $mysqli->prepare("SELECT id, code, expires_at, attempts FROM verification_codes WHERE user_id = ? AND type = '2fa' AND code = ? ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("is", $user_id, $input_code);
            $stmt->execute();
            $current_code = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$current_code) {
                $attempts = ($code_data['attempts'] ?? 0) + 1;
                $stmt = $mysqli->prepare("UPDATE verification_codes SET attempts = ? WHERE id = ?");
                $stmt->bind_param("ii", $attempts, $code_data['id']);
                $stmt->execute();
                $stmt->close();
                
                if ($attempts >= 4) {
                    writeLog($user_id, '2fa_blocked', 'Блокировка 2FA на 1 час');
                    $error = '❌ Превышено количество попыток. Доступ заблокирован на 1 час.';
                    
                    $stmt = $mysqli->prepare("DELETE FROM verification_codes WHERE user_id = ? AND type = '2fa'");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    session_destroy();
                    header('Location: login.php?blocked=1');
                    exit;
                }
                $error = '❌ Неверный код. Осталось попыток: ' . (4 - $attempts);
            } else {
                if (strtotime($current_code['expires_at']) < time()) {
                    $error = '❌ Код истёк. Запросите новый.';
                } else {
                    // ⚠️ ВАЖНО: перегенерация ID сессии после успешного входа
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['login'] = $user['login'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'] ?? $user['login'];
                    $_SESSION['last_activity'] = time();
                    
                    // Удаляем использованный код
                    $stmt = $mysqli->prepare("DELETE FROM verification_codes WHERE id = ?");
                    $stmt->bind_param("i", $current_code['id']);
                    $stmt->execute();
                    $stmt->close();
                    
                    writeLog($user_id, 'login_2fa', 'Вход с 2FA');
                    header('Location: index.php');
                    exit;
                }
            }
        }
    }
}

// Получаем обновлённый код для отображения (но НЕ показываем его!)
$stmt = $mysqli->prepare("SELECT id, code, sent_to, expires_at, attempts, created_at FROM verification_codes WHERE user_id = ? AND type = '2fa' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$code_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$can_resend = !$code_data || (time() - strtotime($code_data['created_at'])) >= 60;
$has_main_email = !empty($user['email']);
$has_backup_email = !empty($user['backup_email']);
$sent_to = $code_data['sent_to'] ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Двухфакторная аутентификация</title>
<link rel="stylesheet" href="style.css">
<style>
.verify-container { max-width: 450px; margin: 80px auto; padding: 40px; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); text-align: center; }
.verify-container h1 { margin-top: 0; color: #1a2a4a; font-size: 22px; }
.verify-container .user-info { background: #f8f9fa; padding: 10px; border-radius: 6px; margin: 15px 0; font-size: 14px; }
.verify-container .code-input { font-size: 28px; letter-spacing: 8px; text-align: center; padding: 12px; width: 200px; margin: 10px auto; border: 2px solid #007bff; border-radius: 8px; }
.verify-container .btn { padding: 10px 30px; font-size: 16px; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s; }
.verify-container .btn-primary { background: #007bff; color: #fff; }
.verify-container .btn-primary:hover { background: #0056b3; }
.verify-container .btn-outline { background: #f8f9fa; border: 1px solid #ccc; color: #333; }
.verify-container .btn-outline:hover { background: #e9ecef; }
.verify-container .btn:disabled { opacity: 0.5; cursor: not-allowed; }
.verify-container .error { color: #dc3545; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px; }
.verify-container .message { color: #28a745; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px; }
.verify-container .choice-buttons { display: flex; gap: 10px; justify-content: center; margin: 15px 0; flex-wrap: wrap; }
.verify-container .choice-buttons .btn { flex: 1; min-width: 120px; }
.verify-container .choice-buttons .btn.active { background: #007bff; color: #fff; border-color: #007bff; }
.verify-container .choice-buttons .btn:disabled { opacity: 0.3; cursor: not-allowed; }
.verify-container .timer { font-size: 14px; color: #666; margin: 10px 0; }
</style>
</head>
<body>
<div class="container">
<div class="verify-container">
<h1>🔐 Двухфакторная аутентификация</h1>
<p>Пользователь: <strong><?= htmlspecialchars($user['login']) ?></strong></p>

<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($message): ?>
<div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="user-info">
<p>Выберите способ получения кода:</p>
<div class="choice-buttons">
<button class="btn btn-outline <?= $sent_to == $user['email'] ? 'active' : '' ?>"
        onclick="sendCode('main')" <?= !$can_resend || !$has_main_email ? 'disabled' : '' ?>>
📧 Основная почта
</button>
<button class="btn btn-outline <?= $sent_to == $user['backup_email'] ? 'active' : '' ?>"
        onclick="sendCode('backup')" <?= !$can_resend || !$has_backup_email ? 'disabled' : '' ?>>
📧 Резервная почта
</button>
</div>
<?php if ($sent_to): ?>
<p style="font-size:13px;color:#666;">Код отправлен на: <strong><?= htmlspecialchars($sent_to) ?></strong></p>
<?php endif; ?>
</div>

<form method="POST" style="margin-top:20px;">
<?= csrfField() ?>
<input type="hidden" name="action" value="verify_code">
<div>
<input type="text" name="code" class="code-input" placeholder="000000" maxlength="6" required autofocus>
</div>
<p style="font-size:13px;color:#666;margin:5px 0;">Код действует 5 минут</p>
<button type="submit" class="btn btn-primary">✅ Подтвердить</button>
</form>

<div style="margin-top:20px; border-top:1px solid #eee; padding-top:20px;">
<button class="btn btn-outline" onclick="sendCode('resend')" <?= !$can_resend ? 'disabled' : '' ?>>
🔄 Отправить повторно
</button>
<span style="font-size:13px;color:#666;margin-left:10px;" id="timer"></span>
</div>

<div style="margin-top:15px;">
<a href="login.php" style="color:#007bff;text-decoration:none;font-size:14px;">← Вернуться к входу</a>
</div>
</div>
</div>

<script>
function sendCode(type) {
    if (type === 'resend') {
        type = document.querySelector('.choice-buttons .btn.active')?.textContent.includes('Основная') ? 'main' : 'backup';
        if (!type) type = 'main';
    }
    
    document.querySelectorAll('.choice-buttons .btn, .btn-outline').forEach(b => b.disabled = true);
    
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    fetch('2fa_verify.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=send_code&send_to=' + type + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.text())
    .then(html => { location.reload(); })
    .catch(() => { location.reload(); });
}

<?php if ($code_data): ?>
var timeDiff = <?= time() - strtotime($code_data['created_at']) ?>;
if (timeDiff < 60) {
    var seconds = 60 - timeDiff;
    var timerEl = document.getElementById('timer');
    timerEl.textContent = 'Повторная отправка через ' + seconds + 'с';
    var interval = setInterval(function() {
        seconds--;
        if (seconds <= 0) {
            clearInterval(interval);
            timerEl.textContent = '✅ Можно отправить повторно';
            document.querySelectorAll('.choice-buttons .btn, .btn-outline').forEach(b => b.disabled = false);
        } else {
            timerEl.textContent = 'Повторная отправка через ' + seconds + 'с';
        }
    }, 1000);
}
<?php endif; ?>
</script>
</body>
</html>