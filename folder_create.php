<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
verifyCsrf();

$user     = currentUser();
$db       = getDB();
$name     = trim($_POST['name']      ?? '');
$parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
$isShared = !empty($_POST['is_shared']);

$back = APP_URL . '/dashboard.php?view=mine' . ($parentId ? '&folder=' . $parentId : '');

if (!$name) {
    redirectWith($back, 'danger', 'Nama folder tidak boleh kosong.');
}

// Sanitasi nama folder
$name = preg_replace('/[\/\\\:\*\?"<>\|]/', '', $name);
if (!$name) {
    redirectWith($back, 'danger', 'Nama folder tidak valid.');
}

// Cek duplikat
$stmt = $db->prepare('
    SELECT id FROM folders
    WHERE owner_id = ? AND name = ? AND parent_id ' . ($parentId ? '= ?' : 'IS NULL')
);
$params = $parentId ? [$user['id'], $name, $parentId] : [$user['id'], $name];
$stmt->execute($params);
if ($stmt->fetch()) {
    redirectWith($back, 'warning', 'Folder dengan nama "' . $name . '" sudah ada.');
}

$stmt = $db->prepare('INSERT INTO folders (name, owner_id, parent_id) VALUES (?, ?, ?)');
$stmt->execute([$name, $user['id'], $parentId]);
$newFolderId = (int)$db->lastInsertId();

// Jika folder bersama: share ke semua anggota
if ($isShared) {
    $stmt = $db->prepare('
        INSERT IGNORE INTO shares (resource_type, resource_id, shared_by, shared_with, permission)
        VALUES ("folder", ?, ?, NULL, "edit")
    ');
    $stmt->execute([$newFolderId, $user['id']]);
    redirectWith(APP_URL . '/dashboard.php?view=team', 'success', 'Folder bersama "' . $name . '" berhasil dibuat.');
} else {
    redirectWith($back, 'success', 'Folder "' . $name . '" berhasil dibuat.');
}
