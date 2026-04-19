<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../html/login_page.html');
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header('Location: ../../html/login_page.html?error=missing_credentials');
    exit;
}

try {
    $sql = "
        SELECT user_id, email, password_hash, role, is_active
        FROM USERS
        WHERE email = :username
        LIMIT 1
    ";
    $stmt = db()->prepare($sql);
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if ($user === false || (int) ($user['is_active'] ?? 0) !== 1) {
        header('Location: ../../html/login_page.html?error=invalid_credentials');
        exit;
    }

    $hash = (string) ($user['password_hash'] ?? '');
    $validPassword = false;
    if ($hash !== '') {
        // Prefer standard password_hash verification.
        $validPassword = password_verify($password, $hash);
        // Compatibility fallback for seed/demo datasets that may store plaintext.
        if (!$validPassword) {
            $validPassword = hash_equals($hash, $password);
        }
    }

    if (!$validPassword) {
        header('Location: ../../html/login_page.html?error=invalid_credentials');
        exit;
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($role === 'analyst') {
        header('Location: ../../html/analytics_charts.html');
        exit;
    }
    if ($role === 'admin') {
        header('Location: ../../html/admin_page.html');
        exit;
    }

    header('Location: ../../html/login_page.html?error=unauthorized_role');
    exit;
} catch (Throwable $e) {
    header('Location: ../../html/login_page.html?error=server_error');
    exit;
}
