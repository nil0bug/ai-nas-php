<?php
$baseDir = '/var/www/html';
$file = $baseDir . '/data.json';
$uploadDir = '/icons/';
$bgFile = $baseDir . '/backgrounds/daily.jpg';

if (!is_dir($baseDir . $uploadDir)) mkdir($baseDir . $uploadDir, 0755, true);
if (!is_dir($baseDir . '/backgrounds/')) mkdir($baseDir . '/backgrounds/', 0755, true);

function resolveUrl($ico, $baseUrl, $target) {
    if (preg_match('~^https?://|data:~i', $ico)) return $ico;
    $p = parse_url($target);
    if (strpos($ico, '//') === 0) return ($p['scheme']??'http') . ':' . $ico;
    if (strpos($ico, '/') === 0) return $baseUrl . $ico;
    return rtrim($target, '/') . '/' . $ico;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input');

    if ($_GET['action'] === 'save') {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) { echo json_encode(['status' => 'error']); exit; }
        $oldRaw = file_exists($file) ? file_get_contents($file) : '';
        if ($raw === $oldRaw) { echo json_encode(['status' => 'no_change']); exit; }
        file_put_contents($file, $raw, LOCK_EX);
        echo json_encode(['status' => 'ok']);
    } 
    elseif ($_GET['action'] === 'fetch_icon_list') {
        $target = json_decode($raw, true)['url'] ?? '';
        if (!preg_match('~^https?://~i', $target)) { $target = (preg_match('/^\d+$/', $target)) ? 'http://127.0.0.1:' . $target : 'http://' . $target; }
        $opts = ['http'=>['header'=>"User-Agent: Mozilla/5.0\r\n",'timeout'=>5], 'ssl'=>["verify_peer"=>false,"verify_peer_name"=>false]];
        $ctx = stream_context_create($opts);
        $html = @file_get_contents($target, false, $ctx);
        $p = parse_url($target);
        $baseUrl = ($p['scheme']??'http') . '://' . ($p['host']??'') . (isset($p['port'])?':'.$p['port']:'');
        $icons = [];
        if ($html) {
            if (preg_match_all('/<link.*?rel=["\']([^"\']*icon[^"\']*)["\'].*?href=["\'](.*?)["\']/i', $html, $matches)) {
                foreach ($matches[2] as $ico) {
                    $fullUrl = resolveUrl($ico, $baseUrl, $target);
                    if ($fullUrl) $icons[] = $fullUrl;
                }
            }
        }
        $icons[] = $baseUrl . '/favicon.ico';
        $icons = array_values(array_unique($icons));
        echo json_encode(['status' => 'ok', 'icons' => $icons]);
        exit;
    }
    elseif ($_GET['action'] === 'download_icon') {
        $url = json_decode($raw, true)['url'] ?? '';
        if (!$url) { echo json_encode(['status' => 'error']); exit; }
        $opts = ['http'=>['header'=>"User-Agent: Mozilla/5.0\r\n",'timeout'=>8], 'ssl'=>["verify_peer"=>false,"verify_peer_name"=>false]];
        $ctx = stream_context_create($opts);
        $img = @file_get_contents($url, false, $ctx);
        if ($img) {
            $uniqueName = 'fav_' . substr(md5($url), 0, 8) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.png';
            file_put_contents($baseDir.$uploadDir.$uniqueName, $img);
            echo json_encode(['status' => 'ok', 'path' => $uploadDir.$uniqueName]);
        } else { echo json_encode(['status' => 'error']); }
        exit;
    }
    elseif ($_GET['action'] === 'upload') {
        if (isset($_FILES['image'])) {
            $n = 'up_'.time().'_' . bin2hex(random_bytes(4)) . '.' . pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            if(move_uploaded_file($_FILES['image']['tmp_name'], $baseDir . $uploadDir . $n)) echo json_encode(['status' => 'ok', 'path' => $uploadDir . $n]);
        }
        exit;
    }
    elseif ($_GET['action'] === 'update_bg') {
        $api = "https://www.bing.com/HPImageArchive.aspx?format=js&idx=0&n=1&mkt=zh-CN";
        $d = json_decode(@file_get_contents($api), true);
        if (isset($d['images'][0]['url'])) {
            $img = @file_get_contents("https://www.bing.com" . $d['images'][0]['url']);
            if ($img) { file_put_contents($bgFile, $img); echo json_encode(['status' => 'ok']); exit; }
        }
        echo json_encode(['status' => 'error']); exit;
    }
    exit;
}

$db = [["id" => "f_default", "name" => "默认", "sections" => [["id" => "s_def", "title" => "默认区块", "items" => []]]]];
if (file_exists($file)) { $loaded = json_decode(file_get_contents($file), true); if (is_array($loaded)) $db = $loaded; }

function upgradeStructure(&$db) {
    foreach ($db as &$f) {
        if (!isset($f['id'])) $f['id'] = 'f_' . bin2hex(random_bytes(4));
        if (!isset($f['sections'])) { $f['sections'] = [['id' => 's_' . bin2hex(random_bytes(4)), 'title' => '默认区块', 'items' => isset($f['items']) ? $f['items'] : []]]; unset($f['items']); }
        foreach ($f['sections'] as &$s) {
            if (!isset($s['id'])) $s['id'] = 's_' . bin2hex(random_bytes(4));
            if (!isset($s['title'])) $s['title'] = '默认区块';
            if (!isset($s['items'])) $s['items'] = [];
            foreach ($s['items'] as &$item) {
                if (!isset($item['id'])) $item['id'] = 'i_' . bin2hex(random_bytes(4));
                if (!isset($item['type'])) $item['type'] = 'link';
                if ($item['type'] === 'folder') {
                    if (!isset($item['sections'])) { $item['sections'] = [['id' => 's_sub_' . bin2hex(random_bytes(4)), 'title' => '默认区块', 'items' => isset($item['children']) ? $item['children'] : []]]; unset($item['children']); }
                    $tempArr = [$item]; upgradeStructure($tempArr); $item = $tempArr[0];
                }
            }
        }
    }
}
upgradeStructure($db);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ARM Dashboard</title>
    <style>
        :root { --glass: rgba(255, 255, 255, 0.88); --glass-strong: rgba(255, 255, 255, 0.95); --accent: #007bff; --danger: #ff4d4f; --success: #10b981; }
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; min-height: 100vh; background: url('/backgrounds/daily.jpg?t=<?=time()?>') no-repeat center center fixed; background-size: cover; user-select: none; overflow-x: hidden; }
        body::before { content: ''; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.15); backdrop-filter: blur(15px); z-index: -1; }
        .navbar { position: fixed; top: 0; width: 100%; height: 60px; background: var(--glass); display: flex; align-items: center; padding: 0 15px; box-sizing: border-box; z-index: 100; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .tabs { display: flex; gap: 8px; overflow-x: auto; flex: 1; scrollbar-width: none; }
        .tab { cursor: pointer; padding: 6px 18px; border-radius: 20px; font-size: 13px; background: rgba(0,0,0,0.05); white-space: nowrap; font-weight: bold; transition: 0.2s; }
        .tab.active { background: var(--accent); color: white; }
        .tab.drag-hover { background: rgba(0, 123, 255, 0.4) !important; color: white; transform: scale(1.05); }
        .container { max-width: 1000px; margin: 80px auto; padding: 0 15px; }
        .section-block { margin-bottom: 30px; transition: all 0.2s; border-radius: 16px; padding-bottom: 10px; }
        
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding: 8px 12px; border-radius: 12px; transition: all 0.2s; }
        .section-header.draggable { cursor: grab; background: rgba(255,255,255,0.1); backdrop-filter: blur(4px); }
        .section-header.draggable:active { cursor: grabbing; }
        .section-header.dragging { opacity: 0.4; }
        
        /* ✅ 区块标题样式 */
        .section-title { font-size: 15px; font-weight: 700; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.3); letter-spacing: 0.5px; pointer-events: auto; cursor: pointer; }

        .section-actions { display: flex; gap: 8px; }
        .btn-icon { background: rgba(255,255,255,0.2); border: none; color: white; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; backdrop-filter: blur(4px); transition: 0.2s; }
        .btn-icon:hover { background: rgba(255,255,255,0.4); }
        
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 15px; min-height: 120px; }
        .folder-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; min-height: 120px; }
        .folder-grid .card { padding: 10px 2px; border-radius: 16px; }
        .folder-grid .card span { font-size: 11px; line-height: 1.2; }
        @media (max-width: 480px) { .folder-grid { overflow-x: auto; padding-bottom: 10px; } }
        
        .card { background: var(--glass); border-radius: 20px; text-align: center; padding: 15px 5px; cursor: pointer; border: 2.3px solid transparent; transition: all 0.2s; position: relative; }
        .card.pending-edit { border-color: var(--accent) !important; background: rgba(0,123,255,0.15) !important; transform: scale(1.05); }
        .card.selected { border-color: var(--danger) !important; background: rgba(255,77,79,0.15) !important; transform: scale(0.95); }
        .edit-badge { position: absolute; top: -6px; right: -6px; width: 26px; height: 26px; border-radius: 50%; display: none; align-items: center; justify-content: center; font-size: 14px; border: 2px solid white; z-index: 11; }
        .card.pending-edit .edit-badge { display: flex; background: var(--accent); color: white; }
        .card.selected .edit-badge { display: flex; background: var(--danger); color: white; }
        .card img { width: 45px; height: 45px; border-radius: 12px; margin-bottom: 5px; pointer-events: none; object-fit: cover; background: #fff; }
        .card span { display: block; font-size: 12px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #222; pointer-events: none; }
        .add-card { border: 2px dashed rgba(255,255,255,0.6) !important; background: rgba(255,255,255,0.3) !important; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#fff; font-size:24px; font-weight:bold; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .add-card span { font-size: 11px; margin-top: 4px; font-weight: 600; }

        .drop-target { outline: 3px dashed var(--accent) !important; outline-offset: -3px; background: rgba(0, 123, 255, 0.15) !important; }
        .card.dragging, .section-header.dragging { opacity: 0.4; }
        .section-bottom-drop { height: 30px; margin-top: 10px; border: 2px dashed transparent; border-radius: 12px; transition: all 0.2s; }
        .section-bottom-drop.drop-target { border-color: var(--accent) !important; background: rgba(0, 123, 255, 0.1) !important; }
        .marquee { position: fixed; border: 2px dashed var(--accent); background: rgba(0, 123, 255, 0.1); z-index: 999; pointer-events: none; display: none; }

        .folder-icon-preview { width: 45px; height: 45px; display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 3px; padding: 4px; background: rgba(255,255,255,0.9); border-radius: 12px; box-sizing: border-box; margin: 0 auto 5px; pointer-events: none; }
        .folder-icon-preview.modal-preview { width: 55px; height: 55px; padding: 6px; gap: 4px; margin-bottom: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .folder-icon-preview img { width: 100%; height: 100%; border-radius: 4px; object-fit: cover; background: #e5e7eb; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(8px); opacity: 0; transition: opacity 0.2s ease; }
        .modal.show { display: flex; opacity: 1; }
        .modal-content { background: white; padding: 25px; border-radius: 28px; width: 85%; max-width: 380px; transform: scale(0.95); transition: transform 0.2s ease; box-shadow: 0 15px 40px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto; }
        .modal.show .modal-content { transform: scale(1); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 11px; color: #888; margin: 0 0 5px 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid #f0f0f0; border-radius: 14px; box-sizing: border-box; outline: none; background: #f9f9f9; font-size: 14px; }
        .btn { border: none; padding: 11px 18px; border-radius: 14px; cursor: pointer; font-weight: bold; font-size: 13px; transition: 0.2s; }
        .btn:active { transform: scale(0.96); }
        .btn-p { background: var(--accent); color: white; }
        .btn-s { background: #f2f2f2; color: #555; }
        .btn-d { background: var(--danger); color: white; }
        .btn-move { background: rgba(255,255,255,0.2); color: #333; backdrop-filter: blur(4px); }
        .btn-move.active { background: var(--success); color: white; }
        
        #adminPanel { display: none; background: var(--glass); padding: 15px; border-radius: 22px; margin-bottom: 20px; gap: 10px; border: 1px solid rgba(255,255,255,0.5); flex-wrap: wrap; }
        #drawer { position: fixed; top: 0; right: -340px; width: 320px; height: 100vh; background: rgba(255,255,255,0.95); backdrop-filter: blur(20px); z-index: 2000; transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: -5px 0 25px rgba(0,0,0,0.1); padding: 25px; box-sizing: border-box; display: flex; flex-direction: column; gap: 15px; }
        #drawer.open { right: 0; }
        #drawerOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.3); z-index: 1999; display: none; backdrop-filter: blur(4px); }
        #drawerOverlay.show { display: block; }
        .drop-zone { border: 2px dashed #ccc; border-radius: 16px; padding: 25px 15px; text-align: center; color: #666; cursor: pointer; transition: all 0.2s; background: #fafafa; }
        .drop-zone:hover, .drop-zone.drag-over { border-color: var(--accent); background: rgba(0,123,255,0.05); color: var(--accent); }
        #backBtn.drag-hover { background: rgba(0, 123, 255, 0.2) !important; border-color: var(--accent) !important; transform: scale(1.05); }

        .toast { position: fixed; top: 80px; left: 50%; transform: translateX(-50%) translateY(-20px); background: rgba(0,0,0,0.85); color: white; padding: 12px 24px; border-radius: 24px; font-size: 14px; font-weight: 500; z-index: 3000; opacity: 0; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); pointer-events: none; backdrop-filter: blur(8px); box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast.error { background: rgba(255, 77, 79, 0.95); }
        .toast.success { background: rgba(16, 185, 129, 0.95); }

        .icon-picker-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 15px; max-height: 200px; overflow-y: auto; padding: 5px; }
        .icon-picker-item { width: 100%; aspect-ratio: 1; border: 2px solid #eee; border-radius: 12px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; background: #f9f9f9; overflow: hidden; }
        .icon-picker-item:hover { border-color: #ccc; transform: scale(1.05); }
        .icon-picker-item.selected { border-color: var(--accent); background: rgba(0,123,255,0.1); box-shadow: 0 0 0 3px rgba(0,123,255,0.2); }
        .icon-picker-item img { width: 80%; height: 80%; object-fit: contain; }
    </style>
</head>
<body onclick="handleBodyClick(event)">

<div class="navbar">
    <div class="tabs" id="tabBar"></div>
    <div style="display:flex; gap:8px;">
        <button class="btn btn-move" id="moveBtn" onclick="toggleMoveMode()">移动</button>
        <button class="btn btn-d" id="delBtn" style="display:none" onclick="batchDelConfirm()">删除</button>
        <button class="btn btn-s" id="modeBtn" onclick="toggleEditMode()">管理</button>
        <button class="btn btn-s" onclick="toggleDrawer()">☰</button>
    </div>
</div>

<div class="container" id="mainContainer">
    <div id="adminPanel">
        <button class="btn btn-p" onclick="openAddModal()">+ 添加</button>
        <button class="btn btn-s" onclick="openFolderMgr()">分栏管理</button>
        <button class="btn btn-s" onclick="openSectionMgr()">区块管理</button>
        <button class="btn btn-s" onclick="updateWallpaper()">更新壁纸</button>
    </div>
    <div id="contentArea"></div>
</div>

<div class="marquee" id="marquee"></div>
<div class="toast" id="toast"></div>
<div class="modal" id="uiModal"><div class="modal-content" id="uiModalBody"></div></div>

<div id="drawerOverlay" onclick="toggleDrawer()"></div>
<div id="drawer">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h3 style="margin:0;font-size:18px;">🗂️ 数据管理</h3>
        <button class="btn btn-s" style="padding:6px 12px;" onclick="toggleDrawer()">✕</button>
    </div>
    <button class="btn btn-p" onclick="exportData()">📤 一键导出 JSON</button>
    <div class="drop-zone" id="dropZone" onclick="document.getElementById('importFile').click()">
        <div style="font-size:24px;margin-bottom:8px;">📥</div>
        <div style="font-weight:bold;">点击 或 拖拽</div>
        <div style="font-size:12px;margin-top:4px;">JSON 文件到此处导入</div>
        <input type="file" id="importFile" accept=".json" style="display:none" onchange="importData(this.files[0])">
    </div>
</div>

<script>
    let db = <?php echo json_encode($db); ?>;
    let lastSavedDbString = JSON.stringify(db);
    let saveTimer = null;

    let activeIdx = parseInt(localStorage.getItem('activeIdx')) || 0;
    if (activeIdx >= db.length) activeIdx = 0;
    let activeFolder = null;
    
    let isEditMode = false;
    let isMoveMode = false;
    
    let pendingEditItem = null; 
    let selectedIds = new Set(); 
    let lastClickedId = null; 
    
    let isMarqueeActive = false;
    let marqueeStartX = 0, marqueeStartY = 0;

    let dragType = null; 
    let currentlyDraggedId = null;
    let currentlyDraggedSectionId = null;
    let isDropped = false;
    
    let tabHoverTimer = null;
    let currentHoverTabIdx = -1;
    let backHoverTimer = null;
    let currentHoverBack = false;

    let scrollInterval = null;
    const scrollThreshold = 80;
    const scrollSpeed = 15;

    function startAutoScroll(direction) { if (scrollInterval) return; scrollInterval = setInterval(() => { window.scrollBy(0, direction * scrollSpeed); }, 16); }
    function stopAutoScroll() { if (scrollInterval) { clearInterval(scrollInterval); scrollInterval = null; } }

    function showToast(msg, type = 'info') {
        const toast = document.getElementById('toast');
        toast.innerText = msg;
        toast.className = 'toast ' + type;
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    function upgradeStructureJS(data) {
        if (!Array.isArray(data)) return;
        data.forEach(f => {
            if (!f.id) f.id = 'f_' + Math.random().toString(36).substr(2, 9);
            if (!f.sections) { f.sections = [{ id: 's_' + Math.random().toString(36).substr(2, 9), title: '默认区块', items: f.items || [] }]; delete f.items; }
            f.sections.forEach(s => {
                if (!s.id) s.id = 's_' + Math.random().toString(36).substr(2, 9);
                if (!s.title) s.title = '默认区块';
                if (!s.items) s.items = [];
                s.items.forEach(item => {
                    if (!item.id) item.id = 'i_' + Math.random().toString(36).substr(2, 9);
                    if (!item.type) item.type = 'link';
                    if (item.type === 'folder') {
                        if (!item.sections) { item.sections = [{ id: 's_sub_' + Math.random().toString(36).substr(2, 9), title: '默认区块', items: item.children || [] }]; delete item.children; }
                        upgradeStructureJS([item]);
                    }
                });
            });
        });
    }

    function getCurrentContext() { return activeFolder ? activeFolder : db[activeIdx]; }
    function findItemById(id, context = db) {
        for (let ctx of context) {
            if (ctx.sections) {
                for (let s of ctx.sections) {
                    for (let item of s.items) {
                        if (item.id === id) return item;
                        if (item.type === 'folder') { const found = findItemById(id, [item]); if (found) return found; }
                    }
                }
            }
        }
        return null;
    }
    function findContainerOfSection(sectionId) {
        for (let f of db) {
            if (f.sections.some(s => s.id === sectionId)) return f;
            for (let s of f.sections) {
                for (let item of s.items) {
                    if (item.type === 'folder' && item.sections.some(s => s.id === sectionId)) return item;
                }
            }
        }
        return null;
    }
    function findSectionById(sectionId) {
        for (let f of db) {
            for (let s of f.sections) {
                if (s.id === sectionId) return s;
                for (let item of s.items) {
                    if (item.type === 'folder' && item.sections) {
                        for (let subS of item.sections) { if (subS.id === sectionId) return subS; }
                    }
                }
            }
        }
        return null;
    }

    function renderFolderIconHTML(folderItem, sizeClass = '') {
        let allItems = [];
        if (folderItem && folderItem.sections) folderItem.sections.forEach(s => { allItems = allItems.concat(s.items); });
        let iconsHtml = '';
        for (let i = 0; i < 4; i++) {
            const item = allItems[i];
            let src = item ? (item.icon || '/icons/default.png') : '';
            if (src && !src.startsWith('http') && !src.startsWith('data:') && !src.startsWith('/')) src = '/' + src.replace(/^\//, '');
            iconsHtml += `<img src="${src}" onerror="this.style.visibility='hidden'">`;
        }
        return `<div class="folder-icon-preview ${sizeClass}">${iconsHtml}</div>`;
    }

    function renderTabs() {
        const bar = document.getElementById('tabBar');
        bar.innerHTML = db.map((f, i) => `<div class="tab ${i===activeIdx?'active':''}" onclick="switchFolder(${i})" ondblclick="openRenameModal(${i})">${f.name}</div>`).join('') + `<div class="tab" onclick="openAddFolderModal()">+</div>`;
    }

    function renderContent() {
        const ctx = getCurrentContext();
        const isRoot = activeFolder === null;
        const container = document.getElementById('contentArea');
        let html = '';
        
        if (!isRoot) {
            const backDropAttr = isMoveMode ? `ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDropOnBack(event)"` : '';
            html += `<div class="section-block" style="text-align:center;margin-bottom:20px;"><button class="btn btn-s" id="backBtn" onclick="activeFolder=null; renderContent();" style="padding:8px 20px;" ${backDropAttr}>🔙 返回上级分栏</button></div>`;
        }

        ctx.sections.forEach(section => {
            const sectionHeaderDragAttr = isMoveMode ? `draggable="true" ondragstart="handleSectionDS(event, '${section.id}')" ondragend="this.classList.remove('dragging')"` : '';
            const sectionGridDropAttr = isMoveMode ? `ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDropOnGrid(event, '${section.id}')"` : '';
            const sectionHeaderDropAttr = isMoveMode ? `ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDropOnSectionHeader(event, '${section.id}')"` : '';
            const bottomDropAttr = isMoveMode ? `ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDropAfterSection(event, '${section.id}')"` : '';

            html += `<div class="section-block"><div class="section-header ${isMoveMode ? 'draggable' : ''}" id="sec-head-${section.id}" ${sectionHeaderDragAttr} ${sectionHeaderDropAttr}>`;
            
            // ✅ 区块标题交互：编辑模式双击打开编辑弹窗，任意模式右键打开编辑弹窗
            const titleDblClick = isEditMode ? `ondblclick="event.stopPropagation();editSection('${section.id}')"` : '';
            html += `<span class="section-title" ${titleDblClick} oncontextmenu="event.preventDefault();event.stopPropagation();editSection('${section.id}')">${section.title}</span>`;
            
            if (isEditMode) {
                html += `<div class="section-actions"><button class="btn-icon" onclick="event.stopPropagation();editSection('${section.id}')">✎</button>`;
                if (ctx.sections.length > 1) html += `<button class="btn-icon" style="background:rgba(255,77,79,0.2);color:#ff4d4f;" onclick="event.stopPropagation();deleteSection('${section.id}')">🗑</button>`;
                html += `</div>`;
            }
            html += `</div>`;
            
            html += `<div class="${activeFolder ? 'grid folder-grid' : 'grid'}" id="section-${section.id}" ${sectionGridDropAttr}>`;
            
            section.items.forEach(item => {
                const isPending = pendingEditItem === item;
                const isSelected = selectedIds.has(item.id);
                const isFolder = item.type === 'folder';
                const iconHtml = isFolder ? renderFolderIconHTML(item) : `<img src="${item.icon || '/icons/default.png'}">`;
                
                const itemDragAttr = isMoveMode ? `draggable="true" ondragstart="handleItemDS(event, '${item.id}')"` : '';
                let cardDropAttr = '';
                if (isMoveMode) {
                    cardDropAttr = `ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDropOnCard(event, '${item.id}', '${section.id}', '${isFolder ? 'folder' : 'link'}')"`;
                }

                html += `<div class="card ${isPending?'pending-edit':''} ${isSelected?'selected':''}" data-id="${item.id}" ${itemDragAttr} ${cardDropAttr} onclick="handleIconClick(event, '${item.id}')" oncontextmenu="handleIconContext(event, '${item.id}')">`;
                html += `<div class="edit-badge">✎</div>${iconHtml}<span>${item.name}</span>`;
                if (isFolder) html += `<div style="position:absolute;bottom:4px;right:4px;font-size:8px;background:rgba(0,0,0,0.5);color:white;padding:1px 4px;border-radius:3px;font-weight:bold;">专区</div>`;
                html += `</div>`;
            });
            
            if (isEditMode && !isMoveMode) {
                html += `<div class="card add-card" onclick="openAddModalToSection('${section.id}')">+<span>添加</span></div>`;
            }
            html += `</div>`;
            html += `<div class="section-bottom-drop" ${bottomDropAttr}></div>`;
            html += `</div>`;
        });

        if (isEditMode && !isMoveMode) {
            html += `<div class="section-block" style="text-align:center;margin-top:10px;">
                <button class="btn" onclick="addNewSection()" style="border:2px dashed rgba(255,255,255,0.6);background:rgba(255,255,255,0.2);color:#fff;width:100%;padding:14px;border-radius:16px;font-weight:bold;backdrop-filter:blur(4px);font-size:14px;">+ 添加新区块</button>
            </div>`;
        }

        container.innerHTML = html;
        updateDelBtn();
    }

    function showError(msg) { showUI(`<h3 style="margin-top:0;color:var(--danger)">⚠️ 提示</h3><p style="color:#555;line-height:1.5;">${msg}</p><button class="btn btn-p" style="width:100%;margin-top:10px;" onclick="closeUI()">知道了</button>`); }

    // ✅ 移除 Ctrl 逻辑，仅保留 Shift 范围选择和普通多选
    function handleIconClick(e, id) {
        e.stopPropagation();
        if (isMoveMode) return; 
        const item = findItemById(id);
        if (!item) return;

        if (isEditMode) {
            const isShift = e.shiftKey;

            if (isShift && lastClickedId) {
                const items = getCurrentContext().sections.flatMap(s => s.items);
                const startIdx = items.findIndex(i => i.id === lastClickedId);
                const endIdx = items.findIndex(i => i.id === id);
                if (startIdx !== -1 && endIdx !== -1) {
                    const min = Math.min(startIdx, endIdx);
                    const max = Math.max(startIdx, endIdx);
                    for (let i = min; i <= max; i++) selectedIds.add(items[i].id);
                }
                pendingEditItem = null;
            } else if (selectedIds.size > 0) {
                if (selectedIds.has(id)) selectedIds.delete(id); else selectedIds.add(id);
                lastClickedId = id;
            } else if (pendingEditItem === item) {
                openAddModal(item); pendingEditItem = null;
            } else {
                pendingEditItem = item; lastClickedId = id;
            }
        } else {
            if (item.type === 'folder') { activeFolder = item; renderContent(); } 
            else { 
                let url = item.url; 
                if (/^\d+$/.test(url)) url = window.location.protocol + '//' + window.location.hostname + ':' + url; 
                else if (!url.includes('://')) url = 'http://' + url; 
                window.open(url, '_blank'); 
            }
        }
        renderContent();
    }

    function handleIconContext(e, id) {
        e.preventDefault(); e.stopPropagation();
        const item = findItemById(id);
        if (!item) return;
        openAddModal(item);
    }

    function handleBodyClick(e) {
        if (!e.target.closest('.card') && !e.target.closest('.modal') && !e.target.closest('.btn') && !e.target.closest('.navbar') && pendingEditItem !== null) {
            pendingEditItem = null; renderContent();
        }
    }

    // ✅ 移除框选时的 Ctrl 判断，每次框选直接清空
    document.addEventListener('mousedown', (e) => {
        if (!isEditMode || isMoveMode) return;
        if (e.button !== 0) return;
        if (e.target.closest('.card') || e.target.closest('.btn') || e.target.closest('.modal') || e.target.closest('.navbar') || e.target.closest('.section-header') || e.target.closest('#backBtn')) return;

        isMarqueeActive = true; marqueeStartX = e.clientX; marqueeStartY = e.clientY;
        const marqueeEl = document.getElementById('marquee');
        marqueeEl.style.display = 'block'; marqueeEl.style.left = e.clientX + 'px'; marqueeEl.style.top = e.clientY + 'px';
        marqueeEl.style.width = '0px'; marqueeEl.style.height = '0px';

        selectedIds.clear(); pendingEditItem = null;
        document.querySelectorAll('.card').forEach(c => c.classList.remove('selected', 'pending-edit'));
        updateDelBtn();
    });

    document.addEventListener('mousemove', (e) => {
        if (!isMarqueeActive) return;
        const x = Math.min(e.clientX, marqueeStartX); const y = Math.min(e.clientY, marqueeStartY);
        const w = Math.abs(e.clientX - marqueeStartX); const h = Math.abs(e.clientY - marqueeStartY);
        const marqueeEl = document.getElementById('marquee');
        marqueeEl.style.left = x + 'px'; marqueeEl.style.top = y + 'px'; marqueeEl.style.width = w + 'px'; marqueeEl.style.height = h + 'px';

        const marqueeRect = { left: x, top: y, right: x + w, bottom: y + h };
        document.querySelectorAll('.card').forEach(card => {
            const rect = card.getBoundingClientRect();
            if (!(rect.right < marqueeRect.left || rect.left > marqueeRect.right || rect.bottom < marqueeRect.top || rect.top > marqueeRect.bottom)) {
                const id = card.getAttribute('data-id');
                if (id) { selectedIds.add(id); card.classList.add('selected'); card.classList.remove('pending-edit'); }
            }
        });
        pendingEditItem = null; updateDelBtn();
    });

    document.addEventListener('mouseup', () => {
        if (isMarqueeActive) { isMarqueeActive = false; document.getElementById('marquee').style.display = 'none'; }
    });

    function batchDelConfirm() {
        if (selectedIds.size === 0) return;
        showUI(`<h3 style="margin-top:0;color:var(--danger)">批量删除</h3><p style="color:#555;">确认删除选中的 <b>${selectedIds.size}</b> 个项目吗？</p><div style="display:flex;gap:10px;margin-top:15px;"><button class="btn btn-s" style="flex:1" onclick="closeUI()">取消</button><button class="btn btn-d" style="flex:1" onclick="actBatchDel()">确定删除</button></div>`);
    }
    function actBatchDel() {
        for (let f of db) {
            for (let s of f.sections) {
                s.items = s.items.filter(i => !selectedIds.has(i.id));
                for (let item of s.items) { if (item.type === 'folder' && item.sections) { for (let subS of item.sections) { subS.items = subS.items.filter(i => !selectedIds.has(i.id)); } } }
            }
        }
        if (activeFolder && selectedIds.has(activeFolder.id)) activeFolder = null;
        selectedIds.clear(); pendingEditItem = null; closeUI(); saveRefresh();
    }
    function updateDelBtn() {
        const btn = document.getElementById('delBtn');
        if (btn) { btn.style.display = (isEditMode && selectedIds.size > 0) ? 'block' : 'none'; btn.innerText = `删除(${selectedIds.size})`; }
    }

    function toggleMoveMode() { isMoveMode = !isMoveMode; document.getElementById('moveBtn').classList.toggle('active', isMoveMode); pendingEditItem = null; selectedIds.clear(); renderContent(); }
    function toggleEditMode() { 
        isEditMode = !isEditMode; 
        if (isEditMode) { isMoveMode = false; document.getElementById('moveBtn').classList.remove('active'); }
        pendingEditItem = null; selectedIds.clear(); 
        document.getElementById('adminPanel').style.display = isEditMode ? 'flex' : 'none'; 
        document.getElementById('modeBtn').innerText = isEditMode ? '完成' : '管理'; 
        renderContent(); 
    }

    function handleItemDS(event, id) { dragType = 'item'; currentlyDraggedId = id; currentlyDraggedSectionId = null; isDropped = false; event.dataTransfer.setData('text/plain', id); event.target.classList.add('dragging'); }
    function handleSectionDS(event, sectionId) { dragType = 'section'; currentlyDraggedSectionId = sectionId; currentlyDraggedId = null; isDropped = false; event.dataTransfer.setData('text/plain', sectionId); event.currentTarget.classList.add('dragging'); }

    function handleDragOver(event) { if (!isMoveMode) return; event.preventDefault(); document.querySelectorAll('.drop-target').forEach(el => el.classList.remove('drop-target')); event.currentTarget.classList.add('drop-target'); }
    function handleDragLeave(event) { event.currentTarget.classList.remove('drop-target'); }
    function clearDropTargets() { document.querySelectorAll('.drop-target').forEach(el => el.classList.remove('drop-target')); }

    function handleDropOnCard(event, targetId, sectionId, targetType) {
        event.preventDefault(); event.stopPropagation();
        const draggedId = event.dataTransfer.getData('text/plain');
        if (!draggedId || draggedId === targetId) return;
        isDropped = true;
        if (dragType === 'item') {
            const draggedItem = findItemById(draggedId);
            if (targetType === 'folder' && draggedItem.type !== 'folder') moveItem(draggedId, 'folder', targetId, null);
            else moveItem(draggedId, 'section', sectionId, targetId);
        } else if (dragType === 'section') {
            if (targetType === 'folder') moveSection(draggedId, 'append_to_folder', targetId);
            else moveSection(draggedId, 'before_section', sectionId);
        }
        clearDropTargets(); renderContent(); saveDB();
    }

    function handleDropOnGrid(event, sectionId) {
        event.preventDefault(); event.stopPropagation();
        const draggedId = event.dataTransfer.getData('text/plain');
        if (!draggedId) return;
        isDropped = true;
        if (dragType === 'item') { moveItem(draggedId, 'section', sectionId, null); } 
        else if (dragType === 'section' && draggedId !== sectionId) {
            const container = findContainerOfSection(sectionId);
            if (container) {
                if (container.id && container.id.startsWith('f_')) moveSection(draggedId, 'append_to_container', 'tab_' + db.indexOf(container), 'tab');
                else moveSection(draggedId, 'append_to_container', container.id, 'folder');
            }
        }
        clearDropTargets(); renderContent(); saveDB();
    }

    function handleDropOnSectionHeader(event, targetSectionId) {
        event.preventDefault(); event.stopPropagation();
        const draggedId = event.dataTransfer.getData('text/plain');
        if (!draggedId || draggedId === targetSectionId || dragType !== 'section') return;
        isDropped = true; moveSection(draggedId, 'before_section', targetSectionId);
        clearDropTargets(); renderContent(); saveDB();
    }

    function handleDropAfterSection(event, targetSectionId) {
        event.preventDefault(); event.stopPropagation();
        const draggedId = event.dataTransfer.getData('text/plain');
        if (!draggedId || draggedId === targetSectionId || dragType !== 'section') return;
        isDropped = true; moveSection(draggedId, 'after_section', targetSectionId);
        clearDropTargets(); renderContent(); saveDB();
    }

    function handleDropOnBack(event) {
        event.preventDefault(); event.stopPropagation();
        const draggedId = event.dataTransfer.getData('text/plain');
        if (!draggedId) return;
        isDropped = true;
        if (dragType === 'item') { if (db[activeIdx].sections.length > 0) moveItem(draggedId, 'section', db[activeIdx].sections[0].id, null); } 
        else if (dragType === 'section') { moveSection(draggedId, 'append_to_parent', null); }
        clearDropTargets(); renderContent(); saveDB();
    }

    function moveItem(draggedId, targetContextType, targetContextId, beforeId) {
        let movedItem = null;
        for (let f of db) {
            for (let s of f.sections) {
                let idx = s.items.findIndex(i => i.id === draggedId);
                if (idx !== -1) { movedItem = s.items.splice(idx, 1)[0]; break; }
                for (let item of s.items) {
                    if (item.type === 'folder' && item.id !== draggedId && item.sections) {
                        for (let subS of item.sections) { let subIdx = subS.items.findIndex(i => i.id === draggedId); if (subIdx !== -1) { movedItem = subS.items.splice(subIdx, 1)[0]; break; } }
                    }
                    if (movedItem) break;
                }
            }
            if (movedItem) break;
        }
        if (!movedItem) return;

        if (targetContextType === 'section') {
            const targetSection = findSectionById(targetContextId);
            if (targetSection) {
                if (beforeId) { let idx = targetSection.items.findIndex(i => i.id === beforeId); if (idx !== -1) targetSection.items.splice(idx, 0, movedItem); else targetSection.items.push(movedItem); }
                else targetSection.items.push(movedItem);
            }
        } else if (targetContextType === 'folder') {
            const folder = findItemById(targetContextId);
            if (folder && folder.type === 'folder') {
                if (!folder.sections) folder.sections = [{id: 's_sub_' + Math.random().toString(36).substr(2, 9), title: '默认区块', items: []}];
                folder.sections[0].items.push(movedItem);
            }
        }
    }

    function moveSection(sectionId, action, targetId, containerType) {
        let movedSection = null; let sourceContainer = null;
        for (let f of db) {
            let idx = f.sections.findIndex(s => s.id === sectionId);
            if (idx !== -1) { movedSection = f.sections.splice(idx, 1)[0]; sourceContainer = f; break; }
            for (let s of f.sections) {
                for (let item of s.items) {
                    if (item.type === 'folder') { let subIdx = item.sections.findIndex(s => s.id === sectionId); if (subIdx !== -1) { movedSection = item.sections.splice(subIdx, 1)[0]; sourceContainer = item; break; } }
                }
                if (movedSection) break;
            }
            if (movedSection) break;
        }
        if (!movedSection || !sourceContainer) return;

        if (sourceContainer.sections.length === 0) sourceContainer.sections.push({ id: 's_auto_' + Math.random().toString(36).substr(2, 9), title: '默认区块', items: [] });

        if (action === 'append_to_parent') { db[activeIdx].sections.push(movedSection); } 
        else if (action === 'append_to_container') {
            if (containerType === 'folder') { const folder = findItemById(targetId); if (folder && folder.sections) folder.sections.push(movedSection); } 
            else { const tabIdx = parseInt(targetId.replace('tab_', '')); if (!isNaN(tabIdx) && db[tabIdx]) db[tabIdx].sections.push(movedSection); }
        } else if (action === 'append_to_folder') { const folder = findItemById(targetId); if (folder && folder.sections) folder.sections.push(movedSection); } 
        else if (action === 'before_section') { const targetContainer = findContainerOfSection(targetId); if (targetContainer) { let idx = targetContainer.sections.findIndex(s => s.id === targetId); if (idx !== -1) targetContainer.sections.splice(idx, 0, movedSection); else targetContainer.sections.push(movedSection); } } 
        else if (action === 'after_section') { const targetContainer = findContainerOfSection(targetId); if (targetContainer) { let idx = targetContainer.sections.findIndex(s => s.id === targetId); if (idx !== -1) targetContainer.sections.splice(idx + 1, 0, movedSection); else targetContainer.sections.push(movedSection); } }
    }

    function openAddFolderModal() { if(db.length >= 10) return showError("分栏数量已达上限 (10个)"); showUI(`<h3 style="margin-top:0">添加分栏</h3><div class="form-group"><label>分栏名称 (最多7字)</label><input type="text" id="fn" maxlength="7" placeholder="请输入名称"></div><div style="display:flex;gap:10px"><button class="btn btn-s" style="flex:1" onclick="closeUI()">取消</button><button class="btn btn-p" style="flex:1" onclick="actAddFolder()">确定</button></div>`); }
    function actAddFolder() { const n = document.getElementById('fn').value.trim(); if(!n) return; if(db.some(f => f.name === n)) return showError(`分栏名 [${n}] 已存在`); db.push({id: 'f_' + Math.random().toString(36).substr(2, 9), name: n, sections: [{ id: 's_' + Math.random().toString(36).substr(2, 9), title: '默认区块', items: [] }]}); activeIdx = db.length-1; saveRefresh(); }
    function openRenameModal(i) { showUI(`<h3 style="margin-top:0">重命名分栏</h3><div class="form-group"><label>新名称 (最多7字)</label><input type="text" id="fn" value="${db[i].name}" maxlength="7"></div><div style="display:flex;gap:10px"><button class="btn btn-s" style="flex:1" onclick="closeUI()">取消</button><button class="btn btn-p" style="flex:1" onclick="actRenameFolder(${i})">保存</button></div>`); }
    function actRenameFolder(i) { const n = document.getElementById('fn').value.trim(); if(!n || n === db[i].name) return closeUI(); if(db.some((f, idx) => f.name === n && idx !== i)) return showError(`分栏名 [${n}] 已存在`); db[i].name = n; saveRefresh(); }
    function openFolderMgr() { const list = db.map((f, i) => `<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:#f9f9f9;border-radius:12px;margin-bottom:8px"><span style="font-weight:bold;font-size:14px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${f.name}</span><div style="display:flex;gap:6px"><button class="btn btn-s" style="padding:6px 10px" onclick="moveF(${i},-1)">↑</button><button class="btn btn-s" style="padding:6px 10px" onclick="moveF(${i},1)">↓</button><button class="btn btn-d" style="padding:6px 10px" onclick="confirmDelFolder(${i})">删</button></div></div>`).join(''); showUI(`<h3 style="margin-top:0">分栏管理</h3><div style="max-height:300px;overflow-y:auto;margin-bottom:15px;">${list}</div><button class="btn btn-s" style="width:100%" onclick="closeUI()">关闭</button>`); }
    function actDelFolder(i) { db.splice(i, 1); if (activeIdx >= db.length) activeIdx = db.length - 1; localStorage.setItem('activeIdx', activeIdx); saveDB(); renderTabs(); renderContent(); openFolderMgr(); }
    function moveF(i, dir) { const t = i + dir; if (t < 0 || t >= db.length) return; const curId = db[activeIdx].id; [db[i], db[t]] = [db[t], db[i]]; activeIdx = db.findIndex(f => f.id === curId); localStorage.setItem('activeIdx', activeIdx); saveDB(); renderTabs(); openFolderMgr(); }
    function confirmDelFolder(i) { if(db.length <= 1) return showError("至少需要保留一个分栏"); showUI(`<h3 style="margin-top:0;color:var(--danger)">删除分栏？</h3><p style="color:#555;">分栏 [<b>${db[i].name}</b>] 及其内部所有专区和图标将永久丢失。</p><div style="display:flex;gap:10px;margin-top:15px;"><button class="btn btn-s" style="flex:1" onclick="openFolderMgr()">取消</button><button class="btn btn-d" style="flex:1" onclick="actDelFolder(${i})">确认删除</button></div>`); }

    function addNewSection() { getCurrentContext().sections.push({ id: 's_' + Math.random().toString(36).substr(2, 9), title: '新区块', items: [] }); saveRefresh(); }
    function editSection(sectionId) { const section = getCurrentContext().sections.find(s => s.id === sectionId); if (!section) return; showUI(`<h3 style="margin-top:0">重命名区块</h3><div class="form-group"><label>区块名称 (最多7字)</label><input type="text" id="sn" value="${section.title}" maxlength="7"></div><div style="display:flex;gap:10px"><button class="btn btn-s" style="flex:1" onclick="closeUI()">取消</button><button class="btn btn-p" style="flex:1" onclick="actRenameSection('${sectionId}')">保存</button></div>`); }
    function actRenameSection(sectionId) { const n = document.getElementById('sn').value.trim(); if (!n) return showError("名称不能为空"); const section = getCurrentContext().sections.find(s => s.id === sectionId); if (section) { section.title = n; saveRefresh(); } }
    function deleteSection(sectionId) { showUI(`<h3 style="margin-top:0;color:var(--danger)">删除区块？</h3><p style="color:#555;">该区块内的所有图标和专区将被永久删除。</p><div style="display:flex;gap:10px;margin-top:15px;"><button class="btn btn-s" style="flex:1" onclick="closeUI()">取消</button><button class="btn btn-d" style="flex:1" onclick="actDeleteSection('${sectionId}')">确认删除</button></div>`); }
    function actDeleteSection(sectionId) { const ctx = getCurrentContext(); ctx.sections = ctx.sections.filter(s => s.id !== sectionId); saveRefresh(); }
    function openSectionMgr() { const ctx = getCurrentContext(); const list = ctx.sections.map(s => `<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:#f9f9f9;border-radius:12px;margin-bottom:8px"><span style="font-weight:bold;font-size:14px;">${s.title}</span><div style="display:flex;gap:6px"><button class="btn btn-s" style="padding:6px 10px" onclick="closeUI();setTimeout(()=>editSection('${s.id}'),100)">✎</button>${ctx.sections.length > 1 ? `<button class="btn btn-d" style="padding:6px 10px" onclick="closeUI();setTimeout(()=>deleteSection('${s.id}'),100)">删</button>` : ''}</div></div>`).join(''); showUI(`<h3 style="margin-top:0">区块管理</h3><p style="font-size:12px;color:#888;margin-bottom:10px;">当前: ${activeFolder ? '专区 ['+activeFolder.name+']' : '分栏 ['+db[activeIdx].name+']'}</p><div style="max-height:300px;overflow-y:auto;margin-bottom:15px;">${list}</div><button class="btn btn-p" style="width:100%;margin-bottom:10px;" onclick="closeUI();setTimeout(addNewSection,100)">+ 添加新区块</button><button class="btn btn-s" style="width:100%" onclick="closeUI()">关闭</button>`); }

    function openAddModalToSection(sectionId) { window.tempTargetSectionId = sectionId; openAddModal(null); }
    
    function openAddModal(existingItem = null) {
        const isNew = !existingItem;
        const initialType = existingItem ? existingItem.type : 'link';
        window.currentEditingItem = existingItem; 
        const ctx = getCurrentContext();
        const defaultTarget = window.tempTargetSectionId || (isNew && ctx.sections.length===1 ? ctx.sections[0].id : '');
        window.tempTargetSectionId = null;
        const sectionOptions = ctx.sections.map(s => `<option value="${s.id}" ${s.id === defaultTarget ? 'selected' : ''}>${s.title}</option>`).join('');

        const typeSelector = isNew ? `<div class="form-group"><label>类型</label><select id="mType" onchange="toggleFormFields()"><option value="link" ${initialType==='link'?'selected':''}>🔗 链接</option><option value="folder" ${initialType==='folder'?'selected':''}>📁 专区</option></select></div>` : `<input type="hidden" id="mType" value="${initialType}">`;

        showUI(`
            <h3 style="margin-top:0;text-align:center;">${isNew ? '添加项目' : '编辑项目'}</h3>
            <div id="previewContainer" style="text-align:center;">
                ${initialType === 'folder' ? renderFolderIconHTML(existingItem, 'modal-preview') : `<img id="pI" src="${existingItem ? (existingItem.icon || '/icons/default.png') : '/icons/default.png'}" style="width:55px;height:55px;display:block;margin:0 auto 15px;background:#f8f8f8;padding:10px;border-radius:16px;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.05);">`}
            </div>
            <input type="hidden" id="tP" value="${existingItem ? (existingItem.icon || '') : ''}">
            ${typeSelector}
            ${isNew ? `<div class="form-group"><label>添加到区块</label><select id="targetSection">${sectionOptions}</select></div>` : ''}
            <div class="form-group"><label>名称 (最多7字)</label><input type="text" id="mN" value="${existingItem ? existingItem.name : ''}" maxlength="7" placeholder="输入名称"></div>
            <div class="form-group" id="urlGroup" ${initialType==='folder' ? 'style="display:none"' : ''}><label>链接 / 端口</label><div style="display:flex;gap:8px"><input type="text" id="mU" value="${existingItem ? (existingItem.url || '') : ''}" placeholder="http:// 或 端口号"><button class="btn btn-s" id="fBtn" onclick="autoFetch()" style="white-space:nowrap;">抓取</button></div></div>
            <div class="form-group" id="uploadGroup" ${initialType==='folder' ? 'style="display:none"' : ''}><label>自定义图片</label><input type="file" id="mF" accept="image/*" onchange="uploadFile()"></div>
            <div style="display:flex;gap:10px;margin-top:20px;"><button class="btn btn-s" style="flex:1" onclick="closeUI()">取消</button><button class="btn btn-p" id="saveBtn" style="flex:1" onclick="saveItem(${isNew}, '${existingItem ? existingItem.id : 'new'}')">保存</button></div>
        `); 
    }

    function toggleFormFields() {
        const type = document.getElementById('mType').value;
        const isFolder = type === 'folder';
        document.getElementById('urlGroup').style.display = isFolder ? 'none' : 'block';
        document.getElementById('uploadGroup').style.display = isFolder ? 'none' : 'block';
        const previewContainer = document.getElementById('previewContainer');
        if (isFolder) { previewContainer.innerHTML = renderFolderIconHTML(window.currentEditingItem || {sections:[]}, 'modal-preview'); } 
        else {
            const currentIcon = window.currentEditingItem ? (window.currentEditingItem.icon || '/icons/default.png') : '/icons/default.png';
            previewContainer.innerHTML = `<img id="pI" src="${currentIcon}" style="width:55px;height:55px;display:block;margin:0 auto 15px;background:#f8f8f8;padding:10px;border-radius:16px;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.05);">`;
            document.getElementById('tP').value = currentIcon;
        }
    }

    function saveItem(isNew, id) {
        const n = document.getElementById('mN').value.trim();
        const type = document.getElementById('mType').value;
        const u = type === 'link' ? document.getElementById('mU').value.trim() : '';
        const p = document.getElementById('tP').value;
        const targetSectionId = isNew ? document.getElementById('targetSection').value : null;
        if(!n) return showError("名称不能为空");
        if (isNew) {
            const section = getCurrentContext().sections.find(s => s.id === targetSectionId);
            if (!section) return showError("未找到目标区块");
            const newItem = { id: 'i_' + Math.random().toString(36).substr(2, 9), name: n, type: type, url: u };
            if (type === 'link') newItem.icon = p || '/icons/default.png';
            if (type === 'folder') newItem.sections = [{ id: 's_sub_' + Math.random().toString(36).substr(2, 9), title: '默认区块', items: [] }];
            section.items.push(newItem);
        } else {
            const item = findItemById(id);
            if (item) {
                item.name = n; item.type = type; item.url = u;
                if (type === 'link') { item.icon = p || item.icon || '/icons/default.png'; delete item.sections; } 
                else if (type === 'folder') { delete item.icon; if(!item.sections) item.sections = [{ id: 's_sub_' + Math.random().toString(36).substr(2, 9), title: '默认区块', items: [] }]; }
            }
        }
        saveRefresh();
    }

    async function uploadFile() { const f = document.getElementById('mF').files[0]; if(!f) return; setModalLoading(true); const fd = new FormData(); fd.append('image', f); try { const res = await fetch('?action=upload',{method:'POST',body:fd}); const d = await res.json(); if(d.status==='ok'){ document.getElementById('pI').src = d.path; document.getElementById('tP').value = d.path; } } finally { setModalLoading(false); } }
    
    async function autoFetch() {
        const u = document.getElementById('mU').value.trim();
        if (!u) return;

        const fBtn = document.getElementById('fBtn');
        const origText = fBtn.innerText;
        fBtn.innerText = "探测中..."; fBtn.disabled = true;

        let targetUrl = u;
        if (!targetUrl.startsWith('http')) targetUrl = 'http://' + targetUrl;

        try {
            await fetch(targetUrl, { method: 'HEAD', mode: 'no-cors' });
        } catch (e) {
            showToast("目标站点无法抵达", "error");
            fBtn.innerText = origText; fBtn.disabled = false;
            return;
        }

        fBtn.innerText = "获取中...";
        try {
            const res = await fetch('?action=fetch_icon_list', { method: 'POST', body: JSON.stringify({ url: targetUrl }) });
            const d = await res.json();
            if (d.status === 'ok' && d.icons.length > 0) {
                showIconPicker(d.icons);
            } else {
                showToast("未找到可用图标", "error");
            }
        } catch (e) {
            showToast("获取图标列表失败", "error");
        } finally {
            fBtn.innerText = origText; fBtn.disabled = false;
        }
    }

    function showIconPicker(icons) {
        const previewContainer = document.getElementById('previewContainer');
        document.getElementById('uploadGroup').style.display = 'none';
        
        let gridHtml = '<div class="icon-picker-grid">';
        icons.forEach((url, index) => {
            gridHtml += `<div class="icon-picker-item" data-url="${url}" onclick="selectPickerIcon(this)" style="display:none;">
                <img src="${url}" onload="this.parentElement.style.display='flex'; if(!document.querySelector('.icon-picker-item.selected')) this.parentElement.click();" onerror="this.parentElement.remove();">
            </div>`;
        });
        gridHtml += '</div><div style="display:flex;gap:10px;"><button class="btn btn-s" style="flex:1" onclick="cancelIconPicker()">取消</button><button class="btn btn-p" style="flex:1" onclick="applyPickerIcon()">应用</button></div>';
        
        previewContainer.innerHTML = gridHtml;
    }

    function selectPickerIcon(el) {
        document.querySelectorAll('.icon-picker-item').forEach(i => i.classList.remove('selected'));
        el.classList.add('selected');
    }

    function cancelIconPicker() {
        const initialType = document.getElementById('mType').value;
        const previewContainer = document.getElementById('previewContainer');
        const currentIcon = window.currentEditingItem ? (window.currentEditingItem.icon || '/icons/default.png') : '/icons/default.png';
        if (initialType === 'folder') { previewContainer.innerHTML = renderFolderIconHTML(window.currentEditingItem || {sections:[]}, 'modal-preview'); } 
        else { previewContainer.innerHTML = `<img id="pI" src="${currentIcon}" style="width:55px;height:55px;display:block;margin:0 auto 15px;background:#f8f8f8;padding:10px;border-radius:16px;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.05);">`; }
        document.getElementById('uploadGroup').style.display = 'block';
    }

    async function applyPickerIcon() {
        const selected = document.querySelector('.icon-picker-item.selected');
        if (!selected) { showToast("请先选择一个图标", "error"); return; }
        
        const url = selected.getAttribute('data-url');
        const fBtn = document.getElementById('fBtn');
        fBtn.innerText = "下载中..."; fBtn.disabled = true;

        try {
            const res = await fetch('?action=download_icon', { method: 'POST', body: JSON.stringify({ url: url }) });
            const d = await res.json();
            if (d.status === 'ok') {
                document.getElementById('tP').value = d.path;
                const previewContainer = document.getElementById('previewContainer');
                previewContainer.innerHTML = `<img id="pI" src="${d.path}" style="width:55px;height:55px;display:block;margin:0 auto 15px;background:#f8f8f8;padding:10px;border-radius:16px;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.05);">`;
                document.getElementById('uploadGroup').style.display = 'block';
                showToast("图标应用成功", "success");
            } else {
                showToast("图标下载失败", "error");
            }
        } catch (e) {
            showToast("网络请求失败", "error");
        } finally {
            fBtn.innerText = "抓取"; fBtn.disabled = false;
        }
    }

    function setModalLoading(isLoading) { const btn = document.getElementById('saveBtn'); const fBtn = document.getElementById('fBtn'); if(btn) btn.disabled = isLoading; if(fBtn && fBtn.innerText !== "抓取") fBtn.innerText = isLoading ? "..." : "抓取"; }
    
    function saveRefresh() { saveDB(); closeUI(); renderTabs(); renderContent(); }
    function showUI(h){ document.getElementById('uiModalBody').innerHTML=h; const modal = document.getElementById('uiModal'); modal.style.display='flex'; setTimeout(() => modal.classList.add('show'), 10); }
    function closeUI(){ const modal = document.getElementById('uiModal'); modal.classList.remove('show'); setTimeout(() => modal.style.display='none', 200); }
    function switchFolder(i){ activeIdx=i; activeFolder = null; localStorage.setItem('activeIdx',i); pendingEditItem = null; selectedIds.clear(); renderTabs(); renderContent(); }
    
    async function updateWallpaper() { const b = event.target; const orig = b.innerText; b.innerText = "更新中..."; b.disabled = true; try { const res = await fetch('?action=update_bg', {method:'POST'}); if((await res.json()).status==='ok') location.reload(); else { b.innerText = "失败"; showToast("壁纸更新失败", "error"); } } catch(e) { b.innerText = "失败"; } finally { b.disabled = false; if(b.innerText==="失败") setTimeout(()=>b.innerText=orig, 2000); } }
    
    function saveDB() {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(async () => {
            const curString = JSON.stringify(db);
            if (curString === lastSavedDbString) return;
            lastSavedDbString = curString;
            try { await fetch('?action=save', { method: 'POST', body: curString }); } catch (e) { console.error('Save failed:', e); }
        }, 250);
    }

    function toggleDrawer() { document.getElementById('drawer').classList.toggle('open'); document.getElementById('drawerOverlay').classList.toggle('show'); }
    function exportData() { const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(db, null, 2)); const a = document.createElement('a'); a.href = dataStr; a.download = "dashboard_backup_" + new Date().toISOString().slice(0,10) + ".json"; document.body.appendChild(a); a.click(); a.remove(); }
    
    function importData(file) { 
        if (!file) return; 
        const reader = new FileReader(); 
        reader.onload = function(e) { 
            try { 
                const imported = JSON.parse(e.target.result); 
                if (Array.isArray(imported)) { 
                    db = imported; upgradeStructureJS(db); lastSavedDbString = JSON.stringify(db); 
                    activeIdx = Math.min(activeIdx, db.length - 1); activeFolder = null; 
                    saveRefresh(); toggleDrawer(); showToast("✅ 数据导入成功！", "success");
                } else showToast("无效的 JSON 格式", "error"); 
            } catch (err) { showToast("解析 JSON 失败: " + err.message, "error"); } 
        }; 
        reader.readAsText(file); 
    }

    document.addEventListener('dragenter', (e) => {
        if (e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.includes('Files')) {
            e.preventDefault();
            if (!document.getElementById('drawer').classList.contains('open')) toggleDrawer();
            const dz = document.getElementById('dropZone'); if(dz) dz.classList.add('drag-over');
        }
    });

    document.addEventListener('dragover', (e) => {
        if (e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.includes('Files')) {
            e.preventDefault();
            const dz = document.getElementById('dropZone'); if(dz) dz.classList.add('drag-over');
            return;
        }

        if (!isMoveMode || (!currentlyDraggedId && !currentlyDraggedSectionId)) return;
        e.preventDefault();

        if (e.clientY < scrollThreshold) startAutoScroll(-1);
        else if (e.clientY > window.innerHeight - scrollThreshold) startAutoScroll(1);
        else stopAutoScroll();

        const el = document.elementFromPoint(e.clientX, e.clientY);
        if (!el) return;

        const tab = el.closest('.tab');
        if (tab) {
            const tabs = document.querySelectorAll('.tab');
            const idx = Array.from(tabs).indexOf(tab);
            if (idx !== -1 && idx !== activeIdx && idx < db.length) {
                if (currentHoverTabIdx !== idx) {
                    clearTimeout(tabHoverTimer);
                    currentHoverTabIdx = idx;
                    tabs.forEach(t => t.classList.remove('drag-hover'));
                    tab.classList.add('drag-hover');
                    tabHoverTimer = setTimeout(() => {
                        activeIdx = idx; activeFolder = null; localStorage.setItem('activeIdx', idx);
                        renderTabs(); renderContent();
                    }, 800);
                }
                return;
            }
        }

        const backBtn = el.closest('#backBtn');
        if (backBtn && activeFolder) {
            if (!currentHoverBack) {
                clearTimeout(backHoverTimer);
                currentHoverBack = true;
                backBtn.classList.add('drag-hover');
                backHoverTimer = setTimeout(() => { activeFolder = null; renderContent(); }, 800);
            }
            return;
        }

        if (currentHoverTabIdx !== -1) {
            clearTimeout(tabHoverTimer);
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('drag-hover'));
            currentHoverTabIdx = -1;
        }
        if (currentHoverBack) {
            clearTimeout(backHoverTimer);
            const btn = document.getElementById('backBtn');
            if (btn) btn.classList.remove('drag-hover');
            currentHoverBack = false;
        }
    });

    document.addEventListener('dragleave', (e) => {
        const dz = document.getElementById('dropZone'); if(dz) dz.classList.remove('drag-over');
    });

    document.addEventListener('dragend', (e) => {
        stopAutoScroll();
        if (!isDropped) {
            if (dragType === 'item' && currentlyDraggedId) {
                const targetSection = activeFolder ? activeFolder.sections[0] : db[activeIdx].sections[0];
                if (targetSection) moveItem(currentlyDraggedId, 'section', targetSection.id, null);
            } else if (dragType === 'section' && currentlyDraggedSectionId) {
                const container = getCurrentContext();
                if (container && container.sections) {
                    let movedSec = null;
                    for(let f of db) {
                        let idx = f.sections.findIndex(s=>s.id===currentlyDraggedSectionId);
                        if(idx!==-1) { movedSec = f.sections.splice(idx,1)[0]; break; }
                        for(let s of f.sections) for(let it of s.items) if(it.type==='folder') { let si = it.sections.findIndex(x=>x.id===currentlyDraggedSectionId); if(si!==-1){movedSec=it.sections.splice(si,1)[0];break;} }
                    }
                    if(movedSec) container.sections.push(movedSec);
                }
            }
            saveDB();
            renderContent();
        }
        
        dragType = null; currentlyDraggedId = null; currentlyDraggedSectionId = null; isDropped = false;
        document.querySelectorAll('.dragging').forEach(el => el.classList.remove('dragging'));
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('drag-hover'));
        const backBtn = document.getElementById('backBtn');
        if (backBtn) backBtn.classList.remove('drag-hover');
        clearDropTargets();
    });

    document.addEventListener('drop', (e) => {
        e.preventDefault();
        stopAutoScroll();
        const dz = document.getElementById('dropZone'); if(dz) dz.classList.remove('drag-over');
        if (e.dataTransfer.files.length > 0) {
            const file = e.dataTransfer.files[0];
            if (file.name.endsWith('.json')) importData(file);
            else showToast("请拖入 .json 格式文件", "error");
        }
    });

    renderTabs(); renderContent();
</script>
</body>
</html>