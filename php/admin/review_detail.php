<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();
ensure_review_events_table();
ensure_review_event_views_table();

$eventId = isset($_GET['event_id']) ? trim((string) $_GET['event_id']) : '';
$entityType = strtolower(trim((string) ($_GET['entity'] ?? '')));
$entityId = trim((string) ($_GET['id'] ?? ''));
$event = null;
$error = '';
$actionError = '';
$actionOk = '';
$user = current_user();
$viewerId = $user !== null ? (int) $user['user_id'] : 0;

try {
    $pdo = db();
    if ($eventId !== '' && ctype_digit($eventId)) {
        $stmt = $pdo->prepare('
            SELECT re.*, u.email AS requested_email
            FROM REVIEW_EVENTS re
            LEFT JOIN USERS u ON u.user_id = re.requested_by
            WHERE re.event_id = :event_id
            LIMIT 1
        ');
        $stmt->execute([':event_id' => (int) $eventId]);
        $event = $stmt->fetch() ?: null;
    } elseif ($entityType !== '' && $entityId !== '') {
        if (!in_array($entityType, ['artist', 'album', 'track'], true)) {
            $error = 'Invalid entity type.';
        } else {
            $stmt = $pdo->prepare('
                SELECT re.*, u.email AS requested_email
                FROM REVIEW_EVENTS re
                LEFT JOIN USERS u ON u.user_id = re.requested_by
                WHERE re.entity_type = :entity_type
                  AND re.entity_id = :entity_id
                ORDER BY re.event_id DESC
                LIMIT 1
            ');
            $stmt->execute([
                ':entity_type' => $entityType,
                ':entity_id' => $entityId,
            ]);
            $event = $stmt->fetch() ?: null;
        }
    } else {
        $error = 'Missing review event identifier.';
    }
} catch (Throwable $e) {
    $error = 'Failed to load review detail.';
}

/**
 * @return array<string,mixed>|null
 */
function fallback_entity_snapshot(PDO $pdo, string $entityType, string $entityId): ?array
{
    if ($entityType === 'track') {
        $stmt = $pdo->prepare('
            SELECT t.track_id, t.track_name, t.popularity, t.duration_ms, t.explicit, t.preview_url, t.status, t.submitted_by, t.reviewed_by
            FROM TRACKS t WHERE t.track_id = :id LIMIT 1
        ');
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $artistStmt = $pdo->prepare('
            SELECT ar.artist_name
            FROM TRACK_ARTISTS ta JOIN ARTISTS ar ON ar.artist_id = ta.artist_id
            WHERE ta.track_id = :id ORDER BY ar.artist_name ASC LIMIT 1
        ');
        $artistStmt->execute([':id' => $entityId]);
        $albumStmt = $pdo->prepare('
            SELECT al.album_name, at.disc_number, at.track_number
            FROM ALBUM_TRACKS at JOIN ALBUMS al ON al.album_id = at.album_id
            WHERE at.track_id = :id ORDER BY al.album_name ASC LIMIT 1
        ');
        $albumStmt->execute([':id' => $entityId]);
        $albumRow = $albumStmt->fetch();
        return [
            'track' => $row,
            'main_artist' => $artistStmt->fetchColumn() ?: null,
            'album_name' => $albumRow['album_name'] ?? null,
            'disc_number' => $albumRow['disc_number'] ?? null,
            'track_number' => $albumRow['track_number'] ?? null,
        ];
    }
    if ($entityType === 'album') {
        $stmt = $pdo->prepare('
            SELECT al.album_id, al.album_name, al.release_date, al.album_image_url, al.status, al.submitted_by, al.reviewed_by
            FROM ALBUMS al WHERE al.album_id = :id LIMIT 1
        ');
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $artistStmt = $pdo->prepare('
            SELECT ar.artist_name
            FROM ALBUM_ARTISTS aa JOIN ARTISTS ar ON ar.artist_id = aa.artist_id
            WHERE aa.album_id = :id ORDER BY ar.artist_name ASC LIMIT 1
        ');
        $artistStmt->execute([':id' => $entityId]);
        return [
            'album' => $row,
            'main_artist' => $artistStmt->fetchColumn() ?: null,
        ];
    }
    if ($entityType === 'artist') {
        $stmt = $pdo->prepare('
            SELECT ar.artist_id, ar.artist_name, ar.status, ar.submitted_by, ar.reviewed_by
            FROM ARTISTS ar WHERE ar.artist_id = :id LIMIT 1
        ');
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $genreStmt = $pdo->prepare('
            SELECT genre FROM ARTIST_GENRES WHERE artist_id = :id ORDER BY genre ASC
        ');
        $genreStmt->execute([':id' => $entityId]);
        $genres = [];
        while (($g = $genreStmt->fetchColumn()) !== false) {
            $genres[] = (string) $g;
        }
        return [
            'artist' => $row,
            'genres' => $genres,
        ];
    }
    return null;
}

if ($event === null && $error === '' && $entityType !== '' && $entityId !== '') {
    try {
        $snapshot = fallback_entity_snapshot(db(), $entityType, $entityId);
        if ($snapshot !== null) {
            $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
            $event = [
                'event_id' => 0,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action_type' => 'legacy_pending',
                'requested_email' => 'unknown (no historical event)',
                'requested_by' => '',
                'requested_role' => 'unknown',
                'status_at_submit' => CATALOG_STATUS_PENDING,
                'created_at' => '',
                // Legacy rows do not have historical audit diffs, so use current DB snapshot as baseline.
                'before_json' => $snapshotJson,
                'after_json' => $snapshotJson,
            ];
        }
    } catch (Throwable $e) {
        $error = 'Failed to load fallback detail.';
    }
}

if ($error === '' && is_array($event) && $viewerId > 0 && isset($event['event_id']) && (int) $event['event_id'] > 0) {
    try {
        mark_review_event_viewed((int) $event['event_id'], $viewerId);
    } catch (Throwable $e) {
        // Keep details readable even if marking the view fails.
    }
}

if ($error === '' && is_array($event) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewAction = strtolower(trim((string) ($_POST['review_action'] ?? '')));
    if (!in_array($reviewAction, ['approve', 'reject'], true)) {
        $actionError = 'Invalid review action.';
    } else {
        try {
            $pdo = db();
            $newStatus = $reviewAction === 'approve' ? CATALOG_STATUS_APPROVED : CATALOG_STATUS_REJECTED;
            $entity = (string) ($event['entity_type'] ?? '');
            $entityIdValue = (string) ($event['entity_id'] ?? '');
            $eventIdValue = isset($event['event_id']) ? (int) $event['event_id'] : 0;
            if (!in_array($entity, ['artist', 'album', 'track'], true) || $entityIdValue === '') {
                throw new RuntimeException('Review event data is invalid.');
            }

            if ($eventIdValue > 0) {
                $latestStmt = $pdo->prepare('
                    SELECT event_id
                    FROM REVIEW_EVENTS
                    WHERE entity_type = :entity_type
                      AND entity_id = :entity_id
                    ORDER BY event_id DESC
                    LIMIT 1
                ');
                $latestStmt->execute([
                    ':entity_type' => $entity,
                    ':entity_id' => $entityIdValue,
                ]);
                $latestEventId = $latestStmt->fetchColumn();
                if ($latestEventId === false || (int) $latestEventId !== $eventIdValue) {
                    throw new RuntimeException('A newer analyst change exists. Please review the newest Details first.');
                }
                if (!has_review_event_view($eventIdValue, $viewerId)) {
                    throw new RuntimeException('Please open Details before Approve/Reject.');
                }
            }

            $table = $entity === 'artist' ? 'ARTISTS' : ($entity === 'album' ? 'ALBUMS' : 'TRACKS');
            $idColumn = $entity === 'artist' ? 'artist_id' : ($entity === 'album' ? 'album_id' : 'track_id');

            $updateStmt = $pdo->prepare("
                UPDATE {$table}
                SET status = :status,
                    reviewed_by = :reviewed_by
                WHERE {$idColumn} = :id
            ");
            $updateStmt->execute([
                ':status' => $newStatus,
                ':reviewed_by' => $viewerId,
                ':id' => $entityIdValue,
            ]);

            header('Location: admin_page.php', true, 302);
            exit;
        } catch (Throwable $e) {
            $actionError = $e->getMessage();
        }
    }
}

/**
 * @return array<string,mixed>|null
 */
function decode_json_array(?string $raw): ?array
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param mixed $value
 */
function value_label($value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    return $json === false ? '[complex]' : $json;
}

/**
 * @param array<string,mixed>|null $before
 * @param array<string,mixed>|null $after
 * @return array<int,array{field:string,before:string,after:string,kind:string}>
 */
function collect_changed_fields(?array $before, ?array $after): array
{
    $changes = [];
    $walk = static function ($b, $a, string $path = '') use (&$changes, &$walk): void {
        $bIsArray = is_array($b);
        $aIsArray = is_array($a);
        if ($bIsArray || $aIsArray) {
            $bArr = $bIsArray ? $b : [];
            $aArr = $aIsArray ? $a : [];
            $keys = array_values(array_unique(array_merge(array_keys($bArr), array_keys($aArr))));
            sort($keys);
            foreach ($keys as $key) {
                $nextPath = $path === '' ? (string) $key : $path . '.' . (string) $key;
                $walk($bArr[$key] ?? null, $aArr[$key] ?? null, $nextPath);
            }
            return;
        }
        if ($b === $a) {
            return;
        }
        $kind = 'updated';
        if ($b === null && $a !== null) {
            $kind = 'added';
        } elseif ($b !== null && $a === null) {
            $kind = 'removed';
        }
        $changes[] = [
            'field' => $path === '' ? '(root)' : $path,
            'before' => value_label($b),
            'after' => value_label($a),
            'kind' => $kind,
        ];
    };
    $walk($before, $after, '');
    return $changes;
}

function action_label(string $actionType): string
{
    $x = strtolower(trim($actionType));
    if ($x === 'delete_request') {
        return 'Delete request';
    }
    if ($x === 'create') {
        return 'Create';
    }
    if ($x === 'edit') {
        return 'Edit';
    }
    return ucfirst($x);
}

$beforeData = is_array($event) ? decode_json_array(isset($event['before_json']) ? (string) $event['before_json'] : null) : null;
$afterData = is_array($event) ? decode_json_array(isset($event['after_json']) ? (string) $event['after_json'] : null) : null;
$changes = collect_changed_fields($beforeData, $afterData);
$systemNoiseFields = [
    'track.reviewed_by' => true,
    'album.reviewed_by' => true,
    'artist.reviewed_by' => true,
    'track.submitted_by' => true,
    'album.submitted_by' => true,
    'artist.submitted_by' => true,
];
$changes = array_values(array_filter(
    $changes,
    static function (array $ch) use ($systemNoiseFields): bool {
        $field = (string) ($ch['field'] ?? '');
        return !isset($systemNoiseFields[$field]);
    }
));
$changedPathMap = [];
foreach ($changes as $ch) {
    $changedPathMap[$ch['field']] = true;
}
$trackView = null;
$albumView = null;
$artistView = null;
if (is_array($event) && ((string) ($event['entity_type'] ?? '') === 'track')) {
    $trackView = [
        'track_id' => value_label(($afterData['track']['track_id'] ?? $beforeData['track']['track_id'] ?? null)),
        'track_name' => value_label(($afterData['track']['track_name'] ?? $beforeData['track']['track_name'] ?? null)),
        'popularity' => value_label(($afterData['track']['popularity'] ?? $beforeData['track']['popularity'] ?? null)),
        'duration_ms' => value_label(($afterData['track']['duration_ms'] ?? $beforeData['track']['duration_ms'] ?? null)),
        'explicit' => value_label(($afterData['track']['explicit'] ?? $beforeData['track']['explicit'] ?? null)),
        'preview_url' => value_label(($afterData['track']['preview_url'] ?? $beforeData['track']['preview_url'] ?? null)),
        'status' => value_label(($afterData['track']['status'] ?? $beforeData['track']['status'] ?? null)),
        'submitted_by' => value_label(($afterData['track']['submitted_by'] ?? $beforeData['track']['submitted_by'] ?? null)),
        'reviewed_by' => value_label(($afterData['track']['reviewed_by'] ?? $beforeData['track']['reviewed_by'] ?? null)),
        'main_artist' => value_label(($afterData['main_artist'] ?? $beforeData['main_artist'] ?? null)),
        'album_name' => value_label(($afterData['album_name'] ?? $beforeData['album_name'] ?? null)),
        'disc_number' => value_label(($afterData['disc_number'] ?? $beforeData['disc_number'] ?? null)),
        'track_number' => value_label(($afterData['track_number'] ?? $beforeData['track_number'] ?? null)),
    ];
}
if (is_array($event) && ((string) ($event['entity_type'] ?? '') === 'album')) {
    $albumView = [
        'album_id' => value_label(($afterData['album']['album_id'] ?? $beforeData['album']['album_id'] ?? null)),
        'album_name' => value_label(($afterData['album']['album_name'] ?? $beforeData['album']['album_name'] ?? null)),
        'release_date' => value_label(($afterData['album']['release_date'] ?? $beforeData['album']['release_date'] ?? null)),
        'album_image_url' => value_label(($afterData['album']['album_image_url'] ?? $beforeData['album']['album_image_url'] ?? null)),
        'main_artist' => value_label(($afterData['main_artist'] ?? $beforeData['main_artist'] ?? null)),
        'status' => value_label(($afterData['album']['status'] ?? $beforeData['album']['status'] ?? null)),
        'submitted_by' => value_label(($afterData['album']['submitted_by'] ?? $beforeData['album']['submitted_by'] ?? null)),
        'reviewed_by' => value_label(($afterData['album']['reviewed_by'] ?? $beforeData['album']['reviewed_by'] ?? null)),
    ];
}
if (is_array($event) && ((string) ($event['entity_type'] ?? '') === 'artist')) {
    $genresAfter = $afterData['genres'] ?? null;
    $genresBefore = $beforeData['genres'] ?? null;
    $artistView = [
        'artist_id' => value_label(($afterData['artist']['artist_id'] ?? $beforeData['artist']['artist_id'] ?? null)),
        'artist_name' => value_label(($afterData['artist']['artist_name'] ?? $beforeData['artist']['artist_name'] ?? null)),
        'genres' => value_label($genresAfter ?? $genresBefore),
        'status' => value_label(($afterData['artist']['status'] ?? $beforeData['artist']['status'] ?? null)),
        'submitted_by' => value_label(($afterData['artist']['submitted_by'] ?? $beforeData['artist']['submitted_by'] ?? null)),
        'reviewed_by' => value_label(($afterData['artist']['reviewed_by'] ?? $beforeData['artist']['reviewed_by'] ?? null)),
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>MusicBox Admin · Review Detail</title>
  <style>
    :root{color-scheme:dark}
    body{margin:0;background:#050507;color:#f2f3f5;font:14px/1.5 Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
    .wrap{max-width:1000px;margin:0 auto;padding:24px}
    .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
    .topActions{display:flex;gap:10px;align-items:center}
    .backBtn{
      color:#d7dde7;
      background:rgba(255,255,255,.04);
      border:1px solid rgba(255,255,255,.16);
      border-radius:10px;
      padding:8px 12px;
      cursor:pointer;
      font:inherit;
    }
    .back{color:#85f4c6;text-decoration:none;border:1px solid rgba(133,244,198,.35);padding:8px 12px;border-radius:10px}
    .card{background:#0b0d12;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px}
    .meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px}
    .meta .k{font-size:12px;color:#b8c0ca}
    .meta .v{font-weight:600;word-break:break-word}
    h3{margin:14px 0 8px}
    .err{color:#ff7f7f}
    .summary{margin:6px 0 14px;padding:10px 12px;border-radius:10px;background:#07090d;border:1px solid rgba(255,255,255,.08)}
    .summary b{color:#85f4c6}
    table{width:100%;border-collapse:collapse;background:#07090d;border:1px solid rgba(255,255,255,.08);border-radius:10px;overflow:hidden}
    th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:top;text-align:left}
    th{font-size:12px;color:#b8c0ca}
    tr:last-child td{border-bottom:none}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;word-break:break-word}
    .tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px}
    .tag.added{background:rgba(24,243,163,.16);color:#5ff0b8}
    .tag.removed{background:rgba(255,94,94,.16);color:#ff8e8e}
    .tag.updated{background:rgba(255,184,0,.18);color:#f8cb63}
    .trackSection{border:1px solid rgba(255,255,255,.08);background:#07090d;border-radius:14px;padding:14px}
    .trackGrid{display:grid;grid-template-columns:200px 1fr;gap:10px 12px;align-items:center}
    .trackLabel{font-size:12px;color:#b8c0ca;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .trackInput{
      width:100%;
      border-radius:12px;
      padding:10px 12px;
      border:1px solid rgba(255,255,255,.12);
      background:rgba(255,255,255,.04);
      color:#f2f3f5;
      font-size:13px;
      font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    }
    .trackInput.changed{
      border-color:rgba(255,184,0,.72);
      background:rgba(255,184,0,.14);
    }
    .trackWide{grid-column:1 / -1}
    .reviewActions{display:flex;gap:10px;margin-top:14px}
    .btn{border:1px solid rgba(255,255,255,.16);background:#11151c;color:#f2f3f5;border-radius:999px;padding:8px 14px;cursor:pointer}
    .btn.ok{border-color:rgba(24,243,163,.45);background:rgba(24,243,163,.13);color:#5ff0b8}
    .btn.no{border-color:rgba(255,94,94,.5);background:rgba(255,94,94,.13);color:#ff9494}
    .btn.back{border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.05);color:#e8ebf0}
    .actionMsg{margin-top:10px;font-size:13px}
    .actionMsg.err{color:#ff8e8e}
    .actionMsg.ok{color:#5ff0b8}
    @media (max-width: 900px){
      .trackGrid{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <h2>Review Detail</h2>
      <div class="topActions">
        <button type="button" class="backBtn" id="goBackBtn">Back</button>
        <a class="back" href="admin_page.php">Back to Admin Review</a>
      </div>
    </div>
    <div class="card">
      <?php if ($error !== ''): ?>
        <div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
      <?php elseif ($event === null): ?>
        <div class="err">No review detail found for this item.</div>
      <?php else: ?>
        <?php if ($actionError !== ''): ?>
          <div class="actionMsg err"><?php echo htmlspecialchars($actionError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($actionOk !== ''): ?>
          <div class="actionMsg ok"><?php echo htmlspecialchars($actionOk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="meta">
          <div><div class="k">Entity</div><div class="v"><?php echo htmlspecialchars((string) $event['entity_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> / <?php echo htmlspecialchars((string) $event['entity_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
          <div><div class="k">Action</div><div class="v"><?php echo htmlspecialchars((string) $event['action_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
          <div><div class="k">Submitted status</div><div class="v"><?php echo htmlspecialchars((string) $event['status_at_submit'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
          <div><div class="k">Requested by</div><div class="v"><?php echo htmlspecialchars((string) ($event['requested_email'] ?? $event['requested_by']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
          <div><div class="k">Role</div><div class="v"><?php echo htmlspecialchars((string) $event['requested_role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
          <div><div class="k">Created at</div><div class="v"><?php echo htmlspecialchars((string) $event['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
        </div>
        <div class="summary">
          Analyst action: <b><?php echo htmlspecialchars(action_label((string) $event['action_type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></b>.
          <?php if (count($changes) > 0): ?>
            Changed fields: <b><?php echo (string) count($changes); ?></b>.
          <?php else: ?>
            No field-level change detected.
          <?php endif; ?>
        </div>
        <?php if ($trackView !== null): ?>
          <h3>Track Edit Preview</h3>
          <div class="trackSection">
            <div class="trackGrid">
              <label class="trackLabel">Track ID</label>
              <input class="trackInput <?php echo isset($changedPathMap['track.track_id']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['track_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Track Name</label>
              <input class="trackInput <?php echo isset($changedPathMap['track.track_name']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['track_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Main Artist</label>
              <input class="trackInput <?php echo isset($changedPathMap['main_artist']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['main_artist'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Album Name</label>
              <input class="trackInput <?php echo isset($changedPathMap['album_name']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['album_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Disc Number</label>
              <input class="trackInput <?php echo isset($changedPathMap['disc_number']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['disc_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Track Number</label>
              <input class="trackInput <?php echo isset($changedPathMap['track_number']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['track_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Popularity</label>
              <input class="trackInput <?php echo isset($changedPathMap['track.popularity']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['popularity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Duration (ms)</label>
              <input class="trackInput <?php echo isset($changedPathMap['track.duration_ms']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['duration_ms'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Explicit</label>
              <input class="trackInput <?php echo isset($changedPathMap['track.explicit']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['explicit'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Status</label>
              <input class="trackInput <?php echo isset($changedPathMap['track.status']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Submitted By</label>
              <input class="trackInput <?php echo isset($changedPathMap['track.submitted_by']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['submitted_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Reviewed By</label>
              <input class="trackInput <?php echo isset($changedPathMap['track.reviewed_by']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['reviewed_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Preview URL</label>
              <input class="trackInput <?php echo isset($changedPathMap['track.preview_url']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($trackView['preview_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
            </div>
          </div>
        <?php endif; ?>
        <?php if ($albumView !== null): ?>
          <h3>Album Edit Preview</h3>
          <div class="trackSection">
            <div class="trackGrid">
              <label class="trackLabel">Album ID</label>
              <input class="trackInput <?php echo isset($changedPathMap['album.album_id']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($albumView['album_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Album Name</label>
              <input class="trackInput <?php echo isset($changedPathMap['album.album_name']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($albumView['album_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Release Date</label>
              <input class="trackInput <?php echo isset($changedPathMap['album.release_date']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($albumView['release_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Main Artist</label>
              <input class="trackInput <?php echo isset($changedPathMap['main_artist']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($albumView['main_artist'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Status</label>
              <input class="trackInput <?php echo isset($changedPathMap['album.status']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($albumView['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Submitted By</label>
              <input class="trackInput <?php echo isset($changedPathMap['album.submitted_by']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($albumView['submitted_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Reviewed By</label>
              <input class="trackInput <?php echo isset($changedPathMap['album.reviewed_by']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($albumView['reviewed_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Album Image URL</label>
              <input class="trackInput <?php echo isset($changedPathMap['album.album_image_url']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($albumView['album_image_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
            </div>
          </div>
        <?php endif; ?>
        <?php if ($artistView !== null): ?>
          <h3>Artist Edit Preview</h3>
          <div class="trackSection">
            <div class="trackGrid">
              <label class="trackLabel">Artist ID</label>
              <input class="trackInput <?php echo isset($changedPathMap['artist.artist_id']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($artistView['artist_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Artist Name</label>
              <input class="trackInput <?php echo isset($changedPathMap['artist.artist_name']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($artistView['artist_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Status</label>
              <input class="trackInput <?php echo isset($changedPathMap['artist.status']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($artistView['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Submitted By</label>
              <input class="trackInput <?php echo isset($changedPathMap['artist.submitted_by']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($artistView['submitted_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Reviewed By</label>
              <input class="trackInput <?php echo isset($changedPathMap['artist.reviewed_by']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($artistView['reviewed_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
              <label class="trackLabel">Genres</label>
              <input class="trackInput <?php echo isset($changedPathMap['genres']) ? 'changed' : ''; ?>" type="text" readonly value="<?php echo htmlspecialchars($artistView['genres'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
            </div>
          </div>
        <?php endif; ?>
        <h3>Changed Fields</h3>
        <?php if (count($changes) === 0): ?>
          <div>No field-level differences found in this request.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Field</th>
                <th>Change</th>
                <th>Before</th>
                <th>After</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($changes as $ch): ?>
                <tr>
                  <td class="mono"><?php echo htmlspecialchars($ch['field'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                  <td><span class="tag <?php echo htmlspecialchars($ch['kind'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars($ch['kind'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></td>
                  <td class="mono"><?php echo htmlspecialchars($ch['before'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                  <td class="mono"><?php echo htmlspecialchars($ch['after'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <form method="post" action="">
          <div class="reviewActions">
            <button type="button" class="btn back" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href='admin_page.php'; }">Back</button>
            <button type="submit" class="btn ok" name="review_action" value="approve">Approve</button>
            <button type="submit" class="btn no" name="review_action" value="reject">Reject</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <script>
    const goBackBtn = document.getElementById("goBackBtn");
    if (goBackBtn) {
      goBackBtn.addEventListener("click", () => {
        if (window.history.length > 1) {
          window.history.back();
          return;
        }
        window.location.href = "admin_page.php";
      });
    }
  </script>
</body>
</html>
