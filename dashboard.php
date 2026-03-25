<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$user     = currentUser();
$db       = getDB();
$view     = $_GET['view']   ?? 'mine';    // mine | shared | recent | team
$folderId = isset($_GET['folder']) ? (int)$_GET['folder'] : null;
$search   = trim($_GET['q'] ?? '');

// ── Validasi akses folder ────────────────────────────────────
$currentFolder = null;
if ($folderId) {
    $stmt = $db->prepare('SELECT * FROM folders WHERE id = ?');
    $stmt->execute([$folderId]);
    $currentFolder = $stmt->fetch();
    if (!$currentFolder) {
        redirectWith(APP_URL . '/dashboard.php', 'danger', 'Folder tidak ditemukan.');
    }
}

// ── Breadcrumb ───────────────────────────────────────────────
$breadcrumb = [];
if ($folderId) {
    $tempId = $folderId;
    while ($tempId) {
        $stmt = $db->prepare('SELECT * FROM folders WHERE id = ?');
        $stmt->execute([$tempId]);
        $f = $stmt->fetch();
        if (!$f) break;
        array_unshift($breadcrumb, $f);
        $tempId = $f['parent_id'];
    }
}

// ── Query data ───────────────────────────────────────────────
$folders = [];
$files   = [];

if ($search) {
    $like = '%' . $search . '%';
    $stmt = $db->prepare('
        SELECT f.*, u.name as owner_name
        FROM files f
        JOIN users u ON f.owner_id = u.id
        WHERE (f.owner_id = ? OR EXISTS (
            SELECT 1 FROM shares s
            WHERE s.resource_type="file" AND s.resource_id=f.id
              AND (s.shared_with=? OR s.shared_with IS NULL)
        ))
        AND f.original_name LIKE ?
        ORDER BY f.original_name
    ');
    $stmt->execute([$user['id'], $user['id'], $like]);
    $files = $stmt->fetchAll();

} elseif ($view === 'mine') {
    $folderCond = $folderId ? 'parent_id = ?' : 'parent_id IS NULL';
    $fileCond   = $folderId ? 'folder_id = ?'  : 'folder_id IS NULL';
    $params     = $folderId ? [$user['id'], $folderId] : [$user['id']];

    $stmt = $db->prepare("SELECT * FROM folders WHERE owner_id = ? AND $folderCond ORDER BY name");
    $stmt->execute($params);
    $folders = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT f.*, u.name as owner_name FROM files f
        JOIN users u ON f.owner_id = u.id
        WHERE f.owner_id = ? AND f.$fileCond ORDER BY f.original_name
    ");
    $stmt->execute($params);
    $files = $stmt->fetchAll();

} elseif ($view === 'shared') {
    $stmt = $db->prepare('
        SELECT f.*, u.name as owner_name FROM files f
        JOIN users u ON f.owner_id = u.id
        JOIN shares s ON s.resource_type="file" AND s.resource_id=f.id
        WHERE (s.shared_with = ? OR s.shared_with IS NULL)
          AND f.owner_id != ?
        GROUP BY f.id
        ORDER BY f.original_name
    ');
    $stmt->execute([$user['id'], $user['id']]);
    $files = $stmt->fetchAll();

} elseif ($view === 'recent') {
    $stmt = $db->prepare('
        SELECT f.*, u.name as owner_name FROM files f
        JOIN users u ON f.owner_id = u.id
        WHERE f.owner_id = ?
        ORDER BY f.updated_at DESC LIMIT 30
    ');
    $stmt->execute([$user['id']]);
    $files = $stmt->fetchAll();

} elseif ($view === 'team') {
    if ($folderId) {
        // Dalam folder bersama: tampilkan semua subfolder dan file
        $stmt = $db->prepare('SELECT fo.*, u.name as owner_name FROM folders fo JOIN users u ON fo.owner_id=u.id WHERE fo.parent_id = ? ORDER BY fo.name');
        $stmt->execute([$folderId]);
        $folders = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT f.*, u.name as owner_name FROM files f JOIN users u ON f.owner_id=u.id WHERE f.folder_id = ? ORDER BY f.original_name');
        $stmt->execute([$folderId]);
        $files = $stmt->fetchAll();
    } else {
        // Root: folder yang dishare ke semua anggota
        $stmt = $db->prepare('
            SELECT fo.*, u.name as owner_name FROM folders fo
            JOIN users u ON fo.owner_id = u.id
            JOIN shares s ON s.resource_type="folder" AND s.resource_id=fo.id
            WHERE s.shared_with IS NULL AND fo.parent_id IS NULL
            ORDER BY fo.name
        ');
        $stmt->execute();
        $folders = $stmt->fetchAll();
    }
}

// ── Semua user untuk modal share ────────────────────────────
$stmt    = $db->query('SELECT id, name, email FROM users ORDER BY name');
$allUsers = $stmt->fetchAll();

// ── External storages untuk sidebar (semua anggota bisa lihat) ──────────────
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
$extStorages = $db->query('SELECT id, name FROM external_storages WHERE enabled=1 ORDER BY name')->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --sidebar-width: 240px;
            --sidebar-bg: #1e1b4b;
            --sidebar-hover: rgba(255,255,255,0.08);
            --sidebar-active: rgba(255,255,255,0.15);
            --accent: #4f46e5;
        }
        body { background: #f3f4f6; font-family: 'Segoe UI', sans-serif; }

        #sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: var(--sidebar-bg);
            position: fixed;
            top: 0; left: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            overflow-y: auto;
        }
        .sidebar-brand {
            padding: 1.25rem 1.25rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .sidebar-brand .logo-icon {
            width: 36px; height: 36px;
            background: linear-gradient(135deg,#4f46e5,#7c3aed);
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            color: white; font-size: 1rem;
        }
        .sidebar-brand span { color: white; font-weight: 700; font-size: 1.1rem; }
        .sidebar-nav { padding: 0.75rem 0; flex: 1; }
        .nav-item a {
            display: flex; align-items: center; gap: 0.65rem;
            padding: 0.6rem 1.25rem;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.15s;
        }
        .nav-item a:hover  { background: var(--sidebar-hover); color: white; }
        .nav-item a.active { background: var(--sidebar-active); color: white; font-weight: 600; }
        .nav-item a .fa-fw { width: 18px; }
        .nav-section {
            padding: 0.5rem 1.25rem 0.25rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255,255,255,0.35);
            font-weight: 600;
        }
        .sidebar-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid rgba(255,255,255,0.08);
        }
        .user-info { color: rgba(255,255,255,0.8); font-size: 0.85rem; }
        .user-info .name { font-weight: 600; color: white; }

        #main { margin-left: var(--sidebar-width); min-height: 100vh; }

        .topbar {
            background: white;
            border-bottom: 1px solid #e5e7eb;
            padding: 0.75rem 1.5rem;
            display: flex; align-items: center; gap: 1rem;
            position: sticky; top: 0; z-index: 50;
        }
        .search-box { flex: 1; max-width: 400px; position: relative; }
        .search-box input {
            padding-left: 2.5rem;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        .search-box input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
            background: white;
        }
        .search-box .fa-magnifying-glass {
            position: absolute; left: 0.75rem; top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .content { padding: 1.5rem; }
        .breadcrumb { background: none; padding: 0; margin-bottom: 1rem; font-size: 0.9rem; }
        .breadcrumb-item a { color: var(--accent); text-decoration: none; }
        .breadcrumb-item a:hover { text-decoration: underline; }

        .toolbar {
            display: flex; align-items: center; gap: 0.5rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .btn-upload {
            background: var(--accent); color: white; border: none;
            border-radius: 8px; padding: 0.5rem 1rem;
            font-weight: 600; font-size: 0.875rem;
            cursor: pointer; transition: all 0.15s;
        }
        .btn-upload:hover { background: #4338ca; transform: translateY(-1px); }

        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 1rem;
        }
        .file-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.15s;
            border: 2px solid transparent;
            position: relative;
        }
        .file-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); border-color: #e0e7ff; }
        .file-card .file-icon { font-size: 2.5rem; margin-bottom: 0.5rem; display: block; }
        .file-card .file-name {
            font-size: 0.8rem; font-weight: 600;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            color: #111827;
        }
        .file-card .file-meta { font-size: 0.7rem; color: #6b7280; margin-top: 0.2rem; }
        .file-card .file-actions {
            position: absolute; top: 0.5rem; right: 0.5rem;
            display: none; gap: 0.25rem;
        }
        .file-card:hover .file-actions { display: flex; }
        .file-actions .btn { padding: 0.2rem 0.4rem; font-size: 0.75rem; border-radius: 6px; }
        .folder-card .file-icon { color: #f59e0b; }
        .folder-shared .file-icon { color: #10b981; }
        .empty-state { text-align: center; padding: 4rem 1rem; color: #9ca3af; }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; }
        .flash-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; min-width: 280px; }
        .badge-owner { font-size: 0.65rem; }
        .shared-badge { position: absolute; bottom: 0.4rem; left: 0.5rem; font-size: 0.65rem; color: #6b7280; }
        .owner-badge  { position: absolute; bottom: 0.4rem; left: 0.5rem; font-size: 0.65rem; color: #6b7280; }

        @media (max-width: 768px) {
            #sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            #sidebar.show { transform: translateX(0); }
            #main { margin-left: 0; }
        }
    </style>
</head>
<body>

<!-- Flash Message -->
<?php if ($flash): ?>
<div class="flash-container">
    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible shadow-sm" role="alert">
        <?= h($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<!-- Sidebar -->
<div id="sidebar">
    <div class="sidebar-brand d-flex align-items-center gap-2">
        <div class="logo-icon"><i class="fa-solid fa-cloud"></i></div>
        <span><?= h(APP_NAME) ?></span>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">File</div>
        <div class="nav-item">
            <a href="dashboard.php?view=mine" class="<?= $view === 'mine' && !$search ? 'active' : '' ?>">
                <i class="fa-solid fa-folder-open fa-fw"></i> File Saya
            </a>
        </div>
        <div class="nav-item">
            <a href="dashboard.php?view=shared" class="<?= $view === 'shared' ? 'active' : '' ?>">
                <i class="fa-solid fa-share-nodes fa-fw"></i> Dibagikan ke Saya
            </a>
        </div>
        <div class="nav-item">
            <a href="dashboard.php?view=team" class="<?= $view === 'team' ? 'active' : '' ?>">
                <i class="fa-solid fa-people-group fa-fw"></i> Folder Bersama
            </a>
        </div>
        <div class="nav-item">
            <a href="dashboard.php?view=recent" class="<?= $view === 'recent' ? 'active' : '' ?>">
                <i class="fa-solid fa-clock-rotate-left fa-fw"></i> Terbaru
            </a>
        </div>

        <?php if (!empty($extStorages)): ?>
        <div class="nav-section mt-2">Storage Eksternal</div>
        <?php foreach ($extStorages as $es): ?>
        <div class="nav-item">
            <a href="external_browse.php?storage=<?= $es['id'] ?>">
                <i class="fa-solid fa-server fa-fw"></i> <?= h($es['name']) ?>
            </a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (isAdmin()): ?>
        <div class="nav-section mt-2">Admin</div>
        <div class="nav-item">
            <a href="monitor.php">
                <i class="fa-solid fa-chart-line fa-fw"></i> Monitor
            </a>
        </div>
        <div class="nav-item">
            <a href="admin.php">
                <i class="fa-solid fa-users fa-fw"></i> Pengguna
            </a>
        </div>
        <div class="nav-item">
            <a href="external_storage.php">
                <i class="fa-solid fa-server fa-fw"></i> Storage Eksternal
            </a>
        </div>
        <div class="nav-item">
            <a href="backup_schedule.php">
                <i class="fa-solid fa-clock-rotate-left fa-fw"></i> Backup Otomatis
            </a>
        </div>
        <div class="nav-item">
            <a href="backup.php">
                <i class="fa-solid fa-file-zipper fa-fw"></i> Backup Manual
            </a>
        </div>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="name"><?= h($user['name']) ?></div>
            <div class="text-truncate" style="max-width:180px;font-size:0.78rem;opacity:.7"><?= h($user['email']) ?></div>
        </div>
        <a href="logout.php" class="btn btn-sm mt-2 w-100"
           style="background:rgba(255,255,255,0.1);color:rgba(255,255,255,0.8);border:1px solid rgba(255,255,255,0.15)">
            <i class="fa-solid fa-right-from-bracket me-1"></i> Keluar
        </a>
    </div>
</div>

<!-- Main -->
<div id="main">
    <!-- Topbar -->
    <div class="topbar">
        <button class="btn btn-sm d-md-none" onclick="document.getElementById('sidebar').classList.toggle('show')">
            <i class="fa-solid fa-bars"></i>
        </button>
        <form class="search-box" method="GET" action="dashboard.php">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Cari file..."
                   value="<?= h($search) ?>">
        </form>
        <div class="ms-auto d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark border">
                <i class="fa-solid fa-circle-user me-1" style="color:#4f46e5"></i>
                <?= h($user['role'] === 'admin' ? 'Admin' : 'Anggota') ?>
            </span>
        </div>
    </div>

    <!-- Content -->
    <div class="content">

        <!-- Breadcrumb -->
        <?php if ($view === 'mine' && !$search): ?>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="dashboard.php?view=mine"><i class="fa-solid fa-house me-1"></i>File Saya</a>
                </li>
                <?php foreach ($breadcrumb as $bc): ?>
                <li class="breadcrumb-item">
                    <a href="dashboard.php?view=mine&folder=<?= $bc['id'] ?>"><?= h($bc['name']) ?></a>
                </li>
                <?php endforeach; ?>
            </ol>
        </nav>

        <?php elseif ($view === 'team' && !$search): ?>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="dashboard.php?view=team"><i class="fa-solid fa-people-group me-1"></i>Folder Bersama</a>
                </li>
                <?php foreach ($breadcrumb as $bc): ?>
                <li class="breadcrumb-item">
                    <a href="dashboard.php?view=team&folder=<?= $bc['id'] ?>"><?= h($bc['name']) ?></a>
                </li>
                <?php endforeach; ?>
            </ol>
        </nav>

        <?php elseif ($search): ?>
        <div class="mb-3 text-muted">
            Hasil pencarian untuk <strong>"<?= h($search) ?>"</strong>
            — <a href="dashboard.php">Bersihkan</a>
        </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <?php if (($view === 'mine' || $view === 'team') && !$search): ?>
        <div class="toolbar">
            <?php if ($view === 'mine'): ?>
            <label class="btn-upload mb-0" style="cursor:pointer">
                <i class="fa-solid fa-arrow-up-from-bracket me-1"></i> Upload File
                <input type="file" id="fileInput" multiple style="display:none" onchange="uploadFiles(this.files)">
            </label>
            <?php elseif ($view === 'team' && $folderId): ?>
            <label class="btn-upload mb-0" style="cursor:pointer">
                <i class="fa-solid fa-arrow-up-from-bracket me-1"></i> Upload ke Folder Ini
                <input type="file" id="fileInput" multiple style="display:none" onchange="uploadFiles(this.files)">
            </label>
            <?php endif; ?>
            <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#newFolderModal">
                <i class="fa-solid fa-folder-plus me-1"></i>
                <?= $view === 'team' ? 'Folder Baru' : 'Folder Baru' ?>
            </button>
            <div class="ms-auto text-muted small">
                <?= count($folders) ?> folder, <?= count($files) ?> file
            </div>
        </div>

        <!-- Upload progress -->
        <div id="uploadProgress" class="mb-3" style="display:none">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="small text-muted" id="uploadStatus">Mengupload...</span>
            </div>
            <div class="progress" style="height:6px">
                <div id="progressBar" class="progress-bar" style="background:#4f46e5;width:0%"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Empty state -->
        <?php if (empty($folders) && empty($files)): ?>
        <div class="empty-state">
            <i class="fa-regular fa-folder-open text-muted"></i>
            <div class="fw-semibold text-dark mt-2">Belum ada file</div>
            <div class="small text-muted mt-1">
                <?php if ($view === 'mine'): ?>
                    Upload file atau buat folder baru untuk memulai.
                <?php elseif ($view === 'shared'): ?>
                    Belum ada file yang dibagikan ke Anda.
                <?php elseif ($view === 'team'): ?>
                    Belum ada folder bersama. Buat folder baru dan centang "Jadikan folder bersama".
                <?php else: ?>
                    Belum ada file terbaru.
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>

        <!-- File Grid -->
        <div class="file-grid">
            <!-- Folders -->
            <?php foreach ($folders as $folder): ?>
            <?php $isSharedFolder = isset($folder['owner_name']); ?>
            <div class="file-card <?= $view === 'team' ? 'folder-shared' : 'folder-card' ?>"
                 ondblclick="window.location='dashboard.php?view=<?= $view ?>&folder=<?= $folder['id'] ?>'">
                <span class="file-icon">
                    <i class="fa-solid <?= $view === 'team' ? 'fa-folder-heart' : 'fa-folder' ?>"></i>
                </span>
                <div class="file-name" title="<?= h($folder['name']) ?>"><?= h($folder['name']) ?></div>
                <div class="file-meta"><?= formatDate($folder['created_at']) ?></div>
                <?php if (isset($folder['owner_name']) && $folder['owner_id'] != $user['id']): ?>
                <div class="owner-badge"><i class="fa-solid fa-user me-1"></i><?= h($folder['owner_name']) ?></div>
                <?php endif; ?>
                <div class="file-actions">
                    <a href="dashboard.php?view=<?= $view ?>&folder=<?= $folder['id'] ?>"
                       class="btn btn-sm btn-light" title="Buka">
                        <i class="fa-solid fa-folder-open"></i>
                    </a>
                    <?php if ($folder['owner_id'] == $user['id']): ?>
                    <button class="btn btn-sm btn-light"
                            onclick="openFolderRenameModal(<?= $folder['id'] ?>,'<?= h(addslashes($folder['name'])) ?>',<?= $folderId ?? 'null' ?>,'<?= $view ?>')"
                            title="Ganti Nama">
                        <i class="fa-solid fa-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-light"
                            onclick="openShareModal('folder',<?= $folder['id'] ?>,<?= $folderId ?? 'null' ?>)"
                            title="Bagikan">
                        <i class="fa-solid fa-share-nodes"></i>
                    </button>
                    <button class="btn btn-sm btn-light text-danger"
                            onclick="confirmDelete('folder',<?= $folder['id'] ?>,<?= $folderId ?? 'null' ?>,'<?= h(addslashes($folder['name'])) ?>')"
                            title="Hapus">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Files -->
            <?php foreach ($files as $file): ?>
            <div class="file-card">
                <span class="file-icon">
                    <i class="fa-solid <?= getFileIcon($file['original_name']) ?> <?= getFileIconColor($file['original_name']) ?>"></i>
                </span>
                <div class="file-name" title="<?= h($file['original_name']) ?>"><?= h($file['original_name']) ?></div>
                <div class="file-meta"><?= formatSize($file['size']) ?></div>
                <div class="file-meta"><?= formatDate($file['updated_at']) ?></div>
                <?php if ($file['owner_id'] != $user['id']): ?>
                <div class="shared-badge"><i class="fa-solid fa-share-nodes me-1"></i><?= h($file['owner_name']) ?></div>
                <?php endif; ?>
                <div class="file-actions">
                    <?php if (isViewableFile($file['original_name'])): ?>
                    <a href="viewer.php?id=<?= $file['id'] ?>"
                       class="btn btn-sm btn-light" title="Buka" target="_blank">
                        <i class="fa-solid fa-eye"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (isOfficeFile($file['original_name']) && ONLYOFFICE_ENABLED): ?>
                    <a href="editor.php?id=<?= $file['id'] ?>"
                       class="btn btn-sm btn-primary" title="Edit" target="_blank">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </a>
                    <?php endif; ?>
                    <a href="download.php?id=<?= $file['id'] ?>"
                       class="btn btn-sm btn-light" title="Download">
                        <i class="fa-solid fa-download"></i>
                    </a>
                    <?php if ($file['owner_id'] == $user['id']): ?>
                    <button class="btn btn-sm btn-light"
                            onclick="openShareModal('file',<?= $file['id'] ?>,<?= $folderId ?? 'null' ?>)"
                            title="Bagikan">
                        <i class="fa-solid fa-share-nodes"></i>
                    </button>
                    <button class="btn btn-sm btn-light"
                            onclick="openRenameModal(<?= $file['id'] ?>,'<?= h(addslashes($file['original_name'])) ?>',<?= $folderId ?? 'null' ?>,'<?= $view ?>')"
                            title="Ganti Nama">
                        <i class="fa-solid fa-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-light text-danger"
                            onclick="confirmDelete('file',<?= $file['id'] ?>,<?= $folderId ?? 'null' ?>,'<?= h(addslashes($file['original_name'])) ?>')"
                            title="Hapus">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Modal: Folder Baru ──────────────────────────────── -->
<div class="modal fade" id="newFolderModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="folder_create.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="parent_id" value="<?= $folderId ?? '' ?>">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-semibold">Folder Baru</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" name="name" class="form-control mb-3" placeholder="Nama folder" required autofocus>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_shared" id="isSharedCheck"
                               <?= $view === 'team' ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="isSharedCheck">
                            <i class="fa-solid fa-people-group me-1 text-success"></i>
                            Folder Bersama (dapat diakses semua anggota)
                        </label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">Buat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─── Modal: Ganti Nama ───────────────────────────────── -->
<div class="modal fade" id="renameModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="rename.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="type"      id="renameType">
                <input type="hidden" name="id"        id="renameId">
                <input type="hidden" name="folder_id" id="renameFolderId">
                <input type="hidden" name="view"      id="renameView">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-semibold" id="renameModalTitle">Ganti Nama</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" name="name" id="renameName" class="form-control" required>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─── Modal: Konfirmasi Hapus ────────────────────────── -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="delete.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="type" id="deleteType">
                <input type="hidden" name="id" id="deleteId">
                <input type="hidden" name="folder_id" id="deleteFolderId">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-semibold text-danger">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Hapus
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <p class="mb-0 small">Hapus <strong id="deleteName"></strong>? Tindakan ini tidak bisa dibatalkan.</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─── Modal: Bagikan ──────────────────────────────────── -->
<div class="modal fade" id="shareModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="share.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="resource_type" id="shareType">
                <input type="hidden" name="resource_id"   id="shareId">
                <input type="hidden" name="folder_id"     id="shareFolderId">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-semibold">
                        <i class="fa-solid fa-share-nodes me-1 text-primary"></i> Bagikan
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Bagikan kepada</label>
                        <select name="shared_with" class="form-select form-select-sm">
                            <option value="">Semua anggota tim</option>
                            <?php foreach ($allUsers as $u): ?>
                                <?php if ($u['id'] != $user['id']): ?>
                                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?> (<?= h($u['email']) ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Izin akses</label>
                        <select name="permission" class="form-select form-select-sm">
                            <option value="view">Lihat saja</option>
                            <option value="edit">Lihat & Edit</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">Bagikan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script>
// ── Upload files ─────────────────────────────────────────
function uploadFiles(files) {
    if (!files.length) return;
    const formData = new FormData();
    for (let f of files) formData.append('files[]', f);
    formData.append('folder_id', '<?= $folderId ?? '' ?>');
    formData.append('csrf_token', '<?= csrfToken() ?>');

    document.getElementById('uploadProgress').style.display = 'block';
    document.getElementById('uploadStatus').textContent = 'Mengupload ' + files.length + ' file...';

    const xhr = new XMLHttpRequest();
    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            document.getElementById('progressBar').style.width = pct + '%';
        }
    };
    xhr.onload = () => {
        const res = JSON.parse(xhr.responseText);
        if (res.success) {
            document.getElementById('uploadStatus').textContent = 'Upload selesai!';
            setTimeout(() => location.reload(), 800);
        } else {
            document.getElementById('uploadStatus').textContent = 'Error: ' + res.message;
        }
    };
    xhr.onerror = () => {
        document.getElementById('uploadStatus').textContent = 'Upload gagal.';
    };
    xhr.open('POST', '<?= APP_URL ?>/upload.php');
    xhr.send(formData);
}

// Drag & drop
const main = document.getElementById('main');
main.addEventListener('dragover', e => { e.preventDefault(); });
main.addEventListener('drop', e => {
    e.preventDefault();
    uploadFiles(e.dataTransfer.files);
});

// ── Modals ───────────────────────────────────────────────
function openRenameModal(id, name, folderId, view) {
    document.getElementById('renameType').value     = 'file';
    document.getElementById('renameId').value       = id;
    document.getElementById('renameName').value     = name;
    document.getElementById('renameFolderId').value = folderId || '';
    document.getElementById('renameView').value     = view || 'mine';
    document.getElementById('renameModalTitle').textContent = 'Ganti Nama File';
    new bootstrap.Modal(document.getElementById('renameModal')).show();
}

function openFolderRenameModal(id, name, folderId, view) {
    document.getElementById('renameType').value     = 'folder';
    document.getElementById('renameId').value       = id;
    document.getElementById('renameName').value     = name;
    document.getElementById('renameFolderId').value = folderId || '';
    document.getElementById('renameView').value     = view || 'mine';
    document.getElementById('renameModalTitle').textContent = 'Ganti Nama Folder';
    new bootstrap.Modal(document.getElementById('renameModal')).show();
}

function confirmDelete(type, id, folderId, name) {
    document.getElementById('deleteType').value     = type;
    document.getElementById('deleteId').value       = id;
    document.getElementById('deleteFolderId').value = folderId || '';
    document.getElementById('deleteName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function openShareModal(type, id, folderId) {
    document.getElementById('shareType').value     = type;
    document.getElementById('shareId').value       = id;
    document.getElementById('shareFolderId').value = folderId || '';
    new bootstrap.Modal(document.getElementById('shareModal')).show();
}

// ── Auto-dismiss flash ───────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.flash-container .alert').forEach(el => {
        bootstrap.Alert.getOrCreateInstance(el).close();
    });
}, 4000);
</script>
</body>
</html>
