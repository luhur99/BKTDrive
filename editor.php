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

// JWT helper — defined early so it can be used for the drop command below
if (!function_exists('jwtEncode')) {
function jwtEncode(array $payload, string $secret): string {
    $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode($payload));
    $header  = str_replace(['+','/',  '='], ['-', '_', ''], $header);
    $payload = str_replace(['+','/',  '='], ['-', '_', ''], $payload);
    $sig     = base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    $sig     = str_replace(['+','/', '='], ['-', '_', ''], $sig);
    return "$header.$payload.$sig";
}
}

// Drop previous OO session to prevent "opened from backup copy" warning
$sessionKey = 'oo_int_key_' . $fileId;
$prevKey    = $_SESSION[$sessionKey] ?? null;
if ($prevKey) {
    $drop = ['c' => 'drop', 'key' => $prevKey, 'users' => []];
    $drop['token'] = jwtEncode($drop, ONLYOFFICE_JWT_SECRET);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($drop), 'timeout' => 2, 'ignore_errors' => true,
    ]]);
    @file_get_contents('http://onlyoffice/coauthoring/CommandService.ashx', false, $ctx);
}

// OnlyOffice config
// Unique key per session; store so next open can drop it
$docKey = substr(md5($file['id'] . '-' . $file['updated_at'] . '-' . session_id() . '-' . time()), 0, 20);
$_SESSION[$sessionKey] = $docKey;
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
            'autosave'      => false,  // disabled: prevents looping status=6 callbacks
            'forcesave'     => false,
            'compactHeader' => true,
        ],
    ],
];


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
            position: relative; z-index: 100;
        }
        .editor-topbar a { color: rgba(255,255,255,0.7); text-decoration: none; }
        .editor-topbar a:hover { color: white; }
        .editor-topbar .filename { font-weight: 600; }
        #placeholder { display: flex; flex: 1; align-items: center; justify-content: center; }
        #onlyoffice-editor { flex: 1; min-height: 0; overflow: hidden; border: none; }
        #save-overlay {
            position: fixed; bottom: 24px; right: 24px;
            z-index: 99999; display: flex; align-items: center; gap: 10px;
        }
        #btn-save {
            background: #4f46e5; color: white;
            border: none; padding: .55rem 1.2rem;
            border-radius: 8px; cursor: pointer; font-size: .9rem;
            display: flex; align-items: center; gap: .45rem;
            box-shadow: 0 4px 14px rgba(0,0,0,.45);
            transition: background .15s, transform .1s;
        }
        #btn-save:hover:not(:disabled) { background: #4338ca; transform: translateY(-1px); }
        #btn-save:disabled { background: #6b7280; cursor: not-allowed; transform: none; }
        #save-status {
            font-size: .82rem; white-space: nowrap; font-weight: 600; color: #fff;
            background: rgba(0,0,0,.75); padding: .35rem .75rem;
            border-radius: 6px; backdrop-filter: blur(4px); display: none;
        }
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

    <?php if ($canEdit): ?>
    <div id="save-overlay">
        <span id="save-status"></span>
        <button id="btn-save" onclick="doSave()">
            <i class="fa-solid fa-floppy-disk"></i> Simpan
        </button>
    </div>
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="<?= ONLYOFFICE_SERVER ?>/web-apps/apps/api/documents/api.js"></script>
    <script>
    let docEditor = null;
    let saveInProgress = false;
    let saveTimer = null;

    function setSaveStatus(msg, color) {
        const el = document.getElementById('save-status');
        if (!el) return;
        el.textContent = msg;
        el.style.color = color || '#fff';
        el.style.display = msg ? 'block' : 'none';
    }

    function onSaveComplete() {
        if (!saveInProgress) return;
        clearTimeout(saveTimer);
        saveInProgress = false;
        const btn = document.getElementById('btn-save');
        if (btn) btn.disabled = false;
        setSaveStatus('✓ Tersimpan', '#86efac');
        setTimeout(() => setSaveStatus(''), 4000);
    }

    function doSave() {
        if (!docEditor || saveInProgress) return;
        saveInProgress = true;
        document.getElementById('btn-save').disabled = true;
        setSaveStatus('Menyimpan…', 'rgba(255,255,255,.65)');
        docEditor.forceSave();
        saveTimer = setTimeout(onSaveComplete, 4000);
    }

    window.addEventListener('beforeunload', function() {
        if (docEditor) { try { docEditor.forceSave(); } catch(e) {} }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('placeholder').remove();
        const editorDiv = document.createElement('div');
        editorDiv.id = 'onlyoffice-editor';
        document.body.appendChild(editorDiv);

        const config = <?= $configJson ?>;
        config.events = {
            onDocumentStateChange: function(event) {
                if (!event.data && saveInProgress) {
                    onSaveComplete();
                }
            },
            onOutdatedVersion: function() {
                // File was saved via Ctrl+S (status=6 callback). Acknowledge without reloading.
                setSaveStatus('✓ Tersimpan (Ctrl+S)', '#86efac');
                setTimeout(() => setSaveStatus(''), 4000);
                if (saveInProgress) {
                    clearTimeout(saveTimer);
                    saveInProgress = false;
                    const btn = document.getElementById('btn-save');
                    if (btn) btn.disabled = false;
                }
            },
            onError: function(e) {
                if (saveInProgress) {
                    clearTimeout(saveTimer);
                    saveInProgress = false;
                    const btn = document.getElementById('btn-save');
                    if (btn) btn.disabled = false;
                    setSaveStatus('✗ Gagal menyimpan', '#fca5a5');
                    setTimeout(() => setSaveStatus(''), 5000);
                }
                console.error('OnlyOffice error:', e.data);
            }
        };
        docEditor = new DocsAPI.DocEditor('onlyoffice-editor', config);
    });
    </script>
</body>
</html>
