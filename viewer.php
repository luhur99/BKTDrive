<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$user   = currentUser();
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$raw    = isset($_GET['raw']);

if (!$fileId || !canAccessFile($fileId, $user['id'])) {
    http_response_code(403);
    die('Akses ditolak.');
}

$db   = getDB();
$stmt = $db->prepare('SELECT * FROM files WHERE id = ?');
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) { http_response_code(404); die('File tidak ditemukan.'); }

$ext  = getFileExtension($file['original_name']);
$path = storagePath($file['stored_name']);

if (!file_exists($path)) { http_response_code(404); die('File tidak ada di storage.'); }

$imageExts = ['jpg','jpeg','png','gif','webp','svg','bmp','ico'];
$isImage   = in_array($ext, $imageExts);
$isPdf     = $ext === 'pdf';
$isTxt     = in_array($ext, ['txt','log','csv','md','json','xml']);

if (!$isImage && !$isPdf && !$isTxt) {
    header('Location: ' . APP_URL . '/download.php?id=' . $fileId);
    exit;
}

// Serve raw (untuk embed / img src)
if ($raw) {
    $mime = $file['mime_type'] ?: ($isPdf ? 'application/pdf' : 'application/octet-stream');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode($file['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$txtContent = $isTxt ? file_get_contents($path) : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($file['original_name']) ?> — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { display:flex; flex-direction:column; height:100vh; background:#111827; font-family:'Segoe UI',sans-serif; }
        .viewer-topbar {
            background:#1e1b4b; color:white;
            padding:0.5rem 1rem;
            display:flex; align-items:center; gap:0.75rem;
            font-size:0.875rem; flex-shrink:0;
            border-bottom:1px solid rgba(255,255,255,0.08);
        }
        .viewer-topbar a.back-btn { color:rgba(255,255,255,0.7); text-decoration:none; font-size:1rem; }
        .viewer-topbar a.back-btn:hover { color:white; }
        .viewer-topbar .filename { font-weight:600; color:white; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .viewer-body { flex:1; overflow:auto; display:flex; align-items:center; justify-content:center; }
        .viewer-body img { max-width:100%; max-height:100%; object-fit:contain; padding:1rem; }
        .viewer-body iframe { width:100%; height:100%; border:none; background:white; }
        .txt-wrapper { width:100%; max-width:960px; margin:1.5rem auto; padding:0 1rem; }
        .txt-content {
            background:white; border-radius:8px; padding:1.5rem 2rem;
            overflow:auto;
        }
        .txt-content pre { white-space:pre-wrap; word-wrap:break-word; font-size:0.875rem; line-height:1.6; margin:0; font-family:'Consolas','Courier New',monospace; }
        .file-info { font-size:0.8rem; color:rgba(255,255,255,0.5); }
    </style>
</head>
<body>
    <div class="viewer-topbar">
        <a href="<?= APP_URL ?>/dashboard.php" class="back-btn" title="Kembali"
           onclick="if(history.length>1){history.back();return false;}">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <span class="file-info">
            <i class="fa-solid <?= getFileIcon($file['original_name']) ?> me-1"></i>
        </span>
        <span class="filename"><?= h($file['original_name']) ?></span>
        <span class="file-info"><?= formatSize($file['size']) ?></span>
        <a href="<?= APP_URL ?>/download.php?id=<?= $fileId ?>" class="btn btn-sm btn-outline-light">
            <i class="fa-solid fa-download me-1"></i> Download
        </a>
    </div>

    <div class="viewer-body">
        <?php if ($isImage): ?>
            <img src="<?= APP_URL ?>/viewer.php?id=<?= $fileId ?>&raw=1"
                 alt="<?= h($file['original_name']) ?>">

        <?php elseif ($isPdf): ?>
            <iframe src="<?= APP_URL ?>/viewer.php?id=<?= $fileId ?>&raw=1"
                    title="<?= h($file['original_name']) ?>"></iframe>

        <?php else: ?>
            <div class="txt-wrapper">
                <div class="txt-content">
                    <pre><?= h($txtContent) ?></pre>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
