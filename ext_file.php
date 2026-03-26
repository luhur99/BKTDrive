<?php
/**
 * Streams an FTP file to OnlyOffice (server-to-server).
 * URL: ext_file.php?storage=ID&path=/encoded/path&token=HASH
 */
ob_start();
error_reporting(0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ftp.php';

ob_clean();

$storageId = (int)($_GET['storage'] ?? 0);
$filePath  = preg_replace('#/+#', '/', $_GET['path'] ?? '');   // normalise double-slashes
$token     = $_GET['token']   ?? '';

// Accept both normalised and legacy (double-slash) tokens
$legacyPath    = $_GET['path'] ?? '';
$legacyExpected = hash_hmac('sha256', $storageId . $legacyPath, ONLYOFFICE_JWT_SECRET);

$expected = hash_hmac('sha256', $storageId . $filePath, ONLYOFFICE_JWT_SECRET);
if (!hash_equals($expected, $token) && !hash_equals($legacyExpected, $token)) {
    http_response_code(403); die('Access denied.');
}

$db   = getDB();
$stmt = $db->prepare('SELECT * FROM external_storages WHERE id = ? AND enabled = 1');
$stmt->execute([$storageId]);
$es   = $stmt->fetch();
if (!$es) { http_response_code(404); die('Storage not found.'); }

$pass = base64_decode($es['password']);
$tmp  = tempnam(sys_get_temp_dir(), 'oo_');
$ok   = ftpRetry($es, $pass, fn($conn) => @ftp_get($conn, $tmp, $filePath, FTP_BINARY));
if ($ok === false) { http_response_code(404); die('File not found on FTP.'); }

$mimeMap = [
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
$ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
exit;
