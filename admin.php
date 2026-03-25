<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAdmin();

$user = currentUser();
$db   = getDB();

$action = $_POST['action'] ?? '';

// ── Handle POST actions ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($action === 'create') {
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['admin','member']) ? $_POST['role'] : 'member';

        if (!$name || !$email || !$password) {
            setFlash('danger', 'Semua field harus diisi.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'Format email tidak valid.');
        } elseif (strlen($password) < 6) {
            setFlash('danger', 'Password minimal 6 karakter.');
        } else {
            // Cek email duplikat
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                setFlash('danger', 'Email sudah digunakan.');
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)');
                $stmt->execute([$name, $email, $hash, $role]);
                setFlash('success', "Pengguna \"$name\" berhasil dibuat.");
            }
        }
    } elseif ($action === 'delete') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        if ($targetId === $user['id']) {
            setFlash('danger', 'Anda tidak bisa menghapus akun sendiri.');
        } else {
            $stmt = $db->prepare('SELECT name FROM users WHERE id = ?');
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();
            if ($target) {
                $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$targetId]);
                setFlash('success', "Pengguna \"{$target['name']}\" berhasil dihapus.");
            }
        }
    } elseif ($action === 'reset_password') {
        $targetId    = (int)($_POST['target_id']    ?? 0);
        $newPassword = trim($_POST['new_password']  ?? '');
        if (!$newPassword || strlen($newPassword) < 6) {
            setFlash('danger', 'Password minimal 6 karakter.');
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([$hash, $targetId]);
            setFlash('success', 'Password berhasil direset.');
        }
    } elseif ($action === 'change_role') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        $role     = in_array($_POST['role'] ?? '', ['admin','member']) ? $_POST['role'] : 'member';
        if ($targetId === $user['id']) {
            setFlash('warning', 'Anda tidak bisa mengubah role diri sendiri.');
        } else {
            $stmt = $db->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->execute([$role, $targetId]);
            setFlash('success', 'Role pengguna berhasil diubah.');
        }
    }

    header('Location: ' . APP_URL . '/admin.php');
    exit;
}

// ── Get all users ────────────────────────────────────────
$stmt  = $db->query('SELECT u.*, (SELECT COUNT(*) FROM files WHERE owner_id=u.id) as file_count FROM users u ORDER BY u.created_at DESC');
$users = $stmt->fetchAll();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengguna — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --sidebar-width: 240px; }
        body { background: #f3f4f6; font-family: 'Segoe UI', sans-serif; }
        #sidebar {
            width: var(--sidebar-width); min-height: 100vh;
            background: #1e1b4b; position: fixed; top: 0; left: 0;
            display: flex; flex-direction: column; z-index: 100;
        }
        .sidebar-brand { padding: 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-brand .logo-icon {
            width: 36px; height: 36px;
            background: linear-gradient(135deg,#4f46e5,#7c3aed);
            border-radius: 8px; display: inline-flex;
            align-items: center; justify-content: center;
            color: white; font-size: 1rem;
        }
        .sidebar-brand span { color: white; font-weight: 700; font-size: 1.1rem; }
        .nav-item a {
            display: flex; align-items: center; gap: .65rem;
            padding: .6rem 1.25rem; color: rgba(255,255,255,.7);
            text-decoration: none; font-size: .9rem;
        }
        .nav-item a:hover { background: rgba(255,255,255,.08); color: white; }
        .nav-item a.active { background: rgba(255,255,255,.15); color: white; font-weight: 600; }
        .nav-section { padding: .5rem 1.25rem .25rem; font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.35); font-weight: 600; }
        .sidebar-footer { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,.08); margin-top: auto; }
        #main { margin-left: var(--sidebar-width); padding: 2rem; }
        .card { border: none; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
        .badge-admin  { background: #4f46e5; }
        .badge-member { background: #6b7280; }
        .flash-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; min-width: 280px; }
        .table th { font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; font-weight: 600; }
        .table td { vertical-align: middle; }
    </style>
</head>
<body>

<?php if ($flash): ?>
<div class="flash-container">
    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible shadow-sm">
        <?= h($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<div id="sidebar">
    <div class="sidebar-brand d-flex align-items-center gap-2">
        <div class="logo-icon"><i class="fa-solid fa-cloud"></i></div>
        <span><?= h(APP_NAME) ?></span>
    </div>
    <nav style="padding:.75rem 0;flex:1">
        <div class="nav-section">File</div>
        <div class="nav-item"><a href="dashboard.php"><i class="fa-solid fa-folder-open fa-fw"></i> File Saya</a></div>
        <div class="nav-section mt-2">Kelola</div>
        <div class="nav-item"><a href="admin.php" class="active"><i class="fa-solid fa-users fa-fw"></i> Pengguna</a></div>
    </nav>
    <div class="sidebar-footer">
        <div style="color:white;font-size:.85rem">
            <div style="font-weight:600"><?= h($user['name']) ?></div>
            <div style="font-size:.75rem;opacity:.6">Admin</div>
        </div>
        <a href="logout.php" class="btn btn-sm mt-2 w-100"
           style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.8);border:1px solid rgba(255,255,255,.15)">
            <i class="fa-solid fa-right-from-bracket me-1"></i> Keluar
        </a>
    </div>
</div>

<div id="main">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-0">Kelola Pengguna</h4>
            <div class="text-muted small"><?= count($users) ?> pengguna terdaftar</div>
        </div>
        <button class="btn btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#createModal"
                style="background:#4f46e5;border:none;border-radius:8px">
            <i class="fa-solid fa-user-plus me-1"></i> Tambah Pengguna
        </button>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Nama</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>File</th>
                            <th>Bergabung</th>
                            <th class="pe-4 text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="ps-4 fw-semibold">
                                <?= h($u['name']) ?>
                                <?php if ($u['id'] == $user['id']): ?>
                                    <span class="badge bg-light text-dark border ms-1" style="font-size:.65rem">Anda</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= h($u['email']) ?></td>
                            <td>
                                <span class="badge <?= $u['role']==='admin' ? 'badge-admin' : 'badge-member' ?>">
                                    <?= $u['role'] === 'admin' ? 'Admin' : 'Anggota' ?>
                                </span>
                            </td>
                            <td class="text-muted small"><?= $u['file_count'] ?> file</td>
                            <td class="text-muted small"><?= formatDate($u['created_at']) ?></td>
                            <td class="pe-4 text-end">
                                <?php if ($u['id'] !== $user['id']): ?>
                                <button class="btn btn-sm btn-outline-secondary me-1"
                                        onclick="openResetModal(<?= $u['id'] ?>, '<?= h($u['name']) ?>')"
                                        title="Reset Password">
                                    <i class="fa-solid fa-key"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary me-1"
                                        onclick="openRoleModal(<?= $u['id'] ?>, '<?= h($u['name']) ?>', '<?= $u['role'] ?>')"
                                        title="Ubah Role">
                                    <i class="fa-solid fa-user-gear"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger"
                                        onclick="confirmDelete(<?= $u['id'] ?>, '<?= h($u['name']) ?>')"
                                        title="Hapus">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ─── Modal: Tambah Pengguna ──────────────────────── -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-semibold">Tambah Pengguna Baru</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Lengkap</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Password</label>
                        <input type="password" name="password" class="form-control" minlength="6" required>
                        <div class="form-text">Minimal 6 karakter.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Role</label>
                        <select name="role" class="form-select">
                            <option value="member">Anggota</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm" style="background:#4f46e5;border:none">Buat Pengguna</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─── Modal: Reset Password ──────────────────────── -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="target_id" id="resetTargetId">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-semibold">Reset Password</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">Reset password untuk <strong id="resetName"></strong></p>
                    <input type="password" name="new_password" class="form-control" placeholder="Password baru" minlength="6" required>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning btn-sm">Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─── Modal: Ubah Role ────────────────────────────── -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="change_role">
                <input type="hidden" name="target_id" id="roleTargetId">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-semibold">Ubah Role</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">Ubah role untuk <strong id="roleName"></strong></p>
                    <select name="role" id="roleSelect" class="form-select form-select-sm">
                        <option value="member">Anggota</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm" style="background:#4f46e5;border:none">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─── Modal: Hapus User ───────────────────────────── -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="target_id" id="deleteUserId">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-semibold text-danger">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Hapus Pengguna
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <p class="small mb-0">Hapus <strong id="deleteUserName"></strong>?
                        Semua file miliknya juga akan dihapus.</p>
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
function openResetModal(id, name) {
    document.getElementById('resetTargetId').value = id;
    document.getElementById('resetName').textContent = name;
    new bootstrap.Modal(document.getElementById('resetModal')).show();
}
function openRoleModal(id, name, currentRole) {
    document.getElementById('roleTargetId').value = id;
    document.getElementById('roleName').textContent = name;
    document.getElementById('roleSelect').value = currentRole;
    new bootstrap.Modal(document.getElementById('roleModal')).show();
}
function confirmDelete(id, name) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteUserName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
}
setTimeout(() => {
    document.querySelectorAll('.flash-container .alert').forEach(el => {
        bootstrap.Alert.getOrCreateInstance(el).close();
    });
}, 4000);
</script>
</body>
</html>
