<?php
ob_start();
session_start();
require_once 'connect.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
  header('Location: login.php');
  exit;
}

$errorMsg = '';
$generatedCode = '';
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function ensureGuestFormsAmenityColumns($con) {
  if (!($con instanceof mysqli)) return;
  $cols = ['amenity','start_date','end_date','price'];
  foreach ($cols as $c) {
    $check = $con->query("SHOW COLUMNS FROM guest_forms LIKE '".$con->real_escape_string($c)."'");
    if ($check && $check->num_rows === 0) {
      if ($c === 'amenity') $con->query("ALTER TABLE guest_forms ADD COLUMN amenity VARCHAR(100) NULL AFTER wants_amenity");
      if ($c === 'start_date') $con->query("ALTER TABLE guest_forms ADD COLUMN start_date DATE NULL AFTER amenity");
      if ($c === 'end_date') $con->query("ALTER TABLE guest_forms ADD COLUMN end_date DATE NULL AFTER start_date");
      if ($c === 'price') $con->query("ALTER TABLE guest_forms ADD COLUMN price DECIMAL(10,2) NULL AFTER persons");
    }
  }
}

function ensureGuestFormsTimeColumns($con){
  if (!($con instanceof mysqli)) return;
  $c1 = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
  if(!$c1 || $c1->num_rows===0){ @$con->query("ALTER TABLE guest_forms ADD COLUMN start_time TIME NULL AFTER start_date"); }
  $c2 = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
  if(!$c2 || $c2->num_rows===0){ @$con->query("ALTER TABLE guest_forms ADD COLUMN end_time TIME NULL AFTER end_date"); }
}

ensureGuestFormsAmenityColumns($con);
ensureGuestFormsTimeColumns($con);

if (isset($_GET['action']) && $_GET['action'] === 'booked_dates') {
  header('Content-Type: application/json');
  $amenity = isset($_GET['amenity']) ? trim($_GET['amenity']) : '';
  $dates = [];
  $collect = function($res) use (&$dates){
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
    if ($amenity !== '') {
      $stmt1 = $con->prepare("SELECT start_date, end_date FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved'))");
      $stmt1->bind_param('s',$amenity); $stmt1->execute(); $collect($stmt1->get_result()); $stmt1->close();
      $stmt2 = $con->prepare("SELECT start_date, end_date FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved')");
      $stmt2->bind_param('s',$amenity); $stmt2->execute(); $collect($stmt2->get_result()); $stmt2->close();
      $stmt3 = $con->prepare("SELECT start_date, end_date FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved')");
      $stmt3->bind_param('s',$amenity); $stmt3->execute(); $collect($stmt3->get_result()); $stmt3->close();
    }
  } catch (Throwable $e) {
    error_log('reserve_guest booked_dates error: '.$e->getMessage());
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
  try {
    if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
    if ($amenity !== '' && $date) {
      $stmt1 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved'))");
      $stmt1->bind_param('s',$amenity); $stmt1->execute(); $res1=$stmt1->get_result();
      while ($row = $res1->fetch_assoc()) {
        if (!$row['start_date'] || !$row['end_date']) continue;
        if ($date >= $row['start_date'] && $date <= $row['end_date']) {
          $times[] = ['start' => ($row['start_time'] ?: '00:00:00'), 'end' => ($row['end_time'] ?: '23:59:59'), 'has_time' => (!empty($row['start_time']) && !empty($row['end_time']))];
        }
      }
      $stmt1->close();
      $stmt2 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved')");
      $stmt2->bind_param('s',$amenity); $stmt2->execute(); $res2=$stmt2->get_result();
      while ($row = $res2->fetch_assoc()) {
        if (!$row['start_date'] || !$row['end_date']) continue;
        if ($date >= $row['start_date'] && $date <= $row['end_date']) {
          $times[] = ['start' => ($row['start_time'] ?: '00:00:00'), 'end' => ($row['end_time'] ?: '23:59:59'), 'has_time' => (!empty($row['start_time']) && !empty($row['end_time']))];
        }
      }
      $stmt2->close();
      $stmt3 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved')");
      $stmt3->bind_param('s',$amenity); $stmt3->execute(); $res3=$stmt3->get_result();
      while ($row = $res3->fetch_assoc()) {
        if (!$row['start_date'] || !$row['end_date']) continue;
        if ($date >= $row['start_date'] && $date <= $row['end_date']) {
          $times[] = ['start' => ($row['start_time'] ?: '00:00:00'), 'end' => ($row['end_time'] ?: '23:59:59'), 'has_time' => (!empty($row['start_time']) && !empty($row['end_time']))];
        }
      }
      $stmt3->close();
    }
  } catch (Throwable $e) {
    error_log('reserve_guest booked_times error: '.$e->getMessage());
    $times = [];
  }
  echo json_encode(['times' => $times]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
  if (!is_string($tokenPosted) || !hash_equals($_SESSION['csrf_token'] ?? '', $tokenPosted)) {
    $errorMsg = 'Invalid form submission.';
  } else {
    $amenity = $_POST['amenity'] ?? '';
    $start   = $_POST['startDate'] ?? '';
    $end     = $_POST['endDate'] ?? '';
    $startTime = $_POST['startTime'] ?? '';
    $endTime   = $_POST['endTime'] ?? '';
    $persons = intval($_POST['persons'] ?? 1);
    $hours = intval($_POST['hours'] ?? 0);
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
    } else if ($amenity === 'Pool' && $persons < 1) {
      $errorMsg = 'Persons must be at least 1.';
    } else if ($stObj && $etObj) {
      $minH = ($amenity === 'Clubhouse') ? 9 : 9;
      $maxH = ($amenity === 'Clubhouse') ? 21 : 18;
      if ((int)$stObj->format('H') < $minH || (int)$etObj->format('H') > $maxH) {
        $errorMsg = 'Selected time is outside operating hours.';
      }
    }
    if (!$errorMsg) {
      $cnt = 0;
      $singleDay = ($start && $end && $start === $end && $startTime && $endTime);
      try {
        if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
        if ($singleDay) {
          $check1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
          $check1->bind_param('ssss',$amenity,$start,$startTime,$endTime); $check1->execute(); $r1=$check1->get_result(); $cnt += ($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $check1->close();
          $check2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
          $check2->bind_param('ssss',$amenity,$start,$startTime,$endTime); $check2->execute(); $r2=$check2->get_result(); $cnt += ($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $check2->close();
          $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (approval_status IN ('pending','approved')) AND (TIME(?) < end_time AND TIME(?) > start_time)");
          $check3->bind_param('ssss',$amenity,$start,$startTime,$endTime); $check3->execute(); $r3=$check3->get_result(); $cnt += ($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $check3->close();
        } else {
          $check1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND start_date <= ? AND end_date >= ?");
          $check1->bind_param('sss',$amenity,$end,$start); $check1->execute(); $r1=$check1->get_result(); $cnt += ($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $check1->close();
          $check2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND start_date <= ? AND end_date >= ?");
          $check2->bind_param('sss',$amenity,$end,$start); $check2->execute(); $r2=$check2->get_result(); $cnt += ($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $check2->close();
          $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND start_date <= ? AND end_date >= ? AND (approval_status IN ('pending','approved'))");
          $check3->bind_param('sss',$amenity,$end,$start); $check3->execute(); $r3=$check3->get_result(); $cnt += ($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $check3->close();
        }
      } catch (Throwable $e) {
        error_log('reserve_guest POST error: '.$e->getMessage());
        $errorMsg = 'Server error. Please try again later.';
      }
      if (!$errorMsg && $cnt > 0) {
        $errorMsg = 'Selected dates are not available. Please choose different dates.';
      }
    }
    if (!$errorMsg) {
      try {
        if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
        $price = 0.0;
        if (in_array($amenity, ['Basketball Court','Tennis Court'], true)) {
          $price = max(1, $hours) * 150;
        } else if ($amenity === 'Clubhouse') {
          $price = max(1, $hours) * 200;
        } else if ($amenity === 'Pool') {
          $price = max(1, $persons) * 175;
        }
        $exists = $con->prepare('SELECT id, resident_user_id FROM guest_forms WHERE ref_code = ? LIMIT 1');
        $exists->bind_param('s',$ref_code); $exists->execute(); $er = $exists->get_result(); $exists->close();
        if (!$er || $er->num_rows === 0) { throw new Exception('Guest form not found.'); }
        $row = $er->fetch_assoc();
        $ownerId = intval($row['resident_user_id'] ?? 0);
        if ($ownerId !== intval($_SESSION['user_id'] ?? 0)) { throw new Exception('Unauthorized.'); }
        $upd = $con->prepare('UPDATE guest_forms SET amenity = ?, start_date = ?, end_date = ?, start_time = ?, end_time = ?, persons = ?, price = ?, wants_amenity = 1, updated_at = NOW(), approval_status = "pending" WHERE ref_code = ?');
        $upd->bind_param('sssssids', $amenity,$start,$end,$startTime,$endTime,$persons,$price,$ref_code);
        $upd->execute(); $upd->close();
        $generatedCode = $ref_code;
      } catch (Throwable $e) {
        error_log('reserve_guest upsert error: '.$e->getMessage());
        $errorMsg = 'Server error. Please try again later.';
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass - Reserve Guest Amenity</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link rel="stylesheet" href="css/reserve.css">
</head>
<body>
  <div id="notifyLayer" class="toast"></div>
  <header class="navbar">
    <div class="logo">
      <a href="mainpage.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
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
            <div class="schedule-panel" data-schedule-panel></div>
          </div>
          <div class="amenity-card" data-amenity="Clubhouse" data-key="clubhouse" data-price="200" role="button" tabindex="0">
            <img src="images/clubhouse.svg" alt="Clubhouse">
            <div class="info">
              <div class="title-block"><div class="name">Clubhouse</div><div class="price">₱200 / hour</div></div>
              <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button><button type="button" class="btn-link" data-action="view-desc">View Description</button></div>
            </div>
            <div class="schedule-panel" data-schedule-panel></div>
          </div>
          <div class="amenity-card" data-amenity="Basketball Court" data-key="basketball" data-price="150" role="button" tabindex="0">
            <img src="images/basketball.svg" alt="Basketball">
            <div class="info">
              <div class="title-block"><div class="name">Basketball Court</div><div class="price">₱150 / hour</div></div>
              <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button><button type="button" class="btn-link" data-action="view-desc">View Description</button></div>
            </div>
            <div class="schedule-panel" data-schedule-panel></div>
          </div>
          <div class="amenity-card" data-amenity="Tennis Court" data-key="tennis" data-price="150" role="button" tabindex="0">
            <img src="images/tennis.jpg" alt="Tennis">
            <div class="info">
              <div class="title-block"><div class="name">Tennis Court</div><div class="price">₱150 / hour</div></div>
              <div class="meta"><button type="button" class="btn-main small" data-action="book-now">Book Now</button><button type="button" class="btn-link" data-action="view-desc">View Description</button></div>
            </div>
            <div class="schedule-panel" data-schedule-panel></div>
          </div>
        </div>
      </div>

      <div class="right-panel">
        <div class="section-header"><h2 id="reservationTitle">Reserve an Amenity</h2><p id="reservationHint">Select an amenity to continue</p></div>
        <?php if (!empty($errorMsg)) { ?><div class="alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php } ?>
        <form method="POST">
          <input type="hidden" name="purpose" value="Amenity Reservation for Guest">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
          <input type="hidden" name="ref_code" value="<?php echo htmlspecialchars($_GET['ref_code'] ?? ''); ?>">
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
              <small id="price">₱0</small>
              <input type="hidden" name="persons" id="personsInput" value="1">
            </div>
            <div id="submitWrap" class="res-item" style="flex-basis:100%; margin-top:8px; display:none; gap:8px; align-items:center; flex-wrap:wrap;">
              <button type="button" class="btn-submit" onclick="goBack()">Go Back</button>
              <button id="submitBtn" class="btn-submit disabled" type="submit" disabled>Submit</button>
              <?php $refParam = isset($_GET['ref_code']) ? $_GET['ref_code'] : ''; ?>
              <?php if (!empty($refParam)) { ?><span class="ref-inline">Status Code: <?php echo htmlspecialchars($refParam); ?></span><?php } ?>
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
      <p>Use this code in the <b>Check Status</b> page to track the guest reservation.</p>
      <div style="text-align:center;margin-top:8px;">
        <button class="close-btn" onclick="closeModal()">OK</button>
      </div>
      <div style="text-align:center;margin-top:12px;">
        <a href="mainpage.php#" class="btn-secondary" title="Back to Home">← Back to Home</a>
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

  async function loadBookedDates(){
    if(!selectedAmenity){ bookedDates=new Set(); renderCalendar(currentMonth,currentYear); computeAvailability(); return; }
    try{ const res=await fetch(`reserve_guest.php?action=booked_dates&amenity=${encodeURIComponent(selectedAmenity)}`); const data=await res.json(); bookedDates=new Set(data.dates||[]); }
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
    if(cell.classList.contains('disabled')){ return; }
    document.querySelectorAll('.calendar td').forEach(td=>td.classList.remove('active'));
    cell.classList.add('active');
    const single = document.getElementById('singleDayToggle')?.checked;
    function setStart(ds){
      const eVal=document.getElementById('endDateInput').value||'';
      if(eVal && ds > eVal){ showStartDateError('Start date cannot be later than end date.'); return false; }
      selectedStart=ds; document.getElementById('startDate').textContent=selectedStart; document.getElementById('startDateInput').value=selectedStart; showStartDateError(''); return true;
    }
    function setEnd(ds){
      const sVal=document.getElementById('startDateInput').value||'';
      if(sVal && ds < sVal){ showDateError('End date cannot be earlier than start date.'); return false; }
      selectedEnd=ds; document.getElementById('endDate').textContent=selectedEnd; document.getElementById('endDateInput').value=selectedEnd; showDateError(''); return true;
    }
    if(single){ setStart(dateString) && setEnd(dateString); }
    else {
      if(!selectedStart){ setStart(dateString); }
      else if(!selectedEnd){ setEnd(dateString); }
      else { if(setStart(dateString)){ selectedEnd=null; document.getElementById('endDate').textContent='--'; document.getElementById('endDateInput').value=''; } }
    }
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('startDateInput');
    updateActionStates();
    updateSelectedTimeRange();
  }

  function clearStartDate(){ selectedStart=null; document.getElementById('startDate').textContent='--'; document.getElementById('startDateInput').value=''; const single = document.getElementById('singleDayToggle')?.checked; if(single){ selectedEnd=null; document.getElementById('endDate').textContent='--'; document.getElementById('endDateInput').value=''; } computeAvailability(); renderTimeSlotButtons(); markDirty('startDateInput'); updateActionStates(); updateSelectedTimeRange(); }
  function clearEndDate(){ selectedEnd=null; document.getElementById('endDate').textContent='--'; document.getElementById('endDateInput').value=''; computeAvailability(); renderTimeSlotButtons(); markDirty('endDateInput'); updateActionStates(); updateSelectedTimeRange(); }

  function initSingleDayToggle(){ const cb=document.getElementById('singleDayToggle'); if(!cb) return; cb.addEventListener('change', function(){ const s=document.getElementById('startDateInput').value; if(this.checked){ if(s){ selectedEnd=s; document.getElementById('endDateInput').value=s; document.getElementById('endDate').textContent=s; } } computeAvailability(); renderTimeSlotButtons(); updateActionStates(); updateSelectedTimeRange(); }); }

  const amenityData={ pool:{title:'Community Pool',value:'Pool',img:'images/pool.svg',desc:'Relax and enjoy the pool with convenient reservation options.'}, clubhouse:{title:'Clubhouse',value:'Clubhouse',img:'images/clubhouse.svg',desc:'Host gatherings and events in the subdivision clubhouse.'}, basketball:{title:'Basketball Court',value:'Basketball Court',img:'images/basketball.svg',desc:'Play and practice on our outdoor basketball court.'}, tennis:{title:'Tennis Court',value:'Tennis Court',img:'images/tennis.jpg',desc:'Reserve time to enjoy a game at the tennis court.'} };

  function openAmenityImageModal(key){ try{ const info=amenityData[key]||amenityData.pool; const modal=document.getElementById('amenityImageModal'); const img=document.getElementById('amenityImageModalImg'); if(!modal||!img) return; img.src=info.img; img.alt=info.title; modal.style.display='flex'; }catch(_){ } }
  (function initAmenityImageModal(){ const modal=document.getElementById('amenityImageModal'); const close=document.getElementById('amenityImageClose'); if(close){ close.onclick=function(){ if(modal) modal.style.display='none'; }; } if(modal){ modal.addEventListener('click',function(e){ if(e.target===modal){ modal.style.display='none'; } }); } })();

  function selectAmenityByKey(key){ const info=amenityData[key]||amenityData.pool; selectedAmenity=info.value; document.getElementById('amenityField').value=info.value; document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected')); const card=document.querySelector(`.amenity-card[data-key="${key}"]`); if(card) card.classList.add('selected'); resetReservationForm(); document.querySelectorAll('.schedule-panel').forEach(p=>p.style.display='none'); loadBookedDates(); configureFieldsForAmenity(selectedAmenity); renderHoursDropdownForAmenity(); renderTimeSlotButtons(); try{ document.getElementById('reservationCard').style.display='none'; document.getElementById('reservationTitle').textContent='Reserve an Amenity'; document.getElementById('reservationHint').textContent='Select an amenity to continue'; }catch(_){} }

  function resetReservationForm(){ try{ selectedStart=null; selectedEnd=null; ['startDateInput','endDateInput','startTimeInput','endTimeInput'].forEach(function(id){ const el=document.getElementById(id); if(el){ el.value=''; } }); const sd=document.getElementById('startDate'); if(sd){ sd.textContent='--'; } const ed=document.getElementById('endDate'); if(ed){ ed.textContent='--'; } const pc=document.getElementById('personCount'); if(pc){ pc.textContent='1'; } const pi=document.getElementById('personsInput'); if(pi){ pi.value='1'; } const hi=document.getElementById('hoursInput'); if(hi){ hi.value='1'; } const hs=document.getElementById('hoursSelect'); if(hs){ hs.value='1'; } const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.textContent=''; tr.style.display='none'; } showStartDateError(''); showDateError(''); setFieldWarning('startTimeInput',''); setFieldWarning('endTimeInput',''); setFieldWarning('personsInput',''); setFieldWarning('hoursInput',''); updateDisplayedPrice(); updateActionStates(); }catch(_){ } }

  document.querySelectorAll('.amenity-card').forEach(function(card){ card.addEventListener('click',function(){ const key=card.getAttribute('data-key'); selectAmenityByKey(key); openAmenityImageModal(key); }); });
  const amenitiesList=document.getElementById('amenitiesList');
  if(amenitiesList){ amenitiesList.addEventListener('click',function(e){ const bookBtn=e.target.closest('button[data-action="book-now"]'); if(bookBtn){ const card=e.target.closest('.amenity-card'); if(card){ selectAmenityByKey(card.getAttribute('data-key')); try{ const rc=document.getElementById('reservationCard'); rc.style.display='flex'; document.getElementById('reservationTitle').textContent='Reservation'; document.getElementById('reservationHint').textContent='Select date, time, and persons'; rc.scrollIntoView({behavior:'smooth',block:'start'}); }catch(_){} } return; } const viewBtn=e.target.closest('button[data-action="view-desc"]'); if(viewBtn){ const card=e.target.closest('.amenity-card'); if(card){ const key=card.getAttribute('data-key'); openAmenityImageModal(key); } return; } }); }

  function showStartDateError(msg){ const el=document.getElementById('startDateError'); if(!el) return; el.textContent=msg||''; el.style.display=msg?'block':'none'; }
  function showDateError(msg){ const el=document.getElementById('dateError'); if(!el) return; el.textContent=msg||''; el.style.display=msg?'block':'none'; }
  function setFieldWarning(id,msg){ const el=document.getElementById(id+'Warn'); if(!el) return; el.textContent=msg||''; el.style.display=msg?'block':'none'; }

  function getAmenityHours(a){ if(a==='Clubhouse'){ return {min:'09:00',max:'21:00'}; } return {min:'09:00',max:'18:00'}; }
  function isHourBasedAmenity(a){ return a==='Basketball Court' || a==='Tennis Court' || a==='Clubhouse'; }

  function changeHours(delta){ const hi=document.getElementById('hoursInput'); let h=parseInt(hi.value||'1',10); h=Math.max(1,h+delta); hi.value=String(h); document.getElementById('hoursCount').textContent=String(h); renderTimeSlotButtons(); updateDisplayedPrice(); }
  function changePersons(delta){ const pi=document.getElementById('personsInput'); let p=parseInt(pi.value||'1',10); p=Math.max(1,p+delta); pi.value=String(p); document.getElementById('personCount').textContent=String(p); updateDisplayedPrice(); }

  function updateDisplayedPrice(){ const amen=document.getElementById('amenityField').value; let price=0; if(amen==='Basketball Court' || amen==='Tennis Court'){ price = Math.max(1, parseInt(document.getElementById('hoursInput').value||'1',10)) * 150; } else if(amen==='Clubhouse'){ price = Math.max(1, parseInt(document.getElementById('hoursInput').value||'1',10)) * 200; } else if(amen==='Pool'){ price = Math.max(1, parseInt(document.getElementById('personsInput').value||'1',10)) * 175; } document.getElementById('price').textContent = '₱' + price.toLocaleString(); }

  function renderHoursDropdownForAmenity(){ const amen=document.getElementById('amenityField').value; const hs=document.getElementById('hoursSelect'); const hc=document.getElementById('hoursCounter'); const hl=document.getElementById('hoursLabel'); const hsl=document.getElementById('hoursSectionLabel'); const st=document.getElementById('startTimeInput'); const et=document.getElementById('endTimeInput'); const tsl=document.getElementById('timeSectionLabel'); const dur=document.getElementById('durationContainer'); if(isHourBasedAmenity(amen)){ hs.style.display='block'; hc.style.display='none'; hl.style.display='block'; hsl.style.display='block'; st.style.display='block'; et.style.display='block'; tsl.style.display='block'; dur.style.display='block'; hs.innerHTML=''; for(let i=1;i<=8;i++){ const opt=document.createElement('option'); opt.value=String(i); opt.textContent=String(i)+' hour'+(i>1?'s':''); hs.appendChild(opt); } hs.value='1'; document.getElementById('hoursInput').value='1'; } else { hs.style.display='none'; hc.style.display='flex'; hl.style.display='none'; hsl.style.display='none'; st.style.display='none'; et.style.display='none'; tsl.style.display='none'; dur.style.display='none'; document.getElementById('hoursInput').value='1'; } }

  function fmtTime(t){ if(!t) return ''; const p=String(t).split(':'), h=(p[0]||'00'), m=(p[1]||'00'); return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`; }
  function renderTimeSlotButtons(){ const amen=document.getElementById('amenityField').value; const cont=document.getElementById('timeSlotContainer'); cont.innerHTML=''; if(!amen) return; const hrsRange=getAmenityHours(amen); const minH=parseInt(hrsRange.min.split(':')[0],10); const maxH=parseInt(hrsRange.max.split(':')[0],10); const startSel=document.getElementById('startTimeInput'); const endSel=document.getElementById('endTimeInput'); startSel.value = String(minH).padStart(2,'0')+':00'; endSel.value = String(minH+1).padStart(2,'0')+':00'; const row=document.createElement('div'); row.className='time-row'; for(let h=minH; h<maxH; h++){ const b=document.createElement('button'); b.type='button'; b.textContent=fmtTime(`${String(h).padStart(2,'0')}:00`); b.addEventListener('click', function(){ startSel.value = fmtTime(`${String(h).padStart(2,'0')}:00`); endSel.value = fmtTime(`${String(Math.min(h+1,maxH)).padStart(2,'0')}:00`); updateSelectedTimeRange(); updateActionStates(); }); cont.appendChild(b); } cont.appendChild(row); }

  async function fetchBookedTimesFor(date){ try{ const amen=document.getElementById('amenityField').value; const res=await fetch(`reserve_guest.php?action=booked_times&amenity=${encodeURIComponent(amen)}&date=${encodeURIComponent(date)}`); return (await res.json()).times||[]; }catch(_){ return []; } }

  function markDirty(id){ const el=document.getElementById(id); if(!el) return; el.setAttribute('data-dirty','1'); }
  function showIncompleteWarnings(){ /* intentionally simplified for guest */ }
  function updateActionStates(){ const ready = !!document.getElementById('amenityField').value && !!document.getElementById('startDateInput').value && !!document.getElementById('endDateInput').value; const btn=document.getElementById('submitBtn'); if(btn){ btn.disabled = !ready; btn.classList.toggle('disabled', !ready); } const sw=document.getElementById('submitWrap'); if(sw){ sw.style.display = 'block'; } }
  function updateSelectedTimeRange(){ const st=document.getElementById('startTimeInput').value, et=document.getElementById('endTimeInput').value; const trg=document.getElementById('selectedTimeRange'); if(st && et){ trg.textContent = `Selected: ${st} - ${et}`; trg.style.display = 'block'; } else { trg.textContent=''; trg.style.display='none'; } }
  function goBack(){ history.back(); }
  function closeModal(){ document.getElementById('refModal').style.display='none'; }

  document.getElementById('prevMonth').addEventListener('click', function(){ currentYear = (currentMonth===0 ? currentYear-1 : currentYear); currentMonth = (currentMonth===0 ? 11 : currentMonth-1); renderCalendar(currentMonth,currentYear); });
  document.getElementById('nextMonth').addEventListener('click', function(){ currentYear = (currentMonth===11 ? currentYear+1 : currentYear); currentMonth = (currentMonth===11 ? 0 : currentMonth+1); renderCalendar(currentMonth,currentYear); });

  loadBookedDates();
  initSingleDayToggle();
  updateDisplayedPrice();
  </script>
</body>
</html>

