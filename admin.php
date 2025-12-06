<?php
session_start();
include 'connect.php';

function admin_status_link($code){ $scheme=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http'; $host=$_SERVER['HTTP_HOST']??'localhost'; $basePath=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/VictorianPass'),'/'); return $scheme.'://'.$host.$basePath.'/status_view.php?code='.urlencode($code); }
function admin_send_email($to,$subject,$body){
  if(!$to) return false;
  $fromName = getenv('MAIL_FROM_NAME') ?: 'VictorianPass';
  $fromEmail = getenv('MAIL_FROM') ?: 'noreply@victorianpass.local';
  $vendor = __DIR__ . '/vendor/autoload.php';
  $hasPHPMailer = file_exists($vendor);
  if($hasPHPMailer){
    require_once $vendor;
    if(class_exists('PHPMailer\\PHPMailer\\PHPMailer')){
      $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      try{
        $host = getenv('SMTP_HOST');
        if($host){
          $mail->isSMTP();
          $mail->Host = $host;
          $mail->SMTPAuth = true;
          $mail->Username = getenv('SMTP_USER') ?: '';
          $mail->Password = getenv('SMTP_PASS') ?: '';
          $secure = getenv('SMTP_SECURE') ?: 'tls';
          $mail->SMTPSecure = $secure;
          $mail->Port = intval(getenv('SMTP_PORT') ?: ($secure==='ssl'?465:587));
        }
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $body;
        return $mail->send();
      } catch (Throwable $e) {
        return false;
      }
    }
  }
  $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: ".$fromName." <".$fromEmail.">\r\n";
  return @mail($to,$subject,$body,$headers);
}
function ensureEmailStatusColumns($con){ if(!($con instanceof mysqli)) return; $tables=['reservations','guest_forms']; foreach($tables as $t){ $c1=$con->query("SHOW COLUMNS FROM $t LIKE 'email_sent'"); if(!$c1||$c1->num_rows===0){ @$con->query("ALTER TABLE $t ADD COLUMN email_sent TINYINT(1) NOT NULL DEFAULT 0"); } $c2=$con->query("SHOW COLUMNS FROM $t LIKE 'email_sent_at'"); if(!$c2||$c2->num_rows===0){ @$con->query("ALTER TABLE $t ADD COLUMN email_sent_at DATETIME NULL"); } $c3=$con->query("SHOW COLUMNS FROM $t LIKE 'email_error'"); if(!$c3||$c3->num_rows===0){ @$con->query("ALTER TABLE $t ADD COLUMN email_error TEXT NULL"); } }
}
function send_status_email_template($to,$code){
  if(!$to||!filter_var($to,FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'err'=>'invalid_email'];
  $subject='Your VictorianPass Status Code & QR Approval';
  $link=admin_status_link($code);
  $body='<div style="font-family:Poppins,Arial,sans-serif;color:#222;background:#f7f7f7;padding:20px">'
       .'<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;box-shadow:0 6px 16px rgba(0,0,0,0.08);overflow:hidden">'
       .'<div style="background:#23412e;color:#fff;padding:16px 20px;font-weight:700">VictorianPass</div>'
       .'<div style="padding:20px">'
       .'<p style="margin:0 0 10px">Hello,</p>'
       .'<p style="margin:0 0 14px;line-height:1.6">Your payment has been confirmed, and your EntryPass QR code has been approved.</p>'
       .'<p style="margin:0 0 8px">Your status code:</p>'
       .'<div style="display:inline-block;background:#f3f3f3;border:1px solid #e0e0e0;padding:12px 16px;border-radius:10px;font-weight:700">'.htmlspecialchars($code).'</div>'
       .'<p style="margin:16px 0 12px;line-height:1.6">Use this code on the Check Status page to view your reservation details and access your EntryPass QR code.</p>'
       .'<p style="margin:0 0 16px"><a href="'.htmlspecialchars($link).'" style="background:#23412e;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;display:inline-block">Open Status Page</a></p>'
       .'<p style="margin:18px 0 0;color:#555">Thank you for using VictorianPass.</p>'
       .'</div>'
       .'</div>'
       .'</div>';
  $ok=admin_send_email($to,$subject,$body);
  return ['ok'=>$ok,'err'=>$ok?null:'send_failed'];
}

function should_send_status_email($con,$refCode){
  if(!$refCode || !($con instanceof mysqli)) return false;
  $stmt=$con->prepare("SELECT approval_status, COALESCE(email_sent,0) AS email_sent FROM reservations WHERE ref_code = ? LIMIT 1");
  $stmt->bind_param('s',$refCode);
  $stmt->execute();
  $res=$stmt->get_result();
  $row=$res?$res->fetch_assoc():null;
  $stmt->close();
  $appr=strtolower($row['approval_status']??'');
  $sent=intval($row['email_sent']??0);
  return ($appr==='approved' && $sent===0);
}

function isAmenityPaymentVerified($con, $refCode){
  if(!$refCode) return false;
  $stmt = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
  $stmt->bind_param('s', $refCode);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  $ps = strtolower($row['payment_status'] ?? '');
  return $ps === 'verified';
}

// Ensure new guest_forms table exists for admin operations
ensureGuestFormsTable($con);
ensureGuestFormsWantsAmenityColumn($con);
ensureGuestFormsAmenityColumns($con);
ensureEmailStatusColumns($con);

// Handle AJAX request for user details (admin resident profile)
if (isset($_GET['action']) && $_GET['action'] == 'get_user_details' && isset($_GET['id'])) {
    $user_id = intval($_GET['id']);
    $stmt = $con->prepare("SELECT id, first_name, middle_name, last_name, email, phone, sex, birthdate, house_number, address, created_at, user_type, IFNULL(status,'active') as status FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    $stmt->close();
    exit;
}

// Handle AJAX request for visitor details (guest_forms first, legacy fallback)
if (isset($_GET['action']) && $_GET['action'] == 'get_visitor_details' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $source = isset($_GET['source']) ? $_GET['source'] : '';

    // Try new guest_forms source unless explicitly a reservation
    if ($source !== 'reservation') {
    $stmtGF = $con->prepare("SELECT gf.*, 
                                    u.first_name AS res_first_name, u.middle_name AS res_middle_name, u.last_name AS res_last_name,
                                    u.email AS res_email, u.phone AS res_phone, u.house_number AS res_house_number,
                                    r.payment_status AS r_payment_status, r.price AS r_price,
                                    r.amenity AS r_amenity, r.start_date AS r_start_date, r.end_date AS r_end_date,
                                    r.start_time AS r_start_time, r.end_time AS r_end_time, r.persons AS r_persons, r.ref_code AS r_ref_code
                             FROM guest_forms gf
                             LEFT JOIN users u ON gf.resident_user_id = u.id
                             LEFT JOIN reservations r ON r.ref_code = gf.ref_code
                             WHERE gf.id = ?");
    $stmtGF->bind_param('i', $id);
    $stmtGF->execute();
        $resGF = $stmtGF->get_result();
    if ($resGF && $row = $resGF->fetch_assoc()) {
        $isAmenity = (!empty($row['amenity'])) || (isset($row['wants_amenity']) && intval($row['wants_amenity']) === 1);
        $ps = null; $refCodeChk = $row['ref_code'] ?? null;
        if ($refCodeChk) {
          $stmtPayChk = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
          $stmtPayChk->bind_param('s', $refCodeChk);
          $stmtPayChk->execute(); $rpC = $stmtPayChk->get_result();
          if($rpC && ($prC=$rpC->fetch_assoc())){ $ps = strtolower($prC['payment_status'] ?? ''); }
          $stmtPayChk->close();
        }
        if ($isAmenity && $ps !== 'verified') { echo json_encode(['success'=>false,'message'=>'Payment not verified']); exit; }
        $details = [
            'id' => intval($row['id']),
            'user_id' => isset($row['resident_user_id']) ? intval($row['resident_user_id']) : null,
            'full_name' => $row['visitor_first_name'],
            'middle_name' => $row['visitor_middle_name'],
            'last_name' => $row['visitor_last_name'],
            'sex' => $row['visitor_sex'],
            'birthdate' => $row['visitor_birthdate'],
            'contact' => $row['visitor_contact'],
            'email' => $row['visitor_email'],
            'address' => $row['resident_house'],
            'valid_id_path' => $row['valid_id_path'],
            'entry_created' => $row['created_at'],
            'amenity' => $isAmenity ? ($row['r_amenity'] ?: ($row['amenity'] ?: 'Amenity Reservation')) : 'Guest Entry',
            'start_date' => $isAmenity ? ($row['r_start_date'] ?: ($row['start_date'] ?: $row['visit_date'])) : $row['visit_date'],
            'end_date' => $isAmenity ? ($row['r_end_date'] ?: ($row['end_date'] ?: $row['visit_date'])) : $row['visit_date'],
            'start_time' => ($row['r_start_time'] ?: ($row['start_time'] ?? null)),
            'end_time' => ($row['r_end_time'] ?: ($row['end_time'] ?? null)),
            'persons' => isset($row['r_persons']) && $row['r_persons']!==null ? intval($row['r_persons']) : (!empty($row['persons']) ? intval($row['persons']) : null),
            'purpose' => $row['purpose'],
            'price' => $isAmenity ? (isset($row['price']) ? floatval($row['price']) : (isset($row['r_price']) ? floatval($row['r_price']) : null)) : null,
            'payment_status' => isset($row['r_payment_status']) ? strtolower($row['r_payment_status']) : null,
            'ref_code' => ($row['r_ref_code'] ?: $row['ref_code']),
            'approval_status' => $row['approval_status'],
            'approved_by' => $row['approved_by'],
            'approval_date' => $row['approval_date'],
            'res_first_name' => $row['res_first_name'],
            'res_middle_name' => $row['res_middle_name'],
            'res_last_name' => $row['res_last_name'],
            'res_house_number' => $row['res_house_number'],
            'res_phone' => $row['res_phone'],
            'res_email' => $row['res_email']
        ];
        echo json_encode(['success' => true, 'details' => $details]);
        exit;
    }
    }

    // Legacy visitor flow: reservations + entry_passes
    $query = "SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate, 
                     ep.contact, ep.email, ep.address, ep.valid_id_path, ep.created_at as entry_created
              FROM reservations r 
              JOIN entry_passes ep ON r.entry_pass_id = ep.id 
              WHERE r.id = ? AND r.entry_pass_id IS NOT NULL";
    if ($source !== 'guest_form') {
        $stmt = $con->prepare($query);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $isAmenity = !empty($row['amenity']); $ps = strtolower($row['payment_status'] ?? '');
            if ($isAmenity && $ps !== 'verified') { echo json_encode(['success'=>false,'message'=>'Payment not verified']); exit; }
            echo json_encode(['success' => true, 'details' => $row]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Visitor details not found']);
    exit;
}

// Handle AJAX request for standard amenity reservation details
if (isset($_GET['action']) && $_GET['action'] == 'get_reservation_details' && isset($_GET['id'])) {
    $reservation_id = intval($_GET['id']);
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              WHERE r.id = ? AND (r.entry_pass_id IS NULL OR r.entry_pass_id = 0)";
    $stmt = $con->prepare($query);
    $stmt->bind_param('i', $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Reservation details not found']);
    }
    exit;
}

// Handle AJAX request for resident amenity reservation details
if (isset($_GET['action']) && $_GET['action'] == 'get_resident_reservation_details' && isset($_GET['id'])) {
    $rr_id = intval($_GET['id']);
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              WHERE r.id = ? AND (r.entry_pass_id IS NULL OR r.entry_pass_id = 0)";
    $stmt = $con->prepare($query);
    $stmt->bind_param('i', $rr_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Resident reservation not found']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_notifications') {
    $payments = getPendingPaymentCount($con);
    $awaiting = getAmenityAwaitingPaymentCount($con);
    $ready = getAmenityReadyForApprovalCount($con);
    $incidents = getOpenIncidentCount($con);
    $newreqs = getNewRequestsCount($con);
    $items = getRecentNotifications($con);
    header('Content-Type: application/json');
    echo json_encode([
        'payments' => $payments,
        'awaiting' => $awaiting,
        'ready' => $ready,
        'incidents' => $incidents,
        'new_requests' => $newreqs,
        'total' => ($payments + $awaiting + $ready + $incidents + $newreqs),
        'items' => $items
    ]);
    exit;
}

// Handle incident report status updates
if (isset($_POST['incident_action']) && isset($_POST['report_id'])) {
    $rid = intval($_POST['report_id']);
    $action = $_POST['incident_action'];
    $newStatus = null;
    if ($action === 'start') $newStatus = 'in_progress';
    elseif ($action === 'resolve') $newStatus = 'resolved';
    elseif ($action === 'reject') $newStatus = 'rejected';
    if ($newStatus) {
        $stmt = $con->prepare("UPDATE incident_reports SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $newStatus, $rid);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin.php?page=report");
    exit;
}

// Handle resident account actions (delete/deactivate/activate)
if (isset($_POST['user_action']) && isset($_POST['user_id'])) {
    $uid = intval($_POST['user_id']);
    $action = $_POST['user_action'];

    // Ensure users table has status column
    $check = $con->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE users ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active'");
    }

    if ($action === 'deactivate_user') {
        $stmt = $con->prepare("UPDATE users SET status='disabled' WHERE id = ?");
        $stmt->bind_param('i', $uid);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            echo "<script>alert('User has been deactivated.'); window.location.href='admin.php?page=residents';</script>";
        } else {
            echo "<script>alert('Failed to deactivate user.'); window.location.href='admin.php?page=residents';</script>";
        }
        exit;
    }
    if ($action === 'activate_user') {
        $stmt = $con->prepare("UPDATE users SET status='active' WHERE id = ?");
        $stmt->bind_param('i', $uid);
        $ok = $stmt->execute();
        $stmt->close();
        echo "<script>alert('User has been reactivated.'); window.location.href='admin.php?page=residents';</script>";
        exit;
    }
    if ($action === 'delete_user') {
        // Safely unlink reservations before deleting user
        $con->begin_transaction();
        try {
            $stmt1 = $con->prepare("UPDATE reservations SET user_id = NULL WHERE user_id = ?");
            $stmt1->bind_param('i', $uid);
            $stmt1->execute();
            $stmt1->close();
            
            $stmt2 = $con->prepare("DELETE FROM users WHERE id = ?");
            $stmt2->bind_param('i', $uid);
            $stmt2->execute();
            $stmt2->close();
            
            $con->commit();
            echo "<script>alert('User account deleted.'); window.location.href='admin.php?page=residents';</script>";
        } catch (Exception $e) {
            $con->rollback();
            echo "<script>alert('Failed to delete user: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='admin.php?page=residents';</script>";
        }
        exit;
    }
}

// Ensure admin session based on existing login.php (role-based)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_email = $_SESSION['email'] ?? '';
$admin_role = $_SESSION['role'] ?? '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Functions to get dashboard statistics
function getResidentCount($con) {
    $query = "SELECT COUNT(*) as count FROM users";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getActivePassesCount($con) {
    // Assuming you have a passes table or similar
    // Modify this query based on your actual database structure
    $query = "SELECT COUNT(*) as count FROM reservations WHERE end_date >= CURDATE()";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getPendingRequestsCount($con) {
    // Count pending visitor requests (entry pass reservations)
    $query = "SELECT COUNT(*) as count FROM reservations WHERE approval_status = 'pending' AND entry_pass_id IS NOT NULL";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getPaymentReceiptsCount($con) {
    // Count verified payments
    // Modify this query based on your actual database structure
    $query = "SELECT COUNT(*) as count FROM reservations WHERE payment_status = 'verified'";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getPendingPaymentCount($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE receipt_path IS NOT NULL AND (payment_status IS NULL OR payment_status = 'pending')";
  $r = $con->query($q);
  if($r){ $row = $r->fetch_assoc(); if($row){ return intval($row['c']); } }
  return 0;
}
function getAmenityAwaitingPaymentCount($con){
  $q = "SELECT COUNT(*) AS c
        FROM guest_forms gf
        LEFT JOIN reservations r ON r.ref_code = gf.ref_code
        WHERE gf.amenity IS NOT NULL AND gf.approval_status = 'pending'
          AND (r.payment_status IS NULL OR r.payment_status <> 'verified')";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getAmenityReadyForApprovalCount($con){
  $q = "SELECT COUNT(*) AS c
        FROM guest_forms gf
        LEFT JOIN reservations r ON r.ref_code = gf.ref_code
        WHERE gf.amenity IS NOT NULL AND gf.approval_status = 'pending'
          AND r.payment_status = 'verified'";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getOpenIncidentCount($con){
  $q = "SELECT COUNT(*) AS c FROM incident_reports WHERE status IN ('new','in_progress')";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getPendingResidentAmenityCount($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE (entry_pass_id IS NULL OR entry_pass_id = 0) AND amenity IS NOT NULL AND approval_status='pending'";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getPendingGuestFormCount($con){
  $q = "SELECT COUNT(*) AS c FROM guest_forms WHERE approval_status='pending'";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getPendingVisitorLegacyCount($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE entry_pass_id IS NOT NULL AND (approval_status='pending' OR (status IS NOT NULL AND status='pending'))";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getNewRequestsCount($con){
  return getPendingResidentAmenityCount($con) + getPendingGuestFormCount($con) + getPendingVisitorLegacyCount($con);
}
function getRecentNotifications($con){
  $items = [];
  $res = $con->query("SELECT id, ref_code, amenity, UNIX_TIMESTAMP(created_at) AS epoch, created_at FROM reservations WHERE receipt_path IS NOT NULL AND (payment_status IS NULL OR payment_status='pending') ORDER BY created_at DESC LIMIT 5");
  if($res){ while($row=$res->fetch_assoc()){ $items[] = ['type'=>'payment','source'=>'verify','title'=>'Receipt awaiting verification','ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
  $gf = $con->query("SELECT id, ref_code, amenity, UNIX_TIMESTAMP(created_at) AS epoch, created_at FROM guest_forms WHERE amenity IS NOT NULL AND approval_status='pending' ORDER BY created_at DESC LIMIT 5");
  if($gf){ while($row=$gf->fetch_assoc()){ $items[] = ['type'=>'amenity','source'=>'guest_form','title'=>'Amenity request pending payment','ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
  $gf2 = $con->query("SELECT gf.id, gf.ref_code, gf.amenity, UNIX_TIMESTAMP(gf.created_at) AS epoch, gf.created_at FROM guest_forms gf LEFT JOIN reservations r ON r.ref_code = gf.ref_code WHERE gf.amenity IS NOT NULL AND gf.approval_status='pending' AND r.payment_status='verified' ORDER BY gf.created_at DESC LIMIT 5");
  if($gf2){ while($row=$gf2->fetch_assoc()){ $items[] = ['type'=>'approval','source'=>'guest_form','title'=>'Amenity request ready for approval','ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
  $rr = $con->query("SELECT id, ref_code, amenity, UNIX_TIMESTAMP(created_at) AS epoch, created_at FROM reservations WHERE (entry_pass_id IS NULL OR entry_pass_id = 0) AND amenity IS NOT NULL AND approval_status='pending' ORDER BY created_at DESC LIMIT 5");
  if($rr){ while($row=$rr->fetch_assoc()){ $items[] = ['type'=>'request','source'=>'resident','title'=>'New resident amenity request','ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
  $legacy = $con->query("SELECT r.id, r.ref_code, r.amenity, UNIX_TIMESTAMP(r.created_at) AS epoch, r.created_at FROM reservations r WHERE r.entry_pass_id IS NOT NULL AND (r.approval_status='pending' OR (r.status IS NOT NULL AND r.status='pending')) ORDER BY r.created_at DESC LIMIT 5");
  if($legacy){ while($row=$legacy->fetch_assoc()){ $items[] = ['type'=>'request','source'=>'visitor','title'=>'New visitor request','ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
  $ir = $con->query("SELECT id, complainant, created_at, status FROM incident_reports ORDER BY created_at DESC LIMIT 5");
  if($ir){ while($row=$ir->fetch_assoc()){ $items[] = ['type'=>'incident','source'=>'report','title'=>'Incident: '.$row['status'],'ref'=>null,'amenity'=>null,'time'=>$row['created_at'],'epoch'=>intval(strtotime($row['created_at']))]; } }
  usort($items,function($a,$b){ return strcmp($b['time'],$a['time']); });
  return array_slice($items,0,8);
}

// Functions to get data for different sections
function getResidents($con) {
    $query = "SELECT * FROM users ORDER BY created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

function getReservations($con) {
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

// Resident amenity reservations (resident_reservations table)
function getResidentReservations($con) {
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.house_number, u.email, u.phone
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              WHERE (r.entry_pass_id IS NULL OR r.entry_pass_id = 0) AND r.amenity IS NOT NULL
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

// Guest amenity reservations (reservations with entry_pass_id and amenity)
function getGuestAmenityReservations($con) {
    $query = "SELECT gf.*, gf.id AS gf_id,
                     gf.visitor_first_name AS full_name, gf.visitor_middle_name AS middle_name, gf.visitor_last_name AS last_name,
                     u.house_number AS res_house_number
              FROM guest_forms gf
              LEFT JOIN users u ON gf.resident_user_id = u.id
              WHERE gf.amenity IS NOT NULL
              ORDER BY gf.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getSecurityGuards($con) {
    $query = "SELECT * FROM staff WHERE role = 'guard'";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

function getIncidentReports($con) {
    $query = "SELECT ir.*, u.first_name, u.middle_name, u.last_name FROM incident_reports ir LEFT JOIN users u ON ir.user_id = u.id ORDER BY ir.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getIncidentProofs($con, $reportId) {
    $stmt = $con->prepare("SELECT file_path FROM incident_proofs WHERE report_id = ? ORDER BY uploaded_at ASC");
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $res = $stmt->get_result();
    $files = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) { $files[] = $row['file_path']; }
    }
    $stmt->close();
    return $files;
}

// Function to get visitor requests with personal details
function getVisitorRequests($con) {
    $query = "SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate, 
                     ep.contact, ep.address, ep.valid_id_path, ep.created_at as entry_created
              FROM reservations r 
              JOIN entry_passes ep ON r.entry_pass_id = ep.id 
              WHERE r.entry_pass_id IS NOT NULL 
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

// Split visitor-related requests by source
function getResidentVisitorRequests($con) {
    // Link guest forms to reservations via ref_code; amenity only when a reservation exists
    $query = "SELECT gf.*, 
                     gf.visitor_first_name AS full_name, gf.visitor_middle_name AS middle_name, gf.visitor_last_name AS last_name,
                     r.amenity AS amenity, COALESCE(r.persons, gf.persons) AS persons, u.house_number AS res_house_number
              FROM guest_forms gf
              LEFT JOIN reservations r ON r.ref_code = gf.ref_code
              LEFT JOIN users u ON gf.resident_user_id = u.id
              WHERE gf.resident_user_id IS NOT NULL
              ORDER BY gf.created_at DESC";
    $res = $con->query($query);
    if ($res && $res->num_rows > 0) return $res;
    // Fallback: legacy reservations + entry_passes
    $legacy = $con->query("SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate,
                                  ep.contact, ep.email, ep.address, ep.valid_id_path, ep.created_at as entry_created,
                                  u.house_number AS res_house_number, r.amenity, r.persons
                           FROM reservations r
                           JOIN entry_passes ep ON r.entry_pass_id = ep.id
                           LEFT JOIN users u ON r.user_id = u.id
                           WHERE r.entry_pass_id IS NOT NULL AND r.user_id IS NOT NULL
                           ORDER BY r.created_at DESC");
    return $legacy ?: false;
}

function getVisitorOnlyRequests($con) {
    $legacy = $con->query("SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate,
                                  ep.contact, ep.email, ep.address, ep.valid_id_path, ep.created_at as entry_created
                           FROM reservations r
                           JOIN entry_passes ep ON r.entry_pass_id = ep.id
                           WHERE r.entry_pass_id IS NOT NULL AND (r.user_id IS NULL OR r.user_id = 0)
                           ORDER BY r.created_at DESC");
    return $legacy ?: false;
}

// Add: ensure reservations has a status column and auto-expire old reservations
function ensureReservationStatusColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'status'");
    if ($check && $check->num_rows === 0) {
        // Create a status column with sensible defaults
        $con->query("ALTER TABLE reservations ADD COLUMN status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending'");
    }
}

function autoExpireReservations($con) {
    // Mark reservations expired when past end_date
    $con->query("UPDATE reservations SET status='expired' WHERE end_date < CURDATE() AND status <> 'expired'");
}

// Ensure incident-related tables exist to prevent runtime errors
function ensureIncidentTables($con) {
    $con->query("CREATE TABLE IF NOT EXISTS incident_reports (
      id INT AUTO_INCREMENT PRIMARY KEY,
      complainant VARCHAR(150) NOT NULL,
      address VARCHAR(255) NOT NULL,
      nature VARCHAR(255) NULL,
      other_concern VARCHAR(255) NULL,
      user_id INT NULL,
      status ENUM('new','in_progress','resolved','rejected') DEFAULT 'new',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL,
      INDEX idx_status (status),
      INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB");

    $con->query("CREATE TABLE IF NOT EXISTS incident_proofs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      report_id INT NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_report_id (report_id)
    ) ENGINE=InnoDB");
}

ensureReservationStatusColumn($con);
autoExpireReservations($con);
ensureIncidentTables($con);
// Ensure resident reservations have necessary columns
function ensureResidentApprovalColumns($con) {
    $check1 = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'approved_by'");
    if ($check1 && $check1->num_rows === 0) {
        $con->query("ALTER TABLE resident_reservations ADD COLUMN approved_by INT NULL AFTER approval_status");
    }
    $check2 = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'approval_date'");
    if ($check2 && $check2->num_rows === 0) {
        $con->query("ALTER TABLE resident_reservations ADD COLUMN approval_date DATETIME NULL AFTER approved_by");
    }
}
ensureResidentApprovalColumns($con);
ensureResidentReservationQrColumn($con);

// Ensure users table has a status column to support deactivation
function ensureUsersStatusColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE users ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active'");
    }
}
ensureUsersStatusColumn($con);

// Ensure new guest_forms table and its QR column exist
function ensureGuestFormsTable($con) {
    $con->query("CREATE TABLE IF NOT EXISTS guest_forms (
      id INT AUTO_INCREMENT PRIMARY KEY,
      resident_user_id INT NULL,
      resident_house VARCHAR(100) NULL,
      resident_email VARCHAR(150) NULL,
      visitor_first_name VARCHAR(100) NOT NULL,
      visitor_middle_name VARCHAR(100) NULL,
      visitor_last_name VARCHAR(100) NOT NULL,
      visitor_sex VARCHAR(20) NULL,
      visitor_birthdate DATE NULL,
      visitor_contact VARCHAR(50) NULL,
      visitor_email VARCHAR(150) NULL,
      valid_id_path VARCHAR(255) NULL,
      visit_date DATE NULL,
      visit_time VARCHAR(20) NULL,
      purpose VARCHAR(255) NULL,
      wants_amenity TINYINT(1) NOT NULL DEFAULT 0,
      persons INT NULL,
      ref_code VARCHAR(50) NOT NULL UNIQUE,
      approval_status ENUM('pending','approved','denied') DEFAULT 'pending',
      approved_by INT NULL,
      approval_date DATETIME NULL,
      qr_path VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL,
      INDEX idx_resident_user_id (resident_user_id),
      INDEX idx_ref_code (ref_code)
    ) ENGINE=InnoDB");
}

// Ensure amenity preference column exists even if table was created earlier
function ensureGuestFormsWantsAmenityColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'wants_amenity'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE guest_forms ADD COLUMN wants_amenity TINYINT(1) NOT NULL DEFAULT 0 AFTER purpose");
    }
}

function ensureGuestFormsAmenityColumns($con) {
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

function generateQrForGuestForm($con, $gfId) {
    $gfId = intval($gfId);
    if ($gfId <= 0) return;

    // Fetch guest form
    $stmt = $con->prepare("SELECT ref_code FROM guest_forms WHERE id = ?");
    $stmt->bind_param('i', $gfId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !$res->num_rows) { $stmt->close(); return; }
    $row = $res->fetch_assoc();
    $stmt->close();

    $ref = $row['ref_code'] ?? ('GF-' . $gfId);

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/');
    $statusLink = $scheme . '://' . $host . $basePath . '/status_view.php?code=' . urlencode($ref);

    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);
    $img = @file_get_contents($qrUrl);
    if ($img === false) return;

    $relPath = 'uploads/qr_guest_' . $gfId . '.png';
    $absPath = __DIR__ . '/' . $relPath;
    @file_put_contents($absPath, $img);

    $stmt2 = $con->prepare("UPDATE guest_forms SET qr_path = ? WHERE id = ?");
    $stmt2->bind_param('si', $relPath, $gfId);
    $stmt2->execute();
    $stmt2->close();
}

// Ensure reservations has a QR path column
function ensureReservationQrColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'qr_path'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN qr_path VARCHAR(255) NULL AFTER receipt_path");
    }
}

// Ensure resident_reservations has a QR path column
function ensureResidentReservationQrColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'qr_path'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE resident_reservations ADD COLUMN qr_path VARCHAR(255) NULL AFTER updated_at");
    }
}

// Generate and store QR code for a reservation
function generateQrForReservation($con, $reservationId) {
    $reservationId = intval($reservationId);
    if ($reservationId <= 0) return;

    ensureReservationQrColumn($con);
    // Fetch reservation details
    $stmt = $con->prepare("SELECT ref_code, start_date, end_date FROM reservations WHERE id = ?");
    $stmt->bind_param('i', $reservationId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !$res->num_rows) { $stmt->close(); return; }
    $row = $res->fetch_assoc();
    $stmt->close();

    $ref = $row['ref_code'] ?? ('RES-' . $reservationId);
    $start = isset($row['start_date']) ? $row['start_date'] : '';
    $end   = isset($row['end_date']) ? $row['end_date'] : '';

    // Build a direct status URL so scanners open the details page
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/');
    $statusLink = $scheme . '://' . $host . $basePath . '/status_view.php?code=' . urlencode($ref);

    // Generate QR for the status link
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);
    $img = @file_get_contents($qrUrl);
    if ($img === false) return; // fail silently

    $relPath = 'uploads/qr_reservation_' . $reservationId . '.png';
    $absPath = __DIR__ . '/' . $relPath;
    @file_put_contents($absPath, $img);

    // Update reservation with QR path
    $stmt2 = $con->prepare("UPDATE reservations SET qr_path = ? WHERE id = ?");
    $stmt2->bind_param('si', $relPath, $reservationId);
    $stmt2->execute();
    $stmt2->close();
}

// Generate and store QR code for a resident reservation
function generateQrForResidentReservation($con, $rrId) {
    $rrId = intval($rrId);
    if ($rrId <= 0) return;

    ensureResidentReservationQrColumn($con);

    $stmt = $con->prepare("SELECT ref_code FROM resident_reservations WHERE id = ?");
    $stmt->bind_param('i', $rrId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !$res->num_rows) { $stmt->close(); return; }
    $row = $res->fetch_assoc();
    $stmt->close();

    $ref = $row['ref_code'] ?? ('RR-' . $rrId);

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/');
    $statusLink = $scheme . '://' . $host . $basePath . '/status_view.php?code=' . urlencode($ref);

    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);
    $img = @file_get_contents($qrUrl);
    if ($img === false) return;

    $relPath = 'uploads/qr_resident_' . $rrId . '.png';
    $absPath = __DIR__ . '/' . $relPath;
    @file_put_contents($absPath, $img);

    $stmt2 = $con->prepare("UPDATE resident_reservations SET qr_path = ? WHERE id = ?");
    $stmt2->bind_param('si', $relPath, $rrId);
    $stmt2->execute();
    $stmt2->close();
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        // Handle visitor request approval/denial (guest_forms first)
        if ($action == 'approve_request' || $action == 'deny_request') {
            $reservation_id = intval($_POST['reservation_id']);
            $approval_status = ($action == 'approve_request') ? 'approved' : 'denied';
            $staff_id = $_SESSION['staff_id'] ?? null;
            $conflict = false; $amenity = ''; $start = ''; $end = ''; $st = ''; $et = '';

            // Try updating guest_forms
            $stmtGFCheck = $con->prepare("SELECT id FROM guest_forms WHERE id = ?");
            $stmtGFCheck->bind_param('i', $reservation_id);
            $stmtGFCheck->execute();
            $resGFCheck = $stmtGFCheck->get_result();
            if ($resGFCheck && $resGFCheck->num_rows > 0) {
                // Load details for conflict check
                $stmtInfo = $con->prepare("SELECT amenity, start_date, end_date, start_time, end_time FROM guest_forms WHERE id = ?");
                $stmtInfo->bind_param('i', $reservation_id);
                $stmtInfo->execute(); $resInfo = $stmtInfo->get_result();
                if($resInfo && ($row=$resInfo->fetch_assoc())){ $amenity=$row['amenity']??''; $start=$row['start_date']??''; $end=$row['end_date']??''; $st=$row['start_time']??''; $et=$row['end_time']??''; }
                $stmtInfo->close();
                if ($approval_status === 'approved' && $amenity && $start && $end) {
                    $singleDay = ($start === $end && $st && $et);
                    $cnt = 0;
                    if ($singleDay) {
                        $stmt1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
                        $stmt1->bind_param('ssss', $amenity, $start, $st, $et); $stmt1->execute(); $r1=$stmt1->get_result(); $cnt+=($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $stmt1->close();
                        $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
                        $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
                        if ($hasRt && $hasRt->num_rows>0 && $hasRe && $hasRe->num_rows>0) {
                            $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
                            $stmt2->bind_param('ssss',$amenity,$start,$st,$et);
                        } else {
                            // No time columns; skip time-based conflict for single-day
                            $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE 0=1");
                        }
                        $stmt2->execute(); $r2=$stmt2->get_result(); $cnt+=($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $stmt2->close();
                        $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
                        $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
                        if ($hasGt && $hasGt->num_rows>0 && $hasGe && $hasGe->num_rows>0) {
                            $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (approval_status IN ('pending','approved')) AND (TIME(?) < end_time AND TIME(?) > start_time)");
                            $stmt3->bind_param('ssss',$amenity,$start,$st,$et);
                        } else {
                            $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE 0=1");
                        }
                        $stmt3->execute(); $r3=$stmt3->get_result(); $cnt+=($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $stmt3->close();
                    } else {
                        $stmt1=$con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND start_date <= ? AND end_date >= ?");
                        $stmt1->bind_param('sss',$amenity,$end,$start); $stmt1->execute(); $r1=$stmt1->get_result(); $cnt+=($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $stmt1->close();
                        $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND start_date <= ? AND end_date >= ?");
                        $stmt2->bind_param('sss',$amenity,$end,$start); $stmt2->execute(); $r2=$stmt2->get_result(); $cnt+=($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $stmt2->close();
                        $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND start_date <= ? AND end_date >= ? AND (approval_status IN ('pending','approved'))");
                        $stmt3->bind_param('sss',$amenity,$end,$start); $stmt3->execute(); $r3=$stmt3->get_result(); $cnt+=($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $stmt3->close();
                    }
                    $conflict = ($cnt > 0);
                    // Do NOT override approval_status. Just warn admin via redirect if conflict.
                }
                $stmtUp = $con->prepare("UPDATE guest_forms SET approval_status = ?, approved_by = ?, approval_date = NOW() WHERE id = ?");
                $stmtUp->bind_param('sii', $approval_status, $staff_id, $reservation_id);
                $stmtUp->execute();
                $stmtUp->close();
                if ($approval_status === 'approved') {
                    generateQrForGuestForm($con, $reservation_id);
                }
            } else {
                // Legacy: Update reservation approval status
                // Load details for conflict check
                $stmtInfo = $con->prepare("SELECT amenity, start_date, end_date, start_time, end_time FROM reservations WHERE id = ?");
                $stmtInfo->bind_param('i', $reservation_id);
                $stmtInfo->execute(); $resInfo = $stmtInfo->get_result();
                if($resInfo && ($row=$resInfo->fetch_assoc())){ $amenity=$row['amenity']??''; $start=$row['start_date']??''; $end=$row['end_date']??''; $st=$row['start_time']??''; $et=$row['end_time']??''; }
                $stmtInfo->close();
                if ($approval_status === 'approved' && $amenity && $start && $end) {
                    $singleDay = ($start === $end && $st && $et);
                    $cnt = 0;
                    if ($singleDay) {
                        $stmt1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
                        $stmt1->bind_param('ssss', $amenity, $start, $st, $et); $stmt1->execute(); $r1=$stmt1->get_result(); $cnt+=($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $stmt1->close();
                        $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
                        $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
                        if ($hasRt && $hasRt->num_rows>0 && $hasRe && $hasRe->num_rows>0) {
                            $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
                            $stmt2->bind_param('ssss',$amenity,$start,$st,$et);
                        } else {
                            $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE 0=1");
                        }
                        $stmt2->execute(); $r2=$stmt2->get_result(); $cnt+=($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $stmt2->close();
                        $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
                        $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
                        if ($hasGt && $hasGt->num_rows>0 && $hasGe && $hasGe->num_rows>0) {
                            $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (approval_status IN ('pending','approved')) AND (TIME(?) < end_time AND TIME(?) > start_time)");
                            $stmt3->bind_param('ssss',$amenity,$start,$st,$et);
                        } else {
                            $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE 0=1");
                        }
                        $stmt3->execute(); $r3=$stmt3->get_result(); $cnt+=($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $stmt3->close();
                    } else {
                        $stmt1=$con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND start_date <= ? AND end_date >= ?");
                        $stmt1->bind_param('sss',$amenity,$end,$start); $stmt1->execute(); $r1=$stmt1->get_result(); $cnt+=($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $stmt1->close();
                        $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND start_date <= ? AND end_date >= ?");
                        $stmt2->bind_param('sss',$amenity,$end,$start); $stmt2->execute(); $r2=$stmt2->get_result(); $cnt+=($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $stmt2->close();
                        $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND start_date <= ? AND end_date >= ? AND (approval_status IN ('pending','approved'))");
                        $stmt3->bind_param('sss',$amenity,$end,$start); $stmt3->execute(); $r3=$stmt3->get_result(); $cnt+=($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $stmt3->close();
                    }
                    $conflict = ($cnt > 0);
                    // Do NOT override approval_status. Just warn admin via redirect if conflict.
                }
                // Enforce payment verification before acting on amenity requests
                $stmtCheck = $con->prepare("SELECT amenity, payment_status, ref_code FROM reservations WHERE id = ? LIMIT 1");
                $stmtCheck->bind_param('i', $reservation_id); $stmtCheck->execute(); $resChk = $stmtCheck->get_result();
                $refCodeRes = null; $psRes = null; $amenRes = null;
                if($resChk && ($rwC=$resChk->fetch_assoc())){ $amenRes = $rwC['amenity'] ?? ''; $psRes = strtolower($rwC['payment_status'] ?? ''); $refCodeRes = $rwC['ref_code'] ?? null; }
                $stmtCheck->close();
                if (!empty($amenRes) && $psRes !== 'verified') {
                  header("Location: admin.php?page=visitor_requests&msg=payment_required");
                  exit;
                }
                $query = "UPDATE reservations SET approval_status = ?, approved_by = ?, approval_date = NOW() WHERE id = ?";
                $stmt = $con->prepare($query);
                $stmt->bind_param("sii", $approval_status, $staff_id, $reservation_id);
                $stmt->execute();
                $stmt->close();
                if ($approval_status === 'approved') {
                    generateQrForReservation($con, $reservation_id);
                }
            }

            // Redirect to prevent form resubmission
            header("Location: admin.php?page=visitor_requests" . ($conflict ? "&msg=time_conflict" : ""));
            exit;
        }
        
        // Handle reservation approval/rejection
        if ($action == 'approve_reservation' || $action == 'reject_reservation') {
            $reservation_id = $_POST['reservation_id'];
            $status = ($action == 'approve_reservation') ? 'approved' : 'rejected';
            
            // Update reservation status (column ensured above)
            $query = "UPDATE reservations SET status = ? WHERE id = ?";
            $stmt = $con->prepare($query);
            $stmt->bind_param("si", $status, $reservation_id);
            $stmt->execute();

            // Generate QR code upon approval
            if ($status === 'approved') {
                generateQrForReservation($con, intval($reservation_id));
            }
            
            // Redirect to prevent form resubmission
            header("Location: admin.php?page=requests");
            exit;
        }

        // Handle deletion of denied/rejected reservations or guest_forms
        if ($action == 'delete_reservation') {
            $reservation_id = intval($_POST['reservation_id'] ?? 0);
            if ($reservation_id > 0) {
                // Prefer guest_forms
                $stmtGF = $con->prepare("SELECT approval_status FROM guest_forms WHERE id = ?");
                $stmtGF->bind_param('i', $reservation_id);
                $stmtGF->execute();
                $resGF = $stmtGF->get_result();
                $stmtGF->close();
                if ($resGF && $rowGF = $resGF->fetch_assoc()) {
                    if (($rowGF['approval_status'] ?? '') === 'denied') {
                        $stmtDelGF = $con->prepare("DELETE FROM guest_forms WHERE id = ?");
                        $stmtDelGF->bind_param('i', $reservation_id);
                        $stmtDelGF->execute();
                        $stmtDelGF->close();
                    }
                } else {
                    // Legacy reservation path
                    $stmt = $con->prepare("SELECT id, entry_pass_id, approval_status, status FROM reservations WHERE id = ?");
                    $stmt->bind_param('i', $reservation_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $row = $res->fetch_assoc()) {
                        $isDenied = (isset($row['approval_status']) && $row['approval_status'] === 'denied') || (isset($row['status']) && $row['status'] === 'rejected');
                        if ($isDenied) {
                            $entryId = intval($row['entry_pass_id'] ?? 0);
                            if ($entryId > 0) {
                                $stmtDelEP = $con->prepare("DELETE FROM entry_passes WHERE id = ?");
                                $stmtDelEP->bind_param('i', $entryId);
                                $stmtDelEP->execute();
                                $stmtDelEP->close();
                            }
                            $stmtDelR = $con->prepare("DELETE FROM reservations WHERE id = ?");
                            $stmtDelR->bind_param('i', $reservation_id);
                            $stmtDelR->execute();
                            $stmtDelR->close();
                        }
                    }
                    $stmt->close();
                }
            }
            header("Location: admin.php?page=requests");
            exit;
        }

        // Handle resident reservation approval/denial (unified reservations)
        if ($action == 'approve_resident_reservation' || $action == 'deny_resident_reservation') {
            $rr_id = intval($_POST['rr_id'] ?? 0);
            $approval_status = ($action == 'approve_resident_reservation') ? 'approved' : 'denied';
            $staff_id = $_SESSION['staff_id'] ?? null;

            if ($rr_id > 0) {
                // Ensure reservations has approval metadata
                $c1 = $con->query("SHOW COLUMNS FROM reservations LIKE 'approved_by'");
                if($c1 && $c1->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN approved_by INT NULL"); }
                $c2 = $con->query("SHOW COLUMNS FROM reservations LIKE 'approval_date'");
                if($c2 && $c2->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN approval_date DATETIME NULL"); }

                $stmt = $con->prepare("UPDATE reservations SET approval_status = ?, approved_by = ?, approval_date = NOW() WHERE id = ? AND (entry_pass_id IS NULL OR entry_pass_id = 0)");
                $stmt->bind_param('sii', $approval_status, $staff_id, $rr_id);
                $stmt->execute();
                $stmt->close();

                if ($approval_status === 'approved') {
                    generateQrForReservation($con, $rr_id);
                }
            }
            header("Location: admin.php?page=reservations");
            exit;
        }

        // Handle deletion of denied resident reservations (unified reservations)
        if ($action == 'delete_resident_reservation') {
            $rr_id = intval($_POST['rr_id'] ?? 0);
            if ($rr_id > 0) {
                // Only allow deletion when denied
                $stmt = $con->prepare("SELECT approval_status FROM reservations WHERE id = ? AND (entry_pass_id IS NULL OR entry_pass_id = 0) ");
                $stmt->bind_param('i', $rr_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $row = $res->fetch_assoc()) {
                    if (($row['approval_status'] ?? '') === 'denied') {
                        $stmtDel = $con->prepare("DELETE FROM reservations WHERE id = ?");
                        $stmtDel->bind_param('i', $rr_id);
                        $stmtDel->execute();
                        $stmtDel->close();
                    }
                }
                $stmt->close();
            }
            header("Location: admin.php?page=reservations");
            exit;
        }

        // Handle receipt verification
        if ($action == 'verify_receipt' || $action == 'reject_receipt') {
            $reservation_id = $_POST['reservation_id'];
            $payment_status = ($action == 'verify_receipt') ? 'verified' : 'rejected';
            $staff_id = $_SESSION['staff_id'] ?? null;
            
            // Update payment status
            $query = "UPDATE reservations SET payment_status = ?, verified_by = ?, verification_date = NOW() WHERE id = ?";
            $stmt = $con->prepare($query);
            $stmt->bind_param("sii", $payment_status, $staff_id, $reservation_id);
            $stmt->execute();
            
            $refCode = null; $entryId = null; $amenityName = null;
            $stmtInfo = $con->prepare("SELECT ref_code, entry_pass_id, amenity, approval_status FROM reservations WHERE id = ? LIMIT 1");
            $stmtInfo->bind_param('i', $reservation_id);
            $stmtInfo->execute(); $resInfo = $stmtInfo->get_result();
            $approvedNow=false; $approvalStatusRes=null;
            if($resInfo && ($rw=$resInfo->fetch_assoc())){ $refCode = $rw['ref_code'] ?? null; $entryId = $rw['entry_pass_id'] ?? null; $amenityName = $rw['amenity'] ?? null; $approvalStatusRes = $rw['approval_status'] ?? null; }
            $stmtInfo->close();
            if ($payment_status === 'verified' && $refCode) {
            }
            $redirect = 'admin.php?page=verify';
            if ($payment_status === 'verified' && !empty($refCode)) {
              $redirectPage = ($entryId) ? 'visitor_requests' : 'reservations';
              $redirect = 'admin.php?page=' . $redirectPage . '&ref=' . urlencode($refCode);
            }
            header("Location: $redirect");
            exit;
        }
    }
}

// Get current page from URL parameter or default to dashboard
$currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>VictorianPass | Admin</title>
<link rel="icon" type="image/png" href="images/logo.svg">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root{
  --bg-dark:#2b2623;
  --nav-cream:#f4efe6;
  --nav-cream-active:#e8dfca;
  --accent:#23412e;
  --header-beige:#f7efe3;
  --card:#ffffff;
  --muted:#8b918d;
  --status-active:#2f80ed;
  --status-approved:#27ae60;
  --status-pending:#6c5ce7;
  --status-expired:#95a5a6;
  --status-rejected:#e74c3c;
  --shadow:0 8px 18px rgba(0,0,0,0.08);
}
*{box-sizing:border-box;font-family:'Poppins',sans-serif;}
body{margin:0;background:#f3efe9;color:#222;overflow-x:hidden;}
.app{display:flex;min-height:100vh;max-width:100vw;overflow-x:hidden;}

/* Sidebar */
.sidebar{width:220px;background:var(--bg-dark);color:#fff;display:flex;flex-direction:column;}
.brand{padding:20px;border-bottom:3px solid rgba(255,255,255,0.07);display:flex;gap:12px;align-items:center;}
.brand img{height:52px;}
.brand .title{display:flex;flex-direction:column;color:var(--nav-cream);}
.brand .title h1{margin:0;font-size:1.05rem;font-weight:700;}
.brand .title p{margin:0;font-size:0.78rem;color:#d6cfc2;}
.nav-list{margin:20px 12px;display:flex;flex-direction:column;gap:12px;}
.nav-item{
  background:var(--nav-cream);color:var(--accent);padding:12px 14px;border-radius:0 20px 20px 0;
  font-weight:600;font-size:0.9rem;display:flex;align-items:center;gap:12px;cursor:pointer;
  transition:transform .12s ease,background-color .12s ease;
}
.nav-item img{width:20px;height:20px;}
.nav-item:hover{transform:translateX(4px);background:#efe7d6;}
.nav-item.active{background:var(--nav-cream-active);box-shadow:0 6px 14px rgba(0,0,0,0.06) ; }
.nav-item {text-decoration: none;}
.sidebar-footer{margin-top:auto;padding:18px;color:#bfb7aa;font-size:0.84rem;}

/* Main */
.main{flex:1;padding:20px 28px;display:flex;flex-direction:column;gap:18px;max-width:100%;
  background:linear-gradient(180deg,#f7f3ec 0%,#f3efe9 100%);overflow-x:hidden;
}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--header-beige);
  padding:14px 18px;border-radius:10px;box-shadow:var(--shadow);position:sticky;top:0;z-index:9;} 
.header h2{margin:0;font-weight:700;}
.search{flex:1;margin:0 18px;display:flex;align-items:center;background:#fff;padding:10px;border-radius:999px;
  box-shadow:0 3px 8px rgba(0,0,0,0.04);} 
.search input{border:0;background:transparent;outline:none;font-size:0.95rem;width:100%;}
.avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;border:3px solid #fff;}
.header-actions{display:flex;align-items:center;gap:12px}

.notifications{position:relative;display:flex;align-items:center;gap:10px;margin-right:8px}
.notif-btn{position:relative;background:#23412e;color:#fff;border:0;border-radius:10px;padding:8px 12px;font-weight:600;cursor:pointer;box-shadow:var(--shadow)}
.notif-btn img{display:block;width:22px;height:22px}
.notif-badge{position:absolute;top:-6px;right:-6px;background:#e74c3c;color:#fff;border-radius:999px;padding:2px 6px;font-size:0.75rem;font-weight:700}
.notif-badge.pulse{animation:notifPulse 0.8s ease}
@keyframes notifPulse{0%{transform:scale(1);box-shadow:0 0 0 0 rgba(231,76,60,0.7)}70%{transform:scale(1.15);box-shadow:0 0 0 12px rgba(231,76,60,0)}100%{transform:scale(1)}}
.notif-panel{position:absolute;top:48px;right:0;background:#fff;border:1px solid #e0e0e0;border-radius:10px;box-shadow:0 10px 24px rgba(0,0,0,0.15);min-width:320px;max-width:min(380px,calc(100vw - 24px));z-index:1000;display:none}
.notif-item{padding:10px 12px;border-bottom:1px solid #f0f0f0;display:flex;align-items:flex-start;gap:10px}
.notif-item:last-child{border-bottom:0}
.notif-item-link{display:flex;align-items:flex-start;gap:10px;color:inherit;text-decoration:none;width:100%}
.notif-item-link:hover{background:#f7f7f7;border-radius:8px}
.notif-type{font-size:0.75rem;font-weight:700;color:#23412e;background:#f0f4f2;border-radius:6px;padding:2px 6px}
.notif-meta{font-size:0.85rem;color:#555}
.notif-actions{display:flex;gap:8px;padding:10px;border-top:1px solid #f0f0f0}
 .notif-dismiss{margin-left:auto;background:transparent;border:0;color:#888;font-weight:700;cursor:pointer;padding:4px 8px;border-radius:6px}
 .notif-dismiss:hover{color:#a83b3b;background:#f6f6f6}

.panel{background:var(--card);border-radius:12px;padding:16px;box-shadow:var(--shadow);max-width:100%;overflow-x:auto}
.panel h3{margin:0 0 12px 0;font-size:1.05rem;font-weight:600;}
.table{width:100%;border-collapse:collapse;table-layout:auto;font-size:0.9rem;line-height:1.3}
  .table thead th{padding:8px 10px;background:#fbfbfb;color:#6b6b6b;text-align:left;font-weight:600;border-bottom:1px solid #eee;word-break:break-word;white-space:normal;vertical-align:middle}
  .table td{padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle;word-break:break-word;white-space:normal}
.table thead th,.table td{overflow-wrap:anywhere}
.table tbody tr:hover{background:#f9fafb}
.row-highlight{background:#eaf7ea;outline:2px solid var(--status-approved)}
.table img.avatar-xs{width:36px;height:36px;border-radius:50%;object-fit:cover;margin-right:10px;vertical-align:middle}

/* Verify Receipts specific column widths */
.table-verify thead th:nth-child(1),.table-verify td:nth-child(1){width:11%}
.table-verify thead th:nth-child(2),.table-verify td:nth-child(2){width:17%}
.table-verify thead th:nth-child(3),.table-verify td:nth-child(3){width:15%}
.table-verify thead th:nth-child(4),.table-verify td:nth-child(4){width:15%}
.table-verify thead th:nth-child(5),.table-verify td:nth-child(5){width:11%}
.table-verify thead th:nth-child(6),.table-verify td:nth-child(6){width:15%}
.table-verify thead th:nth-child(7),.table-verify td:nth-child(7){width:16%}
.table-verify thead th:nth-child(1),.table-verify td:nth-child(1){text-align:center}
.table-verify thead th:nth-child(2),.table-verify td:nth-child(2){text-align:left}
.table-verify thead th:nth-child(3),.table-verify td:nth-child(3){text-align:left}
.table-verify thead th:nth-child(4),.table-verify td:nth-child(4){text-align:center}
.table-verify thead th:nth-child(5),.table-verify td:nth-child(5){text-align:center}
.table-verify thead th:nth-child(6),.table-verify td:nth-child(6){text-align:center}
.table-verify thead th:nth-child(7),.table-verify td:nth-child(7){text-align:center}
.table-verify td:nth-child(5) .receipt-thumbnail{display:block;margin:0 auto}
.table-verify td:nth-child(5) .receipt-link{display:inline-block}

.badge{display:inline-block;padding:6px 12px;border-radius:999px;font-weight:600;font-size:0.82rem;color:#fff;}
.modal{position:fixed;left:0;top:0;width:100%;height:100%;background-color:rgba(0,0,0,0.5);z-index:1000;display:none}
.modal .modal-content{background:#fff;margin:5% auto;padding:16px;border:1px solid #ddd;width:min(90vw,800px);max-height:85vh;overflow:auto;border-radius:10px}
.modal .close{color:#888;float:right;font-size:26px;font-weight:700;cursor:pointer}
.modal .modal-content h3{margin-top:0;color:#23412e}
.modal .modal-content img{width:100%;height:auto;border-radius:8px}
.badge-active{background:var(--status-active)}
.badge-approved{background:var(--status-approved)}
.badge-pending{background:var(--status-pending)}
.badge-expired{background:var(--status-expired)}
.badge-rejected{background:var(--status-rejected)}

.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.actions > *{display:inline-flex}
.actions .btn{flex:0 0 auto;min-width:100px;margin-bottom:0;white-space:nowrap}
.table td.actions{min-width:240px}
.btn{min-height:30px;padding:6px 10px;border-radius:6px;border:0;font-weight:600;cursor:pointer;font-size:0.8rem;text-decoration:none}
.btn-view{background:#23412e;color:#fff}
.btn-approve{background:var(--status-approved);color:#fff}
.btn-reject{background:var(--status-rejected);color:#fff}
.btn-edit{background:#2f80ed;color:#fff}
.btn-remove{background:#a83b3b;color:#fff}
.btn-payverify{background:#23412e;color:#fff}
.btn-disabled{background:#ccc;color:#666;cursor:not-allowed}

.receipt-thumbnail{width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #ddd;cursor:pointer;transition:transform 0.2s;}
.receipt-thumbnail:hover{transform:scale(1.1);}
.receipt-link{text-decoration:none;}

.muted{color:var(--muted);font-size:0.9rem}

.content-row{display:flex;flex-direction:column;gap:20px;align-items:stretch;max-width:1100px;margin:0 auto}
.card-box{background:#fff;border:1px solid #e0e0e0;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);padding:16px;width:100%}
.card-box h3{margin-top:0;color:#23412e}
.notice{background:#fff7f3;border:1px solid #f2d3c7;color:#8a2a2a;padding:10px 12px;border-radius:8px;font-size:0.9rem;margin:8px 0 14px}
.nav-item span{flex:1}
.nav-item img{flex-shrink:0}

@media(max-width:1000px){
  .sidebar{width:68px}
  .nav-item{padding:12px 8px;font-size:0.78rem}
  .nav-item span{display:none}
  .brand .title{display:none}
  .main{padding:12px}
}
@media(max-width:768px){
  .table thead th,.table td{padding:10px}
  .actions .btn{flex:1 1 120px;min-width:120px}
}
/* Verify Payment Receipts responsive layout */
#verify-panel .table{table-layout:auto}
#verify-panel td:nth-child(5),
#verify-panel td:nth-child(6){text-align:center}
#verify-panel .actions{flex-direction:column;align-items:stretch;justify-content:flex-start;gap:6px}
#verify-panel .actions > *{width:100%}
#verify-panel .actions .btn{min-width:0;width:100%}
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="brand">
      <img src="images/logo.svg" alt="VictorianPass logo">
      <div class="title">
        <h1>Admin Dashboard</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>
    <nav class="nav-list">
       <a href="?page=dashboard" class="nav-item <?php echo $currentPage == 'dashboard' ? 'active' : ''; ?>" data-page="dashboard"><img src="images/dashboard.svg"><span>Dashboard</span></a>
       <a href="?page=verify" class="nav-item <?php echo $currentPage == 'verify' ? 'active' : ''; ?>" data-page="verify"><img src="images/dashboard.svg"><span>Verify Payment Receipts</span></a>
       <a href="?page=requests" class="nav-item <?php echo $currentPage == 'requests' ? 'active' : ''; ?>" data-page="requests"><img src="images/dashboard.svg"><span>Resident Requests</span></a>
       <a href="?page=resident_guest_forms" class="nav-item <?php echo $currentPage == 'resident_guest_forms' ? 'active' : ''; ?>" data-page="resident_guest_forms"><img src="images/dashboard.svg"><span>Resident Guest Forms</span></a>
       <a href="?page=visitor_requests" class="nav-item <?php echo $currentPage == 'visitor_requests' ? 'active' : ''; ?>" data-page="visitor_requests"><img src="images/dashboard.svg"><span>Visitor Requests</span></a>
       <a href="?page=report" class="nav-item <?php echo $currentPage == 'report' ? 'active' : ''; ?>" data-page="report"><img src="images/dashboard.svg"><span>View Reported Incidents</span></a>
       <a href="?page=residents" class="nav-item <?php echo $currentPage == 'residents' ? 'active' : ''; ?>" data-page="residents"><img src="images/dashboard.svg"><span>Residents</span></a>
       <a href="?page=security" class="nav-item <?php echo $currentPage == 'security' ? 'active' : ''; ?>" data-page="security"><img src="images/dashboard.svg"><span>Security Guards</span></a>
     </nav>
    <div class="sidebar-footer">
      <a href="?logout=1" style="color:#bfb7aa;text-decoration:none;">Log Out</a>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main">
    <div class="header">
      <?php $pageTitles = [
        'requests' => 'Resident Requests',
        'resident_guest_forms' => 'Resident Guest Forms',
        'visitor_requests' => 'Visitor Requests',
        'reservations' => 'Reservations',
        'report' => 'View Reported Incidents',
        'security' => 'Security Guards',
        'verify' => 'Verify Payment Receipts',
        'residents' => 'Residents',
        'dashboard' => 'Dashboard'
      ];
      $pageTitle = $pageTitles[$currentPage] ?? ucfirst($currentPage); ?>
      <h2 id="page-title"><?php echo htmlspecialchars($pageTitle); ?></h2>
      <div class="search"><input id="search-input" placeholder="Search <?php echo htmlspecialchars($pageTitle); ?>..."></div>
      <div class="header-actions">
      <?php 
        $notifPayments = getPendingPaymentCount($con); 
        $notifAwaiting = getAmenityAwaitingPaymentCount($con); 
        $notifReady = getAmenityReadyForApprovalCount($con);
        $notifIncidents = getOpenIncidentCount($con);
        $notifNewReqs = getNewRequestsCount($con);
        $notifTotal = $notifPayments + $notifAwaiting + $notifReady + $notifIncidents + $notifNewReqs;
        $recent = getRecentNotifications($con);
      ?>
      <div class="notifications">
        <button id="notifToggle" class="notif-btn" aria-label="Notifications" title="Notifications">
          <img alt="Notifications" src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4a1.5 1.5 0 10-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z' fill='%23fff'/></svg>" />
          <?php if($notifTotal>0){ echo "<span class='notif-badge'>".intval($notifTotal)."</span>"; } ?>
        </button>
        <div id="notifPanel" class="notif-panel">
          <?php if(count($recent)>0){ foreach($recent as $it){ 
            $type = $it['type']; $title = htmlspecialchars($it['title']); $ref = htmlspecialchars($it['ref'] ?? ''); $amen = htmlspecialchars($it['amenity'] ?? ''); $time = htmlspecialchars(date('M d, Y H:i', strtotime($it['time'])));
            $src = isset($it['source']) ? $it['source'] : '';
            $href = '?page=dashboard';
            $lt = strtolower($type);
            if($lt==='payment'){ $href='?page=verify'; }
            elseif($lt==='amenity' || $lt==='approval'){ $href='?page=requests'; }
            elseif($lt==='request'){ $href = ($src==='resident') ? '?page=requests' : '?page=visitor_requests'; }
            elseif($lt==='incident'){ $href='?page=report'; }
            echo "<div class='notif-item' data-type='".strtoupper($type)."' data-ref='".$ref."' data-time='".$time."'>";
            echo "<a class='notif-item-link' href='".$href."'>";
            echo "<div class='notif-type'>".strtoupper($type)."</div>";
            echo "<div class='notif-meta'><div><strong>$title</strong>".( $amen? " — $amen" : "" )."</div>".( $ref? "<div>Status Code: $ref</div>" : "" )."<div style='color:#888'>".$time."</div></div>";
            echo "</a><button type='button' class='notif-dismiss' aria-label='Dismiss'>×</button></div>";
          } } else { echo "<div class='notif-item'><div class='notif-meta'>No notifications</div></div>"; } ?>
        </div>
      </div>
      <img class="avatar" src="images/mainpage/profile'.jpg" alt="admin">
      </div>
      <script>
        (function(){
          const input=document.getElementById('search-input');
          function filter(){
            const q=(input.value||'').toLowerCase().trim();
            const main=document.querySelector('.main');
            const tables=main.querySelectorAll('table');
            tables.forEach(function(tbl){
              const rows=tbl.querySelectorAll('tbody tr');
              let any=false;
              rows.forEach(function(r){
                const t=(r.textContent||'').toLowerCase();
                const show=!q||t.indexOf(q)>=0;
                r.style.display=show?'':'none';
                any=any||show;
              });
              const thead=tbl.querySelector('thead');
              const cols=(thead?thead.querySelectorAll('th').length:tbl.rows[0]?tbl.rows[0].cells.length:1)||1;
              let emptyRow=tbl.querySelector('tr.search-empty');
              if(!any&&q){
                if(!emptyRow){
                  emptyRow=document.createElement('tr');
                  emptyRow.className='search-empty';
                  const td=document.createElement('td');
                  td.colSpan=cols; td.style.textAlign='center'; td.style.color='#6b6b6b'; td.textContent='No results';
                  emptyRow.appendChild(td);
                  const tb=tbl.querySelector('tbody')||tbl; tb.appendChild(emptyRow);
                }
              } else { if(emptyRow) emptyRow.remove(); }
            });
          }
          if(input){ input.addEventListener('input',filter); }
          const t=document.getElementById('notifToggle');
          const p=document.getElementById('notifPanel');
          if(t&&p){
            t.addEventListener('click',function(){ p.style.display = (p.style.display==='block')?'none':'block'; });
            document.addEventListener('click',function(e){ if(!p.contains(e.target) && !t.contains(e.target)){ p.style.display='none'; } });
          }
          var lastTotal = null;
          var dismissed = new Set();
          function keyFor(it){ return [String(it.type||''), String(it.ref||''), String(it.time||'')].join('|'); }
          function renderNotif(data){
            if(!data) return;
            var badge = t && t.querySelector('.notif-badge');
            var itemsRaw = Array.isArray(data.items)?data.items:[];
            var items = [];
            for(var i=0;i<itemsRaw.length;i++){ var k=keyFor(itemsRaw[i]); if(!dismissed.has(k)) items.push(itemsRaw[i]); }
            var total = items.length;
            if(t){
              if(total>0){ if(!badge){ badge=document.createElement('span'); badge.className='notif-badge'; t.appendChild(badge);} badge.textContent=String(total); if(lastTotal!==null && total>lastTotal){ badge.classList.add('pulse'); setTimeout(function(){ badge.classList.remove('pulse'); }, 1200); } }
              else { if(badge){ badge.remove(); } }
            }
            if(p){
              var html = '';
              if(items.length===0){ html += "<div class='notif-item'><div class='notif-meta'>No notifications</div></div>"; }
              for(var i=0;i<items.length;i++){
                var it=items[i]||{}; var type=String(it.type||'').toUpperCase(); var title=String(it.title||''); var ref=it.ref?String(it.ref):''; var amen=it.amenity?String(it.amenity):''; var time=String(it.time||''); var href = linkFor(it);
                html += "<div class='notif-item' data-type='"+type+"' data-ref='"+ref.replace(/[<>]/g,'')+"' data-time='"+time+"'><a class='notif-item-link' href='"+href+"'><div class='notif-type'>"+type+"</div><div class='notif-meta'><div><strong>"+title.replace(/[<>]/g,'')+"</strong>"+(amen?" — "+amen.replace(/[<>]/g,''):'')+"</div>"+(ref?"<div>Status Code: "+ref.replace(/[<>]/g,'')+"</div>":"")+"<div style='color:#888'>"+time+"</div></div></a><button type='button' class='notif-dismiss' aria-label='Dismiss'>×</button></div>";
              }
              p.innerHTML = html;
            }
            lastTotal = total;
          }
          function pollNotifications(){
            fetch('admin.php?action=get_notifications')
              .then(function(r){ return r.json(); })
              .then(function(data){ renderNotif(data); })
              .catch(function(){});
          }
          var lastSeenEpoch = 0;
          function linkFor(it){ var type=(it.type||'').toLowerCase(), src=(it.source||''); if(type==='payment') return '?page=verify'; if(type==='amenity'||type==='approval') return (src==='guest_form' ? '?page=resident_guest_forms' : '?page=requests'); if(type==='request') return (src==='resident'? '?page=requests' : '?page=visitor_requests'); if(type==='incident') return '?page=report'; return '?page=dashboard'; }
          function showToast(it){ var c=document.getElementById('toastContainer'); if(!c||!it) return; var el=document.createElement('div'); el.className='toast'; var safeTitle=String(it.title||'').replace(/[<>]/g,''); var safeAmen=it.amenity?String(it.amenity).replace(/[<>]/g,''):''; var safeRef=it.ref?String(it.ref).replace(/[<>]/g,''):''; var href=linkFor(it);
            el.innerHTML = "<div><h4>New "+(String(it.type||'').toUpperCase())+"</h4><p>"+safeTitle+(safeAmen?" — "+safeAmen:'')+(safeRef?" (Status Code: "+safeRef+")":"")+"</p><div class='actions'><a href='"+href+"' class='btn btn-view'>Open</a><button class='btn btn-remove'>Dismiss</button></div></div>";
            var dismissBtn = el.querySelector('.btn-remove'); if(dismissBtn){ dismissBtn.addEventListener('click', function(){ var k = keyFor(it); dismissed.add(k); el.remove(); renderNotif({ items: [] }); }); }
            c.appendChild(el); setTimeout(function(){ if(el&&el.parentNode){ el.remove(); } }, 8000);
          }
          var initialized = false;
          function handleData(data){
            try{
              renderNotif(data);
              var items = Array.isArray(data.items)?data.items:[];
              if(items.length>0){
                var newest = items[0];
                var t = parseInt(newest.epoch||0,10);
                if(!initialized){ lastSeenEpoch = t||0; initialized = true; }
                else if(!isNaN(t) && t>lastSeenEpoch){ showToast(newest); lastSeenEpoch = t; }
              }
            } catch(e){}
          }
          function poll(){ fetch('admin.php?action=get_notifications').then(function(r){ return r.json(); }).then(handleData).catch(function(){}); }
          poll();
          var pollMs = 2000; var timer = setInterval(poll, pollMs);
          document.addEventListener('visibilitychange', function(){ if(document.hidden){ clearInterval(timer); timer = setInterval(poll, 5000); } else { clearInterval(timer); timer = setInterval(poll, pollMs); poll(); } });
          if(p){ p.addEventListener('click', function(e){ var btn=e.target.closest('.notif-dismiss'); if(!btn) return; var item=btn.closest('.notif-item'); if(!item) return; var k=[item.getAttribute('data-type')||'', item.getAttribute('data-ref')||'', item.getAttribute('data-time')||''].join('|'); dismissed.add(k); item.remove(); var b=t&&t.querySelector('.notif-badge'); if(b){ var cur=parseInt(b.textContent||'0',10)||0; b.textContent=String(Math.max(0,cur-1)); if(Math.max(0,cur-1)===0){ b.remove(); } } }); }
        })();
      </script>
      <script>
        (function(){
          try{
            var params = new URLSearchParams(window.location.search);
            var ref = params.get('ref');
            if(ref){
              var row = document.querySelector('tr[data-ref="'+ref+'"]');
              if(row){
                row.classList.add('row-highlight');
                try{ row.scrollIntoView({behavior:'smooth', block:'center'}); }catch(e){}
                var id = row.getAttribute('data-id');
                var src = row.getAttribute('data-source');
                if(id && src){
                  var n = parseInt(id,10);
                  if(src==='resident'){ if(typeof showResidentReservationDetails==='function'){ showResidentReservationDetails(n); } }
                  else if(src==='guest_form'){ if(typeof showVisitorDetails==='function'){ showVisitorDetails(n,'guest_form'); } }
                  else if(src==='reservation'){ if(typeof showVisitorDetails==='function'){ showVisitorDetails(n,'reservation'); } }
                }
              }
            }
          }catch(e){}
        })();
      </script>
    </div>

<!-- DASHBOARD -->
<?php if ($currentPage == 'dashboard'): ?>
<section class="panel" id="dashboard-panel">
  <h3>Community Overview</h3>
  <div style="display:flex;flex-wrap:wrap;gap:18px;margin-top:12px">
    <div style="flex:1;min-width:180px;background:#ffffff;border-radius:12px;padding:18px;
                box-shadow:0 8px 18px rgba(0,0,0,0.08);">
      <div style="font-size:1.9rem;font-weight:800;margin:0;"><?php echo getResidentCount($con); ?></div>
      <div style="font-size:0.92rem;color:#8b918d;margin-top:6px">Residents</div>
    </div>
    <div style="flex:1;min-width:180px;background:#ffffff;border-radius:12px;padding:18px;
                box-shadow:0 8px 18px rgba(0,0,0,0.08);">
      <div style="font-size:1.9rem;font-weight:800;margin:0;"><?php echo getActivePassesCount($con); ?></div>
      <div style="font-size:0.92rem;color:#8b918d;margin-top:6px">Active Passes</div>
    </div>
    <div style="flex:1;min-width:180px;background:#ffffff;border-radius:12px;padding:18px;
                box-shadow:0 8px 18px rgba(0,0,0,0.08);">
      <div style="font-size:1.9rem;font-weight:800;margin:0;"><?php echo getPendingRequestsCount($con); ?></div>
      <div style="font-size:0.92rem;color:#8b918d;margin-top:6px">Pending Requests</div>
    </div>
    <div style="flex:1;min-width:180px;background:#ffffff;border-radius:12px;padding:18px;
                box-shadow:0 8px 18px rgba(0,0,0,0.08);">
      <div style="font-size:1.9rem;font-weight:800;margin:0;"><?php echo getPaymentReceiptsCount($con); ?></div>
      <div style="font-size:0.92rem;color:#8b918d;margin-top:6px">Verified Payment Receipts</div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- RESIDENT GUEST FORMS -->
<?php if ($currentPage == 'resident_guest_forms'): ?>
<section class="panel" id="resident-guest-forms-panel">
  <div class="content-row">
    <div class="card-box">
      <h3>Resident Guest Forms</h3>
      <div class="notice">Guest forms submitted by residents (linked to resident accounts)</div>
      <div class="notice">For amenity requests, confirm payment receipt before viewing details or approving.</div>
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>House #</th>
            <th>Amenity</th>
            <th>Purpose of Visit</th>
            <th>Persons</th>
            <th>Status Code</th>
            <th>Request Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $residentRequests = getResidentVisitorRequests($con);
          $hasResidentRequests = false;
          if ($residentRequests && $residentRequests->num_rows > 0) {
              while ($req = $residentRequests->fetch_assoc()) {
                  $hasResidentRequests = true;
                  echo "<tr data-ref='" . htmlspecialchars($req['ref_code'] ?? '') . "' data-id='" . intval($req['id']) . "' data-source='guest_form'>";
                  $fullName = trim(($req['full_name'] ?? '') . ' ' . ($req['middle_name'] ?? '') . ' ' . ($req['last_name'] ?? ''));
                  echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                  $houseNo = !empty($req['res_house_number']) ? htmlspecialchars($req['res_house_number']) : '<span class=\'muted\'>-</span>';
                  echo "<td>" . $houseNo . "</td>";
                  echo "<td>" . (!empty($req['amenity']) ? htmlspecialchars($req['amenity']) : "") . "</td>";
                  echo "<td>" . (!empty($req['purpose']) ? htmlspecialchars($req['purpose']) : "") . "</td>";
                  echo "<td>" . (!empty($req['persons']) ? intval($req['persons']) : '-') . "</td>";
                  $approval_status = $req['approval_status'] ?? 'pending';
                  $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
                  echo "<td>" . htmlspecialchars($req['ref_code'] ?? '') . "</td>";
                  echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";
                  echo "<td class='actions'>";
                  $payStatus = null; $resIdMatch = null; $receiptPath = null; $isAmenity = !empty($req['amenity']);
                  if (!empty($req['ref_code'])) {
                    $stmtPay2 = $con->prepare("SELECT id, payment_status, receipt_path FROM reservations WHERE ref_code = ? LIMIT 1");
                    $stmtPay2->bind_param('s', $req['ref_code']);
                    $stmtPay2->execute(); $rp2 = $stmtPay2->get_result();
                    if($rp2 && ($pr2=$rp2->fetch_assoc())){ $payStatus = $pr2['payment_status'] ?? null; $resIdMatch = intval($pr2['id'] ?? 0); $receiptPath = $pr2['receipt_path'] ?? null; }
                    $stmtPay2->close();
                  }
                  $disableView = ($isAmenity && $payStatus !== 'verified');
                  if($disableView){
                    echo "<button type='button' class='btn btn-disabled' disabled title='Verify payment receipt first' style='margin-bottom: 5px;'>View More Details</button><br>";
                  } else {
                    echo "<button type='button' class='btn btn-view' onclick=\"showVisitorDetails(" . $req['id'] . ", 'guest_form')\" style='margin-bottom: 5px;'>View More Details</button><br>";
                  }
                  if ($approval_status == 'pending') {
                      $disabled = ($isAmenity && $payStatus !== 'verified');
                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='reservation_id' value='" . $req['id'] . "'>";
                      echo "<input type='hidden' name='action' value='approve_request'>";
                      echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-approve") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . ">Approve</button>";
                      echo "</form>";
                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='reservation_id' value='" . $req['id'] . "'>";
                      echo "<input type='hidden' name='action' value='deny_request'>";
                      echo "<button type='submit' class='btn btn-reject'>Deny</button>";
                      echo "</form>";
                  } elseif ($approval_status == 'denied') {
                      echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied request? This cannot be undone.\")'>";
                      echo "<input type='hidden' name='reservation_id' value='" . $req['id'] . "'>";
                      echo "<input type='hidden' name='action' value='delete_reservation'>";
                      echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                      echo "</form>";
                  } else {
                      $approvedBy = !empty($req['approved_by']) ? "by Staff ID " . $req['approved_by'] : "";
                      $approvalDate = !empty($req['approval_date']) ? date('M d, Y', strtotime($req['approval_date'])) : "";
                      echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
                  }
                  echo "</td>";
                  echo "</tr>";
              }
          }
          if (!$hasResidentRequests) {
              echo "<tr><td colspan='6' style='text-align:center;'>No resident requests found</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- RESERVATIONS -->
<?php if ($currentPage == 'reservations'): ?>
<section class="panel" id="reservations-panel">
  <div class="content-row">
    <div class="card-box">
      <h3>Resident Reservations</h3>
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>House #</th>
            <th>Amenity</th>
            <th>Dates</th>
            <th>Status Code</th>
            <th>Request Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $residentRes = getResidentReservations($con);
          $hasRR = false;
          if ($residentRes && $residentRes->num_rows > 0) {
              while ($rr = $residentRes->fetch_assoc()) {
                  $hasRR = true;
                  echo "<tr data-ref='" . htmlspecialchars($rr['ref_code'] ?? '') . "' data-id='" . intval($rr['id']) . "' data-source='resident'>";
                  $fullName = trim(($rr['first_name'] ?? '') . ' ' . ($rr['middle_name'] ?? '') . ' ' . ($rr['last_name'] ?? ''));
                  echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                  echo "<td>" . htmlspecialchars($rr['house_number'] ?? '-') . "</td>";
                  echo "<td>" . htmlspecialchars($rr['amenity'] ?? '-') . "</td>";
                  $dateRange = (!empty($rr['start_date']) && !empty($rr['end_date'])) ? (date('M d', strtotime($rr['start_date'])) . ' - ' . date('M d, Y', strtotime($rr['end_date']))) : '<span class=\'muted\'>-</span>';
                  echo "<td>" . $dateRange . "</td>";
                  $approval_status = $rr['approval_status'] ?? 'pending';
                  $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
                  echo "<td>" . htmlspecialchars($rr['ref_code'] ?? '') . "</td>";
                  echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";
                  echo "<td class='actions'>";
                  echo "<button type='button' class='btn btn-view' onclick='showResidentReservationDetails(" . intval($rr['id']) . ")' style='margin-bottom: 5px;'>View Details</button><br>";
                  if ($approval_status == 'pending') {
                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='approve_resident_reservation'>";
                      echo "<button type='submit' class='btn btn-approve'>Approve</button>";
                      echo "</form>";

                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='deny_resident_reservation'>";
                      echo "<button type='submit' class='btn btn-reject'>Deny</button>";
                      echo "</form>";
                  } elseif ($approval_status == 'denied') {
                      echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied reservation? This cannot be undone.\")'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='delete_resident_reservation'>";
                      echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                      echo "</form>";
                  } else {
                      $approvedBy = !empty($rr['approved_by']) ? "by Staff ID " . $rr['approved_by'] : "";
                      $approvalDate = !empty($rr['approval_date']) ? date('M d, Y', strtotime($rr['approval_date'])) : "";
                      if ($approval_status === 'approved' && !empty($rr['ref_code'])) {
                        echo "<a class='btn btn-view' href='qr_view.php?code=" . urlencode($rr['ref_code']) . "' target='_blank' style='margin-right:6px;'>View QR</a>";
                      }
                      echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
                  }
                  echo "</td>";
                  echo "</tr>";
              }
          }
          if (!$hasRR) {
              echo "<tr><td colspan='6' style='text-align:center;'>No resident reservations found</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>

    <div class="card-box">
      <h3>Guest Reservations</h3>
      <div class="notice">Verify payment receipt first to unlock viewing and approval of amenity requests.</div>
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Amenity</th>
            <th>Dates</th>
            <th>Persons</th>
            <th>Status Code</th>
            <th>Request Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $guestRes = getGuestAmenityReservations($con);
          $hasGR = false;
          if ($guestRes && $guestRes->num_rows > 0) {
              while ($gr = $guestRes->fetch_assoc()) {
                  $hasGR = true;
                  echo "<tr data-ref='" . htmlspecialchars($gr['ref_code'] ?? '') . "' data-id='" . intval($gr['gf_id'] ?? $gr['id']) . "' data-source='guest_form'>";
                  $fullName = trim(($gr['full_name'] ?? '') . ' ' . ($gr['middle_name'] ?? '') . ' ' . ($gr['last_name'] ?? ''));
                  if ($fullName === '') { $fullName = 'Guest Visitor'; }
                  echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                  echo "<td>" . htmlspecialchars($gr['amenity'] ?? '-') . "</td>";
                  $dateRange = (!empty($gr['start_date']) && !empty($gr['end_date'])) ? (date('M d', strtotime($gr['start_date'])) . ' - ' . date('M d, Y', strtotime($gr['end_date']))) : '<span class=\'muted\'>-</span>';
                  echo "<td>" . $dateRange . "</td>";
                  echo "<td>" . (!empty($gr['persons']) ? intval($gr['persons']) : '-') . "</td>";
                  $approval_status = $gr['approval_status'] ?? 'pending';
                  $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
                  echo "<td>" . htmlspecialchars($gr['ref_code'] ?? '') . "</td>";
                  echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";
                  echo "<td class='actions'>";
                  $viewHandler = "showVisitorDetails(" . intval($gr['gf_id'] ?? $gr['id']) . ", 'guest_form')";
                  $isAmenity = !empty($gr['amenity']);
                  $disableView = ($isAmenity && $payStatus !== 'verified');
                  if($disableView){
                    echo "<button type='button' class='btn btn-disabled' disabled title='Verify payment receipt first' style='margin-bottom: 5px;'>View Details</button><br>";
                  } else {
                    echo "<button type='button' class='btn btn-view' onclick='$viewHandler' style='margin-bottom: 5px;'>View Details</button><br>";
                  }

                  // Lookup matching reservation for payment status/receipt
                  $payStatus = null; $receiptPath = null; $resIdMatch = null;
                  if (!empty($gr['ref_code'])) {
                      $stmtPay = $con->prepare("SELECT id, payment_status, receipt_path FROM reservations WHERE ref_code = ? LIMIT 1");
                      $stmtPay->bind_param('s', $gr['ref_code']);
                      $stmtPay->execute();
                      $resPay = $stmtPay->get_result();
                      if ($resPay && ($rowP = $resPay->fetch_assoc())) {
                          $payStatus = $rowP['payment_status'] ?? null;
                          $receiptPath = $rowP['receipt_path'] ?? null;
                          $resIdMatch = intval($rowP['id'] ?? 0);
                      }
                      $stmtPay->close();
                  }

                  if (!empty($receiptPath)) {
                      echo "<a class='receipt-link' href='" . htmlspecialchars($receiptPath) . "' target='_blank' title='View Receipt'><img class='receipt-thumbnail' src='" . htmlspecialchars($receiptPath) . "' alt='Receipt'></a>";
                  }

                  if ($resIdMatch && $payStatus !== 'verified') {
                    echo "<form method='post' style='display:inline'>";
                    echo "<input type='hidden' name='reservation_id' value='" . $resIdMatch . "'>";
                    echo "<input type='hidden' name='action' value='verify_receipt'>";
                    echo "<button type='submit' class='btn btn-payverify'>Verify Payment Receipt</button>";
                    echo "</form>";
                    echo "<form method='post' style='display:inline'>";
                    echo "<input type='hidden' name='reservation_id' value='" . $resIdMatch . "'>";
                    echo "<input type='hidden' name='action' value='reject_receipt'>";
                    echo "<button type='submit' class='btn btn-reject'>Reject Receipt</button>";
                    echo "</form>";
                  } else if ($resIdMatch && $payStatus === 'verified') {
                    echo "<span class='badge badge-approved'>Payment Verified</span>";
                  }
                  if ($approval_status == 'pending') {
                      $disabled = ($isAmenity && $payStatus !== 'verified');
                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($gr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='approve_request'>";
                      echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-approve") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . ">Approve</button>";
                      echo "</form>";

                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($gr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='deny_request'>";
                      echo "<button type='submit' class='btn btn-reject'>Deny</button>";
                      echo "</form>";
                  } elseif ($approval_status == 'denied') {
                      echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied reservation? This cannot be undone.\")'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($gr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='delete_reservation'>";
                      echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                      echo "</form>";
                  } else {
                      $approvedBy = !empty($gr['approved_by']) ? "by Staff ID " . $gr['approved_by'] : "";
                      $approvalDate = !empty($gr['approval_date']) ? date('M d, Y', strtotime($gr['approval_date'])) : "";
                      if ($approval_status === 'approved' && !empty($gr['ref_code'])) {
                        echo "<a class='btn btn-view' href='qr_view.php?code=" . urlencode($gr['ref_code']) . "' target='_blank' style='margin-right:6px;'>View QR</a>";
                      }
                      echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
                  }
                  echo "</td>";
                  echo "</tr>";
              }
          }
          if (!$hasGR) {
              echo "<tr><td colspan='6' style='text-align:center;'>No guest reservations found</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>

    <div class="card-box">
      <h3>Visitor Reservations (Legacy)</h3>
      <table class="table">
        <thead>
              <tr>
            <th>Status Code</th>
            <th>Name</th>
            <th>Amenity</th>
            <th>Dates</th>
            <th>Payment</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $legacy = $con->query("SELECT r.*, ep.full_name, ep.middle_name, ep.last_name FROM reservations r JOIN entry_passes ep ON r.entry_pass_id = ep.id ORDER BY r.created_at DESC");
          if ($legacy && $legacy->num_rows > 0) {
            while ($row = $legacy->fetch_assoc()) {
              echo '<tr data-ref="' . htmlspecialchars($row['ref_code']) . '" data-id="' . intval($row['id']) . '" data-source="reservation">';
              echo '<td>' . htmlspecialchars($row['ref_code']) . '</td>';
              $fullName = trim(($row['full_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
              echo '<td>' . htmlspecialchars($fullName) . '</td>';
              echo '<td>' . htmlspecialchars($row['amenity'] ?? '-') . '</td>';
              $dateRange = (!empty($row['start_date']) && !empty($row['end_date'])) ? (date('M d', strtotime($row['start_date'])) . ' - ' . date('M d, Y', strtotime($row['end_date']))) : '<span class=\'muted\'>-</span>';
              echo '<td>' . $dateRange . '</td>';
              $ps = strtolower($row['payment_status'] ?? 'pending');
              $psClass = $ps==='verified' ? 'badge-approved' : ($ps==='rejected' ? 'badge-rejected' : 'badge-pending');
              echo '<td><span class="badge ' . $psClass . '">' . ucfirst($ps) . '</span></td>';
              $as = strtolower($row['approval_status'] ?? 'pending');
              $asClass = $as==='approved' ? 'badge-approved' : ($as==='denied' ? 'badge-rejected' : 'badge-pending');
              echo '<td><span class="badge ' . $asClass . '">' . ucfirst($as) . '</span></td>';
              echo '</tr>';
            }
          } else {
            echo '<tr><td colspan="6" style="text-align:center;">No visitor reservations found</td></tr>'; 
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- RESIDENTS -->
<?php if ($currentPage == 'residents'): ?>
<section class="panel" id="residents-panel">
  <h3>Registered Residents</h3>
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>House Number</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Registered On</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $residents = getResidents($con);
      if ($residents && $residents->num_rows > 0) {
          while ($resident = $residents->fetch_assoc()) {
              echo "<tr>";
              echo "<td>" . $resident['first_name'] . " " . $resident['last_name'] . "</td>";
              echo "<td>" . $resident['house_number'] . "</td>";
              echo "<td>" . $resident['email'] . "</td>";
              echo "<td>" . $resident['phone'] . "</td>";
              echo "<td>" . date('M d, Y', strtotime($resident['created_at'])) . "</td>";
              echo "<td class='actions'>";
              // View Details button
              echo "<button type='button' class='btn btn-view' onclick='showUserDetails(" . intval($resident['id']) . ")' style='margin-right:6px;'>View Details</button>";

              

              // Delete button
              echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this account? This cannot be undone.\")'>";
              echo "<input type='hidden' name='user_id' value='" . intval($resident['id']) . "'>";
              echo "<input type='hidden' name='user_action' value='delete_user'>";
              echo "<button type='submit' class='btn btn-remove'>Delete</button>";
              echo "</form>";
              echo "</td>";
              echo "</tr>";
          }
      } else {
          echo "<tr><td colspan='6' style='text-align:center;'>No residents found</td></tr>";
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- VERIFY RECEIPTS -->
<?php if ($currentPage == 'verify'): ?>
<section class="panel" id="verify-panel">
  <div class="content-row">
    <div class="card-box">
      <h3>Verify Payment Receipts</h3>
      <div class="notice">Use View All Details to jump to the matching request. Verify or reject the receipt below.</div>
      <table class="table table-verify">
    <thead>
      <tr>
        <th>User Type</th>
        <th>Name</th>
        <th>Amenity</th>
        <th>Dates</th>
        <th>Receipt</th>
        <th>Payment Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
        $resList = $con->query("SELECT r.id, r.ref_code, r.amenity, r.start_date, r.end_date, r.payment_status, r.receipt_path, r.entry_pass_id,
                                       ep.full_name, ep.middle_name, ep.last_name,
                                       u.first_name AS res_first_name, u.last_name AS res_last_name
                                  FROM reservations r
                                  LEFT JOIN entry_passes ep ON r.entry_pass_id = ep.id
                                  LEFT JOIN users u ON r.user_id = u.id
                                  WHERE r.receipt_path IS NOT NULL
                                  ORDER BY r.created_at DESC");
        if ($resList && $resList->num_rows > 0) {
          while ($row = $resList->fetch_assoc()) {
            echo '<tr>';
            $userType = !empty($row['entry_pass_id']) ? 'Visitor' : 'Resident';
            echo '<td>' . $userType . '</td>';
            $fullName = !empty($row['entry_pass_id'])
              ? trim(($row['full_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))
              : trim(($row['res_first_name'] ?? '') . ' ' . ($row['res_last_name'] ?? ''));
            if ($fullName === '') { $fullName = $userType; }
            echo '<td>' . htmlspecialchars($fullName) . '</td>';
            echo '<td>' . htmlspecialchars($row['amenity'] ?? '-') . '</td>';
            $dateRange = (!empty($row['start_date']) && !empty($row['end_date'])) ? (date('M d', strtotime($row['start_date'])) . ' - ' . date('M d, Y', strtotime($row['end_date']))) : '<span class=\'muted\'>-</span>';
            echo '<td>' . $dateRange . '</td>';
            if (!empty($row['receipt_path'])) {
              $rp = $row['receipt_path'];
              $isPdf = (bool)preg_match('/\.pdf$/i', (string)$rp);
              if ($isPdf) {
                echo '<td><a class="receipt-link" href="' . htmlspecialchars($rp) . '" target="_blank">Open Receipt (PDF)</a></td>';
              } else {
                echo '<td><a class="receipt-link" href="' . htmlspecialchars($rp) . '" target="_blank"><img class="receipt-thumbnail" src="' . htmlspecialchars($rp) . '" alt="Receipt"></a></td>';
              }
            } else {
              echo '<td><span class="muted">No receipt</span></td>';
            }
            $ps = strtolower($row['payment_status'] ?? 'pending');
            $psClass = $ps==='verified' ? 'badge-approved' : ($ps==='rejected' ? 'badge-rejected' : 'badge-pending');
            echo '<td><span class="badge ' . $psClass . '">' . ucfirst($ps) . '</span></td>';
            echo '<td class="actions">';
              $ref = urlencode($row['ref_code']);
              $targetPage = !empty($row['entry_pass_id']) ? 'visitor_requests' : 'reservations';
              echo "<a class='btn btn-view' href='admin.php?page=".$targetPage."&ref=".$ref."'>View All Details</a>";
              if($ps!=='verified'){
                echo '<form method="post">';
                echo '<input type="hidden" name="reservation_id" value="' . intval($row['id']) . '">';
                echo '<input type="hidden" name="action" value="verify_receipt">';
                echo '<button type="submit" class="btn btn-approve">Verify Payment Receipt</button>';
                echo '</form>';

                echo '<form method="post">';
                echo '<input type="hidden" name="reservation_id" value="' . intval($row['id']) . '">';
                echo '<input type="hidden" name="action" value="reject_receipt">';
                echo '<button type="submit" class="btn btn-reject">Reject</button>';
                echo '</form>';
              } else {
              }
            echo '</td>';
            echo '</tr>';
          }
        } else {
          echo '<tr><td colspan="7" style="text-align:center;">No receipts to verify</td></tr>';
        }
      ?>
    </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- SECURITY GUARDS -->
<?php if ($currentPage == 'security'): ?>
<section class="panel" id="security-panel">
  <h3>Security Guards on Duty</h3>
  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $guards = getSecurityGuards($con);
      if ($guards && $guards->num_rows > 0) {
          while ($guard = $guards->fetch_assoc()) {
              echo "<tr>";
              echo "<td>" . $guard['id'] . "</td>";
              echo "<td>" . $guard['email'] . "</td>";
              echo "<td>" . $guard['role'] . "</td>";
              echo "<td><span class='badge badge-active'>On Duty</span></td>";
              echo "</tr>";
          }
      } else {
          echo "<tr><td colspan='4' style='text-align:center;'>No security guards found</td></tr>";
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- REQUESTS -->
<?php if ($currentPage == 'requests'): ?>
<section class="panel" id="requests-panel">
  <div class="content-row">
  <!-- Resident Amenity Requests (from resident_reservations) -->
  <div class="card-box">
    <h3>Resident Amenity Requests</h3>
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>House #</th>
          <th>Amenity</th>
          <th>Dates</th>
          <th>Status Code</th>
          <th>Request Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $residentRes = getResidentReservations($con);
        $hasRR = false;
        if ($residentRes && $residentRes->num_rows > 0) {
            while ($rr = $residentRes->fetch_assoc()) {
                $hasRR = true;
                echo "<tr data-ref='" . htmlspecialchars($rr['ref_code'] ?? '') . "' data-id='" . intval($rr['id']) . "' data-source='resident'>";
                $fullName = trim(($rr['first_name'] ?? '') . ' ' . ($rr['middle_name'] ?? '') . ' ' . ($rr['last_name'] ?? ''));
                echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                echo "<td>" . htmlspecialchars($rr['house_number'] ?? '-') . "</td>";
                echo "<td>" . htmlspecialchars($rr['amenity'] ?? '-') . "</td>";
                $dateRange = (!empty($rr['start_date']) && !empty($rr['end_date'])) ? (date('M d', strtotime($rr['start_date'])) . ' - ' . date('M d, Y', strtotime($rr['end_date']))) : '<span class=\'muted\'>-</span>';
                echo "<td>" . $dateRange . "</td>";
                $approval_status = $rr['approval_status'] ?? 'pending';
                $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
                echo "<td>" . htmlspecialchars($rr['ref_code'] ?? '') . "</td>";
                echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";
                echo "<td class='actions'>";
                echo "<button type='button' class='btn btn-view' onclick='showResidentReservationDetails(" . intval($rr['id']) . ")' style='margin-bottom: 5px;'>View Details</button><br>";
                if ($approval_status == 'pending') {
                    echo "<form method='post' style='display:inline;'>";
                    echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='approve_resident_reservation'>";
                    echo "<button type='submit' class='btn btn-approve'>Approve</button>";
                    echo "</form>";

                    echo "<form method='post' style='display:inline;'>";
                    echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='deny_resident_reservation'>";
                    echo "<button type='submit' class='btn btn-reject'>Deny</button>";
                    echo "</form>";
                } elseif ($approval_status == 'denied') {
                    echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied reservation? This cannot be undone.\")'>";
                    echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='delete_resident_reservation'>";
                    echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                    echo "</form>";
                } else {
                    $approvedBy = !empty($rr['approved_by']) ? "by Staff ID " . $rr['approved_by'] : "";
                    $approvalDate = !empty($rr['approval_date']) ? date('M d, Y', strtotime($rr['approval_date'])) : "";
                    echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
                }
                echo "</td>";
                echo "</tr>";
            }
        }
        if (!$hasRR) {
            echo "<tr><td colspan='6' style='text-align:center;'>No resident amenity requests found</td></tr>";
        }
        ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<!-- (removed duplicate verify section to avoid confusion) -->

<!-- REPORTS -->
<?php if ($currentPage == 'report'): ?>
<section class="panel" id="report-panel">
  <h3>Reported Incidents</h3>
  <table class="table">
    <thead>
      <tr>
        <th>Reported By</th>
        <th>Complainee</th>
        <th>Nature</th>
        <th>Address</th>
        <th>Report Date</th>
        <th>Status</th>
        <th>Proofs</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $reports = getIncidentReports($con);
      if ($reports && $reports->num_rows > 0) {
          while ($r = $reports->fetch_assoc()) {
              $fullName = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
              $displayName = $fullName !== '' ? $fullName : $r['complainant'];
              echo '<tr>';
              echo '<td>' . htmlspecialchars($displayName) . '</td>';
              echo '<td>' . htmlspecialchars($r['subject'] ?: '-') . '</td>';
              echo '<td>' . htmlspecialchars($r['nature'] ?: ($r['other_concern'] ?: '-')) . '</td>';
              echo '<td>' . htmlspecialchars($r['address']) . '</td>';
              $rdate = !empty($r['report_date']) ? date('M d, Y', strtotime($r['report_date'])) : date('M d, Y', strtotime($r['created_at']));
              echo '<td>' . $rdate . '</td>';
              $status = $r['status'];
              $badgeClass = $status === 'resolved' ? 'badge badge-approved' : ($status === 'rejected' ? 'badge badge-rejected' : 'badge badge-warning');
              echo '<td><span class="' . $badgeClass . '">' . ucfirst($status) . '</span></td>';
              // Proofs
              $files = getIncidentProofs($con, intval($r['id']));
              echo '<td>';
              if (count($files) > 0) {
                  foreach ($files as $f) {
                      $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                      if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                          echo '<img src="' . htmlspecialchars($f) . '" class="receipt-thumbnail" onclick="showIncidentProofModal(\'' . htmlspecialchars($f) . '\')" alt="proof"> ';
                      } else {
                          echo '<a href="' . htmlspecialchars($f) . '" target="_blank">View file</a><br/>';
                      }
                  }
              } else {
                  echo '<span class="muted">No proofs</span>';
              }
              echo '</td>';
              // Actions
              echo '<td>';
              echo '<form method="POST" style="display:inline-block;margin-right:6px;">';
              echo '<input type="hidden" name="report_id" value="' . intval($r['id']) . '">';
              if ($status === 'new') {
                  echo '<input type="hidden" name="incident_action" value="start">';
                  echo '<button type="submit" class="btn btn-view">Start</button>';
              } elseif ($status === 'in_progress') {
                  echo '<input type="hidden" name="incident_action" value="resolve">';
                  echo '<button type="submit" class="btn btn-remove">Resolve</button>';
              }
              echo '</form>';
              echo '<form method="POST" style="display:inline-block;">';
              echo '<input type="hidden" name="report_id" value="' . intval($r['id']) . '">';
              echo '<input type="hidden" name="incident_action" value="reject">';
              echo '<button type="submit" class="btn btn-reject">Reject</button>';
              echo '</form>';
              echo '</td>';
              echo '</tr>';
          }
      } else {
          echo '<tr><td colspan="7" style="text-align:center;">No incidents reported yet</td></tr>';
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- VISITOR REQUESTS -->
<?php if ($currentPage == 'visitor_requests'): ?>
<section class="panel" id="visitor-requests-panel">
  <div class="content-row">
    <h3>Visitor Requests</h3>
    <div class="notice">Visitor forms without linked resident account</div>
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Amenity</th>
          <th>Status Code</th>
          <th>Request Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $visitorOnly = getVisitorOnlyRequests($con);
        $hasVisitorOnly = false;
        if ($visitorOnly && $visitorOnly->num_rows > 0) {
            while ($request = $visitorOnly->fetch_assoc()) {
                $hasVisitorOnly = true;
                echo "<tr data-ref='" . htmlspecialchars($request['ref_code'] ?? '') . "' data-id='" . intval($request['id']) . "' data-source='reservation'>";
                // Visitor name
                $fullName = trim(($request['full_name'] ?? '') . ' ' . ($request['middle_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
                echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";

                // Amenity (often Guest Entry)
                echo "<td>" . (!empty($request['amenity']) ? htmlspecialchars($request['amenity']) : '<span class=\'muted\'>No amenity</span>') . "</td>";

                

                // Status Code + Status badge
                $approval_status = $request['approval_status'] ?? 'pending';
                $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
                $ref = $request['ref_code'] ?? '';
                echo "<td>" . htmlspecialchars($ref) . "</td>";
                echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";

                // Actions
                echo "<td class='actions'>";
                $isAmenityLegacy = !empty($request['amenity']);
                $payStatusLegacy = null; if (!empty($request['ref_code'])) { $stmtPayL = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1"); $stmtPayL->bind_param('s', $request['ref_code']); $stmtPayL->execute(); $resPL = $stmtPayL->get_result(); if($resPL && ($rwPL=$resPL->fetch_assoc())){ $payStatusLegacy = $rwPL['payment_status'] ?? null; } $stmtPayL->close(); }
                $disableViewLegacy = ($isAmenityLegacy && $payStatusLegacy !== 'verified');
                if($disableViewLegacy){
                  echo "<button type='button' class='btn btn-disabled' disabled title='Verify payment receipt first' style='margin-bottom: 5px;'>View More Details</button><br>";
                } else {
                  echo "<button type='button' class='btn btn-view' onclick=\"showVisitorDetails(" . $request['id'] . ", 'reservation')\" style='margin-bottom: 5px;'>View More Details</button><br>";
                }
                if ($approval_status == 'pending') {
                    $disabled = ($isAmenityLegacy && $payStatusLegacy !== 'verified');
                    echo "<form method='post' style='display:inline;'>";
                    echo "<input type='hidden' name='reservation_id' value='" . $request['id'] . "'>";
                    echo "<input type='hidden' name='action' value='approve_request'>";
                    echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-approve") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . ">Approve</button>";
                    echo "</form>";

                    echo "<form method='post' style='display:inline;'>";
                    echo "<input type='hidden' name='reservation_id' value='" . $request['id'] . "'>";
                    echo "<input type='hidden' name='action' value='deny_request'>";
                    echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-reject") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . ">Deny</button>";
                    echo "</form>";
                } elseif ($approval_status == 'denied') {
                    echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied request? This cannot be undone.\")'>";
                    echo "<input type='hidden' name='reservation_id' value='" . $request['id'] . "'>";
                    echo "<input type='hidden' name='action' value='delete_reservation'>";
                    echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                    echo "</form>";
                } else {
                    $approvedBy = !empty($request['approved_by']) ? "by Staff ID " . $request['approved_by'] : "";
                    $approvalDate = !empty($request['approval_date']) ? date('M d, Y', strtotime($request['approval_date'])) : "";
                    if ($approval_status === 'approved' && !empty($ref)) {
                      echo "<a class='btn btn-view' href='qr_view.php?code=" . urlencode($ref) . "' target='_blank' style='margin-right:6px;'>View QR</a>";
                    }
                    echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
                }
                echo "</td>";
                echo "</tr>";
            }
        }
        if (!$hasVisitorOnly) {
            echo "<tr><td colspan='5' style='text-align:center;'>No visitor requests found</td></tr>";
        }
        ?>
      </tbody>
    </table>
</div>
</section>
<?php endif; ?>

<!-- Visitor Details Modal -->
<div id="visitorModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeVisitorModal()">&times;</span>
    <h3>Visitor Details</h3>
    <div id="visitorDetailsContent">
      <!-- Content will be loaded here -->
    </div>
  </div>
</div>

<!-- Incident Proof Modal -->
<div id="incidentProofModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeIncidentProofModal()">&times;</span>
    <img id="incidentProofImg" src="" alt="Proof" />
  </div>
</div>

<script>
// JavaScript to handle navigation
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', function() {
    // Update active class
    document.querySelectorAll('.nav-item').forEach(navItem => {
      navItem.classList.remove('active');
    });
    this.classList.add('active');
    
    // Update page title
    const pageTitle = this.querySelector('span').textContent;
    document.getElementById('page-title').textContent = pageTitle;
    
    // Update search placeholder
    document.getElementById('search-input').placeholder = `Search ${pageTitle}...`;
  });
});

// Incident proof modal
function showIncidentProofModal(src){
  var m=document.getElementById('incidentProofModal');
  var img=document.getElementById('incidentProofImg');
  if(m&&img){ img.src=src; m.style.display='block'; }
}
function closeIncidentProofModal(){ var m=document.getElementById('incidentProofModal'); if(m){ m.style.display='none'; } }

// Function to show visitor details modal
function showVisitorDetails(id, source) {
  // Make AJAX request to get visitor details
  const url = 'admin.php?action=get_visitor_details&id=' + encodeURIComponent(id) + (source? ('&source=' + encodeURIComponent(source)) : '');
  fetch(url)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const details = data.details;
        const isResident = details.user_id && String(details.user_id) !== '0';
        // Update modal title depending on source
        const modalTitleEl = document.querySelector('#visitorModal h3');
        if (modalTitleEl) modalTitleEl.textContent = isResident ? 'Resident Request Details' : 'Visitor Request Details';

        const residentName = [details.res_first_name || '', details.res_middle_name || '', details.res_last_name || ''].join(' ').replace(/\s+/g, ' ').trim();
        function fmtTime(t){ if(!t) return ''; const p=String(t).split(':'), h=(p[0]||'00'), m=(p[1]||'00'); return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`; }
        const ps = ((details.payment_status || 'pending') + '').toLowerCase();
        const psClass = ps==='verified'?'badge-approved':(ps==='rejected'?'badge-rejected':'badge-pending');
        const content = isResident
          ? `
          <div style="display: grid; grid-template-columns: 1fr; gap: 12px;">
            <div>
              <h4 style="color:#23412e;margin-bottom:10px;">Resident Information</h4>
              ${residentName ? `<p><strong>Name:</strong> ${residentName}</p>` : ''}
              ${details.res_house_number ? `<p><strong>House No.:</strong> ${details.res_house_number}</p>` : ''}
              ${details.res_email ? `<p><strong>Email:</strong> ${details.res_email}</p>` : ''}
              ${details.res_phone ? `<p><strong>Contact:</strong> ${details.res_phone}</p>` : ''}
            </div>
            <div>
              <h4 style="color:#23412e;margin-bottom:10px;">Visitor Information</h4>
              <p><strong>Full Name:</strong> ${details.full_name} ${details.middle_name || ''} ${details.last_name}</p>
              <p><strong>Sex:</strong> ${details.sex || '-'}</p>
              ${details.birthdate ? `<p><strong>Birthdate:</strong> ${new Date(details.birthdate).toLocaleDateString()}</p>` : ''}
              <p><strong>Contact:</strong> ${details.contact || '-'}</p>
              ${details.email ? `<p><strong>Email:</strong> ${details.email}</p>` : ''}
              ${details.valid_id_path ? `<p><strong>Valid ID:</strong> <a href="${details.valid_id_path}" target="_blank" class="btn btn-view">View ID</a></p>` : '<p><strong>Valid ID:</strong> Not uploaded</p>'}
            </div>
            <div>
              <h4 style="color:#23412e;margin-bottom:10px;">Visit Details</h4>
              ${details.start_date ? `<p><strong>Date:</strong> ${new Date(details.start_date).toLocaleDateString()}${details.end_date ? ' - ' + new Date(details.end_date).toLocaleDateString() : ''}</p>` : ''}
              ${(details.start_time || details.end_time) ? `<p><strong>Time:</strong> ${fmtTime(details.start_time)}${details.end_time ? ' - ' + fmtTime(details.end_time) : ''}</p>` : ''}
              ${details.persons ? `<p><strong>Persons:</strong> ${details.persons}</p>` : ''}
              ${details.purpose ? `<p><strong>Purpose of Visit:</strong> ${details.purpose}</p>` : ''}
              ${details.amenity && details.amenity !== 'Guest Entry' ? `<p><strong>Amenity:</strong> ${details.amenity}</p>` : ''}
              <p><strong>Status:</strong> <span class="badge badge-${details.approval_status || 'pending'}">${(details.approval_status || 'pending').charAt(0).toUpperCase() + (details.approval_status || 'pending').slice(1)}</span></p>
              <p><strong>Request Date:</strong> ${new Date(details.entry_created).toLocaleString()}</p>
              ${details.approved_by ? `<p><strong>Approved By:</strong> Staff ID ${details.approved_by}</p>` : ''}
              ${details.approval_date ? `<p><strong>Approval Date:</strong> ${new Date(details.approval_date).toLocaleString()}</p>` : ''}
            </div>
          </div>
          `
          : `
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
              <h4 style="color: #23412e; margin-bottom: 10px;">Personal Information</h4>
              <p><strong>Full Name:</strong> ${details.full_name} ${details.middle_name || ''} ${details.last_name}</p>
              <p><strong>Sex:</strong> ${details.sex}</p>
              <p><strong>Birthdate:</strong> ${new Date(details.birthdate).toLocaleDateString()}</p>
              <p><strong>Contact:</strong> ${details.contact}</p>
              ${details.email ? `<p><strong>Email:</strong> ${details.email}</p>` : ''}
              <p><strong>Address:</strong> ${details.address}</p>
              ${details.valid_id_path ? `<p><strong>Valid ID:</strong> <a href="${details.valid_id_path}" target="_blank" class="btn btn-view">View ID</a></p>` : '<p><strong>Valid ID:</strong> Not uploaded</p>'}
            </div>
            <div>
              <h4 style="color: #23412e; margin-bottom: 10px;">Reservation Details</h4>
              ${details.ref_code ? `<p><strong>Status Code:</strong> ${details.ref_code}</p>` : ''}
              ${details.amenity && details.amenity !== 'Guest Entry' ? `<p><strong>Amenity:</strong> ${details.amenity}</p>` : ''}
              ${details.start_date ? `<p><strong>Date:</strong> ${new Date(details.start_date).toLocaleDateString()}${details.end_date ? ' - ' + new Date(details.end_date).toLocaleDateString() : ''}</p>` : ''}
              ${(details.start_time || details.end_time) ? `<p><strong>Time:</strong> ${fmtTime(details.start_time)}${details.end_time ? ' - ' + fmtTime(details.end_time) : ''}</p>` : ''}
              ${details.persons ? `<p><strong>Persons:</strong> ${details.persons}</p>` : ''}
              ${details.purpose ? `<p><strong>Purpose of Visit:</strong> ${details.purpose}</p>` : ''}
              ${details.price ? `<p><strong>Price:</strong> ₱${parseFloat(details.price).toLocaleString()}</p>` : ''}
              <p><strong>Downpayment:</strong> <span class="badge ${psClass}">${ps.charAt(0).toUpperCase()+ps.slice(1)}</span></p>
              <p><strong>Request Date:</strong> ${new Date(details.entry_created).toLocaleString()}</p>
              <p><strong>Status:</strong> <span class="badge badge-${details.approval_status || 'pending'}">${(details.approval_status || 'pending').charAt(0).toUpperCase() + (details.approval_status || 'pending').slice(1)}</span></p>
              ${details.approved_by ? `<p><strong>Approved By:</strong> Staff ID ${details.approved_by}</p>` : ''}
              ${details.approval_date ? `<p><strong>Approval Date:</strong> ${new Date(details.approval_date).toLocaleString()}</p>` : ''}
            </div>
          </div>
          `;
        document.getElementById('visitorDetailsContent').innerHTML = content;
        document.getElementById('visitorModal').style.display = 'block';
      } else {
        alert('Error loading visitor details: ' + data.message);
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Error loading visitor details');
    });
}

// Function to close visitor details modal
function closeVisitorModal() {
  document.getElementById('visitorModal').style.display = 'none';
}

// Close modal when clicking outside of it
window.onclick = function(event) {
  const modal = document.getElementById('visitorModal');
  if (event.target == modal) {
    modal.style.display = 'none';
  }
}
</script>

<!-- Reservation Details Modal -->
<div id="reservationModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeReservationModal()">&times;</span>
    <h3>Reservation Details</h3>
    <div id="reservationDetailsContent"></div>
  </div>
</div>

<script>
function showReservationDetails(reservationId){
  fetch('admin.php?action=get_reservation_details&id=' + reservationId)
    .then(r => r.json())
    .then(data => {
      if(!data.success){ alert('Error loading reservation details: ' + (data.message||'Unknown error')); return; }
      const d = data.details || {};
      const fullName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const content = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Resident</h4>
            ${fullName?`<p><strong>Name:</strong> ${fullName}</p>`:''}
            ${d.house_number?`<p><strong>House No.:</strong> ${d.house_number}</p>`:''}
            ${d.email?`<p><strong>Email:</strong> ${d.email}</p>`:''}
            ${d.phone?`<p><strong>Phone:</strong> ${d.phone}</p>`:''}
          </div>
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Reservation</h4>
            ${d.ref_code?`<p><strong>Status Code:</strong> ${d.ref_code}</p>`:''}
            ${d.amenity?`<p><strong>Amenity:</strong> ${d.amenity}</p>`:''}
            ${d.start_date?`<p><strong>Start Date:</strong> ${new Date(d.start_date).toLocaleDateString()}</p>`:''}
            ${d.end_date?`<p><strong>End Date:</strong> ${new Date(d.end_date).toLocaleDateString()}</p>`:''}
            ${(d.start_time||d.end_time)?`<p><strong>Time:</strong> ${fmtTime(d.start_time)}${d.end_time?' - '+fmtTime(d.end_time):''}</p>`:''}
            ${d.persons?`<p><strong>Persons:</strong> ${d.persons}</p>`:''}
            ${d.price?`<p><strong>Price:</strong> ₱${parseFloat(d.price).toLocaleString()}</p>`:''}
            ${d.created_at?`<p><strong>Requested:</strong> ${new Date(d.created_at).toLocaleString()}</p>`:''}
            ${d.approval_status?`<p><strong>Status:</strong> <span class="badge badge-${d.approval_status}">${d.approval_status.charAt(0).toUpperCase()+d.approval_status.slice(1)}</span></p>`:''}
            ${d.approved_by?`<p><strong>Approved By:</strong> Staff ID ${d.approved_by}</p>`:''}
            ${d.approval_date?`<p><strong>Approval Date:</strong> ${new Date(d.approval_date).toLocaleString()}</p>`:''}
          </div>
        </div>`;
      document.getElementById('reservationDetailsContent').innerHTML = content;
      document.getElementById('reservationModal').style.display = 'block';
    })
    .catch(err => { console.error(err); alert('Error loading reservation details'); });
}

function closeReservationModal(){
  document.getElementById('reservationModal').style.display = 'none';
}

window.addEventListener('click', function(event){
  const rmodal = document.getElementById('reservationModal');
  if(event.target === rmodal){ rmodal.style.display = 'none'; }
});
</script>

<!-- Resident Reservation Details Modal -->
<div id="residentReservationModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeResidentReservationModal()">&times;</span>
    <h3>Resident Reservation Details</h3>
    <div id="residentReservationDetailsContent"></div>
  </div>
</div>

<script>
function showResidentReservationDetails(rrId){
  fetch('admin.php?action=get_resident_reservation_details&id=' + rrId)
    .then(r => r.json())
    .then(data => {
      if(!data.success){ alert('Error loading resident reservation details: ' + (data.message||'Unknown error')); return; }
      const d = data.details || {};
      const fullName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const content = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Resident</h4>
            ${fullName?`<p><strong>Name:</strong> ${fullName}</p>`:''}
            ${d.house_number?`<p><strong>House No.:</strong> ${d.house_number}</p>`:''}
            ${d.email?`<p><strong>Email:</strong> ${d.email}</p>`:''}
            ${d.phone?`<p><strong>Phone:</strong> ${d.phone}</p>`:''}
          </div>
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Reservation</h4>
            ${d.ref_code?`<p><strong>Status Code:</strong> ${d.ref_code}</p>`:''}
            ${d.amenity?`<p><strong>Amenity:</strong> ${d.amenity}</p>`:''}
            ${d.start_date?`<p><strong>Start Date:</strong> ${new Date(d.start_date).toLocaleDateString()}</p>`:''}
            ${d.end_date?`<p><strong>End Date:</strong> ${new Date(d.end_date).toLocaleDateString()}</p>`:''}
            ${(d.start_time||d.end_time)?`<p><strong>Time:</strong> ${fmtTime(d.start_time)}${d.end_time?' - '+fmtTime(d.end_time):''}</p>`:''}
            ${d.persons?`<p><strong>Persons:</strong> ${d.persons}</p>`:''}
            ${d.created_at?`<p><strong>Requested:</strong> ${new Date(d.created_at).toLocaleString()}</p>`:''}
            ${d.approval_status?`<p><strong>Status:</strong> <span class="badge badge-${d.approval_status}">${d.approval_status.charAt(0).toUpperCase()+d.approval_status.slice(1)}</span></p>`:''}
            ${d.approved_by?`<p><strong>Approved By:</strong> Staff ID ${d.approved_by}</p>`:''}
            ${d.approval_date?`<p><strong>Approval Date:</strong> ${new Date(d.approval_date).toLocaleString()}</p>`:''}
          </div>
        </div>`;
      document.getElementById('residentReservationDetailsContent').innerHTML = content;
      document.getElementById('residentReservationModal').style.display = 'block';
    })
    .catch(err => { console.error(err); alert('Error loading resident reservation details'); });
}

function closeResidentReservationModal(){
  document.getElementById('residentReservationModal').style.display = 'none';
}

window.addEventListener('click', function(event){
  const rmodal2 = document.getElementById('residentReservationModal');
  if(event.target === rmodal2){ rmodal2.style.display = 'none'; }
});
</script>

<!-- User Details Modal -->
<div id="userModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeUserModal()">&times;</span>
    <h3>Resident Profile</h3>
    <div id="userDetailsContent"></div>
  </div>
  </div>

<script>
function showUserDetails(userId){
  fetch('admin.php?action=get_user_details&id=' + userId)
    .then(r => r.json())
    .then(data => {
      if(!data.success){ alert('Error loading user details: ' + (data.message||'Unknown error')); return; }
      const d = data.details || {};
      const fullName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const content = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Personal</h4>
            ${fullName?`<p><strong>Name:</strong> ${fullName}</p>`:''}
            ${d.sex?`<p><strong>Sex:</strong> ${d.sex}</p>`:''}
            ${d.birthdate?`<p><strong>Birthdate:</strong> ${new Date(d.birthdate).toLocaleDateString()}</p>`:''}
            ${d.email?`<p><strong>Email:</strong> ${d.email}</p>`:''}
            ${d.phone?`<p><strong>Phone:</strong> ${d.phone}</p>`:''}
          </div>
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Residence</h4>
            ${d.house_number?`<p><strong>House No.:</strong> ${d.house_number}</p>`:''}
            ${d.address?`<p><strong>Address:</strong> ${d.address}</p>`:''}
            ${d.created_at?`<p><strong>Registered:</strong> ${new Date(d.created_at).toLocaleString()}</p>`:''}
            ${d.status?`<p><strong>Status:</strong> ${d.status.charAt(0).toUpperCase()+d.status.slice(1)}</p>`:''}
          </div>
        </div>`;
      document.getElementById('userDetailsContent').innerHTML = content;
      document.getElementById('userModal').style.display = 'block';
    })
    .catch(err => { console.error(err); alert('Error loading user details'); });
}

function closeUserModal(){
  document.getElementById('userModal').style.display = 'none';
}

window.addEventListener('click', function(event){
  const umodal = document.getElementById('userModal');
  if(event.target === umodal){ umodal.style.display = 'none'; }
});
</script>

</main>
</div>
<div id="toastContainer" class="toast-container" aria-live="polite"></div>
</body>
</html>
