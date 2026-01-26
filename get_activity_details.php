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
    $rPrice = null;
    $rDownpayment = null;

    $stmtR = $con->prepare("SELECT amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, qr_path FROM reservations WHERE ref_code = ? LIMIT 1");
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
                $rPrice = isset($r['price']) ? floatval($r['price']) : null;
                $rDownpayment = isset($r['downpayment']) ? floatval($r['downpayment']) : null;

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
        'price' => $rPrice,
        'downpayment' => $rDownpayment,
        'publish' => $publishDate,
        'expire' => $expireDate,
        'valid_window' => $validWindow,
        'created' => !empty($row['created_at']) ? date('m/d/y', strtotime($row['created_at'])) : '',
        'qr' => $qrImg,
        'has_reservation' => $hasReservation,
        'is_visitor' => true,
        'is_resident' => false,
        'is_guest' => true,
        'reserved_by' => "Resident’s Guest",
        'resident_name' => trim(((string)($row['res_first_name'] ?? '')) . ' ' . ((string)($row['res_last_name'] ?? ''))),
        'resident_contact' => $row['res_phone'] ?? '',
        'resident_email' => $row['res_email'] ?? '',
        'resident_house_number' => $row['res_house_number'] ?? '',
        'guest_name' => $fullName,
        'is_resident_guest' => true,
        'verification' => $verificationLink
    ];
}

// 2. Try Reservations (with Entry Pass or User)
if (!$data) {
    $stmt = $con->prepare("SELECT r.*, e.full_name AS ep_full_name, e.middle_name AS ep_middle_name, e.last_name AS ep_last_name, e.sex AS ep_sex, e.birthdate AS ep_birthdate, e.contact AS ep_contact, e.email AS ep_email, e.address AS ep_address, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number, u.address AS user_address, u.sex AS user_sex, u.birthdate AS user_birthdate, gf.id AS gf_id FROM reservations r LEFT JOIN entry_passes e ON r.entry_pass_id = e.id LEFT JOIN users u ON r.user_id = u.id LEFT JOIN guest_forms gf ON r.ref_code = gf.ref_code WHERE r.ref_code = ?");
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

        $hasEntryPass = !empty($row['entry_pass_id']);
        $uType = isset($row['user_type']) ? strtolower($row['user_type']) : '';
        
        $isVisitor = $hasEntryPass || $uType === 'visitor';
        $isResident = !empty($row['user_id']) && $uType !== 'visitor';
        $hasReservation = !empty($row['amenity']);
        
        $bookedRole = strtolower(trim($row['booked_by_role'] ?? ''));
        $bookedName = trim((string)($row['booked_by_name'] ?? ''));
        $residentFullName = trim(implode(' ', array_filter([
            $row['first_name'] ?? '',
            $row['middle_name'] ?? '',
            $row['last_name'] ?? ''
        ], function($v){ return $v !== null && $v !== ''; })));
        $residentEmail = $row['email'] ?? '';
        $residentContact = $row['phone'] ?? '';

        if ($hasEntryPass) {
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
        $isResidentGuest = (!$hasEntryPass && ($bookedRole === 'guest' || $bookedRole === 'co_owner') && $bookedName !== '');
        if ($isResidentGuest) {
            $displayName = $bookedName;
            $contact = '';
            $email = '';
            $sex = '';
            $birthdate = '';
            $address = '';
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
            'start_time' => $row['start_time'] ?? null,
            'end_time' => $row['end_time'] ?? null,
            'persons' => isset($row['persons']) ? intval($row['persons']) : null,
            'price' => isset($row['price']) ? floatval($row['price']) : null,
            'downpayment' => isset($row['downpayment']) ? floatval($row['downpayment']) : null,
            'publish' => $publishDate,
            'expire' => $expireDate,
            'valid_window' => $validWindow,
            'created' => $createdAt ? date('m/d/y', strtotime($createdAt)) : '',
            'qr' => $qrImg,
            'has_reservation' => $hasReservation,
            'is_visitor' => $isVisitor,
            'is_resident' => $isResident,
            'reserved_by' => (!empty($row['gf_id']) || $isResidentGuest ? "Resident’s Guest" : ($isResident ? 'Resident' : ($isVisitor ? 'Visitor' : ''))),
            'resident_name' => $residentFullName,
            'resident_contact' => $residentContact,
            'resident_email' => $residentEmail,
            'resident_house_number' => $row['house_number'] ?? '',
            'guest_name' => $displayName,
            'is_resident_guest' => $isResidentGuest,
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
            'start_time' => null, // Assuming not available in resident_reservations unless added
            'end_time' => null,
            'persons' => null,
            'price' => null,
            'downpayment' => null,
            'publish' => $publishDate,
            'expire' => $expireDate,
            'valid_window' => $validWindow,
            'created' => !empty($row['created_at']) ? date('m/d/y', strtotime($row['created_at'])) : '',
            'qr' => $qrImg,
            'has_reservation' => true,
            'is_visitor' => false,
            'is_resident' => true,
            'reserved_by' => 'Resident',
            'resident_name' => $fullName !== '' ? $fullName : 'Resident',
            'resident_contact' => $phone,
            'resident_email' => $email,
            'guest_name' => '',
            'is_resident_guest' => false,
            'verification' => $verificationLink
        ];
    }
}

if (!$data) {
    echo '<div style="padding:20px;text-align:center;font-family:\'Poppins\',sans-serif;">Details not found or invalid reference code.</div>';
    exit;
}
?>

<div class="activity-details-container">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        .activity-details-container {
            font-family: 'Poppins', sans-serif;
            color: #333;
        }
        .details-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .details-header img {
            height: 40px;
        }
        .details-header .title {
            font-weight: 700;
            font-size: 1.1rem;
            color: #23412e;
        }
        
        .section-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: #555;
            margin: 15px 0 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            background: #f9f9f9;
            padding: 15px;
            border-radius: 12px;
            border: 1px solid #eee;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.95rem;
        }
        
        .info-label {
            color: #666;
            font-weight: 500;
        }
        
        .info-value {
            color: #111;
            font-weight: 600;
            text-align: right;
        }
        
        .status-badge-lg {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            margin-bottom: 15px;
        }
        .st-approved { background: #dcfce7; color: #166534; }
        .st-pending { background: #ffedd5; color: #c2410c; }
        .st-denied { background: #fee2e2; color: #991b1b; }
        .st-expired { background: #f3f4f6; color: #4b5563; }
        
        .qr-display {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border: 1px solid #eee;
            border-radius: 12px;
        }
        .qr-display img {
            max-width: 180px;
            height: auto;
        }
        
        .price-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
        
        .total-price {
            display: flex;
            justify-content: space-between;
            font-size: 1.1rem;
            font-weight: 700;
            color: #23412e;
        }

        .action-btn {
            display: block;
            width: 100%;
            padding: 12px;
            background-color: #23412e;
            color: #fff;
            text-align: center;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 20px;
            transition: background 0.2s;
            border: none;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
        }
        .action-btn:hover {
            background-color: #1b3324;
        }
    </style>

    <div class="details-header">
        <img src="images/logo.svg" alt="Logo">
        <div class="title">
            Request Details
            <?php 
                if(strpos(strtolower($data['status']), 'cancel') !== false) {
                    echo ' - Cancelled';
                }
            ?>
        </div>
    </div>

    <?php 
        $stClass = 'st-pending';
        $stLabel = 'Pending Review';
        $s = strtolower($data['status']);
        if(strpos($s, 'approv')!==false) { $stClass = 'st-approved'; $stLabel = 'Approved'; }
        else if(strpos($s, 'denied')!==false || strpos($s, 'reject')!==false) { $stClass = 'st-denied'; $stLabel = 'Denied'; }
        else if(strpos($s, 'cancel')!==false) { $stClass = 'st-denied'; $stLabel = 'Cancelled'; }
        else if(strpos($s, 'expire')!==false) { $stClass = 'st-expired'; $stLabel = 'Expired'; }
    ?>
    <div style="text-align:center;">
        <span class="status-badge-lg <?php echo $stClass; ?>"><?php echo $stLabel; ?></span>
    </div>

    <?php if ($data['has_reservation'] && !empty($data['amenity'])): ?>
    <div class="section-title">Amenity Booking Details</div>
    <div class="info-grid">
        <div class="info-row">
            <span class="info-label">Amenity Type</span>
            <span class="info-value"><?php echo htmlspecialchars($data['amenity']); ?></span>
        </div>
        <?php if(!empty($data['reserved_by'])): ?>
        <div class="info-row">
            <span class="info-label">Reserved By</span>
            <span class="info-value"><?php echo htmlspecialchars($data['reserved_by']); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if(!empty($data['publish'])): ?>
        <div class="info-row">
            <span class="info-label">Booking Date</span>
            <span class="info-value"><?php echo htmlspecialchars($data['publish']); ?><?php if(!empty($data['expire']) && $data['expire'] !== $data['publish']) echo ' - ' . htmlspecialchars($data['expire']); ?></span>
        </div>
        <?php endif; ?>

        <?php 
            $timeStr = '';
            if(!empty($data['start_time'])) {
                $timeStr .= date('h:i A', strtotime($data['start_time']));
                if(!empty($data['end_time'])) {
                    $timeStr .= ' - ' . date('h:i A', strtotime($data['end_time']));
                    // Calculate hours
                    $t1 = strtotime($data['start_time']);
                    $t2 = strtotime($data['end_time']);
                    if($t2 > $t1) {
                        $hrs = round(($t2 - $t1) / 3600, 1);
                        $timeStr .= " ({$hrs} hrs)";
                    }
                }
            }
        ?>
        <?php if($timeStr): ?>
        <div class="info-row">
            <span class="info-label">Time & Hours</span>
            <span class="info-value"><?php echo htmlspecialchars($timeStr); ?></span>
        </div>
        <?php endif; ?>

        <?php if(!empty($data['persons'])): ?>
        <div class="info-row">
            <span class="info-label">No. of Persons</span>
            <span class="info-value"><?php echo htmlspecialchars($data['persons']); ?> Pax</span>
        </div>
        <?php endif; ?>

        <?php if($data['price'] !== null): ?>
        <div class="price-section">
            <div class="info-row total-price">
                <span>Total Price</span>
                <span>₱<?php echo number_format($data['price'], 2); ?></span>
            </div>
            <?php if($data['downpayment'] !== null && $data['downpayment'] > 0): ?>
            <div class="info-row" style="margin-top:5px; font-size:0.9rem; color:#666;">
                <span>Downpayment Paid</span>
                <span>- ₱<?php echo number_format($data['downpayment'], 2); ?></span>
            </div>
            <div class="info-row" style="margin-top:5px; font-weight:600; color:#c2410c;">
                <span>Balance Due</span>
                <span>₱<?php echo number_format(max(0, $data['price'] - $data['downpayment']), 2); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($data['is_resident_guest'])): ?>
    <div class="section-title">Resident Owner Information</div>
    <div class="info-grid">
        <?php if (!empty($data['resident_name'])): ?>
        <div class="info-row">
            <span class="info-label">Resident Name</span>
            <span class="info-value"><?php echo htmlspecialchars($data['resident_name']); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($data['resident_contact'])): ?>
        <div class="info-row">
            <span class="info-label">Resident Contact</span>
            <span class="info-value"><?php echo htmlspecialchars($data['resident_contact']); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($data['resident_email'])): ?>
        <div class="info-row">
            <span class="info-label">Resident Email</span>
            <span class="info-value" style="font-size:0.85rem;"><?php echo htmlspecialchars($data['resident_email']); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($data['resident_house_number'])): ?>
        <div class="info-row">
            <span class="info-label">House Number</span>
            <span class="info-value"><?php echo htmlspecialchars($data['resident_house_number']); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="section-title">Personal Information</div>
    <div class="info-grid">
        <div class="info-row">
            <span class="info-label"><?php echo !empty($data['is_resident_guest']) ? 'Guest Name' : 'Name'; ?></span>
            <span class="info-value"><?php echo htmlspecialchars($data['name']); ?></span>
        </div>
        <?php if(!empty($data['contact'])): ?>
        <div class="info-row">
            <span class="info-label">Contact</span>
            <span class="info-value"><?php echo htmlspecialchars($data['contact']); ?></span>
        </div>
        <?php endif; ?>
        <?php if(!empty($data['email'])): ?>
        <div class="info-row">
            <span class="info-label">Email</span>
            <span class="info-value" style="font-size:0.85rem;"><?php echo htmlspecialchars($data['email']); ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="info-label">Reference Code</span>
            <span class="info-value" style="font-family:monospace; letter-spacing:1px;"><?php echo htmlspecialchars($data['code']); ?></span>
        </div>
    </div>

    <?php if (($data['status'] ?? '') === 'approved'): ?>
    <?php endif; ?>

</div>
