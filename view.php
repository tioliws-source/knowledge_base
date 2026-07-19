<?php
// ==================================================
// ФАЙЛ: view.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// ==================================================
require_once 'config.php';

$company_id = (int)($_GET['company'] ?? 0);
if (!$company_id) {
    header('Location: index.php');
    exit;
}

if ($company_id > 0) {
    $_SESSION['last_company_id'] = $company_id;
}

$user_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? 'guest';
$is_admin = ($role == 'admin');
$is_editor = ($role == 'editor');

// Проверка доступа к компании
if ($is_admin) {
    $has_company_access = true;
} elseif ($user_id > 0) {
    $stmt = $mysqli->prepare("SELECT id FROM access WHERE user_id = ? AND company_id = ?");
    $stmt->bind_param("ii", $user_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_company_access = ($result->num_rows > 0);
    $stmt->close();
} else {
    $stmt = $mysqli->prepare("SELECT id FROM companies WHERE id = ? AND is_public = 1 AND deleted = 0");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_company_access = ($result->num_rows > 0);
    $stmt->close();
}

if (!$has_company_access) {
    die("У вас нет доступа к этой компании. <a href='index.php'>Вернуться</a>");
}

$stmt = $mysqli->prepare("SELECT * FROM companies WHERE id = ? AND deleted = 0");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$company) {
    die("Компания не найдена. <a href='index.php'>Вернуться</a>");
}

$stmt = $mysqli->prepare("SELECT id, parent_id, title, is_public, is_published FROM sections WHERE company_id = ? ORDER BY sort_order, id");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$sections_raw = $stmt->get_result();
$all_sections = [];
while ($row = $sections_raw->fetch_assoc()) {
    $all_sections[] = $row;
}
$stmt->close();

function hasSectionAccess($section, $user_id, $role, $mysqli, $is_admin, $is_editor) {
    if ($is_admin) return true;
    if ($is_editor) return true;
    if (!$section['is_published']) return false;
    
    $stmt = $mysqli->prepare("SELECT access_type FROM section_access WHERE section_id = ? AND role = ?");
    $stmt->bind_param("is", $section['id'], $role);
    $stmt->execute();
    $access = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($access && $access['access_type'] == 'deny') return false;
    if ($access && $access['access_type'] == 'allow') return true;
    return $section['is_public'] == 1;
}

$filtered_sections = [];
foreach ($all_sections as $section) {
    if (hasSectionAccess($section, $user_id, $role, $mysqli, $is_admin, $is_editor)) {
        $filtered_sections[] = $section;
    }
}

function buildTree($items, $parent_id = 0) {
    $tree = [];
    foreach ($items as $item) {
        if ($item['parent_id'] == $parent_id) {
            $children = buildTree($items, $item['id']);
            if ($children) $item['children'] = $children;
            $tree[] = $item;
        }
    }
    return $tree;
}

$tree = buildTree($filtered_sections);

function hasDraftInSubtree($node) {
    if (!$node['is_published']) return true;
    if (isset($node['children'])) {
        foreach ($node['children'] as $child) {
            if (hasDraftInSubtree($child)) return true;
        }
    }
    return false;
}

function isActivePath($node_id) {
    global $section_id, $all_sections;
    if (!$section_id) return false;
    if ($node_id == $section_id) return true;
    
    $current = $section_id;
    while ($current) {
        $parent = null;
        foreach ($all_sections as $s) {
            if ($s['id'] == $current) {
                $parent = $s['parent_id'];
                break;
            }
        }
        if ($parent == $node_id) return true;
        $current = $parent;
    }
    return false;
}

function renderTree($tree, $company_id, $can_edit, $prefix = '', $level = 0) {
    $html = '<ul style="list-style:none;padding-left:' . ($level * 20) . 'px;margin:0;">';
    $index = 1;
    
    foreach ($tree as $node) {
        $current_number = $prefix ? $prefix . '.' . $index : (string)$index;
        $has_children = isset($node['children']) && count($node['children']) > 0;
        $is_draft = !$node['is_published'];
        $color = $is_draft ? '#999' : '#333';
        $pencil = $is_draft ? '✏️ ' : '';
        $display_style = ($has_children && isActivePath($node['id'])) ? 'block' : 'none';
        
        $html .= '<li data-id="' . $node['id'] . '" style="padding:4px 0;display:flex;align-items:center;gap:5px;flex-wrap:wrap;position:relative;">';
        $html .= '<span class="tree-number" style="font-weight:600;color:#333;margin-right:4px;font-size:20px;">' . $current_number . '.</span>';
        $html .= '<a href="#" onclick="loadSection(' . $node['id'] . '); return false;" class="tree-link" data-id="' . $node['id'] . '" style="text-decoration:none;color:' . $color . ';font-size:21px;font-weight:normal;font-style:normal;word-wrap:break-word;max-width:100%;">';
        $html .= ($has_children ? '📂 ' : '📄 ') . $pencil . htmlspecialchars($node['title']);
        $html .= '</a>';
        
        if ($has_children) {
            $html .= '<span class="toggle" onclick="toggleTree(this)" style="cursor:pointer;color:#007bff;font-size:19px;margin-left:6px;">▼</span>';
        }
        
        if ($can_edit) {
            $html .= '<span class="add-child-btn" data-parent="' . $node['id'] . '" style="cursor:pointer;color:#28a745;font-weight:bold;font-size:23px;background:none;border:none;padding:0 4px;margin-left:auto;" onclick="openCreateSectionModal(' . $company_id . ', ' . $node['id'] . '); return false;">+</span>';
        }
        
        if ($has_children) {
            $html .= '<div class="sub-tree" style="display:' . $display_style . ';width:100%;">';
            $html .= renderTree($node['children'], $company_id, $can_edit, $current_number, $level + 1);
            $html .= '</div>';
        }
        
        $html .= '</li>';
        $index++;
    }
    
    $html .= '</ul>';
    return $html;
}

$section_id = (int)($_GET['section'] ?? 0);
$content = '';
$section_title = '';
$is_published = 0;
$section_data = null;

if ($section_id) {
    $stmt = $mysqli->prepare("SELECT * FROM sections WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $section_id, $company_id);
    $stmt->execute();
    $section_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($section_data && hasSectionAccess($section_data, $user_id, $role, $mysqli, $is_admin, $is_editor)) {
        $content = $section_data['content'] ?? '';
        $section_title = $section_data['title'];
        $is_published = $section_data['is_published'];
    } else {
        $content = '<p>Раздел не найден или у вас нет доступа.</p>';
        $section_id = 0;
    }
} else {
    if (!empty($filtered_sections)) {
        $first = $filtered_sections[0];
        $section_id = $first['id'];
        $section_data = $first;
        $content = $section_data['content'] ?? '';
        $section_title = $section_data['title'];
        $is_published = $section_data['is_published'];
    } else {
        $content = '<p>В этой компании пока нет доступных разделов.</p>';
        $section_id = 0;
    }
}

$can_edit = ($is_admin || $is_editor);
$views_count = $section_data['views_count'] ?? 0;
$show_search = true;
$current_company_name = $company['name'] ?? '';

$is_favorite = 0;
if ($user_id && $section_id) {
    $stmt = $mysqli->prepare("SELECT id FROM favorites WHERE user_id = ? AND section_id = ?");
    $stmt->bind_param("ii", $user_id, $section_id);
    $stmt->execute();
    $fav_check = $stmt->get_result();
    $is_favorite = $fav_check->num_rows > 0 ? 1 : 0;
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($company['name']) ?> — База знаний</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <!-- CSRF мета-тег (для AJAX-запросов) -->
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <script src="https://cdn.tiny.cloud/1/pjqumadfpz04wpbwx9l38knboh2zjv707edlllzdfrfdfck6/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <style>
        .view-container { display: flex; gap: 0; flex: 1; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); min-height: 600px; position: relative; }
        .tree-panel { width: 23%; min-width: 200px; background: #f8f9fa; border-right: 1px solid #e9ecef; padding: 20px 0; overflow-y: auto; max-height: 80vh; position: sticky; top: 0; align-self: flex-start; }
        .tree-panel h3 { padding: 0 20px 15px; font-size: 21px; color: #1a2a4a; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; }
        .tree-panel h3 .add-root-btn { cursor: pointer; color: #28a745; font-weight: bold; font-size: 25px; background: none; border: none; }
        .tree-menu { padding: 0 20px; }
        .tree-menu ul { list-style: none; padding-left: 0; margin: 0; }
        .tree-menu li { padding: 4px 0; display: flex; align-items: center; gap: 5px; flex-wrap: wrap; position: relative; }
        .tree-menu li .add-child-btn { cursor: pointer; color: #28a745; font-weight: bold; font-size: 23px; background: none; border: none; padding: 0 4px; margin-left: auto; }
        .tree-menu li .add-child-btn:hover { color: #1e7e34; }
        .tree-menu li .tree-link { text-decoration: none; font-size: 21px; font-weight: normal; font-style: normal; text-transform: none; word-wrap: break-word; max-width: 100%; }
        .tree-menu li .tree-link:hover { text-decoration: underline; }
        .tree-menu li.active > .tree-link { font-weight: 600; color: #0056b3 !important; }
        .tree-menu li .toggle { cursor: pointer; color: #007bff; font-size: 19px; margin-left: 4px; user-select: none; }
        .tree-menu li .tree-number { font-weight: 600; color: #333; margin-right: 4px; font-size: 20px; }
        .tree-menu .sub-tree { width: 100%; }
        .content-panel { width: 77%; padding: 30px 35px; overflow-y: auto; max-height: 80vh; background: #ffffff; position: relative; display: flex; flex-direction: column; }
        .content-body { font-size: 15px; line-height: 1.7; color: #333; position: relative; min-height: 300px; overflow-y: auto; flex: 1; word-wrap: break-word; overflow-wrap: break-word; white-space: pre-wrap; }
        .content-body p { margin: 0 0 10px 0; }
        .content-body img { max-width: 100%; height: auto; cursor: move; }
        .content-body img.dragging { opacity: 0.5; }
        .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .content-title { font-size: 24px; color: #1a2a4a; margin: 0; display: flex; align-items: center; gap: 10px; }
        .content-header-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .view-counter { font-size: 12px; color: #999; background: transparent; padding: 0; border: none; }
        .btn { padding: 8px 20px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 14px; border: none; cursor: pointer; transition: all 0.2s; display: inline-block; }
        .btn-primary { background: #007bff; color: #fff; }
        .btn-primary:hover { background: #0056b3; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-success:hover { background: #1e7e34; }
        .btn-outline { background: transparent; border: 1px solid #ccc; color: #555; }
        .btn-outline:hover { background: #f0f0f0; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-danger:hover { background: #bd2130; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-warning:hover { background: #e0a800; }
        .btn-publish { background: #28a745; color: #fff; }
        .btn-publish:hover { background: #1e7e34; }
        .btn-publish.published { background: #ffc107; color: #333; }
        .btn-publish.published:hover { background: #e0a800; }
        .btn-danger.delete-btn { background: #dc3545; color: #fff; }
        .btn-danger.delete-btn:hover { background: #bd2130; }
        .editor-area { margin-top: 15px; }
        .editor-actions { margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .editor-actions .divider { border-left: 2px solid #ccc; height: 30px; margin: 0 5px; }
        #edit-btn.edit-mode { background: #dc3545 !important; color: #fff !important; border-color: #dc3545 !important; }
        #edit-btn.edit-mode:hover { background: #bd2130 !important; }
        .title-edit-input { width: 100%; padding: 10px; font-size: 20px; font-weight: 600; border: 2px solid #007bff; border-radius: 6px; margin-bottom: 10px; box-sizing: border-box; }
        .title-edit-input:focus { outline: none; border-color: #28a745; }
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999; display: flex; justify-content: center; align-items: center; }
        .modal-box { background: #fff; border-radius: 12px; padding: 30px; max-width: 700px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
        .modal-box h3 { margin-top: 0; }
        .modal-box .buttons { display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; }
        .modal-box .btn { min-width: 80px; }
        .modal-box .tree-selector { border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; max-height: 300px; overflow-y: auto; background: #f8f9fa; }
        .modal-box .tree-selector ul { list-style: none; padding-left: 20px; margin: 0; }
        .modal-box .tree-selector li { padding: 4px 0; cursor: pointer; color: #007bff; }
        .modal-box .tree-selector li:hover { text-decoration: underline; }
        .modal-box .tree-selector li.selected { font-weight: 600; color: #0056b3; background: #e9ecef; }
        .modal-box .tree-selector li .tree-link { text-decoration: none; color: #007bff; }
        .modal-box .tree-selector li .tree-link:hover { text-decoration: underline; }
        .notification-container { position: fixed; top: 20px; right: 20px; z-index: 999999; display: flex; flex-direction: column; gap: 10px; max-width: 400px; width: 100%; }
        .notification { padding: 15px 20px; border-radius: 8px; color: #fff; font-size: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateX(100%); transition: all 0.4s ease; pointer-events: none; border-left: 5px solid rgba(255,255,255,0.3); }
        .notification.show { opacity: 1; transform: translateX(0); pointer-events: auto; }
        .notification.success { background: #28a745; }
        .notification.error { background: #dc3545; }
        .notification.warning { background: #ffc107; color: #333; }
        .notification.info { background: #007bff; }
        @media (max-width: 768px) {
            .view-container { flex-direction: column; }
            .tree-panel { width: 100% !important; min-width: unset; max-height: 300px; border-right: none !important; border-bottom: 1px solid #e9ecef !important; }
            .content-panel { width: 100% !important; padding: 20px !important; }
        }
    </style>
</head>
<body>
<div class="container">
    <?php
    $current_company_name = $company['name'] ?? '';
    $show_search = true;
    include 'header.php';
    ?>
    
    <div class="view-container">
        <div class="tree-panel">
            <h3>
                📂 <?= htmlspecialchars($company['name']) ?>
                <?php if ($can_edit): ?>
                    <button class="add-root-btn" onclick="openCreateSectionModal(<?= $company_id ?>, 0)" title="Создать главу">+</button>
                <?php endif; ?>
            </h3>
            <div class="tree-menu" id="tree-container">
                <?php if (!empty($tree)): ?>
                    <?= renderTree($tree, $company_id, $can_edit) ?>
                <?php else: ?>
                    <p style="padding:15px;color:#999;">Нет разделов</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="content-panel" id="content-panel">
            <div class="content-header">
                <h2 class="content-title" id="section-title">
                    <?= htmlspecialchars($section_title) ?>
                </h2>
                <div class="content-header-right">
                    <?php if ($user_id): ?>
                        <button class="star-btn <?= $is_favorite ? 'active' : '' ?>" onclick="toggleFavorite(<?= $section_id ?>)">
                            <?= $is_favorite ? '⭐ В избранном' : '⭐ В избранное' ?>
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($can_edit): ?>
                        <button class="btn btn-primary" id="edit-btn" onclick="toggleEdit()">✏️ Редактировать</button>
                    <?php else: ?>
                        <button class="btn btn-sm btn-outline" onclick="toggleFullscreenView()">⛶ Во весь экран</button>
                    <?php endif; ?>
                    
                    <span class="view-counter" id="view-counter">👁️ <?= $views_count ?></span>
                </div>
            </div>
            
            <div class="content-body" id="content-body">
                <?= $content ?>
            </div>
            
            <div id="edit-mode" style="display:none;">
                <input type="text" id="edit-title" class="title-edit-input" value="<?= htmlspecialchars($section_title) ?>" placeholder="Введите название статьи">
                <div class="editor-area">
                    <textarea id="my-editor"><?= htmlspecialchars($content) ?></textarea>
                </div>
                <div class="editor-actions">
                    <button class="btn btn-success" id="save-btn" onclick="saveContent(false)">💾 Сохранить</button>
                    <button class="btn btn-outline" onclick="cancelEdit()">❌ Отмена</button>
                    <span class="divider"></span>
                    <button class="btn btn-publish <?= $is_published ? 'published' : '' ?>" id="publish-btn" onclick="togglePublish()">
                        <?= $is_published ? '✅ Выложено' : '📤 Выложить' ?>
                    </button>
                    <button class="btn btn-danger delete-btn" onclick="showDeleteSectionModal()">🗑️ Удалить статью</button>
                </div>
            </div>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; 2026 База знаний. Все права защищены.</p>
    </footer>
</div>

<div class="notification-container" id="notification-container"></div>

<div id="create-section-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>➕ Создать новый раздел</h3>
        <div style="margin-bottom:15px;">
            <label style="display:block;font-weight:600;">Название раздела</label>
            <input type="text" id="new-section-title" value="Новая статья" placeholder="Введите название" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;font-size:16px;">
        </div>
        <div class="buttons">
            <button class="btn btn-outline" onclick="closeModal('create-section-modal')">Отмена</button>
            <button class="btn btn-success" id="create-section-confirm-btn">Создать</button>
        </div>
    </div>
</div>

<div id="bzlink-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>🔗 Ссылка на раздел БЗ</h3>
        <p>Выберите раздел из содержания (кликните по нему для выбора):</p>
        <div class="tree-selector" id="bz-tree-selector">Загрузка...</div>
        <div class="buttons">
            <button class="btn btn-outline" onclick="closeModal('bzlink-modal')">Отмена</button>
            <button class="btn btn-success" id="bz-insert-btn" onclick="insertBZLink()">💾 Вставить ссылку</button>
        </div>
    </div>
</div>

<div id="delete-section-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>⚠️ Удаление статьи</h3>
        <p>Вы уверены, что хотите удалить эту статью? Это действие необратимо.</p>
        <div class="buttons">
            <button class="btn btn-outline" onclick="closeModal('delete-section-modal')">Отмена</button>
            <button class="btn btn-danger" id="delete-confirm-btn">🗑️ Удалить</button>
        </div>
    </div>
</div>

<form id="ajax-form" style="display:none;">
    <input type="hidden" name="section_id" id="ajax-section-id" value="<?= $section_id ?>">
    <input type="hidden" name="company_id" id="ajax-company-id" value="<?= $company_id ?>">
</form>

<script>
// CSRF-токен
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function showNotification(message, type = 'success', duration = 5000) {
    const container = document.getElementById('notification-container');
    const el = document.createElement('div');
    el.className = 'notification ' + type;
    el.textContent = message;
    container.appendChild(el);
    
    setTimeout(() => el.classList.add('show'), 20);
    setTimeout(() => {
        el.classList.remove('show');
        setTimeout(() => el.remove(), 400);
    }, duration);
}

function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function toggleTree(el) {
    const parentLi = el.closest('li');
    const sub = parentLi.querySelector('.sub-tree');
    if (sub) {
        const isHidden = sub.style.display === 'none';
        sub.style.display = isHidden ? 'block' : 'none';
        el.textContent = isHidden ? '▼' : '▶';
    }
}

// ✅ ИСПРАВЛЕНО: Функция полноэкранного режима
function toggleFullscreenView() {
    const contentPanel = document.getElementById('content-panel');
    if (!document.fullscreenElement) {
        contentPanel.requestFullscreen().catch(err => {
            showNotification('Не удалось войти в полноэкранный режим', 'error');
        });
    } else {
        document.exitFullscreen();
    }
}

function loadSection(sectionId) {
    if (isEditMode) {
        showNotification('Сначала сохраните или отмените редактирование', 'warning', 7000);
        return;
    }
    
    document.querySelectorAll('.tree-link').forEach(l => l.parentElement.classList.remove('active'));
    const link = document.querySelector(`.tree-link[data-id="${sectionId}"]`);
    if (link) link.parentElement.classList.add('active');
    
    fetch('get_section.php?section_id=' + sectionId + '&company_id=<?= $company_id ?>')
        .then(res => res.json())
        .then(data => {
            if (data.error) { showNotification('Ошибка: ' + data.error, 'error', 10000); return; }
            
            document.getElementById('section-title').textContent = data.title;
            document.getElementById('ajax-section-id').value = sectionId;
            document.getElementById('content-body').innerHTML = data.content || '';
            
            if (tinymce.get('my-editor')) {
                tinymce.get('my-editor').setContent(data.content || '');
            }
            
            document.getElementById('edit-title').value = data.title;
            
            const publishBtn = document.getElementById('publish-btn');
            if (data.is_published) {
                publishBtn.classList.add('published');
                publishBtn.textContent = '✅ Выложено';
            } else {
                publishBtn.classList.remove('published');
                publishBtn.textContent = '📤 Выложить';
            }
            
            const counter = document.getElementById('view-counter');
            if (counter) counter.textContent = '👁️ ' + (data.views_count || 0);
            
            if (document.querySelector('.star-btn')) {
                // ✅ ИСПРАВЛЕНО: Добавлен CSRF-токен
                const formData = new FormData();
                formData.append('action', 'get_favorites');
                formData.append('csrf_token', csrfToken);
                
                fetch('save.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData
                })
                .then(r => r.json())
                .then(favs => {
                    if (Array.isArray(favs)) {
                        const isFav = favs.some(f => f.id == sectionId);
                        const btn = document.querySelector('.star-btn');
                        if (isFav) { btn.classList.add('active'); btn.innerHTML = '⭐ В избранном'; }
                        else { btn.classList.remove('active'); btn.innerHTML = '⭐ В избранное'; }
                    }
                });
            }
            
            recordView(sectionId);
        })
        .catch(err => showNotification('Ошибка загрузки: ' + err, 'error', 10000));
}

function recordView(section_id) {
    setTimeout(() => {
        // ✅ ИСПРАВЛЕНО: Добавлен CSRF-токен
        const formData = new FormData();
        formData.append('action', 'record_view');
        formData.append('section_id', section_id);
        formData.append('csrf_token', csrfToken);
        
        fetch('save.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'recorded') {
                const counter = document.getElementById('view-counter');
                if (counter) {
                    const current = parseInt(counter.textContent.replace(/\D/g,'')) || 0;
                    counter.textContent = '👁️ ' + (current + 1);
                }
            }
        })
        .catch(() => {});
    }, 30000);
}

let isEditMode = false;

function toggleEdit() {
    const editBtn = document.getElementById('edit-btn');
    const contentBody = document.getElementById('content-body');
    const editMode = document.getElementById('edit-mode');
    
    if (!isEditMode) {
        isEditMode = true;
        contentBody.style.display = 'none';
        editMode.style.display = 'block';
        editBtn.textContent = '✏️ Редактирование';
        editBtn.classList.add('edit-mode');
        
        if (tinymce.get('my-editor')) {
            tinymce.get('my-editor').setContent(contentBody.innerHTML);
        }
        
        document.getElementById('edit-title').value = document.getElementById('section-title').textContent;
        document.querySelectorAll('.tree-link').forEach(l => l.classList.add('disabled'));
    } else {
        saveContent(true);
    }
}

function saveContent(closeAfter = false) {
    const section_id = document.getElementById('ajax-section-id').value;
    if (!section_id) {
        showNotification('Ошибка: раздел не выбран', 'error', 10000);
        return;
    }
    
    let content = '';
    if (tinymce.get('my-editor')) {
        content = tinymce.get('my-editor').getContent();
    } else {
        content = document.getElementById('my-editor').value;
    }
    
    const title = document.getElementById('edit-title').value.trim();
    if (!title) {
        showNotification('Название статьи не может быть пустым!', 'error', 10000);
        return;
    }
    
    // ✅ ИСПРАВЛЕНО: Добавлен CSRF-токен
    const formData = new FormData();
    formData.append('action', 'save_full');
    formData.append('section_id', section_id);
    formData.append('content', content);
    formData.append('title', title);
    formData.append('roles', '');
    formData.append('csrf_token', csrfToken);
    
    fetch('save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data.trim() === 'ok') {
            showNotification('✅ Сохранено успешно!', 'success', 3000);
            document.getElementById('section-title').textContent = title;
            document.getElementById('content-body').innerHTML = content;
            
            const link = document.querySelector(`.tree-link[data-id="${section_id}"]`);
            if (link) {
                const currentText = link.textContent;
                const iconMatch = currentText.match(/^([📄📂])\s*(✏️\s*)?(.*)$/);
                if (iconMatch) {
                    const icon = iconMatch[1];
                    const pencil = iconMatch[2] || '';
                    link.textContent = icon + ' ' + pencil + title;
                } else {
                    link.textContent = title;
                }
            }
            
            if (closeAfter) {
                exitEditMode();
                updatePublishStatus();
            }
        } else {
            showNotification('Ошибка: ' + data, 'error', 10000);
        }
    })
    .catch(err => showNotification('Ошибка: ' + err, 'error', 10000));
}

function exitEditMode() {
    isEditMode = false;
    const editBtn = document.getElementById('edit-btn');
    editBtn.textContent = '✏️ Редактировать';
    editBtn.classList.remove('edit-mode');
    document.getElementById('content-body').style.display = 'block';
    document.getElementById('edit-mode').style.display = 'none';
    document.querySelectorAll('.tree-link').forEach(l => l.classList.remove('disabled'));
    refreshTree(<?= $company_id ?>);
}

function cancelEdit() {
    if (confirm('Отменить изменения? Несохранённые данные будут потеряны.')) {
        const section_id = document.getElementById('ajax-section-id').value;
        if (section_id) {
            loadSection(section_id);
        }
        exitEditMode();
    }
}

function togglePublish() {
    const section_id = document.getElementById('ajax-section-id').value;
    if (!section_id) {
        showNotification('Ошибка: раздел не выбран', 'error', 10000);
        return;
    }
    
    if (isEditMode) {
        saveContent(false);
    }
    
    const publishBtn = document.getElementById('publish-btn');
    const isCurrentlyPublished = publishBtn.classList.contains('published');
    const newStatus = isCurrentlyPublished ? 0 : 1;
    
    // ✅ ИСПРАВЛЕНО: Добавлен CSRF-токен
    const formData = new FormData();
    formData.append('action', 'set_publish');
    formData.append('section_id', section_id);
    formData.append('status', newStatus);
    formData.append('csrf_token', csrfToken);
    
    fetch('save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data.trim() === 'ok') {
            showNotification(newStatus ? '✅ Статья опубликована!' : '✅ Статья снята с публикации.', 'success', 5000);
            
            if (newStatus) {
                publishBtn.classList.add('published');
                publishBtn.textContent = '✅ Выложено';
            } else {
                publishBtn.classList.remove('published');
                publishBtn.textContent = '📤 Выложить';
            }
            
            refreshTree(<?= $company_id ?>);
        } else {
            showNotification('Ошибка: ' + data, 'error', 10000);
        }
    })
    .catch(err => showNotification('Ошибка: ' + err, 'error', 10000));
}

function updatePublishStatus() {
    const section_id = document.getElementById('ajax-section-id').value;
    if (!section_id) return;
    
    fetch('get_section.php?section_id=' + section_id + '&company_id=<?= $company_id ?>')
        .then(res => res.json())
        .then(data => {
            if (data.error) return;
            
            const publishBtn = document.getElementById('publish-btn');
            if (data.is_published) {
                publishBtn.classList.add('published');
                publishBtn.textContent = '✅ Выложено';
            } else {
                publishBtn.classList.remove('published');
                publishBtn.textContent = '📤 Выложить';
            }
        });
}

function showDeleteSectionModal() {
    const section_id = document.getElementById('ajax-section-id').value;
    if (!section_id) {
        showNotification('Ошибка: раздел не выбран', 'error', 10000);
        return;
    }
    
    // ✅ ИСПРАВЛЕНО: Добавлен CSRF-токен
    const formData = new FormData();
    formData.append('action', 'check_children');
    formData.append('section_id', section_id);
    formData.append('csrf_token', csrfToken);
    
    fetch('save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data === 'has_children') {
            showNotification('Нельзя удалить раздел, так как у него есть подразделы. Сначала удалите их.', 'error', 10000);
            return;
        }
        
        openModal('delete-section-modal');
        document.getElementById('delete-confirm-btn').onclick = function() {
            deleteSection(section_id);
        };
    })
    .catch(err => showNotification('Ошибка: ' + err, 'error', 10000));
}

function deleteSection(section_id) {
    // ✅ ИСПРАВЛЕНО: Добавлен CSRF-токен
    const formData = new FormData();
    formData.append('action', 'delete_section');
    formData.append('section_id', section_id);
    formData.append('csrf_token', csrfToken);
    
    fetch('save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data.trim() === 'ok') {
            showNotification('✅ Статья удалена', 'success', 5000);
            closeModal('delete-section-modal');
            
            if (isEditMode) exitEditMode();
            
            let parent_id = 0;
            const allSections = <?= json_encode($all_sections) ?>;
            for (let s of allSections) {
                if (s.id == section_id) {
                    parent_id = s.parent_id || 0;
                    break;
                }
            }
            
            if (parent_id > 0) {
                loadSection(parent_id);
            } else {
                const firstSection = document.querySelector('.tree-link');
                if (firstSection) {
                    loadSection(firstSection.dataset.id);
                } else {
                    location.reload();
                }
            }
            
            refreshTree(<?= $company_id ?>);
        } else if (data === 'has_children') {
            showNotification('Нельзя удалить раздел, так как у него есть подразделы.', 'error', 10000);
            closeModal('delete-section-modal');
        } else {
            showNotification('Ошибка удаления: ' + data, 'error', 10000);
        }
    })
    .catch(err => showNotification('Ошибка: ' + err, 'error', 10000));
}

function toggleFavorite(section_id) {
    const btn = document.querySelector('.star-btn');
    if (!btn) return;
    
    // ✅ ИСПРАВЛЕНО: Добавлен CSRF-токен
    const formData = new FormData();
    formData.append('action', 'toggle_favorite');
    formData.append('section_id', section_id);
    formData.append('csrf_token', csrfToken);
    
    fetch('save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) { showNotification('Ошибка: ' + data.error, 'error', 10000); return; }
        
        if (data.status === 'added') { 
            btn.classList.add('active'); 
            btn.innerHTML = '⭐ В избранном'; 
            showNotification('Добавлено в избранное', 'success', 5000); 
        } else { 
            btn.classList.remove('active'); 
            btn.innerHTML = '⭐ В избранное'; 
            showNotification('Удалено из избранного', 'warning', 7000); 
        }
    })
    .catch(err => showNotification('Ошибка: ' + err, 'error', 10000));
}

function openCreateSectionModal(company_id, parent_id) {
    document.getElementById('new-section-title').value = 'Новая статья';
    openModal('create-section-modal');
    
    document.getElementById('create-section-confirm-btn').onclick = function() {
        const title = document.getElementById('new-section-title').value.trim() || 'Новая статья';
        createSection(company_id, parent_id, title);
    };
}

function createSection(company_id, parent_id, title) {
    // ✅ ИСПРАВЛЕНО: Добавлен CSRF-токен
    const formData = new FormData();
    formData.append('action', 'create_section');
    formData.append('company_id', company_id);
    formData.append('parent_id', parent_id);
    formData.append('title', title);
    formData.append('csrf_token', csrfToken);
    
    fetch('save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data.trim() === 'ok') {
            showNotification('✅ Новый раздел создан', 'success', 5000);
            closeModal('create-section-modal');
            refreshTree(company_id);
        } else {
            showNotification('Ошибка: ' + data, 'error', 10000);
        }
    })
    .catch(err => showNotification('Ошибка: ' + err, 'error', 10000));
}

function refreshTree(company_id) {
    const container = document.getElementById('tree-container');
    container.innerHTML = '<p style="padding:15px;color:#999;">Загрузка...</p>';
    
    fetch('get_tree.php?company_id=' + company_id + '&section_id=' + document.getElementById('ajax-section-id').value)
        .then(res => res.text())
        .then(html => {
            container.innerHTML = html;
            attachTreeEvents();
        })
        .catch(err => {
            container.innerHTML = '<p style="padding:15px;color:#999;">Ошибка обновления дерева</p>';
            showNotification('Ошибка обновления дерева: ' + err, 'error', 10000);
        });
}

function attachTreeEvents() {
    document.querySelectorAll('.tree-link').forEach(link => {
        link.onclick = function(e) {
            e.preventDefault();
            const id = this.dataset.id;
            if (id) {
                if (isEditMode) {
                    showNotification('Сначала сохраните или отмените редактирование', 'warning', 7000);
                    return;
                }
                loadSection(id);
            }
        };
    });
    
    document.querySelectorAll('.add-child-btn').forEach(btn => {
        btn.onclick = function() {
            const company_id = <?= $company_id ?>;
            const parent_id = this.dataset.parent || 0;
            openCreateSectionModal(company_id, parent_id);
        };
    });
    
    document.querySelectorAll('.toggle').forEach(toggle => {
        toggle.onclick = function() {
            const sub = this.parentElement.querySelector('.sub-tree');
            if (sub) {
                const isHidden = sub.style.display === 'none';
                sub.style.display = isHidden ? 'block' : 'none';
                this.textContent = isHidden ? '▼' : '▶';
            }
        };
    });
    
    document.querySelectorAll('.add-root-btn').forEach(btn => {
        btn.onclick = function() {
            openCreateSectionModal(<?= $company_id ?>, 0);
        };
    });
}

function openBZLinkModal() {
    const selection = tinymce.activeEditor.selection.getContent({format: 'text'});
    if (!selection) {
        showNotification('Выделите текст, который хотите сделать ссылкой', 'warning', 7000);
        return;
    }
    
    window._bzSelectedText = selection;
    openModal('bzlink-modal');
    
    document.getElementById('bz-tree-selector').innerHTML = 'Загрузка...';
    
    fetch('get_tree.php?company_id=<?= $company_id ?>&no_edit=1')
        .then(res => res.text())
        .then(html => {
            document.getElementById('bz-tree-selector').innerHTML = html;
            document.querySelectorAll('#bz-tree-selector .tree-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelectorAll('#bz-tree-selector .tree-link').forEach(l => l.parentElement.classList.remove('selected'));
                    this.parentElement.classList.add('selected');
                });
            });
        })
        .catch(() => {
            document.getElementById('bz-tree-selector').innerHTML = '<p style="color:#999;">Ошибка загрузки дерева</p>';
        });
}

function insertBZLink() {
    const selected = document.querySelector('#bz-tree-selector .selected .tree-link');
    if (!selected) {
        showNotification('Выберите раздел', 'error', 10000);
        return;
    }
    
    const id = selected.dataset.id;
    const title = selected.textContent.trim();
    const url = 'view.php?company=<?= $company_id ?>&section=' + id;
    const linkText = window._bzSelectedText || title;
    
    tinymce.activeEditor.execCommand('mceInsertLink', false, {
        href: url,
        text: linkText,
        target: '_blank'
    });
    
    closeModal('bzlink-modal');
    showNotification('Ссылка на раздел вставлена', 'success', 3000);
    window._bzSelectedText = null;
}

// ==========================================================
// TINYMCE С РАБОЧИМ DRAG-AND-DROP
// ==========================================================
tinymce.init({
    selector: '#my-editor',
    height: 500,
    menubar: true,
    language: 'ru',
    plugins: [
        'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
        'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
        'insertdatetime', 'media', 'table', 'help', 'wordcount', 'paste'
    ],
    toolbar: 'undo redo | blocks | ' +
        'bold italic backcolor | alignleft aligncenter alignright alignjustify | ' +
        'bullist numlist outdent indent | removeformat | ' +
        'link image media table | bzlink fullscreen preview | help',
    setup: function(editor) {
        editor.ui.registry.addButton('bzlink', {
            text: 'Ссылка БЗ',
            tooltip: 'Вставить ссылку на раздел базы знаний',
            onAction: function() {
                openBZLinkModal();
            }
        });
        
        // Drag-and-drop для изображений
        editor.on('init', function() {
            const contentBody = editor.getBody();
            let draggedImage = null;
            
            contentBody.addEventListener('dragstart', function(e) {
                if (e.target.tagName === 'IMG') {
                    draggedImage = e.target;
                    e.target.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/html', e.target.outerHTML);
                }
            });
            
            contentBody.addEventListener('dragend', function(e) {
                if (draggedImage) {
                    draggedImage.classList.remove('dragging');
                    draggedImage = null;
                    editor.save();
                }
            });
            
            contentBody.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });
            
            contentBody.addEventListener('drop', function(e) {
                e.preventDefault();
                
                // Проверяем, есть ли файлы (drag-and-drop из проводника)
                if (e.dataTransfer.files.length > 0) {
                    const file = e.dataTransfer.files[0];
                    if (file.type.startsWith('image/')) {
                        // Загружаем файл через XMLHttpRequest
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', 'upload.php', true);
                        xhr.onload = function() {
                            if (xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (response.success && response.download_url) {
                                        editor.insertContent('<img src="' + response.download_url + '" alt="' + response.original_name + '" style="max-width: 100%; height: auto;" />');
                                        editor.save();
                                        showNotification('✅ Изображение загружено', 'success', 3000);
                                    } else {
                                        showNotification('❌ Ошибка: ' + (response.message || 'Неизвестная ошибка'), 'error', 5000);
                                    }
                                } catch (e) {
                                    showNotification('❌ Ошибка парсинга ответа', 'error', 5000);
                                }
                            } else {
                                showNotification('❌ Ошибка сервера: ' + xhr.status, 'error', 5000);
                            }
                        };
                        xhr.onerror = function() {
                            showNotification('❌ Ошибка сети', 'error', 5000);
                        };
                        
                        const formData = new FormData();
                        formData.append('file', file, file.name);
                        formData.append('company_id', '<?= $company_id ?>');
                        formData.append('section_id', document.getElementById('ajax-section-id').value || '');
                        formData.append('folder', '');
                        formData.append('custom_name', file.name);
                        xhr.send(formData);
                    }
                } else if (draggedImage) {
                    // Перемещение изображения внутри редактора
                    const target = e.target.closest('img');
                    if (target && target !== draggedImage) {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = draggedImage.outerHTML;
                        draggedImage.outerHTML = target.outerHTML;
                        target.outerHTML = tempDiv.innerHTML;
                        editor.save();
                        showNotification('✅ Изображение перемещено', 'success', 2000);
                    }
                }
            });
        });
    },
    content_style: 'body { font-family:Segoe UI, Arial, sans-serif; font-size:16px; } img { max-width: 100%; height: auto; cursor: move; }',
    paste_data_images: true,
    automatic_uploads: true,
    images_upload_url: 'upload.php',
    images_upload_params: {
        company_id: <?= $company_id ?>,
        section_id: function() { return document.getElementById('ajax-section-id').value || ''; }
    },
    images_upload_handler: function (blobInfo, progress) {
        return new Promise(function(resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'upload.php', true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success && response.download_url) {
                            resolve(response.download_url);
                        } else {
                            reject('Ошибка загрузки: ' + (response.message || 'Неизвестная ошибка'));
                        }
                    } catch (e) {
                        reject('Ошибка парсинга ответа: ' + e.message);
                    }
                } else {
                    reject('Ошибка сервера: ' + xhr.status);
                }
            };
            xhr.onerror = function() {
                reject('Ошибка сети при загрузке файла');
            };
            
            const formData = new FormData();
            formData.append('file', blobInfo.blob(), blobInfo.filename());
            formData.append('company_id', '<?= $company_id ?>');
            formData.append('section_id', document.getElementById('ajax-section-id').value || '');
            formData.append('folder', '');
            formData.append('custom_name', blobInfo.filename());
            xhr.send(formData);
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    attachTreeEvents();
    
    const activeId = <?= $section_id ?>;
    if (activeId) {
        document.querySelectorAll('.tree-link').forEach(link => {
            if (link.dataset.id == activeId) {
                link.parentElement.classList.add('active');
            }
        });
    }
    
    updatePublishStatus();
});
</script>
</body>
</html>