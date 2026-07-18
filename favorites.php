<?php
// ==================================================
// ФАЙЛ: favorites.php (БЕЗОПАСНАЯ ВЕРСИЯ С CSRF)
// ==================================================
require_once 'config.php';

$show_search = true;
include 'header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Избранное</title>
<link rel="stylesheet" href="style.css">
<!-- CSRF мета-тег для AJAX-запросов -->
<meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
<style>
.favorites-container { max-width: 900px; margin: 30px auto; padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.favorites-container h1 { margin-top: 0; }
.favorites-list { list-style: none; padding: 0; }
.favorites-list li { padding: 12px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
.favorites-list li:last-child { border-bottom: none; }
.favorites-list .company { font-size: 14px; color: #666; }
.favorites-list .date { font-size: 12px; color: #999; }
.favorites-list .title { font-weight: 600; }
.favorites-list .title a { color: #007bff; text-decoration: none; }
.favorites-list .title a:hover { text-decoration: underline; }
.empty { color: #999; text-align: center; padding: 40px 0; }
</style>
</head>
<body>
<div class="container">
<div class="favorites-container">
<h1>⭐ Избранные статьи</h1>
<div id="favorites-list">
<p class="empty">Загрузка...</p>
</div>
</div>
</div>
<script>
// CSRF-токен для AJAX-запросов
var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ✅ ИСПРАВЛЕНО: добавлен CSRF-токен
fetch('save.php', {
    method: 'POST',
    headers: { 
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': csrfToken
    },
    body: 'action=get_favorites&csrf_token=' + encodeURIComponent(csrfToken)
})
.then(res => res.json())
.then(data => {
    const container = document.getElementById('favorites-list');
    if (data.error) {
        container.innerHTML = '<p class="empty">Ошибка: ' + escapeHtml(data.error) + '</p>';
        return;
    }
    if (data.length === 0) {
        container.innerHTML = '<p class="empty">У вас пока нет избранных статей</p>';
        return;
    }
    let html = '<ul class="favorites-list">';
    data.forEach(item => {
        const date = new Date(item.created_at).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
        const safeTitle = escapeHtml(item.title);
        const safeCompany = escapeHtml(item.company_name);
        const safeId = parseInt(item.id);
        const safeCompanyId = parseInt(item.company_id);
        html += `
            <li>
                <div>
                    <div class="title"><a href="view.php?company=${safeCompanyId}&section=${safeId}">${safeTitle}</a></div>
                    <div class="company">🏢 ${safeCompany}</div>
                </div>
                <div class="date">${date}</div>
            </li>
        `;
    });
    html += '</ul>';
    container.innerHTML = html;
})
.catch(err => {
    document.getElementById('favorites-list').innerHTML = '<p class="empty">Ошибка загрузки</p>';
});

// Функция экранирования HTML (защита от XSS)
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>