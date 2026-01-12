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

// Normalize phone
$displayPhone = $user['phone'] ?? '';
if (preg_match('/^\+63(9\d{9})$/', $displayPhone)) { $displayPhone = '0' . substr($displayPhone, 3); }


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

// Sort by date DESC
usort($activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

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
<!-- FontAwesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
</head>
<body>

<div class="app-container">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <a href="mainpage.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i></a>
    </div>

    <nav class="nav-menu">
      <a href="#" class="nav-item"><i class="fa-solid fa-inbox"></i> <span>Inbox</span> <span class="nav-badge">3</span></a>
      <a href="#" class="nav-item"><i class="fa-solid fa-bullhorn"></i> <span>Announcement</span></a>
      <a href="#" class="nav-item"><i class="fa-solid fa-clock"></i> <span>On Going</span></a>
      <a href="#" class="nav-item"><i class="fa-solid fa-paper-plane"></i> <span>Request</span></a>
      <a href="#" class="nav-item"><i class="fa-solid fa-receipt"></i> <span>Receipt</span> <span class="nav-badge">1</span></a>
      <a href="reserve.php" class="nav-item"><i class="fa-solid fa-ticket"></i> <span>Amenity Reservation</span> <span class="nav-badge">3</span></a>
      <a href="residentreport.php" class="nav-item"><i class="fa-solid fa-triangle-exclamation"></i> <span>Report Incident</span></a>
      <a href="guestform.php" class="nav-item"><i class="fa-solid fa-user-group"></i> <span>Guest Form</span></a>
      <a href="#" class="nav-item"><i class="fa-solid fa-circle-question"></i> <span>Help</span></a>
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
        <button class="icon-btn"><i class="fa-regular fa-bell"></i></button>
        <button class="icon-btn"><i class="fa-solid fa-bars"></i></button>
        <div class="user-profile">
          <span class="user-name">Hi, <?php echo htmlspecialchars($fullName); ?></span>
          <img src="images/mainpage/profile'.jpg" alt="Profile" class="user-avatar">
        </div>
      </div>
    </header>

    <div class="content-wrapper">
      <!-- Left Panel: Calendar -->
      <div class="left-panel">
        <div class="calendar-widget">
           <div class="calendar-header">
             <span><?php echo date('F'); ?></span>
           </div>
           <div class="calendar-grid">
             <div class="calendar-day-name">m</div>
             <div class="calendar-day-name">t</div>
             <div class="calendar-day-name">w</div>
             <div class="calendar-day-name">t</div>
             <div class="calendar-day-name">f</div>
             <div class="calendar-day-name">s</div>
             <div class="calendar-day-name">s</div>
             <!-- Mock Calendar Days -->
             <?php
               $daysInMonth = date('t');
               $startDay = date('N', strtotime(date('Y-m-01'))) - 1; // 0 (Mon) - 6 (Sun)
               for ($i = 0; $i < $startDay; $i++) echo '<div></div>';
               $today = date('j');
               for ($d = 1; $d <= $daysInMonth; $d++) {
                 $class = ($d == $today) ? 'calendar-day active' : 'calendar-day';
                 echo "<div class='$class'>$d</div>";
               }
             ?>
           </div>
        </div>
        <div class="upcoming-entries">
          <h4>Your Upcoming Entries</h4>
          <div class="no-events">
            No upcoming events
          </div>
        </div>
      </div>

      <!-- Right Panel: List -->
      <div class="right-panel">
        <div class="toolbar">
           <div class="toolbar-actions">
             <i class="fa-regular fa-square"></i>
             <i class="fa-solid fa-rotate-right" onclick="location.reload()"></i>
             <i class="fa-solid fa-trash"></i>
             <i class="fa-solid fa-ellipsis-vertical"></i>
           </div>
           <div class="search-bar">
             <i class="fa-solid fa-magnifying-glass"></i>
             <input type="text" placeholder="Search keyword ex. HV-0000">
           </div>
           <div class="pagination-info">
             1-<?php echo count($activities); ?> of <?php echo count($activities); ?> &nbsp; <i class="fa-solid fa-chevron-left"></i> <i class="fa-solid fa-chevron-right"></i>
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
                
                // Title display logic based on type
                $displayStatus = ucfirst($act['status']);
            ?>
            <div class="list-item">
               <div class="item-checkbox"><input type="checkbox"></div>
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
                 <div style="font-size:0.8rem; color:#999; margin-left: 80px;">
                   <?php echo htmlspecialchars($act['ref_code']); ?>
                 </div>
               </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

</body>
</html>
