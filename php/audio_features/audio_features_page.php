<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = current_user();
if ($user === null) {
    header('Location: ../../html/login_page.html?error=unauthorized', true, 302);
    exit;
}

$isAdmin = (($user['role'] ?? '') === 'admin');
$message = '';
$error = '';

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parse_nullable_float(string $raw, string $label): ?float
{
    $v = trim($raw);
    if ($v === '') {
        return null;
    }
    if (!is_numeric($v)) {
        throw new RuntimeException($label . ' must be numeric.');
    }
    return (float) $v;
}

function parse_nullable_int(string $raw, string $label): ?int
{
    $v = trim($raw);
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^-?\d+$/', $v)) {
        throw new RuntimeException($label . ' must be an integer.');
    }
    return (int) $v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!$isAdmin) {
        $error = 'Analyst accounts can only search/view AUDIO_FEATURES.';
    } else {
        try {
            $trackId = trim((string) ($_POST['track_id'] ?? ''));
            if ($trackId === '') {
                throw new RuntimeException('track_id is required.');
            }

            if ($action === 'delete') {
                $stmt = db()->prepare('DELETE FROM AUDIO_FEATURES WHERE track_id = :track_id');
                $stmt->execute([':track_id' => $trackId]);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('AUDIO_FEATURES row not found for that track_id.');
                }
                $message = 'Audio features deleted successfully.';
            } elseif ($action === 'save') {
                $payload = [
                    ':track_id' => $trackId,
                    ':danceability' => parse_nullable_float((string) ($_POST['danceability'] ?? ''), 'danceability'),
                    ':energy' => parse_nullable_float((string) ($_POST['energy'] ?? ''), 'energy'),
                    ':valence' => parse_nullable_float((string) ($_POST['valence'] ?? ''), 'valence'),
                    ':acousticness' => parse_nullable_float((string) ($_POST['acousticness'] ?? ''), 'acousticness'),
                    ':instrumentalness' => parse_nullable_float((string) ($_POST['instrumentalness'] ?? ''), 'instrumentalness'),
                    ':liveness' => parse_nullable_float((string) ($_POST['liveness'] ?? ''), 'liveness'),
                    ':speechiness' => parse_nullable_float((string) ($_POST['speechiness'] ?? ''), 'speechiness'),
                    ':tempo' => parse_nullable_float((string) ($_POST['tempo'] ?? ''), 'tempo'),
                    ':key_col' => parse_nullable_int((string) ($_POST['key_col'] ?? ''), 'key'),
                    ':mode' => parse_nullable_int((string) ($_POST['mode'] ?? ''), 'mode'),
                    ':loudness' => parse_nullable_float((string) ($_POST['loudness'] ?? ''), 'loudness'),
                    ':time_signature' => parse_nullable_int((string) ($_POST['time_signature'] ?? ''), 'time_signature'),
                ];

                $exists = db()->prepare('SELECT 1 FROM AUDIO_FEATURES WHERE track_id = :track_id LIMIT 1');
                $exists->execute([':track_id' => $trackId]);
                if ($exists->fetchColumn() === false) {
                    $sql = '
                        INSERT INTO AUDIO_FEATURES
                        (track_id, danceability, energy, valence, acousticness, instrumentalness, liveness, speechiness, tempo, `key`, mode, loudness, time_signature)
                        VALUES
                        (:track_id, :danceability, :energy, :valence, :acousticness, :instrumentalness, :liveness, :speechiness, :tempo, :key_col, :mode, :loudness, :time_signature)
                    ';
                    $stmt = db()->prepare($sql);
                    $stmt->execute($payload);
                    $message = 'Audio features inserted successfully.';
                } else {
                    $sql = '
                        UPDATE AUDIO_FEATURES
                        SET danceability = :danceability,
                            energy = :energy,
                            valence = :valence,
                            acousticness = :acousticness,
                            instrumentalness = :instrumentalness,
                            liveness = :liveness,
                            speechiness = :speechiness,
                            tempo = :tempo,
                            `key` = :key_col,
                            mode = :mode,
                            loudness = :loudness,
                            time_signature = :time_signature
                        WHERE track_id = :track_id
                    ';
                    $stmt = db()->prepare($sql);
                    $stmt->execute($payload);
                    $message = 'Audio features updated successfully.';
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$rows = [];
try {
    if ($q === '') {
        $stmt = db()->query('
            SELECT track_id, danceability, energy, valence, acousticness, instrumentalness, liveness, speechiness, tempo, `key`, mode, loudness, time_signature
            FROM AUDIO_FEATURES
            ORDER BY track_id ASC
            LIMIT 200
        ');
    } else {
        $stmt = db()->prepare('
            SELECT track_id, danceability, energy, valence, acousticness, instrumentalness, liveness, speechiness, tempo, `key`, mode, loudness, time_signature
            FROM AUDIO_FEATURES
            WHERE track_id LIKE :q
            ORDER BY track_id ASC
            LIMIT 200
        ');
        $stmt->execute([':q' => '%' . $q . '%']);
    }
    $rows = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $error = $error !== '' ? $error : 'Failed to load AUDIO_FEATURES.';
}

$backHref = $isAdmin ? '../admin/admin_page.php' : '../../html/analytics_charts.html';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>MusicBox • AUDIO_FEATURES</title>
  <style>
    :root{
      --bg:#0b0b0b; --text:#eaeaea; --muted:#a7a7a7; --border:#2b2b2b;
      --accent:#00f5a0; --danger:#ff6b6b; --shadow:0 12px 34px rgba(0,0,0,.45);
      --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
      --sans: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
    }
    *{box-sizing:border-box} body{margin:0;background:radial-gradient(1200px 600px at 50% -200px, rgba(0,245,160,.22), transparent 60%),var(--bg);color:var(--text);font-family:var(--sans);}
    .wrap{max-width:1320px;margin:22px auto;padding:0 18px 50px}
    .shell{border:1px solid var(--border);border-radius:22px;background:rgba(18,18,18,.78);box-shadow:var(--shadow);overflow:hidden}
    .top{padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;justify-content:space-between;align-items:center;gap:12px}
    .name{font-weight:900}.sub{font-family:var(--mono);font-size:12px;color:var(--muted)}
    .row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
    .card{padding:16px;border:1px solid rgba(255,255,255,.08);border-radius:18px;background:rgba(0,0,0,.18);margin-top:14px}
    .input,.select,.btn{width:100%;border-radius:14px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--text);padding:10px 12px;font-family:var(--mono);font-size:12px}
    .btn{border-color:rgba(0,245,160,.45);background:rgba(0,245,160,.14);color:var(--accent);cursor:pointer}
    .btn.secondary{border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.06);color:var(--text)}
    .btn.danger{border-color:rgba(255,90,90,.45);background:rgba(255,90,90,.10);color:var(--danger)}
    .msg{margin-top:12px;padding:10px 12px;border-radius:12px;font-family:var(--mono);font-size:12px}
    .ok{border:1px solid rgba(0,245,160,.45);background:rgba(0,245,160,.10);color:var(--accent)}
    .err{border:1px solid rgba(255,90,90,.45);background:rgba(255,90,90,.10);color:var(--danger)}
    table{width:100%;border-collapse:collapse;margin-top:12px;font-family:var(--mono);font-size:12px}
    th,td{padding:8px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:top}
    .actions{display:flex;gap:8px;align-items:center}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="shell">
      <div class="top">
        <div>
          <div class="name">MusicBox • AUDIO_FEATURES</div>
          <div class="sub">Role: <?php echo esc((string) ($user['role'] ?? '')); ?><?php echo $isAdmin ? ' (view/search/insert/update/delete)' : ' (view/search only)'; ?></div>
        </div>
        <a class="btn secondary" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;max-width:220px" href="<?php echo esc($backHref); ?>">Back</a>
      </div>

      <div style="padding:16px;">
        <?php if ($message !== ''): ?><div class="msg ok"><?php echo esc($message); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="msg err"><?php echo esc($error); ?></div><?php endif; ?>

        <form method="get" class="card">
          <div class="sub" style="margin-bottom:8px;">Search / View AUDIO_FEATURES by Track ID</div>
          <div class="actions">
            <input class="input" name="q" value="<?php echo esc($q); ?>" placeholder="track_id contains..." />
            <button class="btn" type="submit" style="max-width:140px;">Search</button>
            <a class="btn secondary" href="audio_features_page.php" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;max-width:120px;">Clear</a>
          </div>
          <table>
            <thead>
              <tr>
                <th>track_id</th><th>danceability</th><th>energy</th><th>valence</th><th>acousticness</th><th>instrumentalness</th>
                <th>liveness</th><th>speechiness</th><th>tempo</th><th>key</th><th>mode</th><th>loudness</th><th>time_signature</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo esc((string) ($r['track_id'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['danceability'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['energy'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['valence'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['acousticness'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['instrumentalness'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['liveness'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['speechiness'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['tempo'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['key'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['mode'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['loudness'] ?? '')); ?></td>
                  <td><?php echo esc((string) ($r['time_signature'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($rows === []): ?>
                <tr><td colspan="13">No matching rows.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </form>

        <?php if ($isAdmin): ?>
          <form method="post" class="card">
            <div class="sub" style="margin-bottom:8px;">Insert / Update / Delete AUDIO_FEATURES (admin only)</div>
            <div class="row">
              <input class="input" name="track_id" placeholder="track_id (required)" required />
              <input class="input" name="danceability" placeholder="danceability" />
              <input class="input" name="energy" placeholder="energy" />
              <input class="input" name="valence" placeholder="valence" />
              <input class="input" name="acousticness" placeholder="acousticness" />
              <input class="input" name="instrumentalness" placeholder="instrumentalness" />
              <input class="input" name="liveness" placeholder="liveness" />
              <input class="input" name="speechiness" placeholder="speechiness" />
              <input class="input" name="tempo" placeholder="tempo" />
              <input class="input" name="key_col" placeholder="key" />
              <input class="input" name="mode" placeholder="mode" />
              <input class="input" name="loudness" placeholder="loudness" />
              <input class="input" name="time_signature" placeholder="time_signature" />
            </div>
            <div class="actions" style="margin-top:10px;">
              <button class="btn" type="submit" name="action" value="save" style="max-width:180px;">Save (Insert/Update)</button>
              <button class="btn danger" type="submit" name="action" value="delete" style="max-width:140px;">Delete</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
