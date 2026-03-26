<?php
/**
 * OnlyOffice save callback — saves edited FTP file back to the FTP server.
 * Status 2 = ready to save, Status 6 = force save
 */
ob_start();                    // buffer any stray PHP warnings/notices
error_reporting(0);            // never leak warnings into the JSON response

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ftp.php';

ob_clean();                    // discard anything config.php may have output
header('Content-Type: application/json');

$storageId = (int)($_GET['storage'] ?? 0);
$filePath  = preg_replace('#/+#', '/', $_GET['path'] ?? '');  // normalise double-slashes
$token     = $_GET['token']   ?? '';

$expected = hash_hmac('sha256', $storageId . $filePath, ONLYOFFICE_JWT_SECRET);

// Token was generated with the normalised path — also accept legacy double-slash token
$legacyPath    = $_GET['path'] ?? '';
$legacyExpected = hash_hmac('sha256', $storageId . $legacyPath, ONLYOFFICE_JWT_SECRET);

if (!hash_equals($expected, $token) && !hash_equals($legacyExpected, $token)) {
    echo json_encode(['error' => 1, 'message' => 'Access denied']); exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

// Debug log
$log = ['ts' => date('c'), 'path' => $filePath, 'body' => $data, 'status_code' => $data['status'] ?? null];

if (!$data) { echo json_encode(['error' => 0]); exit; }

$status = (int)($data['status'] ?? 0);
$response = ['error' => 0];

if (in_array($status, [2, 6])) {
    $downloadUrl = $data['url'] ?? '';
    if (!$downloadUrl) {
        $response = ['error' => 1, 'message' => 'URL empty'];
        file_put_contents('/tmp/oo_debug.log', json_encode($log + ['response' => $response]) . "\n", FILE_APPEND);
        echo json_encode($response); exit;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM external_storages WHERE id = ? AND enabled = 1');
    $stmt->execute([$storageId]);
    $es   = $stmt->fetch();
    if (!$es) {
        $response = ['error' => 1, 'message' => 'Storage not found'];
        file_put_contents('/tmp/oo_debug.log', json_encode($log + ['response' => $response]) . "\n", FILE_APPEND);
        echo json_encode($response); exit;
    }

    // OnlyOffice generates URLs with its public hostname (e.g. http://localhost:7400)
    // but from inside the PHP container we must use the Docker service name instead.
    $downloadUrl = preg_replace('#^https?://[^/]+#', 'http://onlyoffice', $downloadUrl);

    $ctx     = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
    $content = @file_get_contents($downloadUrl, false, $ctx);
    $log['download_url'] = $downloadUrl;
    $log['download_size'] = $content === false ? 'FAILED' : strlen($content);

    if ($content === false) {
        $response = ['error' => 1, 'message' => 'Failed to download from OnlyOffice'];
        file_put_contents('/tmp/oo_debug.log', json_encode($log + ['response' => $response]) . "\n", FILE_APPEND);
        echo json_encode($response); exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'oo_save_');
    file_put_contents($tmp, $content);

    $pass = base64_decode($es['password']);
    $ok   = ftpRetry($es, $pass, fn($conn) => @ftp_put($conn, $filePath, $tmp, FTP_BINARY));
    unlink($tmp);
    $log['ftp_put'] = $ok !== false ? 'OK' : 'FAILED';

    if ($ok === false) {
        $response = ['error' => 1, 'message' => 'FTP upload failed'];
    }
}

file_put_contents('/tmp/oo_debug.log', json_encode($log + ['response' => $response]) . "\n", FILE_APPEND);
echo json_encode($response);
exit;
