<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
verifyCsrf();

$user     = currentUser();
$db       = getDB();
$type     = $_POST['type']     ?? 'file';
$id       = (int)($_POST['id'] ?? 0);
$name     = trim($_POST['name'] ?? '');
$folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
$view     = $_POST['view']     ?? 'mine';

$back = APP_URL . '/dashboard.php?view=' . $view . ($folderId ? '&folder=' . $folderId : '');

if (!$id || !$name) {
    redirectWith($back, 'danger', 'Nama tidak boleh kosong.');
}

$name = preg_replace('/[\/\\\:\*\?"<>\|]/', '', $name);
if (!$name) {
    redirectWith($back, 'danger', 'Nama tidak valid.');
}

if ($type === 'folder') {
    $stmt = $db->prepare('SELECT * FROM folders WHERE id = ? AND owner_id = ?');
    $stmt->execute([$id, $user['id']]);
    $folder = $stmt->fetch();

    if (!$folder) {
        redirectWith($back, 'danger', 'Folder tidak ditemukan atau bukan milik Anda.');
    }

    $stmt = $db->prepare('UPDATE folders SET name = ? WHERE id = ?');
    $stmt->execute([$name, $id]);

    redirectWith($back, 'success', 'Folder berhasil diganti nama menjadi "' . $name . '".');
} else {
    $stmt = $db->prepare('SELECT * FROM files WHERE id = ? AND owner_id = ?');
    $stmt->execute([$id, $user['id']]);
    $file = $stmt->fetch();

    if (!$file) {
        redirectWith($back, 'danger', 'File tidak ditemukan atau bukan milik Anda.');
    }

    // Pertahankan ekstensi asli
    $origExt = pathinfo($file['original_name'], PATHINFO_EXTENSION);
    $newExt  = pathinfo($name, PATHINFO_EXTENSION);
    if (!$newExt && $origExt) {
        $name .= '.' . $origExt;
    }

    $stmt = $db->prepare('UPDATE files SET original_name = ? WHERE id = ?');
    $stmt->execute([$name, $id]);

    redirectWith($back, 'success', 'File berhasil diganti nama menjadi "' . $name . '".');
}
