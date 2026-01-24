<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
include 'connect.php';

function ensureReservationsUpdatedAtColumn($con){
    if (!($con instanceof mysqli)) return;
    $c = $con->query("SHOW COLUMNS FROM reservations LIKE 'updated_at'");
    if ($c && $c->num_rows === 0) { @$con->query("ALTER TABLE reservations ADD COLUMN updated_at TIMESTAMP NULL"); }
}
ensureReservationsUpdatedAtColumn($con);

function ensureEnumHasValues($con, $table, $column, $enumValues, $defaultValue){
    if (!($con instanceof mysqli)) return;
    $res = $con->query("SHOW COLUMNS FROM $table LIKE '$column'");
    if (!$res || $res->num_rows === 0) return;
    $row = $res->fetch_assoc();
    $type = $row['Type'] ?? '';
    $needsChange = false;
    foreach ($enumValues as $val) {
        if (stripos($type, "'" . $val . "'") === false && stripos($type, $val) === false) {
            $needsChange = true;
            break;
        }
    }
    if (!$needsChange) return;
    $enumList = "'" . implode("','", $enumValues) . "'";
    $con->query("ALTER TABLE $table MODIFY COLUMN $column ENUM($enumList) DEFAULT '$defaultValue'");
}

function ensureStatusEnums($con){
    ensureEnumHasValues($con, 'reservations', 'approval_status', ['pending','approved','denied','cancelled','deleted'], 'pending');
    ensureEnumHasValues($con, 'reservations', 'status', ['pending','approved','rejected','expired','cancelled','deleted'], 'pending');
    ensureEnumHasValues($con, 'guest_forms', 'approval_status', ['pending','approved','denied','cancelled','deleted'], 'pending');
    ensureEnumHasValues($con, 'resident_reservations', 'approval_status', ['pending','approved','denied','cancelled','deleted'], 'pending');
}
ensureStatusEnums($con);

// Ensure scan logging table exists (idempotent)
if ($con instanceof mysqli) {
  $con->query("CREATE TABLE IF NOT EXISTS entry_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_code VARCHAR(50) NOT NULL,
    scanned_by_guard_id INT NULL,
    scanned_by_name VARCHAR(150) NULL,
    subject_name VARCHAR(150) NULL,
    entry_type VARCHAR(50) NULL,
    status VARCHAR(50) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ref_code (ref_code),
    INDEX idx_guard (scanned_by_guard_id),
    INDEX idx_scanned_at (scanned_at)
  ) ENGINE=InnoDB");
}

// Handle cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $code = trim($_POST['code'] ?? '');
    if ($action === 'cancel' && $code !== '' && ($con instanceof mysqli)) {
        try {
            // Prefer guest_forms if exists
            $stmtG = $con->prepare("SELECT id, approval_status, resident_user_id FROM guest_forms WHERE ref_code = ? LIMIT 1");
            $stmtG->bind_param('s', $code);
            $stmtG->execute();
            $resG = $stmtG->get_result();
            $stmtG->close();
            if ($resG && $resG->num_rows > 0) {
                $row = $resG->fetch_assoc();
                if (strtolower(trim($row['approval_status'] ?? 'pending')) !== 'pending') {
                    echo json_encode(['success' => false, 'message' => 'Only pending reservations can be cancelled.']);
                    exit;
                }
                // Notify admin
                try {
                    $msg = "Guest request $code cancelled by resident.";
                    $stmtN = $con->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (NULL, 'Request Cancelled', ?, 'warning', NOW())");
                    $stmtN->bind_param('s', $msg);
                    $stmtN->execute();
                    $stmtN->close();
                } catch (Throwable $e) {}

                $stmtU = $con->prepare("UPDATE guest_forms SET approval_status='cancelled', updated_at = NOW() WHERE ref_code = ?");
                $stmtU->bind_param('s', $code);
                $stmtU->execute();
                $stmtU->close();
                $stmtUR = $con->prepare("UPDATE reservations SET approval_status='cancelled', status='cancelled', updated_at = NOW() WHERE ref_code = ?");
                $stmtUR->bind_param('s', $code);
                $stmtUR->execute();
                $stmtUR->close();
                echo json_encode(['success' => true]);
                exit;
            }
            // Try reservations by ref_code
            $stmtR = $con->prepare("SELECT id, approval_status, user_id, entry_pass_id FROM reservations WHERE ref_code = ? LIMIT 1");
            $stmtR->bind_param('s', $code);
            $stmtR->execute();
            $resR = $stmtR->get_result();
            $stmtR->close();
            if ($resR && $resR->num_rows > 0) {
                $row = $resR->fetch_assoc();
                if (strtolower(trim($row['approval_status'] ?? 'pending')) !== 'pending') {
                    echo json_encode(['success' => false, 'message' => 'Only pending reservations can be cancelled.']);
                    exit;
                }
                // Notify admin
                try {
                    $uid = isset($row['user_id']) ? intval($row['user_id']) : null;
                    $eid = isset($row['entry_pass_id']) ? intval($row['entry_pass_id']) : null;
                    $msg = "Reservation $code cancelled by user.";
                    
                    // User notification removed as per requirement
                    // $stmtN = $con->prepare("INSERT INTO notifications (user_id, entry_pass_id, title, message, type, created_at) VALUES (?, ?, 'Request Cancelled', ?, 'warning', NOW())");
                    // $stmtN->bind_param('iis', $uid, $eid, $msg);
                    // $stmtN->execute();
                    // $stmtN->close();

                    // Admin notification
                    $stmtA = $con->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (NULL, 'Request Cancelled', ?, 'warning', NOW())");
                    $stmtA->bind_param('s', $msg);
                    $stmtA->execute();
                    $stmtA->close();
                } catch (Throwable $e) {}

                $stmtU2 = $con->prepare("UPDATE reservations SET approval_status='cancelled', status='cancelled', updated_at = NOW() WHERE ref_code = ?");
                $stmtU2->bind_param('s', $code);
                $stmtU2->execute();
                $stmtU2->close();
                echo json_encode(['success' => true]);
                exit;
            }
            // Fallback: resident_reservations
            $stmtRR = $con->prepare("SELECT id, approval_status, user_id FROM resident_reservations WHERE ref_code = ? LIMIT 1");
            $stmtRR->bind_param('s', $code);
            $stmtRR->execute();
            $resRR = $stmtRR->get_result();
            $stmtRR->close();
            if ($resRR && $resRR->num_rows > 0) {
                $row = $resRR->fetch_assoc();
                if (strtolower(trim($row['approval_status'] ?? 'pending')) !== 'pending') {
                    echo json_encode(['success' => false, 'message' => 'Only pending reservations can be cancelled.']);
                    exit;
                }
                // Notify admin
                try {
                    $uid = isset($row['user_id']) ? intval($row['user_id']) : null;
                    $msg = "Amenity request $code cancelled by resident.";
                    
                    // User notification removed as per requirement
                    // $stmtN = $con->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, 'Request Cancelled', ?, 'warning', NOW())");
                    // $stmtN->bind_param('is', $uid, $msg);
                    // $stmtN->execute();
                    // $stmtN->close();

                    // Admin notification
                    $stmtA = $con->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (NULL, 'Request Cancelled', ?, 'warning', NOW())");
                    $stmtA->bind_param('s', $msg);
                    $stmtA->execute();
                    $stmtA->close();
                } catch (Throwable $e) {}

                $stmtU3 = $con->prepare("UPDATE resident_reservations SET approval_status='cancelled', updated_at = NOW() WHERE ref_code = ?");
                $stmtU3->bind_param('s', $code);
                $stmtU3->execute();
                $stmtU3->close();
                $stmtUR2 = $con->prepare("UPDATE reservations SET approval_status='cancelled', status='cancelled', updated_at = NOW() WHERE ref_code = ?");
                $stmtUR2->bind_param('s', $code);
                $stmtUR2->execute();
                $stmtUR2->close();
                echo json_encode(['success' => true]);
                exit;
            }
            echo json_encode(['success' => false, 'message' => 'Reservation not found.']);
            exit;
        } catch (Throwable $e) {
            error_log('status.php cancel error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error.']);
            exit;
        }
    }
    
    // Handle delete
    if ($action === 'delete' && $code !== '' && ($con instanceof mysqli)) {
        try {
            // Prefer guest_forms if exists
            $stmtG = $con->prepare("SELECT id FROM guest_forms WHERE ref_code = ? LIMIT 1");
            $stmtG->bind_param('s', $code);
            $stmtG->execute();
            $resG = $stmtG->get_result();
            $stmtG->close();
            if ($resG && $resG->num_rows > 0) {
                $stmtU = $con->prepare("UPDATE guest_forms SET approval_status='deleted' WHERE ref_code = ?");
                $stmtU->bind_param('s', $code);
                $stmtU->execute();
                $stmtU->close();
                // Also update reservations if linked
                $stmtUR = $con->prepare("UPDATE reservations SET approval_status='deleted', status='deleted' WHERE ref_code = ?");
                $stmtUR->bind_param('s', $code);
                $stmtUR->execute();
                $stmtUR->close();
                echo json_encode(['success' => true]);
                exit;
            }
            
            // Try reservations by ref_code
            $stmtR = $con->prepare("SELECT id FROM reservations WHERE ref_code = ? LIMIT 1");
            $stmtR->bind_param('s', $code);
            $stmtR->execute();
            $resR = $stmtR->get_result();
            $stmtR->close();
            if ($resR && $resR->num_rows > 0) {
                $stmtU2 = $con->prepare("UPDATE reservations SET approval_status='deleted', status='deleted' WHERE ref_code = ?");
                $stmtU2->bind_param('s', $code);
                $stmtU2->execute();
                $stmtU2->close();
                echo json_encode(['success' => true]);
                exit;
            }

            // Fallback: resident_reservations
            $stmtRR = $con->prepare("SELECT id FROM resident_reservations WHERE ref_code = ? LIMIT 1");
            $stmtRR->bind_param('s', $code);
            $stmtRR->execute();
            $resRR = $stmtRR->get_result();
            $stmtRR->close();
            if ($resRR && $resRR->num_rows > 0) {
                $stmtU3 = $con->prepare("UPDATE resident_reservations SET approval_status='deleted' WHERE ref_code = ?");
                $stmtU3->bind_param('s', $code);
                $stmtU3->execute();
                $stmtU3->close();
                // Also update reservations if linked
                $stmtUR2 = $con->prepare("UPDATE reservations SET approval_status='deleted', status='deleted' WHERE ref_code = ?");
                $stmtUR2->bind_param('s', $code);
                $stmtUR2->execute();
                $stmtUR2->close();
                echo json_encode(['success' => true]);
                exit;
            }

            echo json_encode(['success' => false, 'message' => 'Request not found.']);
            exit;
        } catch (Throwable $e) {
            error_log('status.php delete error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error.']);
            exit;
        }
    }
}

$code = isset($_GET['code']) ? trim($_GET['code']) : '';
if ($code === '') {
    echo json_encode(['success' => false, 'message' => 'Status code is required.']);
    exit;
}

// Check if code is a Resident QR URL (contains resident_qr_view.php?rid=X)
$residentId = 0;
if (preg_match('/rid=(\d+)/', $code, $matches)) {
    $residentId = intval($matches[1]);
}

if ($residentId > 0) {
    // Handle Resident Scan
    $stmtRes = $con->prepare("SELECT id, first_name, middle_name, last_name, email, phone, house_number, address, status FROM users WHERE id = ?");
    $stmtRes->bind_param('i', $residentId);
    $stmtRes->execute();
    $resRes = $stmtRes->get_result();
    
    if ($resRes && $resRes->num_rows > 0) {
        $rUser = $resRes->fetch_assoc();
        $stmtRes->close();
        
        $fullName = trim(($rUser['first_name'] ?? '') . ' ' . ($rUser['middle_name'] ?? '') . ' ' . ($rUser['last_name'] ?? ''));
        $statusVal = 'Active'; // Residents are generally active if they exist in DB
        if (isset($rUser['status']) && strtolower($rUser['status']) === 'inactive') {
             $statusVal = 'Inactive';
        }
        
        $refCode = 'RES-' . $rUser['id'];
        
        $resp = [
            'success' => true,
            'code' => $refCode,
            'name' => $fullName,
            'type' => 'Resident',
            'status' => $statusVal,
            'qr_path' => 'images/mainpage/qr.png', // Default or generate if needed
            'message' => 'Resident Entry Verified',
            'payment_status' => null,
            'entry_pass_id' => null,
            'email' => $rUser['email'],
            'phone' => $rUser['phone'],
            'address' => $rUser['house_number'] . ' ' . $rUser['address'],
            'contact' => $rUser['phone'],
            'sex' => '',
            'birthdate' => '',
            'resident_name' => $fullName,
            'resident_house_number' => $rUser['house_number'],
            'resident_email' => $rUser['email'],
            'resident_phone' => $rUser['phone'],
            'purpose' => 'Resident Entry',
            'persons' => 1,
            'price' => null,
            'downpayment' => null,
            'start_date' => date('m/d/y'),
            'end_date' => date('m/d/y'),
            'start_time' => null,
            'end_time' => null,
            'expires_at' => ''
        ];

        // Guard scan logging
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'guard') {
            $gid = isset($_SESSION['staff_id']) ? intval($_SESSION['staff_id']) : null;
            $gname = isset($_SESSION['guard_surname']) ? trim($_SESSION['guard_surname']) : '';
            if ($gname === '' && isset($_SESSION['email'])) {
                $local = explode('@', $_SESSION['email'])[0] ?? '';
                $s = $local;
                if (strpos($local, '_') !== false) { $parts = explode('_', $local); $s = end($parts); }
                if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
                $s = preg_replace('/[^a-zA-Z]/', '', $s);
                $gname = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
            }
            $resp['scanned_by'] = $gname !== '' ? $gname : 'Guard';
            
            // Persist scan entry
            if ($con instanceof mysqli) {
                // For residents, we might allow multiple scans per day (entry/exit), but for now we'll stick to the "one scan record per day" or maybe just log it.
                // The existing logic prevents duplicate scans for the same ref_code on the same day.
                // For residents, "RES-ID" will be the ref_code.
                
                $exists = false;
                $chk = $con->prepare("SELECT 1 FROM entry_scans WHERE ref_code = ? AND DATE(scanned_at) = CURDATE() LIMIT 1");
                $chk->bind_param('s', $refCode);
                $chk->execute();
                $cres = $chk->get_result();
                if ($cres && $cres->num_rows > 0) { $exists = true; }
                $chk->close();
                
                if (!$exists) {
                    $stmtLog = $con->prepare("INSERT INTO entry_scans (ref_code, scanned_by_guard_id, scanned_by_name, subject_name, entry_type, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $sd = date('Y-m-d');
                    $ed = date('Y-m-d');
                    $subject = $resp['name']; 
                    $etype = $resp['type']; 
                    $stat = $resp['status'];
                    $stmtLog->bind_param('sissssss', $refCode, $gid, $gname, $subject, $etype, $stat, $sd, $ed);
                    @$stmtLog->execute();
                    @$stmtLog->close();
                }
            }
        }
        
        echo json_encode($resp);
        exit;
    } else {
        $stmtRes->close();
        echo json_encode(['success' => false, 'message' => 'Resident not found.']);
        exit;
    }
}

// First, try to retrieve from guest_forms (new guest entry flow)
$stmtGF = $con->prepare("SELECT gf.*, 
                                u.house_number AS res_house_number, u.first_name AS res_first_name, u.middle_name AS res_middle_name, u.last_name AS res_last_name,
                                u.email AS res_email, u.phone AS res_phone
                         FROM guest_forms gf
                         LEFT JOIN users u ON gf.resident_user_id = u.id
                         WHERE gf.ref_code = ?");
$stmtGF->bind_param('s', $code);
$stmtGF->execute();
$resGF = $stmtGF->get_result();
if ($resGF && $resGF->num_rows > 0) {
    $row = $resGF->fetch_assoc();
    $today = date('Y-m-d');
    $statusVal = ($row['approval_status'] ?? 'pending');
    $approvalDateYmd = !empty($row['approval_date']) ? date('Y-m-d', strtotime($row['approval_date'])) : null;
    $expireAfterApprovalYmd = $approvalDateYmd ? date('Y-m-d', strtotime($approvalDateYmd . ' +1 day')) : null;
    if ($statusVal === 'approved' && $expireAfterApprovalYmd && $today > $expireAfterApprovalYmd) {
        $statusVal = 'expired';
    }

    // If linked reservation's payment is rejected, show overall status as rejected
    // (handled after payment lookup below)
    $statusMessage = '';
    switch ($statusVal) {
        case 'approved': $statusMessage = 'Approved: Your guest entry is confirmed.'; break;
        case 'pending': $statusMessage = 'Pending: Awaiting admin review.'; break;
        case 'expired': $statusMessage = 'Expired: This pass has reached its validity end.'; break;
        case 'denied': $statusMessage = 'Denied: Your request was not approved.'; break;
        case 'cancelled': $statusMessage = 'Cancelled: This request was cancelled by the user.'; break;
        default: $statusMessage = ucfirst($statusVal);
    }

    $fullName = '';
    if (!empty($row['full_name'])) {
        $fullName = trim($row['full_name']);
    } else {
        $fullName = trim(implode(' ', array_filter([
            $row['visitor_first_name'] ?? '',
            $row['visitor_middle_name'] ?? '',
            $row['visitor_last_name'] ?? ''
        ], function($v){ return $v !== null && $v !== ''; })));    
    }
    if ($fullName === '') $fullName = 'Guest';
    $email = $row['visitor_email'] ?? '';
    $phone = $row['visitor_contact'] ?? '';
    // Normalize phone to 09 format if stored as +63
    if (preg_match('/^\+63(9\d{9})$/', $phone)) { $phone = '0' . substr($phone, 3); }
    $address = '';
    $sex = $row['visitor_sex'] ?? '';
    $birthRaw = $row['visitor_birthdate'] ?? null;
    $birthdate = $birthRaw ? date('m/d/y', strtotime($birthRaw)) : '';

    $isAmenity = (!empty($row['amenity'])) || (isset($row['wants_amenity']) && intval($row['wants_amenity']) === 1);
    $pay = null; $epid = null; $rAmenity = null; $rStart = null; $rEnd = null; $rStartTime = null; $rEndTime = null; $rPersons = null; $rPrice = null; $rDown = null;
    if ($con instanceof mysqli) {
      $stmtP = $con->prepare("SELECT amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, payment_status, entry_pass_id FROM reservations WHERE ref_code = ? LIMIT 1");
      $stmtP->bind_param('s', $row['ref_code']);
      $stmtP->execute(); $resP = $stmtP->get_result();
      if ($resP && ($pr=$resP->fetch_assoc())) {
        $rAmenity = $pr['amenity'] ?? null;
        $rStart = $pr['start_date'] ?? null;
        $rEnd = $pr['end_date'] ?? null;
        $rStartTime = $pr['start_time'] ?? null;
        $rEndTime = $pr['end_time'] ?? null;
        $rPersons = isset($pr['persons']) ? intval($pr['persons']) : null;
        $rPrice = isset($pr['price']) ? floatval($pr['price']) : null;
        $rDown = isset($pr['downpayment']) ? floatval($pr['downpayment']) : null;
        $pay = strtolower($pr['payment_status'] ?? '');
        $epid = isset($pr['entry_pass_id']) ? intval($pr['entry_pass_id']) : null;
      }
      $stmtP->close();

      if (!empty($epid)) {
        $stmtE = $con->prepare("SELECT address FROM entry_passes WHERE id = ? LIMIT 1");
        $stmtE->bind_param('i', $epid);
        $stmtE->execute();
        $resE = $stmtE->get_result();
        if ($resE && ($ep = $resE->fetch_assoc())) {
          $addrCandidate = trim($ep['address'] ?? '');
          if ($addrCandidate !== '') { $address = $addrCandidate; }
        }
        $stmtE->close();
      }
    }
    $residentName = trim(($row['res_first_name'] ?? '') . ' ' . ($row['res_last_name'] ?? ''));
    // Build base response
    $resp = [
        'success' => true,
        'code' => $row['ref_code'],
        'name' => $fullName,
        'type' => $isAmenity ? ($rAmenity ?: ($row['amenity'] ?: 'Amenity Reservation')) : 'Guest Entry',
        'status' => $statusVal,
        'qr_path' => (!empty($row['qr_path']) ? $row['qr_path'] : 'images/mainpage/qr.png'),
        'message' => $statusMessage,
        'payment_status' => $pay,
        'entry_pass_id' => $epid,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'contact' => $phone,
        'sex' => $sex,
        'birthdate' => $birthdate,
        'resident_name' => ($residentName !== '' ? $residentName : null),
        'resident_house_number' => ($row['res_house_number'] ?? null),
        'resident_email' => ($row['res_email'] ?? null),
        'resident_phone' => ($row['res_phone'] ?? null),
        'purpose' => $isAmenity ? 'Amenity Booking' : ($row['purpose'] ?? ''),
        'persons' => ($rPersons !== null ? $rPersons : (isset($row['persons']) ? intval($row['persons']) : null)),
        'price' => $isAmenity ? ($rPrice !== null ? $rPrice : null) : null,
        'downpayment' => $isAmenity ? ($rDown !== null ? $rDown : null) : null,
        'start_date' => $isAmenity && !empty($rStart) ? date('m/d/y', strtotime($rStart)) : (isset($row['visit_date']) ? date('m/d/y', strtotime($row['visit_date'])) : ''),
        'end_date' => $isAmenity && !empty($rEnd) ? date('m/d/y', strtotime($rEnd)) : (isset($row['visit_date']) ? date('m/d/y', strtotime($row['visit_date'])) : ''),
        'start_time' => ($rStartTime ?: null),
        'end_time' => ($rEndTime ?: null),
        'expires_at' => $expireAfterApprovalYmd ? date('m/d/y', strtotime($expireAfterApprovalYmd)) : ''
    ];
    // If a guard is scanning, record and annotate 'scanned_by'
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'guard') {
      $gid = isset($_SESSION['staff_id']) ? intval($_SESSION['staff_id']) : null;
      // Prefer explicit guard surname stored on login, else derive from email
      $gname = isset($_SESSION['guard_surname']) ? trim($_SESSION['guard_surname']) : '';
      if ($gname === '' && isset($_SESSION['email'])) {
        $local = explode('@', $_SESSION['email'])[0] ?? '';
        $s = $local;
        if (strpos($local, '_') !== false) { $parts = explode('_', $local); $s = end($parts); }
        if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
        $s = preg_replace('/[^a-zA-Z]/', '', $s);
        $gname = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
      }
      $resp['scanned_by'] = $gname !== '' ? $gname : 'Guard';
      // Persist scan entry
      if ($con instanceof mysqli) {
        $exists = false;
        $chk = $con->prepare("SELECT 1 FROM entry_scans WHERE ref_code = ? AND DATE(scanned_at) = CURDATE() LIMIT 1");
        $chk->bind_param('s', $row['ref_code']);
        $chk->execute();
        $cres = $chk->get_result();
        if ($cres && $cres->num_rows > 0) { $exists = true; }
        $chk->close();
        if (!$exists) {
          $stmtLog = $con->prepare("INSERT INTO entry_scans (ref_code, scanned_by_guard_id, scanned_by_name, subject_name, entry_type, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
          $sd = $resp['start_date'] ? date('Y-m-d', strtotime($resp['start_date'])) : null;
          $ed = $resp['end_date'] ? date('Y-m-d', strtotime($resp['end_date'])) : null;
          $subject = $resp['name']; $etype = $resp['type']; $stat = $resp['status'];
          $stmtLog->bind_param('sissssss', $row['ref_code'], $gid, $gname, $subject, $etype, $stat, $sd, $ed);
          @$stmtLog->execute();
          @$stmtLog->close();
        }
      }
    }
    echo json_encode($resp);
    exit;
}

// Build query to retrieve reservation and personal details
$stmt = $con->prepare("SELECT r.*, 
                             e.full_name AS ep_full_name, e.email AS ep_email, e.contact AS ep_phone, e.address AS ep_address,
                             e.sex AS ep_sex, e.birthdate AS ep_birthdate,
                             u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number,
                             u.sex AS user_sex, u.birthdate AS user_birthdate
                       FROM reservations r
                       LEFT JOIN entry_passes e ON r.entry_pass_id = e.id
                       LEFT JOIN users u ON r.user_id = u.id
                       WHERE r.ref_code = ?");

$stmt->bind_param('s', $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    
    // Use approval_status as the primary status indicator
    $today = date('Y-m-d');
    $statusVal = 'pending';
    
    // Expiration logic: only expire 1 day after approval
    $approvalDateYmd = !empty($row['approval_date']) ? date('Y-m-d', strtotime($row['approval_date'])) : null;
    $expireAfterApprovalYmd = $approvalDateYmd ? date('Y-m-d', strtotime($approvalDateYmd . ' +1 day')) : null;

    if (isset($row['approval_status']) && $row['approval_status'] !== '') {
        $statusVal = $row['approval_status'];
        if ($statusVal === 'approved' && $expireAfterApprovalYmd && $today > $expireAfterApprovalYmd) {
            $statusVal = 'expired';
        }
    } else {
        $statusVal = 'pending';
    }

    // Reflect rejected payment as rejected overall status
    $pay = strtolower($row['payment_status'] ?? '');
    if ($pay === 'rejected') { $statusVal = 'rejected'; }

    // Map status to message
    $statusMessage = '';
    switch ($statusVal) {
        case 'approved':
            $statusMessage = 'Approved: Your reservation is confirmed.';
            break;
        case 'pending':
            $statusMessage = 'Pending: Awaiting admin review.';
            break;
        case 'expired':
            $statusMessage = 'Expired: This pass has reached its validity end.';
            break;
        case 'denied':
            $statusMessage = 'Denied: Your reservation was not approved.';
            break;
        case 'cancelled':
            $statusMessage = 'Cancelled: This request was cancelled by the user.';
            break;
        case 'rejected':
            $statusMessage = 'Rejected: Your payment was rejected.';
            break;
        default:
            $statusMessage = ucfirst($statusVal);
    }

    // Prepare personal details
    $fullName = '';
    if (!empty($row['ep_full_name'])) {
        $fullName = $row['ep_full_name'];
    } elseif (!empty($row['first_name']) || !empty($row['last_name']) || !empty($row['middle_name'])) {
        $fullName = trim(implode(' ', array_filter([
            $row['first_name'] ?? '',
            $row['middle_name'] ?? '',
            $row['last_name'] ?? ''
        ], function($v){ return $v !== null && $v !== ''; }))); 
    } else {
        $fullName = 'Guest';
    }

    $email = !empty($row['ep_email']) ? $row['ep_email'] : ($row['email'] ?? '');
    $phone = !empty($row['ep_phone']) ? $row['ep_phone'] : ($row['phone'] ?? '');
    $address = !empty($row['ep_address']) ? $row['ep_address'] : (($row['house_number'] ?? '') ? ('Block ' . $row['house_number']) : '');
    $sex = !empty($row['ep_sex']) ? $row['ep_sex'] : ($row['user_sex'] ?? '');
    $birthRaw = !empty($row['ep_birthdate']) ? $row['ep_birthdate'] : ($row['user_birthdate'] ?? null);
    $birthdate = $birthRaw ? date('m/d/y', strtotime($birthRaw)) : '';
    
    $resp = [
        'success' => true,
        'code' => $row['ref_code'],
        'name' => $fullName,
        'type' => $row['amenity'],
        'status' => $statusVal,
        'qr_path' => (!empty($row['qr_path']) ? $row['qr_path'] : 'images/mainpage/qr.png'),
        'message' => $statusMessage,
        'payment_status' => isset($row['payment_status']) ? strtolower($row['payment_status']) : null,
        'entry_pass_id' => isset($row['entry_pass_id']) ? intval($row['entry_pass_id']) : null,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'contact' => $email,
        'sex' => $sex,
        'birthdate' => $birthdate,
        'purpose' => isset($row['purpose']) ? $row['purpose'] : '',
        'persons' => isset($row['persons']) ? intval($row['persons']) : null,
        'price' => isset($row['price']) ? floatval($row['price']) : null,
        'downpayment' => isset($row['downpayment']) ? floatval($row['downpayment']) : null,
        'start_date' => isset($row['start_date']) ? date('m/d/y', strtotime($row['start_date'])) : '',
        'end_date' => isset($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : '',
        'start_time' => !empty($row['start_time']) ? $row['start_time'] : null,
        'end_time' => !empty($row['end_time']) ? $row['end_time'] : null,
        'expires_at' => $expireAfterApprovalYmd ? date('m/d/y', strtotime($expireAfterApprovalYmd)) : ''
    ];
    // Guard scan logging
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'guard') {
      $gid = isset($_SESSION['staff_id']) ? intval($_SESSION['staff_id']) : null;
      $gname = isset($_SESSION['guard_surname']) ? trim($_SESSION['guard_surname']) : '';
      if ($gname === '' && isset($_SESSION['email'])) {
        $local = explode('@', $_SESSION['email'])[0] ?? '';
        $s = $local;
        if (strpos($local, '_') !== false) { $parts = explode('_', $local); $s = end($parts); }
        if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
        $s = preg_replace('/[^a-zA-Z]/', '', $s);
        $gname = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
      }
      $resp['scanned_by'] = $gname !== '' ? $gname : 'Guard';
      if ($con instanceof mysqli) {
        $exists = false;
        $chk = $con->prepare("SELECT 1 FROM entry_scans WHERE ref_code = ? AND DATE(scanned_at) = CURDATE() LIMIT 1");
        $chk->bind_param('s', $row['ref_code']);
        $chk->execute();
        $cres = $chk->get_result();
        if ($cres && $cres->num_rows > 0) { $exists = true; }
        $chk->close();
        if (!$exists) {
          $stmtLog = $con->prepare("INSERT INTO entry_scans (ref_code, scanned_by_guard_id, scanned_by_name, subject_name, entry_type, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
          $sd = $resp['start_date'] ? date('Y-m-d', strtotime($resp['start_date'])) : null;
          $ed = $resp['end_date'] ? date('Y-m-d', strtotime($resp['end_date'])) : null;
          $subject = $resp['name']; $etype = $resp['type']; $stat = $resp['status'];
          $stmtLog->bind_param('sissssss', $row['ref_code'], $gid, $gname, $subject, $etype, $stat, $sd, $ed);
          @$stmtLog->execute();
          @$stmtLog->close();
        }
      }
    }
    echo json_encode($resp);
    exit;
}

// Not found
// Fallback: check resident_reservations by ref_code
$stmt2 = $con->prepare("SELECT rr.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number, u.sex AS user_sex, u.birthdate AS user_birthdate
                        FROM resident_reservations rr
                        LEFT JOIN users u ON rr.user_id = u.id
                        WHERE rr.ref_code = ?");
$stmt2->bind_param('s', $code);
$stmt2->execute();
$res2 = $stmt2->get_result();
if ($res2 && $res2->num_rows > 0) {
    $row = $res2->fetch_assoc();

    $today = date('Y-m-d');
    $statusVal = isset($row['approval_status']) && $row['approval_status'] !== '' ? $row['approval_status'] : 'pending';
    // Expire based on end_date after approval
    if ($statusVal === 'approved' && !empty($row['end_date']) && $row['end_date'] < $today) {
        $statusVal = 'expired';
    }
    $statusMessage = '';
    switch ($statusVal) {
        case 'approved': $statusMessage = 'Approved: Your reservation is confirmed.'; break;
        case 'pending': $statusMessage = 'Pending: Awaiting admin review.'; break;
        case 'expired': $statusMessage = 'Expired: This reservation has ended.'; break;
        case 'denied': $statusMessage = 'Denied: Your reservation was not approved.'; break;
        case 'cancelled': $statusMessage = 'Cancelled: This request was cancelled by the user.'; break;
        default: $statusMessage = ucfirst($statusVal);
    }

    $fullName = trim(implode(' ', array_filter([
        $row['first_name'] ?? '',
        $row['middle_name'] ?? '',
        $row['last_name'] ?? ''
    ], function($v){ return $v !== null && $v !== ''; }))); 
    $email = $row['email'] ?? '';
    $phone = $row['phone'] ?? '';
    // Normalize phone to 09 format if stored as +63
    if (preg_match('/^\+63(9\d{9})$/', $phone)) { $phone = '0' . substr($phone, 3); }
    $address = ($row['house_number'] ?? '') ? ('Block ' . $row['house_number']) : '';
    $sex = $row['user_sex'] ?? '';
    $birthRaw = $row['user_birthdate'] ?? null;
    $birthdate = $birthRaw ? date('m/d/y', strtotime($birthRaw)) : '';
    $pay = null;
    if ($con instanceof mysqli) {
        $stmtPay = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
        $stmtPay->bind_param('s', $row['ref_code']);
        $stmtPay->execute();
        $resPay = $stmtPay->get_result();
        if ($resPay && ($rwP = $resPay->fetch_assoc())) { $pay = strtolower($rwP['payment_status'] ?? ''); }
        $stmtPay->close();
    }

    $resp = [
        'success' => true,
        'code' => $row['ref_code'],
        'name' => $fullName !== '' ? $fullName : 'Resident',
        'type' => $row['amenity'],
        'status' => $statusVal,
        'qr_path' => 'images/mainpage/qr.png',
        'message' => $statusMessage,
        'payment_status' => $pay,
        'entry_pass_id' => null,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'contact' => $email,
        'sex' => $sex,
        'birthdate' => $birthdate,
        'purpose' => $row['notes'] ?? '',
        'persons' => null,
        'price' => null,
        'start_date' => isset($row['start_date']) ? date('m/d/y', strtotime($row['start_date'])) : '',
        'end_date' => isset($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : '',
        'start_time' => !empty($row['start_time']) ? $row['start_time'] : null,
        'end_time' => !empty($row['end_time']) ? $row['end_time'] : null,
        'expires_at' => isset($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : ''
    ];
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'guard') {
      $gid = isset($_SESSION['staff_id']) ? intval($_SESSION['staff_id']) : null;
      $gname = isset($_SESSION['guard_surname']) ? trim($_SESSION['guard_surname']) : '';
      if ($gname === '' && isset($_SESSION['email'])) {
        $local = explode('@', $_SESSION['email'])[0] ?? '';
        $s = $local;
        if (strpos($local, '_') !== false) { $parts = explode('_'); $s = end($parts); }
        if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
        $s = preg_replace('/[^a-zA-Z]/', '', $s);
        $gname = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
      }
      $resp['scanned_by'] = $gname !== '' ? $gname : 'Guard';
      if ($con instanceof mysqli) {
        $exists = false;
        $chk = $con->prepare("SELECT 1 FROM entry_scans WHERE ref_code = ? AND DATE(scanned_at) = CURDATE() LIMIT 1");
        $chk->bind_param('s', $row['ref_code']);
        $chk->execute();
        $cres = $chk->get_result();
        if ($cres && $cres->num_rows > 0) { $exists = true; }
        $chk->close();
        if (!$exists) {
          $stmtLog = $con->prepare("INSERT INTO entry_scans (ref_code, scanned_by_guard_id, scanned_by_name, subject_name, entry_type, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
          $sd = $resp['start_date'] ? date('Y-m-d', strtotime($resp['start_date'])) : null;
          $ed = $resp['end_date'] ? date('Y-m-d', strtotime($resp['end_date'])) : null;
          $subject = $resp['name']; $etype = $resp['type']; $stat = $resp['status'];
          $stmtLog->bind_param('sissssss', $row['ref_code'], $gid, $gname, $subject, $etype, $stat, $sd, $ed);
          @$stmtLog->execute();
          @$stmtLog->close();
        }
      }
    }
    echo json_encode($resp);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid status code.']);
exit;
      // Override status to rejected if payment is rejected
      if ($pay === 'rejected') { $statusVal = 'rejected'; $statusMessage = 'Rejected: Payment was rejected.'; }
