<?php
// ==================================================
// ФАЙЛ: logs.php (БЕЗОПАСНАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

$show_search = true;
include 'header.php';

// Только админ
if (($_SESSION['role'] ?? '') != 'admin') {
    die('Доступ запрещён. Только для администратора.');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Получаем общее количество записей
$count_result = $mysqli->query("SELECT COUNT(*) as total FROM logs");
$total = $count_result->fetch_assoc()['total'];
$total_pages = max(1, ceil($total / $per_page));

// Получаем логи с пагинацией через prepared statement
$stmt = $mysqli->prepare("
    SELECT l.*, u.login as user_login, u.full_name as user_name
    FROM logs l
    LEFT JOIN users u ON u.id = l.user_id
    ORDER BY l.created_at DESC
    LIMIT ?, ?
");
$stmt->bind_param("ii", $offset, $per_page);
$stmt->execute();
$logs = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Журнал действий</title>
<link rel="stylesheet" href="style.css">
<style>
.logs-container { max-width: 1200px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.logs-container h1 { margin-top: 0; color: #1a2a4a; }
.logs-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
.logs-table th, .logs-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; }
.logs-table th { background: #f8f9fa; font-weight: 600; color: #1a2a4a; }
.logs-table tr:hover { background: #f8f9fa; }
.logs-table .action-badge { display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; }
.logs-table .action-badge.add { background: #d4edda; color: #155724; }
.logs-table .action-badge.edit { background: #cce5ff; color: #004085; }
.logs-table .action-badge.delete { background: #f8d7da; color: #721c24; }
.logs-table .action-badge.login { background: #d1ecf1; color: #0c5460; }
.logs-table .action-badge.logout { background: #e2e3e5; color: #383d41; }
.logs-table .action-badge.2fa { background: #fff3cd; color: #856404; }
.logs-table .action-badge.recover { background: #e2e3e5; color: #383d41; }
.logs-table .details-cell { max-width: 300px; word-wrap: break-word; font-size: 13px; color: #555; }
.pagination { display: flex; gap: 8px; justify-content: center; margin-top: 20px; flex-wrap: wrap; }
.pagination .page-link { padding: 6px 14px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #007bff; font-size: 14px; }
.pagination .page-link:hover { background: #007bff; color: #fff; border-color: #007bff; }
.pagination .page-link.active { background: #007bff; color: #fff; border-color: #007bff; }
.pagination .page-link.disabled { color: #999; pointer-events: none; }
</style>
</head>
<body>
<div class="container">
<div class="logs-container">
<h1>📋 Журнал действий</h1>
<p style="color:#666;font-size:14px;">Всего записей: <strong><?= (int)$total ?></strong></p>
<table class="logs-table">
<thead>
<tr>
<th>Время</th>
<th>Пользователь</th>
<th>Действие</th>
<th>Детали</th>
<th>IP-адрес</th>
</tr>
</thead>
<tbody>
<?php if ($logs->num_rows > 0): ?>
<?php while ($log = $logs->fetch_assoc()):
    $action_class = '';
    if (strpos($log['action'], 'add') !== false) $action_class = 'add';
    elseif (strpos($log['action'], 'edit') !== false) $action_class = 'edit';
    elseif (strpos($log['action'], 'delete') !== false) $action_class = 'delete';
    elseif (strpos($log['action'], 'login') !== false) $action_class = 'login';
    elseif (strpos($log['action'], 'logout') !== false) $action_class = 'logout';
    elseif (strpos($log['action'], '2fa') !== false) $action_class = '2fa';
    elseif (strpos($log['action'], 'recover') !== false) $action_class = 'recover';
?>
<tr>
<td><?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?></td>
<td><?= htmlspecialchars($log['user_login'] ?? (string)$log['user_id']) ?></td>
<td><span class="action-badge <?= $action_class ?>"><?= htmlspecialchars($log['action']) ?></span></td>
<td class="details-cell">
<?= htmlspecialchars($log['details']) ?>
<?php if ($log['old_data'] || $log['new_data']): ?>
<br><small style="color:#999;">
ДО: <?= htmlspecialchars($log['old_data']) ?> → ПОСЛЕ: <?= htmlspecialchars($log['new_data']) ?>
</small>
<?php endif; ?>
</td>
<td><?= htmlspecialchars($log['ip']) ?></td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="5" style="text-align:center;color:#999;padding:30px 0;">Нет записей в журнале</td>
</tr>
<?php endif; ?>
</tbody>
</table>
<?php if ($total_pages > 1): ?>
<div class="pagination">
<?php if ($page > 1): ?>
<a href="?page=<?= $page - 1 ?>" class="page-link">←</a>
<?php else: ?>
<span class="page-link disabled">←</span>
<?php endif; ?>
<?php for ($i = 1; $i <= $total_pages; $i++): ?>
<a href="?page=<?= $i ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
<?php endfor; ?>
<?php if ($page < $total_pages): ?>
<a href="?page=<?= $page + 1 ?>" class="page-link">→</a>
<?php else: ?>
<span class="page-link disabled">→</span>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
</body>
</html>