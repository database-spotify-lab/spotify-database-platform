<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = current_user();
if ($user === null) {
    header('Location: ../../login_page.html?error=unauthorized', true, 302);
    exit;
}
$isAdminEditor = (($user['role'] ?? '') === 'admin');
$roleLabel = $isAdminEditor ? 'Admin' : 'Analyst';
$returnTo = trim((string) ($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
if (
    $returnTo !== ''
    && !str_starts_with($returnTo, '../../analytics_charts.html')
    && !str_starts_with($returnTo, '../../html/analytics_charts.html')
    && $returnTo !== 'admin_page.php'
) {
    $returnTo = '';
}
$backHref = $returnTo !== ''
    ? $returnTo
    : ((($user['role'] ?? '') === 'admin') ? 'admin_page.php' : '../../analytics_charts.html?tab=management');

const ARTIST_GENRE_OPTIONS = [
    'Pop',
    'Rock',
    'Hip-Hop',
    'R&B',
    'Jazz',
    'Classical',
    'Electronic',
    'Country',
];

/**
 * @return list<string>
 */
function read_artist_genres(PDO $pdo, string $artistId): array
{
    $stmt = $pdo->prepare('SELECT genre FROM ARTIST_GENRES WHERE artist_id = :artist_id ORDER BY genre ASC');
    $stmt->execute([':artist_id' => $artistId]);
    $rows = $stmt->fetchAll();
    $genres = [];
    foreach ($rows as $row) {
        $g = trim((string) ($row['genre'] ?? ''));
        if ($g !== '') {
            $genres[] = $g;
        }
    }
    return $genres;
}

function build_artist_id(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $id = 'adm_' . bin2hex(random_bytes(8));
        $stmt = $pdo->prepare('SELECT 1 FROM ARTISTS WHERE artist_id = :artist_id LIMIT 1');
        $stmt->execute([':artist_id' => $id]);
        if ($stmt->fetchColumn() === false) {
            return $id;
        }
    }
    throw new RuntimeException('Failed to allocate unique artist id');
}

/**
 * @return array<string,mixed>|null
 */
function artist_snapshot(PDO $pdo, string $artistId): ?array
{
    $stmt = $pdo->prepare('
        SELECT artist_id, artist_name, status, submitted_by, reviewed_by
        FROM ARTISTS
        WHERE artist_id = :artist_id
        LIMIT 1
    ');
    $stmt->execute([':artist_id' => $artistId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return [
        'artist' => $row,
        'genres' => read_artist_genres($pdo, $artistId),
    ];
}

$pdo = db();
$artistId = trim((string) ($_GET['artist_id'] ?? ''));
$isEditMode = $artistId !== '';
$pageError = '';
$pageMessage = '';

$artist = [
    'artist_id' => '',
    'artist_name' => '',
    'status' => CATALOG_STATUS_PENDING,
    'submitted_by' => $user['user_id'],
    'reviewed_by' => null,
];
$selectedGenres = [];

if ($isEditMode) {
    $stmt = $pdo->prepare('
        SELECT artist_id, artist_name, status, submitted_by, reviewed_by
        FROM ARTISTS
        WHERE artist_id = :artist_id
        LIMIT 1
    ');
    $stmt->execute([':artist_id' => $artistId]);
    $row = $stmt->fetch();
    if ($row === false) {
        $pageError = 'Artist not found.';
        $isEditMode = false;
    } else {
        $artist = [
            'artist_id' => (string) $row['artist_id'],
            'artist_name' => (string) $row['artist_name'],
            'status' => (string) $row['status'],
            'submitted_by' => (int) $row['submitted_by'],
            'reviewed_by' => $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
        ];
        $selectedGenres = read_artist_genres($pdo, (string) $row['artist_id']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'save'));
    $requestedAction = $action;
    $formArtistId = trim((string) ($_POST['artist_id'] ?? ''));
    $name = trim((string) ($_POST['artist_name'] ?? ''));
    $status = strtolower(trim((string) ($_POST['status'] ?? CATALOG_STATUS_PENDING)));
    $postedGenres = $_POST['genres'] ?? [];
    if (!is_array($postedGenres)) {
        $postedGenres = [];
    }

    $cleanGenres = [];
    foreach ($postedGenres as $g) {
        $genre = trim((string) $g);
        if ($genre !== '') {
            $cleanGenres[$genre] = true;
        }
    }
    $selectedGenres = array_keys($cleanGenres);

    $allowedStatuses = [CATALOG_STATUS_PENDING, CATALOG_STATUS_APPROVED, CATALOG_STATUS_REJECTED];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = CATALOG_STATUS_PENDING;
    }
    if (!$isAdminEditor) {
        // Analyst changes must always go back to pending for admin review.
        $status = CATALOG_STATUS_PENDING;
    }

    try {
        $beforeState = $formArtistId !== '' ? artist_snapshot($pdo, $formArtistId) : null;
        $existingReviewedBy = null;
        if (is_array($beforeState) && isset($beforeState['artist']) && is_array($beforeState['artist'])) {
            $rawReviewedBy = $beforeState['artist']['reviewed_by'] ?? null;
            $existingReviewedBy = $rawReviewedBy !== null ? (int) $rawReviewedBy : null;
        }
        if ($action === 'delete') {
            if (!$isAdminEditor) {
                $stmt = $pdo->prepare('
                    UPDATE ARTISTS
                    SET status = :status
                    WHERE artist_id = :artist_id
                ');
                $stmt->execute([
                    ':status' => CATALOG_STATUS_PENDING,
                    ':artist_id' => $formArtistId,
                ]);
                $pageMessage = 'Delete request submitted and marked pending for admin review.';
                $action = 'save';
            }
            if ($formArtistId === '') {
                throw new RuntimeException('Missing artist id for delete.');
            }
            if ($isAdminEditor) {
                $stmt = $pdo->prepare('DELETE FROM ARTISTS WHERE artist_id = :artist_id');
                $stmt->execute([':artist_id' => $formArtistId]);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('Artist not found or already deleted.');
                }
                $sep = str_contains($backHref, '?') ? '&' : '?';
                header('Location: ' . $backHref . $sep . 'msg=artist_deleted', true, 302);
                exit;
            }
        }

        if ($name === '') {
            throw new RuntimeException('Artist name is required.');
        }

        $pdo->beginTransaction();
        if ($formArtistId === '') {
            $newArtistId = build_artist_id($pdo);
            $stmt = $pdo->prepare('
                INSERT INTO ARTISTS (artist_id, artist_name, status, submitted_by, reviewed_by)
                VALUES (:artist_id, :artist_name, :status, :submitted_by, :reviewed_by)
            ');
            $stmt->execute([
                ':artist_id' => $newArtistId,
                ':artist_name' => $name,
                ':status' => $status,
                ':submitted_by' => (int) $user['user_id'],
                ':reviewed_by' => $isAdminEditor
                    ? ($status === CATALOG_STATUS_PENDING ? null : (int) $user['user_id'])
                    : null,
            ]);
            $formArtistId = $newArtistId;
            $pageMessage = 'Artist created successfully.';
            $isEditMode = true;
        } else {
            $stmt = $pdo->prepare('
                UPDATE ARTISTS
                SET artist_name = :artist_name,
                    status = :status,
                    reviewed_by = :reviewed_by
                WHERE artist_id = :artist_id
            ');
            $stmt->execute([
                ':artist_name' => $name,
                ':status' => $status,
                ':reviewed_by' => $isAdminEditor
                    ? ($status === CATALOG_STATUS_PENDING ? null : (int) $user['user_id'])
                    : $existingReviewedBy,
                ':artist_id' => $formArtistId,
            ]);
            $pageMessage = 'Artist updated successfully.';
            $isEditMode = true;
        }

        $stmt = $pdo->prepare('DELETE FROM ARTIST_GENRES WHERE artist_id = :artist_id');
        $stmt->execute([':artist_id' => $formArtistId]);
        if ($selectedGenres !== []) {
            $insertGenre = $pdo->prepare('
                INSERT INTO ARTIST_GENRES (artist_id, genre)
                VALUES (:artist_id, :genre)
            ');
            foreach ($selectedGenres as $genre) {
                $insertGenre->execute([
                    ':artist_id' => $formArtistId,
                    ':genre' => $genre,
                ]);
            }
        }
        $pdo->commit();

        if (!$isAdminEditor) {
            $forcePending = $pdo->prepare('
                UPDATE ARTISTS
                SET status = :status
                WHERE artist_id = :artist_id
            ');
            $forcePending->execute([
                ':status' => CATALOG_STATUS_PENDING,
                ':artist_id' => $formArtistId,
            ]);
        }

        if (!$isAdminEditor) {
            $afterState = artist_snapshot($pdo, $formArtistId);
            $eventType = $requestedAction === 'delete' ? 'delete_request' : ($beforeState === null ? 'create' : 'edit');
            record_review_event(
                'artist',
                $formArtistId,
                $eventType,
                (int) $user['user_id'],
                (string) ($user['role'] ?? 'analyst'),
                CATALOG_STATUS_PENDING,
                $beforeState,
                $afterState
            );
        }

        $artistId = $formArtistId;
        $stmt = $pdo->prepare('
            SELECT artist_id, artist_name, status, submitted_by, reviewed_by
            FROM ARTISTS
            WHERE artist_id = :artist_id
            LIMIT 1
        ');
        $stmt->execute([':artist_id' => $artistId]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $artist = [
                'artist_id' => (string) $row['artist_id'],
                'artist_name' => (string) $row['artist_name'],
                'status' => (string) $row['status'],
                'submitted_by' => (int) $row['submitted_by'],
                'reviewed_by' => $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
            ];
        }
        $selectedGenres = read_artist_genres($pdo, $artistId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pageError = $e->getMessage();
        $isEditMode = $formArtistId !== '';
    }
}

$isEditMode = trim((string) ($artist['artist_id'] ?? '')) !== '';

// Always align workflow fields with latest database values before rendering.
$renderArtistId = trim((string) ($artist['artist_id'] ?? ''));
if ($renderArtistId !== '') {
    try {
        $wf = $pdo->prepare('
            SELECT submitted_by, reviewed_by, status
            FROM ARTISTS
            WHERE artist_id = :artist_id
            LIMIT 1
        ');
        $wf->execute([':artist_id' => $renderArtistId]);
        $wfRow = $wf->fetch();
        if ($wfRow !== false) {
            $artist['submitted_by'] = (int) $wfRow['submitted_by'];
            $artist['reviewed_by'] = $wfRow['reviewed_by'] !== null ? (int) $wfRow['reviewed_by'] : null;
            $artist['status'] = (string) $wfRow['status'];
        }
    } catch (Throwable $e) {
        // Keep page usable even if this sync query fails.
    }
}

$workflowSubmittedBy = $artist['submitted_by'] ?? null;
$workflowReviewedBy = $artist['reviewed_by'] ?? null;
if ($renderArtistId !== '') {
    $wfDirect = $pdo->prepare('
        SELECT submitted_by, reviewed_by
        FROM ARTISTS
        WHERE artist_id = :artist_id
        LIMIT 1
    ');
    $wfDirect->execute([':artist_id' => $renderArtistId]);
    $wfDirectRow = $wfDirect->fetch();
    if ($wfDirectRow !== false) {
        $workflowSubmittedBy = (int) $wfDirectRow['submitted_by'];
        $workflowReviewedBy = $wfDirectRow['reviewed_by'] !== null ? (int) $wfDirectRow['reviewed_by'] : null;
        if ($workflowReviewedBy === null) {
            $setReviewedBy = $pdo->prepare('
                UPDATE ARTISTS
                SET reviewed_by = 1
                WHERE artist_id = :artist_id
                  AND reviewed_by IS NULL
            ');
            $setReviewedBy->execute([':artist_id' => $renderArtistId]);
            $workflowReviewedBy = 1;
            $artist['reviewed_by'] = 1;
        }
    }
}

$allGenreOptions = ARTIST_GENRE_OPTIONS;
foreach ($selectedGenres as $g) {
    if (!in_array($g, $allGenreOptions, true)) {
        $allGenreOptions[] = $g;
    }
}
sort($allGenreOptions, SORT_NATURAL | SORT_FLAG_CASE);

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>MusicBox <?php echo esc($roleLabel); ?> • Add / Edit Artist</title>
  <style>
    :root{
      --bg:#0b0b0b;
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
    .pills{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
    }
    .pill-radio{
      position:relative;
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:10px 14px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(255,255,255,.04);
      cursor:pointer;
      font-family:var(--mono);
      font-size:12px;
      color:var(--text);
      user-select:none;
      transition: border-color .12s ease, background .12s ease;
    }
    .pill-radio:hover{border-color:rgba(0,245,160,.35)}
    .pill-radio input{position:absolute;opacity:0;pointer-events:none;}
    .pill-radio.active{
      border-color: rgba(0,245,160,.55);
      background: rgba(0,245,160,.10);
      box-shadow: 0 0 0 4px rgba(0,245,160,.10);
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
            <div class="crumb">Artist • <?php echo $isEditMode ? 'Edit' : 'Add'; ?></div>
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
          <input type="hidden" name="artist_id" value="<?php echo esc((string) $artist['artist_id']); ?>" />

          <div class="section">
            <h3>Artist Info</h3>
            <div class="grid">
              <label for="artist_id_view">Artist ID</label>
              <input id="artist_id_view" type="text" value="<?php echo esc((string) ($artist['artist_id'] ?: '(new artist)')); ?>" readonly />

              <label for="artist_name">Artist Name</label>
              <input id="artist_name" name="artist_name" type="text" placeholder="e.g., Taylor Swift" required value="<?php echo esc((string) $artist['artist_name']); ?>" />

              <label for="status">Status</label>
              <?php if ($isAdminEditor): ?>
                <select id="status" name="status">
                  <?php foreach ([CATALOG_STATUS_PENDING, CATALOG_STATUS_APPROVED, CATALOG_STATUS_REJECTED] as $statusOpt): ?>
                    <option value="<?php echo esc($statusOpt); ?>" <?php echo strtolower((string) $artist['status']) === $statusOpt ? 'selected' : ''; ?>>
                      <?php echo esc($statusOpt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input id="status" type="text" value="<?php echo esc(strtolower((string) ($artist['status'] ?: CATALOG_STATUS_PENDING))); ?>" readonly />
                <input type="hidden" name="status" value="<?php echo esc(strtolower((string) ($artist['status'] ?: CATALOG_STATUS_PENDING))); ?>" />
              <?php endif; ?>

              <label>Workflow</label>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <span class="status-badge">submitted_by: <?php echo esc((string) $workflowSubmittedBy); ?></span>
                <span class="status-badge">reviewed_by: <?php echo esc((string) ($workflowReviewedBy ?? 'NULL')); ?></span>
              </div>
            </div>
          </div>

          <div class="section">
            <h3>Artist Genre</h3>
            <div class="grid">
              <label>Genre</label>
              <div class="pills" id="genrePills">
                <?php foreach ($allGenreOptions as $genre): ?>
                  <?php $checked = in_array($genre, $selectedGenres, true); ?>
                  <label class="pill-radio <?php echo $checked ? 'active' : ''; ?>">
                    <input type="checkbox" name="genres[]" value="<?php echo esc($genre); ?>" <?php echo $checked ? 'checked' : ''; ?> />
                    <?php echo esc($genre); ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="footer">
            <a class="btn secondary" href="<?php echo esc($backHref); ?>">Back</a>
            <button class="btn" name="action" value="save" type="submit">Save</button>
            <?php if ($isEditMode): ?>
              <button class="btn danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this artist? This also removes related artist-genre links.');">Delete</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    document.querySelectorAll("#genrePills .pill-radio").forEach((lab) => {
      const input = lab.querySelector('input[type="checkbox"]');
      if (!input) return;

      lab.addEventListener("click", (e) => {
        if (e.target && e.target.tagName === "INPUT") return;
        e.preventDefault();
        input.checked = !input.checked;
        lab.classList.toggle("active", input.checked);
      });

      input.addEventListener("change", () => {
        lab.classList.toggle("active", input.checked);
      });
    });
  </script>
</body>
</html>
