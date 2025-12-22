<?php
// --- KONFIGURACJA I BACKEND (PHP) ---
session_start();
require_once 'config.php';

define('UPLOAD_BASE_DIR', __DIR__ . '/uploads/');
if (!is_dir(UPLOAD_BASE_DIR)) { @mkdir(UPLOAD_BASE_DIR, 0777, true); }

// --- BAZA DANYCH (MIGRACJE) ---
try {
    $check = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($check->rowCount() == 0) {
        $pdo->exec("CREATE TABLE `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            `theme` VARCHAR(20) DEFAULT 'light',
            `accent_color` VARCHAR(10) DEFAULT '#1f6feb',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $hash = password_hash('admin123#', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password, role) VALUES ('admin', ?, 'admin')")->execute([$hash]);
    }
    try { $pdo->exec("ALTER TABLE users ADD COLUMN default_section_id INT UNSIGNED DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE notes ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1"); } catch (Exception $e) {} 
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sections` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL, `name` VARCHAR(100) NOT NULL, `color` VARCHAR(20) DEFAULT '#cccccc', PRIMARY KEY (`id`), KEY `user_id` (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tags` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL, `name` VARCHAR(100) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `user_tag` (`user_id`, `name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `note_tags` (`note_id` INT UNSIGNED NOT NULL, `tag_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`note_id`, `tag_id`), CONSTRAINT `fk_nt_note` FOREIGN KEY (`note_id`) REFERENCES `notes` (`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try { $pdo->exec("ALTER TABLE `notes` ADD COLUMN `section_id` INT UNSIGNED DEFAULT NULL"); } catch (Exception $e) {} 
    try { $pdo->exec("ALTER TABLE `notes` ADD COLUMN `is_deleted` TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `notes` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL"); } catch (Exception $e) {}
    
    $pdo->exec("UPDATE notes SET is_deleted = 0 WHERE is_deleted IS NULL");

    $oldTrash = $pdo->query("SELECT id, unique_id FROM notes WHERE is_deleted = 1 AND deleted_at < (NOW() - INTERVAL 30 DAY)")->fetchAll(PDO::FETCH_ASSOC);
    if ($oldTrash) {
        foreach ($oldTrash as $ot) {
            $dir = UPLOAD_BASE_DIR . $ot['unique_id'] . '/';
            if (is_dir($dir)) { array_map('unlink', glob("$dir/*.*")); rmdir($dir); }
            $pdo->prepare("DELETE FROM notes WHERE id = ?")->execute([$ot['id']]);
        }
    }
} catch (Exception $e) { }

// --- API ---
if (isset($_GET['action']) || isset($_POST['action'])) {
    if (($_GET['action'] ?? '') !== 'export_xml') { ob_clean(); header('Content-Type: application/json; charset=utf-8'); }
    $response = ['success' => false, 'message' => 'Błąd'];
    $params = array_merge($_POST, $_GET);
    $action = $params['action'] ?? '';

    if ($action === 'login') {
        $user = $params['username'] ?? ''; $pass = $params['password'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?"); $stmt->execute([$user]); $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && password_verify($pass, $u['password'])) {
            $_SESSION['user_id'] = $u['id']; $_SESSION['username'] = $u['username']; $_SESSION['role'] = $u['role'];
            $_SESSION['theme'] = $u['theme'] ?? 'light'; $_SESSION['accent_color'] = $u['accent_color'] ?? '#1f6feb';
            $_SESSION['default_section_id'] = $u['default_section_id'];
            echo json_encode(['success' => true]);
        } else { echo json_encode(['success' => false, 'message' => 'Błąd logowania']); } exit;
    }

    if (!isset($_SESSION['user_id'])) { if ($action !== 'export_xml') echo json_encode(['success' => false, 'message' => 'Brak autoryzacji']); exit; }
    $userId = $_SESSION['user_id']; $userRole = $_SESSION['role'];

    try {
        switch ($action) {
            case 'logout': session_destroy(); $response = ['success' => true]; break;
            case 'read_notes':
                $q = isset($params['q']) ? trim((string)$params['q']) : '';
                $sort = isset($params['sort']) && strtoupper($params['sort']) === 'ASC' ? 'ASC' : 'DESC';
                $trashMode = !empty($params['trash']) && ($params['trash'] == 1 || $params['trash'] == 'true');
                $sql = "SELECT n.id as db_id, n.unique_id AS id, n.title, n.created_at, n.updated_at, n.content, s.name as section_name, s.color as section_color FROM notes n LEFT JOIN sections s ON n.section_id = s.id WHERE n.user_id = ?";
                $args = [$userId];
                if ($trashMode) $sql .= " AND n.is_deleted = 1";
                else {
                    $sql .= " AND n.is_deleted = 0";
                    if (!empty($params['section_id'])) { $sql .= " AND n.section_id = ?"; $args[] = $params['section_id']; }
                }
                $sql .= " ORDER BY COALESCE(n.updated_at, n.created_at) " . $sort;
                $stmt = $pdo->prepare($sql); $stmt->execute($args); $allNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $tagsQuery = $pdo->prepare("SELECT nt.note_id, t.name FROM note_tags nt JOIN tags t ON nt.tag_id = t.id JOIN notes n ON nt.note_id = n.id WHERE n.user_id = ?");
                $tagsQuery->execute([$userId]); $tagsMap = []; while($row = $tagsQuery->fetch(PDO::FETCH_ASSOC)) { $tagsMap[$row['note_id']][] = $row['name']; }
                $filteredNotes = [];
                if ($q === '') { $filteredNotes = $allNotes; } else {
                    $keywords = array_filter(explode(' ', $q));
                    foreach ($allNotes as $note) {
                        $rawContent = (string)$note['content'];
                        $rawContent = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</tr>', '</h1>', '</h2>', '</h3>'], ' ', $rawContent);
                        $cleanText = strip_tags($rawContent); $cleanText = html_entity_decode($cleanText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $title = html_entity_decode((string)$note['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $tagsStr = implode(' ', $tagsMap[$note['db_id']] ?? []);
                        $haystack = $title . ' ' . $cleanText . ' ' . $tagsStr;
                        $allMatch = true;
                        foreach ($keywords as $word) { if (mb_stripos($haystack, $word, 0, 'UTF-8') === false) { $allMatch = false; break; } }
                        if ($allMatch) $filteredNotes[] = $note;
                    }
                }
                foreach ($filteredNotes as &$note) {
                    $stmt_att = $pdo->prepare("SELECT COUNT(*) FROM attachments WHERE note_unique_id = ?"); $stmt_att->execute([$note['id']]);
                    $note['has_attachments'] = ($stmt_att->fetchColumn() > 0);
                    $note['tags'] = $tagsMap[$note['db_id']] ?? [];
                    $d = new DateTime($note['updated_at'] ? $note['updated_at'] : $note['created_at']); $note['display_date'] = $d->format('d.m.Y H:i');
                    unset($note['db_id']);
                }
                $response = ['success' => true, 'notes' => array_values($filteredNotes)]; break;
            case 'read_note':
                $id = $params['id'] ?? '';
                $stmt = $pdo->prepare("SELECT n.id as db_id, n.unique_id AS id, n.title, n.content, n.created_at, n.updated_at, n.section_id, n.is_deleted FROM notes n WHERE n.unique_id = ? AND n.user_id = ?");
                $stmt->execute([$id, $userId]); $note = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($note) {
                    $stmt_att = $pdo->prepare("SELECT id, file_name AS name FROM attachments WHERE note_unique_id = ?"); $stmt_att->execute([$id]);
                    $note['attachments'] = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
                    $stmtTags = $pdo->prepare("SELECT t.name FROM tags t JOIN note_tags nt ON t.id = nt.tag_id WHERE nt.note_id = ?"); $stmtTags->execute([$note['db_id']]);
                    $note['tags'] = $stmtTags->fetchAll(PDO::FETCH_COLUMN); unset($note['db_id']);
                    $response = ['success' => true, 'note' => $note];
                } else $response = ['success' => false, 'message' => 'Brak']; break;
            case 'save_note':
                $id = $params['id']; $title = $params['title']; $content = $params['content']; $sec = !empty($params['section_id']) ? $params['section_id'] : null;
                $check = $pdo->prepare("SELECT id, is_deleted FROM notes WHERE unique_id=? AND user_id=?"); $check->execute([$id, $userId]); $row = $check->fetch();
                if($row && $row['is_deleted']==1) throw new Exception("Kosz!");
                if($row) { $pdo->prepare("UPDATE notes SET title=?, content=?, section_id=?, updated_at=NOW() WHERE id=?")->execute([$title, $content, $sec, $row['id']]); $nid = $row['id']; }
                else { $pdo->prepare("INSERT INTO notes (unique_id, user_id, title, content, section_id, created_at) VALUES (?,?,?,?,?,NOW())")->execute([$id, $userId, $title, $content, $sec]); $nid = $pdo->lastInsertId(); }
                $pdo->prepare("DELETE FROM note_tags WHERE note_id=?")->execute([$nid]);
                if(!empty($params['tags'])) {
                    $tArr = explode(',', $params['tags']); $insT = $pdo->prepare("INSERT INTO tags (user_id, name) VALUES (?,?)"); $selT = $pdo->prepare("SELECT id FROM tags WHERE user_id=? AND name=?"); $lnk = $pdo->prepare("INSERT IGNORE INTO note_tags (note_id, tag_id) VALUES (?,?)");
                    foreach($tArr as $t) { $t=trim($t); if(!$t) continue; $selT->execute([$userId, $t]); $tid=$selT->fetchColumn(); if(!$tid) { $insT->execute([$userId, $t]); $tid=$pdo->lastInsertId(); } $lnk->execute([$nid, $tid]); }
                }
                $response = ['success' => true, 'id' => $id]; break;
            case 'delete_note': $pdo->prepare("UPDATE notes SET is_deleted=1, deleted_at=NOW() WHERE unique_id=? AND user_id=?")->execute([$params['id'], $userId]); $response = ['success' => true]; break;
            case 'restore_note': $pdo->prepare("UPDATE notes SET is_deleted=0, deleted_at=NULL WHERE unique_id=? AND user_id=?")->execute([$params['id'], $userId]); $response = ['success' => true]; break;
            case 'perm_delete_note': $id=$params['id']; $dir=UPLOAD_BASE_DIR.$id.'/'; if(is_dir($dir)){array_map('unlink',glob("$dir/*.*"));rmdir($dir);} $pdo->prepare("DELETE FROM notes WHERE unique_id=? AND user_id=?")->execute([$id,$userId]); $response=['success'=>true]; break;
            case 'empty_trash': $l=$pdo->prepare("SELECT unique_id FROM notes WHERE user_id=? AND is_deleted=1"); $l->execute([$userId]); while($r=$l->fetch()){$d=UPLOAD_BASE_DIR.$r['unique_id'].'/';if(is_dir($d)){array_map('unlink',glob("$d/*.*"));rmdir($d);}} $pdo->prepare("DELETE FROM notes WHERE user_id=? AND is_deleted=1")->execute([$userId]); $response=['success'=>true]; break;
            case 'upload_image':
                $uid=$params['note_id']; if(!isset($_FILES['image'])) throw new Exception("Brak pliku");
                $d = UPLOAD_BASE_DIR . $uid . '/images/'; if(!is_dir($d)) mkdir($d,0777,true);
                $fn=uniqid().'.'.(pathinfo($_FILES['image']['name'],PATHINFO_EXTENSION)?:'png'); move_uploaded_file($_FILES['image']['tmp_name'], $d.$fn);
                $response=['success'=>true,'url'=>"uploads/$uid/images/$fn"]; break;
            case 'upload_attachment':
                $uid=$params['id']; $d=UPLOAD_BASE_DIR.$uid.'/'; if(!is_dir($d)) mkdir($d,0777,true);
                foreach($_FILES['files']['name'] as $i=>$n) { move_uploaded_file($_FILES['files']['tmp_name'][$i], $d.basename($n)); $pdo->prepare("INSERT IGNORE INTO attachments (note_unique_id,file_name,file_path) VALUES (?,?,?)")->execute([$uid,basename($n),$d.basename($n)]); }
                $response=['success'=>true]; break;
            case 'delete_attachment': $uid=$params['note_id']; $f=$params['file_name']; if(file_exists(UPLOAD_BASE_DIR."$uid/$f")) unlink(UPLOAD_BASE_DIR."$uid/$f"); $pdo->prepare("DELETE FROM attachments WHERE note_unique_id=? AND file_name=?")->execute([$uid,$f]); $response=['success'=>true]; break;
            case 'get_metadata': 
                $sql = "SELECT s.id, s.name, s.color, (SELECT COUNT(n.id) FROM notes n WHERE n.section_id = s.id AND n.is_deleted = 0) as count FROM sections s WHERE s.user_id = ? ORDER BY s.name";
                $secs = $pdo->prepare($sql); $secs->execute([$userId]);
                $total = $pdo->prepare("SELECT COUNT(id) FROM notes WHERE user_id = ? AND is_deleted = 0"); $total->execute([$userId]);
                $response=['success'=>true, 'sections'=>$secs->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total->fetchColumn(), 'default_section_id' => $_SESSION['default_section_id']]; break;
            case 'save_settings': 
                $ds = !empty($params['default_section_id']) ? $params['default_section_id'] : null;
                $pdo->prepare("UPDATE users SET theme=?, accent_color=?, default_section_id=? WHERE id=?")->execute([$params['theme'], $params['accent_color'], $ds, $userId]); 
                $_SESSION['theme']=$params['theme']; $_SESSION['accent_color']=$params['accent_color']; $_SESSION['default_section_id'] = $ds;
                $response=['success'=>true]; break;
            case 'add_section': $pdo->prepare("INSERT INTO sections (user_id,name,color) VALUES (?,?,?)")->execute([$userId, $params['name'], $params['color']]); $response=['success'=>true]; break;
            case 'delete_empty_sections': $pdo->prepare("DELETE FROM sections WHERE user_id=? AND id NOT IN (SELECT DISTINCT section_id FROM notes WHERE section_id IS NOT NULL)")->execute([$userId]); $response=['success'=>true]; break;
            case 'change_password': $h=password_hash($params['new_password'], PASSWORD_DEFAULT); $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$h, $userId]); $response=['success'=>true]; break;
            case 'get_users': if($userRole!='admin') throw new Exception("Brak praw"); $response=['success'=>true, 'users'=>$pdo->query("SELECT id,username,role FROM users")->fetchAll(PDO::FETCH_ASSOC)]; break;
            case 'add_user': if($userRole!='admin') throw new Exception("Brak praw"); $h=password_hash($params['password'], PASSWORD_DEFAULT); $pdo->prepare("INSERT INTO users (username,password,role) VALUES (?,?,?)")->execute([$params['username'], $h, $params['role']]); $response=['success'=>true]; break;
            case 'edit_user': if($userRole!='admin') throw new Exception("Brak praw"); $pdo->prepare("UPDATE users SET username=?, role=? WHERE id=?")->execute([$params['username'], $params['role'], $params['target_id']]); $response=['success'=>true]; break;
            case 'delete_user': if($userRole!='admin') throw new Exception("Brak praw"); $pdo->prepare("DELETE FROM notes WHERE user_id=?")->execute([$params['target_id']]); $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$params['target_id']]); $response=['success'=>true]; break;
            case 'admin_change_pass': if($userRole!='admin') throw new Exception("Brak praw"); $h=password_hash($params['new_password'], PASSWORD_DEFAULT); $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$h, $params['target_id']]); $response=['success'=>true]; break;
            case 'import_xml': 
                if (empty($_FILES['xml_file']['tmp_name'])) throw new Exception("Brak pliku"); 
                $c = file_get_contents($_FILES['xml_file']['tmp_name']); $xml = simplexml_load_string($c, 'SimpleXMLElement', LIBXML_NOCDATA); if(!$xml) throw new Exception("Zły XML"); 
                $cnt=0; $secMap=[]; if(isset($xml->sections->section)){
                    $is=$pdo->prepare("INSERT INTO sections(user_id,name,color)VALUES(?,?,?)"); $cs=$pdo->prepare("SELECT id FROM sections WHERE user_id=? AND name=?");
                    foreach($xml->sections->section as $s){ $cs->execute([$userId,(string)$s['name']]); $eid=$cs->fetchColumn(); if($eid)$secMap[(string)$s['id']]=$eid; else{$is->execute([$userId,(string)$s['name'],(string)$s['color']]);$secMap[(string)$s['id']]=$pdo->lastInsertId();} }
                } 
                $in=$pdo->prepare("INSERT INTO notes(unique_id,user_id,section_id,title,content,created_at,updated_at)VALUES(?,?,?,?,?,?,?)"); 
                foreach($xml->note as $n){ $sid=$secMap[(string)$n['section']]??null; $createdRaw = (string)$n['created']; $createdDate = DateTime::createFromFormat('Ymd\THis', $createdRaw); $created = $createdDate ? $createdDate->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'); $updatedRaw = (string)$n['modified']; $updatedDate = DateTime::createFromFormat('Ymd\THis', $updatedRaw); $updated = $updatedDate ? $updatedDate->format('Y-m-d H:i:s') : null; $in->execute([uniqid().'-'.substr(str_shuffle('0123456789'),0,6),$userId,$sid,(string)$n['title'],nl2br((string)$n),$created,$updated]); $cnt++; } 
                $response=['success'=>true,'imported_count'=>$cnt]; break;
            case 'export_xml': 
                ob_clean(); $sid = $params['section_id']??null; $sql="SELECT * FROM notes WHERE user_id=? AND is_deleted=0"; $args=[$userId]; if($sid){$sql.=" AND section_id=?"; $args[]=$sid;} $notes=$pdo->prepare($sql); $notes->execute($args); $dom=new DOMDocument('1.0','UTF-8'); $root=$dom->createElement('notebook'); $dom->appendChild($root); 
                $secSql = "SELECT name, color FROM sections WHERE user_id=?"; $secArgs = [$userId]; if($sid) { $secSql .= " AND id=?"; $secArgs[] = $sid; } $sectionsStmt = $pdo->prepare($secSql); $sectionsStmt->execute($secArgs); $sections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);
                $secsNode = $dom->createElement('sections'); foreach($sections as $s) { $sn = $dom->createElement('section'); $sn->setAttribute('id', $s['name']); $sn->setAttribute('name', $s['name']); $sn->setAttribute('color', $s['color']); $secsNode->appendChild($sn); } $root->appendChild($secsNode);
                while($n=$notes->fetch(PDO::FETCH_ASSOC)){$node=$dom->createElement('note');$node->setAttribute('title',$n['title']); $c = new DateTime($n['created_at']); $node->setAttribute('created', $c->format('Ymd\THis')); if($n['updated_at']){ $m = new DateTime($n['updated_at']); $node->setAttribute('modified', $m->format('Ymd\THis')); } if($n['section_id']) { $sname=$pdo->query("SELECT name FROM sections WHERE id=".$n['section_id'])->fetchColumn(); if($sname) $node->setAttribute('section', $sname); } $node->appendChild($dom->createCDATASection($n['content']));$root->appendChild($node);} header('Content-Type: application/xml'); header('Content-Disposition: attachment; filename="notes.xml"'); echo $dom->saveXML(); exit; break;
        }
    } catch (Exception $e) { $response = ['success' => false, 'message' => $e->getMessage()]; }
    echo json_encode($response); exit;
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Notatki Web</title>
<style>
:root { --bg: #f6f8fb; --card: #fff; --text: #333; --border: #ccc; --hover: #eef; --active: #dde; --primary: <?php echo $_SESSION['accent_color'] ?? '#1f6feb'; ?>; }
[data-theme="dark"] { --bg: #121212; --card: #1e1e1e; --text: #e0e0e0; --border: #333; --hover: #2c2c2c; --active: #252d3a; }
body { font-family: 'Segoe UI', sans-serif; margin: 0; background: var(--bg); color: var(--text); font-size: 13px; }
header { background: var(--primary); color: #fff; padding: 8px 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
.wrap { display: flex; height: calc(100vh - 50px); overflow: hidden; }
.sidebar { width: 320px; flex-shrink: 0; border-right: 1px solid var(--border); background: #fff; display: flex; flex-direction: column; }
.editor { flex: 1; display: flex; flex-direction: column; background: #fff; min-width: 0; }
.section-tabs { display: flex; overflow-x: auto; background: #eee; border-bottom: 1px solid var(--border); padding: 4px 4px 0 4px; gap: 2px; }
.sec-tab { padding: 5px 12px; border: 1px solid var(--border); border-bottom: none; border-radius: 4px 4px 0 0; background: #e0e0e0; cursor: pointer; white-space: nowrap; font-size: 12px; display: flex; align-items: center; color: #555; }
.sec-tab.active { background: #fff; font-weight: bold; border-bottom: 1px solid #fff; margin-bottom: -1px; color: #000; }
.sec-tab-color { width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
.notes-list { flex: 1; overflow-y: auto; background: #fff; }
.note-item { border-bottom: 1px solid var(--border); cursor: pointer; display: flex; flex-direction: column; }
.note-item:hover { background: #f5f5f5; }
.note-item.active { background: #e8f0fe; border-left: 3px solid var(--primary); }
.note-header { padding: 5px 10px; display: flex; justify-content: space-between; font-weight: bold; font-size: 13px; color: #000; }
.note-preview { padding: 5px 10px 8px 10px; color: #666; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; height: 18px; }
.editor-tools { padding: 5px; border-bottom: 1px solid var(--border); background: #f9f9f9; display: flex; gap: 5px; flex-wrap: wrap; align-items: center; }
.editor-tools button, .editor-tools select { cursor: pointer; padding: 3px 8px; border: 1px solid #ccc; border-radius: 2px; }
.editor-content-wrap { flex: 1; overflow: auto; display: block; position: relative; } 
.content { outline: none; padding: 15px; min-height: 100%; font-size: 14px; white-space: pre; width: max-content; min-width: 100%; box-sizing: border-box; }
.content img { max-width: 100%; height: auto; cursor: pointer; }
.search-box { padding: 8px; background: #f9f9f9; border-bottom: 1px solid var(--border); display: flex; gap: 5px; }
.search-box input { flex: 1; padding: 4px; border: 1px solid #ccc; }
.sidebar-toggle, .mobile-close-btn { display: none; }
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 900; }
.modal-content { background: #fff; padding: 20px; border-radius: 4px; width: 350px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
.form-group { margin-bottom: 10px; }
.form-group label { display: block; font-weight: bold; margin-bottom: 3px; }

@media (max-width: 768px) {
    .wrap { flex-direction: column; padding: 0; }
    .sidebar { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100vh; z-index: 2000; background: var(--bg); padding: 0; }
    .sidebar.active { display: flex; }
    .editor { width: 100%; border: none; height: calc(100vh - 50px); }
    .sidebar-toggle { display: inline-block; margin-right: 10px; padding: 6px 12px; background: #ddd; border: 1px solid #ccc; border-radius: 4px; }
    .mobile-close-btn { display: block; margin: 10px; background: #d9534f; color: white; border: none; padding: 10px; border-radius: 4px; font-size: 14px; cursor: pointer; }
}
</style>
</head>
<body data-theme="<?php echo $_SESSION['theme'] ?? 'light'; ?>">
<?php if (!isset($_SESSION['user_id'])): ?>
<div style="display:flex; justify-content:center; align-items:center; height:100vh; background:#eee;">
    <div style="background:#fff; padding:30px; width:300px; border-radius:5px;">
        <h2>Logowanie</h2><input id="loginUser" placeholder="Login" style="width:100%; margin-bottom:10px; padding:8px;"><input id="loginPass" type="password" placeholder="Hasło" style="width:100%; margin-bottom:10px; padding:8px;">
        <button id="loginBtn" style="width:100%; padding:8px; background:#007bff; color:#fff; border:none;">Zaloguj</button><div id="loginError" style="color:red; margin-top:10px;"></div>
    </div>
</div>
<?php else: ?>
<header>
    <div style="font-weight:bold;">Notatki ver. 3.4 MAC-ai</div>
    <div style="display:flex; gap:10px; font-size:12px;">
        <span><?php echo $_SESSION['username']; ?></span>
        <?php if($_SESSION['role'] === 'admin'): ?>
            <button onclick="openUsersModal()" title="Zarządzaj użytkownikami">Użytkownicy</button>
        <?php endif; ?>
        <button onclick="document.getElementById('settingsModal').style.display='flex'" title="Ustawienia wyglądu i aplikacji">Opcje</button>
        <button onclick="openImportModal()" title="Importuj notatki z pliku XML">Import</button>
        <button onclick="openExportModal()" title="Eksportuj notatki do pliku XML">Export</button>
        <button onclick="logout()" title="Wyloguj się z systemu">Wyloguj</button>
    </div>
</header>
<div class="wrap">
    <aside class="sidebar" id="sidebar">
        <button class="mobile-close-btn" onclick="toggleSidebar()">Zamknij listę (X)</button>
        <div class="section-tabs" id="sectionTabs"></div>
        <div class="search-box">
            <input id="q" placeholder="Szukaj...">
            <button onclick="readNotes()" title="Szukaj w treści, tytule i tagach">🔍</button>
            <button onclick="document.getElementById('q').value=''; readNotes();" title="Wyczyść wyszukiwanie i odśwież">⟳</button>
        </div>
        <div style="padding:5px; background:#f0f0f0; border-bottom:1px solid #ccc; display:flex; gap:5px;">
            <button onclick="document.getElementById('sectionModal').style.display='flex'" class="btn-small" title="Utwórz nową sekcję">+ Sekcja</button>
            <button onclick="deleteEmptySections()" class="btn-small" title="Usuń sekcje, które nie zawierają notatek">🧹</button>
            <button onclick="toggleTrashMode()" id="trashBtn" class="btn-small" title="Pokaż usunięte notatki (Kosz)">🗑️ Kosz</button>
            <select id="sortSelect" onchange="readNotes()" style="margin-left:auto; font-size:11px;" title="Sortowanie listy"><option value="DESC">▼ Data</option><option value="ASC">▲ Data</option></select>
        </div>
        <div class="notes-list" id="notesList"></div>
    </aside>
    <main class="editor">
        <input id="title" style="width:100%; border:none; font-size:18px; font-weight:bold; padding:10px 15px; outline:none; border-bottom:1px solid #eee;" placeholder="Tytuł...">
        <div class="meta-bar" style="padding:5px 15px; background:#f9f9f9; display:flex; gap:10px; font-size:11px;">
            <select id="sectionInput" title="Przypisz notatkę do sekcji"><option value="">-- Bez sekcji --</option></select>
            <input id="tagsInput" placeholder="Tagi (oddziel przecinkiem)..." style="flex:1; border:1px solid #ddd;" title="Tagi ułatwiające szukanie">
        </div>
        <div class="editor-tools">
            <button class="sidebar-toggle" onclick="toggleSidebar()" title="Pokaż/ukryj listę notatek">☰ Lista</button>
            <button onclick="newNote()" title="Utwórz nową, pustą notatkę" style="display:flex; align-items:center; justify-content:center; background:#f0fff4; border:1px solid #c6f6d5;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2f855a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
            </button>
            <button id="saveBtn" onclick="save()" title="Zapisz zmiany w notatce" style="background:#e8f0fe;">💾</button>
            <div style="width:1px; height:20px; background:#ccc; margin:0 5px;"></div>
            <button onclick="document.getElementById('attachInput').click()" title="Załącz pliki do notatki" style="padding:2px 5px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
            <input type="file" id="attachInput" multiple style="display:none">
            <div style="width:1px; height:20px; background:#ccc; margin:0 5px;"></div>
            <button onclick="cmd('bold')" title="Pogrubienie tekstu"><b>B</b></button>
            <button onclick="cmd('italic')" title="Kursywa"><i>I</i></button>
            <button onclick="cmd('underline')" title="Podkreślenie"><u>U</u></button>
            <button onclick="document.getElementById('imgUploadInput').click()" title="Wstaw obrazek">🖼️</button>
            <input type="file" id="imgUploadInput" style="display:none" accept="image/*">
            <button onclick="resizeImage()" title="Zmień rozmiar zaznaczonego obrazka">📐</button>
            <button onclick="cmd('hiliteColor', '#ffff00')" title="Zaznacz tekst żółtym kolorem" style="background:#ffff00; border:1px solid #ccc;">🖍</button>
            <button onclick="cmd('hiliteColor', 'transparent')" title="Usuń zaznaczenie kolorem" style="border:1px solid #ccc;">🚫</button>
            <div style="width:1px; height:20px; background:#ccc; margin:0 5px;"></div>
            <select onchange="cmd('fontName', this.value);this.value='';" title="Zmień czcionkę"><option value="">Font</option><option value="Arial">Arial</option><option value="Courier New">Courier</option><option value="Verdana">Verdana</option></select>
            <select onchange="cmd('fontSize', this.value);this.value='';" title="Zmień rozmiar tekstu"><option value="">Rozm</option><option value="2">Mała</option><option value="3">Norm</option><option value="5">Duża</option></select>
            <input type="color" oninput="cmd('foreColor', this.value)" title="Zmień kolor tekstu" style="width:30px; padding:0;">
            <div style="flex:1"></div>
            <button id="deleteBtn" onclick="deleteNote()" title="Przenieś notatkę do kosza" style="color:red;">🗑️</button>
            <button id="restoreBtn" onclick="restoreNote()" style="display:none; background:#d4edda; color:green;" title="Przywróć notatkę z kosza">♻️</button>
            <button id="permDeleteBtn" onclick="permDeleteNote()" style="display:none; background:#f8d7da; color:red;" title="Usuń notatkę trwale i nieodwracalnie">❌</button>
            <button id="emptyTrashBtn" onclick="emptyTrash()" style="display:none; background:#666; color:#fff; font-size:10px;" title="Wyczyść wszystkie notatki w koszu">Opróżnij</button>
        </div>
        <div id="attachmentsList" style="padding:5px 15px; font-size:12px; background:#fff; border-bottom:1px solid #eee;"></div>
        <div class="editor-content-wrap">
            <div id="content" class="content" contenteditable="true"></div>
        </div>
    </main>
</div>

<div id="sectionModal" class="modal"><div class="modal-content"><h3>Dodaj sekcję</h3><input id="newSectionName" placeholder="Nazwa"><br><input type="color" id="newSectionColor"><br><button onclick="saveSection()">Zapisz</button> <button onclick="document.getElementById('sectionModal').style.display='none'">X</button></div></div>
<div id="settingsModal" class="modal"><div class="modal-content"><h3>Opcje</h3><div class="form-group"><label>Motyw:</label><label><input type="radio" name="theme" value="light" <?php echo ($_SESSION['theme']??'light')=='light'?'checked':''; ?>> Jasny</label> <label><input type="radio" name="theme" value="dark" <?php echo ($_SESSION['theme']??'light')=='dark'?'checked':''; ?>> Ciemny</label></div><div class="form-group"><label>Kolor akcentu:</label><input type="color" id="accentColorInput" value="<?php echo $_SESSION['accent_color'] ?? '#1f6feb'; ?>"></div><div class="form-group"><label>Domyślna sekcja:</label><select id="defaultSectionSelect" title="Sekcja ustawiana automatycznie przy starcie aplikacji i nowej notatce"><option value="">-- Wszystkie --</option></select></div><button onclick="saveAppearance()">Zapisz</button> <button onclick="document.getElementById('settingsModal').style.display='none'">Zamknij</button></div></div>
<div id="importModal" class="modal"><div class="modal-content"><h3>Import</h3><input type="file" id="xmlImportInput"><br><br><button onclick="triggerImport()">OK</button> <button onclick="document.getElementById('importModal').style.display='none'">X</button></div></div>
<div id="exportModal" class="modal"><div class="modal-content"><h3>Eksport</h3><select id="exportSectionInput"><option value="">Wszystkie</option></select><br><br><button onclick="doExport()">Pobierz XML</button> <button onclick="document.getElementById('exportModal').style.display='none'">X</button></div></div>
<div id="usersModal" class="modal"><div class="modal-content" style="width:400px;"><h3>Użytkownicy</h3><input type="hidden" id="editUserId"><div style="background:#f9f9f9; padding:10px; border:1px solid #eee;"><b id="userFormTitle">Dodaj:</b><br><input id="newUserName" placeholder="Login" style="width:100px;"><input id="newUserPass" type="password" placeholder="Hasło" style="width:100px;"><select id="newUserRole"><option value="user">User</option><option value="admin">Admin</option></select><button onclick="submitUserForm()" id="submitUserBtn">OK</button> <button onclick="cancelEdit()" id="cancelEditBtn" style="display:none">Anuluj</button></div><div id="usersList" style="margin-top:10px; max-height:200px; overflow:auto;"></div><br><button onclick="document.getElementById('usersModal').style.display='none'">Zamknij</button></div></div>

<script>
let currentNote=null, currentSectionId='<?php echo $_SESSION['default_section_id'] ?? ''; ?>', isTrashMode=false, tempNoteId=null, lastClickedImg=null;
function uid(){return Date.now().toString(36)+Math.random().toString(36).slice(2);}
function cmd(c,v=null){document.execCommand(c,false,v);}

// POPRAWIONA FUNKCJA API DO PRZESYŁANIA PLIKÓW
async function api(act, data={}, method='POST'){
    let url = '?action=' + act + '&_t=' + new Date().getTime(); 
    let options = { method: method };
    if(method === 'POST') {
        if(data instanceof FormData) { 
            options.body = data; 
            // Przy FormData nie ustawiamy nagłówków Content-Type, fetch zrobi to sam z boundary
        } else { 
            let params = new URLSearchParams(); for(let key in data) params.append(key, data[key]); 
            options.body = params; 
            options.headers = { 'Content-Type': 'application/x-www-form-urlencoded' }; 
        }
    } else { url += '&' + new URLSearchParams(data).toString(); }
    try { return await fetch(url, options).then(r => r.json()); } catch(e) { console.error(e); return {success:false}; }
}

async function logout(){await api('logout');location.reload();}
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('active'); }

async function readNotes() {
    const q=document.getElementById('q').value, sort=document.getElementById('sortSelect').value;
    let p = {q:q, sort:sort, trash: isTrashMode ? '1' : '0'};
    if(!isTrashMode) p.section_id=currentSectionId;
    const res = await api('read_notes', p, 'GET');
    const list = document.getElementById('notesList'); list.innerHTML = '';
    if(res.success && res.notes) {
        if(res.notes.length === 0) list.innerHTML = '<div style="padding:10px; color:#999;">Brak notatek.</div>';
        res.notes.forEach(n => {
            let div = document.createElement('div'); div.innerHTML = n.content;
            let text = div.innerText.replace(/\s+/g,' ').trim().substring(0,100);
            let item = document.createElement('div'); item.className = 'note-item';
            if(currentNote && n.id===currentNote.id) item.classList.add('active');
            let bg = (n.section_color || '#d4e4e6') + '33';
            item.innerHTML = `<div class="note-header" style="background:${bg}"><span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#000;">${n.has_attachments?'📎 ':''} ${n.title||'Bez tytułu'}</span><span style="font-weight:normal;font-size:10px;color:#666;margin-left:5px;">${n.display_date}</span></div><div class="note-preview">${text||'...'}</div>`;
            item.onclick = () => loadNote(n.id); list.appendChild(item);
        });
    }
}

async function loadMetadata() {
    const res = await api('get_metadata',{},'GET');
    if(res.success) {
        const sel = document.getElementById('sectionInput'), dsSel = document.getElementById('defaultSectionSelect');
        const currentVal = sel.value; sel.innerHTML = '<option value="">-- Bez sekcji --</option>'; dsSel.innerHTML = '<option value="">-- Wszystkie --</option>';
        const tabs = document.getElementById('sectionTabs'); tabs.innerHTML = '';
        let allDiv = document.createElement('div'); allDiv.className = 'sec-tab' + (currentSectionId === '' ? ' active' : ''); allDiv.innerText = `Wszystkie (${res.total})`;
        allDiv.onclick = function() { currentSectionId=''; readNotes(); const t = document.getElementById('sectionTabs').children; for(let i of t) i.classList.remove('active'); this.classList.add('active'); }; tabs.appendChild(allDiv);
        res.sections.forEach(s => {
            const opt = `<option value="${s.id}" style="color:${s.color}">${s.name}</option>`;
            sel.innerHTML += opt; dsSel.innerHTML += opt;
            let div = document.createElement('div'); div.className = 'sec-tab' + (currentSectionId == s.id ? ' active' : '');
            div.innerHTML = `<span class="sec-tab-color" style="background:${s.color}"></span>${s.name} (${s.count})`;
            div.onclick = function() { currentSectionId=s.id; readNotes(); const t = document.getElementById('sectionTabs').children; for(let i of t) i.classList.remove('active'); this.classList.add('active'); }; tabs.appendChild(div);
        });
        sel.value = currentVal; dsSel.value = res.default_section_id || "";
    }
}

function newNote() {
    if(isTrashMode) return; currentNote = null; tempNoteId = uid();
    document.getElementById('title').value=''; document.getElementById('content').innerHTML='';
    document.getElementById('sectionInput').value = currentSectionId || ""; 
    document.getElementById('tagsInput').value=''; document.getElementById('attachmentsList').innerHTML='';
}

async function loadNote(id) {
    const res = await api('read_note', {id:id}, 'GET');
    if(res.success) {
        currentNote = res.note; document.getElementById('title').value = currentNote.title; document.getElementById('content').innerHTML = currentNote.content; document.getElementById('tagsInput').value = (currentNote.tags||[]).join(', '); document.getElementById('sectionInput').value = (currentNote.section_id !== null) ? currentNote.section_id : "";
        renderAttachments(currentNote.attachments||[]);
        if(window.innerWidth<=768) document.getElementById('sidebar').classList.remove('active');
        ['title','content','sectionInput','tagsInput'].forEach(i => { const el = document.getElementById(i); if(i === 'content') el.contentEditable = !isTrashMode; else el.disabled = isTrashMode; });
        readNotes();
    }
}

async function save() {
    if(isTrashMode) return; const id = currentNote ? currentNote.id : (tempNoteId || uid());
    const res = await api('save_note', { id: id, title: document.getElementById('title').value, content: document.getElementById('content').innerHTML, section_id: document.getElementById('sectionInput').value, tags: document.getElementById('tagsInput').value });
    if(res.success) { await readNotes(); await loadMetadata(); if(!currentNote) newNote(); } else alert(res.message);
}

function toggleTrashMode() {
    isTrashMode = !isTrashMode; document.getElementById('q').value = ''; document.getElementById('trashBtn').style.background = isTrashMode ? '#ffcccc' : ''; document.getElementById('sectionTabs').style.display = isTrashMode ? 'none' : 'flex';
    ['saveBtn','deleteBtn'].forEach(id => document.getElementById(id).style.display = isTrashMode ? 'none' : 'inline-block');
    ['restoreBtn','permDeleteBtn','emptyTrashBtn'].forEach(id => document.getElementById(id).style.display = isTrashMode ? 'inline-block' : 'none');
    document.getElementById('notesList').innerHTML = '<div style="padding:10px;color:#999">Ładowanie...</div>';
    if(!isTrashMode) currentSectionId = '<?php echo $_SESSION['default_section_id'] ?? ''; ?>';
    newNote(); readNotes();
}

async function deleteNote(){ if(currentNote && confirm("Do kosza?")) { await api('delete_note',{id:currentNote.id}); newNote(); readNotes(); await loadMetadata(); } }
async function restoreNote(){ if(currentNote) { await api('restore_note',{id:currentNote.id}); newNote(); readNotes(); await loadMetadata(); } }
async function permDeleteNote(){ if(confirm("Trwale?")) { await api('perm_delete_note',{id:currentNote.id}); newNote(); readNotes(); await loadMetadata(); } }
async function emptyTrash(){ if(confirm("Opróżnić kosz?")) { await api('empty_trash'); readNotes(); await loadMetadata(); } }

function openImportModal() { document.getElementById('importModal').style.display='flex'; }
async function triggerImport() { let fd=new FormData(); fd.append('xml_file', document.getElementById('xmlImportInput').files[0]); await api('import_xml', fd); alert("Gotowe"); location.reload(); }
function openExportModal() { const sel = document.getElementById('exportSectionInput'); sel.innerHTML='<option value="">Wszystkie</option>'; document.querySelectorAll('#sectionInput option').forEach(o => { if(o.value) sel.innerHTML+=`<option value="${o.value}">${o.text}</option>`; }); document.getElementById('exportModal').style.display='flex'; }
function doExport() { location.href='?action=export_xml&section_id='+document.getElementById('exportSectionInput').value; }
function saveAppearance() { const th = document.querySelector('input[name="theme"]:checked').value, col = document.getElementById('accentColorInput').value, ds = document.getElementById('defaultSectionSelect').value; api('save_settings',{theme:th, accent_color:col, default_section_id: ds}).then(()=>location.reload()); }
function saveSection() { api('add_section',{name:document.getElementById('newSectionName').value, color:document.getElementById('newSectionColor').value}).then(()=>{document.getElementById('sectionModal').style.display='none'; loadMetadata();}); }
function deleteEmptySections() { if(confirm("Usunąć puste sekcje?")) api('delete_empty_sections').then(()=>loadMetadata()); }

document.getElementById('content').addEventListener('click', e => { 
    if(e.target.tagName==='IMG') { if(lastClickedImg) lastClickedImg.style.outline='none'; lastClickedImg=e.target; lastClickedImg.style.outline='2px solid blue'; } 
    else { if(lastClickedImg) lastClickedImg.style.outline='none'; lastClickedImg=null; }
    if (e.target.style.backgroundColor && e.target.style.backgroundColor !== 'transparent') e.target.style.backgroundColor = 'transparent';
});
function resizeImage() { if(!lastClickedImg) return alert("Kliknij obrazek!"); let w=prompt("Szerokość:", lastClickedImg.style.width||"100%"); if(w) lastClickedImg.style.width=w; }
document.getElementById('imgUploadInput').addEventListener('change', function(){ if(this.files[0]) upImg(this.files[0]); });
document.getElementById('content').addEventListener('paste', e => { let items=(e.clipboardData||e.originalEvent.clipboardData).items; for(let i of items) if(i.kind==='file'&&i.type.startsWith('image/')) { e.preventDefault(); upImg(i.getAsFile()); } });
async function upImg(f) { let fd=new FormData(); fd.append('action','upload_image'); fd.append('note_id',currentNote?currentNote.id:tempNoteId); fd.append('image',f); let r=await fetch('?action=upload_image',{method:'POST',body:fd}).then(d=>d.json()); if(r.success) cmd('insertImage', r.url); }

// PRZYWRÓCONA FUNKCJONALNOŚĆ ZAŁĄCZNIKÓW
document.getElementById('attachInput').addEventListener('change', async function(){ 
    if(!currentNote) return alert("Zapisz najpierw!"); 
    let fd=new FormData(); 
    for(let f of this.files) fd.append('files[]',f); 
    fd.append('id',currentNote.id); 
    await api('upload_attachment',fd); 
    loadNote(currentNote.id); 
});

function renderAttachments(list) { const div = document.getElementById('attachmentsList'); div.innerHTML=''; if(list.length===0) div.innerHTML='<span style="color:#aaa">Brak załączników</span>'; list.forEach(a => div.innerHTML+=`<span class="att-item"><a href="uploads/${currentNote.id}/${a.name}" target="_blank">📄 ${a.name}</a> <span style="color:red;cursor:pointer;margin-left:5px;" onclick="delAtt('${a.name}')">x</span></span> `); }
async function delAtt(n) { if(confirm('Usunąć?')) { await api('delete_attachment',{note_id:currentNote.id,file_name:n}); loadNote(currentNote.id); } }

async function openUsersModal() { document.getElementById('usersModal').style.display = 'flex'; loadUsers(); cancelEdit(); }
async function loadUsers() {
    const res = await api('get_users'), el = document.getElementById('usersList'); el.innerHTML = '';
    res.users.forEach(u => {
        const d = document.createElement('div'); d.className='user-row';
        d.innerHTML = `<div style="flex:1"><b>${u.username}</b> (${u.role})</div><div style="display:flex; gap:5px;"><button onclick="startEditUser(${u.id}, '${u.username}', '${u.role}')" class="btn-small">Edytuj</button><button onclick="adminPass(${u.id})" class="btn-small">Hasło</button>${u.username !== '<?php echo $_SESSION['username']?>' ? `<button onclick="delUser(${u.id})" class="btn-small" style="color:red">Usuń</button>` : ''}</div>`;
        el.appendChild(d);
    });
}
function startEditUser(id, name, role) { document.getElementById('editUserId').value = id; document.getElementById('newUserName').value = name; document.getElementById('newUserRole').value = role; document.getElementById('userFormTitle').innerText = 'Edytuj:'; document.getElementById('submitUserBtn').innerText = 'Zapisz'; document.getElementById('cancelEditBtn').style.display = 'inline-block'; }
function cancelEdit() { document.getElementById('editUserId').value = ''; document.getElementById('newUserName').value = ''; document.getElementById('newUserRole').value = 'user'; document.getElementById('newUserPass').value = ''; document.getElementById('userFormTitle').innerText = 'Dodaj:'; document.getElementById('submitUserBtn').innerText = 'Dodaj'; document.getElementById('cancelEditBtn').style.display = 'none'; }
async function submitUserForm() {
    const id = document.getElementById('editUserId').value, u = document.getElementById('newUserName').value, p = document.getElementById('newUserPass').value, r = document.getElementById('newUserRole').value;
    let action = 'add_user', data = {username: u, password: p, role: r}; if (id) { action = 'edit_user'; data.target_id = id; } else if (!p) return alert("Hasło!");
    const res = await api(action, data); if (res.success) { loadUsers(); cancelEdit(); } else alert(res.message);
}
async function adminPass(id) { let p = prompt("Nowe hasło:"); if(p) api('admin_change_pass', {target_id: id, new_password: p}).then(()=>alert("OK")); }
async function delUser(id) { if(confirm("Usunąć?")) api('delete_user', {target_id: id}).then(()=>loadUsers()); }

(async function(){ document.execCommand('styleWithCSS', false, true); await loadMetadata(); await readNotes(); newNote(); })();
document.getElementById('q').addEventListener('keypress', e => { if(e.key==='Enter') readNotes(); });
</script>
<?php endif; ?>

<?php if(!isset($_SESSION['user_id'])): ?>
<script>
document.getElementById('loginBtn').onclick = async () => { 
    let u = document.getElementById('loginUser').value;
    let p = document.getElementById('loginPass').value;
    let r = await fetch('?action=login', {
        method: 'POST', 
        headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
        body: `action=login&username=${encodeURIComponent(u)}&password=${encodeURIComponent(p)}`
    }).then(d => d.json()); 
    if(r.success) location.reload(); 
    else document.getElementById('loginError').innerText = r.message; 
}
</script>
<?php endif; ?>
</body>
</html>
