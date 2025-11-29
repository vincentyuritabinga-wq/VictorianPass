<?php
session_start();
include 'connect.php';

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
function vp_status_link($code){ $scheme=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http'; $host=$_SERVER['HTTP_HOST']??'localhost'; $basePath=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/VictorianPass'),'/'); return $scheme.'://'.$host.$basePath.'/status_view.php?code='.urlencode($code); }
function ensureReservationReceiptColumn($con){ if(!($con instanceof mysqli)) return; $c=$con->query("SHOW COLUMNS FROM reservations LIKE 'receipt_path'"); if(!$c || $c->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN receipt_path VARCHAR(255) NULL AFTER downpayment"); } }
ensureReservationReceiptColumn($con);

$continue = isset($_GET['continue']) ? $_GET['continue'] : 'reserve';
$entry_pass_id = isset($_GET['entry_pass_id']) ? intval($_GET['entry_pass_id']) : 0;
$ref_code = isset($_GET['ref_code']) ? trim($_GET['ref_code']) : '';
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
$pending = isset($_SESSION['pending_reservation']) ? $_SESSION['pending_reservation'] : null;

// Compute breakdown amounts using amenity-specific pricing basis
$amenity = isset($pending['amenity']) ? $pending['amenity'] : '';
$price   = isset($pending['price']) ? floatval($pending['price']) : 0.0;
$downpayment = isset($pending['downpayment']) ? floatval($pending['downpayment']) : null;
$isHourBased = in_array($amenity, ['Basketball Court','Tennis Court','Clubhouse'], true);
$isPersonBased = in_array($amenity, ['Pool'], true);
// Fallback partial if not provided: 50% of total
if ($downpayment === null || $downpayment <= 0) { $downpayment = round($price * 0.5, 2); }
$remaining = max(0, round($price - $downpayment, 2));
// Generate a dynamic QR code payload for downpayment (no hard-coded image)
$refDisplay = $ref_code !== '' ? $ref_code : 'N/A';
$qrData = 'VictorianPass Downpayment | Ref=' . $refDisplay . ' | Amount=' . number_format($downpayment, 2) . ' PHP';
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . urlencode($qrData);
$qrData = 'VictorianPass Downpayment | Ref: ' . ($ref_code ?: 'N/A') . ' | Amount: ' . number_format($downpayment,2) . ' PHP';
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . urlencode($qrData);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $uid = ($user_id && $user_id>0) ? $user_id : null;
    if(empty($msg)){
      $stmt = $con->prepare("UPDATE reservations SET amenity = COALESCE(?, amenity), start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date), start_time = COALESCE(?, start_time), end_time = COALESCE(?, end_time), persons = COALESCE(?, persons), price = COALESCE(?, price), downpayment = COALESCE(?, downpayment), receipt_path = COALESCE(?, receipt_path), user_id = COALESCE(?, user_id), entry_pass_id = COALESCE(?, entry_pass_id), payment_status='submitted', approval_status='pending' WHERE ref_code = ?");
      $stmt->bind_param('sssssiddsiis', $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $receiptPath, $uid, $entry_pass_id_post, $ref_code);
      $stmt->execute();
      $affected = $stmt->affected_rows;
      $stmt->close();
      if ($affected === 0) {
        $ins = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, receipt_path, user_id, entry_pass_id, payment_status, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 'pending')");
        $ins->bind_param('ssssssiddsii', $ref_code, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $receiptPath, $uid, $entry_pass_id_post);
        $ins->execute();
        $ins->close();
      }
    }
    $_SESSION['pending_reservation'] = null;
    if(empty($msg)){
      $msg = 'Receipt uploaded. Payment submitted for review.';
      $_SESSION['flash_notice'] = 'Please wait for confirmation. The status code will be sent to your email within 12 hours.';
    }
    $_SESSION['flash_ref_code'] = $ref_code;
    header('Location: mainpage.php');
    exit;
  }
}

if ($ref_code === '') {
  // Generate a code for display, but avoid inserting incomplete reservations
  $ref_code = 'VP-' . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
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
    body{margin:0;font-family:'Poppins',sans-serif;background:#111;color:#fff}
    .wrap{max-width:680px;margin:40px auto;padding:20px}
    .card{background:#1b1816;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:18px}
    .title{font-weight:700;margin:0 0 8px}
    .meta{color:#bbb}
    .qr{display:flex;justify-content:center;margin:14px 0}
    .btn{background:#23412e;color:#fff;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-weight:600;transition:opacity .2s ease,transform .15s ease}
    .btn:hover{transform:translateY(-1px)}
    .btn[disabled]{opacity:.6;cursor:not-allowed}
    .btn-outline{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.3)}
    .code{background:#2b2623;border-radius:10px;padding:8px 12px;display:inline-block;margin-top:8px}
    .break{margin-top:12px;padding:12px;border-top:1px solid rgba(255,255,255,.08)}
    .row{display:flex;justify-content:space-between;align-items:center;margin:6px 0}
    .row .label{color:#ddd;font-weight:600}
    .row .amount{font-weight:700}
    .pay-callout{display:flex;justify-content:center;align-items:center;background:#213825;border:1px solid rgba(255,255,255,.12);color:#e7fff1;border-radius:10px;padding:12px;margin:10px 0;font-weight:700}
    .pay-callout .num{font-size:1.6rem;margin-left:8px}
    .toast{position:fixed;top:14px;left:50%;transform:translateX(-50%);background:#23412e;color:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 8px 18px rgba(0,0,0,.12);font-size:.9rem;z-index:1000}
  </style>
  </head>
<body>
  
  <div class="wrap">
    <div class="card">
      <h2 class="title">Downpayment</h2>
      <p class="meta">Scan the QR code with GCash to pay your partial payment. Upload the receipt and click Confirm.</p>
      <?php if (!empty($msg)) { echo '<p class="meta">' . htmlspecialchars($msg) . '</p>'; } ?>
      <p>Your Status Code: <span class="code"><?php echo htmlspecialchars($ref_code); ?></span></p>
      <div class="qr"><img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="Payment QR Code" style="max-width:280px;border-radius:8px;border:1px solid rgba(255,255,255,.2)" onerror="this.style.display='none'"></div>
      <div class="pay-callout">You will pay now:<span class="num">₱<?php echo number_format($downpayment, 2); ?></span></div>
      <div class="break">
        <div class="row"><span class="label">Amenity</span><span class="amount"><?php echo htmlspecialchars($amenity ?: 'N/A'); ?></span></div>
        <?php
          // Calculate hours
          $hours = 1;
          if ($isHourBased) {
            if (isset($pending['hours'])) {
              $hours = intval($pending['hours']);
            } else {
              $sd = $pending['start_date'] ?? null; $ed = $pending['end_date'] ?? null; $st = $pending['start_time'] ?? null; $et = $pending['end_time'] ?? null;
              if ($sd && $ed && $sd === $ed && $st && $et) {
                $sh = intval(substr($st,0,2)); $eh = intval(substr($et,0,2));
                $sm = intval(substr($st,3,2)); $em = intval(substr($et,3,2));
                $hours = max(1, ($eh*60+$em-($sh*60+$sm))/60);
              }
            }
          }
          $persons = isset($pending['persons']) ? intval($pending['persons']) : 1;
        ?>
        <div class="row"><span class="label">Hours</span><span class="amount"><?php echo $isHourBased ? intval($hours) : '—'; ?></span></div>
        <div class="row"><span class="label">Persons</span><span class="amount"><?php echo $isPersonBased ? intval($persons) : '—'; ?></span></div>
        <div class="row"><span class="label">Online Payment (Partial)</span><span class="amount">₱<?php echo number_format($downpayment, 2); ?></span></div>
        <div class="row"><span class="label">Onsite Payment (Remaining)</span><span class="amount">₱<?php echo number_format($remaining, 2); ?></span></div>
      </div>
      <form method="POST" enctype="multipart/form-data" style="margin-top:12px; display:flex; flex-direction:column; gap:10px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="ref_code" value="<?php echo htmlspecialchars($ref_code); ?>">
        <input type="hidden" name="continue" value="<?php echo htmlspecialchars($continue); ?>">
        <input type="hidden" name="entry_pass_id" value="<?php echo intval($entry_pass_id); ?>">
        <label for="receiptInput" class="label">Upload Payment Receipt (image or PDF)</label>
        <input type="file" name="receipt" id="receiptInput" accept="image/*,.pdf" required>
        <button type="submit" class="btn" id="confirmBtn" disabled>Confirm Payment</button>
      </form>
    </div>
  </div>
  <script>
    (function(){
      const input=document.getElementById('receiptInput');
      const btn=document.getElementById('confirmBtn');
      function update(){ btn.disabled = !(input && input.files && input.files.length>0); }
      if(input){ input.addEventListener('change', update); }
      update();
    })();
  </script>
</body>
</html>
