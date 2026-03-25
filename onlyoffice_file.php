<?php
/**
 * Endpoint untuk OnlyOffice mengambil file dokumen.
 * URL ini dipanggil oleh server OnlyOffice, bukan browser user.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// OnlyOffice mengakses endpoint ini secara langsung (server-to-server).
// Validasi sederhana: cek file ID saja.
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$fileId) {
    http_response_code(400);
    die('Bad request.');
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
    die('File fisik tidak ditemukan.');
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
