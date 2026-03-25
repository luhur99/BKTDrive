<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAdmin();

$db   = getDB();
$user = currentUser();

// Buat tabel jika belum ada
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

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$flash  = getFlash();

// ── Proses form ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'add' || $action === 'edit') {
        $name     = trim($_POST['name']      ?? '');
        $type     = $_POST['type']           ?? 'ftp';
        $host     = trim($_POST['host']      ?? '');
        $port     = (int)($_POST['port']     ?? 21);
        $username = trim($_POST['username']  ?? '');
        $password = $_POST['password']       ?? '';
        $basePath = trim($_POST['base_path'] ?? '/');
        $passive  = !empty($_POST['passive']) ? 1 : 0;

        if (!$name || !$host || !$username) {
            setFlash('danger', 'Nama, host, dan username wajib diisi.');
            header('Location: ' . APP_URL . '/external_storage.php');
            exit;
        }

        if ($basePath && $basePath[0] !== '/') $basePath = '/' . $basePath;

        // Enkripsi password sederhana
        $encPass = base64_encode($password);

        if ($action === 'add') {
            $stmt = $db->prepare('INSERT INTO external_storages (name,type,host,port,username,password,base_path,passive,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$name,$type,$host,$port,$username,$encPass,$basePath,$passive,$user['id']]);
            setFlash('success', 'Storage eksternal "' . $name . '" berhasil ditambahkan.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($password) {
                $stmt = $db->prepare('UPDATE external_storages SET name=?,type=?,host=?,port=?,username=?,password=?,base_path=?,passive=? WHERE id=?');
                $stmt->execute([$name,$type,$host,$port,$username,$encPass,$basePath,$passive,$id]);
            } else {
                $stmt = $db->prepare('UPDATE external_storages SET name=?,type=?,host=?,port=?,username=?,base_path=?,passive=? WHERE id=?');
                $stmt->execute([$name,$type,$host,$port,$username,$basePath,$passive,$id]);
            }
            setFlash('success', 'Storage eksternal berhasil diperbarui.');
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM external_storages WHERE id=?')->execute([$id]);
        setFlash('success', 'Storage eksternal berhasil dihapus.');

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('UPDATE external_storages SET enabled = NOT enabled WHERE id=?')->execute([$id]);
        setFlash('success', 'Status storage diperbarui.');
    }

    header('Location: ' . APP_URL . '/external_storage.php');
    exit;
}

// ── Test koneksi ────────────────────────────────────────────────────────────
if ($action === 'test' && isset($_GET['id'])) {
    $id   = (int)$_GET['id'];
    $stmt = $db->prepare('SELECT * FROM external_storages WHERE id=?');
    $stmt->execute([$id]);
    $es   = $stmt->fetch();

    $result = ['ok' => false, 'msg' => 'Storage tidak ditemukan.'];
    if ($es) {
        $pass = base64_decode($es['password']);
        if ($es['type'] === 'ftp') {
            $conn = @ftp_connect($es['host'], $es['port'], 10);
            if (!$conn) {
                $result = ['ok' => false, 'msg' => 'Gagal terhubung ke ' . $es['host'] . ':' . $es['port']];
            } elseif (!@ftp_login($conn, $es['username'], $pass)) {
                $result = ['ok' => false, 'msg' => 'Login gagal. Periksa username/password.'];
                ftp_close($conn);
            } else {
                if ($es['passive']) ftp_pasv($conn, true);
                $list = @ftp_nlist($conn, $es['base_path']);
                ftp_close($conn);
                $result = ['ok' => true, 'msg' => 'Koneksi berhasil! Ditemukan ' . ($list ? count($list) : 0) . ' item di ' . $es['base_path']];
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// ── Ambil daftar storage ────────────────────────────────────────────────────
$storages = $db->query('SELECT es.*, u.name as creator FROM external_storages es JOIN users u ON es.created_by=u.id ORDER BY es.name')->fetchAll();

// Edit mode
$editItem = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM external_storages WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editItem = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storage Eksternal — <?= h(APP_NAME) ?></title>
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
        .storage-card { background:white; border-radius:12px; border:1px solid #e5e7eb; padding:1.25rem 1.5rem; }
    </style>
</head>
<body>
<div id="sidebar">
    <div class="sidebar-brand"><span><?= h(APP_NAME) ?></span></div>
    <nav style="padding:.75rem 0">
        <div class="nav-item"><a href="<?= APP_URL ?>/dashboard.php"><i class="fa-solid fa-arrow-left fa-fw"></i> Kembali</a></div>
        <div class="nav-item"><a href="<?= APP_URL ?>/admin.php"><i class="fa-solid fa-users fa-fw"></i> Pengguna</a></div>
        <div class="nav-item"><a href="<?= APP_URL ?>/backup.php"><i class="fa-solid fa-file-zipper fa-fw"></i> Backup</a></div>
    </nav>
</div>

<div id="main">
    <h4 class="fw-bold mb-1">Storage Eksternal</h4>
    <p class="text-muted mb-4">Hubungkan server FTP eksternal agar dapat diakses dari aplikasi ini.</p>

    <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible">
        <?= h($flash['message']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Daftar storage -->
        <div class="col-lg-7">
            <?php if (empty($storages)): ?>
            <div class="storage-card text-center py-5 text-muted">
                <i class="fa-solid fa-server fa-3x mb-3 opacity-25"></i>
                <div>Belum ada storage eksternal terkonfigurasi.</div>
            </div>
            <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($storages as $es): ?>
                <div class="storage-card">
                    <div class="d-flex align-items-start gap-3">
                        <div style="background:#eff6ff;border-radius:10px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <i class="fa-solid fa-server" style="color:#3b82f6"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= h($es['name']) ?>
                                <?php if (!$es['enabled']): ?>
                                <span class="badge bg-secondary ms-1">Nonaktif</span>
                                <?php else: ?>
                                <span class="badge bg-success ms-1">Aktif</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small">
                                <?= strtoupper(h($es['type'])) ?> &bull;
                                <?= h($es['username']) ?>@<?= h($es['host']) ?>:<?= $es['port'] ?>
                                &bull; <?= h($es['base_path']) ?>
                            </div>
                            <div class="text-muted" style="font-size:.75rem">Ditambahkan oleh <?= h($es['creator']) ?></div>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <button class="btn btn-sm btn-outline-secondary"
                                    onclick="testConnection(<?= $es['id'] ?>, this)" title="Test Koneksi">
                                <i class="fa-solid fa-plug"></i>
                            </button>
                            <a href="<?= APP_URL ?>/external_browse.php?storage=<?= $es['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Browse">
                                <i class="fa-solid fa-folder-open"></i>
                            </a>
                            <a href="?edit=<?= $es['id'] ?>" class="btn btn-sm btn-light" title="Edit">
                                <i class="fa-solid fa-pencil"></i>
                            </a>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Hapus storage ini?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $es['id'] ?>">
                                <button class="btn btn-sm btn-light text-danger" title="Hapus">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <div id="test-result-<?= $es['id'] ?>" class="mt-2" style="display:none"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Form tambah/edit -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-semibold mb-0">
                        <?= $editItem ? 'Edit Storage' : 'Tambah Storage Baru' ?>
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="<?= $editItem ? 'edit' : 'add' ?>">
                        <?php if ($editItem): ?>
                        <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Nama</label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="Misal: VPS Backup" required
                                   value="<?= $editItem ? h($editItem['name']) : '' ?>">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-4">
                                <label class="form-label small fw-semibold">Tipe</label>
                                <select name="type" class="form-select form-select-sm">
                                    <option value="ftp" <?= (!$editItem || $editItem['type']==='ftp') ? 'selected' : '' ?>>FTP</option>
                                    <option value="sftp" <?= ($editItem && $editItem['type']==='sftp') ? 'selected' : '' ?>>SFTP</option>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label small fw-semibold">Host / IP</label>
                                <input type="text" name="host" class="form-control form-control-sm"
                                       placeholder="192.168.1.1" required
                                       value="<?= $editItem ? h($editItem['host']) : '' ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label small fw-semibold">Port</label>
                                <input type="number" name="port" class="form-control form-control-sm"
                                       value="<?= $editItem ? $editItem['port'] : 21 ?>">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Username</label>
                                <input type="text" name="username" class="form-control form-control-sm"
                                       required value="<?= $editItem ? h($editItem['username']) : '' ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Password</label>
                                <input type="password" name="password" class="form-control form-control-sm"
                                       <?= $editItem ? 'placeholder="Kosongkan jika tidak diubah"' : 'required' ?>>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Base Path</label>
                            <input type="text" name="base_path" class="form-control form-control-sm"
                                   placeholder="/var/www/storage"
                                   value="<?= $editItem ? h($editItem['base_path']) : '/' ?>">
                            <div class="form-text">Folder root di server FTP</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="passive" id="passive"
                                   <?= (!$editItem || $editItem['passive']) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="passive">Mode Pasif (direkomendasikan)</label>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-floppy-disk me-1"></i>
                                <?= $editItem ? 'Simpan' : 'Tambah' ?>
                            </button>
                            <?php if ($editItem): ?>
                            <a href="<?= APP_URL ?>/external_storage.php" class="btn btn-secondary btn-sm">Batal</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script>
function testConnection(id, btn) {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    btn.disabled = true;
    const res = document.getElementById('test-result-' + id);
    res.style.display = 'none';

    fetch('<?= APP_URL ?>/external_storage.php?action=test&id=' + id)
        .then(r => r.json())
        .then(data => {
            res.innerHTML = '<div class="alert alert-' + (data.ok ? 'success' : 'danger') + ' py-1 px-2 mb-0 small">' + data.msg + '</div>';
            res.style.display = 'block';
        })
        .catch(() => {
            res.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small">Gagal melakukan test.</div>';
            res.style.display = 'block';
        })
        .finally(() => {
            btn.innerHTML = orig;
            btn.disabled = false;
        });
}
</script>
</body>
</html>
