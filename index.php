<?php
// ==================================================
// ФАЙЛ: index.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

$show_search = true;
include 'header.php';

// Получаем список компаний с учётом прав доступа
$user_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? 'guest';
$is_admin = ($role == 'admin');

if ($is_admin) {
    $stmt = $mysqli->prepare("SELECT * FROM companies WHERE deleted = 0 ORDER BY name");
    $stmt->execute();
    $companies = $stmt->get_result();
    $stmt->close();
} else {
    if ($user_id > 0) {
        $stmt = $mysqli->prepare("
            SELECT DISTINCT c.*
            FROM companies c
            JOIN access a ON a.company_id = c.id
            WHERE a.user_id = ? AND c.deleted = 0
            ORDER BY c.name
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $companies = $stmt->get_result();
        $stmt->close();
    } else {
        $stmt = $mysqli->prepare("
            SELECT * FROM companies
            WHERE is_public = 1 AND deleted = 0
            ORDER BY name
        ");
        $stmt->execute();
        $companies = $stmt->get_result();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($site_name) ?> — Главная</title>
<link rel="stylesheet" href="style.css">
<link rel="icon" href="favicon.ico" type="image/x-icon">
<style>
.company-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 30px;
}
.company-card {
    position: relative;
    overflow: hidden;
    background: #ffffff;
    border-radius: 16px;
    padding: 40px 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    text-decoration: none;
    color: #333;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    border: 2px solid transparent;
    min-height: 280px;
    justify-content: center;
}
.company-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.15);
    border-color: #007bff;
}
.company-card .company-logo-bg {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.15;
    z-index: 0;
    border-radius: 16px;
}
.company-card .company-content {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
}
.company-card .company-icon {
    font-size: 56px;
    margin-bottom: 12px;
    z-index: 1;
}
.company-card .company-name {
    font-size: 24px;
    font-weight: 700;
    color: #1a2a4a;
    margin-bottom: 8px;
    z-index: 1;
    background: rgba(255,255,255,0.85);
    padding: 4px 16px;
    border-radius: 6px;
}
.company-card .company-desc {
    color: #555;
    font-size: 15px;
    margin-bottom: 14px;
    flex: 1;
    z-index: 1;
    background: rgba(255,255,255,0.8);
    padding: 4px 14px;
    border-radius: 6px;
    max-width: 90%;
}
.company-card .company-link {
    font-weight: 600;
    color: #007bff;
    z-index: 1;
    background: rgba(255,255,255,0.9);
    padding: 6px 18px;
    border-radius: 20px;
    font-size: 15px;
    transition: all 0.2s;
}
.company-card:hover .company-link {
    background: #007bff;
    color: #fff;
}
@media (max-width: 768px) {
    .company-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    .company-card {
        min-height: 220px;
        padding: 30px 20px;
    }
    .company-card .company-name {
        font-size: 20px;
    }
}
</style>
</head>
<body>
<div class="container">
<main class="main">
<h1 class="page-title" style="font-size:32px;">Выберите компанию</h1>
<?php if ($companies && $companies->num_rows > 0): ?>
<div class="company-grid">
<?php while ($company = $companies->fetch_assoc()): ?>
<a href="view.php?company=<?= (int)$company['id'] ?>" class="company-card">
<?php
$logo_path = $company['logo'] ?? '';
$file_exists = !empty($logo_path) && file_exists(__DIR__ . $logo_path);
?>
<?php if ($file_exists): ?>
<img src="<?= htmlspecialchars($logo_path) ?>" class="company-logo-bg" alt="">
<?php endif; ?>
<div class="company-content">
<?php if (!$file_exists): ?>
<div class="company-icon">🏢</div>
<?php endif; ?>
<h2 class="company-name"><?= htmlspecialchars($company['name']) ?></h2>
<?php if ($company['description']): ?>
<p class="company-desc"><?= htmlspecialchars($company['description']) ?></p>
<?php endif; ?>
<span class="company-link">Перейти →</span>
</div>
</a>
<?php endwhile; ?>
</div>
<?php else: ?>
<div class="empty-state">
<p>Пока нет доступных компаний. Обратитесь к администратору.</p>
<?php if ($is_admin): ?>
<a href="admin.php#companies" class="btn btn-primary">➕ Добавить компанию</a>
<?php endif; ?>
</div>
<?php endif; ?>
</main>
<footer class="footer">
<p>&copy; 2025 <?= htmlspecialchars($site_name) ?>. Все права защищены.</p>
</footer>
</div>
</body>
</html>