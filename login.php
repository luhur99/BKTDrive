<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Sudah login? langsung ke dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role']  = $user['role'];
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        } else {
            $error = 'Email atau password salah.';
        }
    } else {
        $error = 'Isi email dan password.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            max-width: 420px;
            width: 100%;
        }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }
        .brand-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border: none;
            padding: 0.75rem;
            font-weight: 600;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #4338ca, #6d28d9);
            transform: translateY(-1px);
        }
        .form-control:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 0.2rem rgba(79,70,229,0.25);
        }
    </style>
</head>
<body>
<div class="container">
    <div class="login-card mx-auto">
        <div class="text-center mb-4">
            <div class="brand-icon mx-auto mb-3">
                <i class="fa-solid fa-cloud"></i>
            </div>
            <h2 class="fw-bold text-white"><?= h(APP_NAME) ?></h2>
            <p class="text-white-50">Workspace kolaborasi tim Anda</p>
        </div>
        <div class="card">
            <div class="card-body p-4">
                <h5 class="fw-semibold mb-4">Masuk ke akun</h5>
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2">
                        <i class="fa-solid fa-circle-exclamation me-1"></i>
                        <?= h($error) ?>
                    </div>
                <?php endif; ?>
                <form method="POST" novalidate>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Email</label>
                        <input type="email" name="email" class="form-control"
                               placeholder="nama@email.com"
                               value="<?= h($_POST['email'] ?? '') ?>"
                               required autofocus>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-medium">Password</label>
                        <input type="password" name="password" class="form-control"
                               placeholder="Password Anda" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 rounded-3">
                        <i class="fa-solid fa-right-to-bracket me-2"></i>Masuk
                    </button>
                </form>
            </div>
        </div>
        <p class="text-center text-white-50 mt-3 small">
            Belum punya akun? Minta admin untuk membuatkan.
        </p>
    </div>
</div>
</body>
</html>
