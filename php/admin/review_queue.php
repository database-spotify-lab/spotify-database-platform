<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin_api();

/**
 * @return array<int, array<string, mixed>>
 */
function review_queue_fetch_artists(PDO $pdo, array $opts): array
{
    $sql = "
        SELECT
            'artist' AS entity_type,
            ar.artist_id AS entity_id,
            ar.artist_name AS item_name,
            ar.artist_name AS artists_label,
            su.email AS submitted_email,
            ru.email AS reviewed_email,
            ar.status AS status,
            (
                SELECT re.event_id
                FROM REVIEW_EVENTS re
                WHERE re.entity_type = 'artist' AND re.entity_id = ar.artist_id
                ORDER BY re.event_id DESC
                LIMIT 1
            ) AS latest_event_id,
            (
                SELECT re.action_type
                FROM REVIEW_EVENTS re
                WHERE re.entity_type = 'artist' AND re.entity_id = ar.artist_id
                ORDER BY re.event_id DESC
                LIMIT 1
            ) AS latest_action_type
        FROM ARTISTS ar
        JOIN USERS su ON su.user_id = ar.submitted_by
        LEFT JOIN USERS ru ON ru.user_id = ar.reviewed_by
        WHERE 1 = 1
    ";
    $params = [];
    if ($opts['status'] !== null) {
        $sql .= ' AND ar.status = :status';
        $params[':status'] = $opts['status'];
    }
    if ($opts['item_like'] !== null) {
        $sql .= ' AND ar.artist_name LIKE :item_like';
        $params[':item_like'] = $opts['item_like'];
    }
    if ($opts['artist_like'] !== null) {
        $sql .= ' AND ar.artist_name LIKE :artist_like';
        $params[':artist_like'] = $opts['artist_like'];
    }
    $sql .= ' ORDER BY ar.artist_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function review_queue_fetch_albums(PDO $pdo, array $opts): array
{
    $sql = "
        SELECT
            'album' AS entity_type,
            al.album_id AS entity_id,
            al.album_name AS item_name,
            COALESCE(GROUP_CONCAT(DISTINCT ar2.artist_name ORDER BY ar2.artist_name SEPARATOR ', '), '') AS artists_label,
            su.email AS submitted_email,
            ru.email AS reviewed_email,
            al.status AS status,
            (
                SELECT re.event_id
                FROM REVIEW_EVENTS re
                WHERE re.entity_type = 'album' AND re.entity_id = al.album_id
                ORDER BY re.event_id DESC
                LIMIT 1
            ) AS latest_event_id,
            (
                SELECT re.action_type
                FROM REVIEW_EVENTS re
                WHERE re.entity_type = 'album' AND re.entity_id = al.album_id
                ORDER BY re.event_id DESC
                LIMIT 1
            ) AS latest_action_type
        FROM ALBUMS al
        JOIN USERS su ON su.user_id = al.submitted_by
        LEFT JOIN USERS ru ON ru.user_id = al.reviewed_by
        LEFT JOIN ALBUM_ARTISTS aa ON aa.album_id = al.album_id
        LEFT JOIN ARTISTS ar2 ON ar2.artist_id = aa.artist_id
        WHERE 1 = 1
    ";
    $params = [];
    if ($opts['status'] !== null) {
        $sql .= ' AND al.status = :status';
        $params[':status'] = $opts['status'];
    }
    if ($opts['item_like'] !== null) {
        $sql .= ' AND al.album_name LIKE :item_like';
        $params[':item_like'] = $opts['item_like'];
    }
    if ($opts['artist_like'] !== null) {
        $sql .= ' AND EXISTS (
            SELECT 1
            FROM ALBUM_ARTISTS aax
            INNER JOIN ARTISTS ax ON ax.artist_id = aax.artist_id
            WHERE aax.album_id = al.album_id AND ax.artist_name LIKE :artist_like
        )';
        $params[':artist_like'] = $opts['artist_like'];
    }
    $sql .= '
        GROUP BY al.album_id, al.album_name, al.release_date, al.album_image_url,
                 al.status, al.submitted_by, al.reviewed_by, su.email, ru.email
        ORDER BY al.album_name ASC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function review_queue_fetch_tracks(PDO $pdo, array $opts): array
{
    $sql = "
        SELECT
            'track' AS entity_type,
            t.track_id AS entity_id,
            t.track_name AS item_name,
            COALESCE(GROUP_CONCAT(DISTINCT ar2.artist_name ORDER BY ar2.artist_name SEPARATOR ', '), '') AS artists_label,
            su.email AS submitted_email,
            ru.email AS reviewed_email,
            t.status AS status,
            (
                SELECT re.event_id
                FROM REVIEW_EVENTS re
                WHERE re.entity_type = 'track' AND re.entity_id = t.track_id
                ORDER BY re.event_id DESC
                LIMIT 1
            ) AS latest_event_id,
            (
                SELECT re.action_type
                FROM REVIEW_EVENTS re
                WHERE re.entity_type = 'track' AND re.entity_id = t.track_id
                ORDER BY re.event_id DESC
                LIMIT 1
            ) AS latest_action_type
        FROM TRACKS t
        JOIN USERS su ON su.user_id = t.submitted_by
        LEFT JOIN USERS ru ON ru.user_id = t.reviewed_by
        LEFT JOIN TRACK_ARTISTS ta ON ta.track_id = t.track_id
        LEFT JOIN ARTISTS ar2 ON ar2.artist_id = ta.artist_id
        WHERE 1 = 1
    ";
    $params = [];
    if ($opts['status'] !== null) {
        $sql .= ' AND t.status = :status';
        $params[':status'] = $opts['status'];
    }
    if ($opts['item_like'] !== null) {
        $sql .= ' AND t.track_name LIKE :item_like';
        $params[':item_like'] = $opts['item_like'];
    }
    if ($opts['artist_like'] !== null) {
        $sql .= ' AND EXISTS (
            SELECT 1
            FROM TRACK_ARTISTS tax
            INNER JOIN ARTISTS ax ON ax.artist_id = tax.artist_id
            WHERE tax.track_id = t.track_id AND ax.artist_name LIKE :artist_like
        )';
        $params[':artist_like'] = $opts['artist_like'];
    }
    $sql .= '
        GROUP BY t.track_id, t.track_name, t.popularity, t.duration_ms, t.explicit, t.preview_url,
                 t.status, t.submitted_by, t.reviewed_by, su.email, ru.email
        ORDER BY t.track_name ASC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

try {
    ensure_review_events_table();
    $statusParam = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
    $statusFilter = null;
    if ($statusParam !== '' && strtolower($statusParam) !== 'all') {
        $allowed = [CATALOG_STATUS_PENDING, CATALOG_STATUS_APPROVED, CATALOG_STATUS_REJECTED];
        if (!in_array(strtolower($statusParam), $allowed, true)) {
            json_response(['ok' => false, 'error' => 'invalid_status'], 400);
            exit;
        }
        $statusFilter = strtolower($statusParam);
    }

    $itemRaw = isset($_GET['item']) ? trim((string) $_GET['item']) : '';
    $artistRaw = isset($_GET['artist']) ? trim((string) $_GET['artist']) : '';
    $itemLike = $itemRaw === '' ? null : '%' . $itemRaw . '%';
    $artistLike = $artistRaw === '' ? null : '%' . $artistRaw . '%';

    $typeParam = isset($_GET['type']) ? trim((string) $_GET['type']) : 'all';
    $typeLower = strtolower($typeParam);
    $validTypes = ['all', 'artist', 'album', 'track'];
    if (!in_array($typeLower, $validTypes, true)) {
        json_response(['ok' => false, 'error' => 'invalid_type'], 400);
        exit;
    }

    $opts = [
        'status' => $statusFilter,
        'item_like' => $itemLike,
        'artist_like' => $artistLike,
    ];

    $pdo = db();
    $rows = [];
    if ($typeLower === 'all' || $typeLower === 'artist') {
        $rows = array_merge($rows, review_queue_fetch_artists($pdo, $opts));
    }
    if ($typeLower === 'all' || $typeLower === 'album') {
        $rows = array_merge($rows, review_queue_fetch_albums($pdo, $opts));
    }
    if ($typeLower === 'all' || $typeLower === 'track') {
        $rows = array_merge($rows, review_queue_fetch_tracks($pdo, $opts));
    }

    usort(
        $rows,
        static function (array $a, array $b): int {
            $statusRank = static function (?string $s): int {
                $x = strtolower(trim((string) $s));
                if ($x === CATALOG_STATUS_PENDING) {
                    return 0;
                }
                if ($x === CATALOG_STATUS_REJECTED) {
                    return 1;
                }
                if ($x === CATALOG_STATUS_APPROVED) {
                    return 2;
                }
                return 9;
            };
            $ra = $statusRank((string) ($a['status'] ?? ''));
            $rb = $statusRank((string) ($b['status'] ?? ''));
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return strcasecmp((string) ($a['item_name'] ?? ''), (string) ($b['item_name'] ?? ''));
        }
    );

    foreach ($rows as &$r) {
        $label = trim((string) ($r['artists_label'] ?? ''));
        if ($label === '') {
            $r['artists_label'] = '—';
        }
    }
    unset($r);

    json_response(['ok' => true, 'items' => $rows]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'server_error'], 500);
}
