<?php
declare(strict_types=1);

function ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * @return array{user_id:int,email:string,role:string}|null
 */
function current_user(): ?array
{
    ensure_session();
    if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
        return null;
    }
    $uid = (int) $_SESSION['user_id'];
    if ($uid < 1) {
        return null;
    }
    $email = (string) ($_SESSION['email'] ?? '');
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return [
        'user_id' => $uid,
        'email' => $email,
        'role' => $role,
    ];
}

/**
 * HTML admin routes: redirect strangers to login.
 */
function require_admin(): void
{
    $u = current_user();
    if ($u === null || $u['role'] !== 'admin') {
        header('Location: ../../html/login_page.html?error=unauthorized', true, 302);
        exit;
    }
}

/** Admin-only JSON endpoints (always 401 JSON; fetch may not send Accept: application/json). */
function require_admin_api(): void
{
    $u = current_user();
    if ($u === null || $u['role'] !== 'admin') {
        json_response(['ok' => false, 'error' => 'unauthorized'], 401);
        exit;
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = require dirname(__DIR__) . '/config.php';
        $envPass = getenv('MUSICBOX_DB_PASSWORD');
        if ($envPass !== false && $envPass !== '') {
            $c['pass'] = $envPass;
        }
        $envUser = getenv('MUSICBOX_DB_USER');
        if ($envUser !== false && $envUser !== '') {
            $c['user'] = $envUser;
        }
        $envDsn = getenv('MUSICBOX_DB_DSN');
        if ($envDsn !== false && $envDsn !== '') {
            $c['dsn'] = $envDsn;
        }
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], $c['options']);
    }
    return $pdo;
}

/** Only surface moderated-approved catalogue rows (adjust if your seed uses other literals). */
const CATALOG_STATUS_APPROVED = 'approved';

const CATALOG_STATUS_PENDING = 'pending';

const CATALOG_STATUS_REJECTED = 'rejected';

/**
 * Maps UI decade keys to [startYear, endYear]. 'all' => null range (caller treats as no filter).
 * @return array{0:?int,1:?int}|null
 */
function decade_year_range(?string $decade): ?array
{
    if ($decade === null || $decade === '' || $decade === 'all') {
        return null;
    }
    $map = [
        '1980s' => [1980, 1989],
        '1990s' => [1990, 1999],
        '2000s' => [2000, 2009],
        '2010s' => [2010, 2019],
        '2020s' => [2020, 2029],
    ];
    return $map[$decade] ?? null;
}

function json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
