<?php
session_start();
require_once 'connect.php';

// Redirect if not logged in or not a visitor
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'visitor') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_data = [];

// Fetch visitor data
if ($con) {
    $stmt = $con->prepare("SELECT first_name, last_name, email, phone, sex, birthdate FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $user_data = $res->fetch_assoc();
    }
    $stmt->close();
}
$firstName = $user_data['first_name'] ?? 'Visitor';
$fullName = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
$birthdate = $user_data['birthdate'] ?? '';
$birthdateDisplay = $birthdate ? date('M d, Y', strtotime($birthdate)) : '';
$flashNotice = $_SESSION['flash_notice'] ?? '';
if ($flashNotice !== '') {
    unset($_SESSION['flash_notice'], $_SESSION['flash_ref_code']);
}

// Change Password Handler
$pwdMsg = '';
$pwdOk = false;
function ensurePasswordField($con){
    if(!($con instanceof mysqli)) return;
    $res = $con->query("SHOW COLUMNS FROM users LIKE 'password'");
    if($res && ($row = $res->fetch_assoc())){
        $type = strtolower($row['Type'] ?? '');
        if (strpos($type, 'varchar(255)') === false && strpos($type, 'text') === false) {
            @$con->query("ALTER TABLE users MODIFY COLUMN password VARCHAR(255)");
        }
    } else {
        @$con->query("ALTER TABLE users ADD COLUMN password VARCHAR(255)");
    }
}
ensurePasswordField($con);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($current === '' || $newPass === '' || $confirm === '') {
        $pwdMsg = 'Please fill in all password fields.';
    } elseif ($newPass !== $confirm) {
        $pwdMsg = 'New password and confirm password do not match.';
    } elseif (strlen($newPass) < 8) {
        $pwdMsg = 'New password must be at least 8 characters.';
    } else {
        if (!($con instanceof mysqli)) {
            $pwdMsg = 'Database connection error.';
        } else {
            $stmtP = $con->prepare("SELECT `password` FROM users WHERE id = ? LIMIT 1");
            if (!$stmtP) {
                $pwdMsg = 'Database error: ' . $con->error;
            } else {
                $stmtP->bind_param('i', $user_id);
                $stmtP->execute();
                $stmtP->bind_result($hash);
                $hasRow = $stmtP->fetch();
                $stmtP->close();
                if ($hasRow) {
                    if (!password_verify($current, $hash)) {
                        $pwdMsg = 'Current password is incorrect.';
                    } else {
                        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                        $updP = $con->prepare("UPDATE users SET `password` = ? WHERE id = ?");
                        if (!$updP) {
                            $esc = $con->real_escape_string($newHash);
                            $ok = $con->query("UPDATE users SET `password` = '".$esc."' WHERE id = ".intval($user_id));
                            if ($ok) {
                                $pwdOk = true;
                                $pwdMsg = 'Your password has been updated.';
                            } else {
                                $pwdMsg = 'Database error: ' . $con->error;
                            }
                        } else {
                            $updP->bind_param('si', $newHash, $user_id);
                            $updP->execute();
                            $updP->close();
                            $pwdOk = true;
                            $pwdMsg = 'Your password has been updated.';
                        }
                    }
                } else {
                    $pwdMsg = 'Unable to verify your account.';
                }
            }
        }
    }
}

// Profile Picture Logic
$profilePicPath = 'images/mainpage/profile\'.jpg'; // Default
if (file_exists('uploads/profiles/user_' . $user_id . '.jpg')) {
    $profilePicPath = 'uploads/profiles/user_' . $user_id . '.jpg';
} elseif (file_exists('uploads/profiles/user_' . $user_id . '.png')) {
    $profilePicPath = 'uploads/profiles/user_' . $user_id . '.png';
} elseif (file_exists('uploads/profiles/user_' . $user_id . '.jpeg')) {
    $profilePicPath = 'uploads/profiles/user_' . $user_id . '.jpeg';
}
$profilePicUrl = $profilePicPath . '?t=' . time();

function ensureNotificationsTable($con) {
    if (!($con instanceof mysqli)) { return; }
    $con->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL COMMENT 'For residents',
        entry_pass_id INT NULL COMMENT 'For visitors',
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
        INDEX idx_user_id (user_id),
        INDEX idx_is_read (is_read)
    ) ENGINE=InnoDB");
}

function getUserNotifications($con, $userId, $limit = 20) {
    $items = [];
    if (!($con instanceof mysqli) || !$userId) { return $items; }
    $stmt = $con->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    if (!$stmt) { return $items; }
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

function getUserUnreadNotificationCount($con, $userId) {
    if (!($con instanceof mysqli) || !$userId) { return 0; }
    $stmt = $con->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
    if (!$stmt) { return 0; }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = 0;
    if ($res && ($row = $res->fetch_assoc())) { $count = intval($row['c']); }
    $stmt->close();
    return $count;
}

ensureNotificationsTable($con);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notifications_read') {
    header('Content-Type: application/json');
    if ($con instanceof mysqli) {
        $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

// Fetch Activities
$activities = [];

// Reservations
$stmt = $con->prepare("SELECT 'reservation' as type, amenity, start_date, end_date, start_time, end_time, status, approval_status, payment_status, created_at, ref_code FROM reservations WHERE user_id = ? AND status != 'deleted' AND approval_status != 'deleted' ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $start = $row['start_date'];
        $end = $row['end_date'] ?? null;
        $sTime = strtotime($row['start_time'] ?? '');
        $eTime = strtotime($row['end_time'] ?? '');
        
        $dateStr = '';
        if (!empty($start) && !empty($end)) {
            $dateStr = date('M d, Y', strtotime($start)) . ' - ' . date('M d, Y', strtotime($end));
        } elseif (!empty($start)) {
            $dateStr = date('M d, Y', strtotime($start));
        } else {
            $dateStr = 'Date not set';
        }
        
        if ($sTime && $eTime) {
             $dateStr .= ' ' . date('h:i A', $sTime) . ' - ' . date('h:i A', $eTime);
        }
        
        $statusVal = $row['approval_status'] ?? '';
        if ($statusVal === '' || $statusVal === null) {
            $statusVal = $row['status'] ?? 'pending';
        }
        
        $resTitle = 'Reservation Schedule - ' . ($row['amenity'] ?? 'Amenity');
        if (stripos($statusVal, 'cancel') !== false) {
            $resTitle .= ' - Cancelled';
        }

        // Calculate event timestamp for history sorting
        $eventTs = 0;
        if ($eTime) {
            $eventTs = $eTime; // If end time is set
            // If end date is set, combine
             if ($end) {
                $eventTs = strtotime($end . ' ' . ($row['end_time'] ?? '23:59:59'));
            } elseif ($start) {
                $eventTs = strtotime($start . ' ' . ($row['end_time'] ?? '23:59:59'));
            }
        } else {
             // Fallback to end of day of start date
             if ($start) $eventTs = strtotime($start . ' 23:59:59');
             else $eventTs = strtotime($row['created_at']);
        }

        $activities[] = [
            'type' => 'reservation',
            'title' => $resTitle,
            'details' => $dateStr,
            'status' => $statusVal,
            'date' => $row['created_at'],
            'event_timestamp' => $eventTs,
            'ref_code' => $row['ref_code'] ?? 'RES',
            'payment_status' => $row['payment_status'] ?? null
        ];
    }
    $stmt->close();
}

usort($activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Split into Active and History
$activeActivities = [];
$historyActivities = [];
$now = time();

foreach ($activities as $act) {
    $s = strtolower($act['status']);
    if (strpos($s, 'deleted') !== false) continue;

    $isHistory = false;

    // 1. Explicit history statuses
    if (strpos($s, 'cancel') !== false || 
        strpos($s, 'denied') !== false || 
        strpos($s, 'reject') !== false || 
        strpos($s, 'expired') !== false) {
        $isHistory = true;
    } 
    // 2. Approved/Resolved but passed date
    else if ((strpos($s, 'approv') !== false || strpos($s, 'resolved') !== false)) {
        // If event timestamp is in the past
        if ($act['event_timestamp'] < $now) {
            $isHistory = true;
        }
    }

    if ($isHistory) {
        $historyActivities[] = $act;
    } else {
        $activeActivities[] = $act;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $notifications = getUserNotifications($con, $user_id, 20);
    $unreadCount = getUserUnreadNotificationCount($con, $user_id);
    echo json_encode([
        'success' => true, 
        'active' => $activeActivities, 
        'history' => $historyActivities,
        'notifications' => $notifications,
        'unread_count' => $unreadCount
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Visitor Dashboard - Victorian Heights</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<link rel="stylesheet" href="css/dashboard.css">
<!-- FontAwesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  /* Fix for Visitor Dashboard Modal to ensure it fits screen and close button is visible */
  #activityModal .modal-content {
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      padding: 0;
  }
  #activityModal .close {
      position: absolute;
      top: 10px;
      right: 15px;
      font-size: 28px;
      font-weight: bold;
      color: #555;
      cursor: pointer;
      z-index: 100;
      background: rgba(255,255,255,0.9);
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
      transition: all 0.2s ease;
  }
  #activityModal .close:hover {
      color: #000;
      background: #fff;
      transform: scale(1.1);
  }
  #activityModalBody {
      overflow-y: auto;
      padding: 30px;
      flex: 1;
  }
  #submitNoticeModal { display: flex; align-items: center; justify-content: center; }
  #submitNoticeModal .modal-content { width: 92%; max-width: 420px; padding: 24px; text-align: center; height: auto; min-height: unset; }
  #submitNoticeModal .close { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 50%; background: #eef2f0; color: #23412e; display: flex; align-items: center; justify-content: center; }
</style>
</head>
<body>
<?php if ($flashNotice !== '') { ?>
  <div id="submitNoticeModal" class="modal" style="display:flex;">
    <div class="modal-content" style="max-width:420px;text-align:center;padding:24px;">
      <span class="close" id="submitNoticeClose">&times;</span>
      <div style="font-size:1.1rem;font-weight:700;color:#23412e;margin-bottom:6px;">Request submitted</div>
      <div style="color:#555;"><?php echo htmlspecialchars($flashNotice); ?></div>
      <button type="button" id="submitNoticeBtn" style="margin-top:18px;background:#23412e;color:#fff;border:none;border-radius:8px;padding:10px 16px;cursor:pointer;font-weight:600;">Close</button>
    </div>
  </div>
  <script>
    (function(){
      var modal=document.getElementById('submitNoticeModal');
      var closeBtn=document.getElementById('submitNoticeClose');
      var okBtn=document.getElementById('submitNoticeBtn');
      function close(){ if(modal) modal.style.display='none'; }
      if(closeBtn) closeBtn.addEventListener('click', close);
      if(okBtn) okBtn.addEventListener('click', close);
      if(modal) modal.addEventListener('click', function(e){ if(e.target===modal) close(); });
    })();
  </script>
<?php } ?>

<div class="app-container">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <a href="mainpage.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i></a>
    </div>

    <nav class="nav-menu">
      <a href="#" class="nav-item active" data-section="panel-requests"><i class="fa-solid fa-list"></i> <span>My Requests</span></a>
      <a href="#" class="nav-item" data-section="panel-history"><i class="fa-solid fa-clock-rotate-left"></i> <span>History</span></a>
      <a href="reserve.php" class="nav-item"><i class="fa-solid fa-ticket"></i> <span>Amenity Reservation</span></a>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" class="logout-btn" title="Log Out"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main-content">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Top Header -->
    <header class="top-header">
      <div class="header-brand">
        <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
        <img src="images/logo.svg" alt="Logo">
        <div class="brand-text">
          <span class="brand-main">VictorianPass</span>
          <span class="brand-sub">Victorian Heights Subdivision</span>
        </div>
      </div>
      <div class="header-actions">
        <button class="icon-btn" id="notifBtn"><i class="fa-regular fa-bell"></i><span id="notifCount" class="notif-count" style="display:none;">0</span></button>
        <div id="notifPanel" class="notif-panel" style="display:none;"></div>
        <a href="#" class="user-profile" id="profileTrigger">
          <span class="user-name">Hi, <?php echo htmlspecialchars($firstName); ?></span>
          <img src="<?php echo $profilePicUrl; ?>" alt="Profile" class="user-avatar" id="headerProfileImg">
        </a>
      </div>
    </header>

    <div class="content-wrapper">
      <div class="right-panel">
        
        <!-- ACTIVE REQUESTS PANEL -->
        <div class="panel-section" id="panel-requests">
          <div class="activity-list-header">
            <div>My Requests</div>
            <div class="search-bar">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" placeholder="Search by code or keyword" class="request-search" data-target="active">
            </div>
          </div>

          <div class="item-list" id="list-active">
            <?php if (empty($activeActivities)): ?>
              <div style="padding:20px; text-align:center; color:#777;">No active requests.</div>
            <?php else: ?>
              <?php foreach ($activeActivities as $act):
                  $statusClass = 'status-pending';
                  $s = strtolower($act['status']);
                  if (strpos($s, 'approv')!==false || strpos($s, 'resolved')!==false || strpos($s, 'ongoing')!==false) $statusClass = 'status-ongoing';
                  elseif (strpos($s, 'denied')!==false || strpos($s, 'reject')!==false) $statusClass = 'status-denied';
                  elseif (strpos($s, 'cancel')!==false) $statusClass = 'status-cancelled';
                  $displayStatus = ucfirst($act['status']);
              ?>
              <div class="list-item" data-ref-code="<?php echo htmlspecialchars($act['ref_code']); ?>" data-status="<?php echo htmlspecialchars($act['status']); ?>" data-type="<?php echo htmlspecialchars($act['type']); ?>" data-payment-status="<?php echo htmlspecialchars($act['payment_status'] ?? ''); ?>">
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

        <!-- HISTORY PANEL -->
        <div class="panel-section" id="panel-history" style="display:none;">
          <div class="activity-list-header">
            <div>History</div>
            <div class="search-bar">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" placeholder="Search history..." class="request-search" data-target="history">
            </div>
          </div>

          <div class="item-list" id="list-history">
            <?php if (empty($historyActivities)): ?>
              <div style="padding:20px; text-align:center; color:#777;">No history records.</div>
            <?php else: ?>
              <?php foreach ($historyActivities as $act):
                  $statusClass = 'status-pending';
                  $s = strtolower($act['status']);
                  if (strpos($s, 'approv')!==false || strpos($s, 'resolved')!==false || strpos($s, 'ongoing')!==false) $statusClass = 'status-completed'; // Completed/Past
                  elseif (strpos($s, 'denied')!==false || strpos($s, 'reject')!==false) $statusClass = 'status-denied';
                  elseif (strpos($s, 'cancel')!==false) $statusClass = 'status-cancelled';
                  elseif (strpos($s, 'expired')!==false) $statusClass = 'status-denied';
                  $displayStatus = ucfirst($act['status']);
              ?>
              <div class="list-item" data-ref-code="<?php echo htmlspecialchars($act['ref_code']); ?>" data-status="<?php echo htmlspecialchars($act['status']); ?>" data-type="<?php echo htmlspecialchars($act['type']); ?>" data-payment-status="<?php echo htmlspecialchars($act['payment_status'] ?? ''); ?>">
                 <div class="item-icon"><i class="fa-solid fa-chevron-right"></i></div>
                 <div class="item-content">
                   <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                     <div>
                       <span class="status-badge <?php echo $statusClass; ?>"><?php echo $displayStatus; ?></span>
                       <span class="item-title"><?php echo htmlspecialchars($act['title']); ?></span>
                       <span class="item-details">- <?php echo htmlspecialchars($act['details']); ?></span>
                     </div>
                     <div class="item-time"><?php echo date('M d, Y', strtotime($act['date'])); ?></div>
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

      </div>
    </div>
  </main>
  </div>
</div>

<div id="activityModal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <div id="activityModalBody"></div>
  </div>
</div>

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
</div>

<div id="profileModal" class="profile-modal">
  <div class="profile-modal-content">
    <button class="close-profile-modal">&times;</button>
    <div class="profile-header">
      <div class="profile-icon-large">
        <img src="<?php echo $profilePicUrl; ?>" alt="Profile" id="profileModalImg">
        <label for="profileUpload" class="profile-edit-overlay" title="Change Profile Picture">
           <i class="fa-solid fa-camera"></i>
        </label>
        <input type="file" id="profileUpload" accept="image/*" style="display:none">
      </div>
      <div class="profile-title">
        <h3><?php echo htmlspecialchars($fullName); ?></h3>
        <span class="profile-role">Visitor</span>
      </div>
    </div>
    <div class="profile-details">
      <div class="detail-row">
        <div class="detail-label">Name</div>
        <div class="detail-value"><?php echo htmlspecialchars($fullName); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Email</div>
        <div class="detail-value"><?php echo htmlspecialchars($user_data['email'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Contact Number</div>
        <div class="detail-value"><?php echo htmlspecialchars($user_data['phone'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Sex</div>
        <div class="detail-value"><?php echo htmlspecialchars($user_data['sex'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Birthdate</div>
        <div class="detail-value"><?php echo htmlspecialchars($birthdateDisplay); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Change Password</div>
        <div class="detail-value" style="width:100%;">
          <button type="button" id="openChangePasswordVisitor" style="background:#23412e; color:#fff; border:none; padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:600;">Change Password</button>
        </div>
      </div>
    </div>
    <div class="profile-actions">
       <a href="logout.php" class="btn-logout-modal">Log Out</a>
    </div>
  </div>
</div>

<!-- Hidden Pass Template for Generation -->
<div id="hiddenPassCard" style="position:fixed; left:-9999px; top:0; width:400px; background:#1e1e1e; color:#fff; font-family:'Poppins',sans-serif; border-radius:16px; overflow:hidden; padding-bottom:20px;">
    <div class="header" style="background:#000; padding:15px; text-align:center; border-bottom:1px solid #333;">
        <img src="images/logo.svg" style="height:32px; vertical-align:middle;">
        <span style="margin-left:10px; font-weight:600; font-size:1.1rem; color:#e5ddc6; vertical-align:middle;">Victorian Heights</span>
    </div>
    <div class="status-banner" style="padding:30px 20px; text-align:center; background-color:#22c55e; color:#000;">
        <div style="font-size:1.8rem; font-weight:800; text-transform:uppercase; margin-bottom:8px;">VALID ENTRY PASS</div>
        <div style="font-size:1rem; font-weight:500;">Access Granted</div>
    </div>
    <div class="qr-section" style="background:#fff; padding:20px; text-align:center;">
        <img id="passQR" src="" style="width:180px; height:180px; display:block; margin:0 auto;">
        <div style="color:#333; margin-top:10px; font-size:0.85rem; font-weight:500;">Present this QR code to the guard</div>
    </div>
    <div class="details" style="padding:24px;">
        <div style="margin-bottom:12px; font-size:0.85rem; color:#9bd08f; font-weight:700; text-transform:uppercase;">Pass Details</div>
        
        <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #333; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Pass Type</span>
            <span style="font-weight:600; font-size:1rem; color:#eee;">Visitor</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #333; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Name</span>
            <span id="passName" style="font-weight:600; font-size:1rem; color:#eee;"></span>
        </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #333; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Ref Code</span>
            <span id="passRef" style="font-weight:600; font-size:1rem; color:#eee; font-family:monospace;"></span>
        </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:0; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Validity</span>
            <span id="passDate" style="font-weight:600; font-size:1rem; color:#eee;"></span>
        </div>
    </div>
    <div style="text-align:center; color:#666; font-size:0.8rem; margin-top:20px;">
        VictorianPass Validation System &copy; <?php echo date('Y'); ?>
    </div>
</div>

<script>
(function(){
  var visitorName = "<?php echo htmlspecialchars($fullName); ?>";
  
  // Tab Switching
  var navItems = document.querySelectorAll('.nav-item[data-section]');
  var sections = document.querySelectorAll('.panel-section');
  
  navItems.forEach(function(item) {
      item.addEventListener('click', function(e) {
          e.preventDefault();
          var sectionId = this.getAttribute('data-section');
          
          // Update Nav
          navItems.forEach(function(n) { n.classList.remove('active'); });
          this.classList.add('active');
          
          // Update Sections
          sections.forEach(function(s) { s.style.display = 'none'; });
          var target = document.getElementById(sectionId);
          if (target) target.style.display = 'block';
      });
  });

  // Search Logic
  var searchInputs = document.querySelectorAll('.request-search');
  searchInputs.forEach(function(inp){
    inp.addEventListener('input', function(){
        var targetType = this.getAttribute('data-target'); // active or history
        var listId = 'list-' + targetType;
        var listContainer = document.getElementById(listId);
        if(!listContainer) return;
        
        var q = (this.value || '').toLowerCase();
        var items = listContainer.querySelectorAll('.list-item');
        
        items.forEach(function(li){
            var text = li.textContent.toLowerCase();
            li.style.display = text.indexOf(q) !== -1 ? '' : 'none';
        });
    });
  });

  var prevStatuses={};
  document.querySelectorAll('.item-list .list-item').forEach(function(li){
    var code=li.getAttribute('data-ref-code')||'';
    var st=li.getAttribute('data-status')||'';
    if(code) prevStatuses[code]=st;
    
    // Expand/Collapse click
    li.addEventListener('click', function(e){
        // Avoid triggering if clicked on button/link
        if(e.target.closest('button') || e.target.closest('a')) return;
        
        var wasExpanded = this.classList.contains('expanded');
        // Collapse all others
        // document.querySelectorAll('.list-item.expanded').forEach(function(el){ el.classList.remove('expanded'); });
        
        if(!wasExpanded){
            this.classList.add('expanded');
            var icon = this.querySelector('.item-icon i');
            if(icon) { icon.classList.remove('fa-chevron-right'); icon.classList.add('fa-chevron-down'); }
            
            var extra = this.querySelector('.item-extra');
            if(extra && extra.getAttribute('data-loaded')!=='1'){
                buildExtraContent(this, extra);
                extra.setAttribute('data-loaded','1');
            }
        } else {
            this.classList.remove('expanded');
            var icon = this.querySelector('.item-icon i');
            if(icon) { icon.classList.remove('fa-chevron-down'); icon.classList.add('fa-chevron-right'); }
        }
    });
  });

  function statusClassFor(s){
    s=(s||'').toLowerCase();
    if(s.indexOf('approv')!==-1||s.indexOf('resolved')!==-1||s.indexOf('ongoing')!==-1) return 'status-ongoing';
    if(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1) return 'status-denied';
    if(s.indexOf('cancel')!==-1) return 'status-cancelled';
    if(s.indexOf('expired')!==-1) return 'status-denied';
    if(s.indexOf('complete')!==-1) return 'status-completed';
    return 'status-pending';
  }
  function fmtLabel(s){
    s=String(s||'').toLowerCase();
    return s.charAt(0).toUpperCase()+s.slice(1);
  }

  // Notification Logic
  var notifCountEl=document.getElementById('notifCount');
  var notifBtn=document.getElementById('notifBtn');
  var notifPanel=document.getElementById('notifPanel');
  var notifItems=[];
  
  function addNotificationEntry(code,status,li){
    if(!code) return;
    var key=code+'|'+String(status||'');
    for(var i=0;i<notifItems.length;i++){ if(notifItems[i].key===key) return; }
    var type=(li.getAttribute('data-type')||'').toLowerCase();
    var titleEl=li.querySelector('.item-title');
    var title=titleEl?titleEl.textContent.trim():(type==='reservation'?'Reservation Schedule':'Request Update');
    var timeText='';
    try{ timeText=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'}); }catch(e){ timeText=''; }
    notifItems.push({
      key:key,
      code:code,
      status:fmtLabel(status),
      title:title,
      type:type,
      time:timeText,
      message:'Code: '+code+' • '+fmtLabel(status)
    });
  }
  
  function renderNotifPanel(){
    if(!notifPanel) return;
    var header='<div class="notif-panel-header"><div class="notif-panel-title">Notifications</div><button type="button" class="notif-panel-close" aria-label="Close">&times;</button></div>';
    if(!notifItems.length){
      notifPanel.innerHTML=header+'<div class="notif-panel-body"><div class="notif-empty">No recent updates</div></div>';
    } else {
      var html='';
      for(var i=notifItems.length-1;i>=0;i--){
        var it=notifItems[i]||{};
        var code=String(it.code||'').replace(/[<>]/g,'');
        var title=String(it.title||'').replace(/[<>]/g,'');
        var status=String(it.status||'').replace(/[<>]/g,'');
        var message=String(it.message||'').replace(/[<>]/g,'');
        var time=String(it.created_at||it.time||'').replace(/[<>]/g,'');
        var subText=message || ('Code: '+code+' • '+status);
        html+='<div class="notif-item" data-code="'+code+'"><div class="notif-item-main"><div class="notif-item-title">'+title+'</div><div class="notif-item-sub">'+subText+'</div>';
        if(time) html+='<div class="notif-item-time">'+time+'</div>';
        html+='</div></div>';
      }
      notifPanel.innerHTML=header+'<div class="notif-panel-body">'+html+'</div>';
    }
    var closeBtn=notifPanel.querySelector('.notif-panel-close');
    if(closeBtn){
      closeBtn.addEventListener('click',function(e){
        e.stopPropagation();
        notifPanel.style.display='none';
      });
    }
  }

  function markNotificationsRead(){
    return fetch('dashboardvisitor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'mark_notifications_read'})})
      .then(function(r){ return r.json(); })["catch"](function(){});
  }
  
  var prevStatuses={};
  function refreshStatuses(){
    fetch('dashboardvisitor.php?ajax=1')
      .then(function(r){return r.json();})
      .then(function(data){
        if(!data||!data.active||!data.history) return;
        
        function fmtLabel(s){
          s=String(s||'').toLowerCase();
          return s.charAt(0).toUpperCase()+s.slice(1);
        }
        
        function updateList(items,listId,panelId){
          var container=document.getElementById(listId);
          if(!container) return;
          var historyList=document.getElementById('list-history');
          var activeList=document.getElementById('list-active');
          
          // Track which codes should exist in this list
          var codesInResponse={};
          items.forEach(function(item){
            codesInResponse[String(item.ref_code||'')]=true;
          });
          
          // Update or preserve existing items
          items.forEach(function(newItem){
            var code=String(newItem.ref_code||'');
            if(!code) return;
            
            var li=container.querySelector('.list-item[data-ref-code="'+code.replace(/"/g,'&quot;')+'"]');
            if(!li) return;
            
            var oldStatus=li.getAttribute('data-status')||'';
            var newStatus=newItem.status||'';
            var newStatusLower=String(newStatus||'').toLowerCase();
            
            // Always update the status attribute to keep in sync with server
            li.setAttribute('data-status',newStatus);
            if(newItem.payment_status !== undefined){
              li.setAttribute('data-payment-status', newItem.payment_status || '');
            }

            if(panelId === 'panel-requests' && newStatusLower.indexOf('cancel') !== -1 && historyList && activeList){
              var safeCode=code.replace(/"/g,'&quot;');
              var existing=historyList.querySelector('.list-item[data-ref-code="'+safeCode+'"]');
              if(existing){
                li.remove();
              } else {
                var titleEl=li.querySelector('.item-title');
                if(titleEl && newItem.title) titleEl.textContent=newItem.title;
                var badge=li.querySelector('.status-badge');
                if(badge){
                  badge.textContent=fmtLabel(newStatus);
                  badge.className='status-badge '+statusClassFor(newStatus);
                }
                var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
                if(noHistoryMsg) noHistoryMsg.remove();
                historyList.insertBefore(li, historyList.firstChild);
                li.style.display='';
                li.classList.remove('expanded');
              }
              if(activeList.querySelectorAll('.list-item').length===0){
                var emptyMsg=activeList.querySelector('div[style*="text-align:center"]');
                if(!emptyMsg){
                  var emptyDiv=document.createElement('div');
                  emptyDiv.style.cssText='padding:20px; text-align:center; color:#777;';
                  emptyDiv.textContent='No active requests.';
                  activeList.appendChild(emptyDiv);
                }
              }
              return;
            }
            
            // Only update badge if status changed (skip for history items - they're permanent)
            if(oldStatus !== newStatus && panelId !== 'panel-history'){
              var badge=li.querySelector('.status-badge');
              if(badge){
                badge.textContent=fmtLabel(newStatus);
                badge.className='status-badge '+statusClassFor(newStatus);
              }
              
              // Notifications (only for admin-actioned items: approved or denied)
              if(oldStatus && panelId === 'panel-requests'){
                var isApproved=newStatusLower.indexOf('approv')!==-1;
                var isDenied=newStatusLower.indexOf('denied')!==-1 || newStatusLower.indexOf('reject')!==-1;
                
                if(isApproved || isDenied){
                  li.classList.add('status-updated');
                  addNotificationEntry(code,newStatus,li);
                  if(notifCountEl){
                    var c=parseInt(notifCountEl.textContent||'0')+1;
                    notifCountEl.textContent=c;
                    notifCountEl.style.display='inline-block';
                  }
                }
              }
              
              prevStatuses[code]=newStatus;
            }
          });
          
          // For history panel: NEVER hide items - they stay until manually deleted
          // For active panel: ensure items in response stay visible
          if(panelId !== 'panel-history'){
            var allItems=container.querySelectorAll('.list-item');
            allItems.forEach(function(item){
              var code=item.getAttribute('data-ref-code')||'';
              if(code && codesInResponse[code]){
                item.style.display='';
              }
            });
          }
        }

        function moveHistoryItems(items){
          var historyList=document.getElementById('list-history');
          var activeList=document.getElementById('list-active');
          if(!historyList||!activeList) return;
          var historyCodes={};
          items.forEach(function(item){
            var code=String(item.ref_code||'');
            if(!code) return;
            historyCodes[code]=true;
            var safeCode=code.replace(/"/g,'&quot;');
            var existing=historyList.querySelector('.list-item[data-ref-code="'+safeCode+'"]');
            if(existing) return;
            var li=activeList.querySelector('.list-item[data-ref-code="'+safeCode+'"]');
            if(!li) return;
            li.setAttribute('data-status', item.status || 'cancelled');
            if(item.payment_status !== undefined){
              li.setAttribute('data-payment-status', item.payment_status || '');
            }
            var titleEl=li.querySelector('.item-title');
            if(titleEl && item.title) titleEl.textContent=item.title;
            var badge=li.querySelector('.status-badge');
            if(badge){
              badge.textContent=fmtLabel(item.status);
              badge.className='status-badge '+statusClassFor(item.status);
            }
            var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
            if(noHistoryMsg) noHistoryMsg.remove();
            historyList.insertBefore(li, historyList.firstChild);
            li.style.display='';
            li.classList.remove('expanded');
          });
          var activeItems=activeList.querySelectorAll('.list-item');
          activeItems.forEach(function(li){
            var code=li.getAttribute('data-ref-code')||'';
            if(code && historyCodes[code]) li.remove();
          });
          if(activeList.querySelectorAll('.list-item').length===0){
            var emptyMsg=activeList.querySelector('div[style*="text-align:center"]');
            if(!emptyMsg){
              var emptyDiv=document.createElement('div');
              emptyDiv.style.cssText='padding:20px; text-align:center; color:#777;';
              emptyDiv.textContent='No active requests.';
              activeList.appendChild(emptyDiv);
            }
          }
        }

        updateList(data.active,'list-active','panel-requests');
        if(data.history) moveHistoryItems(data.history);
        
        if(Array.isArray(data.notifications)){
          notifItems = data.notifications;
          renderNotifPanel();
        }
        if(notifCountEl){
          var uc = parseInt(data.unread_count||'0',10);
          notifCountEl.textContent = uc;
          notifCountEl.style.display = uc > 0 ? 'inline-block' : 'none';
        }
      })["catch"](function(){});
  }
  
  refreshStatuses();
  setInterval(refreshStatuses,10000);
  
  // Modal Logic
  var cancelModal=document.getElementById('cancelModal');
  var cancelModalKeep=cancelModal?cancelModal.querySelector('.cancel-modal-keep'):null;
  var cancelModalConfirm=cancelModal?cancelModal.querySelector('.cancel-modal-confirm'):null;
  var cancelModalClose=cancelModal?cancelModal.querySelector('.cancel-modal-close'):null;
  var cancelModalRef=null;
  var cancelModalLi=null;
  var modalAction = 'cancel';

  window.openCancelModal = function(li,ref){
    if(!cancelModal) return;
    cancelModalRef=ref;
    cancelModalLi=li;
    modalAction = 'cancel';
    
    var h3 = cancelModal.querySelector('h3');
    var pBody = cancelModal.querySelector('.cancel-modal-body p:first-child');
    var pNote = cancelModal.querySelector('.cancel-modal-note');
    var btnKeep = cancelModal.querySelector('.cancel-modal-keep');
    var btnConfirm = cancelModal.querySelector('.cancel-modal-confirm');
    
    if(h3) h3.textContent = 'Cancel Reservation';
    if(pBody) pBody.textContent = 'Are you sure you want to cancel this reservation?';
    if(pNote) {
      pNote.style.display = 'block';
      pNote.textContent = 'Note: Downpayment is non-refundable. Cancelling will forfeit your downpayment.';
    }
    if(btnKeep) btnKeep.textContent = 'Keep Reservation';
    if(btnConfirm) btnConfirm.textContent = 'Confirm Cancel';
    
    cancelModal.style.display='flex';
  };

  window.openDeleteModal = function(li,ref){
    if(!cancelModal) return;
    cancelModalRef=ref;
    cancelModalLi=li;
    modalAction = 'delete';
    
    var h3 = cancelModal.querySelector('h3');
    var pBody = cancelModal.querySelector('.cancel-modal-body p:first-child');
    var pNote = cancelModal.querySelector('.cancel-modal-note');
    var btnKeep = cancelModal.querySelector('.cancel-modal-keep');
    var btnConfirm = cancelModal.querySelector('.cancel-modal-confirm');
    
    if(h3) h3.textContent = 'Delete Request';
    if(pBody) pBody.textContent = 'Are you sure you want to permanently delete this request from your history?';
    if(pNote) pNote.style.display = 'none';
    if(btnKeep) btnKeep.textContent = 'Keep Request';
    if(btnConfirm) btnConfirm.textContent = 'Confirm Delete';
    
    cancelModal.style.display='flex';
  };

  function closeCancelModalVisitor(){
    if(!cancelModal) return;
    cancelModal.style.display='none';
    cancelModalRef=null;
    cancelModalLi=null;
  }
  
  function performCancelVisitor(){
    var ref=cancelModalRef;
    var li=cancelModalLi;
    if(!ref||!li){
      closeCancelModalVisitor();
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
      // Success - Update UI without alert
      li.setAttribute('data-status','cancelled');
      if(typeof prevStatuses !== 'undefined') prevStatuses[ref]='cancelled';

      // Update Title immediately
      var titleEl = li.querySelector('.item-title');
      if (titleEl && titleEl.textContent.indexOf('Cancelled') === -1) {
          titleEl.textContent += ' - Cancelled';
      }
      
      // Update Status Badge
      var badge=li.querySelector('.status-badge');
      if(badge){
        badge.textContent=fmtLabel('cancelled');
        badge.classList.remove('status-pending','status-ongoing','status-denied','status-cancelled','status-completed','status-expired');
        badge.classList.add(statusClassFor('cancelled'));
      }
      
      // Rebuild Extra Content
      var extraEl=li.querySelector('.item-extra');
      if(extraEl){
        extraEl.setAttribute('data-loaded','0');
        extraEl.innerHTML='';
        if(li.classList.contains('expanded')){
          buildExtraContent(li,extraEl);
          extraEl.setAttribute('data-loaded','1');
        }
      }

      // Remove from active list and move to history
      var activeList = document.getElementById('list-active');
      var historyList = document.getElementById('list-history');
      
      if(activeList && historyList){
        // Check if list is now empty and show "No active requests" message if needed
        var remainingActiveItems = activeList.querySelectorAll('.list-item');
        
        // Remove from active list
        li.remove();
        
        // If no more items in active list, show empty message
        if(remainingActiveItems.length === 1){ // Will be 0 after remove
          var emptyDiv = document.createElement('div');
          emptyDiv.style.cssText = 'padding:20px; text-align:center; color:#777;';
          emptyDiv.textContent = 'No active requests.';
          activeList.appendChild(emptyDiv);
        }
        
        // Remove the "No history records" message if present
        var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
        if(noHistoryMsg) noHistoryMsg.remove();
        
        // Add to history list at the top
        historyList.insertBefore(li, historyList.firstChild);
        li.style.display = '';
        li.classList.remove('expanded');
        
        // Switch to history tab
        var historyTab = document.querySelector('.nav-item[data-section="panel-history"]');
        if(historyTab){
          var navItems = document.querySelectorAll('.nav-item[data-section]');
          navItems.forEach(function(n){ n.classList.remove('active'); });
          historyTab.classList.add('active');
          
          var sections = document.querySelectorAll('.panel-section');
          sections.forEach(function(s){ s.style.display = 'none'; });
          document.getElementById('panel-history').style.display = 'block';
        }
      }
      
      closeCancelModalVisitor();

    })["catch"](function(){
      // alert('Network error. Please try again.'); // Suppress as per request
    });
  }

  function performDeleteVisitor(){
    var ref=cancelModalRef;
    var li=cancelModalLi;
    if(!ref||!li){
      closeCancelModalVisitor();
      return;
    }
    fetch('status.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'delete',code:ref})
    }).then(function(r){return r.json();}).then(function(data){
      if(!data||!data.success){
        alert(data && data.message ? data.message : 'Unable to delete request.');
        return;
      }
      li.remove();
      closeCancelModalVisitor();
    })["catch"](function(){
      // alert('Network error. Please try again.');
    });
  }

  if(cancelModalKeep){
    cancelModalKeep.addEventListener('click',function(){
      closeCancelModalVisitor();
    });
  }
  if(cancelModalClose){
    cancelModalClose.addEventListener('click',function(){
      closeCancelModalVisitor();
    });
  }
  if(cancelModalConfirm){
    cancelModalConfirm.addEventListener('click',function(){
      if(modalAction === 'delete'){
        performDeleteVisitor();
      } else {
        performCancelVisitor();
      }
    });
  }

  // Sidebar Toggle Logic
  var menuToggle = document.getElementById('menuToggle');
  var sidebar = document.querySelector('.sidebar');
  var overlay = document.getElementById('sidebarOverlay');

  if(menuToggle && sidebar && overlay) {
      function closeSidebar() {
          sidebar.classList.remove('open');
          overlay.classList.remove('show');
      }

      menuToggle.addEventListener('click', function() {
          sidebar.classList.add('open');
          overlay.classList.add('show');
      });

      overlay.addEventListener('click', closeSidebar);
  }

  // Activity Modal Logic (View Details)
  var activityModal = document.getElementById('activityModal');
  var activityModalBody = document.getElementById('activityModalBody');
  var activityModalClose = activityModal ? activityModal.querySelector('.close') : null;

  window.downloadQRImage = function(code) {
      var img = document.querySelector('#activityModalBody img[alt="QR Code"]');
      if (!img) { alert('QR Code not found'); return; }
      fetch(img.src)
        .then(resp => resp.blob())
        .then(blob => {
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = 'QR_' + code + '.png';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
        })
        .catch(() => alert('Could not download image.'));
  };

  window.openActivityModal = function(refCode) {
      if(!activityModal || !activityModalBody) {
          activityModal = document.getElementById('activityModal');
          activityModalBody = document.getElementById('activityModalBody');
          if(!activityModal || !activityModalBody) return;
      }
      
      activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;">Loading...</div>';
      activityModal.style.display = 'flex';

      fetch('get_activity_details.php?code=' + encodeURIComponent(refCode))
        .then(r => r.text())
        .then(html => {
            activityModalBody.innerHTML = html;
        })
        .catch(e => {
            activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error loading details.</div>';
        });
  };

  if(activityModalClose) {
      activityModalClose.addEventListener('click', function() {
          activityModal.style.display = 'none';
      });
  }
  
  // Notification button handler
  if(notifBtn && notifPanel){
    notifBtn.addEventListener('click',function(e){
      e.stopPropagation();
      notifPanel.style.display=(notifPanel.style.display==='block'?'none':'block');
      if(notifPanel.style.display==='block') renderNotifPanel();
      document.querySelectorAll('.item-list .list-item.status-updated').forEach(function(li){
        li.classList.remove('status-updated');
      });
      if(notifCountEl){
        notifCountEl.textContent='0';
        notifCountEl.style.display='none';
      }
      if(notifPanel.style.display==='block'){
        markNotificationsRead();
      }
    });
  }
  document.addEventListener('click',function(e){
    if(!notifPanel || !notifBtn) return;
    if(notifPanel.style.display!=='block') return;
    if(notifPanel.contains(e.target) || notifBtn.contains(e.target)) return;
    notifPanel.style.display='none';
  });
  
  // Close modals when clicking outside
  window.addEventListener('click', function(e) {
      if (e.target === activityModal) {
          activityModal.style.display = 'none';
      }
      if (e.target === cancelModal) {
          cancelModal.style.display = 'none';
      }
  });


  // Helper to build extra content (details)
  function buildExtraContent(li, extra){
    var type=(li.getAttribute('data-type')||'').toLowerCase();
    var status=(li.getAttribute('data-status')||'').toLowerCase();
    var ref=li.getAttribute('data-ref-code')||'';
    var label=fmtLabel(status);
    var statusNote='';
    var s=status.toLowerCase();
    var paymentStatus=(li.getAttribute('data-payment-status')||'').toLowerCase();
    var basePath=window.location.pathname.replace(/\/[^\/]*$/,'');
    var isApproved=s.indexOf('approv')!==-1;

    // Visitor specific notes
    if(isApproved) {
        statusNote='This request is approved. Use this QR pass at the gate.';
    }
    else if(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1) statusNote='This request was denied. Please contact the subdivision office for details.';
    else if(s.indexOf('cancelled')!==-1) statusNote='This request was cancelled by the user.';
    else if(s.indexOf('pending')!==-1||s===''||s==='new') {
        statusNote='This request is pending. Wait for the admin to review it. The QR entry pass will be available after approval.';
    }
    else if(s.indexOf('resolved')!==-1) statusNote='This item has been marked as resolved by the admin.';
    else if(s.indexOf('expired')!==-1) statusNote='This pass is expired and can no longer be used.';
    if(type==='reservation' && paymentStatus==='rejected'){
        statusNote='Payment proof was rejected. Please update your proof of payment.';
    }

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

    var canCancel=(s.indexOf('pending')!==-1||s===''||s==='new');
    var canDelete=(s.indexOf('cancel')!==-1 || s.indexOf('denied')!==-1 || s.indexOf('reject')!==-1 || s.indexOf('expired')!==-1);
    var canUpdateProof=(type==='reservation' && paymentStatus==='rejected');
    
    var html='';
    html+='<div class="item-extra-section">';
    var qrSrcForDownload = '';
    if(isApproved && ref){
        var statusLink=location.origin+basePath+'/status_view.php?code='+encodeURIComponent(ref);
        var qrSrc='https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='+encodeURIComponent(statusLink);
        qrSrcForDownload = qrSrc;
        html+='<div class="item-extra-title">Entry QR Pass</div>';
        html+='<div class="item-extra-body">';
        html+='<div class="item-extra-qr-wrap"><img class="item-extra-qr" src="'+qrSrc+'" alt="Entry QR Code"></div>';
        html+='<div class="item-extra-info">';
    }else{
        html+='<div class="item-extra-title">Entry Request Status</div>';
        html+='<div class="item-extra-body">';
        html+='<div class="item-extra-info-only">';
    }
    
    html+='<div class="item-extra-status"><span class="status-label '+statusClassFor(status)+'">'+label+'</span></div>';
    if(statusNote) html+='<div class="item-extra-note">'+esc(statusNote)+'</div>';
    if(summaryText) html+='<div class="item-extra-summary">'+esc(summaryText)+'</div>';
    
    html+='<div class="item-actions">';
    if(canUpdateProof && ref){
        html+='<button type="button" class="item-extra-link update-proof-btn view-details-btn" data-ref="'+esc(ref)+'">Update Proof</button>';
        html+='<input type="file" class="update-proof-input" accept="image/*" style="display:none;">';
    }
    if(canCancel && ref){
        html+='<button type="button" class="item-extra-cancel" data-ref="'+esc(ref)+'">Cancel Request</button>';
    }
    if(canDelete && ref){
         html+='<button type="button" class="item-extra-delete" data-ref="'+esc(ref)+'" style="padding:6px 12px; font-size:0.85rem; border-radius:6px; background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; cursor:pointer; font-weight:500;"><i class="fa-solid fa-trash"></i> Remove from History</button>';
    }
    
    if(isApproved && ref){
        html+='<button type="button" class="item-extra-link download-qr-btn view-details-btn" data-qr="'+esc(qrSrcForDownload)+'">Download QR code</button>';
        html+='<button type="button" class="item-extra-link view-details-btn" data-ref="'+esc(ref)+'">View details</button>';
    } else {
        html+='<button type="button" class="item-extra-link view-details-btn" data-ref="'+esc(ref)+'">View details</button>';
    }
    html+='</div>';
    html+='</div></div></div>';
    
    extra.innerHTML=html;

    // Attach event listeners for the new buttons
    var cancelBtn = extra.querySelector('.item-extra-cancel');
    if(cancelBtn) cancelBtn.addEventListener('click', function(){ window.openCancelModal(li, ref); });
    
    var deleteBtn = extra.querySelector('.item-extra-delete');
    if(deleteBtn) deleteBtn.addEventListener('click', function(){ window.openDeleteModal(li, ref); });
    
    var viewBtn = extra.querySelector('.view-details-btn');
    if(viewBtn) viewBtn.addEventListener('click', function(){ window.openActivityModal(ref); });

    var downloadBtn = extra.querySelector('.download-qr-btn');
    if(downloadBtn) downloadBtn.addEventListener('click', function(e){
        e.stopPropagation();
        var url = downloadBtn.getAttribute('data-qr') || '';
        if(!url){
          var img = extra.querySelector('.item-extra-qr');
          if(img) url = img.src || '';
        }
        if(!url) return;
        fetch(url)
          .then(function(resp){ return resp.blob(); })
          .then(function(blob){
            var objectUrl = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = objectUrl;
            a.download = 'QR_' + (ref || 'pass') + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(objectUrl);
          })
          .catch(function(){});
    });
    var updateBtn=extra.querySelector('.update-proof-btn');
    var updateInput=extra.querySelector('.update-proof-input');
    if(updateBtn && updateInput && ref){
      updateBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        updateInput.click();
      });
      updateInput.addEventListener('change',function(){
        if(!updateInput.files || !updateInput.files[0]) return;
        var fd=new FormData();
        fd.append('ref_code', ref);
        fd.append('receipt', updateInput.files[0]);
        updateBtn.disabled=true;
        fetch('upload_receipt.php',{method:'POST',body:fd})
          .then(function(r){ return r.json(); })
          .then(function(data){
            if(!data || !data.success){
              updateBtn.disabled=false;
              alert(data && data.message ? data.message : 'Upload failed.');
              return;
            }
            li.setAttribute('data-payment-status','pending');
            extra.setAttribute('data-loaded','0');
            extra.innerHTML='';
            if(li.classList.contains('expanded')){
              buildExtraContent(li, extra);
              extra.setAttribute('data-loaded','1');
            }
          })
          ["catch"](function(){
            updateBtn.disabled=false;
            alert('Network error. Please try again.');
          });
      });
    }
  }

})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var profileModal = document.getElementById("profileModal");
    var profileTrigger = document.getElementById("profileTrigger");
    var profileClose = document.getElementsByClassName("close-profile-modal")[0];

    if(profileTrigger && profileModal) {
        profileTrigger.onclick = function(e) {
            e.preventDefault();
            profileModal.style.display = "block";
        };
    }

    if(profileClose && profileModal) {
        profileClose.onclick = function() {
            profileModal.style.display = "none";
        };
    }

    window.addEventListener('click', function(event) {
        if (event.target == profileModal) {
            profileModal.style.display = "none";
        }
    });

    var profileUpload = document.getElementById('profileUpload');
    if(profileUpload) {
        profileUpload.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                var formData = new FormData();
                formData.append('profile_pic', this.files[0]);

                var img = document.getElementById('profileModalImg');
                img.style.opacity = '0.5';

                fetch('upload_profile_pic.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    img.style.opacity = '1';
                    if (data.success) {
                        if(img) img.src = data.new_url;
                        var headerImg = document.getElementById('headerProfileImg');
                        if(headerImg) headerImg.src = data.new_url;
                    } else {
                        alert(data.message || 'Upload failed');
                    }
                })
                .catch(error => {
                    img.style.opacity = '1';
                    console.error('Error:', error);
                    alert('An error occurred during upload.');
                });
            }
        });
    }
});
</script>
<div id="changePasswordModalVisitor" class="profile-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); align-items:center; justify-content:center; z-index:3000;">
  <div class="vp-logout-modal" style="position:relative; top:auto; right:auto; margin:0; width:350px; max-width:90vw; max-height:calc(100vh - 100px);">
    <button class="close-change-password" style="position:absolute; right:12px; top:10px; background:transparent; border:none; font-size:20px; color:#fff; cursor:pointer;">&times;</button>
    <div style="margin-bottom:12px; font-weight:700; font-size:1.2rem;">Change Password</div>
    <?php if ($pwdMsg !== '') { ?>
      <div style="margin-bottom:12px; padding:10px 12px; border-radius:8px; font-size:0.9rem; <?php echo $pwdOk ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0' : 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca'; ?>">
        <?php echo htmlspecialchars($pwdMsg); ?>
      </div>
    <?php } ?>
    <form method="post" action="dashboardvisitor.php" style="display:grid; grid-template-columns:1fr; gap:8px; max-width:420px;">
      <input type="hidden" name="change_password" value="1">
      <input type="password" name="current_password" placeholder="Current Password" required style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;">
      <input type="password" name="new_password" placeholder="New Password (min 8 chars)" required style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;">
      <input type="password" name="confirm_password" placeholder="Confirm New Password" required style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;">
      <button type="submit" style="background:#23412e; color:#fff; border:none; padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:600;">Update Password</button>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var m = document.getElementById('changePasswordModalVisitor');
  var openBtn = document.getElementById('openChangePasswordVisitor');
  var closeBtn = document.querySelector('#changePasswordModalVisitor .close-change-password');
  if (openBtn && m) {
    openBtn.onclick = function(){ m.style.display = 'flex'; };
  }
  if (closeBtn && m) {
    closeBtn.onclick = function(){ m.style.display = 'none'; };
  }
  window.addEventListener('click', function(e){
    if (e.target === m) { m.style.display = 'none'; }
  });
  <?php if ($pwdMsg !== '') { echo "if(m){ m.style.display='flex'; }"; } ?>
});
</script>
<script src="js/logout-modal.js"></script>
</body>
</html>
