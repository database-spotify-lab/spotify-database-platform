<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

// Temporary local-dev debugging: surface PHP errors in built-in server.
$isLocalDev = PHP_SAPI === 'cli-server'
    || in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
if ($isLocalDev) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

/**
 * Persist session for any successful role; same keys used for admin-only checks.
 *
 * @param array{user_id:int|string,email?:string,role?:string} $user
 */
function login_write_session(array $user): void
{
    ensure_session();
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['email'] = (string) ($user['email'] ?? '');
    $_SESSION['role'] = strtolower(trim((string) ($user['role'] ?? '')));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../login_page.html');
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header('Location: ../../login_page.html?error=missing_credentials');
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
        header('Location: ../../login_page.html?error=invalid_credentials');
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
        header('Location: ../../login_page.html?error=invalid_credentials');
        exit;
    }

    login_write_session($user);

    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($role === 'analyst') {
        header('Location: ../../analytics_charts.html');
        exit;
    }
    if ($role === 'admin') {
        header('Location: ../admin/admin_page.php');
        exit;
    }

    header('Location: ../../login_page.html?error=unauthorized_role');
    exit;
} catch (Throwable $e) {
    error_log(sprintf(
        '[login.php] %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    header('Location: ../../login_page.html?error=server_error');
    exit;
}
