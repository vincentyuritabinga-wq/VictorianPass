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
    $stmt = $con->prepare("SELECT first_name, last_name, email, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $user_data = $res->fetch_assoc();
    }
    $stmt->close();
}
$fullName = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));

// Fetch Activities
$activities = [];

// Reservations
$stmt = $con->prepare("SELECT 'reservation' as type, amenity, start_date, end_date, status, created_at, ref_code FROM reservations WHERE user_id = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $start = $row['start_date'];
        $end = $row['end_date'] ?? null;
        $dateStr = '';
        if (!empty($start) && !empty($end)) {
            $dateStr = date('M d, Y', strtotime($start)) . ' - ' . date('M d, Y', strtotime($end));
        } elseif (!empty($start)) {
            $dateStr = date('M d, Y', strtotime($start));
        } else {
            $dateStr = 'Date not set';
        }
        $activities[] = [
            'type' => 'reservation',
            'title' => 'Reservation Schedule - ' . ($row['amenity'] ?? 'Amenity'),
            'details' => $dateStr,
            'status' => $row['status'] ?? 'pending',
            'date' => $row['created_at'],
            'ref_code' => $row['ref_code'] ?? 'RES'
        ];
    }
    $stmt->close();
}

// Sort by date DESC (though single source is already sorted, good practice if we add more sources)
usort($activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Visitor Dashboard - Victorian Heights</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css">
<!-- FontAwesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
      <a href="#" class="nav-item"><i class="fa-solid fa-circle-question"></i> <span>Help</span></a>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" class="logout-btn" title="Log Out"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
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
             <div class="calendar-day-name">M</div>
             <div class="calendar-day-name">T</div>
             <div class="calendar-day-name">W</div>
             <div class="calendar-day-name">T</div>
             <div class="calendar-day-name">F</div>
             <div class="calendar-day-name">S</div>
             <div class="calendar-day-name">S</div>
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
