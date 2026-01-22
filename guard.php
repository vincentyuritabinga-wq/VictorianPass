<?php
session_start();
require_once 'connect.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guard') { header('Location: login.php'); exit; }
$email = $_SESSION['email'] ?? '';
$local = explode('@', $email)[0] ?? '';
$s = $local;
if (strpos($local, '_') !== false) { $parts = explode('_', $local); $s = end($parts); }
if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
$s = preg_replace('/[^a-zA-Z]/', '', $s);
$surname = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
$staffId = intval($_SESSION['staff_id'] ?? 0);
$currentLoginId = intval($_SESSION['login_history_id'] ?? 0);
$con->query("CREATE TABLE IF NOT EXISTS login_history (id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT NOT NULL, login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, logout_time DATETIME NULL, INDEX idx_staff_id (staff_id)) ENGINE=InnoDB");
$currentLogin = null;
if ($currentLoginId > 0) {
  $stmt = $con->prepare('SELECT id, staff_id, login_time, logout_time FROM login_history WHERE id = ? LIMIT 1');
  $stmt->bind_param('i', $currentLoginId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && $res->num_rows === 1) { $currentLogin = $res->fetch_assoc(); }
  $stmt->close();
}
$history = [];
if ($staffId > 0) {
  $stmt = $con->prepare('SELECT id, login_time, logout_time FROM login_history WHERE staff_id = ? ORDER BY login_time DESC LIMIT 10');
  $stmt->bind_param('i', $staffId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($res && ($row = $res->fetch_assoc())) { $history[] = $row; }
$stmt->close();
}

// Ensure incident tables and escalation columns exist
$con->query("CREATE TABLE IF NOT EXISTS incident_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  complainant VARCHAR(150) NOT NULL,
  address VARCHAR(255) NOT NULL,
  nature VARCHAR(255) NULL,
  other_concern VARCHAR(255) NULL,
  user_id INT NULL,
  status ENUM('new','in_progress','resolved','rejected','cancelled') DEFAULT 'new',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL,
  INDEX idx_status (status),
  INDEX idx_user_id (user_id)
) ENGINE=InnoDB");
$c1 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'escalated_to_admin'");
if ($c1 && $c1->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status"); }
$c2 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'escalated_by_guard_id'");
if ($c2 && $c2->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN escalated_by_guard_id INT NULL AFTER escalated_to_admin"); }
$c3 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'escalated_at'");
if ($c3 && $c3->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN escalated_at DATETIME NULL AFTER escalated_by_guard_id"); }
// Track guard-handled incidents
$c4 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'handled_by_guard_id'");
if ($c4 && $c4->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN handled_by_guard_id INT NULL AFTER escalated_at"); }
$c5 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'handled_at'");
if ($c5 && $c5->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN handled_at DATETIME NULL AFTER handled_by_guard_id"); }

// Ensure entry_scans table exists
if ($con instanceof mysqli) {
  $con->query("CREATE TABLE IF NOT EXISTS entry_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_code VARCHAR(50) NOT NULL,
    scanned_by_guard_id INT NULL,
    scanned_by_name VARCHAR(150) NULL,
    subject_name VARCHAR(150) NULL,
    entry_type VARCHAR(50) NULL,
    status VARCHAR(50) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ref_code (ref_code),
    INDEX idx_guard (scanned_by_guard_id),
    INDEX idx_scanned_at (scanned_at)
  ) ENGINE=InnoDB");
}

// API: list incidents for guards (not yet escalated)
if (isset($_GET['action']) && $_GET['action'] === 'list_incidents') {
  header('Content-Type: application/json');
  $rows = [];
  $q = "SELECT ir.id, ir.complainant, ir.subject, ir.address, ir.nature, ir.other_concern, ir.created_at, ir.status,
               u.first_name, u.middle_name, u.last_name
        FROM incident_reports ir
        LEFT JOIN users u ON ir.user_id = u.id
        WHERE COALESCE(ir.escalated_to_admin,0) = 0
        ORDER BY ir.created_at DESC";
  $res = $con->query($q);
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $full = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      $rows[] = [
        'id' => intval($r['id']),
        'resident_name' => ($full !== '' ? $full : $r['complainant']),
        'subject' => $r['subject'] ?? '',
        'address' => $r['address'] ?? '',
        'nature' => $r['nature'] ?? ($r['other_concern'] ?? ''),
        'created_at' => $r['created_at'],
        'status' => $r['status']
      ];
    }
  }
  echo json_encode(['success' => true, 'incidents' => $rows]);
  exit;
}

// API: escalate incident to admin
if (isset($_POST['action']) && $_POST['action'] === 'escalate' && isset($_POST['report_id'])) {
  header('Content-Type: application/json');
  $rid = intval($_POST['report_id']);
  $gid = intval($staffId);
  if ($rid > 0 && $gid > 0) {
    $stmt = $con->prepare("UPDATE incident_reports SET escalated_to_admin = 1, escalated_by_guard_id = ?, escalated_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ii', $gid, $rid);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
  } else {
    echo json_encode(['success' => false]);
  }
  exit;
}

// API: mark incident as handled by guard (in progress)
if (isset($_POST['action']) && $_POST['action'] === 'handle' && isset($_POST['report_id'])) {
  header('Content-Type: application/json');
  $rid = intval($_POST['report_id']);
  $gid = intval($staffId);
  if ($rid > 0 && $gid > 0) {
    $stmt = $con->prepare("UPDATE incident_reports SET status = 'in_progress', handled_by_guard_id = ?, handled_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ii', $gid, $rid);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
  } else {
    echo json_encode(['success' => false]);
  }
  exit;
}

// API: list today's entry scans
if (isset($_GET['action']) && $_GET['action'] === 'list_today_scans') {
  header('Content-Type: application/json');
  $rows = [];
  $q = "SELECT e.ref_code, e.scanned_by_name, e.subject_name, e.entry_type, e.status, e.start_date, e.end_date, e.scanned_at
        FROM entry_scans e
        INNER JOIN (
          SELECT ref_code, MAX(scanned_at) AS ms
          FROM entry_scans
          WHERE DATE(scanned_at) = CURDATE()
          GROUP BY ref_code
        ) t ON e.ref_code = t.ref_code AND e.scanned_at = t.ms
        ORDER BY e.scanned_at DESC";
  $res = $con->query($q);
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => $r['subject_name'],
        'type' => $r['entry_type'],
        'start_date' => $r['start_date'],
        'end_date' => $r['end_date'],
        'status' => $r['status'],
        'scanned_by' => $r['scanned_by_name'],
        'scanned_at' => $r['scanned_at']
      ];
    }
  }
  echo json_encode(['success' => true, 'entries' => $rows]);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Victorian Pass | Guard</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
*{font-family:'Poppins',sans-serif;box-sizing:border-box;}
:root{--bg-dark:#2b2623;--nav-cream:#f4efe6;--nav-cream-active:#e8dfca;--accent:#23412e;--header-beige:#f7efe3;--card:#ffffff;--muted:#8b918d;--status-rejected:#e74c3c;--status-approved:#27ae60;--shadow:0 8px 18px rgba(0,0,0,0.08)}
body{margin:0;background:#f3efe9;color:#222;display:flex;min-height:100vh}
.sidebar{width:280px;background:var(--bg-dark);color:#fff;display:flex;flex-direction:column}
.sidebar .brand{padding:20px;border-bottom:3px solid rgba(255,255,255,0.07);display:flex;align-items:center;gap:12px}
.sidebar .brand img{height:52px}
.sidebar .brand .title{display:flex;flex-direction:column}
.sidebar .brand .title h1{margin:0;font-size:1.05rem;font-weight:700;color:#f4f4f4}
.sidebar .brand .title p{margin:0;font-size:0.78rem;color:#d6cfc2}
.nav-list{margin:20px 12px;display:flex;flex-direction:column;gap:12px}
.nav-item{background:var(--nav-cream);color:var(--accent);padding:14px 18px;border-radius:0 20px 20px 0;font-weight:600;font-size:0.96rem;display:flex;align-items:center;gap:12px;cursor:pointer;transition:transform .12s ease,background-color .12s ease}
.nav-item img{width:20px;height:20px}
.nav-item:hover{transform:translateX(4px);background:#efe7d6}
.nav-item.active{background:var(--nav-cream-active);box-shadow:0 6px 14px rgba(0,0,0,0.06)}
.sidebar-footer{margin-top:auto;padding:18px;color:#bfb7aa;font-size:0.84rem}
.sidebar-footer a{color:#bfb7aa;text-decoration:none}
.main-content{flex:1;padding:20px;display:flex;flex-direction:column;gap:20px;background:linear-gradient(180deg,#f7f3ec 0%,#f3efe9 100%)}
.header{display:flex;justify-content:space-between;align-items:center;background:var(--header-beige);padding:12px 20px;border-radius:8px;box-shadow:var(--shadow)}
.header h1{font-size:1.3rem;margin:0;font-weight:700}
.profile-mini{display:flex;align-items:center;gap:10px}
.profile-mini img{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #fff}
.dashboard{display:grid;grid-template-columns:2fr 1fr;gap:20px;flex:1}
.card{background:var(--card);color:#222;border-radius:12px;overflow:hidden;box-shadow:var(--shadow);display:flex;flex-direction:column}
.card-header{background:var(--accent);color:#fff;padding:12px 16px;font-weight:600;font-size:1rem}
.card-body{padding:12px;flex:1;overflow-y:auto}
table{width:100%;border-collapse:collapse;font-size:0.85rem}
th,td{padding:8px 10px;border-bottom:1px solid #f0f0f0;text-align:left}
th{background:#fbfbfb;color:#6b6b6b;font-weight:600}
td img.proof-thumb{width:60px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #ccc}
.action-btn{padding:4px 10px;border:none;border-radius:6px;color:#fff;font-size:0.78rem;cursor:pointer;transition:background .15s ease}
.action-btn.approve{background:var(--status-approved)}
.action-btn.approve:hover{background:#219150}
.action-btn.deny{background:var(--status-rejected)}
.action-btn.deny:hover{background:#c0392b}
.section.hidden{display:none}
.toast{position:fixed;bottom:20px;right:20px;background:var(--accent);color:#fff;padding:10px 16px;border-radius:8px;box-shadow:var(--shadow);opacity:0;transition:opacity .3s ease, transform .3s ease}
.toast.show{opacity:1;transform:translateY(-10px)}
@media(max-width:900px){body{flex-direction:column}.sidebar{flex-direction:row;width:100%;height:auto;overflow-x:auto}.dashboard{grid-template-columns:1fr}}
.history-table th:nth-child(1),.history-table td:nth-child(1){width:40%}
.history-table th:nth-child(2),.history-table td:nth-child(2){width:40%}
.history-table th:nth-child(3),.history-table td:nth-child(3){width:20%}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="brand">
    <img src="images/logo.svg" alt="VictorianPass logo">
    <div class="title">
      <h1>Guard Panel</h1>
      <p>Victorian Heights Subdivision</p>
    </div>
  </div>
  <nav class="nav-list">
    <div class="nav-item active" data-section="dashboard"><img src="images/dashboard.svg"><span>Dashboard</span></div>
    <div class="nav-item" data-section="entries"><img src="images/dashboard.svg"><span>Today's Entry</span></div>
    <div class="nav-item" data-section="restricted"><img src="images/dashboard.svg"><span>Incident Reports</span></div>
    <div class="nav-item" data-section="notifications"><img src="images/dashboard.svg"><span>Notifications</span></div>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php">Log Out</a>
  </div>
</aside>
<div class="main-content">
  <div class="header">
    <h1 id="page-title">Welcome, <?php echo htmlspecialchars($surname); ?></h1>
    <div class="profile-mini">
      <img src="images/logo.svg" alt="Guard">
      <span><?php echo htmlspecialchars($surname); ?></span>
    </div>
  </div>
  <div id="dashboardSection" class="dashboard section">
    <div class="card">
      <div class="card-header">Today's Entry</div>
      <div class="card-body">
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px">
          <input id="scanCode" type="text" placeholder="Scan or enter code" style="flex:1;padding:8px;border:1px solid #ccc;border-radius:6px">
          <button class="action-btn approve" onclick="scanCode()">Scan</button>
          <button class="action-btn" onclick="openStatusCard()" style="background:#23412e">Open QR Card</button>
        </div>
        <table id="entryTable">
          <tr><th>Code</th><th>Name</th><th>Type</th><th>Dates</th><th>Status</th><th>Scanned By</th></tr>
          <tr id="emptyRow"><td colspan="6" style="text-align:center;color:#6b6b6b">Awaiting scans...</td></tr>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Restricted</div>
      <div class="card-body">
        <table>
          <tr><th>IP</th><th>Image</th><th>Name</th><th>Status</th></tr>
          <tr id="restrictedEmpty"><td colspan="4" style="text-align:center;color:#6b6b6b">No restricted entries</td></tr>
        </table>
      </div>
    </div>
  </div>
  <div id="entriesSection" class="section hidden">
    <div class="card">
      <div class="card-header">Today's Entry (Detailed)</div>
      <div class="card-body">
        <table id="todayEntries" class="history-table">
          <tr><th>Code</th><th>Name</th><th>Type</th><th>Dates</th><th>Status</th><th>Scanned By</th><th>Scanned At</th></tr>
          <tbody id="todayEntriesBody">
            <tr id="todayEmpty"><td colspan="7" style="text-align:center;color:#6b6b6b">No scans today</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div id="restrictedSection" class="section hidden">
    <div class="card">
      <div class="card-header">Manage Reported Incidents</div>
      <div class="card-body">
        <table id="incidentTable">
          <thead>
            <tr><th>Report ID</th><th>Resident Name</th><th>Description</th><th>Report Date</th><th>Action</th></tr>
          </thead>
          <tbody id="incidentTableBody">
            <tr id="noIncidents"><td colspan="5" style="text-align:center;color:#6b6b6b">No incidents reported</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div id="notificationsSection" class="section hidden">
    <div class="card">
      <div class="card-header">Notifications</div>
      <div class="card-body">All notifications will appear here.</div>
    </div>
  </div>
  <div class="section" id="historySection">
    <div class="card">
      <div class="card-header">Your Login History</div>
      <div class="card-body">
        <div style="margin-bottom:10px">
          <strong>Current Login:</strong>
          <span>
            <?php echo $currentLogin ? htmlspecialchars($currentLogin['login_time']) : '—'; ?>
          </span>
          <strong style="margin-left:16px">Current Logout:</strong>
          <span>
            <?php echo ($currentLogin && $currentLogin['logout_time']) ? htmlspecialchars($currentLogin['logout_time']) : 'Pending'; ?>
          </span>
        </div>
        <table class="history-table">
          <tr><th>Login Time</th><th>Logout Time</th><th>Status</th></tr>
          <?php if (count($history) === 0) { ?>
            <tr><td colspan="3" style="text-align:center;color:#6b6b6b">No records</td></tr>
          <?php } else { foreach ($history as $h) { $st = ($h['logout_time'] ? 'Completed' : 'Pending'); ?>
            <tr>
              <td><?php echo htmlspecialchars($h['login_time']); ?></td>
              <td><?php echo htmlspecialchars($h['logout_time'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars($st); ?></td>
            </tr>
          <?php } } ?>
        </table>
      </div>
    </div>
  </div>
</div>
<div id="toast" class="toast"></div>
<script>
const navItems = document.querySelectorAll('.nav-item');
const sections = document.querySelectorAll('.section');
const pageTitle = document.getElementById('page-title');
navItems.forEach(item=>{ item.addEventListener('click',()=>{ navItems.forEach(i=>i.classList.remove('active')); item.classList.add('active'); sections.forEach(s=>s.classList.add('hidden')); const target=document.getElementById(item.dataset.section+'Section'); if(target) target.classList.remove('hidden'); pageTitle.textContent=item.querySelector('span').textContent; if(item.dataset.section==='entries'){ loadTodayEntries(); } }); });
function showToast(message, type){ const toast=document.getElementById('toast'); toast.textContent=message; toast.style.background=type==='error'?"var(--status-rejected)":"var(--status-approved)"; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'),2500); }
function scanCode(){ const code=(document.getElementById('scanCode').value||'').trim(); if(!code){ showToast('Enter a code to scan','error'); return; } fetch(`status.php?code=${encodeURIComponent(code)}`).then(r=>r.json()).then(data=>{ if(!data||!data.success){ showToast(data&&data.message?data.message:'Invalid code','error'); return; } showToast('Scan recorded'); loadDashboardEntries(); loadTodayEntries(); }).catch(_=>{ showToast('Network error','error'); }); }
function renderDashboardEntries(rows){ const tbl=document.getElementById('entryTable'); if(!tbl) return; const header=tbl.querySelector('tr'); const rowsToRemove=Array.from(tbl.querySelectorAll('tr')).slice(1); rowsToRemove.forEach(tr=>tr.remove()); if(!rows||rows.length===0){ const tr=document.createElement('tr'); tr.id='emptyRow'; tr.innerHTML=`<td colspan="6" style="text-align:center;color:#6b6b6b">Awaiting scans...</td>`; tbl.appendChild(tr); return; } rows.forEach(r=>{ const tr=document.createElement('tr'); const dateDisplay=(r.start_date&&r.end_date)?`${formatMDY(r.start_date)} → ${formatMDY(r.end_date)}`:(r.start_date?formatMDY(r.start_date):'-'); tr.innerHTML=`<td>${r.code||'-'}</td><td>${r.name||'-'}</td><td>${r.type||'-'}</td><td>${dateDisplay}</td><td>${r.status||'-'}</td><td>${r.scanned_by||'-'}</td>`; tbl.appendChild(tr); }); }
function loadDashboardEntries(){ fetch('guard.php?action=list_today_scans').then(r=>r.json()).then(data=>{ if(data&&data.success){ renderDashboardEntries(data.entries||[]); } }).catch(_=>{}); }
  function openStatusCard(){ const code=(document.getElementById('scanCode').value||'').trim(); if(!code){ showToast('Enter a code first','error'); return; } window.open(`qr_view.php?code=${encodeURIComponent(code)}`,'_blank'); }
// Incident listing & escalation
let lastIncidentIds = new Set();
function renderIncidents(rows){
  const tbody = document.getElementById('incidentTableBody');
  if(!tbody) return;
  tbody.innerHTML = '';
  if(!rows || rows.length===0){
    const tr = document.createElement('tr'); tr.id='noIncidents'; tr.innerHTML = `<td colspan="5" style="text-align:center;color:#6b6b6b">No incidents reported</td>`;
    tbody.appendChild(tr);
    return;
  }
  rows.forEach(r=>{
    const isNew = !lastIncidentIds.has(r.id);
    lastIncidentIds.add(r.id);
    const tr = document.createElement('tr');
    const desc = (r.nature||'').trim();
    const dt = r.created_at ? new Date(r.created_at) : null;
    const dstr = dt ? dt.toLocaleDateString() : '';
    tr.innerHTML = `<td>${r.id}</td><td>${(r.resident_name||'-')}</td><td>${desc||'-'}</td><td>${dstr}</td>
      <td>
        <button class="action-btn handle-btn" data-id="${r.id}" style="background:#23412e">Handle Locally</button>
        <button class="action-btn approve escalate-btn" data-id="${r.id}">Escalate to Admin</button>
      </td>`;
    tbody.appendChild(tr);
    if(isNew){ showToast('New resident incident reported'); }
  });
  // Attach handle locally
  Array.from(tbody.querySelectorAll('button.handle-btn')).forEach(btn=>{
    btn.addEventListener('click', function(){
      const id = parseInt(this.getAttribute('data-id')||'0');
      if(!id) return;
      fetch('guard.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=handle&report_id=${encodeURIComponent(id)}` })
        .then(r=>r.json()).then(data=>{
          if(data&&data.success){ showToast('Incident marked in progress'); loadIncidents(); }
          else { showToast('Failed to update','error'); }
        }).catch(_=>{ showToast('Network error','error'); });
    });
  });
  // Attach escalate handlers
  Array.from(tbody.querySelectorAll('button.escalate-btn')).forEach(btn=>{
    btn.addEventListener('click', function(){
      const id = parseInt(this.getAttribute('data-id')||'0');
      if(!id) return;
      fetch('guard.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=escalate&report_id=${encodeURIComponent(id)}` })
        .then(r=>r.json()).then(data=>{
          if(data&&data.success){ showToast('Incident escalated to admin'); loadIncidents(); }
          else { showToast('Failed to escalate','error'); }
        }).catch(_=>{ showToast('Network error','error'); });
    });
  });
}
function loadIncidents(){ fetch('guard.php?action=list_incidents').then(r=>r.json()).then(data=>{ if(data&&data.success){ renderIncidents(data.incidents||[]); } }).catch(_=>{}); }
document.addEventListener('DOMContentLoaded', function(){ loadIncidents(); setInterval(loadIncidents, 15000); });
function renderTodayEntries(rows){ const tbody=document.getElementById('todayEntriesBody'); if(!tbody) return; tbody.innerHTML=''; if(!rows||rows.length===0){ const tr=document.createElement('tr'); tr.id='todayEmpty'; tr.innerHTML=`<td colspan="7" style="text-align:center;color:#6b6b6b">No scans today</td>`; tbody.appendChild(tr); return; } rows.forEach(r=>{ const tr=document.createElement('tr'); const dateDisplay=(r.start_date&&r.end_date)?`${formatMDY(r.start_date)} → ${formatMDY(r.end_date)}`:(r.start_date?formatMDY(r.start_date):'-'); const sat=r.scanned_at?formatDateTime(r.scanned_at):''; tr.innerHTML=`<td>${r.code||'-'}</td><td>${r.name||'-'}</td><td>${r.type||'-'}</td><td>${dateDisplay}</td><td>${r.status||'-'}</td><td>${r.scanned_by||'-'}</td><td>${sat}</td>`; tbody.appendChild(tr); }); }
function formatMDY(ymd){ try{ const d=new Date(ymd); return `${(d.getMonth()+1).toString().padStart(2,'0')}/${d.getDate().toString().padStart(2,'0')}/${String(d.getFullYear()).slice(-2)}`; }catch(e){ return ymd; } }
function formatDateTime(dt){ try{ const d=new Date(dt); const mm=(d.getMonth()+1).toString().padStart(2,'0'); const dd=d.getDate().toString().padStart(2,'0'); const yy=String(d.getFullYear()).slice(-2); const hh=d.getHours().toString().padStart(2,'0'); const mi=d.getMinutes().toString().padStart(2,'0'); return `${mm}/${dd}/${yy} ${hh}:${mi}`; }catch(e){ return dt; } }
function loadTodayEntries(){ fetch('guard.php?action=list_today_scans').then(r=>r.json()).then(data=>{ if(data&&data.success){ renderTodayEntries(data.entries||[]); } }).catch(_=>{}); }
document.addEventListener('DOMContentLoaded', function(){
  loadTodayEntries();
  loadDashboardEntries();
  const inp = document.getElementById('scanCode');
  if (inp) {
    inp.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ scanCode(); } });
  }
  setInterval(loadTodayEntries, 60000);
  setInterval(loadDashboardEntries, 60000);
});
</script>
<script src="js/logout-modal.js"></script>
</body>
</html>
