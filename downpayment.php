<?php
session_start();
include 'connect.php';

// FETCH USER EMAIL FROM ENTRYPASS
$entry_pass_id = intval($_GET['entry_pass_id'] ?? 0);
$user_email_prefill = '';
if($entry_pass_id > 0){
    $stmt = $con->prepare("SELECT email FROM entry_passes WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $entry_pass_id);
    $stmt->execute();
    $stmt->bind_result($user_email);
    if($stmt->fetch()){
        $user_email_prefill = $user_email;
    }
    $stmt->close();
}

// Helpers and CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
function ensureReservationsCommonColumns($con){ if(!($con instanceof mysqli)) return; $cols=['downpayment','receipt_path','payment_status','account_type','booking_for','receipt_uploaded_at']; foreach($cols as $col){ $c=$con->query("SHOW COLUMNS FROM reservations LIKE '".$con->real_escape_string($col)."'"); if(!$c || $c->num_rows===0){ if($col==='downpayment'){ @$con->query("ALTER TABLE reservations ADD COLUMN downpayment DECIMAL(10,2) NULL"); } else if($col==='receipt_path'){ @$con->query("ALTER TABLE reservations ADD COLUMN receipt_path VARCHAR(255) NULL"); } else if($col==='payment_status'){ @$con->query("ALTER TABLE reservations ADD COLUMN payment_status ENUM('pending','submitted','verified') NULL"); } else if($col==='account_type'){ @$con->query("ALTER TABLE reservations ADD COLUMN account_type ENUM('visitor','resident') NULL"); } else if($col==='booking_for'){ @$con->query("ALTER TABLE reservations ADD COLUMN booking_for ENUM('resident','guest') NULL"); } else if($col==='receipt_uploaded_at'){ @$con->query("ALTER TABLE reservations ADD COLUMN receipt_uploaded_at DATETIME NULL"); } } } }
ensureReservationsCommonColumns($con);

function ensureReservationBookerColumns($con){
    if(!($con instanceof mysqli)) return;
    $c1 = $con->query("SHOW COLUMNS FROM reservations LIKE 'booked_by_role'");
    if(!$c1 || $c1->num_rows===0){
        @$con->query("ALTER TABLE reservations ADD COLUMN booked_by_role ENUM('resident','guest','co_owner') NULL AFTER booking_for");
    }
    $c2 = $con->query("SHOW COLUMNS FROM reservations LIKE 'booked_by_name'");
    if(!$c2 || $c2->num_rows===0){
        @$con->query("ALTER TABLE reservations ADD COLUMN booked_by_name VARCHAR(255) NULL AFTER booked_by_role");
    }
}
ensureReservationBookerColumns($con);
// Pull pending reservation context
$continue = isset($_GET['continue']) ? $_GET['continue'] : 'reserve';
// capture ref_code from URL once, then remove from address bar
$ref_code_url = isset($_GET['ref_code']) ? trim($_GET['ref_code']) : '';
if ($ref_code_url !== '') {
    $_SESSION['dp_ref_code'] = $ref_code_url;
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = $_SERVER['SCRIPT_NAME'] ?? '/VictorianPass/downpayment.php';
    $qs = $_GET; unset($qs['ref_code']);
    $query = http_build_query($qs);
    header('Location: ' . $scheme . '://' . $host . $path . ($query ? ('?' . $query) : ''));
    exit;
}
$ref_code = isset($_SESSION['dp_ref_code']) ? $_SESSION['dp_ref_code'] : '';
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
$pending = isset($_SESSION['pending_reservation']) ? $_SESSION['pending_reservation'] : null;

if ((!is_array($pending) || empty($pending)) && $ref_code !== '' && ($con instanceof mysqli)) {
    $stmtC = $con->prepare("SELECT amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, entry_pass_id, booking_for FROM reservations WHERE ref_code = ? LIMIT 1");
    $stmtC->bind_param('s', $ref_code);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    if ($resC && ($rwC = $resC->fetch_assoc())) {
        $pending = [
            'amenity' => $rwC['amenity'] ?? '',
            'start_date' => $rwC['start_date'] ?? null,
            'end_date' => $rwC['end_date'] ?? null,
            'start_time' => $rwC['start_time'] ?? null,
            'end_time' => $rwC['end_time'] ?? null,
            'persons' => isset($rwC['persons']) ? intval($rwC['persons']) : null,
            'price' => isset($rwC['price']) ? floatval($rwC['price']) : null,
            'downpayment' => isset($rwC['downpayment']) ? floatval($rwC['downpayment']) : null,
            'entry_pass_id' => isset($rwC['entry_pass_id']) ? intval($rwC['entry_pass_id']) : null,
            'booking_for' => $rwC['booking_for'] ?? null
        ];
        $_SESSION['pending_reservation'] = $pending;
        if ($entry_pass_id <= 0 && !empty($pending['entry_pass_id'])) { $entry_pass_id = intval($pending['entry_pass_id']); }
    }
    $stmtC->close();
}

// HANDLE FORM SUBMISSION
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $ref_code = isset($_POST['ref_code']) ? trim($_POST['ref_code']) : '';
    $continue_post = isset($_POST['continue']) ? $_POST['continue'] : $continue;
    $entry_pass_id_post_form = isset($_POST['entry_pass_id']) ? intval($_POST['entry_pass_id']) : $entry_pass_id;
    if (!is_string($tokenPosted) || !hash_equals($_SESSION['csrf_token'] ?? '', $tokenPosted)) {
      $msg = 'Invalid submission.';
    } else if ($ref_code !== '') {
      $receiptPath = null;
      if(!isset($_FILES['receipt']) || !is_array($_FILES['receipt']) || ($_FILES['receipt']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
        $msg = 'Please upload your payment receipt before confirming.';
      } else {
        $allowedExt=['png','jpg','jpeg','gif','webp','bmp','pdf'];
        $origName=$_FILES['receipt']['name']??'';
        $ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
        if(!in_array($ext,$allowedExt,true)){
          $msg='Unsupported receipt file type.';
        } else if(($_FILES['receipt']['size']??0) > 10*1024*1024){
          $msg='Receipt file is too large (max 10MB).';
        } else {
          $uploadsDir=__DIR__.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'receipts'; if(!is_dir($uploadsDir)) { @mkdir($uploadsDir,0775,true); }
          $base=preg_replace('/[^a-zA-Z0-9_-]/','_', $ref_code);
          $fname=$base.'-'.date('YmdHis').'.'.$ext;
          $target=$uploadsDir.DIRECTORY_SEPARATOR.$fname;
          $relative='uploads/receipts/'.$fname;
          if(@move_uploaded_file($_FILES['receipt']['tmp_name'],$target)){ $receiptPath=$relative; } else { $msg='Unable to save receipt upload. Please try again.'; }
        }
      }
      $amenity = isset($pending['amenity']) ? $pending['amenity'] : null;
      $start   = isset($pending['start_date']) ? $pending['start_date'] : null;
      $end     = isset($pending['end_date']) ? $pending['end_date'] : null;
      $startTime = isset($pending['start_time']) ? $pending['start_time'] : null;
      $endTime   = isset($pending['end_time']) ? $pending['end_time'] : null;
      $persons = isset($pending['persons']) ? intval($pending['persons']) : null;
      $price = isset($pending['price']) ? floatval($pending['price']) : null;
      $downpayment = isset($pending['downpayment']) ? floatval($pending['downpayment']) : null;
      $entry_pass_id_post = isset($pending['entry_pass_id']) ? intval($pending['entry_pass_id']) : ($entry_pass_id_post_form ?: null);
      $booking_for = isset($pending['booking_for']) ? trim($pending['booking_for']) : '';
      if ($booking_for === '') { $booking_for = null; }
      $guest_id = isset($pending['guest_id']) ? trim($pending['guest_id']) : '';
      $guest_ref_code = isset($pending['guest_ref_code']) ? trim($pending['guest_ref_code']) : '';
      $booked_by_role = null;
      $booked_by_name = null;
      if ($booking_for === 'guest') {
        $booked_by_role = 'guest';
        try {
          if ($con instanceof mysqli) {
            if ($guest_id !== '') {
              $stmtG = $con->prepare("SELECT visitor_first_name, visitor_middle_name, visitor_last_name FROM guest_forms WHERE id = ? LIMIT 1");
              $gid = intval($guest_id);
              $stmtG->bind_param('i', $gid);
              $stmtG->execute();
              $resG = $stmtG->get_result();
              if ($resG && ($rwG = $resG->fetch_assoc())) {
                $parts = [];
                if (!empty($rwG['visitor_first_name'])) $parts[] = $rwG['visitor_first_name'];
                if (!empty($rwG['visitor_middle_name'])) $parts[] = $rwG['visitor_middle_name'];
                if (!empty($rwG['visitor_last_name'])) $parts[] = $rwG['visitor_last_name'];
                $booked_by_name = trim(implode(' ', $parts));
              }
              $stmtG->close();
            } else if ($guest_ref_code !== '') {
              $stmtG = $con->prepare("SELECT visitor_first_name, visitor_middle_name, visitor_last_name FROM guest_forms WHERE ref_code = ? LIMIT 1");
              $stmtG->bind_param('s', $guest_ref_code);
              $stmtG->execute();
              $resG = $stmtG->get_result();
              if ($resG && ($rwG = $resG->fetch_assoc())) {
                $parts = [];
                if (!empty($rwG['visitor_first_name'])) $parts[] = $rwG['visitor_first_name'];
                if (!empty($rwG['visitor_middle_name'])) $parts[] = $rwG['visitor_middle_name'];
                if (!empty($rwG['visitor_last_name'])) $parts[] = $rwG['visitor_last_name'];
                $booked_by_name = trim(implode(' ', $parts));
              }
              $stmtG->close();
            } else if (!empty($ref_code)) {
              $stmtG = $con->prepare("SELECT visitor_first_name, visitor_middle_name, visitor_last_name FROM guest_forms WHERE ref_code = ? LIMIT 1");
              $stmtG->bind_param('s', $ref_code);
              $stmtG->execute();
              $resG = $stmtG->get_result();
              if ($resG && ($rwG = $resG->fetch_assoc())) {
                $parts = [];
                if (!empty($rwG['visitor_first_name'])) $parts[] = $rwG['visitor_first_name'];
                if (!empty($rwG['visitor_middle_name'])) $parts[] = $rwG['visitor_middle_name'];
                if (!empty($rwG['visitor_last_name'])) $parts[] = $rwG['visitor_last_name'];
                $booked_by_name = trim(implode(' ', $parts));
              }
              $stmtG->close();
            }
          }
        } catch (Throwable $_) { /* ignore */ }
      } else if ($booking_for === 'co_owner') {
        $booked_by_role = 'co_owner';
      }
      $uid = ($user_id && $user_id>0) ? $user_id : null;
      if(empty($msg)){
        $acct = ($continue_post === 'reserve_resident') ? 'resident' : 'visitor';
        $hadLegacy = false;
        if($con instanceof mysqli){ $chk=$con->prepare("SELECT id FROM resident_reservations WHERE ref_code = ? LIMIT 1"); $chk->bind_param('s',$ref_code); $chk->execute(); $cr=$chk->get_result(); $hadLegacy = ($cr && $cr->num_rows>0); $chk->close(); }
        $stmt = $con->prepare("UPDATE reservations SET amenity = COALESCE(?, amenity), start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date), start_time = COALESCE(?, start_time), end_time = COALESCE(?, end_time), persons = COALESCE(?, persons), price = COALESCE(?, price), downpayment = COALESCE(?, downpayment), receipt_path = COALESCE(?, receipt_path), user_id = COALESCE(?, user_id), entry_pass_id = COALESCE(?, entry_pass_id), booking_for = COALESCE(?, booking_for), booked_by_role = COALESCE(?, booked_by_role), booked_by_name = COALESCE(?, booked_by_name), account_type = COALESCE(account_type, ?), payment_status='submitted', approval_status='pending', receipt_uploaded_at = COALESCE(receipt_uploaded_at, NOW()) WHERE ref_code = ?");
        $stmt->bind_param('sssssiddsiisssss', $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $receiptPath, $uid, $entry_pass_id_post, $booking_for, $booked_by_role, $booked_by_name, $acct, $ref_code);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
          $ins = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, receipt_path, user_id, entry_pass_id, booking_for, booked_by_role, booked_by_name, account_type, payment_status, approval_status, receipt_uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 'pending', NOW())");
          $ins->bind_param('ssssssiddsiissss', $ref_code, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $receiptPath, $uid, $entry_pass_id_post, $booking_for, $booked_by_role, $booked_by_name, $acct);
          $ins->execute();
          $ins->close();
        }
        if ($acct === 'resident') {
          try {
            $chkRR = $con->prepare("SELECT id FROM resident_reservations WHERE ref_code = ? LIMIT 1");
            $chkRR->bind_param('s', $ref_code);
            $chkRR->execute(); $resRR = $chkRR->get_result(); $existsRR = ($resRR && $resRR->num_rows>0); $chkRR->close();
            if ($existsRR) {
              $uRR = $con->prepare("UPDATE resident_reservations SET amenity = COALESCE(?, amenity), start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date), approval_status = 'pending', updated_at = NOW(), user_id = COALESCE(?, user_id) WHERE ref_code = ?");
              $uRR->bind_param('sssis', $amenity, $start, $end, $uid, $ref_code);
              $uRR->execute(); $uRR->close();
            } else {
              $iRR = $con->prepare("INSERT INTO resident_reservations (user_id, amenity, start_date, end_date, approval_status, ref_code, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NOW())");
              $iRR->bind_param('issss', $uid, $amenity, $start, $end, $ref_code);
              $iRR->execute(); $iRR->close();
            }
          } catch (Throwable $_) { }
        }
      }
      $_SESSION['pending_reservation'] = null;
      if(empty($msg)){
        $msg = 'Receipt uploaded. Payment submitted for review.';
        $_SESSION['flash_notice'] = 'Request submitted, waiting for approval';
        unset($_SESSION['flash_ref_code']);
      }
      // Resolve recipient name/email
      $full_name = '';
      $email = '';
      if ($entry_pass_id_post) {
        $stmtInfo = $con->prepare("SELECT full_name, middle_name, last_name, email FROM entry_passes WHERE id = ? LIMIT 1");
        $stmtInfo->bind_param('i', $entry_pass_id_post);
        $stmtInfo->execute();
        $stmtInfo->bind_result($fn, $mn, $ln, $em);
        if ($stmtInfo->fetch()) {
          $full_name = trim(($fn ?: '') . ' ' . ($mn ?: '') . ' ' . ($ln ?: ''));
          $email = $em ?: '';
        }
        $stmtInfo->close();
      }
      if ($email === '' && $uid) {
        $stmtU = $con->prepare("SELECT first_name, middle_name, last_name, email FROM users WHERE id = ? LIMIT 1");
        $stmtU->bind_param('i', $uid);
        $stmtU->execute();
        $stmtU->bind_result($uf, $um, $ul, $ue);
        if ($stmtU->fetch()) {
          $full_name = trim(($uf ?: '') . ' ' . ($um ?: '') . ' ' . ($ul ?: ''));
          $email = $ue ?: '';
        }
        $stmtU->close();
      }
      if ($email === '' && $user_email_prefill !== '') { $email = $user_email_prefill; }
      if ($full_name === '') { $full_name = 'Guest'; }

    }
    if (($continue_post ?? $continue) === 'reserve_resident') {
      header('Location: profileresident.php');
    } else {
      header('Location: dashboardvisitor.php');
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Downpayment - GCash</title>
    <link rel="icon" type="image/png" href="images/logo.svg">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *{font-family:'Poppins',sans-serif}
    body{margin:0;background:#fafbfc;color:#111827;padding-top:76px}
    .wrap{max-width:720px;margin:60px auto;padding:0 16px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 4px 16px rgba(15,23,42,0.08)}
    .title{font-weight:700;font-size:1.5rem;margin:0 0 6px;color:#111827}
    .meta{color:#4b5563;font-size:.95rem;margin-bottom:8px}
    .qr{display:flex;justify-content:center;margin:18px 0}
    .btn{background:#23412e;color:#fff;border:none;padding:12px 20px;border-radius:8px;cursor:pointer;font-weight:600;transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease;font-size:.95rem}
    .btn:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(35,65,46,0.4);opacity:.95}
    .btn[disabled]{opacity:.6;cursor:not-allowed;box-shadow:none;transform:none}
    .btn-outline{background:#fff;color:#23412e;border:1px solid #d1d5db}
    .code{background:#f3f4f6;border-radius:10px;padding:8px 12px;display:inline-block;margin-top:8px;color:#111827;font-weight:600}
    .break{margin-top:16px;padding-top:12px;border-top:1px solid #e5e7eb}
    .row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:.9rem}
    .row:last-child{border-bottom:none}
    .row .label{color:#6b7280;font-weight:500}
    .row .amount{font-weight:600;color:#111827}
    .pay-callout{display:flex;justify-content:center;align-items:center;background:#f0faf2;border:1.5px solid #cfe6d4;color:#23412e;border-radius:12px;padding:12px 14px;margin:14px 0;font-weight:700;font-size:.95rem}
    .pay-callout .num{font-size:1.4rem;margin-left:8px}
    .toast{position:fixed;top:14px;left:50%;transform:translateX(-50%);background:#23412e;color:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 8px 18px rgba(0,0,0,.12);font-size:.9rem;z-index:1000}
    .upload-area{border:1.5px dashed #d1d5db;background:#f9fafb;padding:18px;border-radius:12px;margin-top:16px;display:flex;flex-direction:column;gap:10px}
    .upload-area .label{color:#111827;font-weight:600;font-size:.95rem}
    #receiptInput{padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;color:#111827;font-size:.95rem;font-family:'Poppins',sans-serif}
    #receiptInput:focus{border-color:#23412e;box-shadow:0 0 0 3px rgba(35,65,46,0.1);outline:none}
    #confirmBtn{padding:12px 20px;font-size:1rem;margin-top:4px;align-self:flex-end}
    .upload-preview{display:flex;flex-direction:column;gap:8px;align-items:flex-start;justify-content:flex-start;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px}
    .upload-preview img{max-width:100%;height:auto;border-radius:8px}
    .upload-preview .file-name{color:#111827;font-weight:600;font-size:.9rem}
    .nonrefundable{background:#fee2e2;color:#b30000;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;font-weight:700;margin-top:10px;display:block;font-size:.9rem;border-left:4px solid #dc2626}
    .navbar{display:flex;justify-content:space-between;align-items:center;padding:14px 6%;background:rgba(43,38,35,0.95);backdrop-filter:blur(10px);position:fixed;top:0;left:0;right:0;z-index:1000;border-bottom:1px solid rgba(255,255,255,0.1);box-shadow:0 4px 12px rgba(0,0,0,0.1)}
    .logo{display:flex;align-items:center;gap:12px}
    .logo img{width:42px;height:42px}
    .brand-text h1{margin:0;font-size:1.3rem;font-weight:700;color:#f4f4f4}
    .brand-text p{margin:0;font-size:.85rem;color:#aaa}
    @media (max-width:640px){
      .wrap{margin:40px auto}
      .card{padding:18px}
      .pay-callout{flex-direction:column;align-items:flex-start}
      .pay-callout .num{margin-left:0;margin-top:4px}
    }
  </style>
  </head>
<body>
  <?php
    // compute pricing breakdown
    $amenity = isset($pending['amenity']) ? $pending['amenity'] : '';
    $price   = isset($pending['price']) ? floatval($pending['price']) : 0.0;
    $downpayment = isset($pending['downpayment']) ? floatval($pending['downpayment']) : null;
    $isHourBased = in_array($amenity, ['Basketball Court','Tennis Court','Clubhouse'], true);
    $isPersonBased = in_array($amenity, ['Pool'], true);
    if ($downpayment === null || $downpayment <= 0) { $downpayment = round($price * 0.5, 2); }
    $remaining = max(0, round($price - $downpayment, 2));
    $refDisplay = 'N/A';
    $qrUrl = 'images/downpayment.jpg';
    if ($ref_code === '' && $continue !== 'reserve_resident') { $ref_code = 'VP-' . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT); }
  ?>
  <header class="navbar">
    <div class="logo">
      <a href="mainpage.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
      <div class="brand-text">
        <h1>VictorianPass</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>
  </header>
  <div class="wrap">
    <div class="card">
      <h2 class="title">Downpayment</h2>
      <p class="meta">Use the GCash details shown to pay your partial payment. Upload the receipt and click Confirm.</p>
      <div class="qr"><img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="GCash Downpayment" style="max-width:280px;border-radius:8px;border:1px solid rgba(255,255,255,.2)" onerror="this.style.display='none'"></div>
      <div class="pay-callout">You will pay now:<span class="num">₱<?php echo number_format($downpayment, 2); ?></span></div>
      <p class="nonrefundable">Downpayment is non-refundable.</p>
      <div class="break">
        <div class="row"><span class="label">Amenity</span><span class="amount"><?php echo htmlspecialchars($amenity ?: 'N/A'); ?></span></div>
        <?php
          $hours = 1;
          if (isset($pending['hours'])) {
            $hours = max(1, intval($pending['hours']));
          } else {
            $sd = $pending['start_date'] ?? null; $ed = $pending['end_date'] ?? null; $st = $pending['start_time'] ?? null; $et = $pending['end_time'] ?? null;
            if ($sd && $ed && $sd === $ed && $st && $et) {
              $sh = intval(substr($st,0,2)); $eh = intval(substr($et,0,2));
              $sm = intval(substr($st,3,2)); $em = intval(substr($et,3,2));
              $hours = max(1, ($eh*60+$em-($sh*60+$sm))/60);
            }
          }
          $persons = isset($pending['persons']) ? intval($pending['persons']) : 1;
        ?>
        <div class="row"><span class="label">Hours</span><span class="amount"><?php echo intval($hours); ?></span></div>
        <div class="row"><span class="label">Persons</span><span class="amount"><?php echo intval($persons); ?></span></div>
        <div class="row"><span class="label">Total Price</span><span class="amount">₱<?php echo number_format($price, 2); ?></span></div>
        <div class="row"><span class="label">Online Payment (Partial)</span><span class="amount">₱<?php echo number_format($downpayment, 2); ?></span></div>
        <div class="row"><span class="label">Onsite Payment (Remaining)</span><span class="amount">₱<?php echo number_format($remaining, 2); ?></span></div>
      </div>
      <form method="POST" enctype="multipart/form-data" style="margin-top:12px; display:flex; flex-direction:column; gap:10px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="ref_code" value="<?php echo htmlspecialchars($ref_code); ?>">
        <input type="hidden" name="continue" value="<?php echo htmlspecialchars($continue); ?>">
        <input type="hidden" name="entry_pass_id" value="<?php echo intval($entry_pass_id); ?>">
        <div class="upload-area">
          <label for="receiptInput" class="label">Upload Payment Receipt (image or PDF)</label>
          <input type="file" name="receipt" id="receiptInput" accept="image/*,.pdf" required>
          <div class="upload-preview" id="uploadPreview" style="display:none"></div>
          <button type="button" class="btn btn-outline" id="removeFileBtn" disabled>Remove Selected File</button>
        </div>
        <button type="submit" class="btn" id="confirmBtn" disabled>Confirm Payment</button>
      </form>
    </div>
  </div>
  <script>
    (function(){
      const input=document.getElementById('receiptInput');
      const btn=document.getElementById('confirmBtn');
      const preview=document.getElementById('uploadPreview');
      const removeBtn=document.getElementById('removeFileBtn');
      function renderPreview(file){
        if(!file){ preview.style.display='none'; preview.innerHTML=''; return; }
        const name=document.createElement('div');
        name.className='file-name';
        name.textContent=file.name;
        preview.innerHTML='';
        preview.appendChild(name);
        const type=(file.type||'').toLowerCase();
        if(type.startsWith('image/')){
          const img=document.createElement('img');
          const reader=new FileReader();
          reader.onload=function(e){ img.src=e.target.result; };
          reader.readAsDataURL(file);
          preview.appendChild(img);
        } else {
          const note=document.createElement('div');
          note.style.color='#cfe9d3';
          note.textContent='Selected file ready to upload.';
          preview.appendChild(note);
        }
        preview.style.display='flex';
      }
      function update(){
        const hasFile=!!(input && input.files && input.files.length>0);
        btn.disabled=!hasFile;
        removeBtn.disabled=!hasFile;
        renderPreview(hasFile?input.files[0]:null);
      }
      if(input){ input.addEventListener('change', update); }
      if(removeBtn){ removeBtn.addEventListener('click', function(){ input.value=''; update(); }); }
      update();
    })();
  </script>
</body>
</html>