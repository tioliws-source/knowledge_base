<?php
// ==================================================
// ФАЙЛ: admin_users.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// ИСПРАВЛЕНИЯ:
//   1. Проблема 27: Пароль 000000 → случайный 6-значный
//   2. Проблема 29: mkdir с 0777 → 0755
//   3. Проблема 16: rand() → random_int()
// ==================================================
require_once 'config.php';

// Только админ
if (($_SESSION['role'] ?? '') != 'admin') {
    die('Доступ запрещён');
}

// ==========================================================
// CSRF-ПРОВЕРКА ДЛЯ ВСЕХ POST-ЗАПРОСОВ (кроме AJAX-чтений)
// ==========================================================
$csrf_exempt_actions = ['get_user_data', 'get_section_access', 'get_online_users', 'get_users_by_role', 'toggle_users_section'];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !in_array($_POST['action'] ?? '', $csrf_exempt_actions)) {
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Ошибка безопасности. Обновите страницу.']);
        exit;
    }
}

// ==========================================================
// ОБРАБОТКА AJAX-ЗАПРОСА ДЛЯ СОХРАНЕНИЯ СОСТОЯНИЯ СКРЫТИЯ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'toggle_users_section') {
    $state = $_POST['state'] ?? '';
    if ($state == 'show') {
        $_SESSION['users_section_hidden'] = false;
    } elseif ($state == 'hide') {
        $_SESSION['users_section_hidden'] = true;
    }
    echo 'ok';
    exit;
}

// ==========================================================
// МАССОВОЕ УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЕЙ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'mass_delete') {
    $user_ids = isset($_POST['user_ids']) ? explode(',', $_POST['user_ids']) : [];
    if (empty($user_ids)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Не выбраны пользователи']);
        exit;
    }
    $ids = array_map('intval', $user_ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $mysqli->prepare("UPDATE users SET deleted = 1, deleted_at = NOW() WHERE id IN ($placeholders) AND role != 'admin'");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $stmt->close();
    foreach ($ids as $uid) {
        writeLog($_SESSION['user_id'], 'mass_delete_user', "Массовое удаление пользователя ID $uid");
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Выбранные пользователи удалены']);
    exit;
}

// ==========================================================
// МАССОВОЕ ВОССТАНОВЛЕНИЕ ПОЛЬЗОВАТЕЛЕЙ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'mass_restore') {
    $user_ids = isset($_POST['user_ids']) ? explode(',', $_POST['user_ids']) : [];
    if (empty($user_ids)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Не выбраны пользователи']);
        exit;
    }
    $ids = array_map('intval', $user_ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $mysqli->prepare("UPDATE users SET deleted = 0, deleted_at = NULL WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $stmt->close();
    foreach ($ids as $uid) {
        writeLog($_SESSION['user_id'], 'mass_restore_user', "Массовое восстановление пользователя ID $uid");
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Выбранные пользователи восстановлены']);
    exit;
}

// ==========================================================
// СОХРАНЕНИЕ ДОСТУПА К РАЗДЕЛАМ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'save_section_access') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $section_ids_raw = $_POST['section_ids'] ?? '';
    $section_ids = !empty($section_ids_raw) ? explode(',', $section_ids_raw) : [];
    if (!$user_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Ошибка: пользователь не найден']);
        exit;
    }
    $stmt = $mysqli->prepare("DELETE FROM user_section_access WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    foreach ($section_ids as $section_id) {
        $section_id = (int)$section_id;
        $stmt_check = $mysqli->prepare("SELECT id FROM sections WHERE id = ?");
        $stmt_check->bind_param("i", $section_id);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            $stmt = $mysqli->prepare("INSERT INTO user_section_access (user_id, section_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $section_id);
            $stmt->execute();
            $stmt->close();
        }
        $stmt_check->close();
    }
    writeLog($_SESSION['user_id'], 'edit_section_access', "Изменён доступ к разделам для пользователя ID $user_id");
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Доступ к разделам сохранён']);
    exit;
}

// ==========================================================
// ЗАГРУЗКА ДОСТУПА К РАЗДЕЛАМ ДЛЯ ПОЛЬЗОВАТЕЛЯ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'get_section_access') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if (!$user_id) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Пользователь не найден']);
        exit;
    }
    $stmt = $mysqli->prepare("SELECT section_id FROM user_section_access WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $allowed_sections = [];
    while ($row = $result->fetch_assoc()) {
        $allowed_sections[] = $row['section_id'];
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($allowed_sections);
    exit;
}

// ==========================================================
// ДОБАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'add_user') {
    $login = trim($_POST['login'] ?? '');
    $password_raw = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $role = $_POST['role'] ?? 'employee';
    $companies_raw = $_POST['companies'] ?? '';
    $companies = !empty($companies_raw) ? explode(',', $companies_raw) : [];
    $avatar = $_POST['avatar'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $backup_email = trim($_POST['backup_email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $two_factor_enabled = (int)($_POST['two_factor_enabled'] ?? 0);
    
    $errors = [];
    if (empty($login)) {
        $errors[] = 'Логин обязателен для заполнения';
    } elseif (strlen($login) < 6 || strlen($login) > 12) {
        $errors[] = 'Логин должен быть от 6 до 12 символов';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
        $errors[] = 'Логин может содержать только латинские буквы, цифры и _';
    } else {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE login = ?");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = 'Логин уже занят';
        }
        $stmt->close();
    }
    if (empty($password_raw)) {
        $errors[] = 'Пароль обязателен для заполнения';
    } elseif (strlen($password_raw) < 6 || strlen($password_raw) > 12) {
        $errors[] = 'Пароль должен быть от 6 до 12 символов';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $password_raw)) {
        $errors[] = 'Пароль может содержать только латинские буквы, цифры и _';
    }
    if (empty($companies)) {
        $errors[] = 'Необходимо выбрать хотя бы одну компанию';
    }
    if (!empty($full_name) && (mb_strlen($full_name) < 3 || mb_strlen($full_name) > 50)) {
        $errors[] = 'ФИО должно быть от 3 до 50 символов';
    }
    if (!empty($position) && mb_strlen($position) > 40) {
        $errors[] = 'Должность должна быть не более 40 символов';
    }
    if (!empty($avatar)) {
        $filename = basename($avatar);
        if (strlen($filename) > 40) {
            $errors[] = 'Название файла аватарки не должно превышать 40 символов';
        }
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Некорректный email';
    }
    if (!empty($backup_email) && !filter_var($backup_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Некорректный резервный email';
    }
    if (!empty($errors)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => implode('. ', $errors)]);
        exit;
    }
    
    $password = password_hash($password_raw, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("INSERT INTO users (login, password, full_name, position, role, avatar, email, backup_email, phone, two_factor_enabled, deleted, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)");
    $stmt->bind_param("sssssssssi", $login, $password, $full_name, $position, $role, $avatar, $email, $backup_email, $phone, $two_factor_enabled);
    $stmt->execute();
    $user_id = $stmt->insert_id;
    $stmt->close();
    
    if (!empty($companies)) {
        $action_type = ($role == 'editor') ? 'write' : 'read';
        foreach ($companies as $company_id) {
            $company_id = (int)$company_id;
            $stmt = $mysqli->prepare("INSERT INTO access (user_id, company_id, action) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $user_id, $company_id, $action_type);
            $stmt->execute();
            $stmt->close();
        }
    }
    writeLog($_SESSION['user_id'], 'add_user', "Создан пользователь $login (ID: $user_id)");
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Пользователь успешно создан!', 'user_id' => $user_id]);
    exit;
}

// ==========================================================
// РЕДАКТИРОВАНИЕ ПОЛЬЗОВАТЕЛЯ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'edit_user') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $restore = isset($_POST['restore']) ? (int)$_POST['restore'] : 0;
    if (!$user_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Ошибка: пользователь не найден']);
        exit;
    }
    if ($restore == 1) {
        $stmt = $mysqli->prepare("UPDATE users SET deleted = 0, deleted_at = NULL WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        writeLog($_SESSION['user_id'], 'restore_user', "Восстановлен пользователь ID $user_id");
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Пользователь восстановлен!']);
        exit;
    }
    
    $login = trim($_POST['login'] ?? '');
    $password_raw = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $role = $_POST['role'] ?? 'employee';
    $companies_raw = $_POST['companies'] ?? '';
    $companies = !empty($companies_raw) ? explode(',', $companies_raw) : [];
    $avatar = $_POST['avatar'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $backup_email = trim($_POST['backup_email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $two_factor_enabled = (int)($_POST['two_factor_enabled'] ?? 0);
    
    $errors = [];
    if (empty($login)) {
        $errors[] = 'Логин обязателен для заполнения';
    } elseif (strlen($login) < 6 || strlen($login) > 12) {
        $errors[] = 'Логин должен быть от 6 до 12 символов';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
        $errors[] = 'Логин может содержать только латинские буквы, цифры и _';
    } else {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE login = ? AND id != ?");
        $stmt->bind_param("si", $login, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = 'Логин уже занят';
        }
        $stmt->close();
    }
    if (empty($companies)) {
        $errors[] = 'Необходимо выбрать хотя бы одну компанию';
    }
    if (!empty($full_name) && (mb_strlen($full_name) < 3 || mb_strlen($full_name) > 50)) {
        $errors[] = 'ФИО должно быть от 3 до 50 символов';
    }
    if (!empty($position) && mb_strlen($position) > 40) {
        $errors[] = 'Должность должна быть не более 40 символов';
    }
    if (!empty($avatar)) {
        $filename = basename($avatar);
        if (strlen($filename) > 40) {
            $errors[] = 'Название файла аватарки не должно превышать 40 символов';
        }
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Некорректный email';
    }
    if (!empty($backup_email) && !filter_var($backup_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Некорректный резервный email';
    }
    if (!empty($password_raw)) {
        if (strlen($password_raw) < 6 || strlen($password_raw) > 12) {
            $errors[] = 'Пароль должен быть от 6 до 12 символов';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $password_raw)) {
            $errors[] = 'Пароль может содержать только латинские буквы, цифры и _';
        }
    }
    if (!empty($errors)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => implode('. ', $errors)]);
        exit;
    }
    
    if (!empty($password_raw)) {
        $password = password_hash($password_raw, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("UPDATE users SET login = ?, password = ?, full_name = ?, position = ?, role = ?, avatar = ?, email = ?, backup_email = ?, phone = ?, two_factor_enabled = ? WHERE id = ?");
        $stmt->bind_param("sssssssssii", $login, $password, $full_name, $position, $role, $avatar, $email, $backup_email, $phone, $two_factor_enabled, $user_id);
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET login = ?, full_name = ?, position = ?, role = ?, avatar = ?, email = ?, backup_email = ?, phone = ?, two_factor_enabled = ? WHERE id = ?");
        $stmt->bind_param("ssssssssii", $login, $full_name, $position, $role, $avatar, $email, $backup_email, $phone, $two_factor_enabled, $user_id);
    }
    $stmt->execute();
    $stmt->close();
    
    $stmt = $mysqli->prepare("DELETE FROM access WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    
    if (!empty($companies)) {
        $action_type = ($role == 'editor') ? 'write' : 'read';
        foreach ($companies as $company_id) {
            $company_id = (int)$company_id;
            $stmt = $mysqli->prepare("INSERT INTO access (user_id, company_id, action) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $user_id, $company_id, $action_type);
            $stmt->execute();
            $stmt->close();
        }
    }
    writeLog($_SESSION['user_id'], 'edit_user', "Отредактирован пользователь $login (ID: $user_id)");
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Изменения успешно сохранены!']);
    exit;
}

// ==========================================================
// УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЯ (В КОРЗИНУ)
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'delete_user') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if (!$user_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Ошибка: пользователь не найден']);
        exit;
    }
    $stmt = $mysqli->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $role = $result->fetch_assoc()['role'];
        if ($role == 'admin') {
            $stmt->close();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Администратора нельзя удалить']);
            exit;
        }
    }
    $stmt->close();
    $stmt = $mysqli->prepare("UPDATE users SET deleted = 1, deleted_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    writeLog($_SESSION['user_id'], 'delete_user', "Удалён пользователь ID $user_id");
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Пользователь удалён (будет стёрт через 15 дней)']);
    exit;
}

// ==========================================================
// ✅ ИСПРАВЛЕНО (проблема 27): СБРОС ПАРОЛЯ — теперь случайный
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'reset_password') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if (!$user_id) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Ошибка: пользователь не найден']);
        exit;
    }
    $stmt = $mysqli->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $role = $result->fetch_assoc()['role'];
        if ($role == 'admin') {
            $stmt->close();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Нельзя сбросить пароль администратору']);
            exit;
        }
    }
    $stmt->close();
    
    // ✅ ИСПРАВЛЕНО: генерируем случайный 6-значный пароль (только цифры)
    // вместо жёстко заданного '000000'
    $new_password = (string)random_int(100000, 999999);
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hashed_password, $user_id);
    $stmt->execute();
    $stmt->close();
    
    writeLog($_SESSION['user_id'], 'reset_password', "Сброшен пароль для пользователя ID $user_id");
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Пароль сброшен на: ' . $new_password]);
    exit;
}

// ==========================================================
// ЗАГРУЗКА ДАННЫХ ПОЛЬЗОВАТЕЛЯ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'get_user_data') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if (!$user_id) {
        die('Ошибка: пользователь не найден');
    }
    $stmt = $mysqli->prepare("
        SELECT u.*, GROUP_CONCAT(a.company_id) as company_ids
        FROM users u
        LEFT JOIN access a ON a.user_id = u.id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        die('Ошибка: пользователь не найден');
    }
    header('Content-Type: application/json');
    echo json_encode($user);
    exit;
}

// ==========================================================
// ✅ ИСПРАВЛЕНО (проблема 29): ЗАГРУЗКА АВАТАРКИ — mkdir 0755
// ИСПРАВЛЕНО (проблема 16): rand() → random_int()
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'upload_avatar') {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
        $file = $_FILES['avatar'];
        $tmp_name = $file['tmp_name'];
        $original_name = $file['name'];
        if (strlen($original_name) > 40) {
            die('Название файла не должно превышать 40 символов');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_name);
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
            // ✅ ИСПРАВЛЕНО (проблема 29): права 0755 вместо 0777
            if (!mkdir($avatar_dir, 0755, true)) {
                die('Не удалось создать папку для аватарок');
            }
        }
        // ✅ ИСПРАВЛЕНО (проблема 16): random_int() вместо rand()
        $new_filename = 'avatar_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
        $target_path = $avatar_dir . $new_filename;
        if (move_uploaded_file($tmp_name, $target_path)) {
            $image = null;
            switch ($mime_type) {
                case 'image/jpeg': $image = imagecreatefromjpeg($target_path); break;
                case 'image/png': $image = imagecreatefrompng($target_path); break;
                case 'image/webp': $image = imagecreatefromwebp($target_path); break;
                case 'image/gif': $image = imagecreatefromgif($target_path); break;
            }
            if ($image) {
                $width = imagesx($image);
                $height = imagesy($image);
                $max_size = 200;
                $ratio = min($max_size / $width, $max_size / $height);
                if ($ratio < 1) {
                    $new_width = (int)($width * $ratio);
                    $new_height = (int)($height * $ratio);
                    $resized = imagecreatetruecolor($new_width, $new_height);
                    imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                    imagedestroy($image);
                    $image = $resized;
                }
                if ($mime_type == 'image/png') {
                    imagepng($image, $target_path, 8);
                } elseif ($mime_type == 'image/webp') {
                    imagewebp($image, $target_path, 80);
                } elseif ($mime_type == 'image/gif') {
                    imagegif($image, $target_path);
                } else {
                    imagejpeg($image, $target_path, 85);
                }
                imagedestroy($image);
            }
            echo '/uploads/avatars/' . $new_filename;
        } else {
            echo 'Ошибка загрузки файла';
        }
    } else {
        echo 'Файл не выбран или ошибка при загрузке';
    }
    exit;
}

// ==========================================================
// ОБНОВЛЕНИЕ СТАТУСА "В СЕТИ"
// ==========================================================
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $mysqli->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->close();
}

// ==========================================================
// ОБРАБОТКА ЗАПРОСА "В СЕТИ"
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'get_online_users') {
    $online_users = $mysqli->query("
        SELECT u.id, u.login, u.full_name, u.role,
        GROUP_CONCAT(DISTINCT c.name SEPARATOR ' / ') as companies
        FROM users u
        LEFT JOIN access a ON a.user_id = u.id
        LEFT JOIN companies c ON c.id = a.company_id
        WHERE u.role IN ('admin', 'editor', 'employee')
        AND u.last_activity > NOW() - INTERVAL 5 MINUTE
        GROUP BY u.id
        ORDER BY u.full_name
    ");
    $guests_today = $mysqli->query("
        SELECT COUNT(DISTINCT visitor_ip) as cnt
        FROM visitors
        WHERE visit_date = CURDATE()
    ")->fetch_assoc()['cnt'];
    
    $html = '<div style="max-height:400px;overflow-y:auto;">';
    $html .= '<h3>👥 В сети сейчас</h3>';
    $html .= '<p><strong>Всего активных пользователей:</strong> ' . $online_users->num_rows . '</p>';
    if ($online_users->num_rows > 0) {
        $html .= '<table style="width:100%;border-collapse:collapse;margin-top:10px;">';
        $html .= '<tr style="background:#f0f2f5;"><th>ID</th><th>Логин</th><th>ФИО</th><th>Роль</th><th>Компания</th></tr>';
        while ($user = $online_users->fetch_assoc()) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($user['id']) . '</td>';
            $html .= '<td>' . htmlspecialchars($user['login']) . '</td>';
            $html .= '<td>' . htmlspecialchars($user['full_name'] ?? '—') . '</td>';
            $html .= '<td>' . htmlspecialchars($user['role']) . '</td>';
            $html .= '<td>' . htmlspecialchars($user['companies'] ?? '—') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    } else {
        $html .= '<p style="color:#999;">Сейчас нет активных пользователей</p>';
    }
    $html .= '<hr style="margin:15px 0;">';
    $html .= '<p><strong>👋 Посетители (гости) за сегодня:</strong> ' . $guests_today . '</p>';
    $html .= '</div>';
    echo $html;
    exit;
}

// ==========================================================
// ПОЛУЧИТЬ СПИСОК ПОЛЬЗОВАТЕЛЕЙ ПО РОЛИ
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'get_users_by_role') {
    $company_id = (int)($_POST['company_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    if (!$company_id || !$role) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Неверные параметры']);
        exit;
    }
    $stmt = $mysqli->prepare("
        SELECT u.id, u.login, u.full_name, u.role
        FROM users u
        JOIN access a ON a.user_id = u.id
        WHERE a.company_id = ? AND u.role = ?
        ORDER BY u.login
    ");
    $stmt->bind_param("is", $company_id, $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $users_list = [];
    while ($user = $result->fetch_assoc()) {
        $users_list[] = $user;
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($users_list);
    exit;
}

// ==========================================================
// ОСНОВНОЙ ВЫВОД
// ==========================================================
$filter_type = $_GET['filter_type'] ?? 'all';
$filter_value = $_GET['filter_value'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = (int)($_GET['page'] ?? 1);
$per_page = 10;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$where_conditions[] = "u.role != 'admin'";
if (!empty($search)) {
    $search_escaped = $mysqli->real_escape_string($search);
    $where_conditions[] = "(u.login LIKE '%$search_escaped%' OR u.full_name LIKE '%$search_escaped%' OR u.id LIKE '%$search_escaped%')";
}
if ($filter_type == 'deleted') {
    $where_conditions[] = "u.deleted = 1";
} else {
    $where_conditions[] = "u.deleted = 0";
}
if ($filter_type == 'company' && $filter_value) {
    $company_id = (int)$filter_value;
    $where_conditions[] = "a.company_id = $company_id";
} elseif ($filter_type == 'role' && $filter_value) {
    $role_map = ['Редактор' => 'editor', 'Сотрудник' => 'employee'];
    $role_value = $role_map[$filter_value] ?? $filter_value;
    $where_conditions[] = "u.role = '$role_value'";
}
$where_sql = implode(' AND ', $where_conditions);

$count_query = $mysqli->query("
    SELECT COUNT(DISTINCT u.id) as total
    FROM users u
    LEFT JOIN access a ON a.user_id = u.id
    LEFT JOIN companies c ON c.id = a.company_id
    WHERE $where_sql
");
$total_users = $count_query->fetch_assoc()['total'];
$total_pages = ceil($total_users / $per_page);

$users_query = $mysqli->query("
    SELECT u.id, u.login, u.role, u.full_name, u.position, u.last_activity, u.deleted, u.deleted_at, u.email, u.backup_email, u.phone, u.two_factor_enabled,
    GROUP_CONCAT(c.name SEPARATOR ' / ') as companies
    FROM users u
    LEFT JOIN access a ON a.user_id = u.id
    LEFT JOIN companies c ON c.id = a.company_id
    WHERE $where_sql
    GROUP BY u.id
    ORDER BY u.id
    LIMIT $offset, $per_page
");

$all_companies_editor = $mysqli->query("
    SELECT DISTINCT c.id, c.name
    FROM companies c
    JOIN access a ON a.company_id = c.id
    WHERE c.deleted = 0 AND a.action = 'write'
    ORDER BY c.name
");
$all_companies_employee = $mysqli->query("
    SELECT DISTINCT c.id, c.name
    FROM companies c
    JOIN access a ON a.company_id = c.id
    WHERE c.deleted = 0 AND a.action = 'read'
    ORDER BY c.name
");
$all_companies_all = $mysqli->query("SELECT id, name FROM companies WHERE deleted = 0 ORDER BY name");
$all_sections = $mysqli->query("SELECT id, parent_id, title, company_id FROM sections ORDER BY company_id, parent_id, title");
$sections_by_company = [];
while ($section = $all_sections->fetch_assoc()) {
    $company_id = $section['company_id'];
    if (!isset($sections_by_company[$company_id])) {
        $sections_by_company[$company_id] = [];
    }
    $sections_by_company[$company_id][] = $section;
}

$is_hidden = $_SESSION['users_section_hidden'] ?? true;
$toggle_label = $is_hidden ? 'Раскрыть' : 'Скрыть';
$toggle_icon = $is_hidden ? '▶' : '▼';

function buildSectionTree($sections, $parent_id = 0) {
    $result = [];
    foreach ($sections as $section) {
        if ($section['parent_id'] == $parent_id) {
            $children = buildSectionTree($sections, $section['id']);
            $section['children'] = $children;
            $result[] = $section;
        }
    }
    return $result;
}
?>
<style>
.users-section { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); width: 100%; min-width: 100%; max-width: 100%; overflow-x: auto; box-sizing: border-box; }
.users-section-content { width: 100%; min-width: 100%; max-width: 100%; overflow-x: auto; box-sizing: border-box; }
.users-table { width: 100%; min-width: 1200px; max-width: 100%; table-layout: auto; border-collapse: collapse; box-sizing: border-box; }
.users-table th, .users-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; word-wrap: break-word; overflow-wrap: break-word; vertical-align: middle; white-space: nowrap; }
.users-table th { background: #f8f9fa; font-weight: 600; color: #1a2a4a; }
.users-table tr:hover { background: #f8f9fa; }
.users-section-header { display: flex; justify-content: space-between; align-items: center; user-select: none; }
.users-section-header h2 { margin: 0; color: #1a2a4a; font-size: 22px; }
.users-section-header .actions { display: flex; gap: 10px; }
.users-section-content.hidden { display: none; }
.btn-edit { background: #007bff; color: white; border: none; padding: 5px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; }
.btn-edit:hover { background: #0056b3; }
.btn-add { background: #28a745; color: white; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-size: 14px; }
.btn-add:hover { background: #1e7e34; }
.btn-filter { background: #f8f9fa; border: 1px solid #ccc; padding: 5px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; }
.btn-filter:hover { background: #e9ecef; }
.btn-filter.active { background: #007bff; color: white; border-color: #007bff; }
.btn-mass { background: #6c757d; color: white; border: none; padding: 5px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; }
.btn-mass:hover { background: #5a6268; }
.btn-mass-danger { background: #dc3545; color: white; border: none; padding: 5px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; }
.btn-mass-danger:hover { background: #bd2130; }
.btn-mass-success { background: #28a745; color: white; border: none; padding: 5px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; }
.btn-mass-success:hover { background: #1e7e34; }
.status-online { color: #28a745; font-weight: 600; }
.status-offline { color: #dc3545; font-weight: 600; }
.status-deleted { color: #dc3545; font-weight: 600; }
.role-badge { padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
.role-badge.editor { background: #cce5ff; color: #004085; }
.role-badge.employee { background: #d4edda; color: #155724; }
.filter-bar { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
.search-bar { display: flex; gap: 10px; margin-bottom: 15px; margin-top: 10px; align-items: center; width: 100%; }
.search-bar input[type="text"] { flex: 1; padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; min-width: 200px; }
.mass-actions { display: flex; gap: 10px; margin: 15px 0; flex-wrap: wrap; align-items: center; }
.user-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 99999; display: flex; justify-content: center; align-items: center; }
.user-modal-box { background: #fff; border-radius: 12px; padding: 30px; max-width: 1100px; width: 95%; max-height: 90vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
.user-modal-box h3 { margin-top: 0; }
.user-modal-box .buttons { margin-top: 20px; display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap; }
.user-modal-box .form-group { margin-bottom: 15px; }
.user-modal-box .form-group label { display: block; font-weight: 600; margin-bottom: 4px; }
.user-modal-box .form-group input, .user-modal-box .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
.user-modal-box .form-group input[type="file"] { padding: 6px; }
.user-modal-box .form-group .multi-select { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; min-height: 100px; background: #fff; }
.user-modal-box .form-group small { display: block; margin-top: 4px; color: #666; font-size: 12px; }
.user-modal-box .form-group .hint { color: #6c757d; font-size: 12px; margin-top: 4px; }
.user-modal-box .section-tree-container { border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; background: #f8f9fa; max-height: 300px; overflow-y: auto; margin-top: 10px; }
.user-modal-box .section-tree-container ul { list-style: none; padding-left: 20px; margin: 0; }
.user-modal-box .section-tree-container li { padding: 4px 0; }
.user-modal-box .section-tree-container label { display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer; }
.user-modal-box .section-tree-container input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
.company-buttons { display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap; }
.company-buttons .company-btn { padding: 6px 16px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa; cursor: pointer; font-size: 13px; transition: all 0.2s; }
.company-buttons .company-btn:hover { background: #e9ecef; }
.company-buttons .company-btn.active { background: #007bff; color: #fff; border-color: #007bff; }
.company-tree { display: none; }
.company-tree.active { display: block; }
.custom-confirm-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.3); z-index: 999999; display: flex; justify-content: center; align-items: center; }
.custom-confirm-box { background: #fff; border-radius: 12px; padding: 30px 40px; max-width: 450px; width: 90%; box-shadow: 0 8px 32px rgba(0,0,0,0.2); text-align: center; }
.custom-confirm-box h3 { margin: 0 0 10px 0; color: #1a2a4a; font-size: 18px; }
.custom-confirm-box p { margin: 0 0 20px 0; color: #555; font-size: 15px; }
.custom-confirm-box .buttons { display: flex; gap: 12px; justify-content: center; }
.custom-confirm-box .buttons .btn { min-width: 100px; padding: 10px 20px; font-size: 15px; }
.custom-confirm-box .buttons .btn-danger { background: #dc3545; color: white; }
.custom-confirm-box .buttons .btn-success { background: #28a745; color: white; }
.custom-confirm-box .buttons .btn:disabled { opacity: 0.5; cursor: not-allowed; }
.notification { position: fixed; top: 30px; left: 50%; transform: translateX(-50%) translateY(-20px); padding: 16px 32px; border-radius: 10px; color: #fff; font-weight: 500; font-size: 15px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); z-index: 999999; opacity: 0; transition: all 0.4s ease; pointer-events: none; max-width: 500px; width: auto; text-align: center; border-left: 5px solid rgba(255,255,255,0.3); }
.notification.show { opacity: 1; transform: translateX(-50%) translateY(0); pointer-events: auto; }
.notification.success { background: #28a745; border-left-color: #1e7e34; }
.notification.error { background: #dc3545; border-left-color: #bd2130; }
.notification.info { background: #007bff; border-left-color: #0056b3; }
.notification.warning { background: #ffc107; border-left-color: #e0a800; color: #333; }
.pagination { display: flex; gap: 8px; justify-content: center; margin-top: 20px; flex-wrap: wrap; }
.pagination .page-link { padding: 6px 14px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #007bff; font-size: 14px; transition: all 0.2s; }
.pagination .page-link:hover { background: #007bff; color: #fff; border-color: #007bff; }
.pagination .page-link.active { background: #007bff; color: #fff; border-color: #007bff; }
.pagination .page-link.disabled { color: #999; pointer-events: none; }
.checkbox-col { width: 30px; text-align: center; }
.checkbox-2fa { display: flex; align-items: center; gap: 10px; }
.checkbox-2fa input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; flex-shrink: 0; margin: 0; }
.checkbox-2fa label { margin: 0; cursor: pointer; font-weight: 500; font-size: 14px; color: #333; }
</style>
<div class="users-section">
    <div class="users-section-header">
        <h2>👥 Пользователи</h2>
        <div class="actions">
            <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); showOnlineUsers()">🟢 В сети</button>
            <button class="btn-add" onclick="event.stopPropagation(); showAddUserModal()">➕ Добавить</button>
            <button class="btn btn-sm btn-outline" id="toggle-users-btn" onclick="event.stopPropagation(); toggleUsersSection()">
                <?= $toggle_icon ?> <?= $toggle_label ?>
            </button>
        </div>
    </div>
    <div class="users-section-content <?= $is_hidden ? 'hidden' : '' ?>" id="users-section-content">
        <div class="search-bar">
            <input type="text" id="search-input" placeholder="🔍 Поиск по логину, ФИО или ID..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary" onclick="applySearch()">Найти</button>
            <?php if (!empty($search)): ?>
                <button class="btn btn-outline" onclick="clearSearch()">✕ Сбросить</button>
            <?php endif; ?>
        </div>
        <div class="filter-bar">
            <button class="btn-filter <?= $filter_type == 'all' ? 'active' : '' ?>" onclick="applyFilter('all')">Все</button>
            <button class="btn-filter <?= $filter_type == 'company' ? 'active' : '' ?>" onclick="openCompanyFilter()">🏢 Компания</button>
            <button class="btn-filter <?= $filter_type == 'role' ? 'active' : '' ?>" onclick="openRoleFilter()">🎯 Роль</button>
            <button class="btn-filter <?= $filter_type == 'deleted' ? 'active' : '' ?>" onclick="applyFilter('deleted')">🗑️ Удалены</button>
            <?php if ($filter_type != 'all' && $filter_type != 'deleted'): ?>
                <span style="font-size:13px;color:#666;margin-left:10px;">
                    Фильтр: <strong><?= htmlspecialchars($filter_value) ?></strong>
                    <a href="?filter_type=all" style="color:#dc3545;text-decoration:none;">✕</a>
                </span>
            <?php endif; ?>
        </div>
        <div class="mass-actions">
            <button class="btn-mass-success" onclick="massAction('restore')" <?= $filter_type != 'deleted' ? 'style="display:none;"' : '' ?>>↩️ Восстановить выбранных</button>
            <button class="btn-mass-danger" onclick="massAction('delete')">🗑️ Удалить выбранных</button>
            <span style="font-size:13px;color:#666;margin-left:10px;">
                <span id="selected-count">0</span> выбрано
            </span>
        </div>
        <table class="users-table">
            <thead>
                <tr>
                    <th class="checkbox-col"><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes()"></th>
                    <th>ID</th>
                    <th>Логин</th>
                    <th>Компания</th>
                    <th>Роль</th>
                    <th>ФИО</th>
                    <th>Должность</th>
                    <th>Статус</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users_query && $users_query->num_rows > 0): ?>
                    <?php while ($user = $users_query->fetch_assoc()):
                        $is_online = strtotime($user['last_activity']) > strtotime('-5 minutes');
                        if ($user['deleted'] == 1) {
                            $status_class = 'status-deleted';
                            $status_text = '🗑️ Удалён (' . date('d.m.Y', strtotime($user['deleted_at'])) . ')';
                        } else {
                            $status_class = $is_online ? 'status-online' : 'status-offline';
                            $status_text = $is_online ? '🟢 В сети' : '🔴 Не в сети';
                        }
                        $role_name = getRoleName($user['role']);
                        $role_class = $user['role'] == 'editor' ? 'editor' : 'employee';
                    ?>
                        <tr>
                            <td class="checkbox-col"><input type="checkbox" class="user-checkbox" value="<?= (int)$user['id'] ?>" onchange="updateSelectedCount()"></td>
                            <td><?= htmlspecialchars($user['id']) ?></td>
                            <td><?= htmlspecialchars($user['login']) ?></td>
                            <td><?= htmlspecialchars($user['companies'] ?? '—') ?></td>
                            <td><span class="role-badge <?= $role_class ?>"><?= htmlspecialchars($role_name) ?></span></td>
                            <td><?= htmlspecialchars($user['full_name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($user['position'] ?? '—') ?></td>
                            <td class="<?= $status_class ?>"><?= $status_text ?></td>
                            <td>
                                <?php if ($filter_type == 'deleted'): ?>
                                    <button class="btn-edit" onclick="editUser(<?= (int)$user['id'] ?>)" style="background:#28a745; border-color:#28a745;">↩️ Восстановить</button>
                                <?php else: ?>
                                    <button class="btn-edit" onclick="editUser(<?= (int)$user['id'] ?>)">✏️ Редактировать</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align:center; color:#999; padding:30px 0;">
                            Нет пользователей, соответствующих фильтру
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?filter_type=<?= urlencode($filter_type) ?>&filter_value=<?= urlencode($filter_value) ?>&search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>" class="page-link">←</a>
                <?php else: ?>
                    <span class="page-link disabled">←</span>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?filter_type=<?= urlencode($filter_type) ?>&filter_value=<?= urlencode($filter_value) ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?filter_type=<?= urlencode($filter_type) ?>&filter_value=<?= urlencode($filter_value) ?>&search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>" class="page-link">→</a>
                <?php else: ?>
                    <span class="page-link disabled">→</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== МОДАЛЬНОЕ ОКНО ДОБАВЛЕНИЯ ===== -->
<div id="add-user-modal" class="user-modal-overlay" style="display:none;">
    <div class="user-modal-box">
        <h3>➕ Добавить пользователя</h3>
        <form id="add-user-form">
            <div class="form-group">
                <label>Фото (аватарка)</label>
                <input type="file" id="avatar-file" accept=".jpg,.jpeg,.png">
                <input type="hidden" id="avatar-path" value="">
                <div id="avatar-preview" style="margin-top:8px;"></div>
                <button type="button" class="btn btn-sm btn-primary" onclick="uploadAvatar('add')">Загрузить</button>
                <div class="hint">Форматы: JPG, PNG. Максимальный размер: <strong>2 МБ</strong>. Имя файла до 40 символов</div>
            </div>
            <div class="form-group">
                <label>Логин *</label>
                <input type="text" id="add-user-login" required>
                <div class="hint">От 6 до 12 символов, только латиница, цифры и знак подчёркивания</div>
            </div>
            <div class="form-group">
                <label>Пароль *</label>
                <input type="text" id="add-user-password" required>
                <div class="hint">От 6 до 12 символов, только латиница, цифры и знак подчёркивания</div>
            </div>
            <div class="form-group">
                <label>Роль *</label>
                <select id="add-user-role" onchange="updateCompanyList('add')">
                    <option value="editor">Редактор</option>
                    <option value="employee" selected>Сотрудник</option>
                </select>
            </div>
            <div class="form-group">
                <label>ФИО</label>
                <input type="text" id="add-user-fullname">
                <div class="hint">От 3 до 50 символов</div>
            </div>
            <div class="form-group">
                <label>Должность</label>
                <input type="text" id="add-user-position">
                <div class="hint">До 40 символов</div>
            </div>
            <div class="form-group">
                <label>Email (основной)</label>
                <input type="email" id="add-user-email">
                <div class="hint">Для 2FA и восстановления пароля</div>
            </div>
            <div class="form-group">
                <label>Резервный email</label>
                <input type="email" id="add-user-backup-email">
                <div class="hint">Для 2FA и восстановления пароля</div>
            </div>
            <div class="form-group">
                <label>Телефон</label>
                <input type="text" id="add-user-phone">
                <div class="hint">Необязательно</div>
            </div>
            <div class="form-group checkbox-2fa">
                <input type="checkbox" id="add-user-2fa" value="1">
                <label for="add-user-2fa">Включить двухфакторную аутентификацию по почте</label>
            </div>
            <div class="form-group">
                <label>Доступ к компаниям *</label>
                <select id="add-user-companies" class="multi-select" multiple></select>
                <small>Зажмите Ctrl (Cmd на Mac) для выбора нескольких компаний</small>
            </div>
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer;" onclick="toggleSectionAccess()">
                    <span id="add-section-access-arrow">▶</span> 🌳 Доступ к разделам
                </label>
                <div id="add-section-access-container" style="display:none; margin-top:10px;">
                    <div style="color:#999; font-size:13px; padding:10px 0;">
                        Выберите компанию, чтобы настроить доступ к разделам.
                    </div>
                </div>
            </div>
            <div class="buttons">
                <button type="button" class="btn btn-outline" onclick="closeAddUserModal()">Отмена</button>
                <button type="button" class="btn btn-success" id="add-user-submit">💾 Создать</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== МОДАЛЬНОЕ ОКНО РЕДАКТИРОВАНИЯ ===== -->
<div id="edit-user-modal" class="user-modal-overlay" style="display:none;">
    <div class="user-modal-box">
        <h3 id="edit-user-title">✏️ Редактировать пользователя</h3>
        <form id="edit-user-form">
            <input type="hidden" id="edit-user-id" value="">
            <div class="form-group">
                <label>Фото (аватарка)</label>
                <input type="file" id="edit-avatar-file" accept=".jpg,.jpeg,.png">
                <input type="hidden" id="edit-avatar-path" value="">
                <div id="edit-avatar-preview" style="margin-top:8px;"></div>
                <button type="button" class="btn btn-sm btn-primary" onclick="uploadAvatar('edit')">Загрузить</button>
                <div class="hint">Форматы: JPG, PNG. Максимальный размер: <strong>2 МБ</strong>. Имя файла до 40 символов</div>
            </div>
            <div class="form-group">
                <label>Логин *</label>
                <input type="text" id="edit-user-login" required>
                <div class="hint">От 6 до 12 символов, только латиница, цифры и знак подчёркивания</div>
            </div>
            <div class="form-group">
                <label>Новый пароль (оставьте пустым, чтобы не менять)</label>
                <input type="text" id="edit-user-password">
                <div class="hint">От 6 до 12 символов, только латиница, цифры и знак подчёркивания</div>
                <!-- ✅ ИСПРАВЛЕНО (проблема 27): убран жёсткий пароль 000000 -->
                <button type="button" class="btn btn-warning" onclick="resetUserPassword()" style="margin-top:5px; background:#ffc107; color:#333; border:none; padding:5px 14px; border-radius:4px; cursor:pointer;">🔑 Сбросить на случайный</button>
            </div>
            <div class="form-group">
                <label>Роль</label>
                <select id="edit-user-role" onchange="updateCompanyList('edit')">
                    <option value="editor">Редактор</option>
                    <option value="employee">Сотрудник</option>
                </select>
            </div>
            <div class="form-group">
                <label>ФИО</label>
                <input type="text" id="edit-user-fullname">
                <div class="hint">От 3 до 50 символов</div>
            </div>
            <div class="form-group">
                <label>Должность</label>
                <input type="text" id="edit-user-position">
                <div class="hint">До 40 символов</div>
            </div>
            <div class="form-group">
                <label>Email (основной)</label>
                <input type="email" id="edit-user-email">
                <div class="hint">Для 2FA и восстановления пароля</div>
            </div>
            <div class="form-group">
                <label>Резервный email</label>
                <input type="email" id="edit-user-backup-email">
                <div class="hint">Для 2FA и восстановления пароля</div>
            </div>
            <div class="form-group">
                <label>Телефон</label>
                <input type="text" id="edit-user-phone">
                <div class="hint">Необязательно</div>
            </div>
            <div class="form-group checkbox-2fa">
                <input type="checkbox" id="edit-user-2fa" value="1">
                <label for="edit-user-2fa">Включить двухфакторную аутентификацию по почте</label>
            </div>
            <div class="form-group">
                <label>Доступ к компаниям *</label>
                <select id="edit-user-companies" class="multi-select" multiple></select>
                <small>Зажмите Ctrl (Cmd на Mac) для выбора нескольких компаний</small>
            </div>
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer;" onclick="toggleEditSectionAccess()">
                    <span id="edit-section-access-arrow">▶</span> 🌳 Доступ к разделам
                </label>
                <div id="edit-section-access-container" style="display:none; margin-top:10px;">
                    <div style="color:#999; font-size:13px; padding:10px 0;">
                        Выберите компанию, чтобы настроить доступ к разделам.
                    </div>
                </div>
            </div>
            <div class="buttons">
                <button type="button" class="btn btn-outline" onclick="closeEditUserModal()">Отмена</button>
                <button type="button" class="btn btn-success" id="edit-user-submit">💾 Сохранить</button>
                <button type="button" class="btn btn-danger" id="edit-user-delete">🗑️ Удалить</button>
            </div>
        </form>
    </div>
</div>

<script>
function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

var companiesForEditor = <?php
$list = [];
while ($c = $all_companies_editor->fetch_assoc()) {
    $list[] = ['id' => $c['id'], 'name' => $c['name']];
}
echo json_encode($list);
?>;

var companiesForEmployee = <?php
$list = [];
while ($c = $all_companies_employee->fetch_assoc()) {
    $list[] = ['id' => $c['id'], 'name' => $c['name']];
}
echo json_encode($list);
?>;

var allCompanies = <?php
$list = [];
$all_companies_all->data_seek(0);
while ($c = $all_companies_all->fetch_assoc()) {
    $list[] = ['id' => $c['id'], 'name' => $c['name']];
}
echo json_encode($list);
?>;

function updateCompanyList(mode) {
    var selectId = mode === 'add' ? 'add-user-companies' : 'edit-user-companies';
    var roleSelectId = mode === 'add' ? 'add-user-role' : 'edit-user-role';
    var select = document.getElementById(selectId);
    var roleSelect = document.getElementById(roleSelectId);
    if (!select || !roleSelect) return;
    var role = roleSelect.value;
    var companies = role === 'editor' ? companiesForEditor : companiesForEmployee;
    var selectedValues = [];
    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].selected) {
            selectedValues.push(select.options[i].value);
        }
    }
    select.innerHTML = '';
    companies.forEach(function(c) {
        var option = document.createElement('option');
        option.value = c.id;
        option.textContent = c.name;
        if (selectedValues.includes(String(c.id))) {
            option.selected = true;
        }
        select.appendChild(option);
    });
    if (select.options.length > 0 && selectedValues.length === 0) {
        select.options[0].selected = true;
    }
}

function showNotification(message, type) {
    type = type || 'success';
    var duration = type === 'error' ? 5000 : 3000;
    var notification = document.createElement('div');
    notification.className = 'notification ' + type;
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(function() { notification.classList.add('show'); }, 20);
    setTimeout(function() {
        notification.classList.remove('show');
        setTimeout(function() { notification.remove(); }, 400);
    }, duration);
}

function customConfirmRestore(message, callback) {
    var old = document.querySelector('.custom-confirm-overlay');
    if (old) old.remove();
    var overlay = document.createElement('div');
    overlay.className = 'custom-confirm-overlay';
    var box = document.createElement('div');
    box.className = 'custom-confirm-box';
    box.innerHTML = `
        <h3>⚠️ Подтверждение</h3>
        <p>${escapeHtml(message)}</p>
        <div class="buttons">
            <button class="btn btn-danger" id="confirm-no" style="background:#dc3545; color:white;">❌ Нет</button>
            <button class="btn btn-success" id="confirm-yes" style="background:#28a745; color:white;">✅ Да</button>
        </div>
    `;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    document.getElementById('confirm-yes').addEventListener('click', function() {
        overlay.remove();
        callback(true);
    });
    document.getElementById('confirm-no').addEventListener('click', function() {
        overlay.remove();
        callback(false);
    });
}

function customConfirm(message, callback) {
    var old = document.querySelector('.custom-confirm-overlay');
    if (old) old.remove();
    var overlay = document.createElement('div');
    overlay.className = 'custom-confirm-overlay';
    var box = document.createElement('div');
    box.className = 'custom-confirm-box';
    box.innerHTML = `
        <h3>⚠️ Подтверждение</h3>
        <p>${escapeHtml(message)}</p>
        <div class="buttons">
            <button class="btn btn-success" id="confirm-no">❌ Нет</button>
            <button class="btn btn-danger" id="confirm-yes" disabled>🗑️ Да</button>
        </div>
        <div style="margin-top:10px;font-size:14px;color:#666;" id="confirm-timer">Подождите 15 секунд...</div>
    `;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    var seconds = 15;
    var timer = setInterval(function() {
        seconds--;
        var timerEl = document.getElementById('confirm-timer');
        var yesBtn = document.getElementById('confirm-yes');
        if (timerEl) timerEl.textContent = 'Осталось ' + seconds + ' секунд...';
        if (seconds <= 0) {
            clearInterval(timer);
            if (timerEl) timerEl.textContent = '✅ Подтверждение доступно';
            if (yesBtn) yesBtn.disabled = false;
        }
    }, 1000);
    document.getElementById('confirm-yes').addEventListener('click', function() {
        overlay.remove();
        clearInterval(timer);
        callback(true);
    });
    document.getElementById('confirm-no').addEventListener('click', function() {
        overlay.remove();
        clearInterval(timer);
        callback(false);
    });
}

function toggleUsersSection() {
    var content = document.getElementById('users-section-content');
    var btn = document.getElementById('toggle-users-btn');
    var isHidden = content.classList.contains('hidden');
    if (isHidden) {
        content.classList.remove('hidden');
        btn.innerHTML = '▼ Скрыть';
    } else {
        content.classList.add('hidden');
        btn.innerHTML = '▶ Раскрыть';
    }
    fetch('admin_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=toggle_users_section&state=' + (isHidden ? 'show' : 'hide') + '&csrf_token=' + encodeURIComponent(csrfToken)
    });
}

function applyFilter(type, value) {
    var url = window.location.pathname + '?filter_type=' + type;
    if (value) url += '&filter_value=' + encodeURIComponent(value);
    if (document.getElementById('search-input')) {
        var search = document.getElementById('search-input').value.trim();
        if (search) url += '&search=' + encodeURIComponent(search);
    }
    window.location.href = url;
}

function openCompanyFilter() {
    var companies = allCompanies;
    var old = document.querySelector('.user-modal-overlay.company-filter-overlay');
    if (old) old.remove();
    var overlay = document.createElement('div');
    overlay.className = 'user-modal-overlay company-filter-overlay';
    overlay.style.position = 'fixed';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.width = '100%';
    overlay.style.height = '100%';
    overlay.style.background = 'rgba(0,0,0,0.4)';
    overlay.style.zIndex = '99999';
    overlay.style.display = 'flex';
    overlay.style.justifyContent = 'center';
    overlay.style.alignItems = 'center';
    overlay.addEventListener('click', function(e) { e.stopPropagation(); });
    var box = document.createElement('div');
    box.className = 'user-modal-box';
    box.style.background = '#fff';
    box.style.borderRadius = '12px';
    box.style.padding = '30px';
    box.style.maxWidth = '600px';
    box.style.width = '95%';
    box.style.maxHeight = '80vh';
    box.style.overflowY = 'auto';
    box.style.boxShadow = '0 8px 32px rgba(0,0,0,0.2)';
    box.style.position = 'relative';
    var html = '<h3>🏢 Выберите компанию</h3>';
    html += '<ul style="list-style:none;padding:0;">';
    if (companies.length > 0) {
        companies.forEach(function(c) {
            html += '<li style="padding:8px 0;border-bottom:1px solid #eee;">';
            html += '<a href="#" onclick="applyFilter(\'company\', ' + c.id + ')" style="color:#007bff;text-decoration:none;">';
            html += escapeHtml(c.name);
            html += '</a>';
            html += '</li>';
        });
    } else {
        html += '<li style="padding:8px 0;color:#999;">Нет доступных компаний</li>';
    }
    html += '</ul>';
    html += '<div class="buttons" style="margin-top:20px;display:flex;gap:12px;justify-content:flex-end;border-top:1px solid #eee;padding-top:15px;">';
    html += '<button class="btn btn-outline" onclick="this.closest(\'.user-modal-overlay\').remove()">Закрыть</button>';
    html += '</div>';
    box.innerHTML = html;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
}

function openRoleFilter() {
    var old = document.querySelector('.user-modal-overlay.role-filter-overlay');
    if (old) old.remove();
    var overlay = document.createElement('div');
    overlay.className = 'user-modal-overlay role-filter-overlay';
    overlay.style.position = 'fixed';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.width = '100%';
    overlay.style.height = '100%';
    overlay.style.background = 'rgba(0,0,0,0.4)';
    overlay.style.zIndex = '99999';
    overlay.style.display = 'flex';
    overlay.style.justifyContent = 'center';
    overlay.style.alignItems = 'center';
    overlay.addEventListener('click', function(e) { e.stopPropagation(); });
    var box = document.createElement('div');
    box.className = 'user-modal-box';
    box.style.background = '#fff';
    box.style.borderRadius = '12px';
    box.style.padding = '30px';
    box.style.maxWidth = '600px';
    box.style.width = '95%';
    box.style.maxHeight = '80vh';
    box.style.overflowY = 'auto';
    box.style.boxShadow = '0 8px 32px rgba(0,0,0,0.2)';
    box.style.position = 'relative';
    var html = '<h3>🎯 Выберите роль</h3>';
    html += '<ul style="list-style:none;padding:0;">';
    html += '<li style="padding:8px 0;border-bottom:1px solid #eee;"><a href="#" onclick="applyFilter(\'role\', \'Редактор\')" style="color:#007bff;text-decoration:none;">Редактор</a></li>';
    html += '<li style="padding:8px 0;"><a href="#" onclick="applyFilter(\'role\', \'Сотрудник\')" style="color:#007bff;text-decoration:none;">Сотрудник</a></li>';
    html += '</ul>';
    html += '<div class="buttons" style="margin-top:20px;display:flex;gap:12px;justify-content:flex-end;border-top:1px solid #eee;padding-top:15px;">';
    html += '<button class="btn btn-outline" onclick="this.closest(\'.user-modal-overlay\').remove()">Закрыть</button>';
    html += '</div>';
    box.innerHTML = html;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
}

function applySearch() {
    var search = document.getElementById('search-input').value.trim();
    var url = window.location.pathname + '?filter_type=all';
    if (search) url += '&search=' + encodeURIComponent(search);
    window.location.href = url;
}

function clearSearch() {
    document.getElementById('search-input').value = '';
    applySearch();
}

document.getElementById('search-input')?.addEventListener('keyup', function(e) {
    if (e.key === 'Enter') { applySearch(); }
});

function getSelectedIds() {
    var checkboxes = document.querySelectorAll('.user-checkbox:checked');
    var ids = [];
    checkboxes.forEach(function(cb) { ids.push(cb.value); });
    return ids;
}

function updateSelectedCount() {
    var count = getSelectedIds().length;
    document.getElementById('selected-count').textContent = count;
}

function toggleAllCheckboxes() {
    var checked = document.getElementById('select-all').checked;
    document.querySelectorAll('.user-checkbox').forEach(function(cb) { cb.checked = checked; });
    updateSelectedCount();
}

function massAction(action) {
    var ids = getSelectedIds();
    if (ids.length === 0) {
        showNotification('Выберите хотя бы одного пользователя', 'warning');
        return;
    }
    var message = action === 'delete'
        ? 'Удалить выбранных пользователей (' + ids.length + ')? Они будут перемещены в корзину на 15 дней.'
        : 'Восстановить выбранных пользователей (' + ids.length + ')?';
    var confirmCallback = function(confirmed) {
        if (!confirmed) return;
        var formData = new FormData();
        formData.append('action', action === 'delete' ? 'mass_delete' : 'mass_restore');
        formData.append('user_ids', ids.join(','));
        formData.append('csrf_token', csrfToken);
        fetch('admin_users.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message, 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showNotification('Ошибка: ' + data.message, 'error');
            }
        })
        .catch(err => { showNotification('Ошибка сети: ' + err, 'error'); });
    };
    if (action === 'delete') {
        customConfirm(message, confirmCallback);
    } else {
        customConfirmRestore(message, confirmCallback);
    }
}

function showUsersList(companyId, role) {
    fetch('admin_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=get_users_by_role&company_id=' + companyId + '&role=' + role + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            showNotification('Ошибка: ' + data.error, 'error');
            return;
        }
        var roleName = role === 'editor' ? 'Редакторы' : 'Сотрудники';
        var html = '<div style="max-height:400px;overflow-y:auto;">';
        html += '<h3>' + escapeHtml(roleName) + ' компании (ID: ' + companyId + ')</h3>';
        if (data.length > 0) {
            html += '<table style="width:100%;border-collapse:collapse;margin-top:10px;">';
            html += '<tr style="background:#f0f2f5;"><th>ID</th><th>Логин</th><th>ФИО</th><th>Роль</th></tr>';
            data.forEach(function(user) {
                html += '<tr>';
                html += '<td>' + escapeHtml(user.id) + '</td>';
                html += '<td>' + escapeHtml(user.login) + '</td>';
                html += '<td>' + escapeHtml(user.full_name || '—') + '</td>';
                html += '<td>' + escapeHtml(user.role) + '</td>';
                html += '</tr>';
            });
            html += '</table>';
        } else {
            html += '<p style="color:#999;">Нет пользователей с этой ролью в компании</p>';
        }
        html += '</div>';
        var modal = document.createElement('div');
        modal.className = 'user-modal-overlay';
        modal.style.position = 'fixed';
        modal.style.top = '0';
        modal.style.left = '0';
        modal.style.width = '100%';
        modal.style.height = '100%';
        modal.style.background = 'rgba(0,0,0,0.4)';
        modal.style.zIndex = '99999';
        modal.style.display = 'flex';
        modal.style.justifyContent = 'center';
        modal.style.alignItems = 'center';
        modal.addEventListener('click', function(e) { e.stopPropagation(); });
        var box = document.createElement('div');
        box.className = 'user-modal-box';
        box.style.background = '#fff';
        box.style.borderRadius = '12px';
        box.style.padding = '30px';
        box.style.maxWidth = '700px';
        box.style.width = '95%';
        box.style.maxHeight = '80vh';
        box.style.overflowY = 'auto';
        box.style.boxShadow = '0 8px 32px rgba(0,0,0,0.2)';
        box.style.position = 'relative';
        box.innerHTML = html + `
            <div class="buttons" style="margin-top:20px;display:flex;gap:12px;justify-content:flex-end;border-top:1px solid #eee;padding-top:15px;">
                <button class="btn btn-outline" onclick="this.closest('.user-modal-overlay').remove()">Закрыть</button>
            </div>
        `;
        modal.appendChild(box);
        document.body.appendChild(modal);
    })
    .catch(err => { showNotification('Ошибка загрузки: ' + err, 'error'); });
}

var sectionAccessData = {};
var companySections = <?php
$data = [];
foreach ($sections_by_company as $company_id => $sections) {
    $company_name = $mysqli->query("SELECT name FROM companies WHERE id = " . (int)$company_id)->fetch_assoc()['name'];
    $data[$company_id] = [
        'name' => $company_name,
        'sections' => buildSectionTree($sections)
    ];
}
echo json_encode($data);
?>;

var currentUserCompanies = [];
var isSectionAccessVisible = false;

function toggleSectionAccess() {
    var container = document.getElementById('add-section-access-container');
    var arrow = document.getElementById('add-section-access-arrow');
    if (container.style.display === 'none') {
        container.style.display = 'block';
        arrow.textContent = '▼';
        loadSectionAccessForAdd();
    } else {
        container.style.display = 'none';
        arrow.textContent = '▶';
    }
}

function loadSectionAccessForAdd() {
    var select = document.getElementById('add-user-companies');
    var companyIds = [];
    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].selected) {
            companyIds.push(parseInt(select.options[i].value));
        }
    }
    currentUserCompanies = companyIds;
    sectionAccessData = {};
    renderSectionTree('add-section-access-container', true);
}

function toggleEditSectionAccess() {
    var container = document.getElementById('edit-section-access-container');
    var arrow = document.getElementById('edit-section-access-arrow');
    if (container.style.display === 'none') {
        container.style.display = 'block';
        arrow.textContent = '▼';
        var userId = document.getElementById('edit-user-id').value;
        if (userId) { loadEditSectionAccess(userId); }
    } else {
        container.style.display = 'none';
        arrow.textContent = '▶';
    }
}

function loadEditSectionAccess(userId) {
    fetch('admin_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=get_section_access&user_id=' + userId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        sectionAccessData = {};
        if (Array.isArray(data)) {
            data.forEach(function(id) { sectionAccessData[id] = true; });
        }
        var select = document.getElementById('edit-user-companies');
        var companyIds = [];
        for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].selected) {
                companyIds.push(parseInt(select.options[i].value));
            }
        }
        currentUserCompanies = companyIds;
        renderSectionTree('edit-section-access-container', false);
    })
    .catch(err => { showNotification('Ошибка загрузки доступа к разделам: ' + err, 'error'); });
}

function renderTreeHTML(tree) {
    var html = '<ul>';
    tree.forEach(function(node) {
        html += '<li>';
        var checked = sectionAccessData[node.id] ? 'checked' : '';
        html += '<label>';
        html += '<input type="checkbox" class="section-checkbox" value="' + node.id + '" ' + checked + '>';
        html += '📄 ' + escapeHtml(node.title);
        html += '</label>';
        if (node.children && node.children.length > 0) {
            html += renderTreeHTML(node.children);
        }
        html += '</li>';
    });
    html += '</ul>';
    return html;
}

function renderSectionTree(containerId, isAddMode) {
    var container = document.getElementById(containerId);
    if (!container) return;
    var html = '';
    var availableCompanies = currentUserCompanies.filter(function(id) {
        return companySections[id] && companySections[id].sections.length > 0;
    });
    if (availableCompanies.length === 0) {
        html = '<div style="color:#999; padding:10px 0;">Нет доступных компаний с разделами</div>';
        container.innerHTML = html;
        return;
    }
    html += '<div class="company-buttons">';
    availableCompanies.forEach(function(id, index) {
        var active = index === 0 ? 'active' : '';
        html += '<button class="company-btn ' + active + '" onclick="switchCompanyTree(\'' + containerId + '\', ' + id + ', this)">' + escapeHtml(companySections[id].name) + '</button>';
    });
    html += '</div>';
    availableCompanies.forEach(function(id, index) {
        var active = index === 0 ? 'active' : '';
        html += '<div class="company-tree ' + active + '" id="tree-' + containerId + '-' + id + '">';
        html += '<div class="section-tree-container">';
        html += renderTreeHTML(companySections[id].sections);
        html += '</div>';
        html += '</div>';
    });
    container.innerHTML = html;
}

function switchCompanyTree(containerId, companyId, btn) {
    document.querySelectorAll('#' + containerId + ' .company-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('#' + containerId + ' .company-tree').forEach(function(t) { t.classList.remove('active'); });
    var target = document.getElementById('tree-' + containerId + '-' + companyId);
    if (target) target.classList.add('active');
}

function saveSectionAccessCommon(userId) {
    var checkboxes = document.querySelectorAll('.section-checkbox:checked');
    var sectionIds = [];
    checkboxes.forEach(function(cb) { sectionIds.push(cb.value); });
    var formData = new FormData();
    formData.append('action', 'save_section_access');
    formData.append('user_id', userId);
    formData.append('section_ids', sectionIds.join(','));
    formData.append('csrf_token', csrfToken);
    fetch('admin_users.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showNotification('✅ ' + data.message, 'success');
        } else {
            showNotification('❌ Ошибка: ' + data.message, 'error');
        }
    })
    .catch(err => { showNotification('❌ Ошибка сети: ' + err, 'error'); });
}

// ✅ ИСПРАВЛЕНО (проблема 27): сообщение о случайном пароле
function resetUserPassword() {
    var userId = document.getElementById('edit-user-id').value;
    if (!userId) {
        showNotification('Ошибка: пользователь не найден', 'error');
        return;
    }
    customConfirmRestore('Сбросить пароль на случайный 6-значный для этого пользователя?', function(confirmed) {
        if (!confirmed) return;
        var formData = new FormData();
        formData.append('action', 'reset_password');
        formData.append('user_id', userId);
        formData.append('csrf_token', csrfToken);
        fetch('admin_users.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showNotification('✅ ' + data.message, 'success');
            } else {
                showNotification('❌ Ошибка: ' + data.message, 'error');
            }
        })
        .catch(err => { showNotification('❌ Ошибка сети: ' + err, 'error'); });
    });
}

function editUser(userId) {
    fetch('admin_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=get_user_data&user_id=' + userId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            showNotification('Ошибка: ' + data.error, 'error');
            return;
        }
        if (data.deleted == 1) {
            customConfirmRestore('Восстановить пользователя ' + escapeHtml(data.login) + '?', function(confirmed) {
                if (confirmed) { restoreUser(userId); }
            });
            return;
        }
        var titleEl = document.getElementById('edit-user-title');
        if (titleEl) {
            titleEl.textContent = '✏️ Редактировать пользователя: ' + data.login + ' (ID: ' + data.id + ')';
        }
        document.getElementById('edit-user-id').value = data.id;
        document.getElementById('edit-user-login').value = data.login;
        document.getElementById('edit-user-role').value = data.role;
        document.getElementById('edit-user-fullname').value = data.full_name || '';
        document.getElementById('edit-user-position').value = data.position || '';
        document.getElementById('edit-user-password').value = '';
        document.getElementById('edit-user-email').value = data.email || '';
        document.getElementById('edit-user-backup-email').value = data.backup_email || '';
        document.getElementById('edit-user-phone').value = data.phone || '';
        document.getElementById('edit-user-2fa').checked = data.two_factor_enabled == 1;
        var previewEl = document.getElementById('edit-avatar-preview');
        var pathEl = document.getElementById('edit-avatar-path');
        if (data.avatar) {
            if (previewEl) previewEl.innerHTML = '<img src="' + data.avatar + '" style="max-height:100px;max-width:100px;border-radius:50%;border:1px solid #ddd;padding:3px;">';
            if (pathEl) pathEl.value = data.avatar;
        } else {
            if (previewEl) previewEl.innerHTML = '';
            if (pathEl) pathEl.value = '';
        }
        var select = document.getElementById('edit-user-companies');
        if (select) {
            var companyIds = data.company_ids ? data.company_ids.split(',').map(Number) : [];
            updateCompanyList('edit');
            for (var i = 0; i < select.options.length; i++) {
                select.options[i].selected = companyIds.includes(parseInt(select.options[i].value));
            }
        }
        var deleteBtn = document.getElementById('edit-user-delete');
        if (deleteBtn) {
            deleteBtn.textContent = '🗑️ Удалить';
            deleteBtn.className = 'btn btn-danger';
            deleteBtn.onclick = function() { confirmDeleteUser(data.id); };
        }
        var modal = document.getElementById('edit-user-modal');
        if (modal) modal.style.display = 'flex';
        var arrow = document.getElementById('edit-section-access-arrow');
        if (arrow) arrow.textContent = '▶';
        var container = document.getElementById('edit-section-access-container');
        if (container) {
            container.style.display = 'none';
            container.innerHTML = '<div style="color:#999; font-size:13px; padding:10px 0;">Выберите компанию, чтобы настроить доступ к разделам.</div>';
        }
        loadEditSectionAccess(data.id);
    })
    .catch(err => { showNotification('Ошибка загрузки данных: ' + err, 'error'); });
}

function closeEditUserModal() {
    document.getElementById('edit-user-modal').style.display = 'none';
    document.getElementById('edit-user-form').reset();
}

function restoreUser(userId) {
    var formData = new FormData();
    formData.append('action', 'edit_user');
    formData.append('user_id', userId);
    formData.append('restore', 1);
    formData.append('csrf_token', csrfToken);
    fetch('admin_users.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(function() { location.reload(); }, 800);
        } else {
            showNotification('Ошибка: ' + data.message, 'error');
        }
    })
    .catch(err => { showNotification('Ошибка сети: ' + err, 'error'); });
}

function confirmDeleteUser(userId) {
    customConfirm('Вы уверены, что хотите удалить этого пользователя? Он будет перемещён в корзину на 15 дней.', function(confirmed) {
        if (confirmed) {
            var formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);
            fetch('admin_users.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeEditUserModal();
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    showNotification('Ошибка: ' + data.message, 'error');
                }
            })
            .catch(err => { showNotification('Ошибка сети: ' + err, 'error'); });
        }
    });
}

function uploadAvatar(mode) {
    var fileInput = mode === 'add' ? document.getElementById('avatar-file') : document.getElementById('edit-avatar-file');
    var file = fileInput.files[0];
    if (!file) {
        showNotification('Выберите файл', 'warning');
        return;
    }
    if (file.name.length > 40) {
        showNotification('Название файла не должно превышать 40 символов', 'error');
        return;
    }
    var maxSize = 2 * 1024 * 1024;
    if (file.size > maxSize) {
        showNotification('Размер файла не должен превышать 2 МБ', 'error');
        return;
    }
    var formData = new FormData();
    formData.append('action', 'upload_avatar');
    formData.append('avatar', file);
    formData.append('csrf_token', csrfToken);
    fetch('admin_users.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data.startsWith('/uploads/')) {
            var previewEl = mode === 'add' ? document.getElementById('avatar-preview') : document.getElementById('edit-avatar-preview');
            var pathEl = mode === 'add' ? document.getElementById('avatar-path') : document.getElementById('edit-avatar-path');
            previewEl.innerHTML = '<img src="' + data + '" style="max-height:100px;max-width:100px;border-radius:50%;border:1px solid #ddd;padding:3px;">';
            pathEl.value = data;
            showNotification('Аватарка загружена', 'success');
        } else {
            showNotification('Ошибка: ' + data, 'error');
        }
    })
    .catch(err => { showNotification('Ошибка сети: ' + err, 'error'); });
}

function showAddUserModal() {
    document.getElementById('add-user-modal').style.display = 'flex';
    updateCompanyList('add');
}

function closeAddUserModal() {
    document.getElementById('add-user-modal').style.display = 'none';
    document.getElementById('add-user-form').reset();
    document.getElementById('avatar-path').value = '';
    document.getElementById('avatar-preview').innerHTML = '';
    var arrow = document.getElementById('add-section-access-arrow');
    if (arrow) arrow.textContent = '▶';
    var container = document.getElementById('add-section-access-container');
    if (container) {
        container.style.display = 'none';
        container.innerHTML = '<div style="color:#999; font-size:13px; padding:10px 0;">Выберите компанию, чтобы настроить доступ к разделам.</div>';
    }
}

document.getElementById('add-user-companies')?.addEventListener('change', function() {
    var container = document.getElementById('add-section-access-container');
    if (container && container.style.display === 'block') { loadSectionAccessForAdd(); }
});

document.getElementById('edit-user-companies')?.addEventListener('change', function() {
    var container = document.getElementById('edit-section-access-container');
    if (container && container.style.display === 'block') {
        var userId = document.getElementById('edit-user-id').value;
        if (userId) { loadEditSectionAccess(userId); }
    }
});

document.getElementById('add-user-submit')?.addEventListener('click', function() {
    var login = document.getElementById('add-user-login').value.trim();
    var password = document.getElementById('add-user-password').value.trim();
    var role = document.getElementById('add-user-role').value;
    var full_name = document.getElementById('add-user-fullname').value.trim();
    var position = document.getElementById('add-user-position').value.trim();
    var avatar = document.getElementById('avatar-path').value;
    var email = document.getElementById('add-user-email').value.trim();
    var backup_email = document.getElementById('add-user-backup-email').value.trim();
    var phone = document.getElementById('add-user-phone').value.trim();
    var two_factor_enabled = document.getElementById('add-user-2fa').checked ? 1 : 0;
    var companies = [];
    var select = document.getElementById('add-user-companies');
    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].selected) { companies.push(select.options[i].value); }
    }
    var formData = new FormData();
    formData.append('action', 'add_user');
    formData.append('login', login);
    formData.append('password', password);
    formData.append('role', role);
    formData.append('full_name', full_name);
    formData.append('position', position);
    formData.append('avatar', avatar);
    formData.append('email', email);
    formData.append('backup_email', backup_email);
    formData.append('phone', phone);
    formData.append('two_factor_enabled', two_factor_enabled);
    formData.append('companies', companies.join(','));
    formData.append('csrf_token', csrfToken);
    fetch('admin_users.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            if (data.user_id) {
                var sectionContainer = document.getElementById('add-section-access-container');
                if (sectionContainer && sectionContainer.style.display === 'block') {
                    var checkboxes = document.querySelectorAll('#add-section-access-container .section-checkbox:checked');
                    if (checkboxes.length > 0) {
                        var sectionIds = [];
                        checkboxes.forEach(function(cb) { sectionIds.push(cb.value); });
                        var formData2 = new FormData();
                        formData2.append('action', 'save_section_access');
                        formData2.append('user_id', data.user_id);
                        formData2.append('section_ids', sectionIds.join(','));
                        formData2.append('csrf_token', csrfToken);
                        fetch('admin_users.php', {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': csrfToken },
                            body: formData2
                        });
                    }
                }
            }
            closeAddUserModal();
            setTimeout(function() { location.reload(); }, 800);
        } else {
            showNotification('Ошибка: ' + data.message, 'error');
        }
    })
    .catch(err => { showNotification('Ошибка сети: ' + err, 'error'); });
});

document.getElementById('edit-user-submit')?.addEventListener('click', function() {
    var userId = document.getElementById('edit-user-id').value;
    var login = document.getElementById('edit-user-login').value.trim();
    var password = document.getElementById('edit-user-password').value.trim();
    var role = document.getElementById('edit-user-role').value;
    var full_name = document.getElementById('edit-user-fullname').value.trim();
    var position = document.getElementById('edit-user-position').value.trim();
    var avatar = document.getElementById('edit-avatar-path').value;
    var email = document.getElementById('edit-user-email').value.trim();
    var backup_email = document.getElementById('edit-user-backup-email').value.trim();
    var phone = document.getElementById('edit-user-phone').value.trim();
    var two_factor_enabled = document.getElementById('edit-user-2fa').checked ? 1 : 0;
    var companies = [];
    var select = document.getElementById('edit-user-companies');
    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].selected) { companies.push(select.options[i].value); }
    }
    var formData = new FormData();
    formData.append('action', 'edit_user');
    formData.append('user_id', userId);
    formData.append('login', login);
    formData.append('password', password);
    formData.append('role', role);
    formData.append('full_name', full_name);
    formData.append('position', position);
    formData.append('avatar', avatar);
    formData.append('email', email);
    formData.append('backup_email', backup_email);
    formData.append('phone', phone);
    formData.append('two_factor_enabled', two_factor_enabled);
    formData.append('companies', companies.join(','));
    formData.append('csrf_token', csrfToken);
    fetch('admin_users.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            closeEditUserModal();
            setTimeout(function() { location.reload(); }, 800);
        } else {
            showNotification('Ошибка: ' + data.message, 'error');
        }
    })
    .catch(err => { showNotification('Ошибка сети: ' + err, 'error'); });
});

function showOnlineUsers() {
    fetch('admin_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'action=get_online_users&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.text())
    .then(html => {
        var modal = document.createElement('div');
        modal.className = 'user-modal-overlay';
        modal.style.position = 'fixed';
        modal.style.top = '0';
        modal.style.left = '0';
        modal.style.width = '100%';
        modal.style.height = '100%';
        modal.style.background = 'rgba(0,0,0,0.4)';
        modal.style.zIndex = '99999';
        modal.style.display = 'flex';
        modal.style.justifyContent = 'center';
        modal.style.alignItems = 'center';
        modal.addEventListener('click', function(e) { e.stopPropagation(); });
        var box = document.createElement('div');
        box.className = 'user-modal-box';
        box.style.background = '#fff';
        box.style.borderRadius = '12px';
        box.style.padding = '30px';
        box.style.maxWidth = '700px';
        box.style.width = '95%';
        box.style.maxHeight = '80vh';
        box.style.overflowY = 'auto';
        box.style.boxShadow = '0 8px 32px rgba(0,0,0,0.2)';
        box.style.position = 'relative';
        box.innerHTML = html + `
            <div class="buttons" style="margin-top:20px;display:flex;gap:12px;justify-content:flex-end;border-top:1px solid #eee;padding-top:15px;">
                <button class="btn btn-outline" onclick="this.closest('.user-modal-overlay').remove()">Закрыть</button>
            </div>
        `;
        modal.appendChild(box);
        document.body.appendChild(modal);
    })
    .catch(err => { showNotification('Ошибка загрузки: ' + err, 'error'); });
}
</script>