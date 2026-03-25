<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

// OnlyOffice harus aktif
if (!ONLYOFFICE_ENABLED) {
    redirectWith(APP_URL . '/dashboard.php', 'warning',
        'Editor belum aktif. Install OnlyOffice Document Server terlebih dahulu (lihat SETUP.md).');
}

$user   = currentUser();
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$fileId || !canAccessFile($fileId, $user['id'])) {
    http_response_code(403);
    die('Akses ditolak.');
}

$db   = getDB();
$stmt = $db->prepare('SELECT * FROM files WHERE id = ?');
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file || !isOfficeFile($file['original_name'])) {
    die('File tidak ditemukan atau bukan file Office.');
}

$canEdit = canEditFile($fileId, $user['id']);
$docType = getDocumentType($file['original_name']);

// OnlyOffice config
$docKey     = md5($file['id'] . '-' . $file['updated_at']);
$documentUrl = APP_INTERNAL_URL . '/onlyoffice_file.php?id=' . $fileId;
$callbackUrl = APP_INTERNAL_URL . '/callback.php?id='         . $fileId;

$config = [
    'document' => [
        'fileType'    => getFileExtension($file['original_name']),
        'key'         => $docKey,
        'title'       => $file['original_name'],
        'url'         => $documentUrl,
        'permissions' => [
            'edit'     => $canEdit,
            'download' => true,
        ],
    ],
    'documentType' => $docType,
    'editorConfig' => [
        'mode'         => $canEdit ? 'edit' : 'view',
        'callbackUrl'  => $callbackUrl,
        'lang'         => 'id',
        'user' => [
            'id'   => (string)$user['id'],
            'name' => $user['name'],
        ],
        'customization' => [
            'autosave'    => true,
            'forcesave'   => true,
            'compactHeader' => true,
        ],
    ],
];

// JWT signing
function jwtEncode(array $payload, string $secret): string {
    $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode($payload));
    $header  = str_replace(['+','/',  '='], ['-', '_', ''], $header);
    $payload = str_replace(['+','/',  '='], ['-', '_', ''], $payload);
    $sig     = base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    $sig     = str_replace(['+','/', '='], ['-', '_', ''], $sig);
    return "$header.$payload.$sig";
}

$config['token'] = jwtEncode($config, ONLYOFFICE_JWT_SECRET);
$configJson      = json_encode($config, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($file['original_name']) ?> — <?= h(APP_NAME) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { display: flex; flex-direction: column; height: 100vh; font-family: sans-serif; background: #f3f4f6; }
        .editor-topbar {
            background: #1e1b4b; color: white;
            padding: 0.5rem 1rem;
            display: flex; align-items: center; gap: 1rem;
            font-size: 0.875rem; flex-shrink: 0;
        }
        .editor-topbar a { color: rgba(255,255,255,0.7); text-decoration: none; }
        .editor-topbar a:hover { color: white; }
        .editor-topbar .filename { font-weight: 600; }
        #placeholder { display: flex; flex: 1; align-items: center; justify-content: center; }
        #onlyoffice-editor { flex: 1; border: none; }
    </style>
</head>
<body>
    <div class="editor-topbar">
        <a href="<?= APP_URL ?>/dashboard.php">
            ← <?= h(APP_NAME) ?>
        </a>
        <span>/</span>
        <span class="filename"><?= h($file['original_name']) ?></span>
        <?php if (!$canEdit): ?>
            <span style="background:rgba(255,255,255,0.15);padding:0.15rem 0.5rem;border-radius:4px;font-size:0.75rem">
                Lihat Saja
            </span>
        <?php endif; ?>
    </div>
    <div id="placeholder">Memuat editor...</div>

    <script src="<?= ONLYOFFICE_SERVER ?>/web-apps/apps/api/documents/api.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('placeholder').remove();
        const editorDiv = document.createElement('div');
        editorDiv.id = 'onlyoffice-editor';
        document.body.appendChild(editorDiv);

        const config = <?= $configJson ?>;
        config.events = {
            onError: function(e) {
                console.error('OnlyOffice error:', e.data);
            }
        };
        new DocsAPI.DocEditor('onlyoffice-editor', config);
    });
    </script>
</body>
</html>
