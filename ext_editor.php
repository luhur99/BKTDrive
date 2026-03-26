<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if (!ONLYOFFICE_ENABLED) {
    redirectWith(APP_URL . '/dashboard.php', 'warning',
        'Editor belum aktif. OnlyOffice Document Server diperlukan.');
}

$user      = currentUser();
$storageId = (int)($_GET['storage'] ?? 0);
$filePath  = $_GET['path'] ?? '';

if (!$storageId || !$filePath) { die('Parameter tidak valid.'); }

$db   = getDB();
$stmt = $db->prepare('SELECT * FROM external_storages WHERE id = ? AND enabled = 1');
$stmt->execute([$storageId]);
$es   = $stmt->fetch();
if (!$es) { redirectWith(APP_URL . '/dashboard.php', 'danger', 'Storage tidak ditemukan.'); }

$filename = basename($filePath);
if (!isOfficeFile($filename)) { die('Bukan file Office yang didukung.'); }

function jwtEncode(array $payload, string $secret): string {
    $h = str_replace(['+','/',  '='], ['-','_',''], base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])));
    $p = str_replace(['+','/',  '='], ['-','_',''], base64_encode(json_encode($payload)));
    $s = str_replace(['+','/', '='],  ['-','_',''], base64_encode(hash_hmac('sha256', "$h.$p", $secret, true)));
    return "$h.$p.$s";
}

$token       = hash_hmac('sha256', $storageId . $filePath, ONLYOFFICE_JWT_SECRET);
$docKey      = substr(md5('ext-' . $storageId . '-' . $filePath . '-' . session_id() . '-' . time()), 0, 20);
$documentUrl = APP_INTERNAL_URL . '/ext_file.php?storage=' . $storageId
             . '&path=' . urlencode($filePath) . '&token=' . $token;
$callbackUrl = APP_INTERNAL_URL . '/ext_callback.php?storage=' . $storageId
             . '&path=' . urlencode($filePath) . '&token=' . $token;
$saveUrl     = APP_URL . '/ext_save.php?storage=' . $storageId
             . '&path=' . urlencode($filePath) . '&token=' . $token;
$parentPath  = dirname($filePath);
$backUrl     = APP_URL . '/external_browse.php?storage=' . $storageId
             . '&path=' . urlencode($parentPath);
$fileExt     = getFileExtension($filename);

$config = [
    'document' => [
        'fileType'    => $fileExt,
        'key'         => $docKey,
        'title'       => $filename,
        'url'         => $documentUrl,
        'permissions' => ['edit' => true, 'download' => true],
    ],
    'documentType' => getDocumentType($filename),
    'editorConfig' => [
        'mode'        => 'edit',
        'callbackUrl' => $callbackUrl,
        'lang'        => 'id',
        'user'        => ['id' => (string)$user['id'], 'name' => $user['name']],
        'customization' => [
            'autosave'      => false,
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
    <title><?= h($filename) ?> — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { display: flex; flex-direction: column; height: 100vh; background: #1e1b4b; font-family: 'Segoe UI', sans-serif; }

        /* ── Topbar sits above the iframe in the flex stack ── */
        .topbar {
            background: #312e81;
            color: white;
            padding: .45rem 1rem;
            display: flex; align-items: center; gap: .75rem;
            border-bottom: 2px solid #4f46e5;
            flex-shrink: 0;
            /* stacking above the OO iframe */
            position: relative; z-index: 1000;
            min-height: 44px;
        }
        .topbar a { color: rgba(255,255,255,.75); text-decoration: none; font-size: .85rem; }
        .topbar a:hover { color: white; }
        .topbar .sep { color: rgba(255,255,255,.3); }
        .topbar .filename { color: white; font-weight: 600; font-size: .9rem;
            flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .topbar .storage-name { color: rgba(255,255,255,.45); font-size: .78rem; white-space: nowrap; }

        #btn-save {
            background: #4f46e5; color: white;
            border: none; padding: .38rem 1rem;
            border-radius: 6px; cursor: pointer; font-size: .85rem;
            display: flex; align-items: center; gap: .4rem;
            white-space: nowrap; flex-shrink: 0;
            transition: background .15s;
        }
        #btn-save:hover:not(:disabled) { background: #4338ca; }
        #btn-save:disabled { background: #6b7280; cursor: not-allowed; }

        #save-status {
            font-size: .78rem; white-space: nowrap; flex-shrink: 0;
        }

        #onlyoffice-editor { flex: 1; min-height: 0; overflow: hidden; }
    </style>
</head>
<body>

<div class="topbar">
    <a href="<?= h($backUrl) ?>"><i class="fa-solid fa-arrow-left"></i> Kembali</a>
    <span class="sep">/</span>
    <span class="filename"><?= h($filename) ?></span>
    <span class="storage-name"><?= h($es['name']) ?></span>
    <button id="btn-save" onclick="doSave()">
        <i class="fa-solid fa-floppy-disk"></i> Simpan ke FTP
    </button>
    <span id="save-status"></span>
</div>

<div id="onlyoffice-editor"></div>

<script src="<?= h(ONLYOFFICE_SERVER) ?>/web-apps/apps/api/documents/api.js"></script>
<script>
const SAVE_URL = <?= json_encode($saveUrl) ?>;
const FILE_EXT = <?= json_encode($fileExt) ?>;

let docEditor    = null;
let saveInProgress = false;

function setSaveStatus(msg, color) {
    const el = document.getElementById('save-status');
    el.textContent = msg;
    el.style.color = color || 'rgba(255,255,255,.8)';
}

function onSavedOK() {
    saveInProgress = false;
    document.getElementById('btn-save').disabled = false;
    setSaveStatus('✓ Tersimpan ke FTP', '#86efac');
    setTimeout(() => setSaveStatus(''), 4000);
}

function onSaveFail(msg) {
    saveInProgress = false;
    document.getElementById('btn-save').disabled = false;
    setSaveStatus('✗ ' + (msg || 'Gagal'), '#fca5a5');
    setTimeout(() => setSaveStatus(''), 5000);
}

function doSave() {
    if (!docEditor || saveInProgress) return;
    saveInProgress = true;
    document.getElementById('btn-save').disabled = true;
    setSaveStatus('Menyimpan…');
    // downloadAs generates a snapshot URL — does NOT trigger version-changed.
    docEditor.downloadAs(FILE_EXT);
}

const config = <?= $configJson ?>;
config.events = {
    // Fires after downloadAs() — contains the URL to the generated file.
    onDownloadAs: function(event) {
        fetch(SAVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url: event.data.url })
        })
        .then(r => r.json())
        .then(d => d.error === 0 ? onSavedOK() : onSaveFail(d.message))
        .catch(() => onSaveFail('Koneksi gagal'));
    },
    // Fires when Ctrl+S is pressed inside OO (triggers internal forceSave / status=6).
    // The file IS saved via the callbackUrl. We just acknowledge it and suppress the reload.
    onOutdatedVersion: function() {
        setSaveStatus('✓ Tersimpan (Ctrl+S)', '#86efac');
        setTimeout(() => setSaveStatus(''), 4000);
        if (saveInProgress) {
            saveInProgress = false;
            document.getElementById('btn-save').disabled = false;
        }
    },
    onError: function(e) {
        if (saveInProgress) onSaveFail('Error editor');
        console.error('OnlyOffice error:', e.data);
    }
};

document.addEventListener('DOMContentLoaded', function() {
    docEditor = new DocsAPI.DocEditor('onlyoffice-editor', config);
});
</script>
</body>
</html>
