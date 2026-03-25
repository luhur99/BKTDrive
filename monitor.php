<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAdmin();

$db   = getDB();
$user = currentUser();

// ── Buat tabel backup_logs jika belum ada ──────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS backup_logs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        type          ENUM('manual','auto') DEFAULT 'manual',
        status        ENUM('success','failed') DEFAULT 'success',
        file_size     BIGINT DEFAULT 0,
        gdrive_file_id VARCHAR(255) DEFAULT '',
        message       TEXT,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

// ── Disk & Storage ─────────────────────────────────────────────────────────
$diskTotal = @disk_total_space(STORAGE_PATH) ?: 0;
$diskFree  = @disk_free_space(STORAGE_PATH)  ?: 0;
$diskUsed  = $diskTotal - $diskFree;

$storageSize = 0;
$storageFiles = 0;
if (is_dir(STORAGE_PATH)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(STORAGE_PATH, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if ($f->isFile()) { $storageSize += $f->getSize(); $storageFiles++; }
    }
}

// ── DB Stats ───────────────────────────────────────────────────────────────
$dbStats = [
    'users'   => $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'files'   => $db->query('SELECT COUNT(*) FROM files')->fetchColumn(),
    'folders' => $db->query('SELECT COUNT(*) FROM folders')->fetchColumn(),
    'shares'  => $db->query('SELECT COUNT(*) FROM shares')->fetchColumn(),
    'db_size' => $db->query("SELECT ROUND(SUM(data_length+index_length)) FROM information_schema.tables WHERE table_schema='" . DB_NAME . "'")->fetchColumn(),
    'total_size' => $db->query('SELECT COALESCE(SUM(size),0) FROM files')->fetchColumn(),
];

// ── Per-user quota ─────────────────────────────────────────────────────────
$userQuota = $db->query("
    SELECT u.id, u.name, u.email, u.role, u.created_at,
           COUNT(f.id) as file_count,
           COALESCE(SUM(f.size),0) as total_size,
           MAX(f.updated_at) as last_activity
    FROM users u
    LEFT JOIN files f ON f.owner_id = u.id
    GROUP BY u.id ORDER BY total_size DESC
")->fetchAll();

// ── File type breakdown ────────────────────────────────────────────────────
$fileTypes = $db->query("
    SELECT LOWER(SUBSTRING_INDEX(original_name,'.',-1)) as ext,
           COUNT(*) as cnt,
           COALESCE(SUM(size),0) as total_size
    FROM files GROUP BY ext ORDER BY cnt DESC LIMIT 12
")->fetchAll();

// ── Upload activity (last 14 days) ─────────────────────────────────────────
$dailyActivity = $db->query("
    SELECT DATE(created_at) as day, COUNT(*) as cnt, COALESCE(SUM(size),0) as total_size
    FROM files
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY day ORDER BY day ASC
")->fetchAll();

// ── Recent files ────────────────────────────────────────────────────────────
$recentFiles = $db->query("
    SELECT f.original_name, f.size, f.created_at, u.name as owner_name, u.email
    FROM files f JOIN users u ON f.owner_id = u.id
    ORDER BY f.created_at DESC LIMIT 15
")->fetchAll();

// ── Backup logs ─────────────────────────────────────────────────────────────
$backupLogs = $db->query('SELECT * FROM backup_logs ORDER BY created_at DESC LIMIT 15')->fetchAll();

// ── PHP & Server info ──────────────────────────────────────────────────────
$sysMemTotal = 0;
$sysMemFree  = 0;
if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
    $t = @shell_exec('wmic ComputerSystem get TotalPhysicalMemory /value 2>nul');
    $f = @shell_exec('wmic OS get FreePhysicalMemory /value 2>nul');
    if ($t) preg_match('/=(\d+)/', $t, $tm); $sysMemTotal = (int)($tm[1] ?? 0);
    if ($f) preg_match('/=(\d+)/', $f, $fm); $sysMemFree  = (int)($fm[1] ?? 0) * 1024;
}

$maxStorage = (int)($dbStats['total_size']);
$topUserSize = $userQuota[0]['total_size'] ?? 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --sidebar-width:240px; --sidebar-bg:#1e1b4b; --accent:#4f46e5; }
        body { background:#f3f4f6; font-family:'Segoe UI',sans-serif; }
        #sidebar {
            width:var(--sidebar-width); min-height:100vh; background:var(--sidebar-bg);
            position:fixed; top:0; left:0; z-index:100; overflow-y:auto;
            display:flex; flex-direction:column;
        }
        .sidebar-brand { padding:1.25rem; border-bottom:1px solid rgba(255,255,255,.08); }
        .sidebar-brand span { color:white; font-weight:700; font-size:1.1rem; }
        .nav-item a {
            display:flex; align-items:center; gap:.65rem; padding:.6rem 1.25rem;
            color:rgba(255,255,255,.7); text-decoration:none; font-size:.9rem; transition:all .15s;
        }
        .nav-item a:hover, .nav-item a.active { background:rgba(255,255,255,.12); color:white; }
        .nav-section { padding:.5rem 1.25rem .25rem; font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.35); font-weight:600; }
        #main { margin-left:var(--sidebar-width); }
        .topbar { background:white; border-bottom:1px solid #e5e7eb; padding:.75rem 1.5rem; display:flex; align-items:center; gap:1rem; position:sticky; top:0; z-index:50; }
        .content { padding:1.5rem; }
        .stat-card { background:white; border-radius:12px; padding:1.25rem 1.5rem; border:1px solid #e5e7eb; height:100%; }
        .stat-card .stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
        .stat-val { font-size:1.6rem; font-weight:700; line-height:1.2; }
        .section-card { background:white; border-radius:12px; border:1px solid #e5e7eb; overflow:hidden; }
        .section-header { padding:1rem 1.25rem; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; gap:.5rem; }
        .section-header h6 { font-weight:700; margin:0; }
        .section-body { padding:1.25rem; }
        .progress-bar-custom { height:8px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
        .progress-fill { height:100%; border-radius:4px; transition:width .4s; }
        .ext-badge { display:inline-flex; align-items:center; gap:.35rem; background:#f3f4f6; border-radius:6px; padding:.3rem .6rem; font-size:.78rem; font-weight:600; }
        .activity-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .refresh-btn { font-size:.8rem; }
        .disk-ring { position:relative; display:inline-flex; align-items:center; justify-content:center; }
        .server-badge { display:inline-flex; align-items:center; gap:.4rem; padding:.2rem .6rem; border-radius:6px; font-size:.78rem; font-weight:600; }
        .badge-ok   { background:#d1fae5; color:#065f46; }
        .badge-warn { background:#fef3c7; color:#92400e; }
        .badge-err  { background:#fee2e2; color:#991b1b; }
        .chart-bar { display:flex; align-items:flex-end; gap:4px; height:60px; }
        .chart-bar-item { flex:1; background:var(--accent); border-radius:3px 3px 0 0; min-width:8px; opacity:.7; transition:opacity .2s; }
        .chart-bar-item:hover { opacity:1; }
        table { font-size:.85rem; }
    </style>
</head>
<body>

<div id="sidebar">
    <div class="sidebar-brand"><span><?= h(APP_NAME) ?></span></div>
    <nav style="padding:.75rem 0; flex:1">
        <div class="nav-section">Navigasi</div>
        <div class="nav-item"><a href="<?= APP_URL ?>/dashboard.php"><i class="fa-solid fa-house fa-fw"></i> Dashboard</a></div>
        <div class="nav-section mt-2">Admin</div>
        <div class="nav-item"><a href="monitor.php" class="active"><i class="fa-solid fa-chart-line fa-fw"></i> Monitor</a></div>
        <div class="nav-item"><a href="backup_schedule.php"><i class="fa-solid fa-clock-rotate-left fa-fw"></i> Backup Otomatis</a></div>
        <div class="nav-item"><a href="backup.php"><i class="fa-solid fa-file-zipper fa-fw"></i> Backup Manual</a></div>
        <div class="nav-item"><a href="admin.php"><i class="fa-solid fa-users fa-fw"></i> Pengguna</a></div>
        <div class="nav-item"><a href="external_storage.php"><i class="fa-solid fa-server fa-fw"></i> Storage Eksternal</a></div>
    </nav>
</div>

<div id="main">
    <div class="topbar">
        <span class="fw-bold"><i class="fa-solid fa-chart-line me-2" style="color:var(--accent)"></i>Server Monitor</span>
        <span class="text-muted small ms-auto">Diperbarui: <?= date('d M Y, H:i:s') ?></span>
        <button class="btn btn-sm btn-outline-secondary refresh-btn" onclick="location.reload()">
            <i class="fa-solid fa-rotate-right me-1"></i> Refresh
        </button>
    </div>

    <div class="content">

        <!-- ── Stat Cards ── -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#ede9fe"><i class="fa-solid fa-hard-drive" style="color:#7c3aed"></i></div>
                        <div class="text-muted small">Disk Server</div>
                    </div>
                    <div class="stat-val"><?= formatSize($diskUsed) ?></div>
                    <div class="text-muted small mt-1">dari <?= formatSize($diskTotal) ?></div>
                    <div class="progress-bar-custom mt-2">
                        <div class="progress-fill" style="width:<?= $diskTotal ? min(100, round($diskUsed/$diskTotal*100)) : 0 ?>%;background:<?= ($diskTotal && $diskUsed/$diskTotal > 0.85) ? '#ef4444' : '#7c3aed' ?>"></div>
                    </div>
                    <div class="text-muted small mt-1">Sisa: <?= formatSize($diskFree) ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#dbeafe"><i class="fa-solid fa-folder-open" style="color:#3b82f6"></i></div>
                        <div class="text-muted small">Storage App</div>
                    </div>
                    <div class="stat-val"><?= formatSize($storageSize) ?></div>
                    <div class="text-muted small mt-1"><?= number_format($storageFiles) ?> file di storage/</div>
                    <div class="progress-bar-custom mt-2">
                        <div class="progress-fill" style="width:<?= $diskTotal ? min(100, round($storageSize/$diskTotal*100)) : 0 ?>%;background:#3b82f6"></div>
                    </div>
                    <div class="text-muted small mt-1"><?= $diskTotal ? round($storageSize/$diskTotal*100,1) : 0 ?>% dari total disk</div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#d1fae5"><i class="fa-solid fa-users" style="color:#10b981"></i></div>
                        <div class="text-muted small">Pengguna</div>
                    </div>
                    <div class="stat-val"><?= $dbStats['users'] ?></div>
                    <div class="text-muted small mt-1"><?= $dbStats['files'] ?> file &bull; <?= $dbStats['folders'] ?> folder</div>
                    <div class="mt-2">
                        <?php foreach (array_slice($userQuota, 0, 3) as $u): ?>
                        <div class="d-flex justify-content-between small text-muted">
                            <span class="text-truncate" style="max-width:120px"><?= h($u['name']) ?></span>
                            <span><?= formatSize($u['total_size']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="stat-icon" style="background:#fef3c7"><i class="fa-solid fa-database" style="color:#f59e0b"></i></div>
                        <div class="text-muted small">Database</div>
                    </div>
                    <div class="stat-val"><?= formatSize($dbStats['db_size'] ?: 0) ?></div>
                    <div class="text-muted small mt-1"><?= $dbStats['shares'] ?> entri share</div>
                    <div class="mt-2">
                        <span class="server-badge badge-ok">
                            <i class="fa-solid fa-circle-check"></i> MySQL OK
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">

            <!-- ── Server Info ── -->
            <div class="col-lg-4">
                <div class="section-card h-100">
                    <div class="section-header">
                        <i class="fa-solid fa-server" style="color:var(--accent)"></i>
                        <h6>Info Server</h6>
                    </div>
                    <div class="section-body">
                        <table class="w-100">
                            <tr>
                                <td class="text-muted py-1" style="width:50%">PHP</td>
                                <td class="fw-semibold"><?= PHP_VERSION ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted py-1">OS</td>
                                <td class="fw-semibold" style="font-size:.78rem"><?= php_uname('s') ?> <?= php_uname('r') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted py-1">Memory Limit</td>
                                <td class="fw-semibold"><?= ini_get('memory_limit') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted py-1">Upload Max</td>
                                <td class="fw-semibold"><?= ini_get('upload_max_filesize') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted py-1">Post Max</td>
                                <td class="fw-semibold"><?= ini_get('post_max_size') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted py-1">Max Exec Time</td>
                                <td class="fw-semibold"><?= ini_get('max_execution_time') ?>s</td>
                            </tr>
                        </table>
                        <div class="mt-3">
                            <div class="small text-muted fw-semibold mb-2">Ekstensi PHP</div>
                            <div class="d-flex flex-wrap gap-1">
                                <?php
                                $exts = ['zip','ftp','curl','pdo_mysql','gd','mbstring','openssl','json'];
                                foreach ($exts as $ext):
                                    $ok = extension_loaded($ext);
                                ?>
                                <span class="server-badge <?= $ok ? 'badge-ok' : 'badge-err' ?>">
                                    <i class="fa-solid <?= $ok ? 'fa-check' : 'fa-xmark' ?>"></i>
                                    <?= $ext ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Upload Activity Chart ── -->
            <div class="col-lg-8">
                <div class="section-card h-100">
                    <div class="section-header">
                        <i class="fa-solid fa-chart-column" style="color:var(--accent)"></i>
                        <h6>Aktivitas Upload (14 Hari Terakhir)</h6>
                    </div>
                    <div class="section-body">
                        <?php
                        // Buat map tanggal → count
                        $actMap = [];
                        foreach ($dailyActivity as $a) $actMap[$a['day']] = $a;
                        $maxCnt = max(1, max(array_column($dailyActivity, 'cnt') ?: [1]));
                        $days = [];
                        for ($i = 13; $i >= 0; $i--) {
                            $d = date('Y-m-d', strtotime("-$i days"));
                            $days[$d] = $actMap[$d] ?? ['cnt' => 0, 'total_size' => 0];
                        }
                        ?>
                        <div class="chart-bar mb-2" style="height:80px;align-items:flex-end">
                            <?php foreach ($days as $d => $v): ?>
                            <div class="chart-bar-item"
                                 style="height:<?= $v['cnt'] ? max(4, round($v['cnt']/$maxCnt*100)) : 2 ?>%;<?= $v['cnt'] ? '' : 'opacity:.2' ?>"
                                 title="<?= $d ?>: <?= $v['cnt'] ?> file (<?= formatSize($v['total_size']) ?>)">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex justify-content-between" style="font-size:.65rem; color:#9ca3af">
                            <?php
                            $shown = 0;
                            foreach (array_keys($days) as $d):
                                if ($shown % 2 == 0): ?>
                            <span><?= date('d/m', strtotime($d)) ?></span>
                            <?php endif; $shown++; endforeach; ?>
                        </div>
                        <div class="row g-2 mt-3">
                            <div class="col-4 text-center">
                                <div class="fw-bold"><?= array_sum(array_column($dailyActivity,'cnt')) ?></div>
                                <div class="text-muted" style="font-size:.75rem">File 14 hari</div>
                            </div>
                            <div class="col-4 text-center">
                                <div class="fw-bold"><?= formatSize(array_sum(array_column($dailyActivity,'total_size'))) ?></div>
                                <div class="text-muted" style="font-size:.75rem">Ukuran 14 hari</div>
                            </div>
                            <div class="col-4 text-center">
                                <div class="fw-bold"><?= count($dailyActivity) ?></div>
                                <div class="text-muted" style="font-size:.75rem">Hari aktif</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">

            <!-- ── Quota per User ── -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <i class="fa-solid fa-user-group" style="color:#10b981"></i>
                        <h6>Kuota per Pengguna</h6>
                    </div>
                    <div class="section-body">
                        <?php if (empty($userQuota)): ?>
                        <div class="text-muted text-center py-3">Belum ada data</div>
                        <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($userQuota as $u): ?>
                            <div>
                                <div class="d-flex justify-content-between mb-1">
                                    <div>
                                        <span class="fw-semibold small"><?= h($u['name']) ?></span>
                                        <span class="badge <?= $u['role']==='admin' ? 'bg-primary' : 'bg-secondary' ?> ms-1" style="font-size:.65rem"><?= $u['role'] ?></span>
                                    </div>
                                    <span class="text-muted small"><?= formatSize($u['total_size']) ?> &bull; <?= $u['file_count'] ?> file</span>
                                </div>
                                <div class="progress-bar-custom">
                                    <div class="progress-fill"
                                         style="width:<?= $topUserSize > 0 ? min(100, round($u['total_size']/$topUserSize*100)) : 0 ?>%;background:<?= $u['role']==='admin' ? '#4f46e5' : '#10b981' ?>">
                                    </div>
                                </div>
                                <?php if ($u['last_activity']): ?>
                                <div class="text-muted mt-1" style="font-size:.68rem">Terakhir aktif: <?= formatDate($u['last_activity']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── File Type Breakdown ── -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <i class="fa-solid fa-chart-pie" style="color:#f59e0b"></i>
                        <h6>Jenis File</h6>
                    </div>
                    <div class="section-body">
                        <?php if (empty($fileTypes)): ?>
                        <div class="text-muted text-center py-3">Belum ada file</div>
                        <?php else: ?>
                        <?php $maxTypeCnt = max(1, $fileTypes[0]['cnt']); ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($fileTypes as $ft): ?>
                            <div class="d-flex align-items-center gap-2">
                                <span class="ext-badge" style="min-width:52px;justify-content:center">
                                    <?= h(strtoupper($ft['ext'] ?: '?')) ?>
                                </span>
                                <div class="flex-grow-1">
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill" style="width:<?= round($ft['cnt']/$maxTypeCnt*100) ?>%;background:#f59e0b"></div>
                                    </div>
                                </div>
                                <span class="text-muted small" style="min-width:80px;text-align:right">
                                    <?= $ft['cnt'] ?> file &bull; <?= formatSize($ft['total_size']) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">

            <!-- ── Recent Files ── -->
            <div class="col-lg-7">
                <div class="section-card">
                    <div class="section-header">
                        <i class="fa-solid fa-clock-rotate-left" style="color:#3b82f6"></i>
                        <h6>File Terbaru</h6>
                    </div>
                    <div class="section-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Nama File</th><th>Pemilik</th><th>Ukuran</th><th>Waktu Upload</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentFiles)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">Belum ada file</td></tr>
                                <?php else: ?>
                                <?php foreach ($recentFiles as $f): ?>
                                <tr>
                                    <td>
                                        <i class="fa-solid <?= getFileIcon($f['original_name']) ?> <?= getFileIconColor($f['original_name']) ?> me-1"></i>
                                        <span class="text-truncate d-inline-block" style="max-width:160px" title="<?= h($f['original_name']) ?>">
                                            <?= h($f['original_name']) ?>
                                        </span>
                                    </td>
                                    <td class="text-muted"><?= h($f['owner_name']) ?></td>
                                    <td><?= formatSize($f['size']) ?></td>
                                    <td class="text-muted"><?= formatDate($f['created_at']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ── Backup Logs ── -->
            <div class="col-lg-5">
                <div class="section-card">
                    <div class="section-header justify-content-between">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-file-zipper" style="color:#7c3aed"></i>
                            <h6>Riwayat Backup</h6>
                        </div>
                        <a href="backup_schedule.php" class="btn btn-sm btn-outline-primary">Kelola</a>
                    </div>
                    <div class="section-body p-0">
                        <?php if (empty($backupLogs)): ?>
                        <div class="text-center text-muted py-4 px-3">
                            <i class="fa-solid fa-file-zipper fa-2x mb-2 opacity-25"></i>
                            <div class="small">Belum ada backup. <a href="backup_schedule.php">Setup backup otomatis</a>.</div>
                        </div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($backupLogs as $log): ?>
                            <div class="list-group-item px-3 py-2">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="activity-dot" style="background:<?= $log['status']==='success' ? '#10b981' : '#ef4444' ?>"></div>
                                    <div class="flex-grow-1">
                                        <div class="small fw-semibold">
                                            <?= ucfirst($log['type']) ?>
                                            <?php if ($log['gdrive_file_id']): ?>
                                            <span class="badge" style="background:#4285f4;color:white;font-size:.6rem">GDrive</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted" style="font-size:.72rem">
                                            <?= formatDate($log['created_at']) ?>
                                            <?php if ($log['file_size']): ?> &bull; <?= formatSize($log['file_size']) ?><?php endif; ?>
                                        </div>
                                        <?php if ($log['message'] && $log['status'] === 'failed'): ?>
                                        <div class="text-danger" style="font-size:.72rem"><?= h(substr($log['message'],0,60)) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge <?= $log['status']==='success' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= $log['status'] ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>
