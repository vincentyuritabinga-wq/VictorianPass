<?php
include 'connect.php';

$code = isset($_GET['code']) ? trim($_GET['code']) : '';
if ($code === '') { 
    echo '<div style="color:red;padding:20px;">Error: Reference code is required.</div>';
    exit;
}

$data = null;
$error = null;

$today = date('Y-m-d');
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/\\');
$verificationLink = sprintf('%s://%s%s/qr_view.php?code=%s', $scheme, $host, $basePath, urlencode($code));

// 1. Try Guest Forms
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

    $fullName = trim(implode(' ', array_filter([
        $row['visitor_first_name'] ?? '',
        $row['visitor_middle_name'] ?? '',
        $row['visitor_last_name'] ?? ''
    ], function($v){ return $v !== null && $v !== ''; })));
    if ($fullName === '') { $fullName = 'Guest'; }
    $email = $row['visitor_email'] ?? '';
    $phone = $row['visitor_contact'] ?? '';
    if (preg_match('/^\+63(9\d{9})$/', $phone)) { $phone = '0' . substr($phone, 3); }
    $address = ($row['res_house_number'] ?? ($row['resident_house'] ?? ''));
    $sex = $row['visitor_sex'] ?? '';
    $birthRaw = $row['visitor_birthdate'] ?? null;
    $birthdate = $birthRaw ? date('m/d/y', strtotime($birthRaw)) : '';
    $publishDate = !empty($row['visit_date']) ? date('m/d/y', strtotime($row['visit_date'])) : '';
    $expireDate = '';
    $validWindow = ($publishDate ?: '-');
    $qrPath = !empty($row['qr_path']) ? $row['qr_path'] : '';
    $qrImg = $qrPath ? $qrPath : ('https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($verificationLink));
    $hasReservation = false;

    // Try to load linked reservation details via ref_code
    $rAmenity = '';
    $rStartDate = '';
    $rEndDate = '';
    $rStartTime = null;
    $rEndTime = null;
    $rPersons = null;
    $stmtR = $con->prepare("SELECT amenity, start_date, end_date, start_time, end_time, persons, qr_path FROM reservations WHERE ref_code = ? LIMIT 1");
    if ($stmtR) {
        $stmtR->bind_param('s', $row['ref_code']);
        if ($stmtR->execute()) {
            $resR = $stmtR->get_result();
            if ($resR && $resR->num_rows > 0) {
                $r = $resR->fetch_assoc();
                $rAmenity = $r['amenity'] ?? '';
                $rStartDate = $r['start_date'] ?? '';
                $rEndDate = $r['end_date'] ?? '';
                $rStartTime = $r['start_time'] ?? null;
                $rEndTime = $r['end_time'] ?? null;
                $rPersons = isset($r['persons']) ? intval($r['persons']) : null;
                $hasReservation = !empty($rAmenity);
                if (!$qrPath && !empty($r['qr_path'])) { $qrPath = $r['qr_path']; $qrImg = $qrPath; }
                if ($hasReservation) {
                    $publishDate = !empty($rStartDate) ? date('m/d/y', strtotime($rStartDate)) : $publishDate;
                    $expireDate = !empty($rEndDate) ? date('m/d/y', strtotime($rEndDate)) : '';
                    $validWindow = ($publishDate ?: '-') . ($expireDate ? (' → ' . $expireDate) : '');
                }
            }
        }
        $stmtR->close();
    }

    $data = [
        'code' => $row['ref_code'],
        'status' => $statusVal,
        'name' => $fullName,
        'sex' => $sex,
        'birthdate' => $birthdate,
        'contact' => $phone,
        'email' => $email,
        'address' => $address,
        'amenity' => $hasReservation ? $rAmenity : '',
        'purpose' => $row['purpose'] ?? '',
        'start_time' => $rStartTime,
        'end_time' => $rEndTime,
        'persons' => $rPersons,
        'publish' => $publishDate,
        'expire' => $expireDate,
        'valid_window' => $validWindow,
        'created' => !empty($row['created_at']) ? date('m/d/y', strtotime($row['created_at'])) : '',
        'qr' => $qrImg,
        'has_reservation' => $hasReservation,
        'is_visitor' => true,
        'is_resident' => false,
        'is_guest' => true,
        'resident_name' => trim(((string)($row['res_first_name'] ?? '')) . ' ' . ((string)($row['res_last_name'] ?? ''))),
        'verification' => $verificationLink
    ];
}

// 2. Try Reservations (with Entry Pass or User)
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
            $displayName = trim(implode(' ', array_filter([
                $row['ep_full_name'] ?? '',
                $row['ep_middle_name'] ?? '',
                $row['ep_last_name'] ?? ''
            ], function($v){ return $v !== null && $v !== ''; })));
            $sex = $row['ep_sex'] ?? '';
            $birthdate = $row['ep_birthdate'] ?? '';
            $contact = $row['ep_contact'] ?? '';
            $address = $row['ep_address'] ?? '';
            $email = $row['ep_email'] ?? '';
            $createdAt = $row['created_at'] ?? '';
        } else {
            $displayName = trim(implode(' ', array_filter([
                $row['first_name'] ?? '',
                $row['middle_name'] ?? '',
                $row['last_name'] ?? ''
            ], function($v){ return $v !== null && $v !== ''; })));
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

// 3. Try Resident Reservations
if (!$data) {
    $stmt2 = $con->prepare("SELECT rr.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number, u.sex AS user_sex, u.birthdate AS user_birthdate FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id WHERE rr.ref_code = ?");
    $stmt2->bind_param('s', $code);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $stmt2->close();
    if ($res2 && $res2->num_rows > 0) {
        $row = $res2->fetch_assoc();
        $statusVal = isset($row['approval_status']) && $row['approval_status'] !== '' ? $row['approval_status'] : 'pending';
        if ($statusVal === 'approved' && !empty($row['end_date']) && $row['end_date'] < $today) { $statusVal = 'expired'; }
        $fullName = trim(implode(' ', array_filter([
            $row['first_name'] ?? '',
            $row['middle_name'] ?? '',
            $row['last_name'] ?? ''
        ], function($v){ return $v !== null && $v !== ''; })));
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

if (!$data) {
    echo '<div style="padding:20px;text-align:center;">Details not found or invalid reference code.</div>';
    exit;
}
?>

<div class="qr-card">
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
            case 'rejected': echo 'Rejected'; break;
            default: echo '⏳ Pending Review';
          }
        ?>
    </div>
    <?php if (($data['status'] ?? '') === 'approved'): ?>
    <div class="qr-area">
        <img src="<?php echo htmlspecialchars($data['qr']); ?>" alt="QR Code" crossorigin="anonymous" />
    </div>
    <?php endif; ?>
    <div class="content">
        <div class="row">
          <div class="label">QR <?php echo !empty($data['is_guest']) ? "Resident's Guest" : ($data['is_resident'] ? 'Resident' : ($data['is_visitor'] ? 'Visitor' : 'Pass')); ?></div>
          <div>
            <?php if ($data['is_resident']): ?><span class="badge resident">Resident</span><?php endif; ?>
            <?php if ($data['has_reservation']): ?><span class="badge reservation">Reservation</span><?php endif; ?>
            <?php if ($data['is_visitor']): ?><span class="badge visitor"><?php echo !empty($data['is_guest']) ? "Resident's Guest" : 'Visitor'; ?></span><?php endif; ?>
          </div>
        </div>
        <div class="meta">
          <?php if (!empty($data['resident_name']) && !empty($data['is_guest'])): ?><p><strong>Resident:</strong> <?php echo htmlspecialchars($data['resident_name']); ?></p><?php endif; ?>
          <p><strong><?php echo !empty($data['is_guest']) ? "Resident's Guest Name" : "Name"; ?>:</strong> <?php echo htmlspecialchars($data['name']); ?></p>
          <?php if ($data['birthdate']): ?><p><strong>Birthdate:</strong> <?php echo htmlspecialchars($data['birthdate']); ?></p><?php endif; ?>
          <?php if (!empty($data['sex'])): ?><p><strong>Sex:</strong> <?php echo htmlspecialchars($data['sex']); ?></p><?php endif; ?>

          <?php if (!empty($data['address'])): ?><p><strong><?php echo !empty($data['is_guest']) ? "Resident House Number" : "Address"; ?>:</strong> <?php echo htmlspecialchars($data['address']); ?></p><?php endif; ?>
          <?php if (!empty($data['purpose'])): ?><p><strong>Purpose:</strong> <?php echo htmlspecialchars($data['purpose']); ?></p><?php endif; ?>
          <?php if (!empty($data['amenity'])): ?><p><strong>Amenity/Visit:</strong> <?php echo htmlspecialchars($data['amenity']); ?></p><?php endif; ?>
          <?php $t1 = !empty($data['start_time']) ? date('H:i', strtotime($data['start_time'])) : ''; $t2 = !empty($data['end_time']) ? date('H:i', strtotime($data['end_time'])) : ''; if($t1 || $t2){ ?>
            <p><strong>Time:</strong> <?php echo htmlspecialchars($t1 . ($t2 ? ' → ' . $t2 : '')); ?></p>
          <?php } ?>
          <?php if (!empty($data['persons'])): ?><p><strong>Persons:</strong> <?php echo htmlspecialchars($data['persons']); ?></p><?php endif; ?>
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
    
    <?php if (($data['status'] ?? '') === 'approved'): ?>
      <div class="verify"><a href="#" onclick="downloadQRImage('<?php echo htmlspecialchars($data['code']); ?>');return false;">Download QR</a></div>
    <?php endif; ?>
</div>
