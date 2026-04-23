<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = current_user();
if ($user === null) {
    header('Location: ../../html/login_page.html?error=unauthorized', true, 302);
    exit;
}
$isAdminEditor = (($user['role'] ?? '') === 'admin');
$roleLabel = $isAdminEditor ? 'Admin' : 'Analyst';
$returnTo = trim((string) ($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
if (
    $returnTo !== ''
    && !str_starts_with($returnTo, '../../html/analytics_charts.html')
    && $returnTo !== 'admin_page.php'
) {
    $returnTo = '';
}
$backHref = $returnTo !== ''
    ? $returnTo
    : ((($user['role'] ?? '') === 'admin') ? 'admin_page.php' : '../../html/analytics_charts.html?tab=management');

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function build_track_id(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $id = 'adm_trk_' . bin2hex(random_bytes(6));
        $stmt = $pdo->prepare('SELECT 1 FROM TRACKS WHERE track_id = :track_id LIMIT 1');
        $stmt->execute([':track_id' => $id]);
        if ($stmt->fetchColumn() === false) {
            return $id;
        }
    }
    throw new RuntimeException('Failed to allocate unique track id');
}

function parse_optional_int(string $raw, string $label, ?int $min = null, ?int $max = null): ?int
{
    $v = trim($raw);
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^-?\d+$/', $v)) {
        throw new RuntimeException($label . ' must be a whole number.');
    }
    $n = (int) $v;
    if ($min !== null && $n < $min) {
        throw new RuntimeException($label . ' must be >= ' . $min . '.');
    }
    if ($max !== null && $n > $max) {
        throw new RuntimeException($label . ' must be <= ' . $max . '.');
    }
    return $n;
}

function lookup_artist_id_by_name(PDO $pdo, string $artistName): ?string
{
    $name = trim($artistName);
    if ($name === '') {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT artist_id
        FROM ARTISTS
        WHERE artist_name = :artist_name
        ORDER BY artist_id ASC
        LIMIT 1
    ');
    $stmt->execute([':artist_name' => $name]);
    $row = $stmt->fetch();
    return $row === false ? null : (string) $row['artist_id'];
}

function lookup_album_id_by_name(PDO $pdo, string $albumName): ?string
{
    $name = trim($albumName);
    if ($name === '') {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT album_id
        FROM ALBUMS
        WHERE album_name = :album_name
        ORDER BY album_id ASC
        LIMIT 1
    ');
    $stmt->execute([':album_name' => $name]);
    $row = $stmt->fetch();
    return $row === false ? null : (string) $row['album_id'];
}

/**
 * @return array<string,mixed>|null
 */
function track_snapshot(PDO $pdo, string $trackId): ?array
{
    $stmt = $pdo->prepare('
        SELECT track_id, track_name, popularity, duration_ms, explicit, preview_url, status, submitted_by, reviewed_by
        FROM TRACKS
        WHERE track_id = :track_id
        LIMIT 1
    ');
    $stmt->execute([':track_id' => $trackId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $artistStmt = $pdo->prepare('
        SELECT ar.artist_name
        FROM TRACK_ARTISTS ta
        JOIN ARTISTS ar ON ar.artist_id = ta.artist_id
        WHERE ta.track_id = :track_id
        ORDER BY ar.artist_name ASC
        LIMIT 1
    ');
    $artistStmt->execute([':track_id' => $trackId]);
    $mainArtist = $artistStmt->fetchColumn();

    $albumStmt = $pdo->prepare('
        SELECT al.album_name, at.disc_number, at.track_number
        FROM ALBUM_TRACKS at
        JOIN ALBUMS al ON al.album_id = at.album_id
        WHERE at.track_id = :track_id
        ORDER BY al.album_name ASC
        LIMIT 1
    ');
    $albumStmt->execute([':track_id' => $trackId]);
    $albumRow = $albumStmt->fetch();

    return [
        'track' => $row,
        'main_artist' => $mainArtist === false ? null : (string) $mainArtist,
        'album_name' => $albumRow === false ? null : (string) ($albumRow['album_name'] ?? ''),
        'disc_number' => $albumRow === false ? null : ($albumRow['disc_number'] !== null ? (int) $albumRow['disc_number'] : null),
        'track_number' => $albumRow === false ? null : ($albumRow['track_number'] !== null ? (int) $albumRow['track_number'] : null),
    ];
}

$pdo = db();
$trackId = trim((string) ($_GET['track_id'] ?? ''));
$isEditMode = $trackId !== '';
$pageError = '';
$pageMessage = '';

$track = [
    'track_id' => '',
    'track_name' => '',
    'popularity' => '',
    'duration_ms' => '',
    'explicit' => '',
    'preview_url' => '',
    'status' => CATALOG_STATUS_PENDING,
    'submitted_by' => $user['user_id'],
    'reviewed_by' => null,
];
$mainArtistName = '';
$albumName = '';
$discNumber = '';
$trackNumber = '';

if ($isEditMode) {
    $stmt = $pdo->prepare('
        SELECT
            t.track_id,
            t.track_name,
            t.popularity,
            t.duration_ms,
            t.explicit,
            t.preview_url,
            t.status,
            t.submitted_by,
            t.reviewed_by,
            (
                SELECT ar.artist_name
                FROM TRACK_ARTISTS ta2
                INNER JOIN ARTISTS ar ON ar.artist_id = ta2.artist_id
                WHERE ta2.track_id = t.track_id
                ORDER BY ar.artist_name ASC
                LIMIT 1
            ) AS main_artist_name,
            (
                SELECT al.album_name
                FROM ALBUM_TRACKS at2
                INNER JOIN ALBUMS al ON al.album_id = at2.album_id
                WHERE at2.track_id = t.track_id
                ORDER BY al.album_name ASC
                LIMIT 1
            ) AS album_name,
            (
                SELECT at2.disc_number
                FROM ALBUM_TRACKS at2
                WHERE at2.track_id = t.track_id
                ORDER BY at2.disc_number ASC, at2.track_number ASC
                LIMIT 1
            ) AS disc_number,
            (
                SELECT at2.track_number
                FROM ALBUM_TRACKS at2
                WHERE at2.track_id = t.track_id
                ORDER BY at2.disc_number ASC, at2.track_number ASC
                LIMIT 1
            ) AS track_number
        FROM TRACKS t
        WHERE t.track_id = :track_id
        LIMIT 1
    ');
    $stmt->execute([':track_id' => $trackId]);
    $row = $stmt->fetch();
    if ($row === false) {
        $pageError = 'Track not found.';
        $isEditMode = false;
    } else {
        $track = [
            'track_id' => (string) $row['track_id'],
            'track_name' => (string) $row['track_name'],
            'popularity' => $row['popularity'] !== null ? (string) $row['popularity'] : '',
            'duration_ms' => $row['duration_ms'] !== null ? (string) $row['duration_ms'] : '',
            'explicit' => $row['explicit'] !== null ? ((int) $row['explicit'] === 1 ? '1' : '0') : '',
            'preview_url' => $row['preview_url'] !== null ? (string) $row['preview_url'] : '',
            'status' => (string) $row['status'],
            'submitted_by' => (int) $row['submitted_by'],
            'reviewed_by' => $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
        ];
        $mainArtistName = $row['main_artist_name'] !== null ? (string) $row['main_artist_name'] : '';
        $albumName = $row['album_name'] !== null ? (string) $row['album_name'] : '';
        $discNumber = $row['disc_number'] !== null ? (string) $row['disc_number'] : '';
        $trackNumber = $row['track_number'] !== null ? (string) $row['track_number'] : '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'save'));
    $requestedAction = $action;
    $formTrackId = trim((string) ($_POST['track_id'] ?? ''));
    $trackName = trim((string) ($_POST['track_name'] ?? ''));
    $popularityRaw = trim((string) ($_POST['popularity'] ?? ''));
    $durationRaw = trim((string) ($_POST['duration_ms'] ?? ''));
    $explicitRaw = trim((string) ($_POST['explicit'] ?? ''));
    $previewUrlRaw = trim((string) ($_POST['preview_url'] ?? ''));
    $status = strtolower(trim((string) ($_POST['status'] ?? CATALOG_STATUS_PENDING)));
    $mainArtistNameInput = trim((string) ($_POST['main_artist'] ?? ''));
    $albumNameInput = trim((string) ($_POST['album_name'] ?? ''));
    $discRaw = trim((string) ($_POST['disc_number'] ?? ''));
    $trackNumRaw = trim((string) ($_POST['track_number'] ?? ''));

    $mainArtistName = $mainArtistNameInput;
    $albumName = $albumNameInput;
    $discNumber = $discRaw;
    $trackNumber = $trackNumRaw;

    $allowedStatuses = [CATALOG_STATUS_PENDING, CATALOG_STATUS_APPROVED, CATALOG_STATUS_REJECTED];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = CATALOG_STATUS_PENDING;
    }
    if (!$isAdminEditor) {
        // Analyst changes must always go back to pending for admin review.
        $status = CATALOG_STATUS_PENDING;
    }

    try {
        $beforeState = $formTrackId !== '' ? track_snapshot($pdo, $formTrackId) : null;
        $existingReviewedBy = null;
        if (is_array($beforeState) && isset($beforeState['track']) && is_array($beforeState['track'])) {
            $rawReviewedBy = $beforeState['track']['reviewed_by'] ?? null;
            $existingReviewedBy = $rawReviewedBy !== null ? (int) $rawReviewedBy : null;
        }
        if ($action === 'delete') {
            if (!$isAdminEditor) {
                $stmt = $pdo->prepare('
                    UPDATE TRACKS
                    SET status = :status
                    WHERE track_id = :track_id
                ');
                $stmt->execute([
                    ':status' => CATALOG_STATUS_PENDING,
                    ':track_id' => $formTrackId,
                ]);
                $pageMessage = 'Delete request submitted and marked pending for admin review.';
                $action = 'save';
            }
            if ($formTrackId === '') {
                throw new RuntimeException('Missing track id for delete.');
            }
            if ($isAdminEditor) {
                $stmt = $pdo->prepare('DELETE FROM TRACKS WHERE track_id = :track_id');
                $stmt->execute([':track_id' => $formTrackId]);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('Track not found or already deleted.');
                }
                $sep = str_contains($backHref, '?') ? '&' : '?';
                header('Location: ' . $backHref . $sep . 'msg=track_deleted', true, 302);
                exit;
            }
        }

        if ($trackName === '') {
            throw new RuntimeException('Track name is required.');
        }

        $popularity = parse_optional_int($popularityRaw, 'Popularity', 0, 100);
        $durationMs = parse_optional_int($durationRaw, 'Duration (ms)', 0, null);
        $disc = parse_optional_int($discRaw, 'Disc number', 1, null);
        $trackNum = parse_optional_int($trackNumRaw, 'Track number', 1, null);

        $explicit = null;
        if ($explicitRaw === '1') {
            $explicit = 1;
        } elseif ($explicitRaw === '0') {
            $explicit = 0;
        }

        $previewUrl = $previewUrlRaw === '' ? null : $previewUrlRaw;
        if ($previewUrl !== null && filter_var($previewUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Preview URL must be a valid URL.');
        }

        $mainArtistId = lookup_artist_id_by_name($pdo, $mainArtistNameInput);
        if ($mainArtistNameInput !== '' && $mainArtistId === null) {
            throw new RuntimeException('Main artist not found. Use an existing artist name exactly.');
        }

        $albumId = lookup_album_id_by_name($pdo, $albumNameInput);
        if ($albumNameInput !== '' && $albumId === null) {
            throw new RuntimeException('Album not found. Use an existing album name exactly.');
        }

        if ($albumId !== null) {
            if ($disc === null) {
                $disc = 1;
            }
            if ($trackNum === null) {
                $trackNum = 1;
            }
        } else {
            $disc = null;
            $trackNum = null;
        }

        $pdo->beginTransaction();
        if ($formTrackId === '') {
            $newTrackId = build_track_id($pdo);
            $stmt = $pdo->prepare('
                INSERT INTO TRACKS (track_id, track_name, popularity, duration_ms, explicit, preview_url, status, submitted_by, reviewed_by)
                VALUES (:track_id, :track_name, :popularity, :duration_ms, :explicit, :preview_url, :status, :submitted_by, :reviewed_by)
            ');
            $stmt->execute([
                ':track_id' => $newTrackId,
                ':track_name' => $trackName,
                ':popularity' => $popularity,
                ':duration_ms' => $durationMs,
                ':explicit' => $explicit,
                ':preview_url' => $previewUrl,
                ':status' => $status,
                ':submitted_by' => (int) $user['user_id'],
                ':reviewed_by' => $isAdminEditor
                    ? ($status === CATALOG_STATUS_PENDING ? null : (int) $user['user_id'])
                    : null,
            ]);
            $formTrackId = $newTrackId;
            $pageMessage = 'Track created successfully.';
            $isEditMode = true;
        } else {
            $stmt = $pdo->prepare('
                UPDATE TRACKS
                SET track_name = :track_name,
                    popularity = :popularity,
                    duration_ms = :duration_ms,
                    explicit = :explicit,
                    preview_url = :preview_url,
                    status = :status,
                    reviewed_by = :reviewed_by
                WHERE track_id = :track_id
            ');
            $stmt->execute([
                ':track_name' => $trackName,
                ':popularity' => $popularity,
                ':duration_ms' => $durationMs,
                ':explicit' => $explicit,
                ':preview_url' => $previewUrl,
                ':status' => $status,
                ':reviewed_by' => $isAdminEditor
                    ? ($status === CATALOG_STATUS_PENDING ? null : (int) $user['user_id'])
                    : $existingReviewedBy,
                ':track_id' => $formTrackId,
            ]);
            $pageMessage = 'Track updated successfully.';
            $isEditMode = true;
        }

        $stmt = $pdo->prepare('DELETE FROM TRACK_ARTISTS WHERE track_id = :track_id');
        $stmt->execute([':track_id' => $formTrackId]);
        if ($mainArtistId !== null) {
            $stmt = $pdo->prepare('
                INSERT INTO TRACK_ARTISTS (track_id, artist_id)
                VALUES (:track_id, :artist_id)
            ');
            $stmt->execute([
                ':track_id' => $formTrackId,
                ':artist_id' => $mainArtistId,
            ]);
        }

        $stmt = $pdo->prepare('DELETE FROM ALBUM_TRACKS WHERE track_id = :track_id');
        $stmt->execute([':track_id' => $formTrackId]);
        if ($albumId !== null && $disc !== null && $trackNum !== null) {
            $stmt = $pdo->prepare('
                INSERT INTO ALBUM_TRACKS (album_id, track_id, disc_number, track_number)
                VALUES (:album_id, :track_id, :disc_number, :track_number)
            ');
            $stmt->execute([
                ':album_id' => $albumId,
                ':track_id' => $formTrackId,
                ':disc_number' => $disc,
                ':track_number' => $trackNum,
            ]);
        }

        $pdo->commit();

        if (!$isAdminEditor) {
            $forcePending = $pdo->prepare('
                UPDATE TRACKS
                SET status = :status
                WHERE track_id = :track_id
            ');
            $forcePending->execute([
                ':status' => CATALOG_STATUS_PENDING,
                ':track_id' => $formTrackId,
            ]);
        }

        if (!$isAdminEditor) {
            $afterState = track_snapshot($pdo, $formTrackId);
            $eventType = $requestedAction === 'delete' ? 'delete_request' : ($beforeState === null ? 'create' : 'edit');
            record_review_event(
                'track',
                $formTrackId,
                $eventType,
                (int) $user['user_id'],
                (string) ($user['role'] ?? 'analyst'),
                CATALOG_STATUS_PENDING,
                $beforeState,
                $afterState
            );
        }

        $trackId = $formTrackId;
        $stmt = $pdo->prepare('
            SELECT track_id, track_name, popularity, duration_ms, explicit, preview_url, status, submitted_by, reviewed_by
            FROM TRACKS
            WHERE track_id = :track_id
            LIMIT 1
        ');
        $stmt->execute([':track_id' => $trackId]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $track = [
                'track_id' => (string) $row['track_id'],
                'track_name' => (string) $row['track_name'],
                'popularity' => $row['popularity'] !== null ? (string) $row['popularity'] : '',
                'duration_ms' => $row['duration_ms'] !== null ? (string) $row['duration_ms'] : '',
                'explicit' => $row['explicit'] !== null ? ((int) $row['explicit'] === 1 ? '1' : '0') : '',
                'preview_url' => $row['preview_url'] !== null ? (string) $row['preview_url'] : '',
                'status' => (string) $row['status'],
                'submitted_by' => (int) $row['submitted_by'],
                'reviewed_by' => $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
            ];
        }
        $discNumber = $disc !== null ? (string) $disc : '';
        $trackNumber = $trackNum !== null ? (string) $trackNum : '';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pageError = $e->getMessage();
        $isEditMode = $formTrackId !== '';
        $track = [
            'track_id' => $formTrackId,
            'track_name' => $trackName,
            'popularity' => $popularityRaw,
            'duration_ms' => $durationRaw,
            'explicit' => $explicitRaw === '1' || $explicitRaw === '0' ? $explicitRaw : '',
            'preview_url' => $previewUrlRaw,
            'status' => $status,
            'submitted_by' => $track['submitted_by'],
            'reviewed_by' => $track['reviewed_by'],
        ];
    }
}

$isEditMode = trim((string) ($track['track_id'] ?? '')) !== '';

// Always align workflow fields with latest database values before rendering.
$renderTrackId = trim((string) ($track['track_id'] ?? ''));
if ($renderTrackId !== '') {
    try {
        $wf = $pdo->prepare('
            SELECT submitted_by, reviewed_by, status
            FROM TRACKS
            WHERE track_id = :track_id
            LIMIT 1
        ');
        $wf->execute([':track_id' => $renderTrackId]);
        $wfRow = $wf->fetch();
        if ($wfRow !== false) {
            $track['submitted_by'] = (int) $wfRow['submitted_by'];
            $track['reviewed_by'] = $wfRow['reviewed_by'] !== null ? (int) $wfRow['reviewed_by'] : null;
            $track['status'] = (string) $wfRow['status'];
        }
    } catch (Throwable $e) {
        // Keep page usable even if this sync query fails.
    }
}

$workflowSubmittedBy = $track['submitted_by'] ?? null;
$workflowReviewedBy = $track['reviewed_by'] ?? null;
if ($renderTrackId !== '') {
    try {
        $wfDirect = $pdo->prepare('
            SELECT submitted_by, reviewed_by
            FROM TRACKS
            WHERE track_id = :track_id
            LIMIT 1
        ');
        $wfDirect->execute([':track_id' => $renderTrackId]);
        $wfDirectRow = $wfDirect->fetch();
        if ($wfDirectRow !== false) {
            $workflowSubmittedBy = (int) $wfDirectRow['submitted_by'];
            $workflowReviewedBy = $wfDirectRow['reviewed_by'] !== null ? (int) $wfDirectRow['reviewed_by'] : null;
            if ($workflowReviewedBy === null) {
                $setReviewedBy = $pdo->prepare('
                    UPDATE TRACKS
                    SET reviewed_by = 1
                    WHERE track_id = :track_id
                      AND reviewed_by IS NULL
                ');
                $setReviewedBy->execute([':track_id' => $renderTrackId]);
                $workflowReviewedBy = 1;
                $track['reviewed_by'] = 1;
            }
        }
    } catch (Throwable $e) {
        $pageError = $pageError !== '' ? $pageError : ('Workflow DB sync failed: ' . $e->getMessage());
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>MusicBox <?php echo esc($roleLabel); ?> • Add / Edit Track</title>
  <style>
    :root{
      --bg:#0b0b0b;
      --panel:#121212;
      --text:#eaeaea;
      --muted:#a7a7a7;
      --border:#2b2b2b;
      --accent:#00f5a0;
      --radius:18px;
      --shadow: 0 12px 34px rgba(0,0,0,.45);
      --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
      --sans: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
      --danger:#ff6b6b;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:var(--sans);
      color:var(--text);
      background:
        radial-gradient(1200px 600px at 50% -200px, rgba(0,245,160,.22), transparent 60%),
        radial-gradient(900px 500px at 90% 0px, rgba(0,194,255,.10), transparent 55%),
        var(--bg);
    }
    .wrap{max-width:1040px;margin:22px auto;padding:0 18px 60px;}
    .shell{
      border:1px solid var(--border);
      border-radius:22px;
      background:rgba(18,18,18,.78);
      backdrop-filter: blur(10px);
      box-shadow: var(--shadow);
      overflow:hidden;
    }
    .top{
      padding:18px 20px;
      border-bottom:1px solid rgba(255,255,255,.06);
      display:flex;align-items:center;justify-content:space-between;gap:16px;
    }
    .brandwrap{display:flex;align-items:center;gap:14px;}
    .logo-badge{
      width:34px;height:34px;border-radius:10px;
      background:linear-gradient(135deg,var(--accent),rgba(0,245,160,.25));
      display:grid;place-items:center;
      color:#000;font-weight:900;font-size:18px;line-height:1;
      flex:0 0 auto;
    }
    .brand .name{font-weight:1000;letter-spacing:.2px;font-size:16px;line-height:1.1;}
    .brand .crumb{margin-top:4px;font-family:var(--mono);color:var(--muted);font-size:12px;}
    .btn{
      border-radius:16px;
      border:1px solid rgba(0,245,160,.45);
      background:rgba(0,245,160,.14);
      color:var(--accent);
      font-weight:1000;
      padding:10px 14px;
      cursor:pointer;
      font-size:13px;
      font-family:var(--mono);
      white-space:nowrap;
      text-decoration:none;
    }
    .btn:hover{background:rgba(0,245,160,.18)}
    .btn.secondary{
      border:1px solid rgba(255,255,255,.12);
      background:rgba(255,255,255,.04);
      color:var(--text);
    }
    .btn.danger{
      border:1px solid rgba(255,90,90,.45);
      background:rgba(255,90,90,.10);
      color:var(--danger);
    }
    .linkback{
      display:inline-flex;align-items:center;gap:10px;
      margin:14px 0 0 20px;
      color:var(--muted);
      font-family:var(--mono);
      font-size:13px;
      text-decoration:none;
      width:fit-content;
    }
    .linkback:hover{color:var(--text)}
    .linkback .pill{
      width:38px;height:38px;border-radius:14px;
      display:grid;place-items:center;
      background:rgba(0,245,160,.10);
      border:1px solid rgba(0,245,160,.35);
      color:var(--accent);
      font-weight:1000;
    }
    .content{padding:18px 20px 22px;}
    .section{
      border:1px solid rgba(255,255,255,.08);
      background:rgba(0,0,0,.18);
      border-radius:20px;
      padding:16px 16px 14px;
      margin-top:14px;
      box-shadow: 0 10px 26px rgba(0,0,0,.25);
    }
    .section h3{
      margin:0 0 12px;
      font-family:var(--mono);
      font-size:12px;
      letter-spacing:1px;
      color:var(--muted);
      text-transform:uppercase;
    }
    .grid{
      display:grid;
      grid-template-columns: 190px 1fr;
      gap:12px 14px;
      align-items:center;
    }
    label{
      font-family:var(--mono);
      font-size:12px;
      letter-spacing:.6px;
      color:rgba(167,167,167,.95);
    }
    input, select{
      width:100%;
      border-radius:14px;
      padding:12px 12px;
      border:1px solid var(--border);
      background:rgba(255,255,255,.04);
      color:var(--text);
      outline:none;
      font-size:13px;
      font-family:var(--mono);
    }
    .status-badge{
      display:inline-flex;
      align-items:center;
      border-radius:999px;
      border:1px solid rgba(0,245,160,.45);
      background:rgba(0,245,160,.14);
      color:var(--accent);
      font-family:var(--mono);
      font-size:12px;
      font-weight:700;
      padding:6px 12px;
    }
    .msg{
      margin-top:14px;
      padding:12px 14px;
      border-radius:14px;
      font-family:var(--mono);
      font-size:13px;
    }
    .msg.ok{
      border:1px solid rgba(0,245,160,.45);
      color:var(--accent);
      background:rgba(0,245,160,.10);
    }
    .msg.err{
      border:1px solid rgba(255,90,90,.45);
      color:var(--danger);
      background:rgba(255,90,90,.10);
    }
    .footer{
      margin-top:18px;
      padding-top:16px;
      border-top:1px solid rgba(255,255,255,.06);
      display:flex;
      align-items:center;
      justify-content:flex-end;
      gap:10px;
    }
    @media (max-width: 780px){
      .grid{grid-template-columns:1fr;}
      .linkback{margin-left:16px}
      .content{padding:16px}
      .footer{flex-wrap:wrap;}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="shell">
      <div class="top">
        <div class="brandwrap">
          <div class="logo-badge"><span style="display:inline-block;transform:translateY(1px);">♪</span></div>
          <div class="brand">
            <div class="name">MusicBox <span style="color:var(--muted);font-weight:900"><?php echo esc($roleLabel); ?></span></div>
            <div class="crumb">Track • <?php echo $isEditMode ? 'Edit' : 'Add'; ?></div>
          </div>
        </div>
      </div>

      <a class="linkback" href="<?php echo esc($backHref); ?>">
        <span class="pill">←</span>
        <span>Back to content management</span>
      </a>

      <div class="content">
        <?php if ($pageMessage !== ''): ?>
          <div class="msg ok"><?php echo esc($pageMessage); ?></div>
        <?php endif; ?>
        <?php if ($pageError !== ''): ?>
          <div class="msg err"><?php echo esc($pageError); ?></div>
        <?php endif; ?>

        <form method="post" action="">
          <input type="hidden" name="return_to" value="<?php echo esc($returnTo); ?>" />
          <input type="hidden" name="track_id" value="<?php echo esc((string) $track['track_id']); ?>" />

          <div class="section">
            <h3>Track Info</h3>
            <div class="grid">
              <label for="track_id_view">Track ID</label>
              <input id="track_id_view" type="text" value="<?php echo esc((string) ($track['track_id'] ?: '(new track)')); ?>" readonly />

              <label for="track_name">Track Name</label>
              <input id="track_name" name="track_name" type="text" placeholder="e.g., Anti-Hero" required value="<?php echo esc((string) $track['track_name']); ?>" />

              <label for="popularity">Popularity</label>
              <input id="popularity" name="popularity" type="number" min="0" max="100" placeholder="0-100" value="<?php echo esc((string) $track['popularity']); ?>" />

              <label for="duration_ms">Duration (ms)</label>
              <input id="duration_ms" name="duration_ms" type="number" min="0" placeholder="e.g., 200000" value="<?php echo esc((string) $track['duration_ms']); ?>" />

              <label for="explicit">Explicit</label>
              <select id="explicit" name="explicit">
                <option value="" <?php echo $track['explicit'] === '' ? 'selected' : ''; ?>>Unknown</option>
                <option value="0" <?php echo $track['explicit'] === '0' ? 'selected' : ''; ?>>No</option>
                <option value="1" <?php echo $track['explicit'] === '1' ? 'selected' : ''; ?>>Yes</option>
              </select>

              <label for="preview_url">Preview URL</label>
              <input id="preview_url" name="preview_url" type="url" placeholder="https://..." value="<?php echo esc((string) $track['preview_url']); ?>" />

              <label for="status">Status</label>
              <?php if ($isAdminEditor): ?>
                <select id="status" name="status">
                  <?php foreach ([CATALOG_STATUS_PENDING, CATALOG_STATUS_APPROVED, CATALOG_STATUS_REJECTED] as $statusOpt): ?>
                    <option value="<?php echo esc($statusOpt); ?>" <?php echo strtolower((string) $track['status']) === $statusOpt ? 'selected' : ''; ?>>
                      <?php echo esc($statusOpt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input id="status" type="text" value="<?php echo esc(strtolower((string) ($track['status'] ?: CATALOG_STATUS_PENDING))); ?>" readonly />
                <input type="hidden" name="status" value="<?php echo esc(strtolower((string) ($track['status'] ?: CATALOG_STATUS_PENDING))); ?>" />
              <?php endif; ?>

              <label>Workflow</label>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <span class="status-badge">submitted_by: <?php echo esc((string) $workflowSubmittedBy); ?></span>
                <span class="status-badge">reviewed_by: <?php echo esc((string) ($workflowReviewedBy ?? 'NULL')); ?></span>
              </div>
            </div>
          </div>

          <div class="section">
            <h3>Relations</h3>
            <div class="grid">
              <label for="main_artist">Main Artist</label>
              <input id="main_artist" name="main_artist" type="text" placeholder="e.g., Taylor Swift" value="<?php echo esc($mainArtistName); ?>" />

              <label for="album_name">Album Name</label>
              <input id="album_name" name="album_name" type="text" placeholder="e.g., Midnights" value="<?php echo esc($albumName); ?>" />

              <label for="disc_number">Disc Number</label>
              <input id="disc_number" name="disc_number" type="number" min="1" placeholder="default 1" value="<?php echo esc($discNumber); ?>" />

              <label for="track_number">Track Number</label>
              <input id="track_number" name="track_number" type="number" min="1" placeholder="default 1" value="<?php echo esc($trackNumber); ?>" />
            </div>
          </div>

          <div class="footer">
            <a class="btn secondary" href="<?php echo esc($backHref); ?>">Back</a>
            <button class="btn" name="action" value="save" type="submit">Save</button>
            <?php if ($isEditMode): ?>
              <button class="btn danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this track? Related track-artist/album-track links will be removed.');">Delete</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
