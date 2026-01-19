<?php
session_start();
require_once 'connect.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
  header('Location: login.php');
  exit;
}

$userId = intval($_SESSION['user_id']);
$user = null;

// Fetch resident details
if ($con) {
  $stmt = mysqli_prepare($con, "SELECT id, first_name, middle_name, last_name, email, phone, birthdate, house_number, address, status FROM users WHERE id = ?");
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) === 1) {
      $user = mysqli_fetch_assoc($result);
    }
    mysqli_stmt_close($stmt);
  }
}

if (!$user) {
  header('Location: mainpage.php');
  exit;
}
// Compose full name for display
$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

// Normalize phone and prepare resident fields for guest form
$houseNumber = $user['house_number'] ?? '';
$email = $user['email'] ?? '';
$phoneRaw = $user['phone'] ?? '';
$phoneNormalized = $phoneRaw;
if (preg_match('/^\+63(9\d{9})$/', $phoneNormalized)) {
  $phoneNormalized = '0' . substr($phoneNormalized, 3);
}
$displayPhone = $phoneNormalized ?: $phoneRaw;

// Fetch saved guests for resident
$guestRows = [];
if ($con instanceof mysqli) {
  $stmtG = $con->prepare("SELECT id, visitor_first_name, visitor_middle_name, visitor_last_name, visitor_email, visitor_contact, created_at, ref_code FROM guest_forms WHERE resident_user_id = ? ORDER BY created_at DESC");
  if ($stmtG) {
    $stmtG->bind_param('i', $userId);
    $stmtG->execute();
    $resG = $stmtG->get_result();
    while ($rowG = $resG->fetch_assoc()) {
      $guestRows[] = $rowG;
    }
    $stmtG->close();
  }
}


// Prepare resident QR link and local image path
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/\\');
$qrLink = sprintf('%s://%s%s/resident_qr_view.php?rid=%d', $scheme, $host, $basePath, intval($user['id'] ?? $userId));
$qrRelPath = 'uploads/qr_resident_' . intval($user['id'] ?? $userId) . '.png';
$qrAbsPath = __DIR__ . '/' . $qrRelPath;
// Always ensure the cached QR encodes the current resident ID link
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($qrLink);
$img = @file_get_contents($qrUrl);
if ($img !== false) { @file_put_contents($qrAbsPath, $img); } else { $qrRelPath = $qrUrl; }

// Fetch Activities (Reservations, Reports, Guest Forms)
$activities = [];

// 1. Reservations
// Ensure start_time/end_time exist, if not use created_at or defaults
$stmt = $con->prepare("SELECT 'reservation' as type, amenity, start_date, start_time, end_time, status, created_at, ref_code FROM reservations WHERE user_id = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $start = $row['start_date'];
        $timeStr = ($row['start_time'] ?? '') . ' - ' . ($row['end_time'] ?? '');
        $activities[] = [
            'type' => 'reservation',
            'title' => 'Reservation Schedule - ' . ($row['amenity'] ?? 'Amenity'),
            'details' => date('M d, Y', strtotime($start)) . ' ' . $timeStr,
            'status' => $row['status'] ?? 'pending',
            'date' => $row['created_at'],
            'ref_code' => $row['ref_code'] ?? 'RES'
        ];
    }
    $stmt->close();
}

// 2. Incident Reports
$stmt = $con->prepare("SELECT 'report' as type, nature, address, status, created_at, id FROM incident_reports WHERE user_id = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $activities[] = [
            'type' => 'report',
            'title' => 'Report - ' . ($row['nature'] ?? 'Incident'),
            'details' => 'Address: ' . ($row['address'] ?? ''),
            'status' => $row['status'] ?? 'new',
            'date' => $row['created_at'],
            'ref_code' => 'REP-' . $row['id']
        ];
    }
    $stmt->close();
}

// 3. Guest Forms
$stmt = $con->prepare("SELECT 'guest_form' as type, visitor_first_name, visitor_last_name, visit_date, visit_time, approval_status, created_at, ref_code FROM guest_forms WHERE resident_user_id = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $vName = ($row['visitor_first_name'] ?? '') . ' ' . ($row['visitor_last_name'] ?? '');
        $activities[] = [
            'type' => 'guest_form',
            'title' => 'Guest Request - ' . $vName,
            'details' => 'Visit: ' . date('M d, Y', strtotime($row['visit_date'])) . ' ' . ($row['visit_time'] ?? ''),
            'status' => $row['approval_status'] ?? 'pending',
            'date' => $row['created_at'],
            'ref_code' => $row['ref_code'] ?? 'GST'
        ];
    }
    $stmt->close();
}

usort($activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'items' => $activities]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resident Dashboard - Victorian Heights</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/guestform.css">
<!-- FontAwesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<style>
.field-warning {
  color: #333;
  font-size: 0.85rem;
  margin-top: 6px;
  background: #fff;
  border-left: 4px solid #c0392b;
  box-shadow: 0 2px 8px rgba(0,0,0,0.12);
  border-radius: 8px;
  padding: 8px 10px;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  position: relative;
  z-index: 2;
}
.field-warning .warn-icon {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #c0392b;
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
  flex-shrink: 0;
  line-height: 1;
}
.field-warning .msg {
  color: #333;
}
.field-warning .close-warn {
  margin-left: auto;
  background: transparent;
  border: 0;
  font-size: 1rem;
  cursor: pointer;
  color: #888;
  line-height: 1;
}
.field-warning .close-warn:hover {
  color: #555;
}
</style>
</head>
<body>

<div class="app-container">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <a href="mainpage.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i></a>
    </div>

    <nav class="nav-menu">
      <a href="#" class="nav-item active" data-section="panel-requests"><i class="fa-solid fa-list"></i> <span>My Requests</span></a>
      <a href="reserve.php" class="nav-item"><i class="fa-solid fa-ticket"></i> <span>Amenity Reservation</span></a>
      <a href="#" class="nav-item" data-section="panel-guest-form"><i class="fa-solid fa-user-plus"></i> <span>Guest Form</span></a>
      <a href="#" class="nav-item" data-section="panel-my-guests"><i class="fa-solid fa-user-group"></i> <span>My Guests</span></a>
      <a href="residentreport.php" class="nav-item"><i class="fa-solid fa-triangle-exclamation"></i> <span>Report Incident</span></a>
    </nav>

    <div class="sidebar-footer">
      <div class="qr-section">
        <div style="font-size:0.8rem; font-weight:600; margin-bottom:8px; color:#555;">My Personal QR Code</div>
        <!-- Visible Simple QR Image -->
        <img src="<?php echo htmlspecialchars($qrRelPath); ?>" alt="Personal QR Code" style="width:100%; max-width:150px; border-radius:8px; display:block; margin:0 auto;">
        
        <a href="#" onclick="downloadPersonalQR(); return false;" class="download-qr-btn">Download Personal QR</a>
      </div>
      <a href="logout.php" class="logout-btn" title="Log Out"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>

    <!-- Hidden ID Card for Download Generation -->
    <div style="position:fixed; left:-9999px; top:0;">
        <div class="resident-id-card" id="residentCard" style="width:360px;">
          <div class="card-header">
            <div class="brand"><img src="images/logo.svg" alt="VP"><div class="text">VictorianPass</div></div>
          </div>
          <div class="id-top">
            <div class="avatar"><img src="<?php echo htmlspecialchars($qrRelPath); ?>" alt="QR"></div>
            <div class="top-info">
              <div class="name"><?php echo htmlspecialchars($fullName); ?></div>
              <div class="contact"><?php echo htmlspecialchars($user['email']); ?></div>
            </div>
          </div>
          <div class="divider"></div>
          <div class="id-body">
            <div class="row"><div class="label">Block</div><div class="value"><?php echo htmlspecialchars($user['house_number']??'-'); ?></div></div>
            <div class="row"><div class="label">Unit</div><div class="value"><?php echo htmlspecialchars($user['address']??'-'); ?></div></div>
            <div class="row"><div class="label">Contact</div><div class="value"><?php echo htmlspecialchars($displayPhone?:'-'); ?></div></div>
            <div class="row"><div class="label">Status</div><div class="value"><span class="badge <?php echo ((strtolower($user['status']??'active')==='active')?'active':'disabled'); ?>"><?php echo htmlspecialchars(ucfirst($user['status']??'active')); ?></span></div></div>
          </div>
          <div class="foot">Scan to verify • Resident Access</div>
        </div>
    </div>

    <script>
    function downloadPersonalQR(){
      var element = document.getElementById('residentCard');
      html2canvas(element, {
        scale: 3, // High resolution
        useCORS: true,
        backgroundColor: null
      }).then(function(canvas) {
        var link = document.createElement('a');
        link.download = 'My_Personal_QR_Code.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
      });
    }
    </script>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main-content">
    <!-- Top Header -->
    <header class="top-header">
      <div class="header-brand">
        <img src="images/logo.svg" alt="Logo">
        <div class="brand-text">
          <span class="brand-main">VictorianPass</span>
          <span class="brand-sub">Victorian Heights Subdivision</span>
        </div>
      </div>
      <div class="header-actions">
        <button class="icon-btn" id="notifBtn"><i class="fa-regular fa-bell"></i><span id="notifCount" class="notif-count" style="display:none;">0</span></button>
        <div id="notifPanel" class="notif-panel" style="display:none;"></div>
        <a href="profileresident.php" class="user-profile">
          <span class="user-name">Hi, <?php echo htmlspecialchars($fullName); ?></span>
          <img src="images/mainpage/profile'.jpg" alt="Profile" class="user-avatar">
        </a>
      </div>
    </header>

    <div class="content-wrapper">
      <div class="right-panel">
        <div class="panel-section" id="panel-requests">
          <div class="activity-list-header">
            <div>My Requests</div>
            <div class="search-bar">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" placeholder="Search by code or keyword" id="requestSearch">
            </div>
          </div>

          <div class="item-list">
            <?php if (empty($activities)): ?>
              <div style="padding:20px; text-align:center; color:#777;">No records found.</div>
            <?php else: ?>
              <?php foreach ($activities as $act):
                  $statusClass = 'status-pending';
                  $s = strtolower($act['status']);
                  if (strpos($s, 'approv')!==false || strpos($s, 'resolved')!==false || strpos($s, 'ongoing')!==false) $statusClass = 'status-ongoing';
                  elseif (strpos($s, 'denied')!==false || strpos($s, 'reject')!==false) $statusClass = 'status-denied';
                  elseif (strpos($s, 'cancel')!==false) $statusClass = 'status-cancelled';
                  $displayStatus = ucfirst($act['status']);
              ?>
              <div class="list-item" data-ref-code="<?php echo htmlspecialchars($act['ref_code']); ?>" data-status="<?php echo htmlspecialchars($act['status']); ?>" data-type="<?php echo htmlspecialchars($act['type']); ?>">
                 <div class="item-icon"><i class="fa-solid fa-chevron-right"></i></div>
                 <div class="item-content">
                   <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                     <div>
                       <span class="status-badge <?php echo $statusClass; ?>"><?php echo $displayStatus; ?></span>
                       <span class="item-title"><?php echo htmlspecialchars($act['title']); ?></span>
                       <span class="item-details">- <?php echo htmlspecialchars($act['details']); ?></span>
                     </div>
                     <div class="item-time"><?php echo date('h:i A', strtotime($act['date'])); ?></div>
                   </div>
                   <div style="font-size:0.8rem; color:#999; margin-left: 48px;" class="item-ref">
                     <span><?php echo htmlspecialchars($act['ref_code']); ?></span>
                   </div>
                   <div class="item-extra" data-loaded="0"></div>
                 </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel-section" id="panel-guest-form" style="display:none;">
          <div class="activity-list-header">
            <div>Guest Form</div>
          </div>
          <div style="max-width:720px;margin:0 auto;">
            <form class="entry-form" id="entryForm" enctype="multipart/form-data">
              <div class="form-header">
                <img src="images/mainpage/ticket.svg" alt="Entry Icon">
                <span>Add Guest</span>
              </div>

              <h4 style="margin:10px 0 5px;color:#111827;">Resident Information</h4>
              <div class="form-row">
                <input type="text" id="resident_full_name" name="resident_full_name" placeholder="Resident Full Name*" value="<?php echo htmlspecialchars($fullName); ?>" required>
                <input type="text" id="resident_house" name="resident_house" placeholder="House/Unit No.*" value="<?php echo htmlspecialchars($houseNumber); ?>" required>
              </div>
              <div class="form-row">
                <input type="email" id="resident_email" name="resident_email" placeholder="Resident Email*" value="<?php echo htmlspecialchars($email); ?>" required>
                <input type="tel" id="resident_contact" name="resident_contact" placeholder="Resident Phone Number*" value="<?php echo htmlspecialchars($phoneNormalized); ?>" required>
              </div>

              <h4 style="margin:20px 0 5px;color:#111827;">Guest Information</h4>
              <div class="form-row">
                <input type="text" id="visitor_first_name" name="visitor_first_name" placeholder="Visitor First Name*" required>
                <input type="text" id="visitor_last_name" name="visitor_last_name" placeholder="Visitor Last Name*" required>
              </div>
              <div class="form-row">
                <select id="visitor_sex" name="visitor_sex" required>
                  <option value="" disabled selected>Sex*</option>
                  <option>Male</option>
                  <option>Female</option>
                </select>
                <div class="form-group">
                  <input type="date" id="birthdate" name="visitor_birthdate" placeholder=" " required>
                  <label for="birthdate">Birthdate*</label>
                </div>
              </div>
              <div class="input-wrap">
                <input type="tel" id="visitor_contact" name="visitor_contact" placeholder="Visitor Phone Number*" required>
              </div>
              <input type="email" id="visitor_email" name="visitor_email" placeholder="Visitor Email*" required>

              <label class="upload-box">
                <input type="file" id="visitor_valid_id" name="visitor_valid_id" accept="image/*" hidden required>
                <img src="images/mainpage/upload.svg" alt="Upload">
                <p>Upload Guest’s Valid ID*<br><small>(e.g. National ID, Driver’s License)</small></p>
              </label>
              <div class="privacy-note" style="background:#f9fafb;border:1px solid #e5e7eb;color:#374151;padding:10px 12px;border-radius:8px;margin:10px 0;font-size:0.92rem;line-height:1.35;">
                Data Privacy Notice: The visitor’s ID is used only for verification and stored securely. Access is limited to authorized staff, following the Data Privacy Act of 2012.
              </div>

              <div id="idPreviewWrap" style="display:none;margin:8px 0 14px;">
                <img id="idPreview" alt="Valid ID Preview" style="max-width:240px;border-radius:10px;border:1px solid #e6ebe6;display:block;">
                <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">
                  <button type="button" id="btnClearId" class="btn-next" style="background:#e6ebe6;color:#23412e;">Remove Selected ID</button>
                </div>
              </div>

              <div class="form-actions">
                <a href="#" class="btn-back" id="guestFormBackBtn">Back</a>
                <button type="submit" class="btn-next" id="submitBtn">Save Guest</button>
              </div>
            </form>
          </div>
        </div>

        <div class="panel-section" id="panel-my-guests" style="display:none;">
          <div class="activity-list-header">
            <div>My Guests</div>
          </div>
          <div id="guestListSection" style="margin-top:8px;background:#ffffff;border-radius:16px;padding:20px 22px;box-shadow:0 4px 16px rgba(15,23,42,0.08);border:1px solid #e5e7eb;max-width:860px;width:100%;margin-left:auto;margin-right:auto;">
            <h4 style="margin:0 0 10px;color:#111827;">My Saved Guests</h4>
            <?php if (empty($guestRows)): ?>
              <p style="margin:4px 0 0;font-size:0.95rem;color:#555;">You have not added any guests yet. Use the Guest Form to add a guest.</p>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                  <thead>
                    <tr style="background:#f5f7f5;color:#333;">
                      <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Name</th>
                      <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Contact</th>
                      <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Email</th>
                      <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Added</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($guestRows as $g): ?>
                      <?php
                        $nameParts = [];
                        if (!empty($g['visitor_first_name'])) { $nameParts[] = $g['visitor_first_name']; }
                        if (!empty($g['visitor_middle_name'])) { $nameParts[] = $g['visitor_middle_name']; }
                        if (!empty($g['visitor_last_name'])) { $nameParts[] = $g['visitor_last_name']; }
                        $guestName = trim(implode(' ', $nameParts));
                        if ($guestName === '') { $guestName = 'Guest'; }
                        $contact = $g['visitor_contact'] ?? '';
                        $emailG = $g['visitor_email'] ?? '';
                        $created = $g['created_at'] ?? '';
                        $createdLabel = $created ? date('M d, Y', strtotime($created)) : '';
                      ?>
                      <tr>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;font-weight:600;"><?php echo htmlspecialchars($guestName); ?></td>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;"><?php echo htmlspecialchars($contact); ?></td>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;"><?php echo htmlspecialchars($emailG); ?></td>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;color:#777;"><?php echo htmlspecialchars($createdLabel); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <div id="cancelModal" class="cancel-modal" style="display:none;">
    <div class="cancel-modal-content">
      <div class="cancel-modal-header">
        <h3>Cancel Reservation</h3>
        <button type="button" class="cancel-modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="cancel-modal-body">
        <p>Are you sure you want to cancel this reservation?</p>
        <p class="cancel-modal-note">Note: Downpayment is non-refundable. Cancelling will forfeit your downpayment.</p>
      </div>
      <div class="cancel-modal-actions">
        <button type="button" class="cancel-modal-keep">Keep Reservation</button>
        <button type="button" class="cancel-modal-confirm">Confirm Cancel</button>
      </div>
    </div>
  </div>
  <div id="refModal" class="modal">
    <div class="modal-content">
      <h2>Request Submitted!</h2>
      <p>Your guest has been successfully saved to your account.</p>
      <p><small><em>You can view and manage all guests from your resident dashboard.</em></small></p>
      <div style="display:flex;gap:10px;justify-content:center;margin-top:10px;flex-wrap:wrap;">
        <button type="button" class="close-btn" id="refModalCloseBtn">Back to My Requests</button>
      </div>
    </div>
  </div>
  <div id="verifyModal" class="modal">
    <div class="modal-content">
      <h2>Confirm Guest Request</h2>
      <div id="verifySummary" style="text-align:left;margin-top:10px"></div>
      <div style="text-align:center;margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
        <button type="button" class="close-btn" id="verifyCancelBtn">Cancel</button>
        <button type="button" class="btn-secondary" id="verifyConfirmBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var searchInput=document.getElementById('requestSearch');
    function filterList(){
    var q=(searchInput.value||'').toLowerCase();
    document.querySelectorAll('.item-list .list-item').forEach(function(li){
      var text=li.textContent.toLowerCase();
      li.style.display=text.indexOf(q)!==-1?'':'none';
    });
  }
  if(searchInput){ searchInput.addEventListener('input',filterList); }
  var prevStatuses={};
  document.querySelectorAll('.item-list .list-item').forEach(function(li){
    var code=li.getAttribute('data-ref-code')||'';
    var st=li.getAttribute('data-status')||'';
    if(code) prevStatuses[code]=st;
  });
  function statusClassFor(s){
    s=(s||'').toLowerCase();
    if(s.indexOf('approv')!==-1||s.indexOf('resolved')!==-1||s.indexOf('ongoing')!==-1) return 'status-ongoing';
    if(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1) return 'status-denied';
    if(s.indexOf('cancel')!==-1) return 'status-cancelled';
    return 'status-pending';
  }
  function fmtLabel(s){
    s=String(s||'').toLowerCase();
    return s.charAt(0).toUpperCase()+s.slice(1);
  }
  var notifCountEl=document.getElementById('notifCount');
  var notifBtn=document.getElementById('notifBtn');
  var notifPanel=document.getElementById('notifPanel');
  var notifItems=[];
  var cancelModal=document.getElementById('cancelModal');
  var cancelModalKeep=cancelModal?cancelModal.querySelector('.cancel-modal-keep'):null;
  var cancelModalConfirm=cancelModal?cancelModal.querySelector('.cancel-modal-confirm'):null;
  var cancelModalClose=cancelModal?cancelModal.querySelector('.cancel-modal-close'):null;
  var cancelModalRef=null;
  var cancelModalLi=null;
  function openCancelModal(li,ref){
    if(!cancelModal) return;
    cancelModalRef=ref;
    cancelModalLi=li;
    cancelModal.style.display='flex';
  }
  function closeCancelModalResident(){
    if(!cancelModal) return;
    cancelModal.style.display='none';
    cancelModalRef=null;
    cancelModalLi=null;
  }
  function performCancelResident(){
    var ref=cancelModalRef;
    var li=cancelModalLi;
    if(!ref||!li){
      closeCancelModalResident();
      return;
    }
    fetch('status.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'cancel',code:ref})
    }).then(function(r){return r.json();}).then(function(data){
      if(!data||!data.success){
        alert(data && data.message ? data.message : 'Unable to cancel reservation.');
        return;
      }
      li.setAttribute('data-status','denied');
      prevStatuses[ref]='denied';
      var badge=li.querySelector('.status-badge');
      if(badge){
        badge.textContent=fmtLabel('denied');
        badge.classList.remove('status-pending','status-ongoing','status-denied','status-cancelled','status-completed');
        badge.classList.add(statusClassFor('denied'));
      }
      var extraEl=li.querySelector('.item-extra');
      if(extraEl){
        extraEl.setAttribute('data-loaded','0');
        extraEl.innerHTML='';
        if(li.classList.contains('expanded')){
          buildExtraContent(li,extraEl);
          extraEl.setAttribute('data-loaded','1');
        }
      }
      closeCancelModalResident();
    })["catch"](function(){
      alert('Network error. Please try again.');
    });
  }
  if(cancelModalKeep){
    cancelModalKeep.addEventListener('click',function(){
      closeCancelModalResident();
    });
  }
  if(cancelModalClose){
    cancelModalClose.addEventListener('click',function(){
      closeCancelModalResident();
    });
  }
  if(cancelModalConfirm){
    cancelModalConfirm.addEventListener('click',function(){
      performCancelResident();
    });
  }
  function buildExtraContent(li, extra){
    var type=(li.getAttribute('data-type')||'').toLowerCase();
    var status=li.getAttribute('data-status')||'';
    var ref=li.getAttribute('data-ref-code')||'';
    var label=fmtLabel(status);
    var statusNote='';
    var s=status.toLowerCase();
    var basePath=window.location.pathname.replace(/\/[^\/]*$/,'');
    var isApproved=s.indexOf('approv')!==-1;
    if(isApproved) statusNote='This request is approved. Use this QR pass at the gate.';
    else if(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1) statusNote='This request was denied. Please contact the subdivision office for details.';
    else if(s.indexOf('pending')!==-1||s===''||s==='new') statusNote='This request is pending. Wait for the admin to review it. The QR entry pass will be available after approval.';
    else if(s.indexOf('resolved')!==-1) statusNote='This item has been marked as resolved by the admin.';
    else if(s.indexOf('expired')!==-1) statusNote='This pass is expired and can no longer be used.';
    var titleEl=li.querySelector('.item-title');
    var detailsEl=li.querySelector('.item-details');
    var refSpan=li.querySelector('.item-ref span');
    function esc(t){
      return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    var summaryParts=[];
    if(titleEl){ summaryParts.push(titleEl.textContent.trim()); }
    if(detailsEl){ summaryParts.push(detailsEl.textContent.replace(/^\s*-\s*/,'').trim()); }
    if(refSpan){ summaryParts.push('Code: '+refSpan.textContent.trim()); }
    var summaryText=summaryParts.join(' • ');
    var canCancel=(type==='reservation'||type==='guest_form')&&(s.indexOf('pending')!==-1||s===''||s==='new');
    var html='';
    if(type==='reservation'||type==='guest_form'){
      html+='<div class="item-extra-section">';
      if(isApproved && ref){
        var basePath=window.location.pathname.replace(/\/[^\/]*$/,'');
        var statusLink=location.origin+basePath+'/status_view.php?code='+encodeURIComponent(ref);
        var qrSrc='https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='+encodeURIComponent(statusLink);
        html+='<div class="item-extra-title">Entry QR Pass</div>';
        html+='<div class="item-extra-body">';
        html+='<div class="item-extra-qr-wrap"><img class="item-extra-qr" src="'+qrSrc+'" alt="Entry QR Code"></div>';
        html+='<div class="item-extra-info">';
      }else{
        html+='<div class="item-extra-title">Entry Request Status</div>';
        html+='<div class="item-extra-body">';
        html+='<div class="item-extra-info-only">';
      }
      html+='<div class="item-extra-status"><span class="status-label">'+label+'</span></div>';
      if(statusNote) html+='<div class="item-extra-note">'+esc(statusNote)+'</div>';
      if(summaryText) html+='<div class="item-extra-summary">'+esc(summaryText)+'</div>';
      if(canCancel && ref){
        html+='<button type="button" class="item-extra-cancel" data-ref="'+esc(ref)+'">Cancel reservation</button>';
      }
      if(isApproved && ref){
        var qrViewLink=basePath+'/qr_view.php?code='+encodeURIComponent(ref);
        html+='<a class="item-extra-link" href="'+qrViewLink+'" target="_blank">Open full QR pass</a>';
      }
      if(isApproved && ref){
        html+='</div></div></div>';
      }else{
        var qrViewLink=basePath+'/qr_view.php?code='+encodeURIComponent(ref);
        html+='<a class="item-extra-link" href="'+qrViewLink+'" target="_blank">View details</a>';
        html+='</div></div></div>';
      }
    }else if(type==='report'){
      html+='<div class="item-extra-section">';
      html+='<div class="item-extra-title">Incident Status</div>';
      html+='<div class="item-extra-body">';
      html+='<div class="item-extra-info-only">';
      html+='<div class="item-extra-status"><span class="status-label">'+label+'</span></div>';
      if(statusNote) html+='<div class="item-extra-note">'+esc(statusNote)+'</div>';
      if(summaryText) html+='<div class="item-extra-summary">'+esc(summaryText)+'</div>';
      html+='</div></div></div>';
    }else{
      html+='<div class="item-extra-section">';
      html+='<div class="item-extra-title">Request Details</div>';
      html+='<div class="item-extra-body">';
      html+='<div class="item-extra-info-only">';
      html+='<div class="item-extra-status"><span class="status-label">'+label+'</span></div>';
      if(statusNote) html+='<div class="item-extra-note">'+esc(statusNote)+'</div>';
      if(summaryText) html+='<div class="item-extra-summary">'+esc(summaryText)+'</div>';
      html+='</div></div></div>';
    }
    extra.innerHTML=html;
    var cancelBtn=extra.querySelector('.item-extra-cancel');
    if(cancelBtn && ref && canCancel){
      cancelBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        openCancelModal(li,ref);
      });
    }
  }
  function addNotificationEntry(code,status,li){
    if(!code) return;
    var key=code+'|'+String(status||'');
    for(var i=0;i<notifItems.length;i++){ if(notifItems[i].key===key) return; }
    var type=(li.getAttribute('data-type')||'').toLowerCase();
    var titleEl=li.querySelector('.item-title');
    var title=titleEl?titleEl.textContent.trim():(type==='guest_form'?'Guest Request':(type==='reservation'?'Reservation Schedule':'Request Update'));
    var timeText='';
    try{ timeText=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'}); }catch(e){ timeText=''; }
    notifItems.push({
      key:key,
      code:code,
      status:fmtLabel(status),
      title:title,
      type:type,
      time:timeText
    });
  }
  function renderNotifPanel(){
    if(!notifPanel) return;
    if(!notifItems.length){
      notifPanel.innerHTML='<div class="notif-empty">No recent updates</div>';
      return;
    }
    var html='';
    for(var i=notifItems.length-1;i>=0;i--){
      var it=notifItems[i]||{};
      var code=String(it.code||'').replace(/[<>]/g,'');
      var title=String(it.title||'').replace(/[<>]/g,'');
      var status=String(it.status||'').replace(/[<>]/g,'');
      var time=String(it.time||'').replace(/[<>]/g,'');
      html+='<div class="notif-item" data-code="'+code+'"><div class="notif-item-main"><div class="notif-item-title">'+title+'</div><div class="notif-item-sub">Code: '+code+' • '+status+'</div>';
      if(time) html+='<div class="notif-item-time">'+time+'</div>';
      html+='</div></div>';
    }
    notifPanel.innerHTML=html;
  }
  document.querySelectorAll('.item-list .list-item').forEach(function(li){
    li.addEventListener('click',function(e){
      if(e.target.closest('a')) return;
      li.classList.toggle('expanded');
      var extra=li.querySelector('.item-extra');
      if(!extra) return;
      if(extra.getAttribute('data-loaded')!=='1'&&li.classList.contains('expanded')){
        buildExtraContent(li,extra);
        extra.setAttribute('data-loaded','1');
      }
    });
  });
  if(notifBtn){
    notifBtn.addEventListener('click',function(e){
      e.stopPropagation();
      if(notifPanel){
        notifPanel.style.display=(notifPanel.style.display==='block'?'none':'block');
      }
      document.querySelectorAll('.item-list .list-item.status-updated').forEach(function(li){
        li.classList.remove('status-updated');
      });
      if(notifCountEl){
        notifCountEl.textContent='0';
        notifCountEl.style.display='none';
      }
    });
  }
  if(notifPanel){
    document.addEventListener('click',function(e){
      var t=e.target;
      if(t===notifPanel||notifPanel.contains(t)||t===notifBtn||(notifBtn&&notifBtn.contains(t))) return;
      notifPanel.style.display='none';
    });
  }

  var sections=document.querySelectorAll('.right-panel .panel-section');
  function showPanel(id){
    sections.forEach(function(sec){
      if(!sec) return;
      sec.style.display=sec.id===id?'':'none';
    });
  }
  document.querySelectorAll('.nav-menu .nav-item[data-section]').forEach(function(item){
    item.addEventListener('click',function(e){
      var target=item.getAttribute('data-section');
      if(!target) return;
      e.preventDefault();
      document.querySelectorAll('.nav-menu .nav-item').forEach(function(el){
        el.classList.remove('active');
      });
      item.classList.add('active');
      showPanel(target);
      if(target==='panel-requests' && searchInput && typeof filterList==='function'){
        searchInput.value='';
        filterList();
      }
    });
  });
  var guestFormBackBtn=document.getElementById('guestFormBackBtn');
  if(guestFormBackBtn){
    guestFormBackBtn.addEventListener('click',function(e){
      e.preventDefault();
      var reqNav=document.querySelector('.nav-menu .nav-item[data-section="panel-requests"]');
      if(reqNav) reqNav.click();
    });
  }

  var entryForm=document.getElementById('entryForm');
  var birthdateEl=document.getElementById('birthdate');
  var idInput=document.getElementById('visitor_valid_id');
  var idPreviewWrap=document.getElementById('idPreviewWrap');
  var idPreview=document.getElementById('idPreview');
  var btnClearId=document.getElementById('btnClearId');
  var verifyModal=document.getElementById('verifyModal');
  var verifySummary=document.getElementById('verifySummary');
  var verifyCancelBtn=document.getElementById('verifyCancelBtn');
  var verifyConfirmBtn=document.getElementById('verifyConfirmBtn');
  var refModal=document.getElementById('refModal');
  var refModalCloseBtn=document.getElementById('refModalCloseBtn');
  var submittingGuest=false;

  function setWarning(key,message){
    var inputEl=document.getElementById(key);
    var container=null;
    if(inputEl){
      container=inputEl.closest('.form-group')||inputEl.closest('.form-row')||inputEl.closest('.upload-box')||inputEl.parentNode;
    }
    if(!container) return;
    var warnEl=container.querySelector('.field-warning[data-for="'+key+'"]');
    if(message){
      if(!warnEl){
        warnEl=document.createElement('div');
        warnEl.className='field-warning';
        warnEl.setAttribute('data-for',key);
        warnEl.setAttribute('role','alert');
        container.appendChild(warnEl);
      }
      var icon=warnEl.querySelector('.warn-icon');
      if(!icon){
        icon=document.createElement('span');
        icon.className='warn-icon';
        icon.textContent='!';
        warnEl.appendChild(icon);
      }
      var msgSpan=warnEl.querySelector('.msg');
      if(!msgSpan){
        msgSpan=document.createElement('span');
        msgSpan.className='msg';
        warnEl.appendChild(msgSpan);
      }
      msgSpan.textContent=message;
      var closer=warnEl.querySelector('.close-warn');
      if(!closer){
        closer=document.createElement('button');
        closer.type='button';
        closer.className='close-warn';
        closer.setAttribute('aria-label','Dismiss');
        closer.textContent='\u00d7';
        warnEl.appendChild(closer);
        closer.addEventListener('click',function(){ warnEl.remove(); });
      }
    }else{
      if(warnEl) warnEl.remove();
    }
  }

  function blockDigits(e){
    if(/[0-9]/.test(e.key)){
      e.preventDefault();
      setWarning(e.target.id,'Numbers are not allowed in this field.');
    }
  }
  function sanitizeNoDigits(e){
    var val=e.target.value;
    var cleaned=val.replace(/[0-9]/g,'');
    if(val!==cleaned){
      e.target.value=cleaned;
      setWarning(e.target.id,'Numbers were removed.');
    }else{
      setWarning(e.target.id,'');
    }
  }
  function isValidPhone(el){
    var val=(el.value||'').trim();
    return /^09\d{9}$/.test(val);
  }
  function isValidEmail(el){
    var val=(el.value||'').trim();
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
  }
  ['resident_full_name','visitor_first_name','visitor_last_name'].forEach(function(id){
    var el=document.getElementById(id);
    if(!el) return;
    el.addEventListener('keydown',blockDigits);
    el.addEventListener('input',sanitizeNoDigits);
  });
  ['resident_email','visitor_email'].forEach(function(id){
    var el=document.getElementById(id);
    if(!el) return;
    el.addEventListener('input',function(){
      setWarning(id,isValidEmail(el)?'':'Please enter a valid email.');
    });
  });
  ['resident_contact','visitor_contact'].forEach(function(id){
    var el=document.getElementById(id);
    if(!el) return;
    el.addEventListener('input',function(){
      if(!el.value.trim()){
        setWarning(id,'');
        return;
      }
      setWarning(id,isValidPhone(el)?'':'Please enter a valid phone number starting with 09.');
    });
  });

  if(birthdateEl){
    var d=new Date();
    d.setDate(d.getDate()-1);
    birthdateEl.setAttribute('max',d.toISOString().split('T')[0]);
  }

  function validateGuestForm(){
    var valid=true;
    var reqIds=['resident_full_name','resident_house','resident_email','resident_contact','visitor_first_name','visitor_last_name','visitor_email','birthdate','visitor_contact'];
    reqIds.forEach(function(id){
      var el=document.getElementById(id);
      if(!el) return;
      if(!String(el.value||'').trim()){
        setWarning(id,'This field is required.');
        valid=false;
      }else{
        setWarning(id,'');
      }
    });
    var sexEl=document.getElementById('visitor_sex');
    if(sexEl){
      if(!sexEl.value){
        setWarning('visitor_sex','Please select Sex.');
        valid=false;
      }else{
        setWarning('visitor_sex','');
      }
    }
    if(birthdateEl && birthdateEl.value){
      var todayStr=new Date().toISOString().split('T')[0];
      if(birthdateEl.value>=todayStr){
        setWarning('birthdate','Birthdate must be a past date.');
        valid=false;
      }else{
        setWarning('birthdate','');
      }
    }
    var rc=document.getElementById('resident_contact');
    var vc=document.getElementById('visitor_contact');
    var re=document.getElementById('resident_email');
    var ve=document.getElementById('visitor_email');
    if(re && !isValidEmail(re)){
      setWarning('resident_email','Please enter a valid email.');
      valid=false;
    }
    if(ve && !isValidEmail(ve)){
      setWarning('visitor_email','Please enter a valid email.');
      valid=false;
    }
    if(rc && !isValidPhone(rc)){
      setWarning('resident_contact','Please enter a valid phone number starting with 09.');
      valid=false;
    }
    if(vc && !isValidPhone(vc)){
      setWarning('visitor_contact','Please enter a valid phone number starting with 09.');
      valid=false;
    }
    if(idInput && !(idInput.files && idInput.files[0])){
      setWarning('visitor_valid_id','Please upload Visitor’s Valid ID.');
      valid=false;
    }
    return valid;
  }

  function buildVerifySummary(){
    if(!verifyModal || !verifySummary) return;
    var resNameEl=document.getElementById('resident_full_name');
    var resHouseEl=document.getElementById('resident_house');
    var resContactEl=document.getElementById('resident_contact');
    var visFirstEl=document.getElementById('visitor_first_name');
    var visLastEl=document.getElementById('visitor_last_name');
    var visContactEl=document.getElementById('visitor_contact');
    var visEmailEl=document.getElementById('visitor_email');
    var vSexEl=document.getElementById('visitor_sex');
    var resName=resNameEl?resNameEl.value.trim():'';
    var resHouse=resHouseEl?resHouseEl.value.trim():'';
    var resContact=resContactEl?resContactEl.value.trim():'';
    var visFirst=visFirstEl?visFirstEl.value.trim():'';
    var visLast=visLastEl?visLastEl.value.trim():'';
    var visContact=visContactEl?visContactEl.value.trim():'';
    var visEmail=visEmailEl?visEmailEl.value.trim():'';
    var vSex=vSexEl?vSexEl.value:'';
    var items=[
      ['Resident',resName||'-'],
      ['House/Unit',resHouse||'-'],
      ['Resident Contact',resContact||'-'],
      ['Visitor',(visFirst+' '+visLast).trim()||'-'],
      ['Visitor Sex',vSex||'-'],
      ['Visitor Contact',visContact||'-'],
      ['Visitor Email',visEmail||'-']
    ];
    verifySummary.innerHTML=items.map(function(x){
      return '<div style="display:flex;justify-content:space-between;margin:4px 0"><span style="font-weight:600">'+x[0]+'</span><span>'+x[1]+'</span></div>';
    }).join('');
    verifyModal.style.display='flex';
  }

  if(idInput){
    idInput.addEventListener('change',function(){
      var file=idInput.files && idInput.files[0];
      if(!file){
        if(idPreviewWrap) idPreviewWrap.style.display='none';
        setWarning('visitor_valid_id','Please upload Visitor’s Valid ID.');
        return;
      }
      var reader=new FileReader();
      reader.onload=function(e){
        if(idPreview){
          idPreview.src=e.target.result;
        }
        if(idPreviewWrap){
          idPreviewWrap.style.display='block';
        }
      };
      reader.readAsDataURL(file);
      setWarning('visitor_valid_id','');
    });
  }
  if(btnClearId){
    btnClearId.addEventListener('click',function(){
      if(idInput){
        idInput.value='';
      }
      if(idPreviewWrap){
        idPreviewWrap.style.display='none';
      }
      setWarning('visitor_valid_id','Please upload Visitor’s Valid ID.');
    });
  }

  if(verifyCancelBtn && verifyModal){
    verifyCancelBtn.addEventListener('click',function(){
      if(submittingGuest) return;
      verifyModal.style.display='none';
    });
  }

  function openGuestRefModal(){
    if(refModal){
      refModal.style.display='flex';
    }
  }
  if(refModalCloseBtn && refModal){
    refModalCloseBtn.addEventListener('click',function(){
      refModal.style.display='none';
      var reqNav=document.querySelector('.nav-menu .nav-item[data-section="panel-requests"]');
      if(reqNav) reqNav.click();
    });
  }

  function performGuestSubmit(){
    if(submittingGuest) return;
    submittingGuest=true;
    if(verifyConfirmBtn){
      verifyConfirmBtn.disabled=true;
    }
    var fd=new FormData(entryForm);
    fetch('submit_guest.php',{method:'POST',body:fd})
      .then(function(res){return res.json();})
      .then(function(data){
        if(data && data.success){
          openGuestRefModal();
        }else{
          setWarning('visitor_email',data && data.message ? data.message : 'Failed to save guest.');
        }
      })["catch"](function(){
        setWarning('visitor_email','Error connecting to server.');
      }).then(function(){
        submittingGuest=false;
        if(verifyConfirmBtn){
          verifyConfirmBtn.disabled=false;
        }
      });
  }

  if(verifyConfirmBtn){
    verifyConfirmBtn.addEventListener('click',function(){
      if(submittingGuest) return;
      if(!validateGuestForm()) return;
      if(verifyModal){
        verifyModal.style.display='none';
      }
      if(entryForm){
        performGuestSubmit();
      }
    });
  }

  if(entryForm){
    entryForm.addEventListener('submit',function(e){
      e.preventDefault();
      if(submittingGuest) return;
      if(!validateGuestForm()) return;
      buildVerifySummary();
    });
  }

  function refreshStatuses(){
    fetch('profileresident.php?ajax=1',{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(data){
        if(!data||!data.success||!Array.isArray(data.items)) return;
        var map={};
        data.items.forEach(function(it){ if(it.ref_code) map[it.ref_code]=it; });
        var changed=0;
        document.querySelectorAll('.item-list .list-item').forEach(function(li){
          var code=li.getAttribute('data-ref-code')||'';
          if(!code||!map[code]) return;
          var info=map[code];
          var newStatus=info.status||'';
          var oldStatus=prevStatuses[code];
          if(oldStatus!==undefined && oldStatus!==newStatus){
            changed++;
            li.classList.add('status-updated');
            addNotificationEntry(code,newStatus,li);
          }
          prevStatuses[code]=newStatus;
          li.setAttribute('data-status',newStatus);
          var badge=li.querySelector('.status-badge');
          if(badge){
            badge.textContent=fmtLabel(newStatus);
            badge.classList.remove('status-pending','status-ongoing','status-denied','status-cancelled','status-completed');
            badge.classList.add(statusClassFor(newStatus));
          }
          var timeEl=li.querySelector('.item-time');
          if(timeEl && info.date){
            var d=new Date(info.date.replace(' ','T'));
            if(!isNaN(d.getTime())) timeEl.textContent=d.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
          }
        });
        if(notifPanel) renderNotifPanel();
        if(notifCountEl){
          if(changed>0){
            notifCountEl.textContent=String(changed);
            notifCountEl.style.display='inline-block';
          }else{
            notifCountEl.textContent='0';
            notifCountEl.style.display='none';
          }
        }
      })["catch"](function(){});
  }
  setInterval(refreshStatuses,10000);
})();
</script>
</body>
</html>