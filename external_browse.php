<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$db        = getDB();
$user      = currentUser();

// Pastikan tabel ada (bisa diakses sebelum admin sempat setup)
$db->exec("
    CREATE TABLE IF NOT EXISTS external_storages (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(100)  NOT NULL,
        type        ENUM('ftp','sftp') DEFAULT 'ftp',
        host        VARCHAR(255)  NOT NULL,
        port        SMALLINT      NOT NULL DEFAULT 21,
        username    VARCHAR(100)  NOT NULL,
        password    VARCHAR(255)  NOT NULL,
        base_path   VARCHAR(500)  DEFAULT '/',
        passive     TINYINT(1)    DEFAULT 1,
        enabled     TINYINT(1)    DEFAULT 1,
        created_by  INT NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB
");

$storageId = (int)($_GET['storage'] ?? 0);
$curPath   = $_GET['path'] ?? '/';

// Normalisasi path
if (!$curPath || $curPath[0] !== '/') $curPath = '/' . $curPath;
$curPath = rtrim($curPath, '/') ?: '/';

// Ambil konfigurasi storage
$stmt = $db->prepare('SELECT * FROM external_storages WHERE id=? AND enabled=1');
$stmt->execute([$storageId]);
$es = $stmt->fetch();

if (!$es) {
    redirectWith(APP_URL . '/dashboard.php', 'danger', 'Storage eksternal tidak ditemukan atau tidak aktif.');
}

$ftpPass  = base64_decode($es['password']);
$basePath = rtrim($es['base_path'], '/') ?: '/';
$fullPath = $basePath . ($curPath === '/' ? '' : $curPath);
if (!$fullPath) $fullPath = '/';

// ── Helper: buka koneksi FTP ────────────────────────────────────────────────
function ftpConnect(array $es, string $pass): mixed {
    $conn = @ftp_connect($es['host'], $es['port'], 15);
    if (!$conn) return false;
    if (!@ftp_login($conn, $es['username'], $pass)) { ftp_close($conn); return false; }
    if ($es['passive']) ftp_pasv($conn, true);
    return $conn;
}

$error   = '';
$success = '';

// ── Aksi POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mkdir') {
        $folderName = preg_replace('/[\/\\\:\*\?"<>\|]/', '', trim($_POST['name'] ?? ''));
        if ($folderName) {
            $conn = ftpConnect($es, $ftpPass);
            if ($conn) {
                $newDir = $fullPath . '/' . $folderName;
                if (@ftp_mkdir($conn, $newDir)) {
                    $success = 'Folder "' . $folderName . '" berhasil dibuat.';
                } else {
                    $error = 'Gagal membuat folder. Mungkin sudah ada atau tidak ada izin.';
                }
                ftp_close($conn);
            } else {
                $error = 'Gagal terhubung ke storage.';
            }
        }

    } elseif ($action === 'upload') {
        $conn = ftpConnect($es, $ftpPass);
        if ($conn && !empty($_FILES['file']['tmp_name'])) {
            foreach ($_FILES['file']['tmp_name'] as $i => $tmp) {
                if (!is_uploaded_file($tmp)) continue;
                $origName = basename($_FILES['file']['name'][$i]);
                $origName = preg_replace('/[\/\\\:\*\?"<>\|]/', '_', $origName);
                $remotePath = $fullPath . '/' . $origName;
                if (@ftp_put($conn, $remotePath, $tmp, FTP_BINARY)) {
                    $success = 'File berhasil diupload.';
                } else {
                    $error = 'Gagal upload "' . $origName . '".';
                }
            }
            ftp_close($conn);
        } else {
            $error = 'Gagal terhubung atau tidak ada file yang dipilih.';
        }

    } elseif ($action === 'delete') {
        $target = $_POST['target'] ?? '';
        $type   = $_POST['ftype']  ?? 'file';
        if ($target) {
            $conn = ftpConnect($es, $ftpPass);
            if ($conn) {
                $remotePath = $fullPath . '/' . basename($target);
                if ($type === 'dir') {
                    @ftp_rmdir($conn, $remotePath);
                } else {
                    @ftp_delete($conn, $remotePath);
                }
                ftp_close($conn);
                $success = '"' . basename($target) . '" berhasil dihapus.';
            }
        }

    } elseif ($action === 'rename') {
        $oldName = basename($_POST['old_name'] ?? '');
        $newName = preg_replace('/[\/\\\:\*\?"<>\|]/', '', trim($_POST['new_name'] ?? ''));
        if ($oldName && $newName) {
            $conn = ftpConnect($es, $ftpPass);
            if ($conn) {
                @ftp_rename($conn, $fullPath . '/' . $oldName, $fullPath . '/' . $newName);
                ftp_close($conn);
                $success = 'Berhasil diganti nama.';
            }
        }
    }
}

// ── Download file ──────────────────────────────────────────────────────────
if (isset($_GET['download'])) {
    $target = basename($_GET['download']);
    $conn   = ftpConnect($es, $ftpPass);
    if ($conn) {
        $tmp = tempnam(sys_get_temp_dir(), 'ext_');
        if (@ftp_get($conn, $tmp, $fullPath . '/' . $target, FTP_BINARY)) {
            ftp_close($conn);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . rawurlencode($target) . '"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            unlink($tmp);
            exit;
        }
        ftp_close($conn);
        unlink($tmp);
    }
    die('Gagal mendownload file.');
}

// ── Daftar file/folder ─────────────────────────────────────────────────────
$items  = [];
$conn   = ftpConnect($es, $ftpPass);
$connOk = (bool)$conn;

if ($conn) {
    $rawList = @ftp_rawlist($conn, $fullPath);
    ftp_close($conn);

    if ($rawList) {
        foreach ($rawList as $line) {
            if (!$line) continue;
            // Parse format: drwxr-xr-x  2 user group 4096 Jan  1 12:00 filename
            $parts = preg_split('/\s+/', $line, 9);
            if (count($parts) < 9) continue;
            $name = $parts[8];
            if ($name === '.' || $name === '..') continue;
            $isDir = $parts[0][0] === 'd';
            $size  = (int)$parts[4];
            $items[] = [
                'name'  => $name,
                'is_dir'=> $isDir,
                'size'  => $size,
                'raw'   => $line,
            ];
        }
        // Sort: folder dulu, lalu file
        usort($items, fn($a,$b) => $b['is_dir'] <=> $a['is_dir'] ?: strcmp($a['name'],$b['name']));
    }
}

// ── Breadcrumb dari path ───────────────────────────────────────────────────
$breadcrumb = [];
$parts = array_filter(explode('/', $curPath), fn($p) => $p !== '');
$tmp   = '';
foreach ($parts as $p) {
    $tmp .= '/' . $p;
    $breadcrumb[] = ['name' => $p, 'path' => $tmp];
}

// Semua storage aktif untuk sidebar
$allStorages = $db->query('SELECT * FROM external_storages WHERE enabled=1 ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($es['name']) ?> — Storage Eksternal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --sidebar-width:240px; --sidebar-bg:#1e1b4b; --accent:#4f46e5; }
        body { background:#f3f4f6; font-family:'Segoe UI',sans-serif; }
        #sidebar {
            width:var(--sidebar-width); min-height:100vh; background:var(--sidebar-bg);
            position:fixed; top:0; left:0; display:flex; flex-direction:column; z-index:100;
        }
        .sidebar-brand { padding:1.25rem; border-bottom:1px solid rgba(255,255,255,.08); }
        .sidebar-brand span { color:white; font-weight:700; font-size:1.1rem; }
        .nav-item a {
            display:flex; align-items:center; gap:.65rem; padding:.6rem 1.25rem;
            color:rgba(255,255,255,.7); text-decoration:none; font-size:.9rem; transition:all .15s;
        }
        .nav-item a:hover, .nav-item a.active { background:rgba(255,255,255,.12); color:white; }
        .nav-section { padding:.5rem 1.25rem .25rem; font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.35); font-weight:600; }
        #main { margin-left:var(--sidebar-width); min-height:100vh; }
        .topbar { background:white; border-bottom:1px solid #e5e7eb; padding:.75rem 1.5rem; display:flex; align-items:center; gap:1rem; }
        .content { padding:1.5rem; }
        .breadcrumb { background:none; padding:0; font-size:.9rem; }
        .breadcrumb-item a { color:var(--accent); text-decoration:none; }
        .file-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:1rem; }
        .file-card {
            background:white; border-radius:12px; padding:1rem; cursor:pointer;
            transition:all .15s; border:2px solid transparent; position:relative;
        }
        .file-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); border-color:#e0e7ff; }
        .file-card .file-icon { font-size:2.5rem; margin-bottom:.5rem; display:block; }
        .file-card .file-name { font-size:.8rem; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#111827; }
        .file-card .file-meta { font-size:.7rem; color:#6b7280; margin-top:.2rem; }
        .file-card .file-actions { position:absolute; top:.5rem; right:.5rem; display:none; gap:.25rem; }
        .file-card:hover .file-actions { display:flex; }
        .file-actions .btn { padding:.2rem .4rem; font-size:.75rem; border-radius:6px; }
        .folder-card .file-icon { color:#f59e0b; }
        .error-banner { background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:1rem; color:#dc2626; }
    </style>
</head>
<body>

<div id="sidebar">
    <div class="sidebar-brand"><span><?= h(APP_NAME) ?></span></div>
    <nav style="padding:.75rem 0">
        <div class="nav-section">Navigasi</div>
        <div class="nav-item"><a href="<?= APP_URL ?>/dashboard.php"><i class="fa-solid fa-house fa-fw"></i> Dashboard</a></div>
        <?php if (!empty($allStorages)): ?>
        <div class="nav-section mt-2">Storage Eksternal</div>
        <?php foreach ($allStorages as $s): ?>
        <div class="nav-item">
            <a href="<?= APP_URL ?>/external_browse.php?storage=<?= $s['id'] ?>"
               class="<?= $s['id'] == $storageId ? 'active' : '' ?>">
                <i class="fa-solid fa-server fa-fw"></i> <?= h($s['name']) ?>
            </a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <div class="nav-section mt-2">Admin</div>
        <div class="nav-item"><a href="<?= APP_URL ?>/external_storage.php"><i class="fa-solid fa-gear fa-fw"></i> Kelola Storage</a></div>
        <?php endif; ?>
    </nav>
</div>

<div id="main">
    <div class="topbar">
        <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-sm btn-light">
            <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
        </a>
        <span class="fw-semibold"><i class="fa-solid fa-server me-2" style="color:#3b82f6"></i><?= h($es['name']) ?></span>
        <?php if (isAdmin()): ?>
        <a href="<?= APP_URL ?>/external_storage.php" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="fa-solid fa-gear me-1"></i> Kelola
        </a>
        <?php endif; ?>
    </div>

    <div class="content">

        <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>

        <?php if (!$connOk): ?>
        <div class="error-banner mb-4">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            Tidak dapat terhubung ke <strong><?= h($es['host']) ?></strong>.
            Periksa konfigurasi dan pastikan server FTP aktif.
        </div>
        <?php endif; ?>

        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-3">
                <li class="breadcrumb-item">
                    <a href="?storage=<?= $storageId ?>&path=/"><i class="fa-solid fa-server me-1"></i><?= h($es['name']) ?></a>
                </li>
                <?php foreach ($breadcrumb as $bc): ?>
                <li class="breadcrumb-item">
                    <a href="?storage=<?= $storageId ?>&path=<?= urlencode($bc['path']) ?>"><?= h($bc['name']) ?></a>
                </li>
                <?php endforeach; ?>
            </ol>
        </nav>

        <!-- Toolbar -->
        <div class="d-flex gap-2 mb-4 flex-wrap">
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal">
                <i class="fa-solid fa-arrow-up-from-bracket me-1"></i> Upload
            </button>
            <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#mkdirModal">
                <i class="fa-solid fa-folder-plus me-1"></i> Folder Baru
            </button>
        </div>

        <!-- File Grid -->
        <?php if (empty($items)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa-regular fa-folder-open fa-3x mb-3 opacity-25"></i>
            <div>Folder kosong</div>
        </div>
        <?php else: ?>
        <div class="file-grid">
            <!-- Tombol naik ke parent -->
            <?php if ($curPath !== '/'): ?>
            <?php
                $parentParts = explode('/', $curPath);
                array_pop($parentParts);
                $parentPath  = implode('/', $parentParts) ?: '/';
            ?>
            <div class="file-card folder-card"
                 ondblclick="window.location='?storage=<?= $storageId ?>&path=<?= urlencode($parentPath) ?>'">
                <span class="file-icon"><i class="fa-solid fa-folder-open"></i></span>
                <div class="file-name">..</div>
                <div class="file-meta">Ke atas</div>
            </div>
            <?php endif; ?>

            <?php foreach ($items as $item): ?>
            <?php $encName = urlencode($item['name']); ?>
            <?php if ($item['is_dir']): ?>
            <div class="file-card folder-card"
                 ondblclick="window.location='?storage=<?= $storageId ?>&path=<?= urlencode(rtrim($curPath,'/').'/'.$item['name']) ?>'">
                <span class="file-icon"><i class="fa-solid fa-folder"></i></span>
                <div class="file-name" title="<?= h($item['name']) ?>"><?= h($item['name']) ?></div>
                <div class="file-meta">Folder</div>
                <div class="file-actions">
                    <a href="?storage=<?= $storageId ?>&path=<?= urlencode(rtrim($curPath,'/').'/'.$item['name']) ?>"
                       class="btn btn-sm btn-light" title="Buka"><i class="fa-solid fa-folder-open"></i></a>
                    <button class="btn btn-sm btn-light"
                            onclick="openRename('<?= h(addslashes($item['name'])) ?>')" title="Ganti Nama">
                        <i class="fa-solid fa-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-light text-danger"
                            onclick="confirmDelete('<?= h(addslashes($item['name'])) ?>','dir')" title="Hapus">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div class="file-card">
                <span class="file-icon">
                    <i class="fa-solid <?= getFileIcon($item['name']) ?> <?= getFileIconColor($item['name']) ?>"></i>
                </span>
                <div class="file-name" title="<?= h($item['name']) ?>"><?= h($item['name']) ?></div>
                <div class="file-meta"><?= formatSize($item['size']) ?></div>
                <div class="file-actions">
                    <a href="?storage=<?= $storageId ?>&path=<?= urlencode($curPath) ?>&download=<?= $encName ?>"
                       class="btn btn-sm btn-light" title="Download">
                        <i class="fa-solid fa-download"></i>
                    </a>
                    <button class="btn btn-sm btn-light"
                            onclick="openRename('<?= h(addslashes($item['name'])) ?>')" title="Ganti Nama">
                        <i class="fa-solid fa-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-light text-danger"
                            onclick="confirmDelete('<?= h(addslashes($item['name'])) ?>','file')" title="Hapus">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Upload -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="upload">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-semibold">Upload File</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="file" name="file[]" multiple class="form-control">
                    <div class="form-text">Upload ke: <?= h($fullPath) ?></div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Folder Baru -->
<div class="modal fade" id="mkdirModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="mkdir">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-semibold">Folder Baru</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" name="name" class="form-control" placeholder="Nama folder" required autofocus>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">Buat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Ganti Nama -->
<div class="modal fade" id="renameModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="old_name" id="renameOld">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-semibold">Ganti Nama</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" name="new_name" id="renameNew" class="form-control" required>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Hapus -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="target" id="deleteTarget">
                <input type="hidden" name="ftype"  id="deleteFtype">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-semibold text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Hapus</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <p class="small mb-0">Hapus <strong id="deleteName"></strong>? Tindakan ini tidak dapat dibatalkan.</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script>
function openRename(name) {
    document.getElementById('renameOld').value = name;
    document.getElementById('renameNew').value = name;
    new bootstrap.Modal(document.getElementById('renameModal')).show();
}
function confirmDelete(name, type) {
    document.getElementById('deleteTarget').value = name;
    document.getElementById('deleteFtype').value  = type;
    document.getElementById('deleteName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>
