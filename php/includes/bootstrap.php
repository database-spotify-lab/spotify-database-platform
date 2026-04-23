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

function ensure_review_events_table(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    db()->exec("
        CREATE TABLE IF NOT EXISTS REVIEW_EVENTS (
            event_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(20) NOT NULL,
            entity_id VARCHAR(64) NOT NULL,
            action_type VARCHAR(32) NOT NULL,
            requested_by BIGINT NOT NULL,
            requested_role VARCHAR(20) NOT NULL,
            status_at_submit VARCHAR(20) NOT NULL,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_review_events_entity (entity_type, entity_id, created_at),
            INDEX idx_review_events_user (requested_by, created_at)
        )
    ");
    $ready = true;
}

function ensure_review_event_views_table(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    ensure_review_events_table();
    db()->exec("
        CREATE TABLE IF NOT EXISTS REVIEW_EVENT_VIEWS (
            event_id BIGINT NOT NULL,
            admin_user_id BIGINT NOT NULL,
            viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (event_id, admin_user_id),
            INDEX idx_review_event_views_admin (admin_user_id, viewed_at)
        )
    ");
    $ready = true;
}

/**
 * @param array<string,mixed>|null $before
 * @param array<string,mixed>|null $after
 */
function record_review_event(
    string $entityType,
    string $entityId,
    string $actionType,
    int $requestedBy,
    string $requestedRole,
    string $statusAtSubmit,
    ?array $before,
    ?array $after
): void {
    ensure_review_events_table();
    $stmt = db()->prepare('
        INSERT INTO REVIEW_EVENTS
        (entity_type, entity_id, action_type, requested_by, requested_role, status_at_submit, before_json, after_json)
        VALUES
        (:entity_type, :entity_id, :action_type, :requested_by, :requested_role, :status_at_submit, :before_json, :after_json)
    ');
    $stmt->execute([
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':action_type' => $actionType,
        ':requested_by' => $requestedBy,
        ':requested_role' => $requestedRole,
        ':status_at_submit' => $statusAtSubmit,
        ':before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        ':after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
    ]);
}

function mark_review_event_viewed(int $eventId, int $adminUserId): void
{
    ensure_review_event_views_table();
    $stmt = db()->prepare('
        INSERT INTO REVIEW_EVENT_VIEWS (event_id, admin_user_id, viewed_at)
        VALUES (:event_id, :admin_user_id, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE viewed_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        ':event_id' => $eventId,
        ':admin_user_id' => $adminUserId,
    ]);
}

function has_review_event_view(int $eventId, int $adminUserId): bool
{
    ensure_review_event_views_table();
    $stmt = db()->prepare('
        SELECT 1
        FROM REVIEW_EVENT_VIEWS
        WHERE event_id = :event_id
          AND admin_user_id = :admin_user_id
        LIMIT 1
    ');
    $stmt->execute([
        ':event_id' => $eventId,
        ':admin_user_id' => $adminUserId,
    ]);
    return $stmt->fetchColumn() !== false;
}
