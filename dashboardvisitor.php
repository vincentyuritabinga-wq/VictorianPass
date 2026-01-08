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

// Fetch entry passes / reservations
$reservations = [];
if ($con) {
    // Join reservations with entry_passes (if needed) or just fetch by user_id
    // Note: In entrypass.php, we insert into reservations with user_id.
    $stmt = $con->prepare("
        SELECT r.ref_code, r.amenity, r.start_date, r.status, r.approval_status, r.created_at, ep.valid_id_path
        FROM reservations r
        LEFT JOIN entry_passes ep ON r.entry_pass_id = ep.id
        WHERE r.user_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $reservations[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Visitor Dashboard - VictorianPass</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { margin:0; font-family:'Poppins',sans-serif; background:#f5f5f5; color:#333; display:flex; min-height:100vh; }
    
    /* Sidebar */
    .sidebar { width:260px; background:#2b2b2b; color:#fff; display:flex; flex-direction:column; position:fixed; height:100%; left:0; top:0; }
    .logo-area { padding:20px; display:flex; align-items:center; gap:10px; border-bottom:1px solid #444; }
    .logo-area img { width:32px; }
    .logo-area span { font-size:1.1rem; font-weight:600; color:#f2c24f; }
    .nav-links { padding:20px 0; flex:1; }
    .nav-item { display:flex; align-items:center; padding:12px 25px; color:#aaa; text-decoration:none; transition:0.3s; }
    .nav-item:hover, .nav-item.active { background:#3a3a3a; color:#fff; border-left:4px solid #f2c24f; }
    .nav-item i { margin-right:10px; width:20px; text-align:center; }
    .user-mini { padding:20px; border-top:1px solid #444; display:flex; align-items:center; gap:10px; }
    .user-mini img { width:40px; height:40px; border-radius:50%; object-fit:cover; background:#fff; }
    .user-mini div { overflow:hidden; }
    .user-mini h4 { margin:0; font-size:0.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .user-mini p { margin:0; font-size:0.75rem; color:#888; }

    /* Main Content */
    .main-content { margin-left:260px; flex:1; padding:30px; }
    .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; }
    .header h1 { margin:0; font-size:1.8rem; color:#23412e; }
    .btn-create { background:#23412e; color:#fff; padding:10px 20px; text-decoration:none; border-radius:6px; font-weight:500; display:inline-flex; align-items:center; gap:8px; }
    .btn-create:hover { background:#1a3322; }

    /* Cards */
    .stats-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:30px; }
    .stat-card { background:#fff; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
    .stat-card h3 { margin:0 0 5px 0; font-size:2rem; color:#f2c24f; }
    .stat-card p { margin:0; color:#666; font-size:0.9rem; }

    /* Table */
    .table-container { background:#fff; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:15px 20px; text-align:left; border-bottom:1px solid #eee; }
    th { background:#f9f9f9; font-weight:600; color:#555; }
    tr:last-child td { border-bottom:none; }
    .status-badge { padding:5px 10px; border-radius:20px; font-size:0.8rem; font-weight:500; }
    .status-pending { background:#fff3cd; color:#856404; }
    .status-approved { background:#d4edda; color:#155724; }
    .status-rejected { background:#f8d7da; color:#721c24; }
    .status-expired { background:#e2e3e5; color:#383d41; }
    
    @media (max-width: 768px) {
        .sidebar { width:70px; }
        .logo-area span, .user-mini div, .nav-item span { display:none; }
        .main-content { margin-left:70px; padding:20px; }
    }
  </style>
</head>
<body>

  <div class="sidebar">
    <div class="logo-area">
      <img src="images/logo.svg" alt="Logo">
      <span>VictorianPass</span>
    </div>
    <div class="nav-links">
      <a href="dashboardvisitor.php" class="nav-item active">
        <i>📊</i> <span>Dashboard</span>
      </a>
      <a href="reserve.php" class="nav-item">
        <i>🎫</i> <span>Reserve Amenity</span>
      </a>
      <a href="mainpage.php" class="nav-item">
        <i>🏠</i> <span>Home</span>
      </a>
      <a href="logout.php" class="nav-item">
        <i>🚪</i> <span>Log Out</span>
      </a>
    </div>
    <div class="user-mini">
      <img src="images/mainpage/profile.jpg" alt="Profile">
      <div>
        <h4><?php echo htmlspecialchars($user_data['first_name']); ?></h4>
        <p>Visitor</p>
      </div>
    </div>
  </div>

  <div class="main-content">
    <div class="header">
      <h1>My Reservations</h1>
      <a href="reserve.php" class="btn-create">
        <span>+</span> Reserve Amenity
      </a>
    </div>

    <div class="stats-grid">
      <div class="stat-card">
        <h3><?php echo count($reservations); ?></h3>
        <p>Total Requests</p>
      </div>
      <!-- Add more stats if needed -->
    </div>

    <div class="table-container">
      <?php if (empty($reservations)): ?>
        <div style="padding:40px; text-align:center; color:#888;">
          <p>No reservations found. Reserve one to get started!</p>
        </div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Ref Code</th>
              <th>Amenity / Purpose</th>
              <th>Visit Date</th>
              <th>Status</th>
              <th>Created At</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reservations as $r): ?>
              <?php 
                $statusClass = 'status-pending';
                if ($r['approval_status'] === 'approved') $statusClass = 'status-approved';
                elseif ($r['approval_status'] === 'denied' || $r['approval_status'] === 'rejected') $statusClass = 'status-rejected';
                
                // Override if main status is expired
                if ($r['status'] === 'expired') $statusClass = 'status-expired';
              ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($r['ref_code']); ?></strong></td>
                <td><?php echo htmlspecialchars($r['amenity']); ?></td>
                <td><?php echo htmlspecialchars($r['start_date']); ?></td>
                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo ucfirst($r['approval_status']); ?></span></td>
                <td><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

</body>
</html>
