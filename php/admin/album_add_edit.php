<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$user = current_user();
if ($user === null) {
    header('Location: ../../html/login_page.html?error=unauthorized', true, 302);
    exit;
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function build_album_id(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $id = 'adm_alb_' . bin2hex(random_bytes(6));
        $stmt = $pdo->prepare('SELECT 1 FROM ALBUMS WHERE album_id = :album_id LIMIT 1');
        $stmt->execute([':album_id' => $id]);
        if ($stmt->fetchColumn() === false) {
            return $id;
        }
    }
    throw new RuntimeException('Failed to allocate unique album id');
}

function normalize_release_date(string $value): ?string
{
    $v = trim($value);
    if ($v === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if ($dt === false || $dt->format('Y-m-d') !== $v) {
        throw new RuntimeException('Release date must be YYYY-MM-DD.');
    }
    return $v;
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
    if ($row === false) {
        return null;
    }
    return (string) $row['artist_id'];
}

$pdo = db();
$albumId = trim((string) ($_GET['album_id'] ?? ''));
$isEditMode = $albumId !== '';
$pageError = '';
$pageMessage = '';

$album = [
    'album_id' => '',
    'album_name' => '',
    'release_date' => '',
    'album_image_url' => '',
    'status' => CATALOG_STATUS_PENDING,
    'submitted_by' => $user['user_id'],
    'reviewed_by' => null,
];
$mainArtistName = '';

if ($isEditMode) {
    $stmt = $pdo->prepare('
        SELECT
            al.album_id,
            al.album_name,
            al.release_date,
            al.album_image_url,
            al.status,
            al.submitted_by,
            al.reviewed_by,
            (
                SELECT ar.artist_name
                FROM ALBUM_ARTISTS aa2
                INNER JOIN ARTISTS ar ON ar.artist_id = aa2.artist_id
                WHERE aa2.album_id = al.album_id
                ORDER BY ar.artist_name ASC
                LIMIT 1
            ) AS main_artist_name
        FROM ALBUMS al
        WHERE al.album_id = :album_id
        LIMIT 1
    ');
    $stmt->execute([':album_id' => $albumId]);
    $row = $stmt->fetch();
    if ($row === false) {
        $pageError = 'Album not found.';
        $isEditMode = false;
    } else {
        $album = [
            'album_id' => (string) $row['album_id'],
            'album_name' => (string) $row['album_name'],
            'release_date' => $row['release_date'] !== null ? (string) $row['release_date'] : '',
            'album_image_url' => $row['album_image_url'] !== null ? (string) $row['album_image_url'] : '',
            'status' => (string) $row['status'],
            'submitted_by' => (int) $row['submitted_by'],
            'reviewed_by' => $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
        ];
        $mainArtistName = $row['main_artist_name'] !== null ? (string) $row['main_artist_name'] : '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'save'));
    $formAlbumId = trim((string) ($_POST['album_id'] ?? ''));
    $albumName = trim((string) ($_POST['album_name'] ?? ''));
    $releaseDateRaw = trim((string) ($_POST['release_date'] ?? ''));
    $albumImageUrlRaw = trim((string) ($_POST['album_image_url'] ?? ''));
    $status = strtolower(trim((string) ($_POST['status'] ?? CATALOG_STATUS_PENDING)));
    $mainArtistNameInput = trim((string) ($_POST['main_artist'] ?? ''));

    $mainArtistName = $mainArtistNameInput;
    $allowedStatuses = [CATALOG_STATUS_PENDING, CATALOG_STATUS_APPROVED, CATALOG_STATUS_REJECTED];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = CATALOG_STATUS_PENDING;
    }

    try {
        if ($action === 'delete') {
            if ($formAlbumId === '') {
                throw new RuntimeException('Missing album id for delete.');
            }
            $stmt = $pdo->prepare('DELETE FROM ALBUMS WHERE album_id = :album_id');
            $stmt->execute([':album_id' => $formAlbumId]);
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Album not found or already deleted.');
            }
            header('Location: admin_page.php?msg=album_deleted', true, 302);
            exit;
        }

        if ($albumName === '') {
            throw new RuntimeException('Album name is required.');
        }

        $releaseDate = normalize_release_date($releaseDateRaw);
        $albumImageUrl = $albumImageUrlRaw === '' ? null : $albumImageUrlRaw;

        $mainArtistId = lookup_artist_id_by_name($pdo, $mainArtistNameInput);
        if ($mainArtistNameInput !== '' && $mainArtistId === null) {
            throw new RuntimeException('Main artist not found. Use an existing artist name exactly.');
        }

        $pdo->beginTransaction();
        if ($formAlbumId === '') {
            $newAlbumId = build_album_id($pdo);
            $stmt = $pdo->prepare('
                INSERT INTO ALBUMS (album_id, album_name, release_date, album_image_url, status, submitted_by, reviewed_by)
                VALUES (:album_id, :album_name, :release_date, :album_image_url, :status, :submitted_by, :reviewed_by)
            ');
            $stmt->execute([
                ':album_id' => $newAlbumId,
                ':album_name' => $albumName,
                ':release_date' => $releaseDate,
                ':album_image_url' => $albumImageUrl,
                ':status' => $status,
                ':submitted_by' => (int) $user['user_id'],
                ':reviewed_by' => $status === CATALOG_STATUS_PENDING ? null : (int) $user['user_id'],
            ]);
            $formAlbumId = $newAlbumId;
            $pageMessage = 'Album created successfully.';
            $isEditMode = true;
        } else {
            $stmt = $pdo->prepare('
                UPDATE ALBUMS
                SET album_name = :album_name,
                    release_date = :release_date,
                    album_image_url = :album_image_url,
                    status = :status,
                    reviewed_by = :reviewed_by
                WHERE album_id = :album_id
            ');
            $stmt->execute([
                ':album_name' => $albumName,
                ':release_date' => $releaseDate,
                ':album_image_url' => $albumImageUrl,
                ':status' => $status,
                ':reviewed_by' => $status === CATALOG_STATUS_PENDING ? null : (int) $user['user_id'],
                ':album_id' => $formAlbumId,
            ]);
            $pageMessage = 'Album updated successfully.';
            $isEditMode = true;
        }

        $stmt = $pdo->prepare('DELETE FROM ALBUM_ARTISTS WHERE album_id = :album_id');
        $stmt->execute([':album_id' => $formAlbumId]);
        if ($mainArtistId !== null) {
            $stmt = $pdo->prepare('
                INSERT INTO ALBUM_ARTISTS (album_id, artist_id)
                VALUES (:album_id, :artist_id)
            ');
            $stmt->execute([
                ':album_id' => $formAlbumId,
                ':artist_id' => $mainArtistId,
            ]);
        }

        $pdo->commit();

        $albumId = $formAlbumId;
        $stmt = $pdo->prepare('
            SELECT album_id, album_name, release_date, album_image_url, status, submitted_by, reviewed_by
            FROM ALBUMS
            WHERE album_id = :album_id
            LIMIT 1
        ');
        $stmt->execute([':album_id' => $albumId]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $album = [
                'album_id' => (string) $row['album_id'],
                'album_name' => (string) $row['album_name'],
                'release_date' => $row['release_date'] !== null ? (string) $row['release_date'] : '',
                'album_image_url' => $row['album_image_url'] !== null ? (string) $row['album_image_url'] : '',
                'status' => (string) $row['status'],
                'submitted_by' => (int) $row['submitted_by'],
                'reviewed_by' => $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
            ];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pageError = $e->getMessage();
        $isEditMode = $formAlbumId !== '';
        $album = [
            'album_id' => $formAlbumId,
            'album_name' => $albumName,
            'release_date' => $releaseDateRaw,
            'album_image_url' => $albumImageUrlRaw,
            'status' => $status,
            'submitted_by' => $album['submitted_by'],
            'reviewed_by' => $album['reviewed_by'],
        ];
    }
}

$isEditMode = trim((string) ($album['album_id'] ?? '')) !== '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>MusicBox Admin • Add / Edit Album</title>
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
    .wrap{max-width:1120px;margin:22px auto;padding:0 18px 60px;}
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
    input:focus, select:focus{
      border-color: rgba(0,245,160,.5);
      box-shadow: 0 0 0 4px rgba(0,245,160,.12);
    }
    input::placeholder{color:#7f7f7f}
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
            <div class="name">MusicBox <span style="color:var(--muted);font-weight:900">Admin</span></div>
            <div class="crumb">Album • <?php echo $isEditMode ? 'Edit' : 'Add'; ?></div>
          </div>
        </div>
      </div>

      <a class="linkback" href="admin_page.php">
        <span class="pill">←</span>
        <span>Back to admin page</span>
      </a>

      <div class="content">
        <?php if ($pageMessage !== ''): ?>
          <div class="msg ok"><?php echo esc($pageMessage); ?></div>
        <?php endif; ?>
        <?php if ($pageError !== ''): ?>
          <div class="msg err"><?php echo esc($pageError); ?></div>
        <?php endif; ?>

        <form method="post" action="">
          <input type="hidden" name="album_id" value="<?php echo esc((string) $album['album_id']); ?>" />

          <div class="section">
            <h3>Album Info</h3>
            <div class="grid">
              <label for="album_id_view">Album ID</label>
              <input id="album_id_view" type="text" value="<?php echo esc((string) ($album['album_id'] ?: '(new album)')); ?>" readonly />

              <label for="album_name">Album Name</label>
              <input id="album_name" name="album_name" type="text" placeholder="e.g., Midnights" required value="<?php echo esc((string) $album['album_name']); ?>" />

              <label for="release_date">Release Date</label>
              <input id="release_date" name="release_date" type="date" value="<?php echo esc((string) $album['release_date']); ?>" />

              <label for="album_image_url">Album Cover URL</label>
              <input id="album_image_url" name="album_image_url" type="url" placeholder="https://..." value="<?php echo esc((string) $album['album_image_url']); ?>" />

              <label for="status">Status</label>
              <select id="status" name="status">
                <?php foreach ([CATALOG_STATUS_PENDING, CATALOG_STATUS_APPROVED, CATALOG_STATUS_REJECTED] as $statusOpt): ?>
                  <option value="<?php echo esc($statusOpt); ?>" <?php echo strtolower((string) $album['status']) === $statusOpt ? 'selected' : ''; ?>>
                    <?php echo esc($statusOpt); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <label>Workflow</label>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <span class="status-badge">submitted_by: <?php echo esc((string) $album['submitted_by']); ?></span>
                <span class="status-badge">reviewed_by: <?php echo esc((string) ($album['reviewed_by'] ?? 'NULL')); ?></span>
              </div>
            </div>
          </div>

          <div class="section">
            <h3>Relations</h3>
            <div class="grid">
              <label for="main_artist">Main Artist</label>
              <input id="main_artist" name="main_artist" type="text" placeholder="e.g., Taylor Swift" value="<?php echo esc($mainArtistName); ?>" />
            </div>
          </div>

          <div class="footer">
            <a class="btn secondary" href="admin_page.php">Back</a>
            <button class="btn" name="action" value="save" type="submit">Save</button>
            <?php if ($isEditMode): ?>
              <button class="btn danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this album? Related album-artist and album-track links will also be removed.');">Delete</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
