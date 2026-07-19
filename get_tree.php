<?php
// ==================================================
// ФАЙЛ: get_tree.php (ИСПРАВЛЕННАЯ ВЕРСИЯ)
// НАЗНАЧЕНИЕ: Получение дерева разделов компании (AJAX)
// ИСПРАВЛЕНИЯ:
//   1. Проблема 7: Убран вызов несуществующей функции selectBZItem()
//      Теперь обработчики событий навешиваются через JavaScript
//      в вызывающем файле (view.php через attachTreeEvents)
// ==================================================
require_once 'config.php';

$company_id = (int)($_GET['company_id'] ?? 0);
if (!$company_id) {
    die('Нет компании');
}

// Получаем данные текущего пользователя
$user_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? 'guest';
$is_admin = ($role == 'admin');
$is_editor = ($role == 'editor');
$can_edit = ($is_admin || $is_editor);

// Режим без редактирования (для модалки "Ссылка БЗ")
$no_edit = isset($_GET['no_edit']) && $_GET['no_edit'] == '1';

// Функция проверки доступа к разделу — через prepared statement
function hasAccess($section, $user_id, $role, $mysqli, $is_admin, $is_editor) {
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

// Получаем все разделы компании — через prepared statement
$stmt = $mysqli->prepare("
    SELECT id, parent_id, title, is_public, is_published
    FROM sections
    WHERE company_id = ?
    ORDER BY sort_order, id
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$sections = $stmt->get_result();
$all_sections = [];
while ($row = $sections->fetch_assoc()) {
    if (hasAccess($row, $user_id, $role, $mysqli, $is_admin, $is_editor)) {
        $all_sections[] = $row;
    }
}
$stmt->close();

// Функция построения дерева
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

// Функция проверки, является ли узел предком активного раздела
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

// Функция проверки наличия черновиков в поддереве
function hasDraftInSubtree($node) {
    if (!$node['is_published']) return true;
    if (isset($node['children'])) {
        foreach ($node['children'] as $child) {
            if (hasDraftInSubtree($child)) return true;
        }
    }
    return false;
}

// Функция рендеринга дерева
function renderTree($tree, $company_id, $can_edit, $prefix = '', $level = 0, $no_edit = false) {
    $html = '<ul style="list-style:none;padding-left:' . ($level * 20) . 'px;margin:0;">';
    $index = 1;
    
    foreach ($tree as $node) {
        $current_number = $prefix ? $prefix . '.' . $index : (string)$index;
        $has_children = isset($node['children']) && count($node['children']) > 0;
        $is_draft = !$node['is_published'];
        $has_draft_in_subtree = $has_children && hasDraftInSubtree($node);
        $color = '#333';
        $pencil = '';
        
        if ($can_edit && !$no_edit) {
            if ($is_draft) {
                $color = '#999';
                $pencil = '✏️ ';
            } elseif ($has_draft_in_subtree) {
                $pencil = '✏️ ';
            }
        }
        
        $display_style = ($has_children && isActivePath($node['id'])) ? 'block' : 'none';
        
        $html .= '<li class="' . ($is_draft && $can_edit && !$no_edit ? 'draft' : '') . '" data-id="' . $node['id'] . '" style="padding:4px 0;display:flex;align-items:center;gap:5px;flex-wrap:wrap;position:relative;">';
        $html .= '<span class="tree-number" style="font-weight:600;color:#333;margin-right:4px;font-size:20px;">' . $current_number . '.</span>';
        
        // ✅ ИСПРАВЛЕНО (проблема 7): убран onclick="selectBZItem(this); return false;"
        // Теперь обработчики навешиваются через JavaScript в вызывающем файле
        $html .= '<a href="#" class="tree-link" data-id="' . $node['id'] . '" style="text-decoration:none;color:' . $color . ';font-size:21px;font-weight:normal;font-style:normal;word-wrap:break-word;max-width:100%;' . ($no_edit ? 'cursor:pointer;' : '') . '">';
        $html .= ($has_children ? '📂 ' : '📄 ') . $pencil . htmlspecialchars($node['title']);
        $html .= '</a>';
        
        if ($has_children) {
            $html .= '<span class="toggle" style="cursor:pointer;color:#007bff;font-size:19px;margin-left:6px;">▼</span>';
        }
        
        if ($can_edit && !$no_edit) {
            $html .= '<span class="add-child-btn" data-parent="' . $node['id'] . '" style="cursor:pointer;color:#28a745;font-weight:bold;font-size:23px;background:none;border:none;padding:0 4px;margin-left:auto;">+</span>';
        }
        
        if ($has_children) {
            $html .= '<div class="sub-tree" style="display:' . $display_style . ';width:100%;">';
            $html .= renderTree($node['children'], $company_id, $can_edit, $current_number, $level + 1, $no_edit);
            $html .= '</div>';
        }
        
        $html .= '</li>';
        $index++;
    }
    
    $html .= '</ul>';
    return $html;
}

// Определяем текущий раздел
$section_id = (int)($_GET['section_id'] ?? 0);
$tree = buildTree($all_sections);

// Выводим дерево
if (!empty($tree)) {
    echo renderTree($tree, $company_id, $can_edit, '', 0, $no_edit);
} else {
    echo '<p style="padding:15px;color:#999;">Нет разделов</p>';
}
?>