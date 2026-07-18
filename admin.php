<?php
// ==================================================
// ФАЙЛ: admin.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Админ-панель
// ==================================================
require_once 'config.php';

$show_search = true;
include 'header.php';

// Разрешаем доступ админу и редактору (для редактора будут видны только файлы)
if (!in_array($_SESSION['role'] ?? '', ['admin', 'editor'])) {
    die('Доступ запрещён');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Админ-панель</title>
<link rel="stylesheet" href="style.css">
<link rel="icon" href="favicon.ico" type="image/x-icon">
<style>
.admin-container { max-width: 100%; margin: 0 auto; padding: 20px; box-sizing: border-box; }
.admin-card { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.admin-card h2 { margin-top: 0; color: #1a2a4a; }
.admin-card .btn { margin-top: 10px; }
.admin-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 768px) { .admin-grid { grid-template-columns: 1fr; } }
.message { padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; background: #d4edda; color: #155724; }
.message.error { background: #f8d7da; color: #721c24; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 8px; text-align: left; border-bottom: 1px solid #eee; }
th { background: #f0f2f5; }
</style>
</head>
<body>
<div class="container">
<div class="admin-container">
<!-- ===== РАЗДЕЛ ПОЛЬЗОВАТЕЛЕЙ (только админ) ===== -->
<?php if ($_SESSION['role'] == 'admin'): ?>
<div id="users">
<?php include 'admin_users.php'; ?>
</div>
<?php endif; ?>

<!-- ===== РАЗДЕЛ КОМПАНИЙ (только админ) ===== -->
<?php if ($_SESSION['role'] == 'admin'): ?>
<div id="companies">
<?php include 'admin_company.php'; ?>
</div>
<?php endif; ?>

<!-- ===== УПРАВЛЕНИЕ ФАЙЛАМИ (админ + редактор) ===== -->
<?php if (in_array($_SESSION['role'], ['admin', 'editor'])): ?>
<div class="admin-card" style="background:#f8f9fa;border:1px solid #007bff;">
<h2>📁 Управление файлами</h2>
<p>Просмотр всех загруженных файлов, удаление.</p>
<a href="admin_files.php" class="btn btn-primary">Перейти →</a>
</div>
<?php endif; ?>

<!-- ===== УПРАВЛЕНИЕ БЭКАПАМИ (только админ) ===== -->
<?php if ($_SESSION['role'] == 'admin'): ?>
<div class="admin-card" style="background:#f8f9fa;border:1px solid #002fffff;">
<h2>💾 Управление бэкапами</h2>
<p>Создание бэкапа, скачивание, удаление архивов.</p>
<a href="admin_backups.php" class="btn btn-success">Перейти →</a>
</div>

<!-- ===== ЖУРНАЛ ДЕЙСТВИЙ (только админ) ===== -->
<div class="admin-card" style="background:#f8f9fa;border:1px solid #6c757d;">
<h2>📋 Журнал действий</h2>
<p>Просмотр всех действий пользователей в системе.</p>
<a href="logs.php" class="btn btn-outline">Перейти →</a>
</div>
<?php endif; ?>
</div>
</div>
</body>
</html>