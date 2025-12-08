<?php
session_start();
include 'connect.php';

// PHPMailer imports
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$p1 = __DIR__ . '/PHPMailer-master/src/Exception.php';
$p2 = __DIR__ . '/PHPMailer-master/PHPMailer-master/src/Exception.php';
require file_exists($p1) ? $p1 : $p2;
$p1 = __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
$p2 = __DIR__ . '/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
require file_exists($p1) ? $p1 : $p2;
$p1 = __DIR__ . '/PHPMailer-master/src/SMTP.php';
$p2 = __DIR__ . '/PHPMailer-master/PHPMailer-master/src/SMTP.php';
require file_exists($p1) ? $p1 : $p2;

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
function vp_status_link($code){ $scheme=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http'; $host=$_SERVER['HTTP_HOST']??'localhost'; $basePath=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/VictorianPass'),'/'); return $scheme.'://'.$host.$basePath.'/status_view.php?code='.urlencode($code); }
function ensureReservationsCommonColumns($con){ if(!($con instanceof mysqli)) return; $cols=['downpayment','receipt_path','payment_status','account_type']; foreach($cols as $col){ $c=$con->query("SHOW COLUMNS FROM reservations LIKE '".$con->real_escape_string($col)."'"); if(!$c || $c->num_rows===0){ if($col==='downpayment'){ @$con->query("ALTER TABLE reservations ADD COLUMN downpayment DECIMAL(10,2) NULL"); } else if($col==='receipt_path'){ @$con->query("ALTER TABLE reservations ADD COLUMN receipt_path VARCHAR(255) NULL"); } else if($col==='payment_status'){ @$con->query("ALTER TABLE reservations ADD COLUMN payment_status ENUM('pending','submitted','verified') NULL"); } else if($col==='account_type'){ @$con->query("ALTER TABLE reservations ADD COLUMN account_type ENUM('visitor','resident') NULL"); } } } }
ensureReservationsCommonColumns($con);

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
    $stmtC = $con->prepare("SELECT amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, entry_pass_id FROM reservations WHERE ref_code = ? LIMIT 1");
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
            'entry_pass_id' => isset($rwC['entry_pass_id']) ? intval($rwC['entry_pass_id']) : null
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
      $uid = ($user_id && $user_id>0) ? $user_id : null;
      if(empty($msg)){
        $acct = ($continue_post === 'reserve_resident') ? 'resident' : 'visitor';
        $hadLegacy = false;
        if($con instanceof mysqli){ $chk=$con->prepare("SELECT id FROM resident_reservations WHERE ref_code = ? LIMIT 1"); $chk->bind_param('s',$ref_code); $chk->execute(); $cr=$chk->get_result(); $hadLegacy = ($cr && $cr->num_rows>0); $chk->close(); }
        $stmt = $con->prepare("UPDATE reservations SET amenity = COALESCE(?, amenity), start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date), start_time = COALESCE(?, start_time), end_time = COALESCE(?, end_time), persons = COALESCE(?, persons), price = COALESCE(?, price), downpayment = COALESCE(?, downpayment), receipt_path = COALESCE(?, receipt_path), user_id = COALESCE(?, user_id), entry_pass_id = COALESCE(?, entry_pass_id), account_type = COALESCE(account_type, ?), payment_status='submitted', approval_status='pending' WHERE ref_code = ?");
        $stmt->bind_param('sssssiddsiiss', $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $receiptPath, $uid, $entry_pass_id_post, $acct, $ref_code);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
          $ins = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, receipt_path, user_id, entry_pass_id, account_type, payment_status, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 'pending')");
          $ins->bind_param('ssssssiddsiiis', $ref_code, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $receiptPath, $uid, $entry_pass_id_post, $acct);
          $ins->execute();
          $ins->close();
        }
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
      $_SESSION['pending_reservation'] = null;
      if(empty($msg)){
        $msg = 'Receipt uploaded. Payment submitted for review.';
        if (($continue_post ?? $continue) === 'reserve_resident') {
          $_SESSION['flash_notice'] = 'Your reservation has been submitted. Please wait for confirmation.';
        } else {
          $_SESSION['flash_notice'] = 'Please wait for confirmation. The status code will be sent to your email within 12 hours.';
          $_SESSION['flash_ref_code'] = $ref_code;
        }
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

      if (($continue_post ?? $continue) !== 'reserve_resident') {
        $mail = new PHPMailer(true);
        try {
          $mail->isSMTP();
          $mail->Host = 'smtp.gmail.com';
          $mail->SMTPAuth = true;
          $mail->Username = 'victorianpass@gmail.com';
          $mail->Password = 'vqlsqbrnikcjesia';
          $mail->SMTPSecure = 'tls';
          $mail->Port = 587;
          $mail->setFrom('victorianpass@gmail.com', 'VictorianPass');
          if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new Exception('Recipient email missing'); }
          $mail->addAddress($email);
          $mail->isHTML(true);
          $statusLink = vp_status_link($ref_code);
          $mail->Subject = 'Your VictorianPass Code';
          $mail->Body    = "<h2>Your VictorianPass Code</h2>
<p><strong>Status Code:</strong> <span style=\"font-family:monospace;background:#f0f0f0;padding:6px 10px;border-radius:6px\">$ref_code</span></p>
<p><strong>Name:</strong> $full_name</p>
<p><strong>Email:</strong> $email</p>
<p>We have successfully received your payment. Please wait while we verify and confirm your request.</p>
<p>To check the status of your reservation, use the status code <strong>$ref_code</strong>. Simply return to the main page and enter this code in the Check Status section to view your current request status.</p>
<p>We appreciate you reaching out. You’ll receive an update within 24 hours.</p>
<p>Thank you for trusting VictorianPass (Victorian Heights Subdivision).</p>";
          $mail->AltBody = "Your VictorianPass Code\nStatus Code: $ref_code\nName: $full_name\nEmail: $email\n\nWe have successfully received your payment. Please wait while we verify and confirm your request.\n\nTo check the status of your reservation, use the status code $ref_code. Return to the main page and enter this code in the Check Status section to view your current request status.\n\nYou’ll receive an update within 24 hours.\n\nThank you for trusting VictorianPass (Victorian Heights Subdivision).";
          $mail->send();
        } catch (Exception $e) {
          $error_msg = "Payment recorded, but email could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
      }
    }
    if (($continue_post ?? $continue) === 'reserve_resident') {
      header('Location: profileresident.php');
    } else {
      header('Location: mainpage.php');
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
    .upload-area{border:2px dashed #9bd08f;background:#1f2b20;padding:20px;border-radius:12px;margin-top:14px;display:flex;flex-direction:column;gap:12px}
    .upload-area .label{color:#e7fff1;font-weight:700;font-size:1.1rem}
    #receiptInput{padding:14px;border:2px solid #9bd08f;border-radius:10px;background:#fff;color:#222;font-size:1rem}
    #confirmBtn{padding:12px 18px;font-size:1rem}
    .upload-preview{display:flex;flex-direction:column;gap:10px;align-items:center;justify-content:center;background:#162216;border:1px solid #325a37;border-radius:10px;padding:12px}
    .upload-preview img{max-width:100%;height:auto;border-radius:8px}
    .upload-preview .file-name{color:#e7fff1;font-weight:600}
    .nonrefundable{background:#ffe6e6;color:#b30000;border:1px solid #e5a3a3;border-radius:10px;padding:8px 10px;font-weight:700;margin-top:8px}
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
