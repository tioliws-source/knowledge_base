<?php
// ==================================================
// ФАЙЛ: profile.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

// Подключаем шапку
$show_search = true;
include 'header.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$error = '';
$message = '';

// Получаем данные пользователя через prepared statement
$stmt = $mysqli->prepare("SELECT id, login, full_name, position, avatar, email, backup_email, phone, two_factor_enabled, last_activity, role FROM users WHERE id = ?");
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
// ОБНОВЛЕНИЕ ПРОФИЛЯ (БЕЗ СМЕНЫ ПАРОЛЯ)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    // CSRF-проверка
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $backup_email = trim($_POST['backup_email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        $errors = [];
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Некорректный email';
        }
        if (!empty($backup_email) && !filter_var($backup_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Некорректный резервный email';
        }
        if (!empty($full_name) && (mb_strlen($full_name) < 3 || mb_strlen($full_name) > 50)) {
            $errors[] = 'ФИО должно быть от 3 до 50 символов';
        }
        if (!empty($position) && mb_strlen($position) > 40) {
            $errors[] = 'Должность должна быть не более 40 символов';
        }
        
        if (empty($errors)) {
            $stmt = $mysqli->prepare("UPDATE users SET full_name = ?, position = ?, email = ?, backup_email = ?, phone = ? WHERE id = ?");
            $stmt->bind_param("sssssi", $full_name, $position, $email, $backup_email, $phone, $user_id);
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['full_name'] = $full_name ?: $_SESSION['login'];
            writeLog($user_id, 'edit_profile', 'Обновлён профиль пользователя');
            $message = '✅ Профиль обновлён!';
            
            // Обновляем данные
            $stmt = $mysqli->prepare("SELECT id, login, full_name, position, avatar, email, backup_email, phone, two_factor_enabled, last_activity, role FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $error = implode('. ', $errors);
        }
    }
}

// ==========================================================
// ЗАГРУЗКА АВАТАРКИ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'upload_avatar') {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
        $file = $_FILES['avatar'];
        $original_name = $file['name'];
        
        if (strlen($original_name) > 40) {
            die('Название файла не должно превышать 40 символов');
        }
        
        // Проверка реального MIME-типа
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime_type, $allowed_mime)) {
            die('Разрешены только изображения: JPG, PNG, WebP, GIF');
        }
        
        $max_size = 2 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            die('Размер файла не должен превышать 2 МБ');
        }
        
        $ext_map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif'
        ];
        $ext = $ext_map[$mime_type] ?? 'jpg';
        
        $avatar_dir = __DIR__ . '/uploads/avatars/';
        if (!is_dir($avatar_dir)) {
            mkdir($avatar_dir, 0777, true);
        }
        
        $new_filename = 'avatar_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $target_path = $avatar_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $avatar_path = '/uploads/avatars/' . $new_filename;
            $stmt = $mysqli->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            $stmt->bind_param("si", $avatar_path, $user_id);
            $stmt->execute();
            $stmt->close();
            echo $avatar_path;
        } else {
            echo 'Ошибка загрузки файла';
        }
    } else {
        echo 'Файл не выбран или ошибка при загрузке';
    }
    exit;
}

// ==========================================================
// СМЕНА ПАРОЛЯ (С ТЕКУЩИМ ПАРОЛЕМ)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password_with_current'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Получаем текущий хеш пароля через prepared statement
        $stmt = $mysqli->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $check = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!password_verify($current_password, $check['password'])) {
            $error = '❌ Неверный текущий пароль';
        } elseif (strlen($new_password) < 6 || strlen($new_password) > 12) {
            $error = 'Пароль должен быть от 6 до 12 символов';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_password)) {
            $error = 'Пароль может содержать только латинские буквы, цифры и _';
        } elseif ($new_password != $confirm_password) {
            $error = 'Пароли не совпадают';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed, $user_id);
            $stmt->execute();
            $stmt->close();
            
            writeLog($user_id, 'change_password', 'Смена пароля (через профиль, с текущим паролем)');
            $message = '✅ Пароль успешно изменён!';
        }
    }
}

// ==========================================================
// СМЕНА ПАРОЛЯ (ЗАБЫЛ ПАРОЛЬ — С КОДОМ НА EMAIL)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password_with_code'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $code = trim($_POST['code'] ?? '');
        $new_password = $_POST['new_password_code'] ?? '';
        $confirm_password = $_POST['confirm_password_code'] ?? '';
        
        if (strlen($new_password) < 6 || strlen($new_password) > 12) {
            $error = 'Пароль должен быть от 6 до 12 символов';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_password)) {
            $error = 'Пароль может содержать только латинские буквы, цифры и _';
        } elseif ($new_password != $confirm_password) {
            $error = 'Пароли не совпадают';
        } elseif (empty($code)) {
            $error = 'Введите код подтверждения';
        } else {
            // Проверяем код через prepared statement
            $stmt = $mysqli->prepare("SELECT id, expires_at FROM verification_codes WHERE user_id = ? AND type = 'change_password' AND code = ? ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("is", $user_id, $code);
            $stmt->execute();
            $code_check = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$code_check) {
                $error = '❌ Неверный код';
            } elseif (strtotime($code_check['expires_at']) < time()) {
                $error = '❌ Код истёк. Запросите новый.';
            } else {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed, $user_id);
                $stmt->execute();
                $stmt->close();
                
                // Удаляем использованный код
                $stmt = $mysqli->prepare("DELETE FROM verification_codes WHERE user_id = ? AND type = 'change_password'");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
                
                writeLog($user_id, 'change_password', 'Смена пароля (через профиль, по коду)');
                $message = '✅ Пароль успешно изменён!';
            }
        }
    }
}

// ==========================================================
// ОТПРАВКА КОДА ДЛЯ СМЕНЫ ПАРОЛЯ (AJAX)
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'send_code') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'Ошибка безопасности';
        exit;
    }
    
    $send_to = $_POST['send_to'] ?? 'main';
    $target_email = $send_to == 'backup' ? $user['backup_email'] : $user['email'];
    
    if (empty($target_email)) {
        echo 'У вас не указана эта почта';
        exit;
    }
    
    // Проверяем, не отправляли ли код меньше минуты назад
    $stmt = $mysqli->prepare("SELECT created_at FROM verification_codes WHERE user_id = ? AND type = 'change_password' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $last_code = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($last_code) {
        $time_diff = time() - strtotime($last_code['created_at']);
        if ($time_diff < 60) {
            echo 'Подождите ' . (60 - $time_diff) . ' секунд перед повторной отправкой';
            exit;
        }
    }
    
    // Генерируем код
    $code = rand(100000, 999999);
    $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    
    $stmt = $mysqli->prepare("INSERT INTO verification_codes (user_id, code, type, sent_to, expires_at) VALUES (?, ?, 'change_password', ?, ?)");
    $stmt->bind_param("isss", $user_id, $code, $target_email, $expires);
    $stmt->execute();
    $stmt->close();
    
    // ⚠️ ВАЖНО: НЕ возвращаем код в ответе! В реальности здесь должна быть отправка email
    // mail($target_email, 'Код подтверждения', 'Ваш код: ' . $code);
    echo 'ok';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Мой профиль</title>
<link rel="stylesheet" href="style.css">
<meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
<style>
.profile-container { max-width: 1100px; margin: 30px auto; padding: 30px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.profile-container h1 { margin-top: 0; color: #1a2a4a; border-bottom: 2px solid #e9ecef; padding-bottom: 15px; }
.profile-container .form-group { margin-bottom: 15px; }
.profile-container .form-group label { display: block; font-weight: 600; margin-bottom: 4px; color: #333; font-size: 14px; }
.profile-container .form-group input, .profile-container .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
.profile-container .form-group .hint { color: #6c757d; font-size: 12px; margin-top: 4px; }
.profile-container .avatar-preview { margin: 10px 0; }
.profile-container .avatar-preview img { max-height: 80px; max-width: 80px; border-radius: 50%; border: 2px solid #ddd; padding: 3px; }
.profile-container .btn { padding: 8px 24px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.2s; }
.profile-container .btn-primary { background: #007bff; color: #fff; }
.profile-container .btn-primary:hover { background: #0056b3; }
.profile-container .btn-success { background: #28a745; color: #fff; }
.profile-container .btn-success:hover { background: #1e7e34; }
.profile-container .btn-outline { background: #f8f9fa; border: 1px solid #ccc; color: #333; }
.profile-container .btn-outline:hover { background: #e9ecef; }
.profile-container .btn-sm { padding: 4px 12px; font-size: 12px; }
.profile-container .error { color: #dc3545; padding: 10px; background: #f8d7da; border-radius: 6px; margin-bottom: 15px; }
.profile-container .message { color: #28a745; padding: 10px; background: #d4edda; border-radius: 6px; margin-bottom: 15px; }
.profile-container .section-divider { border-top: 2px solid #e9ecef; margin: 25px 0 20px; padding-top: 20px; }
.profile-container .section-divider h3 { color: #1a2a4a; margin-top: 0; font-size: 18px; }
.profile-container .code-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.profile-container .code-row input { flex: 1; min-width: 150px; }
.profile-container .code-row .btn { white-space: nowrap; }
.profile-container .btn:disabled { opacity: 0.5; cursor: not-allowed; }
.profile-info { background: #f8f9fa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 15px 30px; }
.profile-info p { margin: 3px 0; font-size: 14px; }
.profile-info strong { color: #1a2a4a; }
.two-factor-status { display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
.two-factor-status.on { background: #d4edda; color: #155724; }
.two-factor-status.off { background: #f8d7da; color: #721c24; }
.role-badge { padding: 2px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
.role-badge.admin { background: #cce5ff; color: #004085; }
.role-badge.editor { background: #d4edda; color: #155724; }
.role-badge.employee { background: #fff3cd; color: #856404; }
.profile-two-col { display: flex; gap: 40px; align-items: flex-start; }
.profile-col-left { flex: 1; min-width: 300px; }
.profile-col-right { flex: 1; min-width: 300px; }
@media (max-width: 768px) { .profile-two-col { flex-direction: column; } .profile-col-left, .profile-col-right { min-width: unset; width: 100%; } }
.email-choice-buttons { display: flex; gap: 10px; margin: 8px 0; flex-wrap: wrap; }
.email-choice-buttons .email-btn { padding: 6px 16px; border: 2px solid #ccc; border-radius: 6px; background: #fff; cursor: pointer; font-size: 13px; transition: all 0.2s; color: #333; text-align: left; }
.email-choice-buttons .email-btn:hover { border-color: #007bff; background: #f0f7ff; }
.email-choice-buttons .email-btn.active { border-color: #28a745; background: #d4edda; color: #155724; font-weight: 600; }
.email-choice-buttons .email-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.email-choice-buttons .email-btn .label { display: block; font-weight: 600; font-size: 13px; }
.email-choice-buttons .email-btn .address { font-size: 11px; color: #666; font-weight: normal; }
.password-section { background: #fafbfc; padding: 20px; border-radius: 10px; border: 1px solid #e9ecef; }
.password-method-toggle { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
.password-method-toggle .method-btn { padding: 6px 16px; border: 2px solid #ccc; border-radius: 6px; background: #fff; cursor: pointer; font-size: 13px; transition: all 0.2s; color: #333; }
.password-method-toggle .method-btn:hover { border-color: #007bff; background: #f0f7ff; }
.password-method-toggle .method-btn.active { border-color: #007bff; background: #cce5ff; color: #004085; font-weight: 600; }
.password-method-content { display: none; }
.password-method-content.active { display: block; }
</style>
</head>
<body>
<div class="container">
<div class="profile-container">
<h1>👤 Мой профиль</h1>
<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($message): ?>
<div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="profile-info">
<p><strong>Логин:</strong> <?= htmlspecialchars($user['login']) ?></p>
<p><strong>ID:</strong> <?= $user['id'] ?></p>
<p><strong>Роль:</strong> <span class="role-badge <?= $role ?>"><?= $role == 'admin' ? 'Администратор' : ($role == 'editor' ? 'Редактор' : 'Сотрудник') ?></span></p>
<?php if ($role == 'admin'): ?>
<p><strong>2FA:</strong> <span class="two-factor-status <?= $user['two_factor_enabled'] ? 'on' : 'off' ?>"><?= $user['two_factor_enabled'] ? '✅ Включена' : '❌ Отключена' ?></span></p>
<?php endif; ?>
<p><strong>Последняя активность:</strong> <?= $user['last_activity'] ? date('d.m.Y H:i:s', strtotime($user['last_activity'])) : '—' ?></p>
</div>

<div class="profile-two-col">
<div class="profile-col-left">
<div class="section-divider" style="border-top: none; padding-top: 0; margin-top: 0;">
<h3>📝 Основная информация</h3>
</div>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="update_profile" value="1">
<div class="form-group">
<label>Фото (аватарка)</label>
<div class="avatar-preview" id="profile-avatar-preview">
<?php if ($user['avatar']): ?>
<img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Аватар">
<?php else: ?>
<span style="color:#999;">Аватар не загружен</span>
<?php endif; ?>
</div>
<input type="file" id="profile-avatar-file" accept=".jpg,.jpeg,.png" style="width:auto;">
<input type="hidden" id="profile-avatar-path" value="<?= htmlspecialchars($user['avatar'] ?? '') ?>">
<button type="button" class="btn btn-sm btn-primary" onclick="uploadProfileAvatar()">📤 Загрузить</button>
<div class="hint">Форматы: JPG, PNG. Макс. размер: <strong>2 МБ</strong></div>
</div>
<div class="form-group">
<label>ФИО</label>
<input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" placeholder="Введите ФИО">
<div class="hint">От 3 до 50 символов</div>
</div>
<div class="form-group">
<label>Должность</label>
<input type="text" name="position" value="<?= htmlspecialchars($user['position'] ?? '') ?>" placeholder="Введите должность">
<div class="hint">До 40 символов</div>
</div>
<div class="form-group">
<label>Основной email *</label>
<input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="example@mail.ru" required>
<div class="hint">Для 2FA и восстановления пароля</div>
</div>
<div class="form-group">
<label>Резервный email</label>
<input type="email" name="backup_email" value="<?= htmlspecialchars($user['backup_email'] ?? '') ?>" placeholder="backup@mail.ru">
<div class="hint">Для 2FA и восстановления пароля</div>
</div>
<div class="form-group">
<label>Телефон</label>
<input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+7 (999) 123-45-67">
<div class="hint">Необязательно</div>
</div>
<button type="submit" class="btn btn-primary">💾 Сохранить профиль</button>
</form>
</div>

<div class="profile-col-right">
<div class="section-divider" style="border-top: none; padding-top: 0; margin-top: 0;">
<h3>🔑 Смена пароля</h3>
</div>
<div class="password-section">
<div class="password-method-toggle">
<button type="button" class="method-btn active" id="method-current" onclick="switchPasswordMethod('current')">🔐 Знаю текущий пароль</button>
<button type="button" class="method-btn" id="method-code" onclick="switchPasswordMethod('code')">📧 Забыл пароль (код на email)</button>
</div>

<div class="password-method-content active" id="method-current-content">
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="change_password_with_current" value="1">
<div class="form-group">
<label>Текущий пароль</label>
<input type="password" name="current_password" placeholder="Введите текущий пароль" required>
</div>
<div class="form-group">
<label>Новый пароль</label>
<input type="password" name="new_password" placeholder="От 6 до 12 символов" required>
<div class="hint">Только латиница, цифры и знак подчёркивания</div>
</div>
<div class="form-group">
<label>Подтвердите пароль</label>
<input type="password" name="confirm_password" placeholder="Повторите пароль" required>
</div>
<button type="submit" class="btn btn-success">💾 Сменить пароль</button>
</form>
</div>

<div class="password-method-content" id="method-code-content">
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="change_password_with_code" value="1">
<div class="form-group">
<label>Новый пароль</label>
<input type="password" name="new_password_code" placeholder="От 6 до 12 символов" required>
<div class="hint">Только латиница, цифры и знак подчёркивания</div>
</div>
<div class="form-group">
<label>Подтвердите пароль</label>
<input type="password" name="confirm_password_code" placeholder="Повторите пароль" required>
</div>
<div class="form-group">
<label>Код подтверждения</label>
<div class="email-choice-buttons">
<button type="button" class="email-btn active" id="email-main-btn" onclick="selectEmail('main')">
<span class="label">📧 Основная</span>
<span class="address"><?= htmlspecialchars($user['email'] ?? 'не указана') ?></span>
</button>
<button type="button" class="email-btn" id="email-backup-btn" onclick="selectEmail('backup')" <?= empty($user['backup_email']) ? 'disabled' : '' ?>>
<span class="label">📧 Резервная</span>
<span class="address"><?= htmlspecialchars($user['backup_email'] ?? 'не указана') ?></span>
</button>
</div>
<div class="code-row">
<input type="text" name="code" id="code-input" placeholder="Введите 6-значный код" maxlength="6">
<button type="button" class="btn btn-outline" id="send-code-btn" onclick="sendPasswordCode()">📩 Отправить код</button>
</div>
<div class="hint" id="code-hint">Выберите почту и нажмите «Отправить код»</div>
</div>
<button type="submit" class="btn btn-success">💾 Сменить пароль</button>
</form>
</div>
</div>
</div>
</div>
</div>
</div>

<script>
function switchPasswordMethod(method) {
    document.querySelectorAll('.method-btn').forEach(function(btn) { btn.classList.remove('active'); });
    document.querySelectorAll('.password-method-content').forEach(function(content) { content.classList.remove('active'); });
    if (method === 'current') {
        document.getElementById('method-current').classList.add('active');
        document.getElementById('method-current-content').classList.add('active');
    } else {
        document.getElementById('method-code').classList.add('active');
        document.getElementById('method-code-content').classList.add('active');
    }
}

var selectedEmail = 'main';
function selectEmail(type) {
    selectedEmail = type;
    document.querySelectorAll('.email-btn').forEach(function(btn) { btn.classList.remove('active'); });
    var btn = document.getElementById(type === 'main' ? 'email-main-btn' : 'email-backup-btn');
    if (btn) btn.classList.add('active');
    var email = type === 'main'
        ? '<?= htmlspecialchars($user['email'] ?? 'не указана') ?>'
        : '<?= htmlspecialchars($user['backup_email'] ?? 'не указана') ?>';
    document.getElementById('code-hint').textContent = 'Код будет отправлен на: ' + email;
}

function uploadProfileAvatar() {
    var fileInput = document.getElementById('profile-avatar-file');
    var file = fileInput.files[0];
    if (!file) { alert('Выберите файл'); return; }
    if (file.name.length > 40) { alert('Название файла не должно превышать 40 символов'); return; }
    var maxSize = 2 * 1024 * 1024;
    if (file.size > maxSize) { alert('Размер файла не должен превышать 2 МБ'); return; }
    
    var formData = new FormData();
    formData.append('action', 'upload_avatar');
    formData.append('avatar', file);
    
    fetch('profile.php', { method: 'POST', body: formData })
    .then(res => res.text())
    .then(data => {
        if (data.startsWith('/uploads/')) {
            var preview = document.getElementById('profile-avatar-preview');
            preview.innerHTML = '<img src="' + data + '" alt="Аватар">';
            document.getElementById('profile-avatar-path').value = data;
            alert('✅ Аватарка загружена!');
        } else {
            alert('❌ Ошибка: ' + data);
        }
    })
    .catch(err => alert('❌ Ошибка сети: ' + err));
}

function sendPasswordCode() {
    var btn = document.getElementById('send-code-btn');
    btn.disabled = true;
    btn.textContent = '⏳ Отправка...';
    var sendTo = selectedEmail;
    
    // Получаем CSRF-токен
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    
    fetch('profile.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=send_code&send_to=' + sendTo + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.text())
    .then(data => {
        if (data === 'ok') {
            document.getElementById('code-hint').innerHTML = '✅ Код отправлен на почту. Проверьте входящие.';
            alert('✅ Код отправлен на почту! Проверьте входящие.');
        } else {
            alert('❌ Ошибка: ' + data);
            document.getElementById('code-hint').textContent = '❌ ' + data;
        }
    })
    .catch(err => alert('❌ Ошибка сети: ' + err))
    .finally(function() {
        btn.disabled = false;
        btn.textContent = '📩 Отправить код';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    selectEmail('main');
});
</script>
</body>
</html>