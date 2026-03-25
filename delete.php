<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
verifyCsrf();

$user     = currentUser();
$db       = getDB();
$type     = $_POST['type']      ?? '';
$id       = (int)($_POST['id']  ?? 0);
$folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;

$back = APP_URL . '/dashboard.php?view=mine' . ($folderId ? '&folder=' . $folderId : '');

if (!in_array($type, ['file', 'folder']) || !$id) {
    redirectWith($back, 'danger', 'Permintaan tidak valid.');
}

if ($type === 'file') {
    $stmt = $db->prepare('SELECT * FROM files WHERE id = ? AND owner_id = ?');
    $stmt->execute([$id, $user['id']]);
    $file = $stmt->fetch();

    if (!$file) {
        redirectWith($back, 'danger', 'File tidak ditemukan atau bukan milik Anda.');
    }

    // Hapus dari storage
    $path = storagePath($file['stored_name']);
    if (file_exists($path)) {
        unlink($path);
    }

    // Hapus dari DB (shares akan cascade)
    $stmt = $db->prepare('DELETE FROM shares WHERE resource_type = "file" AND resource_id = ?');
    $stmt->execute([$id]);
    $stmt = $db->prepare('DELETE FROM files WHERE id = ?');
    $stmt->execute([$id]);

    redirectWith($back, 'success', '"' . $file['original_name'] . '" berhasil dihapus.');

} elseif ($type === 'folder') {
    $stmt = $db->prepare('SELECT * FROM folders WHERE id = ? AND owner_id = ?');
    $stmt->execute([$id, $user['id']]);
    $folder = $stmt->fetch();

    if (!$folder) {
        redirectWith($back, 'danger', 'Folder tidak ditemukan atau bukan milik Anda.');
    }

    // Hapus semua file dalam folder (rekursif via DB cascade)
    // Pertama hapus file fisik dalam folder dan sub-folder
    deleteFilesInFolder($id, $db);

    // Hapus folder (cascade ke sub-folder dan files via FK)
    $stmt = $db->prepare('DELETE FROM folders WHERE id = ?');
    $stmt->execute([$id]);

    redirectWith($back, 'success', '"' . $folder['name'] . '" berhasil dihapus.');
}

function deleteFilesInFolder(int $folderId, PDO $db): void {
    // Hapus file fisik
    $stmt = $db->prepare('SELECT stored_name FROM files WHERE folder_id = ?');
    $stmt->execute([$folderId]);
    foreach ($stmt->fetchAll() as $f) {
        $path = STORAGE_PATH . '/' . $f['stored_name'];
        if (file_exists($path)) unlink($path);
    }
    // Rekursif ke sub-folder
    $stmt = $db->prepare('SELECT id FROM folders WHERE parent_id = ?');
    $stmt->execute([$folderId]);
    foreach ($stmt->fetchAll() as $sub) {
        deleteFilesInFolder($sub['id'], $db);
    }
}
