<?php
// ==================================================
// ФАЙЛ: cron/cleanup_trash.php
// НАЗНАЧЕНИЕ: Автоматическая очистка корзины (пользователи и компании старше 15 дней)
// ЗАПУСК: Через cron раз в сутки
// ==================================================

// Подключаем конфиг
require_once __DIR__ . '/../config.php';

echo "[" . date('Y-m-d H:i:s') . "] Начало очистки корзины...\n";

// ==========================================================
// 1. УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЕЙ ИЗ КОРЗИНЫ (старше 15 дней)
// ==========================================================
$stmt = $mysqli->prepare("SELECT id FROM users WHERE deleted = 1 AND deleted_at < NOW() - INTERVAL 15 DAY");
$stmt->execute();
$result = $stmt->get_result();
$deleted_users = 0;

while ($row = $result->fetch_assoc()) {
    $uid = (int)$row['id'];
    
    // Удаляем связи с компаниями
    $mysqli->query("DELETE FROM access WHERE user_id = $uid");
    
    // Удаляем доступ к разделам
    $mysqli->query("DELETE FROM user_section_access WHERE user_id = $uid");
    
    // Удаляем избранное
    $mysqli->query("DELETE FROM favorites WHERE user_id = $uid");
    
    // Удаляем пользователя
    $mysqli->query("DELETE FROM users WHERE id = $uid");
    
    $deleted_users++;
}
$stmt->close();

// ==========================================================
// 2. УДАЛЕНИЕ КОМПАНИЙ ИЗ КОРЗИНЫ (старше 15 дней)
// ==========================================================
$stmt = $mysqli->prepare("SELECT id FROM companies WHERE deleted = 1 AND deleted_at < NOW() - INTERVAL 15 DAY");
$stmt->execute();
$result = $stmt->get_result();
$deleted_companies = 0;

while ($row = $result->fetch_assoc()) {
    $cid = (int)$row['id'];
    
    // Удаляем файлы компании (физические файлы)
    $files_stmt = $mysqli->prepare("SELECT filepath FROM files WHERE company_id = ?");
    $files_stmt->bind_param("i", $cid);
    $files_stmt->execute();
    $files_result = $files_stmt->get_result();
    
    while ($file = $files_result->fetch_assoc()) {
        $filepath = __DIR__ . '/../' . $file['filepath'];
        if (file_exists($filepath)) {
            @unlink($filepath);
        }
    }
    $files_stmt->close();
    
    // Удаляем записи о файлах из БД
    $mysqli->query("DELETE FROM files WHERE company_id = $cid");
    
    // Удаляем разделы компании
    $mysqli->query("DELETE FROM sections WHERE company_id = $cid");
    
    // Удаляем доступ к компании
    $mysqli->query("DELETE FROM access WHERE company_id = $cid");
    
    // Удаляем компанию
    $mysqli->query("DELETE FROM companies WHERE id = $cid");
    
    $deleted_companies++;
}
$stmt->close();

// ==========================================================
// 3. УДАЛЕНИЕ СТАРЫХ ЗАПИСЕЙ login_attempts (старше 30 дней)
// ==========================================================
$mysqli->query("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY");

// ==========================================================
// 4. УДАЛЕНИЕ СТАРЫХ view_log (старше 90 дней)
// ==========================================================
$mysqli->query("DELETE FROM view_log WHERE viewed_at < NOW() - INTERVAL 90 DAY");

// ==========================================================
// 5. УДАЛЕНИЕ ИСТЁКШИХ verification_codes
// ==========================================================
$mysqli->query("DELETE FROM verification_codes WHERE expires_at < NOW()");

echo "Удалено пользователей: $deleted_users\n";
echo "Удалено компаний: $deleted_companies\n";
echo "[" . date('Y-m-d H:i:s') . "] Очистка завершена.\n";
?>