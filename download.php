<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$user   = currentUser();
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$fileId) {
    http_response_code(400);
    die('ID file tidak valid.');
}

if (!canAccessFile($fileId, $user['id'])) {
    http_response_code(403);
    die('Anda tidak memiliki akses ke file ini.');
}

$db   = getDB();
$stmt = $db->prepare('SELECT * FROM files WHERE id = ?');
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('File tidak ditemukan.');
}

$path = storagePath($file['stored_name']);
if (!file_exists($path)) {
    http_response_code(404);
    die('File fisik tidak ditemukan di server.');
}

// Kirim file ke browser
header('Content-Description: File Transfer');
header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . rawurlencode($file['original_name']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-cache');
header('Pragma: no-cache');

readfile($path);
exit;
