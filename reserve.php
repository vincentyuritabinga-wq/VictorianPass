<?php
ob_start(); // Prevents header issues on redirect
session_start();
include 'connect.php';
$generatedCode = '';
$errorMsg = '';
$canSubmit = true;
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// If a resident is logged in and not handling a visitor entry pass, redirect to resident-only reservation page (GET requests only)
if (
  $_SERVER['REQUEST_METHOD'] === 'GET' &&
  isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident' &&
  (!isset($_GET['entry_pass_id']) || $_GET['entry_pass_id'] === '')
) {
  header('Location: reserve_resident.php');
  exit;
}

// Ensure reservations has entry_pass_id column to link entry pass info
function ensureReservationEntryPassColumn($con) {
  if (!($con instanceof mysqli)) { return; }
  $col = $con->query("SHOW COLUMNS FROM reservations LIKE 'entry_pass_id'");
  if (!$col || $col->num_rows === 0) {
    $con->query("ALTER TABLE reservations ADD COLUMN entry_pass_id INT NULL");
  }
}

ensureReservationEntryPassColumn($con);

// Ensure reservations columns are nullable, supporting placeholder record before amenity selection
function ensureReservationsNullable($con) {
  if (!($con instanceof mysqli)) { return; }
  @$con->query("ALTER TABLE reservations MODIFY amenity VARCHAR(100) NULL");
  @$con->query("ALTER TABLE reservations MODIFY start_date DATE NULL");
  @$con->query("ALTER TABLE reservations MODIFY end_date DATE NULL");
  @$con->query("ALTER TABLE reservations MODIFY persons INT NULL");
  @$con->query("ALTER TABLE reservations MODIFY price DECIMAL(10,2) NULL");
}
ensureReservationsNullable($con);

// Ensure time and downpayment fields exist
function ensureReservationTimeAndDownpayment($con){
  if (!($con instanceof mysqli)) { return; }
  $c1 = $con->query("SHOW COLUMNS FROM reservations LIKE 'start_time'");
  if(!$c1 || $c1->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN start_time TIME NULL AFTER start_date"); }
  $c2 = $con->query("SHOW COLUMNS FROM reservations LIKE 'end_time'");
  if(!$c2 || $c2->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN end_time TIME NULL AFTER end_date"); }
  $c3 = $con->query("SHOW COLUMNS FROM reservations LIKE 'downpayment'");
  if(!$c3 || $c3->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN downpayment DECIMAL(10,2) NULL AFTER price"); }
}
ensureReservationTimeAndDownpayment($con);

// Downpayment moved on-page: do not force redirect; users can pay via GCash from the form

function generateUniqueRefCode($con){
  $tries=0; $code='';
  while($tries<6){
    $candidate='VP-'.str_pad(rand(0,99999),5,'0',STR_PAD_LEFT);
    $exists=false;
    if($con instanceof mysqli){
      $q1=$con->prepare("SELECT 1 FROM reservations WHERE ref_code=? LIMIT 1");
      $q1->bind_param('s',$candidate); $q1->execute(); $r1=$q1->get_result(); $exists = $exists || ($r1 && $r1->num_rows>0); $q1->close();
      $q2=$con->prepare("SELECT 1 FROM guest_forms WHERE ref_code=? LIMIT 1");
      $q2->bind_param('s',$candidate); $q2->execute(); $r2=$q2->get_result(); $exists = $exists || ($r2 && $r2->num_rows>0); $q2->close();
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
    if (in_array($amenity, ['Basketball Court','Tennis Court'], true)) {
      $price = max(1, $hours) * 150;
    } else if ($amenity === 'Clubhouse') {
      $price = max(1, $hours) * 200;
    } else if ($amenity === 'Pool') {
      $price = max(1, $persons) * 175;
    } else {
      $price = 0;
    }
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : NULL;
    $entry_pass_id = (isset($_POST['entry_pass_id']) && $_POST['entry_pass_id'] !== '') ? intval($_POST['entry_pass_id']) : ((isset($_GET['entry_pass_id']) && $_GET['entry_pass_id'] !== '') ? intval($_GET['entry_pass_id']) : NULL);
    $ref_code = isset($_POST['ref_code']) ? $_POST['ref_code'] : (isset($_GET['ref_code']) ? $_GET['ref_code'] : '');
    $allowedAmenities = ['Pool','Clubhouse','Basketball Court','Tennis Court'];
    if (!in_array($amenity, $allowedAmenities, true)) { $errorMsg = 'Please select an amenity.'; }
    $sdObj = $start ? DateTime::createFromFormat('Y-m-d', $start) : false;
    $edObj = $end ? DateTime::createFromFormat('Y-m-d', $end) : false;
    $stObj = $startTime ? DateTime::createFromFormat('H:i', $startTime) : false;
    $etObj = $endTime ? DateTime::createFromFormat('H:i', $endTime) : false;
    if (!$sdObj || !$edObj) {
      $errorMsg = 'Please select a start and end date.';
    } else if (!$stObj || !$etObj) {
      $errorMsg = 'Please select a start and end time.';
    } else if ($sdObj && $edObj && $sdObj > $edObj) {
      $errorMsg = 'Start date must be before end date.';
    } else if ($start === $end && $stObj && $etObj && $stObj >= $etObj) {
      $errorMsg = 'Start time must be before end time.';
    } else if (($sdObj && $edObj) && (($sdObj < new DateTime('today')) || ($edObj < new DateTime('today')))) {
      $errorMsg = 'Selected dates must be today or later.';
    } else if (in_array($amenity, ['Pool','Clubhouse'], true) && $persons < 1) {
      $errorMsg = 'Persons must be at least 1.';
    } else if ($stObj && $etObj) {
      $minH = ($amenity === 'Clubhouse') ? 9 : 9;
      $maxH = ($amenity === 'Clubhouse') ? 21 : 18;
      if ((int)$stObj->format('H') < $minH || (int)$etObj->format('H') > $maxH) {
        $errorMsg = 'Selected time is outside operating hours.';
      }
    }
      $visitorFlow = (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident');
      if (!$errorMsg) {
        $cnt = 0;
        $singleDay = ($start && $end && $start === $end && $startTime && $endTime);
        try {
          if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
          if ($singleDay) {
            $check1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND ? BETWEEN start_date AND end_date AND NOT (? >= end_time OR ? <= start_time)");
            $check1->bind_param("ssss", $amenity, $start, $startTime, $endTime);
            $check1->execute(); $r1 = $check1->get_result(); $cnt += ($r1 && ($rw=$r1->fetch_assoc())) ? intval($rw['c']) : 0; $check1->close();
            $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
            $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
            if ($hasRt && $hasRt->num_rows>0 && $hasRe && $hasRe->num_rows>0) {
              $check2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND NOT (? >= end_time OR ? <= start_time)");
              $check2->bind_param("ssss", $amenity, $start, $startTime, $endTime);
            } else {
              $check2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND start_date <= ? AND end_date >= ?");
              $check2->bind_param("sss", $amenity, $end, $start);
            }
            $check2->execute(); $r2 = $check2->get_result(); $cnt += ($r2 && ($rw=$r2->fetch_assoc())) ? intval($rw['c']) : 0; $check2->close();
            $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
            $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
            if ($hasGt && $hasGt->num_rows>0 && $hasGe && $hasGe->num_rows>0) {
              $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (approval_status IN ('pending','approved')) AND NOT (? >= end_time OR ? <= start_time)");
              $check3->bind_param("ssss", $amenity, $start, $startTime, $endTime);
            } else {
              $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND start_date <= ? AND end_date >= ? AND (approval_status IN ('pending','approved'))");
              $check3->bind_param("sss", $amenity, $end, $start);
            }
            $check3->execute(); $r3 = $check3->get_result(); $cnt += ($r3 && ($rw=$r3->fetch_assoc())) ? intval($rw['c']) : 0; $check3->close();
          } else {
            $check1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND start_date <= ? AND end_date >= ?");
            $check1->bind_param("sss", $amenity, $end, $start);
            $check1->execute(); $r1 = $check1->get_result(); $cnt += ($r1 && ($rw=$r1->fetch_assoc())) ? intval($rw['c']) : 0; $check1->close();
            $check2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND start_date <= ? AND end_date >= ?");
            $check2->bind_param("sss", $amenity, $end, $start);
            $check2->execute(); $r2 = $check2->get_result(); $cnt += ($r2 && ($rw=$r2->fetch_assoc())) ? intval($rw['c']) : 0; $check2->close();
            $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND start_date <= ? AND end_date >= ? AND (approval_status IN ('pending','approved'))");
            $check3->bind_param("sss", $amenity, $end, $start);
            $check3->execute(); $r3 = $check3->get_result(); $cnt += ($r3 && ($rw=$r3->fetch_assoc())) ? intval($rw['c']) : 0; $check3->close();
          }
        } catch (Throwable $e) {
          error_log('reserve.php POST error: ' . $e->getMessage());
          $errorMsg = 'Server error. Please try again later.';
        }
        if (!$errorMsg && $cnt > 0) {
          $errorMsg = 'Selected dates are not available. Please choose different dates.';
        }
        if (!$errorMsg) {
          $paidOk = false;
          if ($ref_code !== '') {
            try {
              if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
              $s1 = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
              $s1->bind_param('s', $ref_code);
              $s1->execute();
              $g1 = $s1->get_result();
              if ($g1 && ($rr = $g1->fetch_assoc())) {
                $ps = strtolower(trim($rr['payment_status'] ?? ''));
                $paidOk = ($ps === 'verified');
              }
              $s1->close();
            } catch (Throwable $e) {
              error_log('reserve.php payment check error: ' . $e->getMessage());
            }
          }
          $newRef = $ref_code !== '' ? $ref_code : generateUniqueRefCode($con);
          try {
            if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
            $dpIns = ($downpayment !== null ? $downpayment : 0.0);
            $uidIns = ($user_id && intval($user_id) > 0) ? intval($user_id) : NULL;
            $epIns = ($entry_pass_id && intval($entry_pass_id) > 0) ? intval($entry_pass_id) : NULL;

            $existsStmt = $con->prepare("SELECT id FROM reservations WHERE ref_code = ? LIMIT 1");
            $existsStmt->bind_param('s', $newRef);
            $existsStmt->execute();
            $existsRes = $existsStmt->get_result();
            $existsStmt->close();

            if ($existsRes && $existsRes->num_rows > 0) {
              $upd = $con->prepare("UPDATE reservations SET amenity = ?, start_date = ?, end_date = ?, start_time = ?, end_time = ?, persons = ?, price = ?, downpayment = ?, user_id = ?, entry_pass_id = ?, purpose = ?, approval_status = 'pending' WHERE ref_code = ?");
              $upd->bind_param('sssssiddiiss', $amenity, $start, $end, $startTime, $endTime, $persons, $price, $dpIns, $uidIns, $epIns, $purpose, $newRef);
              $upd->execute();
              $upd->close();
              if ($paidOk) {
                $psu = $con->prepare("UPDATE reservations SET payment_status = 'verified' WHERE ref_code = ?");
                $psu->bind_param('s', $newRef);
                $psu->execute();
                $psu->close();
              } else {
                $psu = $con->prepare("UPDATE reservations SET payment_status = 'pending' WHERE ref_code = ?");
                $psu->bind_param('s', $newRef);
                $psu->execute();
                $psu->close();
              }
            } else {
              $payStatus = $paidOk ? 'verified' : 'pending';
              $ins = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, user_id, entry_pass_id, purpose, payment_status, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
              $ins->bind_param('ssssssiddiiss', $newRef, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $dpIns, $uidIns, $epIns, $purpose, $payStatus);
              $ins->execute();
              $ins->close();
            }
          } catch (Throwable $e) {
            error_log('reserve.php upsert error: ' . $e->getMessage());
            $errorMsg = 'Server error. Please try again later.';
          }
          if (!$errorMsg) {
            // Store reservation info in session for confirmation/debugging if needed
            $_SESSION['pending_reservation'] = [
              'amenity' => $amenity,
              'start_date' => $start,
              'end_date' => $end,
              'start_time' => $startTime,
              'end_time' => $endTime,
              'persons' => $persons,
              'price' => $price,
              'downpayment' => $downpayment,
              'user_id' => $user_id,
              'entry_pass_id' => $entry_pass_id,
              'ref_code' => $newRef
            ];
            $generatedCode = $newRef;
            $canSubmit = true;
            // Prevent double submission
            if (isset($_SESSION['reservation_submitted']) && $_SESSION['reservation_submitted'] === $newRef) {
              $errorMsg = 'This reservation has already been submitted.';
            } else {
              $_SESSION['reservation_submitted'] = $newRef;
              // Always redirect to downpayment page with entry_pass_id and ref_code
              $redir = 'downpayment.php?continue=reserve';
              if (!empty($entry_pass_id)) { $redir .= '&entry_pass_id=' . urlencode((string)$entry_pass_id); }
              if (!empty($newRef)) { $redir .= '&ref_code=' . urlencode($newRef); }
              header('Location: ' . $redir);
              exit;
            }
          }
        }
      }
    }
  }
// End of POST handler

// Lightweight endpoint to return booked dates for the selected amenity
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
    $stmt2 = $con->prepare("SELECT start_date, end_date FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved')");
    $stmt2->bind_param("s", $amenity); $stmt2->execute(); $collect($stmt2->get_result()); $stmt2->close();
    $stmt3 = $con->prepare("SELECT start_date, end_date FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved')");
    $stmt3->bind_param("s", $amenity); $stmt3->execute(); $collect($stmt3->get_result()); $stmt3->close();
  } catch (Throwable $e) {
    error_log('reserve.php booked_dates error: ' . $e->getMessage());
    $dates = [];
  }
  echo json_encode(['dates' => array_values(array_unique($dates))]);
  exit;
}

// Submission gate: require payment before submission for visitors
$refFromQuery = isset($_GET['ref_code']) ? trim($_GET['ref_code']) : '';
if ($refFromQuery !== '') {
try {
  if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
$stmtGate = $con->prepare("SELECT payment_status, amenity, start_date FROM reservations WHERE ref_code = ? LIMIT 1");
  $stmtGate->bind_param('s', $refFromQuery);
  $stmtGate->execute();
  $resGate = $stmtGate->get_result();
  if ($resGate && ($rw = $resGate->fetch_assoc())) {
    if (!empty($rw['amenity']) && !empty($rw['start_date'])) {
      $_SESSION['flash_notice'] = 'A reservation already exists for this status code. Please wait for your status code via SMS.';
      $_SESSION['flash_ref_code'] = $refFromQuery;
      header('Location: mainpage.php');
      exit;
    }
    $ps = strtolower(trim($rw['payment_status'] ?? ''));
    $canSubmit = ($ps === 'verified');
  } else {
    $canSubmit = false;
  }
  $stmtGate->close();
} catch (Throwable $e) {
  error_log('reserve.php gate error: ' . $e->getMessage());
  $canSubmit = false;
}
}
// Enforce gate for visitors (no resident session or entry_pass_id provided)
if ((!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') && (!isset($_GET['entry_pass_id']) || $_GET['entry_pass_id'] === '')) {
  if ($refFromQuery === '') { $canSubmit = false; }
}

// Endpoint: booked_times for a specific date and amenity
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
          $times[] = ['start' => $st, 'end' => $et];
        }
      }
      $stmt1->close();
      $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
      $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
      if ($hasRt && $hasRt->num_rows>0 && $hasRe && $hasRe->num_rows>0) {
        $stmt2 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM resident_reservations WHERE amenity = ?");
      } else {
        $stmt2 = $con->prepare("SELECT start_date, end_date, NULL AS start_time, NULL AS end_time FROM resident_reservations WHERE amenity = ?");
      }
      $stmt2->bind_param("s", $amenity);
      $stmt2->execute();
      $res2 = $stmt2->get_result();
      while ($row = $res2->fetch_assoc()) {
        if (!$row['start_date'] || !$row['end_date']) continue;
        if ($date >= $row['start_date'] && $date <= $row['end_date']) {
          $st = $row['start_time'] ?: '00:00:00';
          $et = $row['end_time'] ?: '23:59:59';
          $times[] = ['start' => $st, 'end' => $et];
        }
      }
      $stmt2->close();
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
          $times[] = ['start' => $st, 'end' => $et];
        }
      }
      $stmt3->close();
    } catch (Throwable $e) {
      error_log('reserve.php booked_times error: ' . $e->getMessage());
      $times = [];
    }
  }
  echo json_encode(['times' => $times]);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass - Reserve</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="mainpage/logo.svg">

  <style>
    /* Modern UI Design */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      margin: 0;
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(135deg, #30522bff 0%, #30522bff 100%);
      color: #fff;
      animation: fadeIn .6s ease-in-out;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    /* Navigation */
    .navbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 14px 6%;
      background: rgba(43, 38, 35, 0.95);
      backdrop-filter: blur(10px);
      position: sticky;
      top: 0;
      z-index: 1000;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .logo {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .logo img {
      width: 42px;
      height: 42px;
    }
    
    .brand-text h1 {
      margin: 0;
      font-size: 1.3rem;
      font-weight: 600;
      color: #f4f4f4;
    }
    
    .brand-text p {
      margin: 0;
      font-size: .85rem;
      color: #aaa;
    }
    
    /* Main Layout */
    .hero {
      display: flex;
      justify-content: center;
      align-items: flex-start;
      padding: 30px 6%;
      min-height: calc(100vh - 80px);
    }
    
    .layout {
      display: grid;
      gap: 32px;
      align-items: start;
      width: 100%;
      max-width: 1200px;
      grid-template-columns: 1fr;
    }
    
    .left-panel {
      display: flex;
      flex-direction: column;
      gap: 14px;
      min-width: 0;
    }
    
    .right-panel {
      min-width: 0;
    }
    
    /* Calendar */
    .calendar {
      background: rgba(255, 255, 255, 0.95);
      color: #222;
      padding: 20px;
      border-radius: 16px;
      width: 100%;
      text-align: center;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .calendar-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }
    
    .calendar-header h3 {
      font-size: 1.2rem;
      margin: 0;
      font-weight: 600;
    }
    
    .calendar-header button {
      background: linear-gradient(135deg, #23412e 0%, #345c40 100%);
      color: #fff;
      border: none;
      padding: 6px 12px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 1.2rem;
      transition: all 0.3s ease;
    }
    
    .calendar-header button:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(35, 65, 46, 0.4);
    }
    
    .calendar table {
      width: 100%;
      border-collapse: collapse;
    }
    
    .calendar th, .calendar td {
      width: 14%;
      padding: 10px;
      font-size: .95rem;
      text-align: center;
    }
    
    .calendar th {
      font-weight: 600;
      color: #666;
    }
    
    .calendar td {
      cursor: pointer;
      border-radius: 8px;
      transition: all 0.3s ease;
      position: relative;
    }
    
    .calendar td:hover:not(.disabled) {
      background: #e8f5e8;
      transform: scale(1.05);
    }
    
    .calendar td.active {
      background: linear-gradient(135deg, #23412e 0%, #345c40 100%);
      color: #fff;
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(35, 65, 46, 0.4);
    }
    
    .calendar td.today {
      border: 2px solid #23412e;
      border-radius: 8px;
      font-weight: 600;
      background: rgba(35, 65, 46, 0.1);
    }
    
    .calendar td.disabled {
      color: #999;
      cursor: not-allowed;
      background: #f7f7f7;
      opacity: 0.6;
    }
    .calendar td.partly {
      background: linear-gradient(135deg, rgba(245,242,234,1) 50%, rgba(247,247,247,1) 50%);
      border: 1px dashed #b08900;
    }
    .section-header{display:flex;justify-content:space-between;align-items:flex-end;margin:0 0 10px}
    .section-header h2{font-size:1.4rem;font-weight:700}
    .section-header p{color:#cfcfcf}
    
    /* Amenity Description */
    .amenity-desc {
      background: #fff;
      color: #222;
      padding: 18px;
      border-radius: 14px;
      min-height: 120px;
      box-shadow: 0 10px 24px rgba(0,0,0,.12);
      border: 2px solid #23412e;
    }
    .amenity-desc .media{display:flex;align-items:center;gap:12px;margin-bottom:8px}
    .amenity-desc .desc-img{width:64px;height:64px;border-radius:10px;object-fit:cover;display:none}
    
    .amenity-desc h3 {
      margin: 0 0 6px;
      font-weight: 700;
      color: #23412e;
      font-size: 1.14rem;
    }
    
    .amenity-desc p {
      margin: 0;
      color: #555;
      line-height: 1.5;
      font-size: 0.95rem;
    }
    
    /* Amenities List */
    .amenities-list {
      display: grid;
      grid-template-columns: 1fr;
      gap: 12px;
      margin-bottom: 12px;
    }
    
    .amenity-card {
      display: flex;
      gap: 16px;
      align-items: center;
      background: #fff;
      color: #222;
      padding: 18px;
      border-radius: 14px;
      box-shadow: 0 10px 24px rgba(0,0,0,.12);
      border: 1px solid #e9ecef;
      cursor: pointer;
      transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease, background .25s ease;
      min-height: 160px;
      position: relative;
      pointer-events: auto;
    }
    
    .amenity-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 14px 26px rgba(0,0,0,.18);
      border-color: #dfe4ea;
    }
    
    .amenity-card.selected {
      border: 2px solid #23412e;
      background: linear-gradient(135deg, #f5f2ea 0%, #e9e4d3 100%);
      box-shadow: 0 8px 24px rgba(35, 65, 46, 0.25);
    }
    
    .amenity-card img {
      width: 160px;
      height: 120px;
      object-fit: cover;
      border-radius: 12px;
      box-shadow: 0 6px 14px rgba(0, 0, 0, 0.25);
    }
    
    .amenity-card .info {
      flex: 1;
      color: #222;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .amenity-card .title-block{display:flex;flex-direction:column;gap:4px}
    .amenity-card .price{color:#23412e;font-weight:700}
    
    .amenity-card .info .name {
      font-weight: 700;
      font-size: 1.15rem;
      margin: 0;
      line-height: 1.2;
    }
    
    .amenity-card .info .meta {
      color: #666;
      font-size: 0.9rem;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      background: #f9f9f9;
      border: 1px solid #e9ecef;
      padding: 8px 10px;
      border-radius: 12px;
    }
    .status-pill{display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 14px;border-radius:10px;font-weight:600;font-size:0.9rem;box-shadow:0 4px 12px rgba(0,0,0,0.08)}
    .status-pill.available{background:#23412e;color:#fff}
    .status-pill.unavailable{background:#8a2a2a;color:#fff}
    .status-pill.neutral{background:#e5ddc6;color:#23412e;border:1px solid #d7cfb0}
    .btn-main.small{display:inline-flex;align-items:center;justify-content:center;height:38px;min-width:140px;padding:0 14px;border-radius:10px;font-size:.9rem;margin-left:0;box-shadow:0 4px 12px rgba(35,65,46,0.35)}
    .schedule-panel{margin-top:8px;background:#f7f7f7;border:1px solid #e9ecef;border-radius:10px;padding:10px;display:none}
    
    /* Reservation Card */
    .reservation-card {
      display: flex;
      flex-direction: column;
      align-items: stretch;
      background: rgba(255, 255, 255, 0.95);
      color: #222;
      padding: 20px;
      border-radius: 12px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.1);
      gap: 18px;
    }
    .date-row{display:flex;gap:12px;flex-wrap:wrap}
    .date-row .date-item{flex:1 1 280px}
    .single-day{display:inline-flex;align-items:center;gap:8px;font-weight:600}
    .single-day input{accent-color:#23412e}
    .reservation-card .calendar{grid-column:1/-1;margin-bottom:6px}
    
    .res-item {
      flex: 0 0 auto;
      text-align: left;
      min-width: 120px;
    }
    
    .res-label {
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 6px;
      margin-bottom: 6px;
      font-size: 0.85rem;
      color: #666;
      font-weight: 500;
    }
    
    .reservation-card p {
      margin: 0;
      font-weight: 600;
      color: #333;
    }
    
    .btn-submit {
      background: linear-gradient(135deg, #23412e 0%, #345c40 100%);
      color: #fff;
      border: none;
      padding: 12px 24px;
      border-radius: 10px;
      cursor: pointer;
      font-weight: 600;
      font-size: 1rem;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(35, 65, 46, 0.4);
    }
    #submitWrap{display:none; width:100%; justify-content:flex-end}
    
    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(35, 65, 46, 0.5);
    }
    
    .counter {
      display: flex;
      align-items: center;
      gap: 6px;
      justify-content: center;
    }
    
    .counter button {
      background: linear-gradient(135deg, #23412e 0%, #345c40 100%);
      color: #fff;
      border: none;
      padding: 6px 12px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 1rem;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    
    .counter button:hover {
      transform: scale(1.1);
    }
    
    .counter span {
      font-weight: 600;
      min-width: 30px;
      display: inline-block;
      text-align: center;
      font-size: 1.1rem;
    }
    
    /* Form Inputs */
    input[type="time"] {
      width: 100%;
      padding: 8px;
      border: 2px solid #e0e0e0;
      border-radius: 8px;
      font-size: 0.9rem;
      transition: all 0.3s ease;
      background: #fff;
    }
    
    input[type="time"]:focus {
      outline: none;
      border-color: #23412e;
      box-shadow: 0 0 0 3px rgba(35, 65, 46, 0.1);
    }
    
    /* Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 2000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.8);
      backdrop-filter: blur(5px);
      align-items: center;
      justify-content: center;
    }
    
    .modal-content {
      background: rgba(255, 255, 255, 0.95);
      color: #222;
      padding: 30px;
      border-radius: 14px;
      width: 90%;
      max-width: 450px;
      text-align: center;
      animation: fadeIn .5s ease-in-out;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .modal-content h2 {
      margin: 0 0 12px;
      font-weight: 700;
      color: #23412e;
    }
    
    .ref-code {
      font-size: 1.4rem;
      font-weight: 700;
      background: linear-gradient(135deg, #f3f3f3 0%, #e8e8e8 100%);
      padding: 10px 16px;
      border-radius: 10px;
      display: inline-block;
      margin-bottom: 18px;
      color: #23412e;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .close-btn {
      background: linear-gradient(135deg, #23412e 0%, #345c40 100%);
      color: #fff;
      border: none;
      padding: 10px 20px;
      border-radius: 10px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    
    .close-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(35, 65, 46, 0.4);
    }
    
    .btn-secondary {
      background: linear-gradient(135deg, #e5ddc6 0%, #d4cdb8 100%);
      color: #222;
      border: none;
      padding: 10px 22px;
      border-radius: 10px;
      cursor: pointer;
      font-weight: 600;
      text-decoration: none;
      display: inline-block;
      transition: all 0.3s ease;
    }
    
    .btn-secondary:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }
    .field-warning{background:#fff3cd;color:#7a5c00;border-left:4px solid #b08900;padding:8px;border-radius:10px;margin-top:6px;display:flex;align-items:center;gap:8px}
    .field-warning .warn-icon{display:inline-block;font-weight:700}
    .field-warning .close-warn{margin-left:auto;background:transparent;border:none;color:#7a5c00;font-size:1rem;cursor:pointer}
    .field-warning .close-warn:hover{opacity:.8}
    .date-line{display:flex;align-items:center;gap:8px}
    .clear-date{background:transparent;border:1px solid #ccc;color:#fff;padding:4px 8px;border-radius:6px;cursor:pointer}
    .clear-date:hover{background:rgba(255,255,255,0.1)}
    button:focus-visible,input:focus-visible{outline:2px solid #23412e;outline-offset:2px}
  
    .btn-main {
      background: linear-gradient(135deg, #23412e 0%, #345c40 100%);
      color: #fff;
      padding: 8px 16px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.9rem;
      transition: all 0.3s ease;
      display: inline-block;
      box-shadow: 0 4px 12px rgba(35, 65, 46, 0.4);
    }
    
    .btn-main:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(35, 65, 46, 0.5);
    }
    .btn-main.disabled{opacity:.5;pointer-events:none}
    .btn-submit.disabled{opacity:.6;cursor:not-allowed}
    .ref-inline{display:inline-block;margin-left:10px;background:#f3f3f3;color:#23412e;padding:6px 10px;border-radius:8px;font-weight:600}
    .page-header{width:100%;max-width:1400px;margin:0 auto 12px;padding:0 0 8px;border-bottom:1px solid rgba(255,255,255,.1)}
    .page-title{font-size:1.6rem;font-weight:700;margin-bottom:6px}
    .page-subtitle{color:#cfcfcf}
    .alert-error{background:#8a2a2a;color:#fff;padding:10px 12px;border-radius:10px;margin:10px 0;border:1px solid rgba(0,0,0,.2)}
    .reserve-instructions{background:rgba(43,38,35,.6);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:12px;color:#ddd}
    .reserve-instructions strong{color:#fff}
    .time-error{background:#8a2a2a;color:#fff;padding:8px 10px;border-radius:8px;margin-top:8px;font-size:0.9rem}
    .selected-time-range{background:#f3f3f3;color:#23412e;padding:8px 10px;border-radius:8px;margin-top:8px;font-size:0.95rem;font-weight:600}
    .slot-btn{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 12px;border-radius:10px;border:1px solid #e0e0e0;background:#fff;color:#23412e;font-weight:600;box-shadow:0 4px 10px rgba(0,0,0,.06);transition:all .2s ease}
    .slot-btn.airbnb:hover{transform:translateY(-1px);box-shadow:0 6px 12px rgba(0,0,0,.1)}
    .slot-btn.selected{background:#23412e;color:#fff;border-color:#23412e}
    .slot-btn.unavailable{opacity:.5;cursor:not-allowed;background:#f5f5f5;color:#888}
    .hours-select{width:100%;padding:8px 10px;border:2px solid #e0e0e0;border-radius:10px;background:#fff;color:#23412e;font-weight:600}
    .counter button{background:#e5ddc6;color:#23412e;border:none;padding:6px 10px;border-radius:8px;cursor:pointer;font-weight:700}
    .counter button:hover{filter:brightness(0.95)}
    .clear-date{border:1px solid #d7cfb0;color:#23412e}
    
    /* Responsive Design */
    @media (max-width: 768px) {
      .layout { grid-template-columns: 1fr; }
      .left-panel, .right-panel { width: 100%; }
      .reservation-card { flex-direction: column; gap: 20px; }
      .res-item { width: 100%; }
      .hero { padding: 20px 4%; }
      .amenities-list{grid-template-columns: 1fr}
      .amenity-card img{width:96px;height:72px;border-radius:10px}
      .amenity-card .info{flex-direction:column;align-items:flex-start;gap:8px}
      .amenity-card .info .meta{width:100%;justify-content:flex-start}
    }

    @media (min-width: 769px) and (max-width: 1023px) {
      .layout { grid-template-columns: 1fr; }
      .amenities-list{grid-template-columns: 1fr}
    }

    @media (min-width: 1024px) {
      .layout { grid-template-columns: 1fr; }
      .amenities-list{grid-template-columns: repeat(2, minmax(360px, 1fr));}
      .amenity-card img{width:180px;height:120px}
    }

  @media (min-width: 1440px) {
    .layout { grid-template-columns: 1fr; }
  }
  .left-panel:has(.amenity-card.selected[data-key="pool"]) .amenity-desc .desc-img[data-key="pool"]{display:block}
  .left-panel:has(.amenity-card.selected[data-key="clubhouse"]) .amenity-desc .desc-img[data-key="clubhouse"]{display:block}
  .left-panel:has(.amenity-card.selected[data-key="basketball"]) .amenity-desc .desc-img[data-key="basketball"]{display:block}
  .left-panel:has(.amenity-card.selected[data-key="tennis"]) .amenity-desc .desc-img[data-key="tennis"]{display:block}
  .toast{position:fixed;top:14px;left:50%;transform:translateX(-50%);background:#23412e;color:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 8px 18px rgba(0,0,0,.12);font-size:.9rem;z-index:1000;display:none}
  </style>
</head>
<body>
  <div id="notifyLayer" class="toast"></div>
   
<header class="navbar">
  <div class="logo">
    <a href="mainpage.php"><img src="mainpage/logo.svg" alt="VictorianPass Logo"></a>
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
      <div class="amenity-desc">
        <div class="media">
          <img class="desc-img" data-key="pool" src="mainpage/pool.svg" alt="Pool">
          <img class="desc-img" data-key="clubhouse" src="mainpage/clubhouse.svg" alt="Clubhouse">
          <img class="desc-img" data-key="basketball" src="mainpage/basketball.svg" alt="Basketball Court">
          <img class="desc-img" data-key="tennis" src="mainpage/tennis.svg" alt="Tennis Court">
          <div>
            <h3 id="amenityDescTitle">Reserve Amenity</h3>
            <p id="amenityDescText">Select an amenity to see its details here.</p>
            <p id="hoursNotice" style="margin-top:6px;color:#cfcfcf;font-size:.9rem"></p>
          </div>
        </div>
      </div>
      <div class="amenities-list" id="amenitiesList">
        <div class="amenity-card" data-amenity="Pool" data-key="pool" data-price="175" role="button" tabindex="0">
          <img src="mainpage/pool.svg" alt="Pool">
          <div class="info">
            <div class="title-block"><div class="name">Community Pool</div><div class="price">₱175 / person</div></div>
            <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button></div>
          </div>
          <div class="schedule-panel" data-schedule-panel></div>
        </div>
        <div class="amenity-card" data-amenity="Clubhouse" data-key="clubhouse" data-price="200" role="button" tabindex="0">
          <img src="mainpage/clubhouse.svg" alt="Clubhouse">
          <div class="info">
            <div class="title-block"><div class="name">Clubhouse</div><div class="price">₱200 / hour</div></div>
            <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button></div>
          </div>
          <div class="schedule-panel" data-schedule-panel></div>
        </div>
        <div class="amenity-card" data-amenity="Basketball Court" data-key="basketball" data-price="150" role="button" tabindex="0">
          <img src="mainpage/basketball.svg" alt="Basketball">
          <div class="info">
            <div class="title-block"><div class="name">Basketball Court</div><div class="price">₱150 / hour</div></div>
            <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button></div>
          </div>
          <div class="schedule-panel" data-schedule-panel></div>
        </div>
        <div class="amenity-card" data-amenity="Tennis Court" data-key="tennis" data-price="150" role="button" tabindex="0">
          <img src="mainpage/tennis.svg" alt="Tennis">
          <div class="info">
            <div class="title-block"><div class="name">Tennis Court</div><div class="price">₱150 / hour</div></div>
            <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button></div>
          </div>
          <div class="schedule-panel" data-schedule-panel></div>
        </div>
      </div>
    </div>

    <div class="right-panel">
      <div class="section-header"><h2 id="reservationTitle">Reserve an Amenity</h2><p id="reservationHint">Select an amenity to continue</p></div>
      <?php if (!empty($errorMsg)) { ?><div class="alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php } ?>
      <form method="POST">
        <input type="hidden" name="purpose" value="Amenity Reservation">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="entry_pass_id" value="<?php echo (isset($_GET['entry_pass_id']) && $_GET['entry_pass_id'] !== '') ? intval($_GET['entry_pass_id']) : ''; ?>">
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
        <div class="res-item">
          <div class="res-label"><small>Persons</small></div>
          <div class="counter">
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
            <a class="btn-main" style="margin-top:8px;" href="downpayment.php?continue=reserve<?php echo (!empty($_GET['entry_pass_id']) ? '&entry_pass_id=' . urlencode($_GET['entry_pass_id']) : ''); ?><?php echo (!empty($refParam) ? '&ref_code=' . urlencode($refParam) : ''); ?>">Pay Downpayment</a>
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
      <a href="mainpage.php#" class="btn-secondary" title="Back to Visitor Home">← Back to Visitor Home</a>
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

<!--
<div id="hintModal" class="modal" style="display:none;">
  <div class="modal-content">
    <h2>Next Step</h2>
    <p>Select date here and fill out the form.</p>
    <div style="text-align:center;margin-top:8px;">
      <button class="close-btn" onclick="closeHint()">OK</button>
    </div>
  </div>
</div>
-->

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
    try{ const res=await fetch(`reserve.php?action=booked_dates&amenity=${encodeURIComponent(selectedAmenity)}`); const data=await res.json(); bookedDates=new Set(data.dates||[]); }
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
        if(cell.classList.contains('disabled') && ds >= todayStr){ cell.classList.remove('disabled'); }
        const booked=await fetchBookedTimesFor(ds);
        const slots=generateTimeSlots(amen);
        let anyFits=false;
        for(const slot of slots){
          const startHour=parseInt(slot.value.split(':')[0],10);
          const maxPossible=computeMaxDuration(amen,startHour,booked);
          if(maxPossible>=hours){ anyFits=true; break; }
        }
        cell.classList.remove('partly');
        if(!anyFits){ cell.classList.add('disabled'); cell.title='Fully booked — no slots fit the selected duration.'; }
        else {
          const hrsRange=getAmenityHours(amen);
          const minH=parseInt(hrsRange.min.split(':')[0],10);
          const maxH=parseInt(hrsRange.max.split(':')[0],10);
          const totalHours=Math.max(0,maxH-minH);
          let reservedHours=0; const marked={};
          (booked||[]).forEach(t=>{ const bS=parseInt(String(t.start).split(':')[0],10); const bE=parseInt(String(t.end).split(':')[0],10); for(let h=bS; h<bE; h++){ if(h>=minH && h<maxH){ if(!marked[h]){ marked[h]=true; reservedHours++; } } } });
          const halfThreshold=Math.floor(totalHours/2);
          if(reservedHours>=halfThreshold && reservedHours<totalHours){ cell.classList.add('partly'); cell.title='Partially booked — some slots still available.'; }
          else { cell.title='Fully available'; }
        }
      }
    }catch(_){ }
  }

  function handleDateClick(cell,dateString){
    if(cell.classList.contains('disabled')) return;
    document.querySelectorAll('.calendar td').forEach(td=>td.classList.remove('active'));
    cell.classList.add('active');
    const single = document.getElementById('singleDayToggle')?.checked;
    if(single){
      selectedStart=dateString;
      selectedEnd=dateString;
      document.getElementById('startDate').textContent=selectedStart;
      document.getElementById('startDateInput').value=selectedStart;
      document.getElementById('endDate').textContent=selectedEnd;
      document.getElementById('endDateInput').value=selectedEnd;
    } else {
      if(!selectedStart){
        selectedStart=dateString;
        document.getElementById('startDate').textContent=selectedStart;
        document.getElementById('startDateInput').value=selectedStart;
      } else if(!selectedEnd){
        selectedEnd=dateString;
        document.getElementById('endDate').textContent=selectedEnd;
        document.getElementById('endDateInput').value=selectedEnd;
      } else {
        selectedStart=dateString;
        selectedEnd=null;
        document.getElementById('startDate').textContent=selectedStart;
        document.getElementById('startDateInput').value=selectedStart;
        document.getElementById('endDate').textContent='--';
        document.getElementById('endDateInput').value='';
      }
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
      if(this.checked){
        if(s){ selectedEnd=s; document.getElementById('endDateInput').value=s; document.getElementById('endDate').textContent=s; }
      }
      computeAvailability();
      renderTimeSlotButtons();
      updateActionStates();
      showIncompleteWarnings(false);
      updateSelectedTimeRange();
    });
  }

  const amenityData={
    pool:{title:'Community Pool',value:'Pool',img:'mainpage/pool.svg',desc:'Relax and enjoy the pool with convenient reservation options.'},
    clubhouse:{title:'Clubhouse',value:'Clubhouse',img:'mainpage/clubhouse.svg',desc:'Host gatherings and events in the subdivision clubhouse.'},
    basketball:{title:'Basketball Court',value:'Basketball Court',img:'mainpage/basketball.svg',desc:'Play and practice on our outdoor basketball court.'},
    tennis:{title:'Tennis Court',value:'Tennis Court',img:'mainpage/tennis.svg',desc:'Reserve time to enjoy a game at the tennis court.'}
  };

  function selectAmenityByKey(key){
    const info=amenityData[key]||amenityData.pool;
    selectedAmenity=info.value;
    document.getElementById('amenityField').value=info.value;
    document.getElementById('amenityDescTitle').textContent=info.title;
    document.getElementById('amenityDescText').textContent=info.desc;
    document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected'));
    const card=document.querySelector(`.amenity-card[data-key="${key}"]`);
    if(card) card.classList.add('selected');
    const rc=document.querySelector('.reservation-card');
    // if(!hintShown){ hintShown=true; const hm=document.getElementById('hintModal'); if(hm){ hm.style.display='flex'; } }
    selectedStart=null;selectedEnd=null;
    document.getElementById('startDate').textContent='--';
    document.getElementById('endDate').textContent='--';
    document.getElementById('startDateInput').value='';
    document.getElementById('endDateInput').value='';
    document.querySelectorAll('.schedule-panel').forEach(p=>p.style.display='none');
    loadBookedDates();
    configureFieldsForAmenity(selectedAmenity);
    renderHoursDropdownForAmenity();
    renderTimeSlotButtons();
    try{ document.getElementById('reservationCard').style.display='flex'; document.getElementById('reservationTitle').textContent='Reservation'; document.getElementById('reservationHint').textContent='Select date, time, and persons'; if(rc){ rc.scrollIntoView({behavior:'smooth',block:'start'}); } }catch(_){}
  }

  document.querySelectorAll('.amenity-card').forEach(function(card){
    card.addEventListener('click',function(){
      const key=card.getAttribute('data-key');
      selectAmenityByKey(key);
    });
  });

  const amenitiesList=document.getElementById('amenitiesList');
  if(amenitiesList){
    amenitiesList.addEventListener('click',function(e){
      const bookBtn=e.target.closest('button[data-action="book-now"]');
      if(bookBtn){
        const card=e.target.closest('.amenity-card');
        if(card){ selectAmenityByKey(card.getAttribute('data-key')); }
        return;
      }
      const card=e.target.closest('.amenity-card');
      if(card){ selectAmenityByKey(card.getAttribute('data-key')); }
    });
    amenitiesList.addEventListener('keydown',function(e){
      if(e.key==='Enter'||e.key===' '){
        const card=e.target.closest('.amenity-card');
        if(card){ e.preventDefault(); selectAmenityByKey(card.getAttribute('data-key')); }
      }
    });
  }

  function changePersons(val){
    let count=parseInt(document.getElementById('personCount').textContent);
    count=Math.max(1,count+val);
    document.getElementById('personCount').textContent=count;
    document.getElementById('personsInput').value=count;
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
  }
  function changeHours(val){
    const hoursSpan=document.getElementById('hoursCount');
    if(!hoursSpan) return;
    let hrs=parseInt(hoursSpan.textContent||'1');
    hrs=Math.max(1,hrs+val);
    hoursSpan.textContent=hrs;
    const hid=document.getElementById('hoursInput'); if(hid){ hid.value=hrs; }
    computeEndTimeFromHours();
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    renderTimeSlotButtons();
  }

  function selectDuration(hours){
    const hoursInput=document.getElementById('hoursInput');
    const hoursCount=document.getElementById('hoursCount');
    if(hoursInput){ hoursInput.value=String(Math.max(1,parseInt(hours,10)||1)); }
    if(hoursCount){ hoursCount.textContent=String(Math.max(1,parseInt(hours,10)||1)); }
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    renderTimeSlotButtons();
    const st=document.getElementById('startTimeInput').value;
    if(st){ computeEndTimeFromHours(); const sh=parseInt(st.split(':')[0],10); const eh=sh+parseInt(hoursInput.value||'1',10); const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.textContent=`Selected: ${formatTimeSlot(sh)} - ${formatTimeSlot(eh)}`; tr.style.display='block'; } }
    const dc=document.getElementById('durationContainer'); if(dc){ Array.from(dc.children).forEach(b=>b.classList.remove('selected')); const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(hoursInput.value)); if(sel){ sel.classList.add('selected'); } }
    updateActionStates();
  }

  async function fetchBookedTimesFor(date){ if(!document.getElementById('amenityField').value) return []; try{ const res=await fetch(`reserve.php?action=booked_times&amenity=${encodeURIComponent(selectedAmenity)}&date=${encodeURIComponent(date)}`); const data=await res.json(); return data.times||[]; }catch(_){ return []; } }

  function isHourBasedAmenity(amen){ return amen==='Basketball Court' || amen==='Tennis Court' || amen==='Clubhouse'; }
  function isPersonBasedAmenity(amen){ return amen==='Pool'; }
  function updateDisplayedPrice(){
    const amen=document.getElementById('amenityField').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    let base=0;
    if(amen==='Basketball Court' || amen==='Tennis Court'){ base = Math.max(1,hours) * 150; }
    else if(amen==='Clubhouse'){ base = Math.max(1,hours) * 200; }
    else { base = Math.max(1,persons) * 175; }
    const dpPercent=0.5;
    const downpayment=(base*dpPercent);
    const priceEl=document.getElementById('price'); if(priceEl){ priceEl.textContent = '₱' + base.toFixed(2); }
    const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); }
  }
  function updateDownpaymentSuggestion(){
    const dp=document.getElementById('downpaymentInput'); if(!dp) return;
    const amen=document.getElementById('amenityField').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    let base=0;
    if(amen==='Basketball Court' || amen==='Tennis Court'){ base = Math.max(1,hours) * 150; }
    else if(amen==='Clubhouse'){ base = Math.max(1,hours) * 200; }
    else { base = Math.max(1,persons) * 175; }
    const dpPercent=0.5;
    const downpayment=(base*dpPercent);
    dp.value = downpayment.toFixed(2);
    const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); }
  }

  function configureFieldsForAmenity(amen){
    if(!amen){
      try{
        document.getElementById('reservationCard').style.display='none';
        document.getElementById('reservationTitle').textContent='Reserve an Amenity';
        document.getElementById('reservationHint').textContent='Select an amenity to continue';
      }catch(_){}
      return;
    }
    const personsWrap=document.getElementById('personsInput')?.closest('.res-item');
    const hoursLabel=document.getElementById('hoursLabel');
    const hoursInput=document.getElementById('hoursInput');
    const hoursCounter=document.getElementById('hoursCounter');
    const endTimeInput=document.getElementById('endTimeInput');
    const startTimeInput=document.getElementById('startTimeInput');
    const hrs=getAmenityHours(amen);
    if(startTimeInput){ startTimeInput.min=hrs.min; startTimeInput.max=hrs.max; }
    if(endTimeInput){ endTimeInput.min=hrs.min; endTimeInput.max=hrs.max; }
    const hn=document.getElementById('hoursNotice'); if(hn){ hn.textContent = amen==='Clubhouse' ? 'Available 9:00 AM – 9:00 PM' : 'Available 9:00 AM – 6:00 PM'; }
    if(isHourBasedAmenity(amen)){
      if(personsWrap){ personsWrap.style.display='none'; }
      if(hoursLabel){ hoursLabel.style.display='none'; }
      if(hoursCounter){ hoursCounter.style.display='none'; }
      if(hoursInput){ if(!hoursInput.value) hoursInput.value=1; }
      if(endTimeInput){ endTimeInput.readOnly=true; }
      if(startTimeInput && hoursInput){ computeEndTimeFromHours(); }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      renderHoursDropdownForAmenity();
      renderTimeSlotButtons();
    } else if(amen==='Pool'){
      if(personsWrap){ personsWrap.style.display='block'; }
      if(hoursLabel){ hoursLabel.style.display='none'; }
      if(hoursCounter){ hoursCounter.style.display='none'; }
      const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='inline-block'; }
      document.getElementById('hoursSectionLabel').style.display='block';
      document.getElementById('timeSectionLabel').style.display='block';
      if(hoursInput && !hoursInput.value) hoursInput.value=1;
      if(endTimeInput){ endTimeInput.readOnly=true; }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      renderHoursDropdownForAmenity();
      renderTimeSlotButtons();
    } else {
      if(personsWrap){ personsWrap.style.display='block'; }
      if(hoursLabel){ hoursLabel.style.display='none'; }
      if(hoursCounter){ hoursCounter.style.display='none'; }
      const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='none'; }
      if(endTimeInput){ endTimeInput.readOnly=false; }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      document.getElementById('hoursSectionLabel').style.display='none';
      document.getElementById('timeSectionLabel').style.display='block';
      renderTimeSlotButtons();
    }
  }

  function clampToRange(timeStr){
    if(!timeStr) return '';
    const amen=document.getElementById('amenityField').value;
    const hrs=getAmenityHours(amen);
    const [h,m]=(timeStr||'').split(':');
    let hh=parseInt(h||'0',10); let mm=parseInt(m||'0',10);
    const [minH]=hrs.min.split(':'), [maxH]=hrs.max.split(':');
    const minHour=parseInt(minH,10), maxHour=parseInt(maxH,10);
    if(hh<minHour){ hh=minHour; mm=0; }
    if(hh>maxHour){ hh=maxHour; mm=0; }
    return `${String(hh).padStart(2,'0')}:${String(mm).padStart(2,'0')}`;
  }

  function getAmenityHours(amen){
    if(amen==='Clubhouse') return {min:'09:00', max:'21:00'};
    return {min:'09:00', max:'18:00'};
  }

  function computeEndTimeFromHours(){
    const amen=document.getElementById('amenityField').value;
    const st=document.getElementById('startTimeInput').value;
    const hrs=parseInt(document.getElementById('hoursInput').value||'0',10);
    if(!st) return;
    if(!isHourBasedAmenity(amen)){
      const [sh,sm]=(st||'').split(':'); const endH=parseInt(sh||'0',10)+1; const et=`${String(endH).padStart(2,'0')}:${String(sm||'0').padStart(2,'0')}`; document.getElementById('endTimeInput').value=et; checkTimeAvailability(); updateActionStates(); return;
    }
    if(!hrs||hrs<1) return;
    const [sh,sm]=(clampToRange(st)||'').split(':');
    let h=parseInt(sh||'0',10), m=parseInt(sm||'0',10);
    let endH=h+hrs; let endM=m;
    const allowed=getAmenityHours(amen);
    const maxHour=parseInt(allowed.max.split(':')[0],10);
    if(endH>maxHour){ endH=maxHour; endM=0; }
    const et=`${String(endH).padStart(2,'0')}:${String(endM).padStart(2,'0')}`;
    document.getElementById('startTimeInput').value = clampToRange(st);
    document.getElementById('endTimeInput').value = et;
    checkTimeAvailability();
    updateActionStates();
    updateDisplayedPrice();
    updateSelectedTimeRange();
  }

  function updateSelectedTimeRange(){
    const st=document.getElementById('startTimeInput').value;
    const hrs=parseInt(document.getElementById('hoursInput').value||'1',10);
    const el=document.getElementById('selectedTimeRange');
    if(!el) return;
    if(!st){ el.style.display='none'; el.textContent=''; return; }
    const sh=parseInt((st||'').split(':')[0],10);
    const eh=sh+Math.max(1,hrs);
    el.textContent=`Selected: ${formatTimeSlot(sh)} - ${formatTimeSlot(eh)}`;
    el.style.display='block';
  }

  function computeAvailability(){
    const amenSel=document.getElementById('amenityField').value;
    if(!amenSel){ const card=document.querySelector('.amenity-card.selected'); if(card){ const pill=card.querySelector('.status-pill'); if(pill){ pill.textContent='Select amenity'; pill.className='status-pill neutral'; } } return; }
    const s=document.getElementById('startDateInput').value;
    const e=document.getElementById('endDateInput').value;
    const card=document.querySelector('.amenity-card.selected');
    if(!card) return;
    const pill=card.querySelector('.status-pill');
    if(!pill) return;
    if(!s||!e){pill.textContent='Select dates';pill.className='status-pill neutral';return}
    const sd=new Date(s),ed=new Date(e);
    let unavailable=false;
    for(let d=new Date(sd); d<=ed; d.setDate(d.getDate()+1)){
      const ds=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
      if(bookedDates.has(ds)){unavailable=true;break}
    }
    if(unavailable){pill.textContent='Unavailable';pill.className='status-pill unavailable'}
    else{pill.textContent='Available';pill.className='status-pill available'}
  }

  async function checkTimeAvailability(){
    const amenSel=document.getElementById('amenityField').value; if(!amenSel){ computeAvailability(); return; }
    const s=document.getElementById('startDateInput').value;
    const e=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const card=document.querySelector('.amenity-card.selected');
    if(!card) return;
    const pill=card.querySelector('.status-pill');
    if(!pill) { return; }
    if(!s||!e||!st||!et){computeAvailability();return}
    if(s!==e){computeAvailability();return}
    if(st && et){
      const [sh,sm]=st.split(':'), [eh,em]=et.split(':');
      const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10);
      const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10);
      const amen=document.getElementById('amenityField').value;
      const allowed=getAmenityHours(amen);
      const minHour=parseInt(allowed.min.split(':')[0],10);
      const maxHour=parseInt(allowed.max.split(':')[0],10);
      if(eMin<=sMin || parseInt(sh,10)<minHour || parseInt(eh,10)>maxHour){
        pill.textContent='Invalid time'; pill.className='status-pill unavailable';
        const te=document.getElementById('timeError'); if(te){ te.style.display='block'; te.textContent='Selected time is outside operating hours.'; }
        return;
      }
    }
    const times=await fetchBookedTimesFor(s);
    const overlap=times.some(t=>!(st>=t.end || et<=t.start));
    if(overlap){pill.textContent='Unavailable';pill.className='status-pill unavailable'}
    else{pill.textContent='Available';pill.className='status-pill available'}
    const te=document.getElementById('timeError');
    if(te){
      if(overlap){ te.style.display='block'; te.textContent='Time slot is already booked. Please choose a different time.'; }
      else { te.style.display='none'; te.textContent=''; }
    }
  }

  function isDateBooked(ds){ try { return bookedDates && bookedDates.has(ds); } catch(e){ return false; } }
  function showDateError(msg){ const el=document.getElementById('dateError'); if(el){ el.style.display = msg? 'block':'none'; el.textContent = msg || ''; } }
  function validateDates(){
    const s=document.getElementById('startDateInput').value;
    const e=document.getElementById('endDateInput').value;
    if(!s||!e){ showDateError(''); return false; }
    if(e < s){ showDateError('End date cannot be earlier than start date.'); return false; }
    if(s > e){ showDateError('Start date cannot be later than end date.'); return false; }
    let hasBooked=false; try {
      const sd=new Date(s), ed=new Date(e);
      for(let d=new Date(sd); d<=ed; d.setDate(d.getDate()+1)){
        const ds=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        if(isDateBooked(ds)){ hasBooked=true; break; }
      }
    } catch(err){}
    if(hasBooked){ showDateError('Selected dates are not available. Please choose different dates.'); return false; }
    showDateError(''); return true;
  }

  function setFieldWarning(id,msg){
    const container=(id==='startDateInput')?document.getElementById('startDateGroup'):(id==='endDateInput')?document.getElementById('endDateGroup'):(id==='amenityField')?document.querySelector('.amenities-list'):document.getElementById(id)?.closest('.res-item');
    if(!container)return;
    let w=container.querySelector('.field-warning[data-for="'+id+'"]');
    if(msg){
      if(!w){ w=document.createElement('div'); w.className='field-warning'; w.setAttribute('data-for',id); container.appendChild(w);} 
      let icon=w.querySelector('.warn-icon'); if(!icon){ icon=document.createElement('span'); icon.className='warn-icon'; icon.textContent='!'; w.appendChild(icon);} 
      let m=w.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; w.appendChild(m);} m.textContent=msg;
      let close=w.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; w.appendChild(close); close.addEventListener('click',function(){ w.remove(); }); }
    } else { if(w) w.remove(); }
  }

  let __dirtyFields = {};
  function markDirty(id){ try{ __dirtyFields[id] = true; }catch(_){} }
  function isDirty(id){ try{ return !!__dirtyFields[id]; }catch(_){ return false; } }
  function showIncompleteWarnings(force){
    const amen=document.getElementById('amenityField').value;
    const s=document.getElementById('startDateInput').value;
    const eD=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    if(!amen){ if(force||isDirty('amenityField')) setFieldWarning('amenityField','Please select an amenity.'); } else { setFieldWarning('amenityField',''); }
    if(!s){ if(force||isDirty('startDateInput')) setFieldWarning('startDateInput','Start date is required.'); } else { setFieldWarning('startDateInput',''); }
    if(!eD){ if(force||isDirty('endDateInput')) setFieldWarning('endDateInput','End date is required.'); } else { setFieldWarning('endDateInput',''); }
    if(!st){ if(force||isDirty('startTimeInput')) setFieldWarning('startTimeInput','Start time is required.'); } else { setFieldWarning('startTimeInput',''); }
    if(!et){ if(force||isDirty('endTimeInput')) setFieldWarning('endTimeInput','End time is required.'); } else { setFieldWarning('endTimeInput',''); }
    if(isHourBasedAmenity(amen)){
      if(hours<1){ if(force||isDirty('hoursInput')) setFieldWarning('hoursInput','Number of hours must be at least 1.'); } else { setFieldWarning('hoursInput',''); }
    } else if(amen==='Pool'){
      if(hours<1){ if(force||isDirty('hoursInput')) setFieldWarning('hoursInput','Number of hours must be at least 1.'); } else { setFieldWarning('hoursInput',''); }
      if(persons<1){ if(force||isDirty('personsInput')) setFieldWarning('personsInput','Persons must be at least 1.'); } else { setFieldWarning('personsInput',''); }
    } else {
      if(persons<1){ if(force||isDirty('personsInput')) setFieldWarning('personsInput','Persons must be at least 1.'); } else { setFieldWarning('personsInput',''); }
    }
  }

  function formIsComplete(){
    const amen=document.getElementById('amenityField').value;
    const s=document.getElementById('startDateInput').value;
    const eD=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    if(!amen||!s||!eD||!st||!et) return false;
    if(s && eD && eD < s) return false;
    if(st && et){ const [sh,sm]=(st||'').split(':'), [eh,em]=(et||'').split(':'); const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10); const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10); if(eMin<=sMin) return false; }
    if(isHourBasedAmenity(amen)){ if(hours<1) return false; }
    else if(amen==='Pool'){ if(hours<1 || persons<1) return false; }
    else { if(persons<1) return false; }
    return true;
  }

  document.getElementById("prevMonth").onclick=()=>{currentMonth=currentMonth===0?11:currentMonth-1;currentYear=currentMonth===11?currentYear-1:currentYear;renderCalendar(currentMonth,currentYear)};
  document.getElementById("nextMonth").onclick=()=>{currentMonth=currentMonth===11?0:currentMonth+1;currentYear=currentMonth===0?currentYear+1:currentYear;renderCalendar(currentMonth,currentYear)};
  loadBookedDates();

  document.querySelectorAll('[data-action="book-now"]').forEach(btn=>{
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      const card=this.closest('.amenity-card');
      if(card){ selectAmenityByKey(card.getAttribute('data-key')); }
    });
  });
  

  ['startTimeInput','endTimeInput'].forEach(id=>{const el=document.getElementById(id);if(el){el.addEventListener('input',function(){ if(isHourBasedAmenity(document.getElementById('amenityField').value) && id==='startTimeInput'){ computeEndTimeFromHours(); } else { checkTimeAvailability(); } })}});
  const hoursEl=document.getElementById('hoursInput'); if(hoursEl){ hoursEl.addEventListener('input',function(){ computeEndTimeFromHours(); updateDisplayedPrice(); updateDownpaymentSuggestion(); }); }
  const hoursSelect=document.getElementById('hoursSelect'); if(hoursSelect){ hoursSelect.addEventListener('change',function(){ const val=parseInt(hoursSelect.value||'1',10); const hid=document.getElementById('hoursInput'); if(hid){ hid.value=String(val); const hc=document.getElementById('hoursCount'); if(hc){ hc.textContent=String(val); } }
    computeEndTimeFromHours();
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    renderTimeSlotButtons();
  }); }
  document.addEventListener('DOMContentLoaded',function(){ renderHoursDropdownForAmenity(); renderTimeSlotButtons(); });
  const cs=document.getElementById('clearStartBtn'); if(cs){ cs.addEventListener('click',clearStartDate); }
  const ce=document.getElementById('clearEndBtn'); if(ce){ ce.addEventListener('click',clearEndDate); }
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
      const dpVal=document.getElementById('downpaymentInput')?document.getElementById('downpaymentInput').value:'';
      const persons=parseInt(document.getElementById('personsInput').value||'0');
      const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
      showIncompleteWarnings(true);
      if(s && eD && s===eD && st && et){
        const [sh,sm]=(st||'').split(':');
        const [eh,em]=(et||'').split(':');
        const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10);
        const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10);
        if(eMin<=sMin){ setFieldWarning('endTimeInput','End time must be after start time (24-hour).'); verifyAllowed=false; }
      }
      if(dpVal!=='' && !isNaN(Number(dpVal))){ if(Number(dpVal)<0){ setFieldWarning('downpaymentInput','Downpayment cannot be negative.'); verifyAllowed=false; } }
      const priceEl=document.getElementById('price');
      if(priceEl && dpVal!=='' && !isNaN(Number(dpVal))){ const price=parseFloat(priceEl.textContent.replace(/[^0-9.]/g,''))||0; if(Number(dpVal)>price){ setFieldWarning('downpaymentInput','Downpayment cannot exceed total price.'); verifyAllowed=false; } }
      if(isPersonBasedAmenity(amen) && persons<1){ setFieldWarning('personsInput','Persons must be at least 1.'); verifyAllowed=false; }
      if(s && eD && s===eD && st && et){
        const times=await fetchBookedTimesFor(s);
        const overlap=times.some(t=>!(st>=t.end || et<=t.start));
        if(overlap){
          e.preventDefault();
          alert('Selected time overlaps an existing booking. Please choose a different time.');
          return false;
        }
      }
      const amenVal=document.getElementById('amenityField').value;
      if(!amenVal || !s || !eD || !st || !et){ verifyAllowed=false; }
      if(isHourBasedAmenity(amenVal)){ if(hours<1) verifyAllowed=false; } else { if(persons<1) verifyAllowed=false; }
      if(!verifyAllowed){ e.preventDefault(); showToast('Please complete all fields accurately before proceeding.','warning'); return false; }
      // Only show modal if not confirmed yet
      if(!window.__verifyConfirmed){
        e.preventDefault();
        const priceTxt = (priceEl && priceEl.textContent) ? priceEl.textContent : '₱0';
        const unitsLabel = isHourBasedAmenity(amenVal) ? 'Hours' : 'Persons';
        const unitsValue = isHourBasedAmenity(amenVal) ? (parseInt(document.getElementById('hoursInput').value||'1',10)) : (parseInt(document.getElementById('personsInput').value||'1',10));
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
        // Reset confirmation for next submit
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
    if(pBtn){
      pBtn.addEventListener('click', function(){
        showIncompleteWarnings();
        if(!formIsComplete()){
          showToast('Please fix the highlighted fields before proceeding.','warning');
          return;
        }
        window.__verifyConfirmed=true;
        if(vm){ vm.style.display='none'; }
        showToast('Details confirmed. Redirecting to payment…','success');
        // Actually submit the form (bypass modal)
        const f=document.querySelector('form');
        if(f){
          f.requestSubmit();
        }
      });
    }
    if(btn){
      btn.addEventListener('click', function(e){
        const f=document.querySelector('form');
        if(f){
          f.requestSubmit();
        }
      });
    }
  })();
  function showToast(message,type){ const nl=document.getElementById('notifyLayer'); if(!nl) return; nl.textContent=message; nl.style.background= type==='warning' ? '#8a2a2a' : type==='success' ? '#23412e' : '#345c40'; nl.style.display='block'; clearTimeout(nl.__t); nl.__t=setTimeout(function(){ nl.style.display='none'; }, 3000); }
  function updateActionStates(){
    const s=document.getElementById('startDateInput').value;
    const eD=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const amenVal=document.getElementById('amenityField').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    const submitBtn=document.getElementById('submitBtn');
    const gate=document.getElementById('submitAllowed');
    let allowed=true;
    if(!amenVal) allowed=false;
    if(!s||!eD) allowed=false;
    if(!st||!et) allowed=false;
    if(s&&eD && eD < s) allowed=false;
    if(st&&et){ const [sh,sm]=(st||'').split(':'), [eh,em]=(et||'').split(':'); const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10); const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10); if(eMin<=sMin) allowed=false; }
    if(isHourBasedAmenity(amenVal)){ if(hours<1) allowed=false; }
    else if(amenVal==='Pool'){ if(hours<1 || persons<1) allowed=false; }
    else { if(persons<1) allowed=false; }
    
    if(submitBtn){ if(allowed){ submitBtn.classList.remove('disabled'); submitBtn.removeAttribute('disabled'); } else { submitBtn.classList.add('disabled'); submitBtn.setAttribute('disabled','disabled'); } }
    const sw=document.getElementById('submitWrap'); if(sw){ sw.style.display = 'flex'; }
  }
  function persistForm(){
    try{
      const data={
        amenity:document.getElementById('amenityField').value||'',
        start_date:document.getElementById('startDateInput').value||'',
        end_date:document.getElementById('endDateInput').value||'',
        start_time:document.getElementById('startTimeInput').value||'',
        end_time:document.getElementById('endTimeInput').value||'',
        persons:document.getElementById('personsInput').value||'1',
        hours:document.getElementById('hoursInput')?.value||'1',
        downpayment:document.getElementById('downpaymentInput')?.value||''
      };
      sessionStorage.setItem('reserve_form', JSON.stringify(data));
    }catch(_){}
  }
  function restoreFormFromSession(){
    try{
      const raw=sessionStorage.getItem('reserve_form'); if(!raw) return;
      const data=JSON.parse(raw||'{}');
      if(data.amenity){ document.getElementById('amenityField').value=data.amenity; }
      if(data.start_date){ document.getElementById('startDateInput').value=data.start_date; }
      if(data.end_date){ document.getElementById('endDateInput').value=data.end_date; }
      if(data.start_time){ document.getElementById('startTimeInput').value=data.start_time; }
      if(data.end_time){ document.getElementById('endTimeInput').value=data.end_time; }
      if(data.persons){ document.getElementById('personsInput').value=data.persons; document.getElementById('personCount').textContent=String(data.persons); }
      if(data.hours && document.getElementById('hoursInput')){ document.getElementById('hoursInput').value=data.hours; const hc=document.getElementById('hoursCount'); if(hc){ hc.textContent=String(data.hours); } }
      if(data.downpayment && document.getElementById('downpaymentInput')){ document.getElementById('downpaymentInput').value=data.downpayment; }
      configureFieldsForAmenity(document.getElementById('amenityField').value);
      const amenName=(document.getElementById('amenityField').value||'');
      const key = amenName==='Pool' ? 'pool' : amenName==='Clubhouse' ? 'clubhouse' : amenName==='Basketball Court' ? 'basketball' : amenName==='Tennis Court' ? 'tennis' : '';
      document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected'));
      const card=document.querySelector(`.amenity-card[data-key="${key}"]`); if(card){ card.classList.add('selected'); }
    }catch(_){}
  }
  ['amenityField','startDateInput','endDateInput','startTimeInput','endTimeInput','personsInput','hoursInput','downpaymentInput'].forEach(id=>{const el=document.getElementById(id); if(el){ el.addEventListener('input',function(){ markDirty(id); persistForm(); updateActionStates(); showIncompleteWarnings(false); }); }});
  document.addEventListener('DOMContentLoaded',function(){ restoreFormFromSession(); updateActionStates(); updateDisplayedPrice(); updateDownpaymentSuggestion(); initSingleDayToggle(); try{ const amen=document.getElementById('amenityField').value; if(!amen){ document.getElementById('reservationCard').style.display='none'; document.getElementById('reservationTitle').textContent='Reserve an Amenity'; document.getElementById('reservationHint').textContent='Select an amenity to continue'; } else { document.getElementById('reservationCard').style.display='flex'; document.getElementById('reservationTitle').textContent='Reservation'; document.getElementById('reservationHint').textContent='Select date, time, and persons'; } }catch(_){} });
  document.addEventListener('DOMContentLoaded',function(){ const s=document.getElementById('startTimeInput'); const e=document.getElementById('endTimeInput'); if(s){ s.value=''; } if(e){ e.value=''; } });
  function goBack(){ persistForm(); if(document.referrer){ window.history.back(); } else { window.location.href = 'mainpage.php'; } }
  function closeModal(){document.getElementById('refModal').style.display='none'}
  function closeHint(){document.getElementById('hintModal').style.display='none'}
</script>

<script>
  function formatTimeSlot(h){ const ampm = h>=12 ? 'PM' : 'AM'; let hh=h%12; if(hh===0) hh=12; return `${hh}:00 ${ampm}`; }
  function generateTimeSlots(amenity){ const hrs=getAmenityHours(amenity); const min=parseInt(hrs.min.split(':')[0],10); const max=parseInt(hrs.max.split(':')[0],10); const out=[]; for(let h=min; h<max; h++){ out.push({ label: formatTimeSlot(h), value: `${String(h).padStart(2,'0')}:00` }); } return out; }
  function computeMaxDuration(amenity,startHour,booked){ const hrs=getAmenityHours(amenity); const maxHour=parseInt(hrs.max.split(':')[0],10); let max=0; for(let h=1; startHour+h<=maxHour; h++){ const thisStart=`${String(startHour).padStart(2,'0')}:00`; const thisEnd=`${String(startHour+h).padStart(2,'0')}:00`; const overlaps=(booked||[]).some(t=>!(thisEnd<=t.start || thisStart>=t.end)); if(overlaps) break; max=h; } return max; }

  function renderHoursChipsForAmenity(){ const amen=document.getElementById('amenityField').value; const dc=document.getElementById('durationContainer'); const lbl=document.getElementById('hoursSectionLabel'); if(!dc) return; dc.innerHTML=''; if(!isHourBasedAmenity(amen)){ dc.style.display='none'; if(lbl) lbl.style.display='none'; return; } dc.style.display='flex'; if(lbl) lbl.style.display='block'; dc.style.flexWrap='wrap'; dc.style.gap='8px'; dc.style.margin='8px 0 0 0'; const maxH=amen==='Clubhouse'?12:9; for(let h=1; h<=maxH; h++){ const b=document.createElement('button'); b.type='button'; b.className='dur-btn'; b.textContent=`${h}h`; b.dataset.hours=String(h); b.onclick=function(){ selectDuration(h); }; dc.appendChild(b); } const currentH=parseInt(document.getElementById('hoursInput').value||'1',10); const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(currentH)); if(sel) sel.classList.add('selected'); }

  function renderTimeSlotButtons(){ const amen=document.getElementById('amenityField').value; const container=document.getElementById('timeSlotContainer'); const tLbl=document.getElementById('timeSectionLabel'); if(!container) return; container.innerHTML=''; if(!(amen==='Pool' || isHourBasedAmenity(amen))){ container.style.display='none'; if(tLbl) tLbl.style.display='none'; return; } container.style.display='flex'; if(tLbl) tLbl.style.display='block'; container.style.flexWrap='wrap'; container.style.gap='8px'; container.style.margin='8px 0 0 0'; const slots=generateTimeSlots(amen); const date=document.getElementById('startDateInput').value; const hours=parseInt(document.getElementById('hoursInput').value||'1',10); window.__slotRenderTokenCounter=(window.__slotRenderTokenCounter||0)+1; const __token=window.__slotRenderTokenCounter; window.__activeSlotRenderToken=__token; if(!date){ slots.forEach(slot=>{ const btn=document.createElement('button'); btn.type='button'; btn.className='slot-btn airbnb unavailable'; btn.textContent=slot.label; btn.disabled=true; container.appendChild(btn); }); const msg=document.createElement('div'); msg.style.width='100%'; msg.style.color='#888'; msg.style.fontSize='0.98em'; msg.style.margin='8px 0 0 0'; msg.textContent='Select a date to see available time slots.'; container.appendChild(msg); return; } fetchBookedTimesFor(date).then(booked=>{ if(window.__activeSlotRenderToken!==__token) return; window.__bookedTimesForDate=booked||[]; let anyEnabled=false; slots.forEach(slot=>{ const startHour=parseInt(slot.value.split(':')[0],10); const maxPossible=computeMaxDuration(amen,startHour,booked); const valid=(maxPossible>=hours); const btn=document.createElement('button'); btn.type='button'; btn.className='slot-btn airbnb'; btn.textContent=slot.label; btn.dataset.slot=slot.value; if(!valid){ btn.disabled=true; btn.classList.add('unavailable'); } else { anyEnabled=true; btn.onclick=function(){ selectTimeSlot(slot.value); }; } container.appendChild(btn); }); if(!anyEnabled){ const msg=document.createElement('div'); msg.style.width='100%'; msg.style.color='#888'; msg.style.fontSize='0.98em'; msg.style.margin='8px 0 0 0'; msg.textContent='No start times fit the selected hours. Try a different duration.'; container.appendChild(msg); } const st=document.getElementById('startTimeInput').value; if(st){ const selBtn=Array.from(container.children).find(b=>b.dataset.slot===st); if(selBtn) selBtn.classList.add('selected'); } updateActionStates(); }); }

  function selectTimeSlot(start){ const hInput=document.getElementById('hoursInput'); const hrs=parseInt(hInput?.value||'1',10); const amen=document.getElementById('amenityField').value; const booked=window.__bookedTimesForDate||[]; const startHour=parseInt(start.split(':')[0],10); if(computeMaxDuration(amen,startHour,booked) < Math.max(1,hrs)){ showToast(`⚠️ Not enough free hours starting from this time to complete ${hrs} hour${hrs>1?'s':''}.`,'warning'); return; } document.getElementById('startTimeInput').value=start; computeEndTimeFromHours(); const sh=startHour, eh=sh+hrs; const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.textContent=`Selected: ${formatTimeSlot(sh)} - ${formatTimeSlot(eh)}`; tr.style.display='block'; } updateActionStates(); }
  function renderHoursDropdownForAmenity(){
    const amen=document.getElementById('amenityField').value;
    const sel=document.getElementById('hoursSelect');
    const lbl=document.getElementById('hoursSectionLabel');
    if(!sel) return;
    sel.innerHTML='';
    if(!(isHourBasedAmenity(amen) || amen==='Pool')){ sel.style.display='none'; if(lbl) lbl.style.display='none'; return; }
    sel.style.display='inline-block'; if(lbl) lbl.style.display='block';
    const maxH=amen==='Clubhouse'?12:9;
    for(let h=1; h<=maxH; h++){ const opt=document.createElement('option'); opt.value=String(h); opt.textContent=`${h} hour${h>1?'s':''}`; sel.appendChild(opt); }
    const currentH=parseInt(document.getElementById('hoursInput').value||'1',10);
    sel.value=String(currentH);
  }

  function decorateSlotButtons(){
    const container=document.getElementById('timeSlotContainer');
    if(!container) return;
    const amen=document.getElementById('amenityField').value;
    const hours=parseInt(document.getElementById('hoursInput').value||'1',10);
    const booked=window.__bookedTimesForDate||[];
    const selectedDate=document.getElementById('startDateInput').value||'';
    const now = new Date();
    const todayStrLocal = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
    const currentHour=now.getHours();
    const currentMinute=now.getMinutes();
    Array.from(container.querySelectorAll('.slot-btn')).forEach(function(btn){
      const ds=btn.dataset.slot; if(!ds) return;
      const sh=parseInt(ds.split(':')[0],10);
      const maxPossible=computeMaxDuration(amen,sh,booked);
      const isPastOnToday = (selectedDate===todayStrLocal) && (sh<currentHour || (sh===currentHour && currentMinute>0));
      if(btn.disabled || isPastOnToday){
        btn.disabled=true;
        btn.classList.add('unavailable');
        if(isPastOnToday){ btn.title='This time has already passed and cannot be booked.'; }
        else { btn.title=`Partially booked — some slots still available. Need ${hours} hour${hours>1?'s':''}, only ${Math.max(0,maxPossible)} available from ${btn.textContent}`; }
      } else {
        btn.title='Available';
      }
    });
  }

  (function observeSlotContainer(){
    const container=document.getElementById('timeSlotContainer');
    if(!container) return;
    const obs=new MutationObserver(function(){ decorateSlotButtons(); });
    obs.observe(container,{childList:true});
  })();

</script>

</body>
</html>