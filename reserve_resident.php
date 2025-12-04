<?php
ob_start();
session_start();
include 'connect.php';
$generatedCode = '';
$errorMsg = '';
$canSubmit = true;
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function ensureUnifiedReservationsColumns($con){
  if (!($con instanceof mysqli)) { return; }
  @$con->query("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_code VARCHAR(50) NOT NULL UNIQUE,
    amenity VARCHAR(100) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    persons INT NULL,
    price DECIMAL(10,2) NULL,
    downpayment DECIMAL(10,2) NULL,
    receipt_path VARCHAR(255) NULL,
    user_id INT NULL,
    entry_pass_id INT NULL,
    purpose VARCHAR(255) NULL,
    payment_status ENUM('pending','submitted','verified') NULL,
    approval_status ENUM('pending','approved','denied') DEFAULT 'pending',
    account_type ENUM('visitor','resident') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_ref_code (ref_code)
  ) ENGINE=InnoDB");
  $cols=['price','downpayment','receipt_path','payment_status','account_type','purpose','entry_pass_id'];
  foreach($cols as $col){
    $c=$con->query("SHOW COLUMNS FROM reservations LIKE '".$con->real_escape_string($col)."'");
    if(!$c || $c->num_rows===0){
      if($col==='price'){ @$con->query("ALTER TABLE reservations ADD COLUMN price DECIMAL(10,2) NULL"); }
      else if($col==='downpayment'){ @$con->query("ALTER TABLE reservations ADD COLUMN downpayment DECIMAL(10,2) NULL"); }
      else if($col==='receipt_path'){ @$con->query("ALTER TABLE reservations ADD COLUMN receipt_path VARCHAR(255) NULL"); }
      else if($col==='payment_status'){ @$con->query("ALTER TABLE reservations ADD COLUMN payment_status ENUM('pending','submitted','verified') NULL"); }
      else if($col==='account_type'){ @$con->query("ALTER TABLE reservations ADD COLUMN account_type ENUM('visitor','resident') NULL"); }
      else if($col==='purpose'){ @$con->query("ALTER TABLE reservations ADD COLUMN purpose VARCHAR(255) NULL"); }
      else if($col==='entry_pass_id'){ @$con->query("ALTER TABLE reservations ADD COLUMN entry_pass_id INT NULL"); }
    }
  }
}
ensureUnifiedReservationsColumns($con);
// Migrate legacy resident_reservations into unified reservations (guard missing columns)
if ($con instanceof mysqli) {
  $tbl = $con->query("SHOW TABLES LIKE 'resident_reservations'");
  if ($tbl && $tbl->num_rows > 0) {
    $has = function($name) use ($con){ $q=$con->query("SHOW COLUMNS FROM resident_reservations LIKE '".$con->real_escape_string($name)."'"); return ($q && $q->num_rows>0); };
    $selParts = [];
    $selParts[] = "rr.ref_code";
    $selParts[] = "rr.amenity";
    $selParts[] = "rr.start_date";
    $selParts[] = "rr.end_date";
    $selParts[] = $has('start_time') ? "rr.start_time" : "NULL AS start_time";
    $selParts[] = $has('end_time') ? "rr.end_time" : "NULL AS end_time";
    $selParts[] = $has('persons') ? "rr.persons" : "NULL AS persons";
    $selParts[] = $has('price') ? "rr.price" : "NULL AS price";
    $selParts[] = $has('downpayment') ? "rr.downpayment" : "NULL AS downpayment";
    $selParts[] = $has('user_id') ? "rr.user_id" : "NULL AS user_id";
    $selParts[] = $has('purpose') ? "rr.purpose" : "NULL AS purpose";
    $selParts[] = $has('approval_status') ? "rr.approval_status" : "'pending' AS approval_status";
    $selParts[] = $has('payment_status') ? "rr.payment_status" : "NULL AS payment_status";
    $select = implode(', ', $selParts);
    @$con->query(
      "INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, user_id, purpose, approval_status, payment_status, account_type)\n".
      "SELECT $select, 'resident' FROM resident_reservations rr\n".
      "WHERE NOT EXISTS (SELECT 1 FROM reservations r WHERE r.ref_code = rr.ref_code)"
    );
  }
}

function generateUniqueRefCodeResident($con){
  $tries=0; $code='';
  while($tries<6){
    $candidate='VP-'.str_pad(rand(0,99999),5,'0',STR_PAD_LEFT);
    $exists=false;
    if($con instanceof mysqli){
      $q1=$con->prepare("SELECT 1 FROM resident_reservations WHERE ref_code=? LIMIT 1");
      $q1->bind_param('s',$candidate); $q1->execute(); $r1=$q1->get_result(); $exists = $exists || ($r1 && $r1->num_rows>0); $q1->close();
      $q2=$con->prepare("SELECT 1 FROM reservations WHERE ref_code=? LIMIT 1");
      $q2->bind_param('s',$candidate); $q2->execute(); $r2=$q2->get_result(); $exists = $exists || ($r2 && $r2->num_rows>0); $q2->close();
      $q3=$con->prepare("SELECT 1 FROM guest_forms WHERE ref_code=? LIMIT 1");
      $q3->bind_param('s',$candidate); $q3->execute(); $r3=$q3->get_result(); $exists = $exists || ($r3 && $r3->num_rows>0); $q3->close();
    }
    if(!$exists){ $code=$candidate; break; }
    $tries++;
  }
  if($code===''){ $code='VP-'.str_pad(rand(0,99999),5,'0',STR_PAD_LEFT); }
  return $code;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
  if (!is_string($tokenPosted) || !hash_equals($_SESSION['csrf_token'] ?? '', $tokenPosted)) {
    $errorMsg = 'Invalid form submission.';
  } else {
    $amenity = isset($_POST['amenity']) ? $_POST['amenity'] : '';
    $start   = isset($_POST['startDate']) ? $_POST['startDate'] : '';
    $end     = isset($_POST['endDate']) ? $_POST['endDate'] : '';
    $startTime = isset($_POST['startTime']) ? $_POST['startTime'] : '';
    $endTime   = isset($_POST['endTime']) ? $_POST['endTime'] : '';
    $persons = intval($_POST['persons'] ?? 1);
    $hours = intval($_POST['hours'] ?? 0);
    $downpayment = isset($_POST['downpayment']) ? floatval($_POST['downpayment']) : null;
    $purpose = isset($_POST['purpose']) ? trim($_POST['purpose']) : null;
    if (in_array($amenity, ['Basketball Court','Tennis Court'], true)) { $price = max(1, $hours) * 150; }
    else if ($amenity === 'Clubhouse') { $price = max(1, $hours) * 200; }
    else if ($amenity === 'Pool') { $price = max(1, $persons) * 175; }
    else { $price = 0; }
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : NULL;
    $ref_code = isset($_POST['ref_code']) ? $_POST['ref_code'] : (isset($_GET['ref_code']) ? $_GET['ref_code'] : '');
    $allowedAmenities = ['Pool','Clubhouse','Basketball Court','Tennis Court'];
    if (!in_array($amenity, $allowedAmenities, true)) { $errorMsg = 'Please select an amenity.'; }
    $sdObj = $start ? DateTime::createFromFormat('Y-m-d', $start) : false;
    $edObj = $end ? DateTime::createFromFormat('Y-m-d', $end) : false;
    $stObj = $startTime ? DateTime::createFromFormat('H:i', $startTime) : false;
    $etObj = $endTime ? DateTime::createFromFormat('H:i', $endTime) : false;
    if (!$sdObj || !$edObj) { $errorMsg = 'Please select a start and end date.'; }
    else if (!$stObj || !$etObj) { $errorMsg = 'Please select a start and end time.'; }
    else if ($sdObj && $edObj && $sdObj > $edObj) { $errorMsg = 'Start date must be before end date.'; }
    else if ($start === $end && $stObj && $etObj && $stObj >= $etObj) { $errorMsg = 'Start time must be before end time.'; }
    else if (($sdObj && $edObj) && (($sdObj < new DateTime('today')) || ($edObj < new DateTime('today')))) { $errorMsg = 'Selected dates must be today or later.'; }
    else if ($amenity === 'Pool' && $persons < 1) { $errorMsg = 'Persons must be at least 1.'; }
    else if ($stObj && $etObj) {
      $minH = ($amenity === 'Clubhouse') ? 9 : 9;
      $maxH = ($amenity === 'Clubhouse') ? 21 : 18;
      if ((int)$stObj->format('H') < $minH || (int)$etObj->format('H') > $maxH) { $errorMsg = 'Selected time is outside operating hours.'; }
    }
    if (!$errorMsg) {
      $cnt = 0;
      $singleDay = ($start && $end && $start === $end && $startTime && $endTime);
      try {
        if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
        if ($singleDay) {
      $check1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
          $check1->bind_param("ssss", $amenity, $start, $startTime, $endTime);
          $check1->execute(); $r1 = $check1->get_result(); $cnt += ($r1 && ($rw=$r1->fetch_assoc())) ? intval($rw['c']) : 0; $check1->close();
          // unified table already checked above; skip resident_reservations
          $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
          $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
          if ($hasGt && $hasGt->num_rows>0 && $hasGe && $hasGe->num_rows>0) {
            $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (approval_status IN ('pending','approved')) AND (TIME(?) < end_time AND TIME(?) > start_time)");
            $check3->bind_param("ssss", $amenity, $start, $startTime, $endTime);
          } else {
            $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE 0=1");
          }
          $check3->execute(); $r3 = $check3->get_result(); $cnt += ($r3 && ($rw=$r3->fetch_assoc())) ? intval($rw['c']) : 0; $check3->close();
        } else {
          $check1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND start_date <= ? AND end_date >= ?");
          $check1->bind_param("sss", $amenity, $end, $start);
          $check1->execute(); $r1 = $check1->get_result(); $cnt += ($r1 && ($rw=$r1->fetch_assoc())) ? intval($rw['c']) : 0; $check1->close();
          // unified table already checked above; skip resident_reservations
          $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND start_date <= ? AND end_date >= ? AND (approval_status IN ('pending','approved'))");
          $check3->bind_param("sss", $amenity, $end, $start);
          $check3->execute(); $r3 = $check3->get_result(); $cnt += ($r3 && ($rw=$r3->fetch_assoc())) ? intval($rw['c']) : 0; $check3->close();
        }
      } catch (Throwable $e) {
        error_log('reserve_resident.php POST error: ' . $e->getMessage());
        $errorMsg = 'Server error. Please try again later.';
      }
      if (!$errorMsg && $cnt > 0) { $errorMsg = 'Selected dates are not available. Please choose different dates.'; }
      if (!$errorMsg) {
        $newRef = $ref_code !== '' ? $ref_code : generateUniqueRefCodeResident($con);
        try {
          if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
          $uidIns = ($user_id && intval($user_id) > 0) ? intval($user_id) : NULL;
          $existsStmt = $con->prepare("SELECT id FROM reservations WHERE ref_code = ? LIMIT 1");
          $existsStmt->bind_param('s', $newRef);
          $existsStmt->execute();
          $existsRes = $existsStmt->get_result();
          $existsStmt->close();
          if ($existsRes && $existsRes->num_rows > 0) {
            $upd = $con->prepare("UPDATE reservations SET amenity = ?, start_date = ?, end_date = ?, start_time = ?, end_time = ?, persons = ?, price = ?, downpayment = ?, user_id = ?, entry_pass_id = COALESCE(entry_pass_id, NULL), purpose = ?, account_type = COALESCE(account_type, 'resident'), approval_status = 'pending', updated_at = NOW() WHERE ref_code = ?");
            $upd->bind_param('sssssiddiss', $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $uidIns, $purpose, $newRef);
            $upd->execute();
            $upd->close();
          } else {
            $ins = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, user_id, entry_pass_id, purpose, payment_status, approval_status, account_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 'pending', 'resident')");
            $entryPassId = NULL;
            $ins->bind_param('ssssssiddiis', $newRef, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $uidIns, $entryPassId, $purpose);
            $ins->execute();
            $ins->close();
          }
        } catch (Throwable $e) {
          error_log('reserve_resident.php upsert error: ' . $e->getMessage());
          $errorMsg = 'Server error. Please try again later.';
        }
        if (!$errorMsg) {
          $_SESSION['pending_reservation'] = [
            'amenity' => $amenity,
            'start_date' => $start,
            'end_date' => $end,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'persons' => $persons,
            'hours' => $hours,
            'price' => $price,
            'downpayment' => $downpayment,
            'user_id' => $user_id,
            'ref_code' => $newRef
          ];
          $generatedCode = $newRef;
          $redir = 'downpayment.php?continue=reserve_resident';
          if (!empty($newRef)) { $redir .= '&ref_code=' . urlencode($newRef); }
          header('Location: ' . $redir);
          exit;
        }
      }
    }
  }
}

if (isset($_GET['action']) && $_GET['action'] === 'booked_dates') {
  header('Content-Type: application/json');
  $amenity = isset($_GET['amenity']) ? trim($_GET['amenity']) : '';
  $dates = [];
  if ($amenity === '') { echo json_encode(['dates' => []]); exit; }
  $collect = function($res) use (&$dates) {
    while ($row = $res->fetch_assoc()) {
      if (empty($row['start_date']) || empty($row['end_date'])) continue;
      $start = new DateTime($row['start_date']);
      $end = new DateTime($row['end_date']);
      $period = new DatePeriod($start, new DateInterval('P1D'), (clone $end)->modify('+1 day'));
      foreach ($period as $d) { $dates[] = $d->format('Y-m-d'); }
    }
  };
  try {
    if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
    $stmt1 = $con->prepare("SELECT start_date, end_date FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved'))");
    $stmt1->bind_param("s", $amenity); $stmt1->execute(); $collect($stmt1->get_result()); $stmt1->close();
    // unified table already included above
    $stmt3 = $con->prepare("SELECT start_date, end_date FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved')");
    $stmt3->bind_param("s", $amenity); $stmt3->execute(); $collect($stmt3->get_result()); $stmt3->close();
  } catch (Throwable $e) {
    error_log('reserve_resident.php booked_dates error: ' . $e->getMessage());
    $dates = [];
  }
  echo json_encode(['dates' => array_values(array_unique($dates))]);
  exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'booked_times') {
  header('Content-Type: application/json');
  $amenity = isset($_GET['amenity']) ? trim($_GET['amenity']) : '';
  $date = $_GET['date'] ?? '';
  $times = [];
  if ($amenity !== '' && $date) {
    try {
      if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
      $stmt1 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved'))");
      $stmt1->bind_param("s", $amenity);
      $stmt1->execute();
      $res1 = $stmt1->get_result();
      while ($row = $res1->fetch_assoc()) {
        if (!$row['start_date'] || !$row['end_date']) continue;
        if ($date >= $row['start_date'] && $date <= $row['end_date']) {
          $st = $row['start_time'] ?: '00:00:00';
          $et = $row['end_time'] ?: '23:59:59';
          $has = (!empty($row['start_time']) && !empty($row['end_time']));
          $times[] = ['start' => $st, 'end' => $et, 'has_time' => $has];
        }
      }
      $stmt1->close();
      // unified table already included above; skip resident_reservations
      $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
      $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
      if ($hasGt && $hasGt->num_rows>0 && $hasGe && $hasGe->num_rows>0) {
        $stmt3 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM guest_forms WHERE amenity = ? AND (approval_status IN ('pending','approved'))");
      } else {
        $stmt3 = $con->prepare("SELECT start_date, end_date, NULL AS start_time, NULL AS end_time FROM guest_forms WHERE amenity = ? AND (approval_status IN ('pending','approved'))");
      }
      $stmt3->bind_param("s", $amenity);
      $stmt3->execute();
      $res3 = $stmt3->get_result();
      while ($row = $res3->fetch_assoc()) {
        if (!$row['start_date'] || !$row['end_date']) continue;
        if ($date >= $row['start_date'] && $date <= $row['end_date']) {
          $st = $row['start_time'] ?: '00:00:00';
          $et = $row['end_time'] ?: '23:59:59';
          $has = (!empty($row['start_time']) && !empty($row['end_time']));
          $times[] = ['start' => $st, 'end' => $et, 'has_time' => $has];
        }
      }
      $stmt3->close();
    } catch (Throwable $e) {
      error_log('reserve_resident.php booked_times error: ' . $e->getMessage());
      $times = [];
    }
  }
  echo json_encode(['times' => $times]);
  exit;
}

if (isset($_GET['ref_code'])) {
  $refFromQuery = trim($_GET['ref_code']);
  try {
    if ($con instanceof mysqli) {
      $stmtGate = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
      $stmtGate->bind_param('s', $refFromQuery);
      $stmtGate->execute();
      $resGate = $stmtGate->get_result();
      if ($resGate && ($rw = $resGate->fetch_assoc())) {
        $ps = strtolower(trim($rw['payment_status'] ?? ''));
        $canSubmit = ($ps === 'verified');
      } else {
        $canSubmit = true;
      }
      $stmtGate->close();
    }
  } catch (Throwable $e) {
    $canSubmit = true;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass - Reserve (Resident)</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link rel="stylesheet" href="css/reserve.css">
  <style>.ref-inline{margin-left:8px;color:#23412e;font-weight:600}</style>
</head>
<body>
  <div id="notifyLayer" class="toast"></div>
<header class="navbar">
  <div class="logo">
    <a href="profileresident.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
    <div class="brand-text">
      <h1>VictorianPass</h1>
      <p>Victorian Heights Subdivision</p>
    </div>
  </div>
</header>

<section class="hero">
  <div class="layout">
    <div class="left-panel">
      <div class="section-header"><h2>Amenities</h2><p>Select an amenity</p></div>
      <div class="amenity-desc" id="amenityDescBox">
        <div class="media">
          <img class="desc-img" data-key="pool" src="images/pool.svg" alt="Pool">
          <img class="desc-img" data-key="clubhouse" src="images/clubhouse.svg" alt="Clubhouse">
          <img class="desc-img" data-key="basketball" src="images/basketball.svg" alt="Basketball Court">
          <img class="desc-img" data-key="tennis" src="images/tennis.jpg" alt="Tennis Court">
          <div>
            <div id="hoursNotice" class="avail" style="display:none"></div>
            <h3 id="amenityDescTitle">No Amenity Selected</h3>
            <p id="amenityDescText">“Click ‘View Description’ on an amenity to see its details here.”</p>
          </div>
        </div>
      </div>
      <div id="amenityImageModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center;">
        <div style="position:relative; background:#fff; border-radius:12px; padding:12px; max-width:90vw; max-height:90vh;">
          <button type="button" id="amenityImageClose" style="position:absolute; top:8px; right:8px; background:#8a2a2a; color:#fff; border:none; border-radius:8px; padding:4px 8px; cursor:pointer;">Close</button>
          <img id="amenityImageModalImg" src="" alt="Amenity" style="display:block; max-width:85vw; max-height:80vh;">
        </div>
      </div>
      <div class="amenities-list" id="amenitiesList">
        <div class="amenity-card" data-amenity="Pool" data-key="pool" data-price="175" role="button" tabindex="0">
          <img src="images/pool.svg" alt="Pool">
          <div class="info">
            <div class="title-block"><div class="name">Community Pool</div><div class="price">₱175 / person</div></div>
            <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button><button type="button" class="btn-link" data-action="view-desc">View Description</button></div>
          </div>
          <div class="schedule-panel" data-schedule-panel><div class="status-pill neutral">Select amenity</div></div>
        </div>
        <div class="amenity-card" data-amenity="Clubhouse" data-key="clubhouse" data-price="200" role="button" tabindex="0">
          <img src="images/clubhouse.svg" alt="Clubhouse">
          <div class="info">
            <div class="title-block"><div class="name">Clubhouse</div><div class="price">₱200 / hour</div></div>
            <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button><button type="button" class="btn-link" data-action="view-desc">View Description</button></div>
          </div>
          <div class="schedule-panel" data-schedule-panel><div class="status-pill neutral">Select amenity</div></div>
        </div>
        <div class="amenity-card" data-amenity="Basketball Court" data-key="basketball" data-price="150" role="button" tabindex="0">
          <img src="images/basketball.svg" alt="Basketball">
          <div class="info">
            <div class="title-block"><div class="name">Basketball Court</div><div class="price">₱150 / hour</div></div>
            <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button><button type="button" class="btn-link" data-action="view-desc">View Description</button></div>
          </div>
          <div class="schedule-panel" data-schedule-panel><div class="status-pill neutral">Select amenity</div></div>
        </div>
        <div class="amenity-card" data-amenity="Tennis Court" data-key="tennis" data-price="150" role="button" tabindex="0">
          <img src="images/tennis.jpg" alt="Tennis">
          <div class="info">
            <div class="title-block"><div class="name">Tennis Court</div><div class="price">₱150 / hour</div></div>
            <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button><button type="button" class="btn-link" data-action="view-desc">View Description</button></div>
          </div>
          <div class="schedule-panel" data-schedule-panel><div class="status-pill neutral">Select amenity</div></div>
        </div>
      </div>
    </div>

    <div class="right-panel">
      <div class="section-header"><h2 id="reservationTitle">Reserve an Amenity</h2><p id="reservationHint">Select an amenity to continue</p></div>
      <?php if (!empty($errorMsg)) { ?><div class="alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php } ?>
      <form method="POST">
        <input type="hidden" name="purpose" value="Amenity Reservation">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="ref_code" value="<?php echo htmlspecialchars($_GET['ref_code'] ?? ''); ?>">
        <input type="hidden" id="submitAllowed" value="1">
        <div class="reservation-card" id="reservationCard" style="display:none;">
          <div class="calendar" style="width:100%">
            <div class="calendar-header">
              <button id="prevMonth">&lt;</button>
              <h3 id="monthAndYear"></h3>
              <button id="nextMonth">&gt;</button>
            </div>
            <table>
              <thead><tr><th>Mo</th><th>Tu</th><th>We</th><th>Th</th><th>Fr</th><th>Sa</th><th>Su</th></tr></thead>
              <tbody id="calendar-body"></tbody>
            </table>
          </div>
          <input type="hidden" name="amenity" id="amenityField" value="">
          <div class="res-item" id="singleDayRow"><label class="single-day"><input type="checkbox" id="singleDayToggle"> Single-day reservation</label></div>
          <div class="date-row" id="dateRow">
          <div class="res-item date-item" id="startDateGroup">
            <div class="res-label"><small>Start Date</small></div>
            <div class="date-line"><p id="startDate">--</p><button type="button" class="clear-date" id="clearStartBtn" title="Clear start date">Clear</button></div>
            <input type="hidden" name="startDate" id="startDateInput">
            <input type="time" name="startTime" id="startTimeInput" min="08:00" max="23:00" style="display:none;">
            <div id="startDateError" class="time-error" style="display:none;"></div>
            <div class="res-label" id="hoursLabel" style="margin-top:8px; display:none;"><small>Number of Hours</small></div>
            <div class="counter" id="hoursCounter" style="display:none;">
              <button type="button" onclick="changeHours(-1)">-</button>
              <span id="hoursCount">1</span>
              <button type="button" onclick="changeHours(1)">+</button>
            </div>
            <input type="hidden" name="hours" id="hoursInput" value="1">
            <div class="res-label" id="hoursSectionLabel" style="margin-top:8px; display:none;"><small>Hours</small><div class="label-help">Pick how many hours</div></div>
            <select id="hoursSelect" class="hours-select" style="display:none;"></select>
            <div id="durationContainer" style="display:none;"></div>
            <div class="res-label" id="timeSectionLabel" style="margin-top:8px; display:none;"><small>Start Time</small><div class="label-help">Pick your starting time</div></div>
            <div id="timeSlotContainer"></div>
            <div id="selectedTimeRange" class="selected-time-range" style="display:none;"></div>
            <div id="availabilityNotice" class="avail-notice" style="display:none;"></div>
          </div>
          <div class="res-item date-item" id="endDateGroup">
            <div class="res-label"><small>End Date</small></div>
            <div class="date-line"><p id="endDate">--</p><button type="button" class="clear-date" id="clearEndBtn" title="Clear end date">Clear</button></div>
            <input type="hidden" name="endDate" id="endDateInput">
            <div id="dateError" class="time-error" style="display:none;"></div>
          <input type="time" name="endTime" id="endTimeInput" min="08:00" max="23:00" style="display:none;">
          <div id="timeError" class="time-error" style="display:none;"></div>
        </div>
        </div>
        <div class="res-item persons">
          <div class="res-label"><small>How Many Persons</small></div>
          <div class="counter" style="margin-top:6px;">
            <button type="button" onclick="changePersons(-1)">-</button>
            <span id="personCount">1</span>
            <button type="button" onclick="changePersons(1)">+</button>
          </div>
          <small id="price">$1</small>
          <input type="hidden" name="persons" id="personsInput" value="1">
        </div>
        <div class="res-item">
          <div class="res-label"><small>Downpayment</small> <span id="dpAmountText" style="font-weight:700; color:#222; margin-left:8px;">₱0</span></div>
          <input type="number" step="0.01" min="0" name="downpayment" id="downpaymentInput" readonly aria-readonly="true" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:8px; background:#f7f7f7; color:#333;" placeholder="Auto-calculated">
          <small class="dp-info" style="display:block;color:#666;margin-top:6px;">A partial downpayment is required to reserve your slot. You can pay the partial amount online now and settle the remaining balance onsite.</small>
        </div>
        <div id="submitWrap" class="res-item" style="flex-basis:100%; margin-top:8px; display:none; gap:8px; align-items:center; flex-wrap:wrap;">
          <button type="button" class="btn-submit" onclick="goBack()">Go Back</button>
          <?php $refParam = isset($_GET['ref_code']) ? $_GET['ref_code'] : ''; if (empty($refParam) && !empty($generatedCode)) { $refParam = $generatedCode; } ?>
          <button id="submitBtn" class="btn-submit disabled" type="submit" disabled>Next</button>
          <?php if (!empty($refParam)) { ?><span class="ref-inline">Status Code: <?php echo htmlspecialchars($refParam); ?></span><?php } ?>
          <?php if (!empty($refParam) && !$canSubmit) { ?>
            <div class="field-warning" style="margin-top:8px;">
              <span class="warn-icon">!</span>
              <span class="msg">Payment pending. Complete downpayment to enable submission.</span>
              <button class="close-warn" type="button" onclick="this.closest('.field-warning').remove()">×</button>
            </div>
            <a class="btn-main" style="margin-top:8px;" href="downpayment.php?continue=reserve_resident<?php echo (!empty($refParam) ? '&ref_code=' . urlencode($refParam) : ''); ?>">Pay Downpayment</a>
          <?php } ?>
        </div>
      </div>
    </form>
  </div>
  </div>
</section>

<div id="refModal" class="modal" style="<?php echo $generatedCode ? 'display:flex;' : ''; ?>">
  <div class="modal-content">
    <h2>Reservation Submitted!</h2>
    <p>Your Status Code:</p>
    <div class="ref-code"><?php echo htmlspecialchars($generatedCode); ?></div>
    <p>Use this code in the <b>Check Status</b> page to track your reservation.</p>
    <div style="text-align:center;margin-top:8px;">
      <button class="close-btn" onclick="closeModal()">OK</button>
    </div>
    <div style="text-align:center;margin-top:12px;">
      <a href="profileresident.php#" class="btn-secondary" title="Back to Resident Home">← Back to Resident Home</a>
    </div>
  </div>
</div>

<div id="verifyModal" class="modal" style="display:none;">
  <div class="modal-content">
    <h2>Confirm Details</h2>
    <div id="verifySummary" style="text-align:left;margin-top:10px"></div>
    <div style="text-align:center;margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <button type="button" class="close-btn" id="verifyCancelBtn">Cancel</button>
      <button type="button" class="btn-secondary" id="verifyConfirmBtn">Proceed</button>
    </div>
  </div>
</div>

<script>
  const monthNames=["January","February","March","April","May","June","July","August","September","October","November","December"];
  let today=new Date(),currentMonth=today.getMonth(),currentYear=today.getFullYear();
  const monthAndYear=document.getElementById("monthAndYear"),calendarBody=document.getElementById("calendar-body");
  const todayStr=`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
  let selectedStart=null,selectedEnd=null;
  let bookedDates=new Set();
  let selectedAmenity=document.getElementById('amenityField').value||'';
  let hintShown=false;

  async function loadBookedDates(){
    if(!selectedAmenity){ bookedDates=new Set(); renderCalendar(currentMonth,currentYear); computeAvailability(); return; }
    try{ const res=await fetch(`reserve_resident.php?action=booked_dates&amenity=${encodeURIComponent(selectedAmenity)}`); const data=await res.json(); bookedDates=new Set(data.dates||[]); }
    catch(e){ bookedDates=new Set(); }
    renderCalendar(currentMonth,currentYear); computeAvailability();
  }

  function renderCalendar(month,year){
    calendarBody.innerHTML="";
    let firstDay=(new Date(year,month)).getDay();
    let daysInMonth=32-new Date(year,month,32).getDate();
    monthAndYear.textContent=monthNames[month]+" "+year;
    let date=1;
    for(let i=0;i<6;i++){
      let row=document.createElement("tr");
      for(let j=1;j<=7;j++){
        if(i===0&&j<(firstDay===0?7:firstDay)){row.appendChild(document.createElement("td"));}
        else if(date>daysInMonth){break;}
        else{
          let cell=document.createElement("td");
          cell.textContent=date;
          let ds=`${year}-${String(month+1).padStart(2,'0')}-${String(date).padStart(2,'0')}`;
          cell.setAttribute('data-date', ds);
          if(ds < todayStr) { cell.classList.add('disabled'); }
          cell.addEventListener('click',()=>handleDateClick(cell,ds));
          if(date===today.getDate()&&year===today.getFullYear()&&month===today.getMonth()) cell.classList.add('today');
          row.appendChild(cell);date++;
        }
      }
      calendarBody.appendChild(row);
    }
    evaluateCalendarAvailability();
  }

  async function evaluateCalendarAvailability(){
    try{
      const amen=document.getElementById('amenityField').value;
      if(!amen){ return; }
      const hrsInput=document.getElementById('hoursInput');
      const hours = isHourBasedAmenity(amen) ? Math.max(1, parseInt(hrsInput?.value||'1',10)) : 1;
      const cells=Array.from(document.querySelectorAll('.calendar td')).filter(c=>c.hasAttribute('data-date'));
      for(const cell of cells){
        const ds=cell.getAttribute('data-date');
        if(!ds) continue;
        if(ds < todayStr){ cell.classList.add('disabled'); cell.title='Past date — cannot be booked.'; continue; }
        cell.classList.remove('disabled','partly','available');
        const booked=await fetchBookedTimesFor(ds);
        const slots=generateTimeSlots(amen);
        const hrsRange=getAmenityHours(amen);
        const minH=parseInt(hrsRange.min.split(':')[0],10);
        const maxH=parseInt(hrsRange.max.split(':')[0],10);
        const totalHours=Math.max(0,maxH-minH);
        let reservedHours=0; const marked={};
        (booked||[]).forEach(t=>{
          if(isHourBasedAmenity(amen) && (t.has_time===false || t.has_time===0)) return;
          const bS=parseInt(String(t.start).split(':')[0],10);
          const bE=parseInt(String(t.end).split(':')[0],10);
          for(let h=bS; h<bE; h++){
            if(h>=minH && h<maxH){ if(!marked[h]){ marked[h]=true; reservedHours++; } }
          }
        });
        if(reservedHours>=totalHours){ cell.classList.add('disabled'); cell.title='Fully Booked — no time slots available for this date.'; }
        else if(reservedHours>0){ cell.classList.add('partly'); cell.title='Partially Booked — some time slots are unavailable.'; }
        else { cell.classList.add('available'); cell.title='Fully Available — all time slots are open.'; }
      }
    }catch(_){ }
  }

  function handleDateClick(cell,dateString){
    if(cell.classList.contains('disabled')){ if(dateString && dateString < todayStr){ showStartDateError('Past date — cannot be booked.'); } return; }
    document.querySelectorAll('.calendar td').forEach(td=>td.classList.remove('active'));
    cell.classList.add('active');
    const single = document.getElementById('singleDayToggle')?.checked;
    function setStart(ds){
      const eVal=document.getElementById('endDateInput').value||'';
      if(eVal && ds > eVal){ showStartDateError('Start date cannot be later than end date.'); return false; }
      selectedStart=ds;
      document.getElementById('startDate').textContent=selectedStart;
      document.getElementById('startDateInput').value=selectedStart;
      showStartDateError('');
      return true;
    }
    function setEnd(ds){
      const sVal=document.getElementById('startDateInput').value||'';
      if(sVal && ds < sVal){ showDateError('End date cannot be earlier than start date.'); return false; }
      selectedEnd=ds;
      document.getElementById('endDate').textContent=selectedEnd;
      document.getElementById('endDateInput').value=selectedEnd;
      showDateError('');
      return true;
    }
    if(single){
      setStart(dateString) && setEnd(dateString);
    } else {
      if(!selectedStart){ setStart(dateString); }
      else if(!selectedEnd){ setEnd(dateString); }
      else { if(!setStart(dateString)){} else { selectedEnd=null; document.getElementById('endDate').textContent='--'; document.getElementById('endDateInput').value=''; } }
    }
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('startDateInput');
    showIncompleteWarnings(false);
    updateActionStates();
    updateSelectedTimeRange();
  }

  function clearStartDate(){
    selectedStart=null;
    document.getElementById('startDate').textContent='--';
    document.getElementById('startDateInput').value='';
    const single = document.getElementById('singleDayToggle')?.checked;
    if(single){ selectedEnd=null; document.getElementById('endDate').textContent='--'; document.getElementById('endDateInput').value=''; }
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('startDateInput');
    showIncompleteWarnings(false);
    updateActionStates();
    updateSelectedTimeRange();
  }
  function clearEndDate(){
    selectedEnd=null;
    document.getElementById('endDate').textContent='--';
    document.getElementById('endDateInput').value='';
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('endDateInput');
    showIncompleteWarnings(false);
    updateActionStates();
    updateSelectedTimeRange();
  }

  function initSingleDayToggle(){
    const cb=document.getElementById('singleDayToggle');
    if(!cb) return;
    cb.addEventListener('change', function(){
      const s=document.getElementById('startDateInput').value;
      if(this.checked){ if(s){ selectedEnd=s; document.getElementById('endDateInput').value=s; document.getElementById('endDate').textContent=s; } }
      else { const e=document.getElementById('endDateInput').value; if(e && s && e===s){ selectedEnd=null; document.getElementById('endDateInput').value=''; document.getElementById('endDate').textContent='--'; }
        document.getElementById('startTimeInput').style.display='none'; document.getElementById('endTimeInput').style.display='none';
        document.getElementById('timeSectionLabel').style.display='none'; document.getElementById('selectedTimeRange').style.display='none';
      }
      configureFieldsForAmenity(document.getElementById('amenityField').value);
      renderTimeSlotButtons();
    });
  }

  const amenityData={
    pool:{title:'Community Pool',value:'Pool',img:'images/pool.svg',desc:'Relax and enjoy the pool with convenient reservation options.'},
    clubhouse:{title:'Clubhouse',value:'Clubhouse',img:'images/clubhouse.svg',desc:'Host gatherings and events in the subdivision clubhouse.'},
    basketball:{title:'Basketball Court',value:'Basketball Court',img:'images/basketball.svg',desc:'Play and practice on our outdoor basketball court.'},
    tennis:{title:'Tennis Court',value:'Tennis Court',img:'images/tennis.jpg',desc:'Reserve time to enjoy a game at the tennis court.'}
  };
  function openAmenityImageModal(key){ const info=amenityData[key]||amenityData.pool; const modal=document.getElementById('amenityImageModal'); const img=document.getElementById('amenityImageModalImg'); if(!modal||!img) return; img.src=info.img; img.alt=info.title; modal.style.display='flex'; }
  (function(){ const modal=document.getElementById('amenityImageModal'); const close=document.getElementById('amenityImageClose'); if(close){ close.onclick=function(){ if(modal) modal.style.display='none'; }; } if(modal){ modal.addEventListener('click',function(e){ if(e.target===modal){ modal.style.display='none'; } }); } })();
  function selectAmenityByKey(key){ const info=amenityData[key]||amenityData.pool; selectedAmenity=info.value; document.getElementById('amenityField').value=info.value; document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected')); const card=document.querySelector(`.amenity-card[data-key="${key}"]`); if(card) card.classList.add('selected'); resetReservationForm(); document.querySelectorAll('.schedule-panel').forEach(p=>p.style.display='none'); loadBookedDates(); configureFieldsForAmenity(selectedAmenity); renderHoursDropdownForAmenity(); renderTimeSlotButtons(); try{ document.getElementById('reservationCard').style.display='none'; document.getElementById('reservationTitle').textContent='Reserve an Amenity'; document.getElementById('reservationHint').textContent='Select an amenity to continue'; }catch(_){ } }

  document.getElementById('prevMonth').addEventListener('click',()=>{currentMonth--;if(currentMonth<0){currentMonth=11;currentYear--; } renderCalendar(currentMonth,currentYear);});
  document.getElementById('nextMonth').addEventListener('click',()=>{currentMonth++;if(currentMonth>11){currentMonth=0;currentYear++; } renderCalendar(currentMonth,currentYear);});

  function setFieldWarning(id,msg){ const container=(id==='startDateInput')?document.getElementById('startDateGroup'):(id==='endDateInput')?document.getElementById('endDateGroup'):(id==='amenityField')?document.querySelector('.amenities-list'):document.getElementById(id)?.closest('.res-item'); if(!container)return; let w=container.querySelector('.field-warning[data-for="'+id+'"]'); if(msg){ if(!w){ w=document.createElement('div'); w.className='field-warning'; w.setAttribute('data-for',id); container.appendChild(w);} let icon=w.querySelector('.warn-icon'); if(!icon){ icon=document.createElement('span'); icon.className='warn-icon'; icon.textContent='!'; w.appendChild(icon);} let m=w.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; w.appendChild(m);} m.textContent=msg; let close=w.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; w.appendChild(close); close.addEventListener('click',function(){ w.remove(); }); } } else { if(w) w.remove(); } }

  function showStartDateError(msg){ const el=document.getElementById('startDateError'); if(el){ el.style.display = msg? 'block':'none'; el.textContent = msg || ''; } }
  function showDateError(msg){ const el=document.getElementById('dateError'); if(el){ el.style.display = msg? 'block':'none'; el.textContent = msg || ''; } }
  function showTimeError(msg){ const el=document.getElementById('timeError'); if(el){ el.style.display = msg? 'block':'none'; el.textContent = msg || ''; } }

  function getAmenityHours(amen){ if(amen==='Clubhouse'){ return {min:'09:00',max:'21:00'}; } return {min:'09:00',max:'18:00'}; }
  function isHourBasedAmenity(amen){ return amen==='Basketball Court' || amen==='Tennis Court' || amen==='Clubhouse'; }
  function isPersonBasedAmenity(amen){ return amen==='Pool'; }
  function formatTimeSlot(h){ const ampm = h>=12 ? 'PM' : 'AM'; let hh=h%12; if(hh===0) hh=12; return `${hh}:00 ${ampm}`; }
  function generateTimeSlots(amenity){ const hrs=getAmenityHours(amenity); const min=parseInt(hrs.min.split(':')[0],10); const max=parseInt(hrs.max.split(':')[0],10); const out=[]; for(let h=min; h<max; h++){ out.push({ label: formatTimeSlot(h), value: `${String(h).padStart(2,'0')}:00` }); } return out; }

  function selectDuration(hours){ const hoursInput=document.getElementById('hoursInput'); const hoursCount=document.getElementById('hoursCount'); if(hoursInput){ hoursInput.value=String(Math.max(1,parseInt(hours,10)||1)); } if(hoursCount){ hoursCount.textContent=String(Math.max(1,parseInt(hours,10)||1)); } updateDisplayedPrice(); updateDownpaymentSuggestion(); renderTimeSlotButtons(); const st=document.getElementById('startTimeInput').value; if(st){ computeEndTimeFromHours(); const sh=parseInt(st.split(':')[0],10); const eh=sh+parseInt(hoursInput.value||'1',10); const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.textContent=`Selected: ${formatTimeSlot(sh)} - ${formatTimeSlot(eh)}`; tr.style.display='block'; } } const dc=document.getElementById('durationContainer'); if(dc){ Array.from(dc.children).forEach(b=>b.classList.remove('selected')); const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(hoursInput.value)); if(sel){ sel.classList.add('selected'); } } updateActionStates(); }
  function computeMaxDuration(amenity,startHour,booked){ const hrs=getAmenityHours(amenity); const maxHour=parseInt(hrs.max.split(':')[0],10); let max=0; for(let h=1; startHour+h<=maxHour; h++){ const thisStart=`${String(startHour).padStart(2,'0')}:00`; const thisEnd=`${String(startHour+h).padStart(2,'0')}:00`; const sM=toMinutes(thisStart), eM=toMinutes(thisEnd); const overlaps=(booked||[]).some(function(t){ if(isHourBasedAmenity(amenity) && (t.has_time===false || t.has_time===0)) return false; const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); }); if(overlaps) break; max=h; } return max; }

  async function fetchBookedTimesFor(date){ if(!document.getElementById('amenityField').value) return []; try{ const res=await fetch(`reserve_resident.php?action=booked_times&amenity=${encodeURIComponent(selectedAmenity)}&date=${encodeURIComponent(date)}`); const data=await res.json(); return data.times||[]; }catch(_){ return []; } }

  function updateDisplayedPrice(){ const amen=document.getElementById('amenityField').value; const persons=parseInt(document.getElementById('personsInput').value||'0'); const hours=parseInt(document.getElementById('hoursInput')?.value||'0'); let base=0; if(amen==='Basketball Court' || amen==='Tennis Court'){ base = Math.max(1,hours) * 150; } else if(amen==='Clubhouse'){ base = Math.max(1,hours) * 200; } else { base = Math.max(1,persons) * 175; } const dpPercent=0.5; const downpayment=(base*dpPercent); const priceEl=document.getElementById('price'); if(priceEl){ priceEl.textContent = '₱' + base.toFixed(2); } const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); } }
  function updateDownpaymentSuggestion(){ const dp=document.getElementById('downpaymentInput'); if(!dp) return; const amen=document.getElementById('amenityField').value; const persons=parseInt(document.getElementById('personsInput').value||'0'); const hours=parseInt(document.getElementById('hoursInput')?.value||'0'); let base=0; if(amen==='Basketball Court' || amen==='Tennis Court'){ base = Math.max(1,hours) * 150; } else if(amen==='Clubhouse'){ base = Math.max(1,hours) * 200; } else { base = Math.max(1,persons) * 175; } const dpPercent=0.5; const downpayment=(base*dpPercent); dp.value = downpayment.toFixed(2); const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); } }

  function changePersons(val){ let count=parseInt(document.getElementById('personCount').textContent); const amen=document.getElementById('amenityField').value; const max=amen==='Pool'?20:Infinity; count=Math.min(max,Math.max(1,count+val)); document.getElementById('personCount').textContent=count; document.getElementById('personsInput').value=count; if(amen==='Pool' && count>=20){ setFieldWarning('personsInput','Maximum is 20 persons.'); } else { setFieldWarning('personsInput',''); } updateDisplayedPrice(); updateDownpaymentSuggestion(); }

  function changeHours(val){ const hoursSpan=document.getElementById('hoursCount'); if(!hoursSpan) return; let hrs=parseInt(hoursSpan.textContent||'1'); hrs=Math.max(1,hrs+val); hoursSpan.textContent=hrs; const hid=document.getElementById('hoursInput'); if(hid){ hid.value=hrs; } computeEndTimeFromHours(); updateDisplayedPrice(); updateDownpaymentSuggestion(); renderTimeSlotButtons(); }

  function configureFieldsForAmenity(amen){ if(!amen){ try{ document.getElementById('reservationCard').style.display='none'; document.getElementById('reservationTitle').textContent='Reserve an Amenity'; document.getElementById('reservationHint').textContent='Select an amenity to continue'; }catch(_){ } return; } const personsWrap=document.getElementById('personsInput')?.closest('.res-item'); const hoursLabel=document.getElementById('hoursLabel'); const hoursInput=document.getElementById('hoursInput'); const hoursCounter=document.getElementById('hoursCounter'); const endTimeInput=document.getElementById('endTimeInput'); const startTimeInput=document.getElementById('startTimeInput'); const hrs=getAmenityHours(amen); if(startTimeInput){ startTimeInput.min=hrs.min; startTimeInput.max=hrs.max; } if(endTimeInput){ endTimeInput.min=hrs.min; endTimeInput.max=hrs.max; } const hn=document.getElementById('hoursNotice'); if(hn){ hn.style.display='none'; hn.textContent=''; } if(isHourBasedAmenity(amen)){ if(personsWrap){ personsWrap.style.display='none'; } if(hoursLabel){ hoursLabel.style.display='none'; } if(hoursCounter){ hoursCounter.style.display='none'; } const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='inline-block'; } const hsl=document.getElementById('hoursSectionLabel'); if(hsl){ hsl.style.display='block'; } const tsl=document.getElementById('timeSectionLabel'); if(tsl){ tsl.style.display='block'; } if(hoursInput){ if(!hoursInput.value) hoursInput.value=1; } if(endTimeInput){ endTimeInput.readOnly=true; } if(startTimeInput && hoursInput){ computeEndTimeFromHours(); } updateDisplayedPrice(); updateDownpaymentSuggestion(); renderHoursDropdownForAmenity(); renderTimeSlotButtons(); } else if(amen==='Pool'){ if(personsWrap){ personsWrap.style.display='block'; } if(hoursLabel){ hoursLabel.style.display='none'; } if(hoursCounter){ hoursCounter.style.display='none'; } const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='inline-block'; } document.getElementById('hoursSectionLabel').style.display='block'; document.getElementById('timeSectionLabel').style.display='block'; if(hoursInput && !hoursInput.value) hoursInput.value=1; if(endTimeInput){ endTimeInput.readOnly=true; } updateDisplayedPrice(); updateDownpaymentSuggestion(); renderHoursDropdownForAmenity(); renderTimeSlotButtons(); } else { if(personsWrap){ personsWrap.style.display='block'; } if(hoursLabel){ hoursLabel.style.display='none'; } if(hoursCounter){ hoursCounter.style.display='none'; } const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='none'; } if(endTimeInput){ endTimeInput.readOnly=false; } updateDisplayedPrice(); updateDownpaymentSuggestion(); document.getElementById('hoursSectionLabel').style.display='none'; document.getElementById('timeSectionLabel').style.display='block'; renderTimeSlotButtons(); } }

  function renderHoursChipsForAmenity(){ const amen=document.getElementById('amenityField').value; const dc=document.getElementById('durationContainer'); const lbl=document.getElementById('hoursSectionLabel'); if(!dc) return; dc.innerHTML=''; if(!isHourBasedAmenity(amen)){ dc.style.display='none'; if(lbl) lbl.style.display='none'; return; } dc.style.display='flex'; if(lbl) lbl.style.display='block'; dc.style.flexWrap='wrap'; dc.style.gap='8px'; dc.style.margin='8px 0 0 0'; const maxH=amen==='Clubhouse'?12:9; for(let h=1; h<=maxH; h++){ const b=document.createElement('button'); b.type='button'; b.className='dur-btn'; b.textContent=`${h}h`; b.dataset.hours=String(h); b.onclick=function(){ selectDuration(h); }; dc.appendChild(b); } const currentH=parseInt(document.getElementById('hoursInput').value||'1',10); const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(currentH)); if(sel) sel.classList.add('selected'); }
  function renderTimeSlotButtons(){ const amen=document.getElementById('amenityField').value; const container=document.getElementById('timeSlotContainer'); const tLbl=document.getElementById('timeSectionLabel'); const notice=document.getElementById('availabilityNotice'); if(!container) return; container.innerHTML=''; if(notice){ notice.style.display='none'; notice.textContent=''; } if(!(amen==='Pool' || isHourBasedAmenity(amen))){ container.style.display='none'; if(tLbl) tLbl.style.display='none'; return; } container.style.display='flex'; if(tLbl) tLbl.style.display='block'; container.style.flexWrap='wrap'; container.style.gap='8px'; container.style.margin='8px 0 0 0'; const slots=generateTimeSlots(amen); const date=document.getElementById('startDateInput').value; const hours=parseInt(document.getElementById('hoursInput').value||'1',10); window.__slotRenderTokenCounter=(window.__slotRenderTokenCounter||0)+1; const __token=window.__slotRenderTokenCounter; window.__activeSlotRenderToken=__token; if(!date){ slots.forEach(slot=>{ const btn=document.createElement('button'); btn.type='button'; btn.className='slot-btn airbnb unavailable'; btn.textContent=slot.label; btn.onclick=function(){ showToast('Select a date to see available time slots.','warning'); }; container.appendChild(btn); }); const msg=document.createElement('div'); msg.style.width='100%'; msg.style.color='#888'; msg.style.fontSize='0.98em'; msg.style.margin='8px 0 0 0'; msg.textContent='Select a date to see available time slots.'; container.appendChild(msg); if(notice){ notice.style.display='block'; notice.textContent='Select a date to see availability.'; } return; } fetchBookedTimesFor(date).then(booked=>{ if(window.__activeSlotRenderToken!==__token) return; window.__bookedTimesForDate=booked||[]; let anyEnabled=false; let disabledCount=0; slots.forEach(slot=>{ const startHour=parseInt(slot.value.split(':')[0],10); const maxPossible=computeMaxDuration(amen,startHour,booked); const valid=(maxPossible>=hours); const btn=document.createElement('button'); btn.type='button'; btn.className='slot-btn airbnb'; btn.textContent=slot.label; btn.dataset.slot=slot.value; if(!valid){ disabledCount++; btn.classList.add('unavailable'); btn.setAttribute('aria-disabled','true'); btn.onclick=function(){ showToast('This start time cannot fit your selected duration. Try a different start time or duration.','warning'); }; } else { anyEnabled=true; btn.classList.add('available'); btn.onclick=function(){ selectTimeSlot(slot.value); }; } container.appendChild(btn); }); const total=slots.length; if(notice){ if(!anyEnabled){ notice.style.display='block'; notice.textContent='Fully Booked — no time slots available for this date.'; } else if(disabledCount>0){ notice.style.display='block'; notice.textContent='Partially Booked — some time slots are unavailable.'; } else { notice.style.display='block'; notice.textContent='Fully Available — all time slots are open.'; } } if(!anyEnabled){ const msg=document.createElement('div'); msg.style.width='100%'; msg.style.color='#888'; msg.style.fontSize='0.98em'; msg.style.margin='8px 0 0 0'; msg.textContent='No start times fit the selected hours. Try a different duration.'; container.appendChild(msg); } const st=document.getElementById('startTimeInput').value; if(st){ const selBtn=Array.from(container.children).find(b=>b.tagName==='BUTTON' && b.dataset.slot===st); if(selBtn) selBtn.classList.add('selected'); } updateActionStates(); }); }
  function selectTimeSlot(start){ const hInput=document.getElementById('hoursInput'); const hrs=parseInt(hInput?.value||'1',10); const amen=document.getElementById('amenityField').value; const booked=window.__bookedTimesForDate||[]; const startHour=parseInt(start.split(':')[0],10); if(computeMaxDuration(amen,startHour,booked) < Math.max(1,hrs)){ showTimeError('This start time cannot fit your selected duration. Try a different start time or duration.'); showToast(`⚠️ Not enough free hours starting from this time to complete ${hrs} hour${hrs>1?'s':''}.`,'warning'); return; } document.getElementById('startTimeInput').value=start; computeEndTimeFromHours(); const sh=startHour, eh=sh+hrs; const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.textContent=`Selected: ${formatTimeSlot(sh)} - ${formatTimeSlot(eh)}`; tr.style.display='block'; } showTimeError(''); updateActionStates(); }

  function clampToRange(timeStr){ if(!timeStr) return ''; const amen=document.getElementById('amenityField').value; const hrs=getAmenityHours(amen); const [h,m]=(timeStr||'').split(':'); let hh=parseInt(h||'0',10); let mm=parseInt(m||'0',10); const [minH]=hrs.min.split(':'), [maxH]=hrs.max.split(':'); const minHour=parseInt(minH,10), maxHour=parseInt(maxH,10); if(hh<minHour){ hh=minHour; mm=0; } if(hh>maxHour){ hh=maxHour; mm=0; } return `${String(hh).padStart(2,'0')}:${String(mm).padStart(2,'0')}`; }
  function computeEndTimeFromHours(){ const amen=document.getElementById('amenityField').value; const st=document.getElementById('startTimeInput').value; const hrs=parseInt(document.getElementById('hoursInput').value||'0',10); if(!st) return; if(!isHourBasedAmenity(amen)){ const [sh,sm]=(st||'').split(':'); const endH=parseInt(sh||'0',10)+1; const et=`${String(endH).padStart(2,'0')}:${String(sm||'0').padStart(2,'0')}`; document.getElementById('endTimeInput').value=et; checkTimeAvailability(); updateActionStates(); return; } if(!hrs||hrs<1) return; const [sh,sm]=(clampToRange(st)||'').split(':'); let h=parseInt(sh||'0',10), m=parseInt(sm||'0',10); let endH=h+hrs; let endM=m; const allowed=getAmenityHours(amen); const maxHour=parseInt(allowed.max.split(':')[0],10); if(endH>maxHour){ endH=maxHour; endM=0; } const et=`${String(endH).padStart(2,'0')}:${String(endM).padStart(2,'0')}`; document.getElementById('startTimeInput').value = clampToRange(st); document.getElementById('endTimeInput').value = et; checkTimeAvailability(); updateActionStates(); updateDisplayedPrice(); updateSelectedTimeRange(); }

  function updateSelectedTimeRange(){ const st=document.getElementById('startTimeInput').value; const hrs=parseInt(document.getElementById('hoursInput').value||'1',10); const el=document.getElementById('selectedTimeRange'); if(!el) return; if(!st){ el.style.display='none'; el.textContent=''; return; } const sh=parseInt((st||'').split(':')[0],10); const eh=sh+Math.max(1,hrs); const amen=document.getElementById('amenityField').value; const hrsRange=getAmenityHours(amen); const minH=parseInt(hrsRange.min.split(':')[0],10); const maxH=parseInt(hrsRange.max.split(':')[0],10); el.textContent=`Selected: ${formatTimeSlot(sh)} - ${formatTimeSlot(eh)} • Overall: ${formatTimeSlot(minH)} - ${formatTimeSlot(maxH)}`; el.style.display='block'; }

  function toMinutes(t){ try{ if(!t) return 0; const s = String(t).trim(); const m = s.match(/^(\d{1,2})(?::(\d{2})(?::(\d{2}))?)?\s*(AM|PM)?$/i); if(!m) return 0; let h = parseInt(m[1] || '0', 10); const min = parseInt(m[2] || '0', 10); const ap = (m[4] || '').toUpperCase(); if(ap === 'PM' && h < 12) h += 12; if(ap === 'AM' && h === 12) h = 0; const total = (h * 60) + min; return Math.max(0, Math.min(24 * 60, total)); } catch(_) { return 0; } }

  async function computeAvailability(){ const amenSel=document.getElementById('amenityField').value; if(!amenSel){ const card=document.querySelector('.amenity-card.selected'); if(card){ const pill=card.querySelector('.status-pill'); if(pill){ pill.textContent='Select amenity'; pill.className='status-pill neutral'; } } return; } const s=document.getElementById('startDateInput').value; const e=document.getElementById('endDateInput').value; const card=document.querySelector('.amenity-card.selected'); if(!card) return; const pill=card.querySelector('.status-pill'); if(!pill) return; if(!s||!e){pill.textContent='Select dates';pill.className='status-pill neutral';return} const sd=new Date(s),ed=new Date(e); pill.textContent='Checking availability…'; pill.className='status-pill neutral'; const hrsRange=getAmenityHours(amenSel); const minH=parseInt(hrsRange.min.split(':')[0],10); const maxH=parseInt(hrsRange.max.split(':')[0],10); const totalHours=Math.max(0,maxH-minH); let fullyBookedFound=false; for(let d=new Date(sd); d<=ed; d.setDate(d.getDate()+1)){ const ds=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; try{ const booked=await fetchBookedTimesFor(ds); let reservedHours=0; const marked={}; (booked||[]).forEach(t=>{ if(isHourBasedAmenity(amenSel) && (t.has_time===false || t.has_time===0)) return; const bS=parseInt(String(t.start).split(':')[0],10); const bE=parseInt(String(t.end).split(':')[0],10); for(let h=bS; h<bE; h++){ if(h>=minH && h<maxH){ if(!marked[h]){ marked[h]=true; reservedHours++; } } } }); if(reservedHours>=totalHours){ fullyBookedFound=true; break; } }catch(_){ } } if(fullyBookedFound){ pill.textContent='Unavailable'; pill.className='status-pill unavailable'; } else { pill.textContent='Available'; pill.className='status-pill available'; } }
  async function checkTimeAvailability(){ const amenSel=document.getElementById('amenityField').value; if(!amenSel){ computeAvailability(); return; } const s=document.getElementById('startDateInput').value; const e=document.getElementById('endDateInput').value; const st=document.getElementById('startTimeInput').value; const et=document.getElementById('endTimeInput').value; const card=document.querySelector('.amenity-card.selected'); if(!card) return; const pill=card.querySelector('.status-pill'); if(!pill) { return; } if(!s||!e||!st||!et){computeAvailability();return} if(s!==e){computeAvailability();return} if(st && et){ const sMin=toMinutes(st); const eMin=toMinutes(et); const amen=document.getElementById('amenityField').value; const allowed=getAmenityHours(amen); const minHour=parseInt(allowed.min.split(':')[0],10); const maxHour=parseInt(allowed.max.split(':')[0],10); const shH=Math.floor(sMin/60); const ehH=Math.floor(eMin/60); if(eMin<=sMin || shH<minHour || ehH>maxHour){ pill.textContent='Invalid time'; pill.className='status-pill unavailable'; const te=document.getElementById('timeError'); if(te){ te.style.display='block'; te.textContent='Selected time is outside operating hours.'; } return; } } const times=await fetchBookedTimesFor(s); const sMin2=toMinutes(st); const eMin2=toMinutes(et); const amen=document.getElementById('amenityField').value; const overlap=times.some(function(t){ if(isHourBasedAmenity(amen) && (t.has_time===false || t.has_time===0)) return false; const ts=toMinutes(t.start); const te=toMinutes(t.end); return !(sMin2>=te || eMin2<=ts); }); if(overlap){pill.textContent='Unavailable';pill.className='status-pill unavailable'} else{pill.textContent='Available';pill.className='status-pill available'} const te=document.getElementById('timeError'); if(te){ if(overlap){ te.style.display='block'; te.textContent='Time slot is already booked. Please choose a different time.'; } else { te.style.display='none'; te.textContent=''; } } }

  function renderHoursDropdownForAmenity(){ const amen=document.getElementById('amenityField').value; const sel=document.getElementById('hoursSelect'); const lbl=document.getElementById('hoursSectionLabel'); if(!sel) return; sel.innerHTML=''; if(!(isHourBasedAmenity(amen) || amen==='Pool')){ sel.style.display='none'; if(lbl) lbl.style.display='none'; return; } sel.style.display='inline-block'; if(lbl) lbl.style.display='block'; const maxH=amen==='Clubhouse'?12:9; for(let h=1; h<=maxH; h++){ const opt=document.createElement('option'); opt.value=String(h); opt.textContent=`${h} hour${h>1?'s':''}`; sel.appendChild(opt); } const currentH=parseInt(document.getElementById('hoursInput').value||'1',10); sel.value=String(currentH); }

  function decorateSlotButtons(){ const container=document.getElementById('timeSlotContainer'); if(!container) return; const amen=document.getElementById('amenityField').value; const hours=parseInt(document.getElementById('hoursInput').value||'1',10); const booked=window.__bookedTimesForDate||[]; const selectedDate=document.getElementById('startDateInput').value||''; const now=new Date(); const todayStrLocal=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`; const currentHour=now.getHours(); const currentMinute=now.getMinutes(); Array.from(container.querySelectorAll('.slot-btn')).forEach(function(btn){ const ds=btn.dataset.slot||''; const sh=parseInt(ds.split(':')[0]||'0',10); const maxPossible=computeMaxDuration(amen,sh,booked); const isPastOnToday=(selectedDate===todayStrLocal)&&(sh<currentHour || (sh===currentHour && currentMinute>0)); if(btn.disabled || isPastOnToday || maxPossible<Math.max(1,hours)){ btn.disabled=true; btn.classList.add('unavailable'); btn.dataset.past = isPastOnToday ? '1' : '0'; } else { btn.title=''; } }); }
  (function(){ const container=document.getElementById('timeSlotContainer'); if(!container) return; const obs=new MutationObserver(function(){ decorateSlotButtons(); }); obs.observe(container,{childList:true}); container.addEventListener('pointerdown',function(e){ const b=e.target.closest('.slot-btn'); if(!b) return; if(b.disabled || b.classList.contains('unavailable')){ const isPast=(b.dataset.past==='1'); showTimeError(isPast ? 'This time has already passed and cannot be booked.' : 'This start time cannot fit your selected duration. Try a different start time or duration.'); e.preventDefault(); } }); })();

  let __dirtyFields = {}; function markDirty(id){ try{ __dirtyFields[id] = true; }catch(_){} } function isDirty(id){ try{ return !!__dirtyFields[id]; }catch(_){ return false; } }

  function updateActionStates(){ const amenVal=document.getElementById('amenityField').value; const s=document.getElementById('startDateInput').value; const e=document.getElementById('endDateInput').value; const st=document.getElementById('startTimeInput').value; const et=document.getElementById('endTimeInput').value; const gate=document.getElementById('submitAllowed'); let allowed=true; if(!amenVal) allowed=false; if(!s||!e) allowed=false; const single=document.getElementById('singleDayToggle')?.checked; if(single){ if(!st||!et) allowed=false; } const priceEl=document.getElementById('price'); if(priceEl && priceEl.textContent){ const p=priceEl.textContent.replace(/[^0-9.]/g,''); if(parseFloat(p||'0')<=0) allowed=false; } const btn=document.getElementById('submitBtn'); const wrap=document.getElementById('submitWrap'); if(wrap){ wrap.style.display='flex'; } if(btn){ if(allowed && gate && gate.value==='1'){ btn.classList.remove('disabled'); btn.disabled=false; } else { btn.classList.add('disabled'); btn.disabled=true; } }
  }

  function showIncompleteWarnings(force){ const amenVal=document.getElementById('amenityField').value; const s=document.getElementById('startDateInput').value; const e=document.getElementById('endDateInput').value; const st=document.getElementById('startTimeInput').value; const et=document.getElementById('endTimeInput').value; const single=document.getElementById('singleDayToggle')?.checked; if(!amenVal){ setFieldWarning('amenityField','Please select an amenity.'); } else { setFieldWarning('amenityField',''); } if(!s){ setFieldWarning('startDateInput','Please select a start date.'); } else { setFieldWarning('startDateInput',''); } if(!e){ setFieldWarning('endDateInput','Please select an end date.'); } else { setFieldWarning('endDateInput',''); } if(single){ if(!st){ setFieldWarning('startTimeInput','Please select a start time.'); } else { setFieldWarning('startTimeInput',''); } if(!et){ setFieldWarning('endTimeInput','Please select an end time.'); } else { setFieldWarning('endTimeInput',''); } } }

  function goBack(){ persistForm(); if(document.referrer){ window.history.back(); } else { window.location.href = 'profileresident.php'; } }
  function closeModal(){ document.getElementById('refModal').style.display='none'; }

  document.addEventListener('DOMContentLoaded',function(){ renderHoursDropdownForAmenity(); renderTimeSlotButtons(); updateDisplayedPrice(); updateDownpaymentSuggestion(); });
  const hoursSelect=document.getElementById('hoursSelect'); if(hoursSelect){ hoursSelect.addEventListener('change',function(){ const val=parseInt(hoursSelect.value||'1',10); const hid=document.getElementById('hoursInput'); if(hid){ hid.value=String(val); const hc=document.getElementById('hoursCount'); if(hc){ hc.textContent=String(val); } } computeEndTimeFromHours(); updateDisplayedPrice(); updateDownpaymentSuggestion(); renderTimeSlotButtons(); }); }
  const cs=document.getElementById('clearStartBtn'); if(cs){ cs.addEventListener('click',clearStartDate); }
  const ce=document.getElementById('clearEndBtn'); if(ce){ ce.addEventListener('click',clearEndDate); }
  initSingleDayToggle();

  const hsSel=document.getElementById('hoursSelect'); if(hsSel){ hsSel.addEventListener('change', function(){ const v=parseInt(hsSel.value||'1',10); document.getElementById('hoursCount').textContent=String(v); document.getElementById('hoursInput').value=String(v); computeEndTimeFromHours(); updateDisplayedPrice(); updateDownpaymentSuggestion(); renderTimeSlotButtons(); }); }
  const stIn=document.getElementById('startTimeInput'); if(stIn){ stIn.addEventListener('change', function(){ markDirty('startTimeInput'); computeEndTimeFromHours(); }); }
  const etIn=document.getElementById('endTimeInput'); if(etIn){ etIn.addEventListener('change', function(){ markDirty('endTimeInput'); checkTimeAvailability(); updateSelectedTimeRange(); }); }

  document.querySelectorAll('.amenity-card').forEach(function(card){ card.addEventListener('click',function(){ const key=card.getAttribute('data-key'); selectAmenityByKey(key); openAmenityImageModal(key); }); });
  const amenitiesList=document.getElementById('amenitiesList');
  if(amenitiesList){ amenitiesList.addEventListener('click',function(e){ const bookBtn=e.target.closest('button[data-action="book-now"]'); if(bookBtn){ const card=e.target.closest('.amenity-card'); if(card){ selectAmenityByKey(card.getAttribute('data-key')); try{ const rc=document.getElementById('reservationCard'); rc.style.display='flex'; document.getElementById('reservationTitle').textContent='Reservation'; document.getElementById('reservationHint').textContent='Select date, time, and persons'; rc.scrollIntoView({behavior:'smooth',block:'start'}); }catch(_){} } return; } const card=e.target.closest('.amenity-card'); if(card){ const key=card.getAttribute('data-key'); selectAmenityByKey(key); openAmenityImageModal(key); } }); amenitiesList.addEventListener('keydown',function(e){ if(e.key==='Enter'||e.key===' '){ const card=e.target.closest('.amenity-card'); if(card){ e.preventDefault(); selectAmenityByKey(card.getAttribute('data-key')); } } }); }
  document.querySelectorAll('button[data-action="view-desc"]').forEach(function(btn){ btn.addEventListener('click',function(e){ e.stopPropagation(); const card=btn.closest('.amenity-card'); if(card){ const key=card.getAttribute('data-key'); const info=amenityData[key]||amenityData.pool; const t=document.getElementById('amenityDescTitle'); if(t){ t.textContent=info.title; } const d=document.getElementById('amenityDescText'); if(d){ d.textContent=info.desc; } document.querySelectorAll('.amenity-desc .desc-img').forEach(function(img){ img.style.display='none'; }); const imgEl=document.querySelector('.amenity-desc .desc-img[data-key="'+key+'"]'); if(imgEl){ imgEl.style.display='block'; } const hn=document.getElementById('hoursNotice'); if(hn){ const hrs=getAmenityHours(info.value); const minH=parseInt(hrs.min.split(':')[0],10); const maxH=parseInt(hrs.max.split(':')[0],10); hn.textContent = `Available ${formatTimeSlot(minH)} – ${formatTimeSlot(maxH)}`; hn.style.display='block'; } } }); });

  function resetReservationForm(){ try{ selectedStart=null; selectedEnd=null; ['startDateInput','endDateInput','startTimeInput','endTimeInput'].forEach(function(id){ const el=document.getElementById(id); if(el){ el.value=''; } }); const sd=document.getElementById('startDate'); if(sd){ sd.textContent='--'; } const ed=document.getElementById('endDate'); if(ed){ ed.textContent='--'; } const pc=document.getElementById('personCount'); if(pc){ pc.textContent='1'; } const pi=document.getElementById('personsInput'); if(pi){ pi.value='1'; } }catch(_){} }

  function persistForm(){ try{ const data={ amenity:document.getElementById('amenityField').value||'', start_date:document.getElementById('startDateInput').value||'', end_date:document.getElementById('endDateInput').value||'', start_time:document.getElementById('startTimeInput').value||'', end_time:document.getElementById('endTimeInput').value||'', persons:document.getElementById('personsInput').value||'1', hours:document.getElementById('hoursInput')?.value||'1', downpayment:document.getElementById('downpaymentInput')?.value||'' }; sessionStorage.setItem('reserve_form', JSON.stringify(data)); }catch(_){} }
  function restoreFormFromSession(){ try{ const raw=sessionStorage.getItem('reserve_form'); if(!raw) return; const data=JSON.parse(raw||'{}'); if(data.amenity){ document.getElementById('amenityField').value=data.amenity; } if(data.start_date){ document.getElementById('startDateInput').value=data.start_date; } if(data.end_date){ document.getElementById('endDateInput').value=data.end_date; } if(data.start_time){ document.getElementById('startTimeInput').value=data.start_time; } if(data.end_time){ document.getElementById('endTimeInput').value=data.end_time; } if(data.persons){ document.getElementById('personsInput').value=data.persons; document.getElementById('personCount').textContent=String(data.persons); } if(data.hours && document.getElementById('hoursInput')){ document.getElementById('hoursInput').value=data.hours; const hc=document.getElementById('hoursCount'); if(hc){ hc.textContent=String(data.hours); } } if(data.downpayment && document.getElementById('downpaymentInput')){ document.getElementById('downpaymentInput').value=data.downpayment; } configureFieldsForAmenity(document.getElementById('amenityField').value); const amenName=(document.getElementById('amenityField').value||''); const key = amenName==='Pool' ? 'pool' : amenName==='Clubhouse' ? 'clubhouse' : amenName==='Basketball Court' ? 'basketball' : amenName==='Tennis Court' ? 'tennis' : ''; document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected')); const card=document.querySelector(`.amenity-card[data-key="${key}"]`); if(card){ card.classList.add('selected'); } }catch(_){} }
  restoreFormFromSession();

  const formEl=document.querySelector('form');
  if(formEl){
    let submitting = false;
    formEl.addEventListener('submit', async function(e){
      if (submitting) { e.preventDefault(); return false; }
      persistForm();
      let verifyAllowed=true;
      const gateEl=document.getElementById('submitAllowed');
      if(gateEl && gateEl.value==='0'){ verifyAllowed=false; setFieldWarning('amenityField','Payment pending. Complete downpayment to continue.'); }
      const amen=document.getElementById('amenityField').value;
      const s=document.getElementById('startDateInput').value;
      const eD=document.getElementById('endDateInput').value;
      const st=document.getElementById('startTimeInput').value;
      const et=document.getElementById('endTimeInput').value;
      const persons=parseInt(document.getElementById('personsInput').value||'1',10);
      const hours=parseInt(document.getElementById('hoursInput').value||'1',10);
      const priceEl=document.getElementById('price');
      if(s && eD && s===eD && st && et){
        const times=await fetchBookedTimesFor(s);
        const sM=toMinutes(st), eM=toMinutes(et);
        const overlap=times.some(function(t){ if(isHourBasedAmenity(amen) && (t.has_time===false || t.has_time===0)) return false; const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); });
        if(overlap){ e.preventDefault(); showTimeError('Selected time overlaps an existing booking. Please choose a different time.'); return false; }
      }
      const amenVal=document.getElementById('amenityField').value;
      if(!amenVal || !s || !eD || !st || !et){ verifyAllowed=false; }
      if(isHourBasedAmenity(amenVal)){ if(hours<1) verifyAllowed=false; } else { if(persons<1) verifyAllowed=false; }
      if(!verifyAllowed){ e.preventDefault(); showToast('Please complete all fields accurately before proceeding.','warning'); return false; }
      if(!window.__verifyConfirmed){
        e.preventDefault();
        const priceTxt = (priceEl && priceEl.textContent) ? priceEl.textContent : '₱0';
        const unitsLabel = isHourBasedAmenity(amenVal) ? 'Hours' : 'Persons';
        const unitsValue = isHourBasedAmenity(amenVal) ? (parseInt(document.getElementById('hoursInput').value||'1',10)) : (parseInt(document.getElementById('personsInput').value||'1',10));
        const dpVal=document.getElementById('downpaymentInput')?.value||'';
        const summary = [
          ['Amenity', amenVal||'-'],
          ['Start', s||'-'],
          ['End', eD||'-'],
          ['Time', (st||'') + (et?(' → '+et):'')],
          [unitsLabel, String(unitsValue)],
          ['Total Price', priceTxt],
          ['Downpayment', (dpVal!==''?('₱'+Number(dpVal).toFixed(2)):'—')]
        ].map(function(x){ return '<div style="display:flex;justify-content:space-between;margin:4px 0"><span style="font-weight:600">'+x[0]+'</span><span>'+x[1]+'</span></div>'; }).join('');
        const sumEl=document.getElementById('verifySummary'); if(sumEl){ sumEl.innerHTML = summary; }
        const vm=document.getElementById('verifyModal'); if(vm){ vm.style.display='flex'; }
        return false;
      } else {
        window.__verifyConfirmed = false;
        submitting = true;
        setTimeout(function(){ submitting = false; }, 3000);
      }
    });
  }

  (function(){
    const btn=document.getElementById('submitBtn');
    const vm=document.getElementById('verifyModal');
    const cBtn=document.getElementById('verifyCancelBtn');
    const pBtn=document.getElementById('verifyConfirmBtn');
    window.__verifyConfirmed=false;
    if(cBtn){ cBtn.addEventListener('click', function(){ if(vm){ vm.style.display='none'; } }); }
    if(pBtn){ pBtn.addEventListener('click', function(){ showIncompleteWarnings(); if(!formIsComplete()){ return; } window.__verifyConfirmed=true; const f=document.querySelector('form'); if(f){ f.submit(); } }); }
  })();

  function formIsComplete(){ const amenVal=document.getElementById('amenityField').value; const s=document.getElementById('startDateInput').value; const e=document.getElementById('endDateInput').value; const st=document.getElementById('startTimeInput').value; const et=document.getElementById('endTimeInput').value; const single=document.getElementById('singleDayToggle')?.checked; if(!amenVal||!s||!e) return false; if(single && (!st||!et)) return false; return true; }

  function showToast(msg,type){ const el=document.getElementById('notifyLayer'); el.textContent=msg; el.className='toast '+(type||''); el.style.display='block'; setTimeout(()=>{ el.style.display='none'; },2000); }
</script>

</body>
</html>
