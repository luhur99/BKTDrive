<?php
/**
 * OnlyOffice Callback — dipanggil saat dokumen disimpan.
 * Status 2 = document ready to be saved (forcesave / close)
 * Status 6 = document saved with force save
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    echo json_encode(['error' => 0]);
    exit;
}

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$status = (int)($data['status'] ?? 0);

// Status 2 atau 6 = saatnya simpan file
if (in_array($status, [2, 6])) {
    $downloadUrl = $data['url'] ?? '';
    if (!$downloadUrl) {
        echo json_encode(['error' => 1, 'message' => 'URL kosong']);
        exit;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM files WHERE id = ?');
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();

    if (!$file) {
        echo json_encode(['error' => 1, 'message' => 'File tidak ditemukan']);
        exit;
    }

    // OnlyOffice generates URLs with its public hostname (e.g. http://localhost:7400)
    // but from inside the PHP container we must use the Docker service name instead.
    $downloadUrl = preg_replace('#^https?://[^/]+#', 'http://onlyoffice', $downloadUrl);

    $context = stream_context_create(['http' => ['timeout' => 30]]);
    $content = @file_get_contents($downloadUrl, false, $context);

    if ($content === false) {
        echo json_encode(['error' => 1, 'message' => 'Gagal download dari OnlyOffice']);
        exit;
    }

    // Timpa file yang ada
    $path = storagePath($file['stored_name']);
    if (file_put_contents($path, $content) === false) {
        echo json_encode(['error' => 1, 'message' => 'Gagal menyimpan file']);
        exit;
    }

    // Update timestamp dan ukuran
    $stmt = $db->prepare('UPDATE files SET size = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([strlen($content), $fileId]);
}

// OnlyOffice butuh response {"error": 0} untuk konfirmasi OK
echo json_encode(['error' => 0]);
exit;
