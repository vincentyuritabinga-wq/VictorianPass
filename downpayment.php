<?php
session_start();
include 'connect.php';

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
function vp_status_link($code){ $scheme=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http'; $host=$_SERVER['HTTP_HOST']??'localhost'; $basePath=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/VictorianPass'),'/'); return $scheme.'://'.$host.$basePath.'/status_view.php?code='.urlencode($code); }
function normalizePhonePh($phone){ $p=trim($phone??''); if(preg_match('/^\+63(9\d{9})$/',$p)){ return '0'.substr($p,3); } return $p; }
function sendEmailConfirmation($to,$subject,$body){ if(!$to) return false; $headers="MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: VictorianPass <noreply@victorianpass.local>\r\n"; return @mail($to,$subject,$body,$headers); }
function sendSmsWebhook($phone,$message){ $url=getenv('SMS_WEBHOOK_URL')?:''; $ph=normalizePhonePh($phone); if(!$url||!$ph) return false; $payload=json_encode(['phone'=>$ph,'message'=>$message]); $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$payload,'timeout'=>5]]); $resp=@file_get_contents($url,false,$ctx); return $resp!==false; }

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
  $ref_code = isset($_POST['ref_code']) ? trim($_POST['ref_code']) : '';
  $continue_post = isset($_POST['continue']) ? $_POST['continue'] : $continue;
  $entry_pass_id_post_form = isset($_POST['entry_pass_id']) ? intval($_POST['entry_pass_id']) : $entry_pass_id;
  if (!is_string($tokenPosted) || !hash_equals($_SESSION['csrf_token'] ?? '', $tokenPosted)) {
    $msg = 'Invalid submission.';
  } else if ($ref_code !== '') {
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

    $stmt = $con->prepare("UPDATE reservations SET amenity = COALESCE(?, amenity), start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date), start_time = COALESCE(?, start_time), end_time = COALESCE(?, end_time), persons = COALESCE(?, persons), price = COALESCE(?, price), downpayment = COALESCE(?, downpayment), user_id = COALESCE(?, user_id), entry_pass_id = COALESCE(?, entry_pass_id), payment_status='verified', approval_status='pending' WHERE ref_code = ?");
    $stmt->bind_param('sssssiddiis', $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $uid, $entry_pass_id_post, $ref_code);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) {
      $ins = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, user_id, entry_pass_id, payment_status, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'verified', 'pending')");
      $ins->bind_param('ssssssiddii', $ref_code, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $uid, $entry_pass_id_post);
      $ins->execute();
      $ins->close();
    }
    $toEmail=''; $toPhone=''; $amenityDet=$amenity; $sdDet=$start; $edDet=$end; $stDet=$startTime; $etDet=$endTime; $priceDet=$price;
    $ds=$con->prepare("SELECT r.amenity,r.start_date,r.end_date,r.start_time,r.end_time,r.price,r.ref_code,e.full_name,e.email AS ep_email,e.contact AS ep_phone,u.email AS user_email,u.phone AS user_phone FROM reservations r LEFT JOIN entry_passes e ON r.entry_pass_id=e.id LEFT JOIN users u ON r.user_id=u.id WHERE r.ref_code=? LIMIT 1");
    $ds->bind_param('s',$ref_code);
    $ds->execute();
    $dr=$ds->get_result();
    if($dr && ($row=$dr->fetch_assoc())){
      $amenityDet=$row['amenity']?:$amenityDet;
      $sdDet=$row['start_date']?:$sdDet;
      $edDet=$row['end_date']?:$edDet;
      $stDet=$row['start_time']?:$stDet;
      $etDet=$row['end_time']?:$etDet;
      $priceDet=isset($row['price'])?floatval($row['price']):$priceDet;
      $toEmail=($row['ep_email']?:'')?:($row['user_email']?:'');
      $toPhone=($row['ep_phone']?:'')?:($row['user_phone']?:'');
    }
    $ds->close();
    $link=vp_status_link($ref_code);
    $subject='Reservation Confirmed: '.$ref_code;
    $timeStr=($stDet?$stDet:'').(($etDet&&$stDet)?' - '.$etDet:'');
    $body="Your reservation has been recorded.\nRef Code: ".$ref_code."\nAmenity: ".($amenityDet?:'-')."\nDates: ".($sdDet?:'-')." to ".($edDet?:'-')."\nTime: ".($timeStr?:'-')."\nTotal Price: ₱".number_format(($priceDet?:0),2)."\nTrack status: ".$link;
    sendEmailConfirmation($toEmail,$subject,$body);
    sendSmsWebhook($toPhone,'Reservation '.$ref_code.' confirmed. Track: '.$link);
    $_SESSION['pending_reservation'] = null;
    $msg = 'Payment confirmed.';
    // Redirect to main page with a small notification
    $_SESSION['flash_notice'] = 'Please wait for your status code SMS.';
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
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{margin:0;font-family:'Poppins',sans-serif;background:#111;color:#fff}
    .wrap{max-width:680px;margin:40px auto;padding:20px}
    .card{background:#1b1816;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:18px}
    .title{font-weight:700;margin:0 0 8px}
    .meta{color:#bbb}
    .qr{display:flex;justify-content:center;margin:14px 0}
    .btn{background:#23412e;color:#fff;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;font-weight:600}
    .btn-outline{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.3)}
    .code{background:#2b2623;border-radius:10px;padding:8px 12px;display:inline-block;margin-top:8px}
    .break{margin-top:12px;padding:12px;border-top:1px solid rgba(255,255,255,.08)}
    .row{display:flex;justify-content:space-between;align-items:center;margin:6px 0}
    .row .label{color:#ddd;font-weight:600}
    .row .amount{font-weight:700}
    .toast{position:fixed;top:14px;left:50%;transform:translateX(-50%);background:#23412e;color:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 8px 18px rgba(0,0,0,.12);font-size:.9rem;z-index:1000}
  </style>
  </head>
<body>
  <?php if (!empty($ref_code)) { ?>
    <div class="toast">Reservation captured. Code <?php echo htmlspecialchars($ref_code); ?></div>
  <?php } ?>
  <div class="wrap">
    <div class="card">
      <h2 class="title">Downpayment</h2>
      <p class="meta">This is a sample payment screen. Click confirm to simulate payment.</p>
      <?php if (!empty($msg)) { echo '<p class="meta">' . htmlspecialchars($msg) . '</p>'; } ?>
      <p>Your Status Code: <span class="code"><?php echo htmlspecialchars($ref_code); ?></span></p>
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
      <form method="POST" style="margin-top:12px; display:flex; gap:8px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="ref_code" value="<?php echo htmlspecialchars($ref_code); ?>">
        <input type="hidden" name="continue" value="<?php echo htmlspecialchars($continue); ?>">
        <input type="hidden" name="entry_pass_id" value="<?php echo intval($entry_pass_id); ?>">
        <?php $backUrl = 'reserve.php' . ($entry_pass_id ? ('?entry_pass_id=' . urlencode($entry_pass_id)) : ''); ?>
        <button type="submit" class="btn">Confirm Payment</button>
      </form>
    </div>
  </div>
</body>
</html>
