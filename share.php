<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
verifyCsrf();

$user         = currentUser();
$db           = getDB();
$resourceType = $_POST['resource_type'] ?? '';
$resourceId   = (int)($_POST['resource_id']  ?? 0);
$sharedWith   = !empty($_POST['shared_with']) ? (int)$_POST['shared_with'] : null;
$permission   = in_array($_POST['permission'] ?? '', ['view', 'edit']) ? $_POST['permission'] : 'view';
$folderId     = !empty($_POST['folder_id'])   ? (int)$_POST['folder_id'] : null;

$back = APP_URL . '/dashboard.php?view=mine' . ($folderId ? '&folder=' . $folderId : '');

if (!in_array($resourceType, ['file', 'folder']) || !$resourceId) {
    redirectWith($back, 'danger', 'Permintaan tidak valid.');
}

// Pastikan resource milik user ini
if ($resourceType === 'file') {
    $stmt = $db->prepare('SELECT id FROM files WHERE id = ? AND owner_id = ?');
} else {
    $stmt = $db->prepare('SELECT id FROM folders WHERE id = ? AND owner_id = ?');
}
$stmt->execute([$resourceId, $user['id']]);
if (!$stmt->fetch()) {
    redirectWith($back, 'danger', 'Resource tidak ditemukan atau bukan milik Anda.');
}

// Tidak boleh share ke diri sendiri
if ($sharedWith && $sharedWith === $user['id']) {
    redirectWith($back, 'warning', 'Anda tidak bisa berbagi ke diri sendiri.');
}

// Upsert share
try {
    $stmt = $db->prepare('
        INSERT INTO shares (resource_type, resource_id, shared_by, shared_with, permission)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE permission = VALUES(permission)
    ');
    $stmt->execute([$resourceType, $resourceId, $user['id'], $sharedWith, $permission]);
} catch (PDOException $e) {
    redirectWith($back, 'danger', 'Gagal menyimpan share: ' . $e->getMessage());
}

$target = $sharedWith ? 'anggota yang dipilih' : 'semua anggota tim';
redirectWith($back, 'success', 'Berhasil dibagikan ke ' . $target . ' dengan izin ' . $permission . '.');
