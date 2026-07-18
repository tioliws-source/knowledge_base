<?php
// ==================================================
// ФАЙЛ: admin_ajax.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

if (($_SESSION['role'] ?? '') != 'admin') {
    die('access_denied');
}

$action = $_POST['action'] ?? '';

// ==========================================================
// ПЕРЕКЛЮЧЕНИЕ ВИДИМОСТИ РАЗДЕЛА КОМПАНИЙ
// ==========================================================
if ($action == 'toggle_section') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    $state = $_POST['state'] ?? '';
    if ($state == 'show') {
        $_SESSION['company_section_hidden'] = false;
    } elseif ($state == 'hide') {
        $_SESSION['company_section_hidden'] = true;
    }
    echo 'ok';
    exit;
}

// ==========================================================
// ПЕРЕКЛЮЧЕНИЕ ДОСТУПА КОМПАНИИ ДЛЯ РОЛИ
// ==========================================================
if ($action == 'toggle_role') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    $company_id = (int)($_POST['company_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    $state = (int)($_POST['state'] ?? 0);
    
    if (!$company_id || !$role) {
        echo 'invalid_params';
        exit;
    }
    
    // Используем НОВУЮ логику — поля в таблице companies
    if ($role == 'guest') {
        $stmt = $mysqli->prepare("UPDATE companies SET is_public = ? WHERE id = ?");
        $stmt->bind_param("ii", $state, $company_id);
        $stmt->execute();
        $stmt->close();
    } elseif ($role == 'editor') {
        $stmt = $mysqli->prepare("UPDATE companies SET allow_editors = ? WHERE id = ?");
        $stmt->bind_param("ii", $state, $company_id);
        $stmt->execute();
        $stmt->close();
    } elseif ($role == 'employee') {
        $stmt = $mysqli->prepare("UPDATE companies SET allow_employees = ? WHERE id = ?");
        $stmt->bind_param("ii", $state, $company_id);
        $stmt->execute();
        $stmt->close();
    } else {
        echo 'invalid_role';
        exit;
    }
    
    writeLog($_SESSION['user_id'], 'toggle_company_role', "Компания ID $company_id, роль $role, состояние $state");
    echo 'ok';
    exit;
}

// ==========================================================
// ВОССТАНОВЛЕНИЕ КОМПАНИИ
// ==========================================================
if ($action == 'restore_company') {
    // CSRF-проверка
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        echo 'csrf_error';
        exit;
    }
    
    $company_id = (int)($_POST['company_id'] ?? 0);
    if ($company_id) {
        $stmt = $mysqli->prepare("UPDATE companies SET deleted = 0, deleted_at = NULL WHERE id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $stmt->close();
        writeLog($_SESSION['user_id'], 'restore_company', "Восстановлена компания ID $company_id");
        echo 'ok';
    } else {
        echo 'invalid_id';
    }
    exit;
}

echo 'no_action';
?>