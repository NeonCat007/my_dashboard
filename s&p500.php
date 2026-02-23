<?php
require_once __DIR__ . '/condb.php';
if (session_status() === PHP_SESSION_NONE) session_start();

const APP_ID = 'invest';
const STORAGE_KEY = 'sp500_app_v2';

function json_out($data, int $code=200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

function normalize_state($s){
  if (is_string($s)) {
    $t = trim($s);
    if ($t === '') return null;
    $s = json_decode($t, true);
  }
  if (!is_array($s)) return null;
  if (!isset($s['portfolios']) || !is_array($s['portfolios'])) return null;
  if (!isset($s['ledger']) || !is_array($s['ledger'])) return null;

  if (count($s['portfolios']) === 0) $s['portfolios'] = ['DEFAULT'];

  if (!isset($s['activePortfolio']) || !in_array($s['activePortfolio'], $s['portfolios'], true)) {
    $s['activePortfolio'] = $s['portfolios'][0] ?? 'DEFAULT';
  }

  $clean = [];
  foreach ($s['ledger'] as $e) {
    if (!is_array($e)) continue;
    if (!isset($e['id'],$e['portfolio'],$e['mode'],$e['type'],$e['time'])) continue;

    $item = [
      'id'        => (string)$e['id'],
      'portfolio' => (string)$e['portfolio'],
      'mode'      => (string)$e['mode'],
      'type'      => (string)$e['type'],
      'time'      => (int)$e['time'],
    ];

    if ($item['mode'] === 'stock') {
      $item['usd'] = isset($e['usd']) ? (float)$e['usd'] : 0.0;
    } elseif ($item['mode'] === 'fx') {
      $item['dir']  = isset($e['dir']) ? (string)$e['dir'] : 'THB2USD';
      $item['rate'] = isset($e['rate']) ? (float)$e['rate'] : 0.0;
      $item['thb']  = isset($e['thb']) ? (float)$e['thb'] : 0.0;
      $item['usd']  = isset($e['usd']) ? (float)$e['usd'] : 0.0;
    } else {
      continue;
    }

    if (isset($e['note']) && trim((string)$e['note']) !== '') {
      $item['note'] = (string)$e['note'];
    }

    $clean[] = $item;
  }
  $s['ledger'] = $clean;

  if (!in_array($s['activePortfolio'], $s['portfolios'], true)) {
    $s['activePortfolio'] = $s['portfolios'][0] ?? 'DEFAULT';
  }

  return $s;
}

function default_state(): array {
  return ['portfolios'=>['DEFAULT'],'activePortfolio'=>'DEFAULT','ledger'=>[]];
}

function read_state(mysqli $conn): array {
  $sql = "SELECT value_json, updated_at FROM dashboard_kv WHERE app_id=? AND storage_key=? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if(!$stmt) return ['state'=>default_state(),'updated_at'=>null];

  $app = APP_ID; $key = STORAGE_KEY;
  $stmt->bind_param("ss", $app, $key);
  $stmt->execute();
  $stmt->bind_result($value_json, $updated_at);

  $state = default_state();
  $ts = null;

  if ($stmt->fetch()) {
    $ts = $updated_at;
    $decoded = normalize_state($value_json);
    if ($decoded) $state = $decoded;
  }

  $stmt->close();
  return ['state'=>$state,'updated_at'=>$ts];
}

function write_state(mysqli $conn, array $state): bool {
  $pretty = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

  $sql = "
    INSERT INTO dashboard_kv (app_id, storage_key, value_json)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)
  ";
  $stmt = $conn->prepare($sql);
  if(!$stmt) return false;

  $app = APP_ID; $key = STORAGE_KEY;
  $stmt->bind_param("sss", $app, $key, $pretty);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

/* ------------------ API ------------------ */
if (isset($_GET['api'])) {
  $api = (string)$_GET['api'];

  if ($api === 'state') {
    $r = read_state($conn);
    json_out(['ok'=>true,'state'=>$r['state'],'updated_at'=>$r['updated_at']]);
  }

  if ($api === 'save_state') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      json_out(['ok'=>false,'error'=>'method_not_allowed'], 405);
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $ok = normalize_state($data);
    if (!$ok) json_out(['ok'=>false,'error'=>'bad_state'], 400);

    $saved = write_state($conn, $ok);
    if(!$saved) json_out(['ok'=>false,'error'=>'db_write_failed'], 500);

    $r = read_state($conn);
    json_out(['ok'=>true,'updated_at'=>$r['updated_at']]);
  }

  json_out(['ok'=>false,'error'=>'unknown_api'], 404);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>S&P500 – บันทึกการลงทุน</title>

<script src="https://cdn.jsdelivr.net/npm/dayjs@1/dayjs.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dayjs@1/plugin/duration.js"></script>

<!-- ✅ SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* ===== Theme (ปรับให้โหมดมืด “เข้มทั้งหน้า” + ปุ่มแดง/เขียวเป็นเฉดแบบไฟล์ตัวอย่าง) ===== */
:root{
  --bg:#f3f7ff;
  --card:#ffffff;
  --card2:#ffffff;
  --border:#dbe7ff;

  --accent1:#2563eb;
  --accent2:#38bdf8;

  --danger1:#dc2626;
  --danger2:#ef4444;

  --ok1:#16a34a;
  --ok2:#22c55e;

  --text-main:#0f172a;
  --text-soft:#334155;
  --text-invert:#ffffff;

  --placeholder:#64748b;
  --shadow: 0 10px 30px rgba(0,0,0,.08);
  --radius:14px;
}

body.dark{
  --bg:#0b0b0b;
  --card:#101114;     /* ✅ การ์ด/คอนเทนต์เข้ม */
  --card2:#0d0e10;    /* ✅ ส่วนหัว/แท็บเข้มกว่าเล็กน้อย */
  --border:#2a2a2a;

  --accent1:#b11226;  /* ✅ แดงเข้มแบบไฟล์ตัวอย่าง */
  --accent2:#ff4d4d;  /* ✅ แดงสว่าง */

  --danger1:#b11226;
  --danger2:#ef4444;

  --ok1:#16a34a;
  --ok2:#22c55e;

  --text-main:#f1f1f1;
  --text-soft:#cbd5e1;
  --text-invert:#ffffff;

  --placeholder:#9ca3af;
  --shadow: 0 10px 30px rgba(0,0,0,.45);
}

/* ===== Base ===== */
*{box-sizing:border-box;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial}
body{
  margin:0;
  background:var(--bg);
  color:var(--text-main);
  padding:0;
  color-scheme: light;
}
body.dark{ color-scheme: dark; }

main{padding:12px}

/* ===== Header ===== */
header{
  padding:12px 16px;
  background:var(--card2);
  border-bottom:2px solid var(--accent1);
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
}
header b{font-size:16px}
header .small{color:var(--text-soft);font-size:12px;font-weight:800}

/* ===== Top Tabs ===== */
nav{
  display:flex;
  gap:8px;
  padding:10px;
}
nav button{flex:1}

.tabBtn{
  border-radius:10px;
  padding:10px 14px;
  cursor:pointer;
  font-weight:900;
  border:1px solid var(--accent1);
  background:transparent;
  color:var(--text-main);
}
.tabBtn:hover{background:rgba(37,99,235,.08)}
body.dark .tabBtn:hover{background:rgba(255,255,255,.06)}
.tabBtn.active{
  background:linear-gradient(135deg, var(--accent1), var(--accent2));
  color:var(--text-invert);
  border-color:rgba(0,0,0,.05);
}

/* ===== Card ===== */
.card{
  background:var(--card);
  border-radius:14px;
  padding:14px;
  margin-bottom:12px;
  border:1px solid var(--border);
  box-shadow:var(--shadow);
}

/* ===== Text ===== */
label{font-weight:900;color:var(--text-main)}
small{color:var(--text-soft);font-size:12px;line-height:1.35}
hr{border:none;border-top:1px solid var(--border);margin:14px 0}

/* ===== Rows ===== */
.row{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  align-items:flex-end;
}
.row > *{flex:1;min-width:160px}

/* ===== Grid 2 columns ===== */
.grid2{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:12px;
}
@media (max-width:900px){
  .grid2{ grid-template-columns:1fr; }
}

/* ปุ่ม 2 อันให้กว้างเท่ากัน */
.btnRow{
  display:flex;
  gap:10px;
  margin-top:8px;
}
.btnRow > button{ flex:1; }

.fieldBlock label{ display:block; }

/* ===== Buttons ===== */
button{
  border-radius:10px;
  padding:10px 14px;
  background:linear-gradient(135deg, var(--accent1), var(--accent2));
  color:var(--text-invert);
  border:1px solid rgba(0,0,0,.05);
  cursor:pointer;
  font-weight:900;
}
button:hover{filter:brightness(1.03)}
button:active{transform:translateY(1px)}
button.outline{
  background:none;
  color:var(--text-main);
  border:1px solid var(--accent1);
}
button.outline:hover{background:rgba(37,99,235,.08)}
body.dark button.outline:hover{background:rgba(255,255,255,.06)}

/* ✅ ปุ่มแดง/เขียวเป็น “เฉด” ไม่ใช่สีทึบ */
button.danger, .danger{
  background:linear-gradient(135deg, var(--danger1), var(--danger2));
  border-color:rgba(0,0,0,.05);
  color:#fff;
}
button.ok, .ok{
  background:linear-gradient(135deg, var(--ok1), var(--ok2));
  border-color:rgba(0,0,0,.05);
  color:#fff;
}
.actionBtn{padding:6px 10px;border-radius:8px;font-weight:900}

/* ===== Inputs ===== */
input,select{
  width:100%;
  padding:12px;
  margin-top:6px;
  border-radius:10px;
  border:1px solid var(--border);
  background:transparent;
  color:var(--text-main);
  outline:none;
  font-size:16px;
}
input::placeholder{ color:var(--placeholder); opacity:1 }

/* ✅ dropdown อ่านง่ายทั้งโหมด */
select option, select optgroup{ background: var(--card); color: var(--text-main); }

/* ✅ select arrow ให้ชัดในโหมดมืด */
select{
  appearance:none;
  background-image:
    linear-gradient(45deg, transparent 50%, var(--text-main) 50%),
    linear-gradient(135deg, var(--text-main) 50%, transparent 50%),
    linear-gradient(to right, transparent, transparent);
  background-position:
    calc(100% - 18px) calc(1em + 4px),
    calc(100% - 13px) calc(1em + 4px),
    calc(100% - 2.5em) 0.5em;
  background-size:5px 5px, 5px 5px, 1px 1.5em;
  background-repeat:no-repeat;
}
body.dark select{
  background-image:
    linear-gradient(45deg, transparent 50%, rgba(255,255,255,.92) 50%),
    linear-gradient(135deg, rgba(255,255,255,.92) 50%, transparent 50%),
    linear-gradient(to right, transparent, transparent);
}

/* ===== Table ===== */
.tableWrap{
  overflow:auto;
  border-radius:14px;
  border:1px solid var(--border);
  box-shadow:var(--shadow);
}
table{
  border-collapse:separate;
  border-spacing:0;
  width:100%;
  min-width:920px;
  background:transparent;
}
th,td{
  padding:10px 12px;
  border-bottom:1px solid var(--border);
  text-align:left;
  vertical-align:top;
  font-size:13px;
}
th{
  position:sticky;
  top:0;
  z-index:1;
  background:rgba(148,163,184,.12);
  color:var(--text-soft);
  font-size:12px;
  text-transform:uppercase;
}
body.dark th{
  background:rgba(255,255,255,.06);
}
tr:last-child td{border-bottom:none}
.right{text-align:right}
.mono{font-variant-numeric:tabular-nums}
.badge{
  display:inline-block;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid var(--border);
  font-size:12px;
  color:var(--text-soft);
  font-weight:900;
}

/* ===== Chart canvas ===== */
.canvasWrap{
  border:1px solid var(--border);
  border-radius:14px;
  overflow:hidden;
  box-shadow:var(--shadow);
}
canvas{display:block;width:100%;height:260px;background:transparent}

/* ===== Modal ===== */
.modalBack{
  position:fixed;inset:0;
  background:rgba(0,0,0,.45);
  display:none;
  align-items:center;
  justify-content:center;
  padding:14px;
  z-index:9999;
}
.modal{
  width:min(920px, 96vw);
  background:var(--card);
  border:1px solid var(--border);
  border-radius:16px;
  box-shadow:var(--shadow);
  padding:14px;
}
.modalHeader{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px
}
.modalHeader h3{margin:0}
.modalGrid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:12px;
  margin-top:12px
}
@media (max-width:700px){ .modalGrid{grid-template-columns:1fr} }

/* ===== KBD pill ===== */
.kbd{
  border:1px solid var(--border);
  border-bottom-width:3px;
  border-radius:8px;
  padding:2px 8px;
  font-weight:900;
  color:var(--text-soft);
  background:rgba(100,116,139,.12);
}
body.dark .kbd{ background:rgba(255,255,255,.06); }

/* ===== Panels ===== */
.panel{display:none}
.panel.active{display:block}

/* ===== SweetAlert2: ใช้ฟอนต์ initial แบบไฟล์คุณ ===== */
.swal2-popup,
.swal2-title,
.swal2-html-container,
.swal2-content{
  font-family: initial !important;
}
.swal2-popup{ border-radius:16px !important; }
</style>
</head>

<body>

<?php
// ✅ SweetAlert2 via SESSION (ตามตัวอย่างคุณเป๊ะ)
if (isset($_SESSION['success'])) { ?>
  <script>
  Swal.fire({
    icon: 'success',
    title: 'สำเร็จ!',
    text: <?= json_encode($_SESSION['success'], JSON_UNESCAPED_UNICODE) ?>,
    showConfirmButton: false,
    timer: 1000,
    timerProgressBar: true
  });
  </script>
<?php unset($_SESSION['success']); }

if (isset($_SESSION['error'])) { ?>
  <script>
  Swal.fire({
    icon: 'error',
    title: 'เกิดข้อผิดพลาด!',
    text: <?= json_encode($_SESSION['error'], JSON_UNESCAPED_UNICODE) ?>,
    showConfirmButton: false,
    timer: 1000,
    timerProgressBar: true
  });
  </script>
<?php unset($_SESSION['error']); }
?>

<?php
// ถ้ามี navbar.php ก็ใส่ได้ ไม่มีก็ไม่ error
if (file_exists(__DIR__ . '/navbar.php')) {
  include __DIR__ . '/navbar.php';
}
?>

<header>
  <div class="hdr-left">
    <b>📈 S&P500 – ระบบบันทึกการลงทุน</b>
  </div>
  <div class="hdr-right">
    <span class="small" id="updatedAtText"></span>
  </div>
</header>

<nav>
  <button id="btnHome" class="tabBtn" type="button" onclick="showMainTab('home')">📁 พอร์ต / เพิ่มรายการ</button>
  <button id="btnHistory" class="tabBtn" type="button" onclick="showMainTab('history')">📜 ประวัติ</button>
  <button id="btnCharts" class="tabBtn" type="button" onclick="showMainTab('charts')">📈 กราฟ</button>
</nav>

<main>

  <section id="panelHome" class="panel">
    <div class="card" id="topCard">
      <small>
        กด <span class="kbd">Enter</span> เพื่อบันทึก • ข้อมูลเก็บในฐานข้อมูล
        • Undo/Redo: <span class="kbd">Ctrl</span>+<span class="kbd">Z</span> / <span class="kbd">Ctrl</span>+<span class="kbd">Y</span>
      </small>
    </div>

    <div class="card">
      <div class="grid2">
        <div class="fieldBlock">
          <label>พอร์ต</label>
          <select id="portfolio"></select>
          <small>ข้อมูลแยกตามพอร์ต</small>
        </div>

        <div class="fieldBlock">
          <label>เพิ่มพอร์ตใหม่</label>
          <input id="newPortfolio" placeholder="เช่น VOO / SPY / QQQ" />
          <div class="btnRow">
            <button class="ok" onclick="addPortfolio()" type="button">➕ เพิ่มพอร์ต</button>
            <button class="danger" onclick="deletePortfolio()" type="button">🗑 ลบพอร์ต</button>
          </div>
        </div>
      </div>
    </div>

    <div class="card" id="entryCard">
      <div class="row">
        <div>
          <label>โหมด</label>
          <select id="mode">
            <option value="stock">โหมดหุ้น (USD)</option>
            <option value="fx">โหมดแลกเงิน บาท ↔ ดอลลาร์</option>
          </select>
        </div>
        <div>
          <label id="amountLabel">จำนวนเงิน (USD)</label>
          <input id="amount" type="number" step="0.01" placeholder="จำนวนเงิน" />
        </div>
        <div id="rateWrap" style="display:none">
          <label>อัตราแลกเปลี่ยน (บาทต่อดอลลาร์)</label>
          <input id="rate" type="number" step="0.0001" placeholder="เช่น 35.50" />
        </div>
      </div>

      <div class="row" style="margin-top:10px">
        <button class="danger" onclick="addEntry('invest')" type="button">➖ ลงเงิน</button>
        <button class="ok" onclick="addEntry('return')" type="button">➕ ได้เงิน</button>
      </div>
      <small id="hint"></small>
    </div>

    <div class="card" id="summary"></div>
    <div class="card" id="duration"></div>
  </section>

  <section id="panelHistory" class="panel">
    <div class="card" id="historyCard">
      <div class="row" style="align-items:center">
        <div style="flex:2;min-width:240px">
          <h3 style="margin:0">ประวัติรายการ</h3>
          <small id="historyMeta"></small>
        </div>
        <div>
          <label>แสดงจำนวน</label>
          <select id="limit">
            <option value="20">20</option>
            <option value="50" selected>50</option>
            <option value="100">100</option>
            <option value="9999">ทั้งหมด</option>
          </select>
        </div>
        <div>
          <label>ตัวกรอง</label>
          <select id="filter">
            <option value="all">ทั้งหมด (พอร์ตนี้)</option>
            <option value="stock">เฉพาะหุ้น</option>
            <option value="fx">เฉพาะแลกเงิน</option>
          </select>
        </div>
      </div>

      <div class="tableWrap" style="margin-top:10px">
        <table>
          <thead>
            <tr>
              <th>เวลา</th>
              <th>พอร์ต</th>
              <th>โหมด</th>
              <th>ประเภท</th>
              <th class="right">จำนวน</th>
              <th class="right">THB</th>
              <th class="right">USD</th>
              <th class="right">อัตรา</th>
              <th class="right">จัดการ</th>
            </tr>
          </thead>
          <tbody id="historyBody"></tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="row">
        <button class="danger" onclick="clearMode()" type="button">🧹 ล้างเฉพาะโหมดนี้</button>
        <button class="danger" onclick="clearPortfolio()" type="button">🧹 ล้างเฉพาะพอร์ตนี้</button>
        <button class="danger" onclick="clearAll()" type="button">🗑 ล้างทั้งหมด</button>
      </div>
    </div>
  </section>

  <section id="panelCharts" class="panel">
    <div class="card" id="chartsCard">
      <h3 style="margin:0 0 10px">กราฟ (สะสมตามเวลา)</h3>
      <small id="chartNote"></small>
      <div class="canvasWrap" style="margin-top:10px">
        <canvas id="chart"></canvas>
      </div>
    </div>

    <div class="card">
      <div class="row">
        <button class="danger" onclick="clearMode()" type="button">🧹 ล้างเฉพาะโหมดนี้</button>
        <button class="danger" onclick="clearPortfolio()" type="button">🧹 ล้างเฉพาะพอร์ตนี้</button>
        <button class="danger" onclick="clearAll()" type="button">🗑 ล้างทั้งหมด</button>
      </div>
    </div>
  </section>

</main>

<!-- Modal edit -->
<div class="modalBack" id="modalBack" onclick="closeModal(event)">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="modalHeader">
      <h3>แก้ไขรายการ</h3>
      <button class="danger actionBtn" onclick="closeModal()" type="button">ปิด</button>
    </div>

    <div class="modalGrid">
      <div>
        <label>พอร์ต</label>
        <select id="editPortfolio"></select>
      </div>
      <div>
        <label>โหมด</label>
        <select id="editMode">
          <option value="stock">หุ้น</option>
          <option value="fx">แลกเงิน</option>
        </select>
      </div>

      <div>
        <label>ประเภท</label>
        <select id="editType">
          <option value="invest">ลงเงิน</option>
          <option value="return">ได้เงิน</option>
        </select>
      </div>

      <div id="editDirWrap">
        <label>ทิศทาง (FX)</label>
        <select id="editDir">
          <option value="THB2USD">THB → USD</option>
          <option value="USD2THB">USD → THB</option>
        </select>
      </div>

      <div>
        <label id="editAmountLabel">จำนวน</label>
        <input id="editAmount" type="number" step="0.01" />
      </div>

      <div id="editRateWrap">
        <label>อัตราแลกเปลี่ยน</label>
        <input id="editRate" type="number" step="0.0001" />
      </div>

      <div>
        <label>เวลา (แก้ไม่ได้)</label>
        <input id="editTime" disabled />
      </div>

      <div>
        <label>หมายเหตุ</label>
        <input id="editNote" placeholder="ใส่ก็ได้ ไม่ใส่ก็ได้" />
      </div>
    </div>

    <hr />
    <div class="row">
      <button class="ok" onclick="saveEdit()" type="button">✅ บันทึกการแก้ไข</button>
      <button class="danger" onclick="deleteEditing()" type="button">🗑 ลบรายการนี้</button>
    </div>
    <small>เปลี่ยนโหมด/ประเภท/พอร์ตได้ • ถ้าเป็น FX ต้องมี rate &gt; 0</small>
  </div>
</div>

<script>
dayjs.extend(dayjs_plugin_duration);

// ===== Keys =====
const KEY = 'sp500_app_v2';
const MAIN_TAB_KEY = 'sp500_main_tab';

// ✅ API base
const API_BASE = window.location.pathname;

// ===== SweetAlert2 helpers =====
// (คงไว้ใช้กับปุ่มต่าง ๆ ในหน้า) — เป็น Toast มุมบนเหมือนในรูปตัวอย่าง
const Toast = Swal.mixin({
  toast: true,
  position: 'top',
  showConfirmButton: false,
  timer: 1000,
  timerProgressBar: true
});

function toastOk(title, text=''){ Toast.fire({ icon:'success', title: title||'สำเร็จ', text: text||'' }); }
function toastErr(title, text=''){ Toast.fire({ icon:'error', title: title||'ผิดพลาด', text: text||'' }); }

async function confirmBox({title, text, confirmText='ยืนยัน', cancelText='ยกเลิก', icon='warning'}){
  const r = await Swal.fire({
    icon, title, text,
    showCancelButton:true,
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
    reverseButtons:true
  });
  return !!r.isConfirmed;
}

// ===== Theme (sync via localStorage: darkmode) =====
function applyThemeFromStorage(){
  const dark = localStorage.getItem('darkmode') === 'true';
  document.body.classList.toggle('dark', dark);
}
applyThemeFromStorage();
window.addEventListener('storage', (e)=>{
  if(e.key === 'darkmode') applyThemeFromStorage();
});

// ===== API =====
async function apiGetState(){
  const r = await fetch(`${API_BASE}?api=state`, { cache: 'no-store' });
  return await r.json();
}
async function apiSaveState(state){
  const r = await fetch(`${API_BASE}?api=save_state`, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(state)
  });
  return await r.json();
}

function normalizeStateFromServer(s){
  if(!s || !Array.isArray(s.portfolios) || !Array.isArray(s.ledger)) return null;
  if(s.portfolios.length===0) s.portfolios=['DEFAULT'];
  if(!s.activePortfolio || !s.portfolios.includes(s.activePortfolio)){
    s.activePortfolio = s.portfolios[0] || 'DEFAULT';
  }
  return s;
}

async function loadState(){
  try{
    const j = await apiGetState();
    if(j && j.ok){
      const ok = normalizeStateFromServer(j.state);
      if(ok){
        try{ localStorage.setItem(KEY, JSON.stringify(ok)); }catch{}
        return ok;
      }
    }
    throw new Error(j && j.error ? j.error : 'bad_response');
  }catch(err){
    const cached = localStorage.getItem(KEY);
    if(cached){
      try{
        const s = JSON.parse(cached);
        const ok = normalizeStateFromServer(s);
        if(ok) return ok;
      }catch{}
    }
    return { portfolios:['DEFAULT'], activePortfolio:'DEFAULT', ledger:[] };
  }
}

async function saveState(){
  try{
    const j = await apiSaveState(state);
    if(j && j.ok){
      try{ localStorage.setItem(KEY, JSON.stringify(state)); }catch{}
      return true;
    }
    toastErr('บันทึกไม่สำเร็จ', j && j.error ? j.error : 'server error');
    return false;
  }catch(err){
    toastErr('บันทึกไม่สำเร็จ', 'เชื่อมต่อฐานข้อมูลไม่ได้');
    try{ localStorage.setItem(KEY, JSON.stringify(state)); }catch{}
    return false;
  }
}

/* ===== Undo/Redo ===== */
const UNDO_LIMIT = 30;
let undoStack = [];
let redoStack = [];

function snapshotState(){ return JSON.stringify(state); }

async function restoreFromSnapshot(snap){
  try{
    const obj = JSON.parse(snap);
    if(!obj || !Array.isArray(obj.portfolios) || !Array.isArray(obj.ledger)) throw new Error('bad snapshot');
    state = obj;
    if(state.portfolios.length===0) state.portfolios = ['DEFAULT'];
    if(!state.activePortfolio || !state.portfolios.includes(state.activePortfolio)){
      state.activePortfolio = state.portfolios[0];
    }
    await saveState();
    refreshPortfolioSelects();
    render();
    toastOk('Undo/Redo', 'กู้สถานะเรียบร้อย');
  }catch{
    toastErr('Undo/Redo', 'กู้สถานะไม่สำเร็จ');
  }
}

function pushUndo(){
  try{
    undoStack.push(snapshotState());
    if(undoStack.length > UNDO_LIMIT) undoStack.shift();
    redoStack = [];
  }catch{}
}
function canUndo(){ return undoStack.length>0; }
function canRedo(){ return redoStack.length>0; }
function doUndo(){
  if(!canUndo()) return toastErr('Undo', 'ไม่มีรายการให้ย้อนกลับ');
  const cur = snapshotState();
  const prev = undoStack.pop();
  redoStack.push(cur);
  restoreFromSnapshot(prev);
}
function doRedo(){
  if(!canRedo()) return toastErr('Redo', 'ไม่มีรายการให้ทำซ้ำ');
  const cur = snapshotState();
  const next = redoStack.pop();
  undoStack.push(cur);
  restoreFromSnapshot(next);
}

window.addEventListener('keydown', (ev)=>{
  const tag = (document.activeElement && document.activeElement.tagName) ? document.activeElement.tagName.toLowerCase() : '';
  const typing = (tag === 'input' || tag === 'textarea');
  if(typing) return;

  const isMac = /Mac|iPhone|iPad|iPod/i.test(navigator.platform);
  const ctrlOrCmd = isMac ? ev.metaKey : ev.ctrlKey;
  if(!ctrlOrCmd) return;

  if(ev.key.toLowerCase() === 'z' && !ev.shiftKey){
    ev.preventDefault(); doUndo();
  }else if(ev.key.toLowerCase() === 'y' || (ev.key.toLowerCase() === 'z' && ev.shiftKey)){
    ev.preventDefault(); doRedo();
  }
});

/* ===== Main Tabs ===== */
const btnHome = document.getElementById('btnHome');
const btnHistory = document.getElementById('btnHistory');
const btnCharts = document.getElementById('btnCharts');

const panelHome = document.getElementById('panelHome');
const panelHistory = document.getElementById('panelHistory');
const panelCharts = document.getElementById('panelCharts');

function showMainTab(name){
  const tab = (name==='history' || name==='charts') ? name : 'home';

  panelHome.classList.toggle('active', tab==='home');
  panelHistory.classList.toggle('active', tab==='history');
  panelCharts.classList.toggle('active', tab==='charts');

  btnHome.classList.toggle('active', tab==='home');
  btnHistory.classList.toggle('active', tab==='history');
  btnCharts.classList.toggle('active', tab==='charts');

  localStorage.setItem(MAIN_TAB_KEY, tab);
  if(tab==='charts') setTimeout(drawChart, 0);
}

/* ===== DOM ===== */
const modeEl = document.getElementById('mode');
const amountEl = document.getElementById('amount');
const rateEl = document.getElementById('rate');
const rateWrap = document.getElementById('rateWrap');
const amountLabel = document.getElementById('amountLabel');
const hintEl = document.getElementById('hint');
const summaryEl = document.getElementById('summary');
const durationEl = document.getElementById('duration');

const portfolioEl = document.getElementById('portfolio');
const newPortfolioEl = document.getElementById('newPortfolio');

const historyBody = document.getElementById('historyBody');
const historyMeta = document.getElementById('historyMeta');
const limitEl = document.getElementById('limit');
const filterEl = document.getElementById('filter');

const chartCanvas = document.getElementById('chart');
const chartNote = document.getElementById('chartNote');

// modal dom
const modalBack = document.getElementById('modalBack');
const editPortfolioEl = document.getElementById('editPortfolio');
const editModeEl = document.getElementById('editMode');
const editTypeEl = document.getElementById('editType');
const editDirWrap = document.getElementById('editDirWrap');
const editDirEl = document.getElementById('editDir');
const editAmountEl = document.getElementById('editAmount');
const editRateWrap = document.getElementById('editRateWrap');
const editRateEl = document.getElementById('editRate');
const editTimeEl = document.getElementById('editTime');
const editNoteEl = document.getElementById('editNote');
const editAmountLabel = document.getElementById('editAmountLabel');

/* ===== Data model ===== */
let state = { portfolios:['DEFAULT'], activePortfolio:'DEFAULT', ledger:[] };

function uid(){
  return 'k' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}

/* ===== Helpers ===== */
function fmt(n, d=2){
  if(typeof n !== 'number' || !isFinite(n)) return '-';
  return n.toFixed(d);
}
function fmtTime(ms){
  return dayjs(ms).format('YYYY-MM-DD HH:mm:ss');
}
function badge(text){
  return `<span class="badge">${text}</span>`;
}
function activePortfolio(){
  return portfolioEl.value || state.activePortfolio;
}
function escapeHtml(s){
  return String(s)
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

/* ===== Portfolio UI ===== */
function refreshPortfolioSelects(){
  const opts = state.portfolios.map(p=>`<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`).join('');
  portfolioEl.innerHTML = opts;
  editPortfolioEl.innerHTML = opts;

  if(state.activePortfolio && state.portfolios.includes(state.activePortfolio)){
    portfolioEl.value = state.activePortfolio;
  }else{
    state.activePortfolio = state.portfolios[0];
    portfolioEl.value = state.activePortfolio;
  }
  editPortfolioEl.value = portfolioEl.value;
}

async function addPortfolio(){
  const name = newPortfolioEl.value.trim();
  if(!name) return toastErr('เพิ่มพอร์ต', 'กรุณากรอกชื่อพอร์ต');
  if(state.portfolios.includes(name)) return toastErr('เพิ่มพอร์ต', 'มีพอร์ตนี้อยู่แล้ว');

  pushUndo();
  state.portfolios.push(name);
  state.activePortfolio = name;

  const ok = await saveState();
  if(!ok){ doUndo(); return; }

  newPortfolioEl.value='';
  refreshPortfolioSelects();
  render();
  toastOk('เพิ่มพอร์ต', `เพิ่มพอร์ต: ${name}`);
}

async function deletePortfolio(){
  const p = activePortfolio();
  if(state.portfolios.length <= 1) return toastErr('ลบพอร์ต', 'ต้องมีอย่างน้อย 1 พอร์ต');

  const yes = await confirmBox({
    title:'ลบพอร์ต',
    text:`ต้องการลบพอร์ต “${p}” ใช่ไหม? (รายการในพอร์ตนี้จะถูกลบด้วย)`,
    confirmText:'ลบพอร์ต',
    cancelText:'ยกเลิก',
    icon:'warning'
  });
  if(!yes) return;

  pushUndo();
  state.ledger = state.ledger.filter(e=>e.portfolio !== p);
  state.portfolios = state.portfolios.filter(x=>x!==p);
  state.activePortfolio = state.portfolios[0] || 'DEFAULT';

  const ok = await saveState();
  if(!ok){ doUndo(); return; }

  refreshPortfolioSelects();
  render();
  toastOk('ลบพอร์ต', `ลบพอร์ต: ${p}`);
}

portfolioEl.addEventListener('change', async ()=>{
  state.activePortfolio = activePortfolio();
  await saveState();
  render();
});

/* ===== Mode UI ===== */
function syncModeUI(){
  const fx = modeEl.value === 'fx';
  rateWrap.style.display = fx ? 'block' : 'none';
  amountLabel.textContent = fx ? 'จำนวนเงิน' : 'จำนวนเงิน (USD)';
  hintEl.textContent = fx
    ? 'โหมดแลกเงิน: ลงเงิน = THB→USD, ได้เงิน = USD→THB (ต้องใส่อัตราแลกเปลี่ยน)'
    : 'โหมดหุ้น: ลงเงิน/ได้เงิน เป็นหน่วย USD';
}
modeEl.addEventListener('change', ()=>{ syncModeUI(); render(); });
limitEl.addEventListener('change', render);
filterEl.addEventListener('change', render);

[amountEl, rateEl].forEach(el=>{
  el.addEventListener('keydown',(ev)=>{
    if(ev.key === 'Enter') addEntry('invest');
  });
});

/* ===== Add Entry ===== */
async function addEntry(type){
  const mode = modeEl.value;
  const amount = parseFloat(amountEl.value);
  const port = activePortfolio();

  if(!(amount>0)) return toastErr('บันทึก', 'กรุณากรอกจำนวนเงินให้ถูกต้อง');

  pushUndo();

  if(mode === 'stock'){
    state.ledger.push({
      id: uid(),
      portfolio: port,
      mode:'stock',
      type,
      usd: amount,
      time: Date.now()
    });
  }else{
    const rate = parseFloat(rateEl.value);
    if(!(rate>0)){
      undoStack.pop();
      return toastErr('บันทึก FX', 'กรุณากรอกอัตราแลกเปลี่ยนให้ถูกต้อง (มากกว่า 0)');
    }

    let thb=0, usd=0, dir=null;
    if(type==='invest'){ dir='THB2USD'; thb = amount; usd = amount / rate; }
    else{ dir='USD2THB'; usd = amount; thb = amount * rate; }

    state.ledger.push({
      id: uid(),
      portfolio: port,
      mode:'fx',
      type,
      dir,
      rate,
      thb,
      usd,
      time: Date.now()
    });
  }

  const ok = await saveState();
  if(!ok){ doUndo(); return; }

  amountEl.value='';
  rateEl.value='';
  render();
  toastOk('บันทึกแล้ว', type==='invest' ? 'เพิ่มรายการ “ลงเงิน”' : 'เพิ่มรายการ “ได้เงิน”');
}

/* ===== Clear functions ===== */
async function clearMode(){
  const m = modeEl.value;
  const p = activePortfolio();

  const yes = await confirmBox({
    title:'ล้างข้อมูล',
    text:`ล้างข้อมูลโหมด “${m}” เฉพาะพอร์ต “${p}” ใช่ไหม?`,
    confirmText:'ล้าง',
    cancelText:'ยกเลิก'
  });
  if(!yes) return;

  pushUndo();
  state.ledger = state.ledger.filter(e => !(e.portfolio===p && e.mode===m));

  const ok = await saveState();
  if(!ok){ doUndo(); return; }

  render();
  toastOk('ล้างข้อมูล', `ล้างโหมด ${m} (พอร์ต ${p}) แล้ว`);
}

async function clearPortfolio(){
  const p = activePortfolio();

  const yes = await confirmBox({
    title:'ล้างข้อมูล',
    text:`ล้างข้อมูลทั้งหมดของพอร์ต “${p}” ใช่ไหม?`,
    confirmText:'ล้าง',
    cancelText:'ยกเลิก'
  });
  if(!yes) return;

  pushUndo();
  state.ledger = state.ledger.filter(e => e.portfolio !== p);

  const ok = await saveState();
  if(!ok){ doUndo(); return; }

  render();
  toastOk('ล้างข้อมูล', `ล้างข้อมูลพอร์ต ${p} แล้ว`);
}

async function clearAll(){
  const yes = await confirmBox({
    title:'ล้างทั้งหมด',
    text:'ต้องการล้างข้อมูลทั้งหมดทุกพอร์ตใช่ไหม?',
    confirmText:'ล้างทั้งหมด',
    cancelText:'ยกเลิก'
  });
  if(!yes) return;

  pushUndo();
  state = {portfolios:['DEFAULT'], activePortfolio:'DEFAULT', ledger:[]};

  const ok = await saveState();
  if(!ok){ doUndo(); return; }

  refreshPortfolioSelects();
  render();
  toastOk('ล้างทั้งหมด', 'ล้างข้อมูลทั้งหมดแล้ว');
}

/* ===== Editing modal ===== */
let editingId = null;

function openEdit(id){
  const item = state.ledger.find(x=>x.id===id);
  if(!item) return;

  editingId = id;
  editPortfolioEl.value = item.portfolio;
  editModeEl.value = item.mode;
  editTypeEl.value = item.type;
  editDirEl.value = item.dir || 'THB2USD';
  editRateEl.value = item.rate ?? '';
  editNoteEl.value = item.note ?? '';
  editTimeEl.value = fmtTime(item.time);

  if(item.mode==='stock') editAmountEl.value = item.usd;
  else editAmountEl.value = (item.dir==='THB2USD') ? item.thb : item.usd;

  syncEditUI();
  modalBack.style.display = 'flex';
}
function closeModal(ev){
  if(ev && ev.target && ev.target !== modalBack) return;
  modalBack.style.display = 'none';
  editingId = null;
}
function syncEditUI(){
  const m = editModeEl.value;
  const isFx = m==='fx';
  editDirWrap.style.display = isFx ? 'block' : 'none';
  editRateWrap.style.display = isFx ? 'block' : 'none';
  editAmountLabel.textContent = isFx ? 'จำนวน (ตามประเภท/ทิศทาง)' : 'จำนวน (USD)';
}
editModeEl.addEventListener('change', syncEditUI);

async function saveEdit(){
  if(!editingId) return;
  const idx = state.ledger.findIndex(x=>x.id===editingId);
  if(idx<0) return;

  const portfolio = editPortfolioEl.value;
  const mode = editModeEl.value;
  const type = editTypeEl.value;

  const amount = parseFloat(editAmountEl.value);
  if(!(amount>0)) return toastErr('แก้ไข', 'กรุณากรอกจำนวนให้ถูกต้อง');

  const note = editNoteEl.value.trim();

  pushUndo();

  if(mode==='stock'){
    state.ledger[idx] = { ...state.ledger[idx], portfolio, mode:'stock', type, usd: amount, note: note || undefined };
  }else{
    const dir = editDirEl.value;
    const rate = parseFloat(editRateEl.value);
    if(!(rate>0)){
      undoStack.pop();
      return toastErr('แก้ไข FX', 'กรุณากรอกอัตราแลกเปลี่ยนให้ถูกต้อง');
    }

    let thb=0, usd=0;
    if(dir==='THB2USD'){ thb = amount; usd = amount / rate; }
    else{ usd = amount; thb = amount * rate; }

    state.ledger[idx] = { ...state.ledger[idx], portfolio, mode:'fx', type, dir, rate, thb, usd, note: note || undefined };
  }

  const ok = await saveState();
  if(!ok){ doUndo(); return; }

  closeModal();
  render();
  toastOk('แก้ไขแล้ว', 'บันทึกการแก้ไขสำเร็จ');
}

async function deleteEditing(){
  if(!editingId) return;

  const yes = await confirmBox({
    title:'ลบรายการ',
    text:'ต้องการลบรายการนี้ใช่ไหม?',
    confirmText:'ลบ',
    cancelText:'ยกเลิก'
  });
  if(!yes) return;

  pushUndo();
  state.ledger = state.ledger.filter(x=>x.id!==editingId);

  const ok = await saveState();
  if(!ok){ doUndo(); return; }

  closeModal();
  render();
  toastOk('ลบแล้ว', 'ลบรายการเรียบร้อย');
}

async function quickDelete(id){
  const yes = await confirmBox({
    title:'ลบรายการ',
    text:'ต้องการลบรายการนี้ใช่ไหม?',
    confirmText:'ลบ',
    cancelText:'ยกเลิก'
  });
  if(!yes) return;

  pushUndo();
  state.ledger = state.ledger.filter(x=>x.id!==id);

  const ok = await saveState();
  if(!ok){ doUndo(); return; }

  render();
  toastOk('ลบแล้ว', 'ลบรายการเรียบร้อย');
}

/* ===== Chart drawing ===== */
function resizeCanvasToDisplaySize(canvas){
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  const w = Math.max(1, Math.floor(rect.width * dpr));
  const h = Math.max(1, Math.floor(rect.height * dpr));
  if(canvas.width !== w || canvas.height !== h){
    canvas.width = w; canvas.height = h;
    return true;
  }
  return false;
}
function getThemeColors(){
  const styles = getComputedStyle(document.body);
  return {
    text: styles.getPropertyValue('--text-main').trim() || '#111',
    muted: styles.getPropertyValue('--text-soft').trim() || '#666',
    border: styles.getPropertyValue('--border').trim() || '#ddd',
    accent1: styles.getPropertyValue('--accent1').trim() || '#2563eb',
    accent2: styles.getPropertyValue('--accent2').trim() || '#38bdf8',
    ok1: styles.getPropertyValue('--ok1').trim() || '#16a34a',
    danger1: styles.getPropertyValue('--danger1').trim() || '#dc2626'
  };
}
function makeSeriesForActive(){
  const p = activePortfolio();
  const mode = modeEl.value;

  const items = state.ledger
    .filter(e=>e.portfolio===p && e.mode===mode)
    .slice()
    .sort((a,b)=>a.time-b.time);

  if(items.length===0) return {mode, labels:[], s1:[], s2:[], s3:[]};

  const labels = [];
  const s1=[], s2=[], s3=[];
  if(mode==='stock'){
    let invest=0, ret=0;
    for(const e of items){
      labels.push(dayjs(e.time).format('MM/DD'));
      if(e.type==='invest') invest += e.usd;
      else ret += e.usd;
      s1.push(invest);
      s2.push(ret);
      s3.push(ret - invest);
    }
    chartNote.textContent = 'เส้น 1: ลงทุนสะสม • เส้น 2: รับกลับสะสม • เส้น 3: กำไรสะสม (USD)';
  }else{
    let thbIn=0, thbOut=0;
    for(const e of items){
      labels.push(dayjs(e.time).format('MM/DD'));
      if(e.dir==='THB2USD') thbIn += e.thb;
      else thbOut += e.thb;
      s1.push(thbIn);
      s2.push(thbOut);
      s3.push(thbOut - thbIn);
    }
    chartNote.textContent = 'เส้น 1: จ่ายสะสม • เส้น 2: ได้รับสะสม • เส้น 3: กำไรสะสม (THB)';
  }

  return {mode, labels, s1, s2, s3};
}
function drawLine(ctx, pts){
  if(pts.length===0) return;
  ctx.beginPath();
  ctx.moveTo(pts[0].x, pts[0].y);
  for(let i=1;i<pts.length;i++) ctx.lineTo(pts[i].x, pts[i].y);
  ctx.stroke();
}
function drawChart(){
  if(!panelCharts.classList.contains('active')) return;

  resizeCanvasToDisplaySize(chartCanvas);
  const ctx = chartCanvas.getContext('2d');
  const {labels, s1, s2, s3} = makeSeriesForActive();
  const colors = getThemeColors();

  ctx.clearRect(0,0,chartCanvas.width, chartCanvas.height);

  const padL = 46, padR = 16, padT = 18, padB = 34;
  const W = chartCanvas.width, H = chartCanvas.height;
  const innerW = W - padL - padR;
  const innerH = H - padT - padB;

  ctx.strokeStyle = colors.border;
  ctx.lineWidth = 1;

  const all = [...s1, ...s2, ...s3].filter(n=>typeof n==='number' && isFinite(n));
  const minV = (all.length? Math.min(...all) : 0);
  const maxV = (all.length? Math.max(...all) : 1);
  const span = (maxV - minV) || 1;

  const lines = 4;
  for(let i=0;i<=lines;i++){
    const y = padT + (innerH * i/lines);
    ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(W-padR, y); ctx.stroke();
  }

  ctx.strokeStyle = colors.text;
  ctx.globalAlpha = 0.35;
  ctx.beginPath();
  ctx.moveTo(padL, padT);
  ctx.lineTo(padL, H-padB);
  ctx.lineTo(W-padR, H-padB);
  ctx.stroke();
  ctx.globalAlpha = 1;

  const N = labels.length;
  const toX = (i)=> padL + (N<=1 ? innerW/2 : innerW * (i/(N-1)));
  const toY = (v)=> padT + innerH * (1 - ((v - minV)/span));

  ctx.fillStyle = colors.muted;
  ctx.font = `${Math.max(11, Math.floor(12*(window.devicePixelRatio||1)))}px system-ui`;
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.fillText(fmt(maxV,2), 6, padT);
  ctx.fillText(fmt(minV,2), 6, padT + innerH);

  if(N>0){
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    const idxs = Array.from(new Set([0, Math.floor((N-1)/2), N-1]));
    for(const i of idxs){
      ctx.fillText(labels[i], toX(i), H-padB+8);
    }
  }else{
    ctx.fillStyle = colors.muted;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('ยังไม่มีข้อมูลสำหรับกราฟ (พอร์ต/โหมดนี้)', W/2, H/2);
    return;
  }

  const mkPts = (arr)=> arr.map((v,i)=>({x:toX(i), y:toY(v)}));

  ctx.lineWidth = 2.5;
  ctx.strokeStyle = colors.accent1;
  drawLine(ctx, mkPts(s1));

  ctx.globalAlpha = 0.9;
  ctx.strokeStyle = colors.ok1;
  drawLine(ctx, mkPts(s2));
  ctx.globalAlpha = 1;

  ctx.globalAlpha = 0.85;
  ctx.strokeStyle = colors.text;
  drawLine(ctx, mkPts(s3));
  ctx.globalAlpha = 1;
}
window.addEventListener('resize', ()=> setTimeout(drawChart, 0));

/* ===== Render ===== */
function render(){
  const p = activePortfolio();
  const mode = modeEl.value;

  if(mode==='stock'){
    let invest=0, ret=0;
    state.ledger.filter(e=>e.portfolio===p && e.mode==='stock').forEach(e=>{
      if(e.type==='invest') invest += e.usd;
      else ret += e.usd;
    });
    const profit = ret - invest;
    const pct = invest ? (profit/invest*100) : 0;

    summaryEl.innerHTML = `
      <h3 style="margin:0 0 8px">สรุปพอร์ต ${escapeHtml(p)} • หุ้น (USD)</h3>
      <p class="mono">เงินลงทุนรวม: <b>${fmt(invest,2)}</b> USD</p>
      <p class="mono">เงินที่ได้รับ: <b>${fmt(ret,2)}</b> USD</p>
      <p class="mono">กำไร / ขาดทุน: <b>${fmt(profit,2)}</b> USD (${fmt(pct,2)}%)</p>
    `;
  }else{
    let thbIn=0, thbOut=0, usdIn=0, usdOut=0;
    state.ledger.filter(e=>e.portfolio===p && e.mode==='fx').forEach(e=>{
      if(e.dir==='THB2USD'){ thbIn += e.thb; usdIn += e.usd; }
      else{ thbOut += e.thb; usdOut += e.usd; }
    });
    const diff = thbOut - thbIn;
    const pct = thbIn ? (diff/thbIn*100) : 0;

    summaryEl.innerHTML = `
      <h3 style="margin:0 0 8px">สรุปพอร์ต ${escapeHtml(p)} • แลกเงิน</h3>
      <p class="mono">จ่ายไป: <b>${fmt(thbIn,2)}</b> บาท (${fmt(usdIn,4)} USD)</p>
      <p class="mono">ได้รับกลับ: <b>${fmt(thbOut,2)}</b> บาท (${fmt(usdOut,4)} USD)</p>
      <p class="mono">กำไร / ขาดทุน: <b>${fmt(diff,2)}</b> บาท (${fmt(pct,2)}%)</p>
    `;
  }

  const start = dayjs('2025-08-21');
  const now = dayjs();
  const d = dayjs.duration(now.diff(start));
  durationEl.innerHTML = `
    <h3 style="margin:0 0 8px">ระยะเวลาการบันทึก</h3>
    <p>${d.years()} ปี ${d.months()} เดือน ${d.days()} วัน</p>
    <small>เริ่มนับจาก 2025-08-21</small>
  `;

  const limit = parseInt(limitEl.value,10);
  const f = filterEl.value;
  const rows = state.ledger
    .filter(e=>e.portfolio===p)
    .filter(e=> f==='all' ? true : e.mode===f)
    .slice()
    .sort((a,b)=>b.time - a.time);

  const show = (limit===9999) ? rows : rows.slice(0, limit);
  historyMeta.textContent = `พอร์ต ${p} • ทั้งหมด ${rows.length} รายการ • แสดง ${show.length} รายการล่าสุด`;

  historyBody.innerHTML = show.map((e)=>{
    const isFx = e.mode==='fx';
    const thb = isFx ? e.thb : null;
    const rate = isFx ? e.rate : null;

    const typeTxt = e.type==='invest' ? 'ลงเงิน' : 'ได้เงิน';
    const dirTxt = isFx ? (e.dir==='THB2USD'?'THB→USD':'USD→THB') : '-';
    const amount = isFx ? (e.dir==='THB2USD' ? e.thb : e.usd) : e.usd;

    return `
      <tr>
        <td class="mono">${fmtTime(e.time)}</td>
        <td>${badge(escapeHtml(e.portfolio))}</td>
        <td>${e.mode==='stock' ? badge('หุ้น') : badge('แลกเงิน')}</td>
        <td>${badge(typeTxt)} ${isFx ? `<small>(${dirTxt})</small>` : ''}</td>
        <td class="right mono">${fmt(amount, 2)}</td>
        <td class="right mono">${thb==null ? '-' : fmt(thb,2)}</td>
        <td class="right mono">${fmt(e.usd, isFx?4:2)}</td>
        <td class="right mono">${rate==null ? '-' : fmt(rate,4)}</td>
        <td class="right">
          <button class="actionBtn outline" onclick="openEdit('${e.id}')" type="button">แก้ไข</button>
          <button class="danger actionBtn" onclick="quickDelete('${e.id}')" type="button">ลบ</button>
        </td>
      </tr>
    `;
  }).join('');

  drawChart();
}

/* ===== Init ===== */
async function init(){
  state = await loadState();

  if(!Array.isArray(state.portfolios) || state.portfolios.length===0){
    state.portfolios = ['DEFAULT'];
    state.activePortfolio = 'DEFAULT';
  }
  if(!state.activePortfolio || !state.portfolios.includes(state.activePortfolio)){
    state.activePortfolio = state.portfolios[0];
  }

  refreshPortfolioSelects();
  portfolioEl.value = state.activePortfolio;

  syncModeUI();

  const last = localStorage.getItem(MAIN_TAB_KEY) || 'home';
  showMainTab(last);

  render();

  // ✅ ไม่บังคับแจ้งทุกครั้ง (กันรบกวน) — ถ้าคุณอยากให้แจ้งเสมอค่อยเปิด
  // const hasData = Array.isArray(state.ledger) && state.ledger.length > 0;
  // if(hasData) toastOk('โหลดข้อมูลแล้ว', 'ดึงข้อมูลจากฐานข้อมูลสำเร็จ');
}
init();
</script>
</body>
</html>