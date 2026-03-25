<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gdrive.php';

requireAdmin();

$db   = getDB();
$user = currentUser();

// ── Buat tabel yang dibutuhkan ─────────────────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS backup_schedules (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        frequency    ENUM('daily','weekly') DEFAULT 'daily',
        day_of_week  TINYINT DEFAULT 0 COMMENT '0=Minggu, 1=Senin, ..., 6=Sabtu',
        hour         TINYINT DEFAULT 2,
        minute       TINYINT DEFAULT 0,
        gdrive_enabled TINYINT(1) DEFAULT 0,
        enabled      TINYINT(1) DEFAULT 0,
        last_run     DATETIME DEFAULT NULL,
        next_run     DATETIME DEFAULT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");
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
gdriveEnsureTable();

// ── Ambil konfigurasi ──────────────────────────────────────────────────────
$schedule   = $db->query('SELECT * FROM backup_schedules LIMIT 1')->fetch();
$gdriveConf = gdriveGetConfig();
$flash      = getFlash();
$tab        = $_GET['tab'] ?? 'schedule';

// ── Proses POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_schedule') {
        $freq     = in_array($_POST['frequency']??'', ['daily','weekly']) ? $_POST['frequency'] : 'daily';
        $dow      = (int)($_POST['day_of_week'] ?? 0);
        $hour     = max(0, min(23, (int)($_POST['hour'] ?? 2)));
        $minute   = max(0, min(59, (int)($_POST['minute'] ?? 0)));
        $enabled  = !empty($_POST['enabled']) ? 1 : 0;
        $gdrive   = !empty($_POST['gdrive_enabled']) ? 1 : 0;

        // Hitung next_run
        $next = calculateNextRun($freq, $dow, $hour, $minute);

        if ($schedule) {
            $db->prepare('UPDATE backup_schedules SET frequency=?,day_of_week=?,hour=?,minute=?,enabled=?,gdrive_enabled=?,next_run=? WHERE id=?')
               ->execute([$freq,$dow,$hour,$minute,$enabled,$gdrive,$next,$schedule['id']]);
        } else {
            $db->prepare('INSERT INTO backup_schedules (frequency,day_of_week,hour,minute,enabled,gdrive_enabled,next_run) VALUES (?,?,?,?,?,?,?)')
               ->execute([$freq,$dow,$hour,$minute,$enabled,$gdrive,$next]);
        }
        setFlash('success', 'Jadwal backup berhasil disimpan.');
        header('Location: backup_schedule.php?tab=schedule'); exit;

    } elseif ($action === 'save_gdrive') {
        $clientId     = trim($_POST['client_id']     ?? '');
        $clientSecret = trim($_POST['client_secret'] ?? '');
        $folderId     = trim($_POST['folder_id']     ?? '');
        $folderName   = trim($_POST['folder_name']   ?? '');

        if (!$clientId || !$clientSecret) {
            setFlash('danger', 'Client ID dan Client Secret wajib diisi.');
            header('Location: backup_schedule.php?tab=gdrive'); exit;
        }

        if ($gdriveConf) {
            $db->prepare('UPDATE gdrive_config SET client_id=?,client_secret=?,folder_id=?,folder_name=? WHERE id=?')
               ->execute([$clientId,$clientSecret,$folderId,$folderName,$gdriveConf['id']]);
        } else {
            $db->prepare('INSERT INTO gdrive_config (client_id,client_secret,folder_id,folder_name) VALUES (?,?,?,?)')
               ->execute([$clientId,$clientSecret,$folderId,$folderName]);
        }
        setFlash('success', 'Konfigurasi Google Drive disimpan. Klik "Hubungkan" untuk otorisasi.');
        header('Location: backup_schedule.php?tab=gdrive'); exit;

    } elseif ($action === 'disconnect_gdrive') {
        $db->exec('UPDATE gdrive_config SET access_token=NULL,refresh_token=NULL,token_expires=0,connected=0');
        setFlash('success', 'Google Drive berhasil diputus koneksinya.');
        header('Location: backup_schedule.php?tab=gdrive'); exit;

    } elseif ($action === 'run_now') {
        // Jalankan backup manual langsung
        require_once __DIR__ . '/includes/gdrive.php';
        $zipPath = createBackupZip();
        if (!$zipPath) {
            setFlash('danger', 'Gagal membuat file backup.');
        } else {
            $zipSize = filesize($zipPath);
            $gdriveId = '';
            $schedule = $db->query('SELECT * FROM backup_schedules LIMIT 1')->fetch();
            $useGdrive = $schedule && $schedule['gdrive_enabled'] && gdriveIsConnected();

            if ($useGdrive) {
                $result = gdriveUpload($zipPath, 'backup_' . date('Y-m-d_H-i-s') . '.zip');
                $gdriveId = $result['ok'] ? $result['id'] : '';
                if (!$result['ok']) {
                    $db->prepare('INSERT INTO backup_logs (type,status,file_size,message) VALUES ("manual","failed",?,?)')
                       ->execute([$zipSize, 'GDrive: ' . $result['msg']]);
                    @unlink($zipPath);
                    setFlash('danger', 'Backup berhasil dibuat tapi upload GDrive gagal: ' . $result['msg']);
                    header('Location: backup_schedule.php?tab=logs'); exit;
                }
            }
            $db->prepare('INSERT INTO backup_logs (type,status,file_size,gdrive_file_id,message) VALUES ("manual","success",?,?,?)')
               ->execute([$zipSize, $gdriveId, $useGdrive ? 'Berhasil diupload ke Google Drive.' : 'Backup lokal (tidak dikirim ke GDrive).']);
            @unlink($zipPath);
            setFlash('success', 'Backup berhasil!' . ($useGdrive ? ' File diupload ke Google Drive.' : ''));
        }
        header('Location: backup_schedule.php?tab=logs'); exit;
    }
}

// ── Mulai koneksi Google Drive (OAuth) ────────────────────────────────────
if (isset($_GET['connect_gdrive'])) {
    $cfg = gdriveGetConfig();
    if ($cfg && $cfg['client_id']) {
        $authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
            'client_id'     => $cfg['client_id'],
            'redirect_uri'  => APP_URL . '/gdrive_callback.php',
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.file',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);
        header('Location: ' . $authUrl);
        exit;
    }
    setFlash('danger', 'Masukkan Client ID terlebih dahulu.');
    header('Location: backup_schedule.php?tab=gdrive'); exit;
}

// ── Helper: hitung next_run ────────────────────────────────────────────────
function calculateNextRun(string $freq, int $dow, int $hour, int $minute): string {
    $now    = time();
    $today  = mktime($hour, $minute, 0, date('n'), date('j'), date('Y'));
    if ($freq === 'daily') {
        $next = ($today > $now) ? $today : $today + 86400;
    } else {
        // Weekly: cari hari berikutnya yang sesuai
        $curDow = (int)date('w');
        $diff   = ($dow - $curDow + 7) % 7;
        $diff   = $diff === 0 && $today <= $now ? 7 : $diff;
        $next   = $today + $diff * 86400;
    }
    return date('Y-m-d H:i:s', $next);
}

// ── Reload data ────────────────────────────────────────────────────────────
$schedule   = $db->query('SELECT * FROM backup_schedules LIMIT 1')->fetch();
$gdriveConf = gdriveGetConfig();
$isConnected = gdriveIsConnected();
$backupLogs = $db->query('SELECT * FROM backup_logs ORDER BY created_at DESC LIMIT 20')->fetchAll();

$days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];

// Buat cron secret untuk URL
$cronUrl    = APP_URL . '/cron.php?secret=' . CRON_SECRET;
$cronSecret = CRON_SECRET;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Otomatis — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --sidebar-width:240px; --sidebar-bg:#1e1b4b; --accent:#4f46e5; }
        body { background:#f3f4f6; font-family:'Segoe UI',sans-serif; }
        #sidebar {
            width:var(--sidebar-width); min-height:100vh; background:var(--sidebar-bg);
            position:fixed; top:0; left:0; z-index:100; overflow-y:auto; display:flex; flex-direction:column;
        }
        .sidebar-brand { padding:1.25rem; border-bottom:1px solid rgba(255,255,255,.08); }
        .sidebar-brand span { color:white; font-weight:700; font-size:1.1rem; }
        .nav-item a { display:flex; align-items:center; gap:.65rem; padding:.6rem 1.25rem; color:rgba(255,255,255,.7); text-decoration:none; font-size:.9rem; transition:all .15s; }
        .nav-item a:hover, .nav-item a.active { background:rgba(255,255,255,.12); color:white; }
        .nav-section { padding:.5rem 1.25rem .25rem; font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.35); font-weight:600; }
        #main { margin-left:var(--sidebar-width); }
        .topbar { background:white; border-bottom:1px solid #e5e7eb; padding:.75rem 1.5rem; display:flex; align-items:center; gap:1rem; position:sticky; top:0; z-index:50; }
        .content { padding:1.5rem; max-width:900px; }
        .card-section { background:white; border-radius:12px; border:1px solid #e5e7eb; margin-bottom:1.5rem; }
        .card-section-header { padding:1rem 1.25rem; border-bottom:1px solid #f3f4f6; }
        .card-section-body { padding:1.25rem; }
        .gdrive-logo { width:24px; height:24px; }
        .status-dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
        .cron-cmd { background:#1e1b4b; color:#a5f3fc; border-radius:8px; padding:.75rem 1rem; font-family:monospace; font-size:.82rem; word-break:break-all; }
        .activity-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
    </style>
</head>
<body>

<div id="sidebar">
    <div class="sidebar-brand"><span><?= h(APP_NAME) ?></span></div>
    <nav style="padding:.75rem 0; flex:1">
        <div class="nav-section">Navigasi</div>
        <div class="nav-item"><a href="<?= APP_URL ?>/dashboard.php"><i class="fa-solid fa-house fa-fw"></i> Dashboard</a></div>
        <div class="nav-section mt-2">Admin</div>
        <div class="nav-item"><a href="monitor.php"><i class="fa-solid fa-chart-line fa-fw"></i> Monitor</a></div>
        <div class="nav-item"><a href="backup_schedule.php" class="active"><i class="fa-solid fa-clock-rotate-left fa-fw"></i> Backup Otomatis</a></div>
        <div class="nav-item"><a href="backup.php"><i class="fa-solid fa-file-zipper fa-fw"></i> Backup Manual</a></div>
        <div class="nav-item"><a href="admin.php"><i class="fa-solid fa-users fa-fw"></i> Pengguna</a></div>
    </nav>
</div>

<div id="main">
    <div class="topbar">
        <span class="fw-bold"><i class="fa-solid fa-clock-rotate-left me-2" style="color:var(--accent)"></i>Backup Otomatis</span>
        <div class="ms-auto">
            <?php if ($schedule && $schedule['enabled']): ?>
            <span class="badge bg-success"><i class="fa-solid fa-circle me-1" style="font-size:.5rem"></i>Aktif</span>
            <?php else: ?>
            <span class="badge bg-secondary">Nonaktif</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">

        <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible">
            <?= h($flash['message']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?= $tab==='schedule'?'active':'' ?>" href="?tab=schedule">
                    <i class="fa-solid fa-calendar-days me-1"></i> Jadwal
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab==='gdrive'?'active':'' ?>" href="?tab=gdrive">
                    <i class="fa-brands fa-google-drive me-1"></i> Google Drive
                    <?php if ($isConnected): ?>
                    <span class="status-dot bg-success ms-1"></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab==='logs'?'active':'' ?>" href="?tab=logs">
                    <i class="fa-solid fa-list-check me-1"></i> Log
                    <?php if (!empty($backupLogs)): ?>
                    <span class="badge bg-secondary ms-1"><?= count($backupLogs) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <!-- ════ TAB: JADWAL ════ -->
        <?php if ($tab === 'schedule'): ?>

        <!-- Status -->
        <?php if ($schedule): ?>
        <div class="alert alert-<?= $schedule['enabled'] ? 'success' : 'secondary' ?> d-flex align-items-center gap-3 mb-4">
            <i class="fa-solid fa-<?= $schedule['enabled'] ? 'circle-check' : 'circle-pause' ?> fa-lg"></i>
            <div>
                <?php if ($schedule['enabled']): ?>
                <strong>Backup aktif:</strong>
                <?= $schedule['frequency'] === 'daily' ? 'Setiap hari' : 'Setiap ' . $days[$schedule['day_of_week']] ?>
                pukul <?= str_pad($schedule['hour'],2,'0',STR_PAD_LEFT) ?>:<?= str_pad($schedule['minute'],2,'0',STR_PAD_LEFT) ?> WIB
                <?php if ($schedule['next_run']): ?>
                — <strong>Berikutnya:</strong> <?= formatDate($schedule['next_run']) ?>
                <?php endif; ?>
                <?php else: ?>
                <strong>Backup otomatis tidak aktif.</strong> Aktifkan di bawah atau jalankan manual.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Form Jadwal -->
        <div class="card-section">
            <div class="card-section-header">
                <h6 class="fw-bold mb-0"><i class="fa-solid fa-gear me-2"></i>Konfigurasi Jadwal</h6>
            </div>
            <div class="card-section-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="save_schedule">
                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold">Frekuensi</label>
                            <select name="frequency" id="freqSelect" class="form-select" onchange="toggleDayField()">
                                <option value="daily"  <?= ($schedule['frequency']??'daily')==='daily'  ? 'selected':'' ?>>Harian</option>
                                <option value="weekly" <?= ($schedule['frequency']??'')==='weekly' ? 'selected':'' ?>>Mingguan</option>
                            </select>
                        </div>
                        <div class="col-sm-4" id="dayField" style="<?= ($schedule['frequency']??'daily')==='weekly'?'':'display:none' ?>">
                            <label class="form-label small fw-semibold">Hari</label>
                            <select name="day_of_week" class="form-select">
                                <?php foreach ($days as $i => $d): ?>
                                <option value="<?= $i ?>" <?= ($schedule['day_of_week']??1)==$i ? 'selected':'' ?>><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small fw-semibold">Jam</label>
                            <select name="hour" class="form-select">
                                <?php for ($h=0;$h<24;$h++): ?>
                                <option value="<?= $h ?>" <?= ($schedule['hour']??2)==$h?'selected':'' ?>><?= str_pad($h,2,'0',STR_PAD_LEFT) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small fw-semibold">Menit</label>
                            <select name="minute" class="form-select">
                                <?php foreach ([0,15,30,45] as $m): ?>
                                <option value="<?= $m ?>" <?= ($schedule['minute']??0)==$m?'selected':'' ?>><?= str_pad($m,2,'0',STR_PAD_LEFT) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="enabled" id="enabledSwitch"
                                       <?= ($schedule['enabled']??0) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="enabledSwitch">Aktifkan backup otomatis</label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="gdrive_enabled" id="gdriveSwitch"
                                       <?= ($schedule['gdrive_enabled']??0) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="gdriveSwitch">
                                    Upload ke Google Drive
                                    <?php if (!$isConnected): ?>
                                    <span class="text-muted small">(belum terhubung)</span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Simpan Jadwal
                        </button>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="run_now">
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="fa-solid fa-play me-1"></i> Jalankan Sekarang
                            </button>
                        </form>
                    </div>
                </form>
            </div>
        </div>

        <!-- Setup Cron -->
        <div class="card-section">
            <div class="card-section-header">
                <h6 class="fw-bold mb-0"><i class="fa-solid fa-terminal me-2"></i>Setup Cron Trigger</h6>
            </div>
            <div class="card-section-body">
                <p class="text-muted small mb-3">
                    Jadwal di atas hanya aktif jika ada yang memicu <code>cron.php</code> secara berkala.
                    Pilih salah satu cara berikut:
                </p>

                <div class="accordion" id="cronAccordion">
                    <!-- Windows Task Scheduler -->
                    <div class="accordion-item border mb-2">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#cronWin">
                                <i class="fa-brands fa-windows me-2"></i> Windows Task Scheduler (XAMPP)
                            </button>
                        </h2>
                        <div id="cronWin" class="accordion-collapse collapse">
                            <div class="accordion-body small">
                                <p>1. Buka <strong>Task Scheduler</strong> (cari di Start Menu)</p>
                                <p>2. Klik <strong>Create Basic Task</strong> → beri nama "LuhurWorkspace Backup"</p>
                                <p>3. Trigger: sesuai jadwal yang dipilih (daily/weekly)</p>
                                <p>4. Action: <strong>Start a Program</strong>, isi dengan:</p>
                                <div class="cron-cmd mb-2">Program: <strong>C:\xampp\php\php.exe</strong></div>
                                <div class="cron-cmd">Arguments: <strong>C:\xampp\htdocs\BKTDrive\cron.php <?= htmlspecialchars(CRON_SECRET) ?></strong></div>
                            </div>
                        </div>
                    </div>
                    <!-- Linux/VPS Cron -->
                    <div class="accordion-item border mb-2">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#cronLinux">
                                <i class="fa-brands fa-linux me-2"></i> Linux/VPS (crontab)
                            </button>
                        </h2>
                        <div id="cronLinux" class="accordion-collapse collapse">
                            <div class="accordion-body small">
                                <p>Jalankan <code>crontab -e</code> dan tambahkan:</p>
                                <p><strong>Harian pukul 02:00:</strong></p>
                                <div class="cron-cmd mb-2">0 2 * * * php <?= $_SERVER['DOCUMENT_ROOT'] ?>/BKTDrive/cron.php <?= htmlspecialchars(CRON_SECRET) ?></div>
                                <p><strong>Mingguan Senin pukul 02:00:</strong></p>
                                <div class="cron-cmd">0 2 * * 1 php <?= $_SERVER['DOCUMENT_ROOT'] ?>/BKTDrive/cron.php <?= htmlspecialchars(CRON_SECRET) ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- URL Trigger -->
                    <div class="accordion-item border">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#cronUrl">
                                <i class="fa-solid fa-link me-2"></i> URL Trigger (UptimeRobot / cron-job.org)
                            </button>
                        </h2>
                        <div id="cronUrl" class="accordion-collapse collapse">
                            <div class="accordion-body small">
                                <p>Daftarkan URL ini ke layanan cron eksternal (gratis: <a href="https://cron-job.org" target="_blank">cron-job.org</a>):</p>
                                <div class="cron-cmd d-flex justify-content-between align-items-center">
                                    <span id="cronUrlText"><?= h($cronUrl) ?></span>
                                    <button class="btn btn-sm btn-outline-light ms-2" onclick="copyCronUrl()">
                                        <i class="fa-solid fa-copy"></i>
                                    </button>
                                </div>
                                <div class="mt-2 text-muted">Pastikan server dapat diakses dari internet jika menggunakan URL trigger.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════ TAB: GOOGLE DRIVE ════ -->
        <?php elseif ($tab === 'gdrive'): ?>

        <!-- Status Koneksi -->
        <div class="card-section mb-4">
            <div class="card-section-header d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">
                    <i class="fa-brands fa-google-drive me-2" style="color:#4285f4"></i>Status Google Drive
                </h6>
                <?php if ($isConnected): ?>
                <span class="badge bg-success px-3 py-2"><i class="fa-solid fa-circle-check me-1"></i>Terhubung</span>
                <?php else: ?>
                <span class="badge bg-secondary px-3 py-2">Belum Terhubung</span>
                <?php endif; ?>
            </div>
            <div class="card-section-body">
                <?php if ($isConnected): ?>
                <div class="alert alert-success mb-3">
                    <i class="fa-solid fa-circle-check me-2"></i>
                    Google Drive terhubung.
                    <?php if (!empty($gdriveConf['folder_name'])): ?>
                    Backup akan disimpan di folder: <strong><?= h($gdriveConf['folder_name']) ?></strong>
                    <?php else: ?>
                    Backup akan disimpan di root Google Drive.
                    <?php endif; ?>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="disconnect_gdrive">
                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Putus koneksi Google Drive?')">
                        <i class="fa-solid fa-link-slash me-1"></i> Putus Koneksi
                    </button>
                </form>
                <?php else: ?>
                <p class="text-muted mb-0">Ikuti langkah setup di bawah untuk menghubungkan Google Drive.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Step 1: Buat Google Project -->
        <div class="card-section mb-4">
            <div class="card-section-header">
                <h6 class="fw-bold mb-0"><span class="badge bg-primary me-2">1</span>Buat Google OAuth Credentials</h6>
            </div>
            <div class="card-section-body small">
                <ol class="mb-0">
                    <li class="mb-2">Buka <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></li>
                    <li class="mb-2">Buat project baru (atau gunakan yang sudah ada)</li>
                    <li class="mb-2">Pergi ke <strong>APIs & Services → Library</strong> → Cari <strong>Google Drive API</strong> → Klik <strong>Enable</strong></li>
                    <li class="mb-2">Pergi ke <strong>APIs & Services → Credentials</strong> → Klik <strong>+ CREATE CREDENTIALS → OAuth client ID</strong></li>
                    <li class="mb-2">Application type: <strong>Web application</strong></li>
                    <li class="mb-2">Authorized redirect URIs, tambahkan:
                        <div class="cron-cmd mt-1"><?= h(APP_URL) ?>/gdrive_callback.php</div>
                    </li>
                    <li>Salin <strong>Client ID</strong> dan <strong>Client Secret</strong> yang muncul</li>
                </ol>
            </div>
        </div>

        <!-- Step 2: Masukkan Credentials -->
        <div class="card-section">
            <div class="card-section-header">
                <h6 class="fw-bold mb-0"><span class="badge bg-primary me-2">2</span>Masukkan Credentials & Hubungkan</h6>
            </div>
            <div class="card-section-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="save_gdrive">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Client ID</label>
                        <input type="text" name="client_id" class="form-control"
                               placeholder="xxxx.apps.googleusercontent.com"
                               value="<?= h($gdriveConf['client_id'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Client Secret</label>
                        <input type="text" name="client_secret" class="form-control"
                               placeholder="GOCSPX-..."
                               value="<?= h($gdriveConf['client_secret'] ?? '') ?>" required>
                    </div>
                    <div class="row g-2 mb-4">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">Folder ID Google Drive <span class="text-muted">(opsional)</span></label>
                            <input type="text" name="folder_id" class="form-control"
                                   placeholder="ID folder dari URL Google Drive"
                                   value="<?= h($gdriveConf['folder_id'] ?? '') ?>">
                            <div class="form-text">Kosongkan = simpan di root Drive</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">Nama Folder <span class="text-muted">(label saja)</span></label>
                            <input type="text" name="folder_name" class="form-control"
                                   placeholder="Misal: LuhurWorkspace Backup"
                                   value="<?= h($gdriveConf['folder_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Simpan Credentials
                        </button>
                        <?php if ($gdriveConf && $gdriveConf['client_id']): ?>
                        <a href="?connect_gdrive=1" class="btn btn-primary">
                            <i class="fa-brands fa-google-drive me-1"></i>
                            <?= $isConnected ? 'Hubungkan Ulang' : 'Hubungkan Google Drive' ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- ════ TAB: LOG ════ -->
        <?php elseif ($tab === 'logs'): ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0">Riwayat Backup</h6>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="run_now">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-play me-1"></i> Jalankan Backup Sekarang
                </button>
            </form>
        </div>

        <div class="card-section">
            <div class="section-body p-0">
                <?php if (empty($backupLogs)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-file-zipper fa-3x mb-3 opacity-25"></i>
                    <div>Belum ada riwayat backup.</div>
                </div>
                <?php else: ?>
                <table class="table table-hover mb-0" style="font-size:.85rem">
                    <thead class="table-light">
                        <tr>
                            <th>Waktu</th>
                            <th>Tipe</th>
                            <th>Status</th>
                            <th>Ukuran</th>
                            <th>GDrive</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backupLogs as $log): ?>
                        <tr>
                            <td class="text-muted"><?= formatDate($log['created_at']) ?></td>
                            <td><span class="badge <?= $log['type']==='auto' ? 'bg-info' : 'bg-secondary' ?>"><?= $log['type'] ?></span></td>
                            <td>
                                <span class="badge <?= $log['status']==='success' ? 'bg-success' : 'bg-danger' ?>">
                                    <?= $log['status'] ?>
                                </span>
                            </td>
                            <td><?= $log['file_size'] ? formatSize($log['file_size']) : '-' ?></td>
                            <td>
                                <?php if ($log['gdrive_file_id']): ?>
                                <span class="badge" style="background:#4285f4;color:white">
                                    <i class="fa-brands fa-google-drive me-1"></i>GDrive
                                </span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?= h($log['message'] ?? '') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script>
function toggleDayField() {
    const freq = document.getElementById('freqSelect').value;
    document.getElementById('dayField').style.display = freq === 'weekly' ? '' : 'none';
}
function copyCronUrl() {
    const url = document.getElementById('cronUrlText').textContent;
    navigator.clipboard.writeText(url).then(() => alert('URL berhasil disalin!'));
}
</script>
</body>
</html>
