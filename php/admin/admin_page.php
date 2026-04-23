<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$user = current_user();
$emailDisplay = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$userMessage = '';
$userError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['form_action'] ?? '') === 'save_user') {
    $postedUserId = trim((string) ($_POST['user_id'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $role = strtolower(trim((string) ($_POST['role'] ?? 'analyst')));
    $isActiveRaw = trim((string) ($_POST['is_active'] ?? '1'));
    $isActive = $isActiveRaw === '0' ? 0 : 1;
    $allowedRoles = ['admin', 'analyst'];

    try {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please provide a valid email.');
        }
        if (!in_array($role, $allowedRoles, true)) {
            throw new RuntimeException('Role must be admin or analyst.');
        }

        $pdo = db();
        if ($postedUserId === '') {
            $nextIdStmt = $pdo->query('SELECT COALESCE(MAX(user_id), 0) + 1 AS next_id FROM USERS');
            $nextId = (int) ($nextIdStmt->fetch()['next_id'] ?? 1);
            $insert = $pdo->prepare('
                INSERT INTO USERS (user_id, email, password_hash, role, is_active)
                VALUES (:user_id, :email, :password_hash, :role, :is_active)
            ');
            $insert->execute([
                ':user_id' => $nextId,
                ':email' => $email,
                ':password_hash' => 'temp_password_change_me',
                ':role' => $role,
                ':is_active' => $isActive,
            ]);
            $userMessage = 'User created successfully.';
        } else {
            $userId = (int) $postedUserId;
            if ($userId < 1) {
                throw new RuntimeException('Invalid user id.');
            }
            $update = $pdo->prepare('
                UPDATE USERS
                SET email = :email,
                    role = :role,
                    is_active = :is_active
                WHERE user_id = :user_id
            ');
            $update->execute([
                ':email' => $email,
                ':role' => $role,
                ':is_active' => $isActive,
                ':user_id' => $userId,
            ]);
            $affected = $update->rowCount();
            if ($affected < 1) {
                $exists = $pdo->prepare('SELECT 1 FROM USERS WHERE user_id = :user_id LIMIT 1');
                $exists->execute([':user_id' => $userId]);
                if ($exists->fetchColumn() === false) {
                    throw new RuntimeException('User record not found.');
                }
                // MySQL reports 0 "changed" rows when new values equal old values — not an error.
                $userMessage = 'No database columns were changed (values may already match what you saved).';
            } else {
                $userMessage = 'User updated successfully (' . $affected . ' row).';
            }
        }
    } catch (Throwable $e) {
        $userError = $e->getMessage();
    }
}

$usersRows = [];
try {
    $stmt = db()->query('SELECT user_id, email, role, is_active FROM USERS ORDER BY user_id ASC');
    $usersRows = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $userError = $userError !== '' ? $userError : 'Failed to load users.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MusicBox — Admin</title>
  <style>
    :root{
      --bg:#070707;
      --panel:rgba(18,18,18,.86);
      --panelSolid:#101010;
      --stroke:rgba(255,255,255,.10);
      --stroke2:rgba(255,255,255,.08);
      --text:#f1f1f1;
      --muted:rgba(241,241,241,.72);
      --muted2:rgba(241,241,241,.48);
      --accent:#18f3a3;
      --accent2:#0bd48c;
      --danger:#ff5e5e;
      --shadow:0 18px 60px rgba(0,0,0,.55);
      --radius:26px;
      --radiusSm:18px;
      --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      --sans: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      background:
        radial-gradient(1000px 360px at 20% 0%, rgba(24,243,163,.18), transparent 55%),
        radial-gradient(800px 300px at 90% 10%, rgba(24,243,163,.10), transparent 60%),
        radial-gradient(900px 500px at 50% 120%, rgba(255,255,255,.05), transparent 60%),
        var(--bg);
      color:var(--text);
      font-family:var(--sans);
    }

    .wrap{ max-width:1400px; margin:22px auto 40px; padding:0 18px; }

    .topbar{
      display:flex;
      align-items:center;
      gap:16px;
      padding:18px 20px;
      border-radius:999px;
      background:linear-gradient(180deg, rgba(30,30,30,.70), rgba(18,18,18,.55));
      border:1px solid var(--stroke);
      box-shadow:var(--shadow);
      backdrop-filter: blur(10px);
    }

    .logo{
      display:flex;
      align-items:center;
      gap:10px;
      min-width:220px;
      font-weight:800;
      color:inherit;
      text-decoration:none;
      cursor:pointer;
    }

    .logo-badge{
      width:34px;height:34px;border-radius:10px;
      background:linear-gradient(135deg,var(--accent),rgba(24,243,163,.25));
      display:grid;place-items:center;color:#000;font-weight:900;
    }

    .brand{
      display:flex;
      flex-direction:column;
      line-height:1.05;
    }
    .brand .name{ font-weight:800; letter-spacing:.2px; font-size:20px; }
    .brand .sub{ font-family:var(--mono); color:var(--muted2); font-size:12px; }

    .spacer{ flex:1; }

    .pill{
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:10px 14px;
      border-radius:999px;
      border:1px solid var(--stroke);
      background:rgba(17,17,17,.55);
      color:var(--muted);
      font-family:var(--mono);
      letter-spacing:.08em;
      text-transform:uppercase;
      font-size:12px;
    }
    .pill b{ color:var(--text); letter-spacing:0; text-transform:none; font-family:var(--sans); font-size:13px; }

    .logout{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 14px;
      border-radius:999px;
      border:1px solid var(--stroke);
      background:rgba(255,255,255,.04);
      color:var(--text);
      font-family:var(--mono);
      font-size:12px;
      letter-spacing:.08em;
      text-transform:uppercase;
      text-decoration:none;
      cursor:pointer;
    }
    .logout:hover{ background:rgba(255,255,255,.07); }

    .createBtn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 14px;
      border-radius:999px;
      border:1px solid rgba(24,243,163,.40);
      background:rgba(24,243,163,.10);
      color:var(--accent);
      font-family:var(--mono);
      font-size:12px;
      letter-spacing:.03em;
      cursor:pointer;
      user-select:none;
      white-space:nowrap;
    }
    .createBtn:hover{ background:rgba(24,243,163,.14); }

    .section{
      margin-top:18px;
      border-radius:var(--radius);
      border:1px solid var(--stroke);
      background:linear-gradient(180deg, rgba(22,22,22,.75), rgba(14,14,14,.60));
      box-shadow:var(--shadow);
      overflow:hidden;
      backdrop-filter: blur(10px);
    }

    .sectionHeader{
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:18px 22px;
      border-bottom:1px solid rgba(255,255,255,.06);
    }

    .titleWrap{ display:flex; flex-direction:column; gap:3px; }
    .title{
      font-size:26px;
      font-weight:900;
      letter-spacing:.08em;
      text-transform:uppercase;
      font-family:var(--sans);
    }

    .cardBody{ padding:18px 18px 22px; }

    .gridHeader, .row{
      display:grid;
      grid-template-columns: minmax(220px, 1.4fr) minmax(160px, 220px) minmax(140px, 200px) minmax(120px, auto);
      gap:14px;
      align-items:center;
      font-family:var(--mono);
    }
    .userAction{
      display:flex;
      justify-content:flex-end;
      align-items:center;
      gap:8px;
      flex-wrap:wrap;
    }

    .gridHeader{
      padding:12px 14px;
      border-radius:18px;
      background:rgba(255,255,255,.03);
      border:1px solid rgba(255,255,255,.06);
      font-size:12px;
      letter-spacing:.14em;
      text-transform:uppercase;
      color:var(--muted2);
    }

    .row{
      padding:12px 14px;
      border-radius:18px;
      border:1px solid rgba(255,255,255,.06);
      background:rgba(10,10,10,.35);
      margin-top:10px;
    }

    .input{
      width:100%;
      padding:12px 14px;
      border-radius:16px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(18,18,18,.6);
      color:var(--text);
      font-family:var(--mono);
      outline:none;
    }
    .input::placeholder{ color:rgba(241,241,241,.35); }

    .select{
      width:100%;
      padding:12px 14px;
      border-radius:16px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(18,18,18,.6);
      color:var(--text);
      font-family:var(--mono);
      outline:none;
      appearance:none;
      background-image:
        linear-gradient(45deg, transparent 50%, rgba(241,241,241,.7) 50%),
        linear-gradient(135deg, rgba(241,241,241,.7) 50%, transparent 50%);
      background-position:
        calc(100% - 18px) 50%,
        calc(100% - 12px) 50%;
      background-size:6px 6px, 6px 6px;
      background-repeat:no-repeat;
    }

    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:11px 14px;
      border-radius:999px;
      border:1px solid rgba(24,243,163,.40);
      background:rgba(24,243,163,.10);
      color:var(--accent);
      font-weight:800;
      letter-spacing:.04em;
      cursor:default;
      user-select:none;
      min-width:92px;
      font-family:var(--sans);
    }

    .reviewFilters{
      display:grid;
      grid-template-columns: minmax(100px,1fr) minmax(110px,1fr) minmax(120px,160px) minmax(120px,140px) auto auto;
      gap:10px;
      align-items:center;
      margin-bottom:12px;
    }
    .reviewBtn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(255,255,255,.04);
      color:var(--text);
      padding:10px 16px;
      font-family:var(--mono);
      font-size:12px;
      font-weight:700;
      cursor:pointer;
    }
    .reviewBtn.primary{
      border-color: rgba(24,243,163,.45);
      background: rgba(24,243,163,.14);
      color: var(--accent);
    }
    .reviewBtn:disabled{
      opacity:0.45;
      cursor:not-allowed;
    }
    .reviewTable{
      border:1px solid rgba(255,255,255,.08);
      border-radius:18px;
      overflow:hidden;
      background:rgba(10,10,10,.30);
    }
    .reviewGridHeader, .reviewRow{
      display:grid;
      grid-template-columns: 1.5fr 1.4fr 0.9fr 1.4fr 0.8fr 0.9fr 1fr;
      gap:10px;
      align-items:center;
      font-family:var(--mono);
      padding:12px 14px;
    }
    .reviewGridHeader{
      border-bottom:1px solid rgba(255,255,255,.08);
      font-size:12px;
      letter-spacing:.12em;
      text-transform:uppercase;
      color:var(--muted2);
      background:rgba(255,255,255,.02);
    }
    .reviewRow{
      border-bottom:1px solid rgba(255,255,255,.06);
      font-size:13px;
      color:var(--muted);
    }
    .reviewRow:last-child{ border-bottom:0; }
    .chip{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.12);
      color:var(--text);
      background:rgba(18,18,18,.55);
      font-family:var(--mono);
      font-size:12px;
      width:fit-content;
      max-width:100%;
      word-break:break-word;
    }
    .chip.status{
      border-color: rgba(24,243,163,.42);
      background: rgba(24,243,163,.12);
      color: var(--accent);
    }
    .chip.status.rejected{
      border-color: rgba(255,94,94,.45);
      background: rgba(255,94,94,.12);
      color: var(--danger);
    }
    .chip.status.pending{
      border-color: rgba(255,200,120,.35);
      background: rgba(255,200,120,.10);
      color: #ffc878;
    }
    .reviewAction{
      display:flex;
      justify-content:flex-end;
      gap:8px;
      flex-wrap:wrap;
    }
    .reviewAction .reviewBtn{ padding:8px 12px; cursor:pointer; }

    .btnNeutral{
      border-color: rgba(255,255,255,.16);
      background: rgba(255,255,255,.06);
      color: var(--text);
    }

    .btnDanger{
      border-color: rgba(255,94,94,.50);
      background: rgba(255,94,94,.10);
      color: var(--danger);
    }
    .btnAccent{
      border-color: rgba(24,243,163,.45);
      background: rgba(24,243,163,.12);
      color: var(--accent);
    }

    .reviewMsg{
      margin-bottom:12px;
      font-family:var(--mono);
      font-size:13px;
      color:var(--muted);
    }
    .reviewMsg.err{ color: var(--danger); }
    .userMsg{
      margin-bottom:12px;
      font-family:var(--mono);
      font-size:13px;
      color:var(--muted);
      padding:10px 12px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.08);
      background:rgba(255,255,255,.03);
    }
    .userMsg.ok{
      color:var(--accent);
      border-color:rgba(24,243,163,.35);
      background:rgba(24,243,163,.10);
    }
    .userMsg.err{
      color:var(--danger);
      border-color:rgba(255,94,94,.45);
      background:rgba(255,94,94,.12);
    }

    @media (max-width: 980px){
      .gridHeader, .row{ grid-template-columns: 1fr; }
      .reviewFilters{ grid-template-columns: 1fr; }
      .reviewGridHeader, .reviewRow{ grid-template-columns: 1fr; }
      .btn{ width:100%; }
      .userAction{ justify-content:stretch; }
      .reviewAction{ justify-content:stretch; }
    }
  </style>
</head>

<body>
  <div class="wrap">
    <div class="topbar">
      <a class="logo" href="../../login_page.html" title="Back to login">
        <div class="logo-badge">♪</div>
        <div class="brand">
          <div class="name">MusicBox</div>
          <div class="sub">Discover your sound.</div>
        </div>
      </a>
      <div class="spacer"></div>
      <div class="pill"><span>SIGNED IN</span> <b><?php echo $emailDisplay; ?></b></div>
      <div class="pill"><span>ROLE</span> <b>ADMIN</b></div>
      <a class="logout" href="../auth/logout.php">Logout</a>
    </div>

    <section class="section" aria-label="User Management">
      <div class="sectionHeader">
        <div class="titleWrap">
          <div class="title">USER MANAGEMENT</div>
        </div>
        <button class="createBtn" id="createUserBtn" type="button">+ Create New User</button>
      </div>

      <div class="cardBody" id="userMgmtBody">
        <?php if ($userMessage !== ''): ?>
          <div class="userMsg ok"><?php echo htmlspecialchars($userMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($userError !== ''): ?>
          <div class="userMsg err"><?php echo htmlspecialchars($userError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="gridHeader">
          <div>User</div>
          <div>Role</div>
          <div>Status</div>
          <div class="userAction">Action</div>
        </div>
        <?php foreach ($usersRows as $urow): ?>
          <?php
            $uid = (int) ($urow['user_id'] ?? 0);
            $email = (string) ($urow['email'] ?? '');
            $role = strtolower((string) ($urow['role'] ?? 'analyst'));
            $isActive = (int) ($urow['is_active'] ?? 0) === 1 ? '1' : '0';
          ?>
          <form method="post" action="" class="row">
            <input type="hidden" name="form_action" value="save_user" />
            <input type="hidden" name="user_id" value="<?php echo $uid; ?>" />
            <input class="input" type="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required />
            <select class="select" name="role">
              <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
              <option value="analyst" <?php echo $role === 'analyst' ? 'selected' : ''; ?>>Analyst</option>
            </select>
            <select class="select" name="is_active">
              <option value="1" <?php echo $isActive === '1' ? 'selected' : ''; ?>>Active</option>
              <option value="0" <?php echo $isActive === '0' ? 'selected' : ''; ?>>Disabled</option>
            </select>
            <div class="userAction"><button class="btn" type="submit" style="cursor:pointer;">Save</button></div>
          </form>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section" aria-label="Catalog review">
      <div class="sectionHeader">
        <div class="titleWrap">
          <div class="title">CATALOG REVIEW</div>
        </div>
      </div>

      <div class="cardBody">
        <div id="reviewMsg" class="reviewMsg" hidden></div>
        <div class="reviewFilters">
          <input class="input" id="filterItem" placeholder="Item" autocomplete="off" />
          <input class="input" id="filterArtist" placeholder="Artist" autocomplete="off" />
          <select class="select" id="filterType">
            <option value="all" selected>All types</option>
            <option value="track">Track</option>
            <option value="album">Album</option>
            <option value="artist">Artist</option>
          </select>
          <select class="select" id="filterStatus">
            <option value="all" selected>All statuses</option>
            <option value="pending">pending</option>
            <option value="approved">approved</option>
            <option value="rejected">rejected</option>
          </select>
          <button class="reviewBtn primary" id="reviewSearchBtn" type="button">Search</button>
          <button class="reviewBtn" id="reviewClearBtn" type="button">Clear</button>
        </div>

        <div class="reviewTable">
          <div class="reviewGridHeader">
            <div>Item</div>
            <div>Artist</div>
            <div>Submitted By</div>
            <div>Reviewed By</div>
            <div>Type</div>
            <div>Status</div>
            <div style="text-align:right;">Action</div>
          </div>
          <div id="reviewRows"></div>
        </div>
      </div>
    </section>

  </div>
  <script>
    const reviewRows = document.getElementById("reviewRows");
    const reviewMsg = document.getElementById("reviewMsg");
    const filterItem = document.getElementById("filterItem");
    const filterArtist = document.getElementById("filterArtist");
    const filterType = document.getElementById("filterType");
    const filterStatus = document.getElementById("filterStatus");

    function showMsg(text, isErr) {
      reviewMsg.hidden = false;
      reviewMsg.textContent = text;
      reviewMsg.classList.toggle("err", !!isErr);
    }

    function clearMsg() {
      reviewMsg.hidden = true;
      reviewMsg.textContent = "";
      reviewMsg.classList.remove("err");
    }

    function esc(s) {
      const d = document.createElement("div");
      d.textContent = s;
      return d.innerHTML;
    }

    function typeLabel(t) {
      if (t === "track") return "Track";
      if (t === "album") return "Album";
      if (t === "artist") return "Artist";
      return t;
    }

    function statusClass(st) {
      const x = String(st || "").toLowerCase();
      if (x === "rejected") return "rejected";
      if (x === "pending") return "pending";
      return "";
    }

    async function loadQueue() {
      clearMsg();
      const params = new URLSearchParams();
      const st = filterStatus.value;
      if (st && st !== "all") params.set("status", st);
      const ty = filterType.value;
      if (ty && ty !== "all") params.set("type", ty);
      const it = filterItem.value.trim();
      if (it) params.set("item", it);
      const ar = filterArtist.value.trim();
      if (ar) params.set("artist", ar);

      const url = "review_queue.php?" + params.toString();
      const res = await fetch(url, { credentials: "same-origin" });
      const j = await res.json().catch(() => ({}));
      if (!res.ok || !j.ok) {
        showMsg(j.error || res.statusText || "Failed to load queue", true);
        reviewRows.innerHTML = "";
        return;
      }
      renderRows(j.items || []);
    }

    function renderRows(items) {
      if (!items.length) {
        reviewRows.innerHTML =
          '<div class="reviewRow"><div style="grid-column:1/-1;color:rgba(241,241,241,.55);">No matching rows.</div></div>';
        return;
      }
      reviewRows.innerHTML = items.map((row) => {
        const pending = String(row.status || "").toLowerCase() === "pending";
        const chipStatus = esc(String(row.status || ""));
        const detailHref = row.latest_event_id
          ? `review_detail.php?event_id=${encodeURIComponent(String(row.latest_event_id))}&entity=${encodeURIComponent(String(row.entity_type || ""))}&id=${encodeURIComponent(String(row.entity_id || ""))}`
          : `review_detail.php?entity=${encodeURIComponent(String(row.entity_type || ""))}&id=${encodeURIComponent(String(row.entity_id || ""))}`;
        const detailBtn = `<a class="reviewBtn" href="${detailHref}">Details</a>`;
        const actions = detailBtn;

        const sub = row.submitted_email ? `<span class="chip">${esc(String(row.submitted_email))}</span>` : `<span class="chip">—</span>`;
        const rev = row.reviewed_email ? `<span class="chip">${esc(String(row.reviewed_email))}</span>` : `<span class="chip">—</span>`;

        return `<div class="reviewRow">
            <div><span class="chip">${esc(String(row.item_name || ""))}</span></div>
            <div>${esc(String(row.artists_label || "—"))}</div>
            <div>${sub}</div>
            <div>${rev}</div>
            <div><span class="chip">${esc(typeLabel(row.entity_type))}</span></div>
            <div><span class="chip status ${statusClass(row.status)}">${chipStatus}</span></div>
            <div class="reviewAction">${actions}</div>
          </div>`;
      }).join("");
    }

    async function postAction(entity, id, action) {
      clearMsg();
      const res = await fetch("review_action.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ entity, id, action }),
      });
      const j = await res.json().catch(() => ({}));
      if (!res.ok || !j.ok) {
        let msg = j.error || res.statusText || "Action failed";
        if (msg === "details_not_viewed") {
          msg = "Please open Details for this record before Approve/Reject.";
        } else if (msg === "missing_detail_event") {
          msg = "No detail event found for this record yet.";
        }
        showMsg(msg, true);
        return;
      }
      await loadQueue();
    }

    document.getElementById("reviewSearchBtn").addEventListener("click", () => loadQueue());
    document.getElementById("reviewClearBtn").addEventListener("click", () => {
      filterItem.value = "";
      filterArtist.value = "";
      filterType.value = "all";
      filterStatus.value = "all";
      loadQueue();
    });

    loadQueue();

    const createUserBtn = document.getElementById("createUserBtn");
    const userMgmtBody = document.getElementById("userMgmtBody");

    createUserBtn.addEventListener("click", () => {
      const row = `
        <form method="post" action="" class="row" data-new-user-row="true">
          <input type="hidden" name="form_action" value="save_user" />
          <input type="hidden" name="user_id" value="" />
          <input class="input" type="email" name="email" placeholder="new.user@musicbox.com" required />
          <select class="select" name="role">
            <option value="admin">Admin</option>
            <option value="analyst" selected>Analyst</option>
          </select>
          <select class="select" name="is_active">
            <option value="1" selected>Active</option>
            <option value="0">Disabled</option>
          </select>
          <div class="userAction">
            <button class="btn btnNeutral cancelNewUserBtn" type="button" style="cursor:pointer;">Cancel</button>
            <button class="btn" type="submit" style="cursor:pointer;">Save</button>
          </div>
        </form>
      `;
      const header = userMgmtBody.querySelector(".gridHeader");
      if (header) header.insertAdjacentHTML("afterend", row);
    });

    userMgmtBody.addEventListener("click", (e) => {
      const cancelBtn = e.target.closest(".cancelNewUserBtn");
      if (!cancelBtn) return;
      const row = cancelBtn.closest('[data-new-user-row="true"]');
      if (row) row.remove();
    });
  </script>
</body>
</html>
