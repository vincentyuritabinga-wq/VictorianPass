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

if (isset($_GET['action']) && $_GET['action'] === 'incident_details' && isset($_GET['id'])) {
  header('Content-Type: application/json');
  $rid = intval($_GET['id']);
  $data = null;
  $files = [];
  if ($rid > 0) {
    $stmt = $con->prepare("SELECT ir.id, ir.complainant, ir.subject, ir.address, ir.nature, ir.other_concern, ir.report_date, ir.created_at, ir.status, u.first_name, u.middle_name, u.last_name FROM incident_reports ir LEFT JOIN users u ON ir.user_id = u.id WHERE ir.id = ? LIMIT 1");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
      $r = $res->fetch_assoc();
      $full = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      $data = [
        'id' => intval($r['id']),
        'resident_name' => ($full !== '' ? $full : $r['complainant']),
        'subject' => $r['subject'] ?? '',
        'address' => $r['address'] ?? '',
        'nature' => $r['nature'] ?? '',
        'other_concern' => $r['other_concern'] ?? '',
        'report_date' => $r['report_date'] ?? null,
        'created_at' => $r['created_at'] ?? null,
        'status' => $r['status'] ?? ''
      ];
    }
    $stmt->close();
    $stmtP = $con->prepare("SELECT file_path FROM incident_proofs WHERE report_id = ? ORDER BY uploaded_at ASC");
    $stmtP->bind_param('i', $rid);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    if ($resP) { while ($rowP = $resP->fetch_assoc()) { $files[] = $rowP['file_path']; } }
    $stmtP->close();
  }
  echo json_encode(['success' => ($data !== null), 'report' => $data, 'proofs' => $files]);
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
<?php
if (isset($_GET['action']) && $_GET['action'] === 'list_expected') {
  header('Content-Type: application/json');
  $startParam = isset($_GET['start']) ? $_GET['start'] : null;
  $endParam = isset($_GET['end']) ? $_GET['end'] : null;
  $start = $startParam ? date('Y-m-d', strtotime($startParam)) : date('Y-m-d');
  $end = $endParam ? date('Y-m-d', strtotime($endParam)) : date('Y-m-d', strtotime('+7 day'));
  $rows = [];
  $normalize = function($v){
    if(!$v) return null;
    $formats = [
      'Y-m-d','m/d/Y','d/m/Y','M d, Y','F d, Y','Y/m/d','d-m-Y',
      'Y-m-d H:i:s','m/d/Y H:i:s','d/m/Y H:i:s','d-m-Y H:i:s'
    ];
    foreach($formats as $fmt){
      $dt = DateTime::createFromFormat($fmt, trim($v));
      if($dt) return $dt->format('Y-m-d');
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d',$ts) : null;
  };
  $resGF = $con->query("SELECT ref_code, visitor_first_name, visitor_middle_name, visitor_last_name, visit_date, start_date, end_date, TRIM(approval_status) AS approval_status, approval_date FROM guest_forms WHERE LOWER(TRIM(approval_status))='approved'");
  if ($resGF) {
    while ($r = $resGF->fetch_assoc()) {
      $nm = trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? ''));
      $sd = $normalize($r['visit_date'] ?? '') ?: $normalize($r['start_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if(!$sd) continue;
      if($ed < $start || $sd > $end) continue;
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => ($nm !== '' ? $nm : '-'),
        'type' => 'Guest Entry',
        'start_date' => $sd,
        'end_date' => $ed,
        'status' => $r['approval_status']
      ];
    }
  }
  $resR = $con->query("SELECT r.ref_code, r.start_date, r.end_date, r.approval_date, TRIM(COALESCE(r.approval_status, r.status)) AS status, r.entry_pass_id, e.full_name AS ep_full_name, u.first_name, u.middle_name, u.last_name, gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name FROM reservations r LEFT JOIN entry_passes e ON r.entry_pass_id = e.id LEFT JOIN users u ON r.user_id = u.id LEFT JOIN guest_forms gf ON r.ref_code = gf.ref_code WHERE LOWER(TRIM(COALESCE(r.approval_status, r.status)))='approved'");
  if ($resR) {
    while ($r = $resR->fetch_assoc()) {
      $sd = $normalize($r['start_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if(!$sd) continue;
      if($ed < $start || $sd > $end) continue;
      $full = trim(($r['ep_full_name'] ?? '')) ?: trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? '')) ?: trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => $full,
        'type' => ($r['entry_pass_id'] ? 'Visitor Reservation' : 'Resident Reservation'),
        'start_date' => $sd,
        'end_date' => $ed,
        'status' => $r['status']
      ];
    }
  }
  $resRR = $con->query("SELECT rr.ref_code, rr.start_date, rr.end_date, rr.approval_date, rr.approval_status, u.first_name, u.middle_name, u.last_name FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id WHERE LOWER(rr.approval_status)='approved'");
  if ($resRR) {
    while ($r = $resRR->fetch_assoc()) {
      $sd = $normalize($r['start_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if(!$sd) continue;
      if($ed < $start || $sd > $end) continue;
      $full = trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => $full,
        'type' => 'Resident Reservation',
        'start_date' => $sd,
        'end_date' => $ed,
        'status' => $r['approval_status']
      ];
    }
  }
  echo json_encode(['success' => true, 'entries' => $rows, 'range' => ['start'=>$start, 'end'=>$end]]);
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
/* Modern Admin Dashboard CSS (Imported from admin.php) */
:root {
    /* Color Palette */
    --primary: #23412e;
    --primary-dark: #1a3022;
    --primary-light: #e8f5e9;
    --accent: #d4af37;
    
    --bg-body: #f4f6f8;
    --bg-surface: #ffffff;
    --bg-sidebar: #2b2623;
    
    --text-main: #2c3e50;
    --text-secondary: #5a6b7c;
    --text-muted: #95a5a6;
    
    --border: #e2e8f0;
    --border-light: #f1f5f9;
    
    /* Status Colors */
    --success: #27ae60;
    --success-bg: #e8f8f5;
    --warning: #f39c12;
    --warning-bg: #fef9e7;
    --danger: #c0392b;
    --danger-bg: #fdedec;
    --info: #2980b9;
    --info-bg: #ebf5fb;
    
    /* Shadows & Transitions */
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --transition: all 0.2s ease-in-out;
    
    --radius: 8px;
    --sidebar-width: 280px;
    --header-height: 68px;
}

/* Reset & Base */
* { box-sizing: border-box; }
body, button, input, select, textarea { font-family: 'Poppins', sans-serif; }
*:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

body {
    margin: 0;
    padding: 0;
    font-family: 'Poppins', sans-serif;
    background-color: var(--bg-body);
    color: var(--text-main);
    overflow-x: hidden;
    line-height: 1.5;
}

a { text-decoration: none; color: inherit; transition: var(--transition); }
ul { list-style: none; padding: 0; margin: 0; }
h1, h2, h3, h4, h5, h6 { margin: 0; font-weight: 600; color: var(--text-main); }

/* Layout Structure */
.app {
    display: flex;
    min-height: 100vh;
    gap: 0;
}

/* Sidebar */
.sidebar {
    width: var(--sidebar-width);
    background: radial-gradient(circle at top left, #3a332f 0%, #2b2623 55%, #211b18 100%);
    color: #f4efe6;
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
    z-index: 100;
    flex-shrink: 0;
}

.brand {
    padding: 22px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}

.brand img { width: 36px; height: 36px; }
.brand .title { display: flex; flex-direction: column; }
.brand h1 { font-size: 1rem; color: #f4efe6; line-height: 1.2; }
.brand p { font-size: 0.75rem; color: rgba(255,255,255,0.7); margin: 0; }

.nav-list {
    padding: 18px 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.nav-item {
    padding: 12px 18px;
    border-radius: 12px;
    color: rgba(255,255,255,0.78);
    font-weight: 500;
    font-size: 0.95rem;
    line-height: 1.2;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: var(--transition);
    cursor: pointer;
}

.nav-item:hover, .nav-item.active {
    background: rgba(255,255,255,0.1);
    color: #fff;
    font-weight: 600;
}

.nav-item.active {
    box-shadow: inset 3px 0 0 var(--accent);
}

.nav-item img {
    width: 20px;
    height: 20px;
    object-fit: contain;
    filter: brightness(0) invert(1) opacity(0.7);
    transition: var(--transition);
}

.nav-item:hover img, .nav-item.active img {
    opacity: 1;
}

.sidebar-footer {
    margin-top: auto;
    padding: 18px 20px 22px;
    border-top: 1px solid rgba(255,255,255,0.08);
}

.sidebar-footer .text-muted-link {
    color: #fff;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 0.9rem;
    background: #c0392b;
    padding: 10px 12px;
    border-radius: 10px;
    text-decoration: none;
}
.sidebar-footer .text-muted-link:hover { background: #a93226; color: #fff; }
.sidebar-footer .text-muted-link svg { width: 18px; height: 18px; flex-shrink: 0; }

/* Main Content Area */
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    background: var(--bg-body);
}

/* Top Header */
.top-header {
    height: var(--header-height);
    padding: 0 28px;
    background: radial-gradient(circle at top left, #3a332f 0%, #2b2623 55%, #211b18 100%);
    border-bottom: 1px solid #1a1512;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    position: sticky;
    top: 0;
    z-index: 90;
    color: #fff;
}

.header-brand, .header-actions {
    display: flex;
    align-items: center;
    gap: 15px;
}

.avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255,255,255,0.2);
    cursor: pointer;
    transition: var(--transition);
}
.avatar:hover { border-color: var(--accent); }

/* Page Header */
.page-header {
    padding: 18px 30px 6px;
    display: flex;
    justify-content: flex-start;
    align-items: center;
    margin-bottom: 10px;
}

.page-header h2 { font-size: 1.5rem; color: var(--text-main); }

/* Dashboard Widgets & Panels */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    padding: 0 30px;
    margin-bottom: 30px;
}

.panel, .card, .card-box {
    background: var(--bg-surface);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border);
    margin: 0 30px 30px 30px;
    overflow-x: auto;
}

.panel h3, .card-header, .card-box h3 {
    margin: 0 0 20px 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-main);
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 15px;
    background: transparent;
}

.panel .card-box {
    box-shadow: none;
    border: none;
    padding: 0;
    margin: 0;
    background: transparent;
}

/* Tables */
table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 800px; }
th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border-light); font-size: 0.9rem; vertical-align: middle; }
th {
    font-weight: 600;
    color: var(--text-secondary);
    background: #f8fafc;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.6px;
    position: sticky;
    top: 0;
    z-index: 10;
}
tr:last-child td { border-bottom: none; }
tr:hover { background-color: #f8fafc; }

/* Buttons */
.action-btn, .btn {
    padding: 8px 14px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 500;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    line-height: 1;
    color: #fff;
}
.action-btn.approve, .btn-approve { background: var(--success); }
.action-btn.deny, .btn-reject { background: var(--danger); }
.action-btn:hover { filter: brightness(92%); transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.btn-view { background: var(--info); color: #fff; }
.btn-view:hover { background: #2563eb; }

/* Guard Specific Adapters */
.section.hidden { display: none; }
.dashboard { display: grid; grid-template-columns: 1fr; gap: 24px; padding: 0 30px; margin-bottom: 30px; }

/* Toast */
.toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--bg-surface);
    border-left: 5px solid var(--primary);
    box-shadow: var(--shadow-lg);
    border-radius: 8px;
    padding: 16px;
    width: min(96vw, 380px);
    z-index: 2000;
    opacity: 0;
    transition: opacity 0.3s ease, transform 0.3s ease;
    transform: translateY(20px);
    color: var(--text-main);
}
.toast.show { opacity: 1; transform: translateY(0); }

/* Responsive */
@media(max-width:900px){
    .app { flex-direction: column; }
    .sidebar { width: 100%; height: auto; overflow-x: auto; flex-direction: row; }
    .dashboard { grid-template-columns: 1fr; }
    .nav-list { flex-direction: row; }
    .top-header { padding: 12px 18px; height: auto; }
    .page-header { padding: 16px 20px 6px; }
}
.dashboard .panel { margin: 0; }

.scan-search {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 8px 15px;
    width: 100%;
    outline: none;
    transition: var(--transition);
    color: var(--text-main);
}
.scan-search:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(35, 65, 46, 0.1);
}
.profile-mini {
    display: flex;
    align-items: center;
    gap: 12px;
    height: 100%;
}
.profile-mini img {
    margin: 0;
    display: block;
}
.profile-mini span {
    font-weight: 500;
    line-height: 1;
    display: block;
}
.logout-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #c0392b;
    color: #fff;
    border-radius: 10px;
    padding: 10px 12px;
    text-decoration: none;
    font-weight: 600;
    transition: background 0.2s ease;
}
.logout-btn:hover { background: #a93226; color: #fff; }
.modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
.modal.modal-top { z-index: 3000; }
.modal-content { background-color: var(--bg-surface); margin: 0; padding: 0; border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow-lg); position: relative; display: flex; flex-direction: column; gap: 12px; width: min(92vw, 640px); aspect-ratio: auto; max-height: 90vh; overflow: hidden; }
.modal-content h3 { padding: 12px 16px; border-bottom: 1px solid var(--border-light); margin: 0; font-size: 1.05rem; background: var(--bg-surface); position: sticky; top: 0; z-index: 10; color: #23412e; font-weight: 700; }
.modal-close { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; background: #ffffff; color: #111827; border: 1px solid #333; font-size: 20px; cursor: pointer; line-height: 1; box-shadow: 0 2px 5px rgba(0,0,0,0.1); transition: transform 0.2s ease, color 0.2s ease, border-color 0.2s ease; }
.modal-close:hover { transform: translateY(-1px); color: #0f172a; border-color: #111827; }
.modal-header { display: flex; justify-content: space-between; align-items: center; }
.incident-details-content { overflow-y: auto; flex: 1; padding: 18px 20px 22px; }
.details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.proofs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.proofs img { width: 120px; height: 90px; object-fit: cover; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; }
.proofs a { display: inline-block; padding: 6px 10px; background: #f8fafc; border: 1px solid var(--border); border-radius: 6px; font-size: 0.85rem; }
</style>
</head>
<body>
<div class="app">
<aside class="sidebar">
  <div class="brand">
    <img src="images/logo.svg" alt="VictorianPass logo">
    <div class="title">
      <h1>Guard Panel</h1>
      <p>Victorian Heights Subdivision</p>
    </div>
  </div>
  <nav class="nav-list">
    <div class="nav-item" data-section="dashboard"><img src="images/dashboard.svg"><span>Dashboard</span></div>
    <div class="nav-item active" data-section="expected"><img src="images/dashboard.svg"><span>Scheduled Arrivals</span></div>
    <div class="nav-item" data-section="entries"><img src="images/dashboard.svg"><span>Today's Entry</span></div>
    <div class="nav-item" data-section="restricted"><img src="images/dashboard.svg"><span>Incident Reports</span></div>
    <div class="nav-item" data-section="notifications"><img src="images/dashboard.svg"><span>Notifications</span></div>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php" class="text-muted-link">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16 17v-2H7v-6h9V7l5 5-5 5zm-11 3h8v2H5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8v2H5v16z"/></svg>
      <span>Log Out</span>
    </a>
  </div>
</aside>
<main class="main">
  <header class="top-header">
    <div class="header-brand">
       <!-- Placeholder for future header items -->
    </div>
    <div class="header-actions">
      <div class="profile-mini">
        <img src="images/logo.svg" alt="Guard" class="avatar">
        <span style="colo r:white; margin-top: 2px;"><?php echo htmlspecialchars($surname); ?></span>
      </div>
    </div>
  </header>
  <div class="page-header">
    <h2 id="page-title">Scheduled Arrivals</h2>
  </div>
  <div id="dashboardSection" class="dashboard section hidden">
    <div class="panel">
      <h3>Today's Entry</h3>
      <div style="display:flex; gap:15px; align-items:center; margin-bottom:25px; flex-wrap:wrap;">
        <div style="flex:1; min-width:250px;">
             <input id="scanCode" type="text" class="scan-search" placeholder="Scan or enter code...">
        </div>
        <div style="display:flex; gap:10px;">
            <button class="btn btn-approve" onclick="scanCode()">Scan</button>
            <button class="btn" onclick="openStatusCard()" style="background:#23412e;color:#fff">Open QR Card</button>
        </div>
      </div>
      <div class="content-row">
      <table id="entryTable">
        <tr><th>Code</th><th>Name</th><th>Type</th><th>Dates</th><th>Status</th><th>Scanned By</th></tr>
        <tr id="emptyRow"><td colspan="6" style="text-align:center;color:#6b6b6b">Awaiting scans...</td></tr>
      </table>
    </div>
    </div>
    <div class="panel">
      <h3>Restricted</h3>
      <table>
        <tr><th>IP</th><th>Image</th><th>Name</th><th>Status</th></tr>
        <tr id="restrictedEmpty"><td colspan="4" style="text-align:center;color:#6b6b6b">No restricted entries</td></tr>
      </table>
    </div>
  </div>
  <div id="entriesSection" class="section hidden">
    <div class="panel">
      <h3>Today's Entry (Detailed)</h3>
      <table id="todayEntries" class="history-table">
        <tr><th>Code</th><th>Name</th><th>Type</th><th>Dates</th><th>Status</th><th>Scanned By</th><th>Scanned At</th></tr>
        <tbody id="todayEntriesBody">
          <tr id="todayEmpty"><td colspan="7" style="text-align:center;color:#6b6b6b">No scans today</td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <div id="expectedSection" class="section">
    <div class="panel">
      <div style="display:flex;gap:12px;align-items:center;margin:0 30px 12px 30px">
        <button class="btn" id="thisWeekBtn" style="background:var(--accent)">This Week</button>
        <button class="btn" id="weekFromStartBtn" style="background:#23412e">Next 7 Days</button>
        <button class="btn" id="next30Btn" style="background:#6b7280">Next 30 Days</button>
        <input type="date" id="expectedStart" style="margin-left:auto">
        <input type="date" id="expectedEnd">
        <button class="btn btn-view" id="applyExpected">Custom Range</button>
      </div>
      <table id="expectedTable" class="history-table">
        <tr><th>Code</th><th>Name</th><th>Type</th><th>Dates</th><th>Status</th></tr>
        <tbody id="expectedBody">
          <tr id="expectedEmpty"><td colspan="5" style="text-align:center;color:#6b6b6b">No scheduled arrivals in selected range</td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <div id="restrictedSection" class="section hidden">
    <div class="panel">
      <h3>Manage Reported Incidents</h3>
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
  <div id="notificationsSection" class="section hidden">
    <div class="panel">
      <h3>Notifications</h3>
      <div>All notifications will appear here.</div>
    </div>
  </div>
  <div class="section" id="historySection">
    <div class="panel">
      <h3>Your Login History</h3>
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
</main>
</div>
<div id="toast" class="toast"></div>
<script>
const navItems = document.querySelectorAll('.nav-item');
const sections = document.querySelectorAll('.section');
const pageTitle = document.getElementById('page-title');
navItems.forEach(item=>{ item.addEventListener('click',()=>{ navItems.forEach(i=>i.classList.remove('active')); item.classList.add('active'); sections.forEach(s=>s.classList.add('hidden')); const target=document.getElementById(item.dataset.section+'Section'); if(target) target.classList.remove('hidden'); const ph=document.querySelector('.page-header'); if(ph){ ph.style.display = ''; } pageTitle.textContent=item.querySelector('span').textContent; if(item.dataset.section==='entries'){ loadTodayEntries(); } if(item.dataset.section==='expected'){ const s=document.getElementById('expectedStart'); const e=document.getElementById('expectedEnd'); const sv=s&&s.value?s.value:formatInputDate(new Date()); const ev=e&&e.value?e.value:formatInputDate(new Date(Date.now()+7*24*60*60*1000)); loadExpected(sv,ev); } }); });
function showToast(message, type){ const toast=document.getElementById('toast'); toast.textContent=message; toast.style.background=type==='error'?"var(--status-rejected)":"var(--status-approved)"; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'),2500); }
function scanCode(){
  const raw=(document.getElementById('scanCode').value||'').trim();
  if(!raw){ showToast('Enter a code to scan','error'); return; }
  const basePath = window.location.pathname.replace(/\/[^\/]*$/, '');
  let codeForLog = raw;
  let openUrl = `${location.origin}${basePath}/qr_view.php?code=${encodeURIComponent(raw)}`;
  let parsedUrl = null;
  try { parsedUrl = new URL(raw); } catch(_){}
  if(parsedUrl){
    const codeParam = parsedUrl.searchParams.get('code');
    const ridParam = parsedUrl.searchParams.get('rid');
    if(codeParam){
      codeForLog = codeParam;
      openUrl = `${location.origin}${basePath}/qr_view.php?code=${encodeURIComponent(codeParam)}`;
    } else if(ridParam && parsedUrl.pathname.indexOf('resident_qr_view.php') !== -1){
      codeForLog = raw;
      openUrl = parsedUrl.href;
    }
  } else {
    const codeMatch = raw.match(/[?&]code=([^&]+)/i);
    const ridMatch = raw.match(/[?&]rid=(\d+)/i);
    if(codeMatch && codeMatch[1]){
      const codeParam = decodeURIComponent(codeMatch[1]);
      codeForLog = codeParam;
      openUrl = `${location.origin}${basePath}/qr_view.php?code=${encodeURIComponent(codeParam)}`;
    } else if(ridMatch && ridMatch[1]){
      codeForLog = raw;
      openUrl = `${location.origin}${basePath}/resident_qr_view.php?rid=${encodeURIComponent(ridMatch[1])}`;
    }
  }
  fetch(`status.php?code=${encodeURIComponent(codeForLog)}`)
    .then(r=>r.json())
    .then(data=>{
      if(!data||!data.success){ showToast(data&&data.message?data.message:'Invalid code','error'); return; }
      showToast('Scan recorded');
      loadDashboardEntries();
      loadTodayEntries();
      const win = window.open(openUrl,'_blank');
      if(!win){ window.location.href = openUrl; }
    })
    .catch(_=>{ showToast('Network error','error'); });
}
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
        <button class="btn btn-view details-btn" data-id="${r.id}">View Details</button>
        <button class="btn handle-btn" data-id="${r.id}" style="background:#23412e;color:#fff">Handle Locally</button>
        <button class="btn btn-approve escalate-btn" data-id="${r.id}">Escalate to Admin</button>
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
  Array.from(tbody.querySelectorAll('button.details-btn')).forEach(btn=>{
    btn.addEventListener('click', function(){
      const id = parseInt(this.getAttribute('data-id')||'0');
      if(!id) return;
      fetch(`guard.php?action=incident_details&id=${encodeURIComponent(id)}`).then(r=>r.json()).then(data=>{
        if(data&&data.success){ showIncidentDetailsModal(data.report, data.proofs||[]); }
        else { showToast('Unable to load details','error'); }
      }).catch(_=>{ showToast('Network error','error'); });
    });
  });
}
function loadIncidents(){ fetch('guard.php?action=list_incidents').then(r=>r.json()).then(data=>{ if(data&&data.success){ renderIncidents(data.incidents||[]); } }).catch(_=>{}); }
document.addEventListener('DOMContentLoaded', function(){ loadIncidents(); setInterval(loadIncidents, 15000); });
function renderTodayEntries(rows){ const tbody=document.getElementById('todayEntriesBody'); if(!tbody) return; tbody.innerHTML=''; if(!rows||rows.length===0){ const tr=document.createElement('tr'); tr.id='todayEmpty'; tr.innerHTML=`<td colspan="7" style="text-align:center;color:#6b6b6b">No scans today</td>`; tbody.appendChild(tr); return; } rows.forEach(r=>{ const tr=document.createElement('tr'); const dateDisplay=(r.start_date&&r.end_date)?`${formatMDY(r.start_date)} → ${formatMDY(r.end_date)}`:(r.start_date?formatMDY(r.start_date):'-'); const sat=r.scanned_at?formatDateTime(r.scanned_at):''; tr.innerHTML=`<td>${r.code||'-'}</td><td>${r.name||'-'}</td><td>${r.type||'-'}</td><td>${dateDisplay}</td><td>${r.status||'-'}</td><td>${r.scanned_by||'-'}</td><td>${sat}</td>`; tbody.appendChild(tr); }); }
function formatMDY(ymd){ try{ const d=new Date(ymd); return `${(d.getMonth()+1).toString().padStart(2,'0')}.${d.getDate().toString().padStart(2,'0')}.${String(d.getFullYear()).slice(-2)}`; }catch(e){ return ymd; } }
function formatDateTime(dt){ try{ const d=new Date(dt); const mm=(d.getMonth()+1).toString().padStart(2,'0'); const dd=d.getDate().toString().padStart(2,'0'); const yy=String(d.getFullYear()).slice(-2); let h=d.getHours(); const mi=d.getMinutes().toString().padStart(2,'0'); const ap=h>=12?'PM':'AM'; h=h%12; if(h===0) h=12; return `${mm}.${dd}.${yy} ${h}:${mi} ${ap}`; }catch(e){ return dt; } }
function formatDateValue(v){ if(!v) return ''; try{ const d=new Date(v); if(isNaN(d.getTime())) return v; const mm=(d.getMonth()+1).toString().padStart(2,'0'); const dd=d.getDate().toString().padStart(2,'0'); const yy=String(d.getFullYear()).slice(-2); const hasTime=String(v).match(/\d{1,2}:\d{2}/); if(hasTime){ let h=d.getHours(); const mi=d.getMinutes().toString().padStart(2,'0'); const ap=h>=12?'PM':'AM'; h=h%12; if(h===0) h=12; return `${mm}.${dd}.${yy} ${h}:${mi} ${ap}`; } return `${mm}.${dd}.${yy}`; }catch(e){ return v; } }
function loadTodayEntries(){ fetch('guard.php?action=list_today_scans').then(r=>r.json()).then(data=>{ if(data&&data.success){ renderTodayEntries(data.entries||[]); } }).catch(_=>{}); }
function renderExpected(rows){ const tbody=document.getElementById('expectedBody'); if(!tbody) return; tbody.innerHTML=''; if(!rows||rows.length===0){ const tr=document.createElement('tr'); tr.id='expectedEmpty'; tr.innerHTML=`<td colspan="5" style="text-align:center;color:#6b6b6b">No scheduled arrivals in selected range</td>`; tbody.appendChild(tr); return; } rows.forEach(r=>{ const tr=document.createElement('tr'); const dateDisplay=(r.start_date&&r.end_date)?`${formatMDY(r.start_date)} → ${formatMDY(r.end_date)}`:(r.start_date?formatMDY(r.start_date):'-'); const st=String(r.status||'').replace(/[_-]+/g,' '); const sts=st.replace(/\b\w/g,function(m){return m.toUpperCase();}); tr.innerHTML=`<td>${r.code||'-'}</td><td>${r.name||'-'}</td><td>${r.type||'-'}</td><td>${dateDisplay}</td><td>${sts}</td>`; tbody.appendChild(tr); }); }
function formatInputDate(d){ const z=new Date(d); return z.toISOString().slice(0,10); }
function getRange(){ const s=document.getElementById('expectedStart'); const e=document.getElementById('expectedEnd'); const sv=s&&s.value?s.value:formatInputDate(new Date()); const ev=e&&e.value?e.value:formatInputDate(new Date(Date.now()+7*24*60*60*1000)); return {start:sv,end:ev}; }
function loadExpected(start,end){ const rng = start&&end ? {start,end} : getRange(); const url = `guard.php?action=list_expected&start=${encodeURIComponent(rng.start)}&end=${encodeURIComponent(rng.end)}`; fetch(url).then(r=>r.json()).then(data=>{ if(data&&data.success){ renderExpected(data.entries||[]); } }).catch(_=>{}); }
document.addEventListener('DOMContentLoaded', function(){
  loadTodayEntries();
  loadDashboardEntries();
  const s=document.getElementById('expectedStart'); const e=document.getElementById('expectedEnd'); if(s&&e){ const today=new Date(); const next=new Date(Date.now()+7*24*60*60*1000); s.value=formatInputDate(today); e.value=formatInputDate(next); }
  const apply=document.getElementById('applyExpected'); if(apply){ apply.addEventListener('click', function(){ const rng=getRange(); loadExpected(rng.start,rng.end); }); }
  const wk=document.getElementById('weekFromStartBtn'); if(wk){ wk.addEventListener('click', function(){ const base=new Date(); const end=new Date(base.getTime()); end.setDate(end.getDate()+6); const sv=formatInputDate(base); const ev=formatInputDate(end); const sIn=document.getElementById('expectedStart'); const eIn=document.getElementById('expectedEnd'); if(sIn) sIn.value=sv; if(eIn) eIn.value=ev; loadExpected(sv,ev); }); }
  const tw=document.getElementById('thisWeekBtn'); if(tw){ tw.addEventListener('click', function(){ const now=new Date(); const start=new Date(now.getTime()); start.setDate(start.getDate()-start.getDay()); const end=new Date(start.getTime()); end.setDate(end.getDate()+6); const sv=formatInputDate(start); const ev=formatInputDate(end); const sIn=document.getElementById('expectedStart'); const eIn=document.getElementById('expectedEnd'); if(sIn) sIn.value=sv; if(eIn) eIn.value=ev; loadExpected(sv,ev); }); }
  const n30=document.getElementById('next30Btn'); if(n30){ n30.addEventListener('click', function(){ const base=new Date(); const end=new Date(base.getTime()); end.setDate(end.getDate()+29); const sv=formatInputDate(base); const ev=formatInputDate(end); const sIn=document.getElementById('expectedStart'); const eIn=document.getElementById('expectedEnd'); if(sIn) sIn.value=sv; if(eIn) eIn.value=ev; loadExpected(sv,ev); }); }
  if(s&&e){ s.addEventListener('change', function(){ if(!s.value) return; const base=new Date(s.value); const end=new Date(base.getTime()); end.setDate(end.getDate()+6); e.value=formatInputDate(end); }); }
  loadExpected();
  const inp = document.getElementById('scanCode');
  if (inp) {
    inp.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ scanCode(); } });
  }
  setInterval(loadTodayEntries, 60000);
  setInterval(loadDashboardEntries, 60000);
  setInterval(function(){ const rng=getRange(); loadExpected(rng.start,rng.end); }, 60000);
});
function showIncidentDetailsModal(report, proofs){
  var m = document.getElementById('incidentDetailsModal');
  if(!m || !report) return;
  document.getElementById('incResident').textContent = report.resident_name || '-';
  document.getElementById('incSubject').textContent = report.subject || '-';
  document.getElementById('incAddress').textContent = report.address || '-';
  var n = report.nature || '';
  var o = report.other_concern || '';
  document.getElementById('incNature').textContent = n ? n : (o || '-');
  var rd = report.report_date || report.created_at || '';
  document.getElementById('incDate').textContent = rd ? formatDateValue(rd) : '-';
  document.getElementById('incStatus').textContent = report.status ? report.status : '-';
  var pf = document.getElementById('incProofs');
  pf.innerHTML = '';
  if (proofs && proofs.length > 0) {
    proofs.forEach(function(p){
      var ext = p.split('.').pop().toLowerCase();
      if (['jpg','jpeg','png','gif'].indexOf(ext) >= 0) {
        var img = document.createElement('img'); img.src = p; pf.appendChild(img);
      } else {
        var a = document.createElement('a'); a.href = p; a.target = '_blank'; a.textContent = 'View file'; pf.appendChild(a);
      }
    });
  } else {
    var span = document.createElement('span'); span.textContent = 'No proofs'; pf.appendChild(span);
  }
  m.style.display = 'flex';
}
function closeIncidentDetailsModal(){
  var m = document.getElementById('incidentDetailsModal');
  if (m) { m.style.display = 'none'; }
}
</script>
<script src="js/logout-modal.js"></script>
<div id="incidentDetailsModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Incident Details</h3>
      <button class="modal-close" onclick="closeIncidentDetailsModal()">×</button>
    </div>
    <div id="incidentDetailsContent" class="incident-details-content">
      <div class="details-grid">
        <div><strong>Resident</strong><div id="incResident"></div></div>
        <div><strong>Complainee</strong><div id="incSubject"></div></div>
        <div><strong>Address</strong><div id="incAddress"></div></div>
        <div><strong>Nature</strong><div id="incNature"></div></div>
        <div><strong>Date</strong><div id="incDate"></div></div>
        <div><strong>Status</strong><div id="incStatus"></div></div>
      </div>
      <div style="margin-top:12px"><strong>Proofs</strong></div>
      <div id="incProofs" class="proofs"></div>
    </div>
  </div>
</div>
</body>
</html>
