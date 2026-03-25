<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAdmin();

$db    = getDB();
$user  = currentUser();
$flash = getFlash();
$action = $_GET['action'] ?? '';

// ── Proses backup ──────────────────────────────────────────────────────────
if ($action === 'download') {
    if (!class_exists('ZipArchive')) {
        die('ZipArchive tidak tersedia. Aktifkan ekstensi php_zip di php.ini.');
    }

    $timestamp = date('Y-m-d_H-i-s');
    $zipName   = 'backup_' . $timestamp . '.zip';
    $zipPath   = sys_get_temp_dir() . '/' . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        die('Gagal membuat file backup.');
    }

    // 1. Tambahkan semua file dari storage/
    $storageDir = STORAGE_PATH;
    if (is_dir($storageDir)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storageDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            $filePath     = $file->getRealPath();
            $relativePath = 'storage/' . substr($filePath, strlen(realpath($storageDir)) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }

    // 2. Export database sebagai SQL
    $sql  = "-- LuhurWorkspace Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = ['users','folders','files','shares'];
    foreach ($tables as $table) {
        // Struktur tabel
        $row = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $row[1] . ";\n\n";

        // Data
        $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $cols  = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $sql  .= "INSERT INTO `$table` ($cols) VALUES\n";
            $vals  = [];
            foreach ($rows as $r) {
                $escaped = array_map(function($v) use ($db) {
                    if ($v === null) return 'NULL';
                    return $db->quote($v);
                }, array_values($r));
                $vals[] = '(' . implode(', ', $escaped) . ')';
            }
            $sql .= implode(",\n", $vals) . ";\n\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $zip->addFromString('database.sql', $sql);

    // 3. Tambahkan README
    $readme  = "LuhurWorkspace Backup\n";
    $readme .= "=====================\n";
    $readme .= "Tanggal : " . date('d M Y H:i:s') . "\n";
    $readme .= "Dibuat oleh: " . $user['name'] . " (" . $user['email'] . ")\n\n";
    $readme .= "Isi backup:\n";
    $readme .= "  storage/   - Semua file yang diupload\n";
    $readme .= "  database.sql - Export database MySQL\n\n";
    $readme .= "Cara restore:\n";
    $readme .= "  1. Salin folder storage/ ke direktori aplikasi\n";
    $readme .= "  2. Import database.sql ke MySQL\n";
    $zip->addFromString('README.txt', $readme);

    $zip->close();

    // Kirim file ke browser
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache');
    readfile($zipPath);
    unlink($zipPath);
    exit;
}

// ── Hitung statistik ───────────────────────────────────────────────────────
$stats = [];
$stats['users']   = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$stats['files']   = $db->query('SELECT COUNT(*) FROM files')->fetchColumn();
$stats['folders'] = $db->query('SELECT COUNT(*) FROM folders')->fetchColumn();
$stats['size']    = $db->query('SELECT COALESCE(SUM(size),0) FROM files')->fetchColumn();

// Hitung ukuran storage
$storageSize = 0;
if (is_dir(STORAGE_PATH)) {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STORAGE_PATH, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $f) { if ($f->isFile()) $storageSize += $f->getSize(); }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --sidebar-width:240px; --sidebar-bg:#1e1b4b; }
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
        .nav-item a:hover { background:rgba(255,255,255,.08); color:white; }
        #main { margin-left:var(--sidebar-width); padding:2rem; }
        .stat-card { background:white; border-radius:12px; padding:1.25rem 1.5rem; border:1px solid #e5e7eb; }
    </style>
</head>
<body>
<div id="sidebar">
    <div class="sidebar-brand">
        <span><?= h(APP_NAME) ?></span>
    </div>
    <nav style="padding:.75rem 0">
        <div class="nav-item">
            <a href="<?= APP_URL ?>/dashboard.php"><i class="fa-solid fa-arrow-left fa-fw"></i> Kembali</a>
        </div>
        <div class="nav-item">
            <a href="<?= APP_URL ?>/admin.php"><i class="fa-solid fa-users fa-fw"></i> Pengguna</a>
        </div>
        <div class="nav-item">
            <a href="<?= APP_URL ?>/external_storage.php"><i class="fa-solid fa-server fa-fw"></i> Storage Eksternal</a>
        </div>
    </nav>
</div>

<div id="main">
    <h4 class="fw-bold mb-1">Backup</h4>
    <p class="text-muted mb-4">Download semua file dan database sebagai satu arsip ZIP.</p>

    <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible">
        <?= h($flash['message']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistik -->
    <div class="row g-3 mb-4">
        <div class="col-sm-3">
            <div class="stat-card">
                <div class="text-muted small">Pengguna</div>
                <div class="fs-4 fw-bold"><?= $stats['users'] ?></div>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="stat-card">
                <div class="text-muted small">File</div>
                <div class="fs-4 fw-bold"><?= $stats['files'] ?></div>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="stat-card">
                <div class="text-muted small">Folder</div>
                <div class="fs-4 fw-bold"><?= $stats['folders'] ?></div>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="stat-card">
                <div class="text-muted small">Ukuran Storage</div>
                <div class="fs-4 fw-bold"><?= formatSize($storageSize) ?></div>
            </div>
        </div>
    </div>

    <!-- Backup card -->
    <div class="card border-0 shadow-sm" style="max-width:500px">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div style="background:#ede9fe;border-radius:12px;width:52px;height:52px;display:flex;align-items:center;justify-content:center">
                    <i class="fa-solid fa-file-zipper fa-lg" style="color:#7c3aed"></i>
                </div>
                <div>
                    <div class="fw-semibold">Backup Lengkap</div>
                    <div class="text-muted small">File storage + export database SQL</div>
                </div>
            </div>
            <p class="text-muted small mb-3">
                Backup mencakup semua file yang diupload dan seluruh data database.
                Proses mungkin memakan waktu tergantung ukuran data.
            </p>
            <a href="<?= APP_URL ?>/backup.php?action=download"
               class="btn btn-primary w-100"
               onclick="this.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i>Membuat backup...'; this.classList.add('disabled')">
                <i class="fa-solid fa-download me-2"></i> Download Backup (.zip)
            </a>
        </div>
    </div>

    <div class="alert alert-warning mt-4" style="max-width:500px">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        <strong>Catatan:</strong> Simpan file backup di tempat yang aman.
        Backup berisi semua data pengguna dan file.
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>
