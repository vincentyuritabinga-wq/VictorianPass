<?php
include 'connect.php';

$code = isset($_GET['code']) ? trim($_GET['code']) : '';
if ($code === '') { $error = 'Status code is required.'; }

$data = null;
if (empty($error)) {
  $today = date('Y-m-d');
  $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/\\');
  $verificationLink = sprintf('%s://%s%s/qr_view.php?code=%s', $scheme, $host, $basePath, urlencode($code));

  $stmtGF = $con->prepare("SELECT gf.*, u.house_number AS res_house_number, u.first_name AS res_first_name, u.last_name AS res_last_name, u.email AS res_email, u.phone AS res_phone FROM guest_forms gf LEFT JOIN users u ON gf.resident_user_id = u.id WHERE gf.ref_code = ?");
  $stmtGF->bind_param('s', $code);
  $stmtGF->execute();
  $resGF = $stmtGF->get_result();
  $stmtGF->close();
    if ($resGF && $resGF->num_rows > 0) {
    $row = $resGF->fetch_assoc();
    $statusVal = ($row['approval_status'] ?? 'pending');
    $approvalDateYmd = !empty($row['approval_date']) ? date('Y-m-d', strtotime($row['approval_date'])) : null;
    $expireAfterApprovalYmd = $approvalDateYmd ? date('Y-m-d', strtotime($approvalDateYmd . ' +1 day')) : null;
    if ($statusVal === 'approved' && $expireAfterApprovalYmd && $today > $expireAfterApprovalYmd) { $statusVal = 'expired'; }

    $fullName = trim(($row['visitor_first_name'] ?? '') . ' ' . ($row['visitor_last_name'] ?? ''));
    if ($fullName === '') { $fullName = 'Guest'; }
    $email = $row['visitor_email'] ?? '';
    $phone = $row['visitor_contact'] ?? '';
    if (preg_match('/^\+63(9\d{9})$/', $phone)) { $phone = '0' . substr($phone, 3); }
    $address = $row['resident_house'] ?? (($row['res_house_number'] ?? '') ? ('Block ' . $row['res_house_number']) : '');
    $sex = $row['visitor_sex'] ?? '';
    $birthRaw = $row['visitor_birthdate'] ?? null;
    $birthdate = $birthRaw ? date('m/d/y', strtotime($birthRaw)) : '';
    $hasAmenityDates = (!empty($row['start_date']) && !empty($row['end_date']));
    if ($hasAmenityDates) {
      $publishDate = date('m/d/y', strtotime($row['start_date']));
      $expireDate = date('m/d/y', strtotime($row['end_date']));
    } else {
      $publishDate = !empty($row['visit_date']) ? date('m/d/y', strtotime($row['visit_date'])) : '';
      $expireDate = '';
    }
    $validWindow = ($publishDate ?: '-') . ($expireDate ? (' → ' . $expireDate) : '');
    $qrPath = !empty($row['qr_path']) ? $row['qr_path'] : '';
    $qrImg = $qrPath ? $qrPath : ('https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($verificationLink));
    $hasReservation = !empty($row['amenity']);

    $data = [
      'code' => $row['ref_code'],
      'status' => $statusVal,
      'name' => $fullName,
      'sex' => $sex,
      'birthdate' => $birthdate,
      'contact' => $phone,
      'email' => $email,
      'address' => $address,
      'amenity' => $hasReservation ? ($row['amenity'] ?? '') : '',
      'publish' => $publishDate,
      'expire' => $expireDate,
      'valid_window' => $validWindow,
      'created' => !empty($row['created_at']) ? date('m/d/y', strtotime($row['created_at'])) : '',
      'qr' => $qrImg,
      'has_reservation' => $hasReservation,
      'is_visitor' => true,
      'is_resident' => false,
      'verification' => $verificationLink
    ];
  }

  if (!$data) {
    $stmt = $con->prepare("SELECT r.*, e.full_name AS ep_full_name, e.middle_name AS ep_middle_name, e.last_name AS ep_last_name, e.sex AS ep_sex, e.birthdate AS ep_birthdate, e.contact AS ep_contact, e.email AS ep_email, e.address AS ep_address, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number, u.address AS user_address, u.sex AS user_sex, u.birthdate AS user_birthdate FROM reservations r LEFT JOIN entry_passes e ON r.entry_pass_id = e.id LEFT JOIN users u ON r.user_id = u.id WHERE r.ref_code = ?");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res && $res->num_rows > 0) {
      $row = $res->fetch_assoc();
      $statusVal = 'pending';
      $approvalDateYmd = !empty($row['approval_date']) ? date('Y-m-d', strtotime($row['approval_date'])) : null;
      $expireAfterApprovalYmd = $approvalDateYmd ? date('Y-m-d', strtotime($approvalDateYmd . ' +1 day')) : null;
      if (!empty($row['approval_status'])) { $statusVal = $row['approval_status']; if ($statusVal === 'approved' && $expireAfterApprovalYmd && $today > $expireAfterApprovalYmd) { $statusVal = 'expired'; } }

      $isVisitor = !empty($row['entry_pass_id']);
      $isResident = !empty($row['user_id']);
      $hasReservation = !empty($row['amenity']);
      if ($isVisitor) {
        $displayName = trim(($row['ep_full_name'] ?? '') . ' ' . ($row['ep_last_name'] ?? ''));
        $sex = $row['ep_sex'] ?? '';
        $birthdate = $row['ep_birthdate'] ?? '';
        $contact = $row['ep_contact'] ?? '';
        $address = $row['ep_address'] ?? '';
        $email = $row['ep_email'] ?? '';
        $createdAt = $row['created_at'] ?? '';
      } else {
        $displayName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $sex = $row['user_sex'] ?? '';
        $birthdate = $row['user_birthdate'] ?? '';
        $contact = $row['phone'] ?? '';
        $address = $row['user_address'] ?? (($row['house_number'] ?? '') ? ('Block ' . $row['house_number']) : '');
        $email = $row['email'] ?? '';
        $createdAt = $row['created_at'] ?? '';
      }
      $publishDate = !empty($row['start_date']) ? date('m/d/y', strtotime($row['start_date'])) : '';
      $expireDate = !empty($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : ($expireAfterApprovalYmd ? date('m/d/y', strtotime($expireAfterApprovalYmd)) : '');
      $validWindow = ($publishDate ?: '-') . ($expireDate ? (' → ' . $expireDate) : '');
      $qrPath = !empty($row['qr_path']) ? $row['qr_path'] : '';
      $qrImg = $qrPath ? $qrPath : ('https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($verificationLink));
      $data = [
        'code' => $row['ref_code'],
        'status' => $statusVal,
        'name' => $displayName ?: 'Guest',
        'sex' => $sex,
        'birthdate' => $birthdate ? date('m/d/y', strtotime($birthdate)) : '',
        'contact' => $contact,
        'email' => $email,
        'address' => $address,
        'amenity' => $row['amenity'] ?? '',
        'publish' => $publishDate,
        'expire' => $expireDate,
        'valid_window' => $validWindow,
        'created' => $createdAt ? date('m/d/y', strtotime($createdAt)) : '',
        'qr' => $qrImg,
        'has_reservation' => $hasReservation,
        'is_visitor' => $isVisitor,
        'is_resident' => $isResident,
        'verification' => $verificationLink
      ];
    }
  }

  if (!$data) {
    $stmt2 = $con->prepare("SELECT rr.*, u.first_name, u.last_name, u.email, u.phone, u.house_number, u.sex AS user_sex, u.birthdate AS user_birthdate FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id WHERE rr.ref_code = ?");
    $stmt2->bind_param('s', $code);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $stmt2->close();
    if ($res2 && $res2->num_rows > 0) {
      $row = $res2->fetch_assoc();
      $statusVal = isset($row['approval_status']) && $row['approval_status'] !== '' ? $row['approval_status'] : 'pending';
      if ($statusVal === 'approved' && !empty($row['end_date']) && $row['end_date'] < $today) { $statusVal = 'expired'; }
      $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
      $email = $row['email'] ?? '';
      $phone = $row['phone'] ?? '';
      if (preg_match('/^\+63(9\d{9})$/', $phone)) { $phone = '0' . substr($phone, 3); }
      $address = ($row['house_number'] ?? '') ? ('Block ' . $row['house_number']) : '';
      $sex = $row['user_sex'] ?? '';
      $birthRaw = $row['user_birthdate'] ?? null;
      $birthdate = $birthRaw ? date('m/d/y', strtotime($birthRaw)) : '';
      $publishDate = !empty($row['start_date']) ? date('m/d/y', strtotime($row['start_date'])) : '';
      $expireDate = !empty($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : '';
      $validWindow = ($publishDate ?: '-') . ($expireDate ? (' → ' . $expireDate) : '');
      $qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($verificationLink);
      $data = [
        'code' => $row['ref_code'],
        'status' => $statusVal,
        'name' => $fullName !== '' ? $fullName : 'Resident',
        'sex' => $sex,
        'birthdate' => $birthdate,
        'contact' => $phone,
        'email' => $email,
        'address' => $address,
        'amenity' => $row['amenity'] ?? '',
        'publish' => $publishDate,
        'expire' => $expireDate,
        'valid_window' => $validWindow,
        'created' => !empty($row['created_at']) ? date('m/d/y', strtotime($row['created_at'])) : '',
        'qr' => $qrImg,
        'has_reservation' => true,
        'is_visitor' => false,
        'is_resident' => true,
        'verification' => $verificationLink
      ];
    }
  }

  if (!$data) { $error = 'Invalid status code.'; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>QR Pass - VictorianPass</title>
  <link rel="icon" type="image/png" href="images/logo.svg" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet" />
  <style>
    *{ font-family:'Poppins',sans-serif; box-sizing:border-box; }
    body{ margin:0; background:#0f0f0f; color:#fff; display:flex; align-items:center; justify-content:center; min-height:100vh; }
    .wrap{ padding:24px; }
    .card{ width:330px; background:#1e1e1e; border-radius:12px; box-shadow:0 6px 16px rgba(0,0,0,0.35); overflow:hidden; }
    .card-header{ display:flex; align-items:center; gap:10px; padding:12px 16px; background:#111; color:#e5ddc6; }
    .card-header img{ height:28px; }
    .brand{ font-weight:600; }
    .qr-area{ background:#fff; display:flex; align-items:center; justify-content:center; padding:18px; }
    .qr-area img{ width:200px; height:200px; }
    .content{ padding:16px; color:#ddd; }
    .row{ display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
    .label{ font-weight:600; font-size:0.95rem; }
    .badge{ display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:12px; font-size:0.85rem; font-weight:600; }
    .badge.resident{ background:#1e3a8a; color:#d6eaff; }
    .badge.reservation{ background:#5b0a1e; color:#ffb1c9; }
    .badge.visitor{ background:#23412e; color:#e5ddc6; }
    .meta{ font-size:0.9rem; line-height:1.45; color:#bbb; }
    .meta strong{ color:#eee; }
    .divider{ height:1px; background:#2f2f2f; margin:10px 0 12px; }
    .foot{ font-size:0.78rem; color:#aaa; line-height:1.35; }
    .foot .cols{ display:flex; justify-content:space-between; }
    .verify{ display:block; text-align:center; margin:12px 16px 16px; }
    .verify a{ color:#9bd08f; font-weight:600; text-decoration:none; }
    .banner{ text-align:center; font-weight:700; padding:8px; border-top:1px solid #333; color:#9bd08f; }
    .banner.expired, .banner.denied{ color:#ffb3b3; }
  </style>
  <script>
    function goBack(){
      if (document.referrer && document.referrer.indexOf(location.origin) === 0) { location.href = document.referrer; return; }
      history.back();
    }
  </script>
  </head>
<body>
  <div class="wrap">
    <?php if (!empty($error)): ?>
      <div style="background:#ffe5e5;color:#900;padding:12px;border-radius:8px;max-width:330px;">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php else: ?>
    <div class="card">
      <div class="card-header">
        <img src="images/logo.svg" alt="Victorian Heights" />
        <div class="brand">Victorian Heights</div>
      </div>
      <div class="banner <?php echo htmlspecialchars($data['status']); ?>">
        <?php 
          switch($data['status']){
            case 'approved': echo '✅ Valid Entry Pass'; break;
            case 'expired': echo '❌ Expired Entry Pass'; break;
            case 'denied': echo '❌ Denied Entry Pass'; break;
            default: echo '⏳ Pending Review';
          }
        ?>
      </div>
      <div class="qr-area">
        <img src="<?php echo htmlspecialchars($data['qr']); ?>" alt="QR Code" />
      </div>
      <div class="content">
        <div class="row">
          <div class="label">QR <?php echo $data['is_resident'] ? 'Resident' : ($data['is_visitor'] ? 'Visitor' : 'Pass'); ?></div>
          <div>
            <?php if ($data['is_resident']): ?><span class="badge resident">Resident</span><?php endif; ?>
            <?php if ($data['has_reservation']): ?><span class="badge reservation">Reservation</span><?php endif; ?>
            <?php if ($data['is_visitor']): ?><span class="badge visitor">Visitor</span><?php endif; ?>
          </div>
        </div>
        <div class="meta">
          <p><strong>Name:</strong> <?php echo htmlspecialchars($data['name']); ?></p>
          <?php if ($data['birthdate']): ?><p><strong>Birthdate:</strong> <?php echo htmlspecialchars($data['birthdate']); ?></p><?php endif; ?>
          <?php if (!empty($data['sex'])): ?><p><strong>Sex:</strong> <?php echo htmlspecialchars($data['sex']); ?></p><?php endif; ?>
          
          <?php if (!empty($data['address'])): ?><p><strong>Address:</strong> <?php echo htmlspecialchars($data['address']); ?></p><?php endif; ?>
          <?php if (!empty($data['amenity'])): ?><p><strong>Amenity/Visit:</strong> <?php echo htmlspecialchars($data['amenity']); ?></p><?php endif; ?>
        </div>
        <div class="divider"></div>
        <div class="meta">
          <p><strong>Valid Dates:</strong> <?php echo htmlspecialchars($data['valid_window']); ?></p>
          <p><strong>Code:</strong> <?php echo htmlspecialchars($data['code']); ?></p>
        </div>
        <div class="divider"></div>
        <div class="foot">
          <p><strong>Reminder:</strong><br>
            Please present this pass upon entry.
          </p>
          <div class="cols">
            <?php if (!empty($data['created'])): ?><div><strong>Date Created:</strong><br><?php echo htmlspecialchars($data['created']); ?></div><?php endif; ?>
          </div>
        </div>
      </div>
      
      <div class="verify"><a href="<?php echo htmlspecialchars($data['verification']); ?>" target="_blank">Open verification page</a></div>
    </div>
    <?php endif; ?>
  </div>
</body>
</html>
