<?php
session_start();
include 'connect.php';

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$continue = isset($_GET['continue']) ? $_GET['continue'] : 'reserve';
$entry_pass_id = isset($_GET['entry_pass_id']) ? intval($_GET['entry_pass_id']) : 0;
$ref_code = isset($_GET['ref_code']) ? trim($_GET['ref_code']) : '';
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
$pending = isset($_SESSION['pending_reservation']) ? $_SESSION['pending_reservation'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
  $ref_code = isset($_POST['ref_code']) ? trim($_POST['ref_code']) : '';
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
    $entry_pass_id_post = isset($pending['entry_pass_id']) ? intval($pending['entry_pass_id']) : ($entry_pass_id ?: null);
    $uid = ($user_id && $user_id>0) ? $user_id : null;

    $stmt = $con->prepare("UPDATE reservations SET amenity = COALESCE(?, amenity), start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date), start_time = COALESCE(?, start_time), end_time = COALESCE(?, end_time), persons = COALESCE(?, persons), price = COALESCE(?, price), downpayment = COALESCE(?, downpayment), user_id = COALESCE(?, user_id), entry_pass_id = COALESCE(?, entry_pass_id), payment_status='verified' WHERE ref_code = ?");
    $stmt->bind_param('sssssiddiis', $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $uid, $entry_pass_id_post, $ref_code);
    $stmt->execute();
    $stmt->close();
    $_SESSION['pending_reservation'] = null;
    $msg = 'Payment confirmed.';
  }
}

if ($ref_code === '') {
  $ref_code = 'VP-' . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
  $stmt = $con->prepare("INSERT INTO reservations (ref_code, entry_pass_id, user_id, payment_status) VALUES (?, ?, ?, 'pending') ");
  $ep = $entry_pass_id > 0 ? $entry_pass_id : null;
  $uid = ($user_id && $user_id>0) ? $user_id : null;
  $stmt->bind_param('sii', $ref_code, $ep, $uid);
  $stmt->execute();
  $stmt->close();
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
    .code{background:#2b2623;border-radius:10px;padding:8px 12px;display:inline-block;margin-top:8px}
  </style>
  </head>
<body>
  <div class="wrap">
    <div class="card">
      <h2 class="title">Downpayment</h2>
      <p class="meta">This is a sample payment screen. Click confirm to simulate payment.</p>
      <?php if (!empty($msg)) { echo '<p class="meta">' . htmlspecialchars($msg) . '</p>'; } ?>
      <p>Your Status Code: <span class="code"><?php echo htmlspecialchars($ref_code); ?></span></p>
      <form method="POST" style="margin-top:12px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="ref_code" value="<?php echo htmlspecialchars($ref_code); ?>">
        <button type="submit" class="btn">Confirm Payment</button>
      </form>
    </div>
  </div>
</body>
</html>
