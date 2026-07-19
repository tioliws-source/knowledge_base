<?php
// ==================================================
// ФАЙЛ: header.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Общая шапка сайта
// ИСПРАВЛЕНИЯ:
//   1. Проблема 23: meta csrf-token больше НЕ в body.
//      Вместо этого JS динамически добавляет его в <head>.
//   2. Проблема 24: logo.png заменён на SVG data-URI (не требует файла).
//      Favicon тоже добавляется через JS в <head> (SVG data-URI).
// ==================================================
$user_avatar = '';
$user_full_name = $_SESSION['full_name'] ?? $_SESSION['login'] ?? 'Гость';
$user_position = '';
$user_company = '';
$user_role = $_SESSION['role'] ?? 'guest';
$is_admin = ($user_role == 'admin');
$is_editor = ($user_role == 'editor');

if (isset($_SESSION['user_id'])) {
    // Получаем данные пользователя через prepared statement
    $stmt = $mysqli->prepare("SELECT avatar, full_name, position FROM users WHERE id = ?");
    $uid = (int)$_SESSION['user_id'];
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($user_data) {
        $user_avatar = $user_data['avatar'] ?? '';
        $user_full_name = $user_data['full_name'] ?? $_SESSION['login'];
        $user_position = $user_data['position'] ?? '';
    }
    
    // Получаем компанию пользователя через prepared statement
    if (!$is_admin) {
        $stmt = $mysqli->prepare("
            SELECT c.name FROM companies c
            JOIN access a ON a.company_id = c.id
            WHERE a.user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $company_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $user_company = $company_data['name'] ?? '';
    }
}

// По умолчанию поиск скрыт, если не установлено $show_search = true
$show_search = $show_search ?? false;

// Название компании (передаётся из view.php)
$current_company_name = $current_company_name ?? '';
$site_title = ($site_name ?? 'База знаний') . ($current_company_name ? ' — ' . $current_company_name : '');
?>
<style>
.header {
    background: #ffffff;
    padding: 15px 25px;
    border: 2px solid #ddd;
    border-radius: 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 0;
    flex-wrap: wrap;
    gap: 10px;
    width: 100%;
}
.logo { display: flex; align-items: center; gap: 12px; }
/* ✅ ИСПРАВЛЕНО (проблема 24): SVG-логотип вместо пустого logo.png */
.logo-svg {
    width: 40px;
    height: 40px;
    display: inline-block;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(0,123,255,0.3);
    flex-shrink: 0;
}
.site-title { font-size: 24px; font-weight: 700; color: #1a2a4a; }
.user-menu {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}
.user-menu .search-form {
    display: flex;
    align-items: center;
    gap: 5px;
}
.user-menu .search-form input[type="text"] {
    padding: 4px 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 13px;
    width: 150px;
}
.user-menu .search-form input[type="text"]:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
}
.user-menu .btn-profile {
    background: transparent;
    border: 1px solid #ccc;
    color: #555;
    padding: 5px 14px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    font-size: 13px;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.user-menu .btn-profile:hover {
    background: #f0f0f0;
}
@media (max-width: 768px) {
    .header { flex-direction: column; align-items: stretch; padding: 10px 15px; }
    .user-menu { flex-wrap: wrap; gap: 8px; }
    .user-menu .search-form { width: 100%; }
    .user-menu .search-form input[type="text"] { flex: 1; width: auto; }
}
</style>

<!-- ✅ ИСПРАВЛЕНО (проблема 23 + 24): JS динамически добавляет favicon и meta csrf-token в <head> -->
<script>
(function() {
    // SVG-favicon (эмодзи 📚)
    var faviconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
        + '<rect width="100" height="100" rx="20" fill="%23007bff"/>'
        + '<text x="50" y="72" font-size="70" text-anchor="middle" fill="white" font-family="Arial" font-weight="bold">БЗ</text>'
        + '</svg>';
    var faviconUrl = 'data:image/svg+xml,' + faviconSvg;
    
    // Удаляем старые favicon (если есть)
    var oldIcons = document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"]');
    oldIcons.forEach(function(el) { el.remove(); });
    
    // Добавляем новый favicon в <head>
    var link = document.createElement('link');
    link.rel = 'icon';
    link.type = 'image/svg+xml';
    link.href = faviconUrl;
    if (document.head) document.head.appendChild(link);
    
    // ✅ ИСПРАВЛЕНО (проблема 23): добавляем meta csrf-token в <head>
    var csrfMeta = document.createElement('meta');
    csrfMeta.name = 'csrf-token';
    csrfMeta.content = '<?= htmlspecialchars(generateCsrfToken()) ?>';
    if (document.head) document.head.appendChild(csrfMeta);
})();
</script>

<header class="header">
    <div class="logo">
        <!-- ✅ ИСПРАВЛЕНО (проблема 24): SVG-логотип вместо пустого logo.png -->
        <span class="logo-svg" title="База знаний">БЗ</span>
        <span class="site-title"><?= htmlspecialchars($site_title) ?></span>
    </div>
    <div class="user-menu">
        <!-- 1. Поиск (если включён) -->
        <?php if ($show_search): ?>
        <form action="search.php" method="get" class="search-form">
            <input type="text" name="q" placeholder="Поиск..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            <button type="submit" class="btn btn-sm btn-outline">🔍</button>
        </form>
        <?php endif; ?>
        
        <!-- 2. Содержание (переход к последней компании) -->
        <?php if (!empty($_SESSION['last_company_id'])): ?>
            <a href="view.php?company=<?= (int)$_SESSION['last_company_id'] ?>" class="btn btn-sm btn-outline">📚 Содержание</a>
        <?php else: ?>
            <a href="index.php" class="btn btn-sm btn-outline">📚 Содержание</a>
        <?php endif; ?>
        
        <!-- 3. Главная -->
        <a href="index.php" class="btn btn-sm btn-outline">🏠 Главная</a>
        
        <!-- 4. Избранное (только для авторизованных) -->
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="favorites.php" class="btn btn-sm btn-outline">⭐ Избранное</a>
        <?php endif; ?>
        
        <!-- 5. Профиль (с именем) для авторизованных, иначе Войти -->
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="profile.php" class="btn-profile">👤 <?= htmlspecialchars($user_full_name) ?></a>
        <?php else: ?>
            <a href="login.php" class="btn btn-sm btn-primary">Войти</a>
        <?php endif; ?>
        
        <!-- 6. Админ-панель (только для админа и редактора) -->
        <?php if (isset($_SESSION['user_id']) && ($is_admin || $is_editor)): ?>
            <a href="admin.php" class="btn btn-sm btn-primary">🔧 Админ-панель</a>
        <?php endif; ?>
        
        <!-- 7. Выйти (только для авторизованных) -->
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="logout.php" class="btn btn-sm btn-outline">Выйти</a>
        <?php endif; ?>
    </div>
</header>
<!-- ✅ УБРАНО: <meta name="csrf-token"> — теперь добавляется через JS в <head> -->