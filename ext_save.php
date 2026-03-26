<?php
/**
 * Saves the current OnlyOffice document to FTP.
 * Called via AJAX from ext_editor.php with the downloadAs URL.
 */
ob_start();
error_reporting(0);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ftp.php';
ob_clean();

header('Content-Type: application/json');

requireLogin();

$storageId = (int)($_GET['storage'] ?? 0);
$filePath  = $_GET['path'] ?? '';
$token     = $_GET['token'] ?? '';

$expected = hash_hmac('sha256', $storageId . $filePath, ONLYOFFICE_JWT_SECRET);
if (!hash_equals($expected, $token)) {
    echo json_encode(['error' => 1, 'message' => 'Access denied']); exit;
}

$body        = json_decode(file_get_contents('php://input'), true);
$downloadUrl = $body['url'] ?? '';
if (!$downloadUrl) {
    echo json_encode(['error' => 1, 'message' => 'No URL provided']); exit;
}

// OnlyOffice returns its public URL; inside Docker we must use the service name.
$downloadUrl = preg_replace('#^https?://[^/]+#', 'http://onlyoffice', $downloadUrl);

$ctx     = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
$content = @file_get_contents($downloadUrl, false, $ctx);
if ($content === false) {
    echo json_encode(['error' => 1, 'message' => 'Download dari OnlyOffice gagal']); exit;
}

$db   = getDB();
$stmt = $db->prepare('SELECT * FROM external_storages WHERE id = ? AND enabled = 1');
$stmt->execute([$storageId]);
$es   = $stmt->fetch();
if (!$es) {
    echo json_encode(['error' => 1, 'message' => 'Storage tidak ditemukan']); exit;
}

$tmp  = tempnam(sys_get_temp_dir(), 'oo_save_');
file_put_contents($tmp, $content);
$pass = base64_decode($es['password']);
$ok   = ftpRetry($es, $pass, fn($conn) => @ftp_put($conn, $filePath, $tmp, FTP_BINARY));
unlink($tmp);

echo json_encode($ok !== false
    ? ['error' => 0]
    : ['error' => 1, 'message' => 'FTP upload gagal']);
