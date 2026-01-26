<?php
session_start();
include 'connect.php';

// Ensure scanned_at columns exist in relevant tables (Self-healing schema)
function ensureScannedAtColumns($con) {
    $tables = ['guest_forms', 'reservations', 'resident_reservations'];
    foreach ($tables as $tbl) {
        $res = $con->query("SHOW COLUMNS FROM $tbl LIKE 'scanned_at'");
        if ($res && $res->num_rows === 0) {
            $con->query("ALTER TABLE $tbl ADD COLUMN scanned_at DATETIME NULL");
        }
    }
}
ensureScannedAtColumns($con);

$code = isset($_GET['code']) ? trim($_GET['code']) : '';
$error = '';
if ($code === '') { $error = 'Status code is required.'; }

$data = null;
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// Authorization Check
// Strict check: Only 'guard' role can see the confirm button. Admins/Residents/Visitors cannot.
$isAuthorizedScanner = (isset($_SESSION['role']) && $_SESSION['role'] === 'guard');

// URL Construction Helpers
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/\\');
$verificationLink = sprintf('%s://%s%s/qr_view.php?code=%s', $scheme, $host, $basePath, urlencode($code));
$qrImgUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($verificationLink);

// -------------------------------------------------------------------------
// POST Action: Mark as Scanned
// -------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'confirm_entry' && !empty($_POST['ref_code']) && !empty($_POST['source_table']) && !empty($_POST['source_id'])) {
    if (!$isAuthorizedScanner) {
        // Silently fail or redirect if not authorized
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    $tbl = $_POST['source_table'];
    $sid = intval($_POST['source_id']);
    $ref = $_POST['ref_code'];
    
    // Security check: only allow known tables
    if (in_array($tbl, ['guest_forms', 'reservations', 'resident_reservations'])) {
        $upStmt = $con->prepare("UPDATE $tbl SET scanned_at = NOW() WHERE id = ? AND ref_code = ?");
        $upStmt->bind_param('is', $sid, $ref);
        $upStmt->execute();
        $upStmt->close();
        
        // Reload to show updated status
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// -------------------------------------------------------------------------
// Data Fetching & Logic
// -------------------------------------------------------------------------
if (empty($error)) {

    // 1. Check GUEST FORMS
    if (!$data) {
        $stmtGF = $con->prepare("SELECT gf.*, u.house_number AS res_house_number, u.first_name AS res_first_name, u.last_name AS res_last_name FROM guest_forms gf LEFT JOIN users u ON gf.resident_user_id = u.id WHERE gf.ref_code = ?");
        $stmtGF->bind_param('s', $code);
        $stmtGF->execute();
        $resGF = $stmtGF->get_result();
        $stmtGF->close();

        if ($resGF && $resGF->num_rows > 0) {
            $row = $resGF->fetch_assoc();
            
            // Basic Fields
            $id = $row['id'];
            $table = 'guest_forms';
            $statusVal = $row['approval_status'] ?? 'pending';
            $visitDate = $row['visit_date'] ?? null;
            $scannedAt = $row['scanned_at'] ?? null;
            
            // Expiry Logic
            $isExpired = false;
            if ($visitDate && $visitDate < $today) { $isExpired = true; }
            if ($statusVal === 'approved' && $isExpired) { $statusVal = 'expired'; }

            $fullName = trim(implode(' ', array_filter([$row['visitor_first_name']??'', $row['visitor_middle_name']??'', $row['visitor_last_name']??''])));
            $residentName = trim(($row['res_first_name']??'') . ' ' . ($row['res_last_name']??''));
            
            $data = [
                'id' => $id,
                'table' => $table,
                'code' => $row['ref_code'],
                'type_label' => "Resident's Guest",
                'name' => $fullName ?: 'Guest',
                'resident_name' => $residentName,
                'status_raw' => $statusVal,
                'is_expired' => $isExpired,
                'scanned_at' => $scannedAt,
                'valid_date' => $visitDate ? date('M j, Y', strtotime($visitDate)) : 'N/A',
                'details' => [
                    'Purpose' => $row['purpose'] ?? '-',
                    'Address' => $row['res_house_number'] ?? '-'
                ]
            ];
        }
    }

    // 2. Check RESERVATIONS (Entry Pass linked)
    if (!$data) {
        $stmt = $con->prepare("SELECT r.*, e.full_name, e.middle_name, e.last_name, u.first_name, u.middle_name, u.last_name as u_last_name, u.user_type FROM reservations r LEFT JOIN entry_passes e ON r.entry_pass_id = e.id LEFT JOIN users u ON r.user_id = u.id WHERE r.ref_code = ?");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            
            $id = $row['id'];
            $table = 'reservations';
            $statusVal = $row['approval_status'] ?? 'pending';
            $startDate = $row['start_date'] ?? null;
            $endDate = $row['end_date'] ?? null;
            $scannedAt = $row['scanned_at'] ?? null;
            
            // Expiry
            $isExpired = false;
            if ($endDate && $endDate < $today) { $isExpired = true; }
            if ($statusVal === 'approved' && $isExpired) { $statusVal = 'expired'; }
            
            // Name & Type
            $uType = isset($row['user_type']) ? strtolower($row['user_type']) : '';
            $hasEntryPass = !empty($row['entry_pass_id']);
            
            if ($hasEntryPass) {
                $name = trim(implode(' ', array_filter([$row['full_name']??'', $row['middle_name']??'', $row['last_name']??''])));
                $typeLabel = 'Visitor';
            } else {
                $name = trim(implode(' ', array_filter([$row['first_name']??'', $row['middle_name']??'', $row['u_last_name']??''])));
                $typeLabel = ($uType === 'visitor') ? 'Visitor' : 'Resident';
            }

            $validWindow = ($startDate ? date('M j', strtotime($startDate)) : '?') . ' - ' . ($endDate ? date('M j, Y', strtotime($endDate)) : '?');

            $data = [
                'id' => $id,
                'table' => $table,
                'code' => $row['ref_code'],
                'type_label' => $typeLabel,
                'name' => $name ?: 'Unknown',
                'resident_name' => '', 
                'status_raw' => $statusVal,
                'is_expired' => $isExpired,
                'scanned_at' => $scannedAt,
                'valid_date' => $validWindow,
                'details' => [
                    'Amenity' => $row['amenity'] ?? '-',
                    'Time' => ($row['start_time'] ?? '') . ' - ' . ($row['end_time'] ?? ''),
                    'Persons' => $row['persons'] ?? '-'
                ]
            ];
        }
    }

    // 3. Check RESIDENT RESERVATIONS
    if (!$data) {
        $stmt2 = $con->prepare("SELECT rr.*, u.first_name, u.middle_name, u.last_name FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id WHERE rr.ref_code = ?");
        $stmt2->bind_param('s', $code);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $stmt2->close();

        if ($res2 && $res2->num_rows > 0) {
            $row = $res2->fetch_assoc();

            $id = $row['id'];
            $table = 'resident_reservations';
            $statusVal = $row['approval_status'] ?? 'pending';
            $startDate = $row['start_date'] ?? null;
            $endDate = $row['end_date'] ?? null;
            $scannedAt = $row['scanned_at'] ?? null;

            $isExpired = false;
            if ($endDate && $endDate < $today) { $isExpired = true; }
            if ($statusVal === 'approved' && $isExpired) { $statusVal = 'expired'; }

            $name = trim(implode(' ', array_filter([$row['first_name']??'', $row['middle_name']??'', $row['last_name']??''])));
            $validWindow = ($startDate ? date('M j', strtotime($startDate)) : '?') . ' - ' . ($endDate ? date('M j, Y', strtotime($endDate)) : '?');

            $data = [
                'id' => $id,
                'table' => $table,
                'code' => $row['ref_code'],
                'type_label' => 'Resident',
                'name' => $name ?: 'Resident',
                'resident_name' => '',
                'status_raw' => $statusVal,
                'is_expired' => $isExpired,
                'scanned_at' => $scannedAt,
                'valid_date' => $validWindow,
                'details' => [
                    'Amenity' => $row['amenity'] ?? '-',
                    'Time' => ($row['start_time'] ?? '') . ' - ' . ($row['end_time'] ?? '')
                ]
            ];
        }
    }

    // Final Validation State Calculation
    if ($data) {
        $s = $data['status_raw'];
        $exp = $data['is_expired'];
        $scan = $data['scanned_at'];
        
        if ($scan) {
            $data['ui_state'] = 'scanned';
            $data['ui_title'] = 'ALREADY SCANNED';
            $data['ui_color'] = '#f97316'; // Orange
            $data['ui_msg'] = 'This pass has already been used on ' . date('M j, Y h:i A', strtotime($scan));
        } elseif ($exp) {
            $data['ui_state'] = 'expired';
            $data['ui_title'] = 'EXPIRED PASS';
            $data['ui_color'] = '#ef4444'; // Red
            $data['ui_msg'] = 'This pass has expired.';
        } elseif ($s === 'approved') {
            $data['ui_state'] = 'valid';
            $data['ui_title'] = 'VALID ENTRY PASS';
            $data['ui_color'] = '#22c55e'; // Green
            $data['ui_msg'] = 'Access Granted';
        } elseif ($s === 'pending') {
            $data['ui_state'] = 'pending';
            $data['ui_title'] = 'PENDING APPROVAL';
            $data['ui_color'] = '#eab308'; // Yellow
            $data['ui_msg'] = 'This pass is awaiting approval.';
        } else {
            $data['ui_state'] = 'invalid';
            $data['ui_title'] = 'INVALID / DENIED';
            $data['ui_color'] = '#ef4444'; // Red
            $data['ui_msg'] = 'Access Denied. Status: ' . ucfirst($s);
        }
    } else {
        $error = 'Invalid or Unknown QR Code.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entry Pass Details - VictorianPass</title>
    <link rel="icon" type="image/png" href="images/logo.svg" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { margin: 0; background: #121212; color: #fff; display: flex; flex-direction: column; align-items: center; min-height: 100vh; padding: 20px; }
        .container { width: 100%; max-width: 420px; background: #1e1e1e; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        
        .header { background: #000; padding: 15px; text-align: center; border-bottom: 1px solid #333; }
        .header img { height: 32px; vertical-align: middle; }
        .header span { margin-left: 10px; font-weight: 600; font-size: 1.1rem; vertical-align: middle; color: #e5ddc6; }

        .status-banner { padding: 30px 20px; text-align: center; color: #fff; }
        .status-title { font-size: 1.8rem; font-weight: 800; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; line-height: 1.2; }
        .status-msg { font-size: 1rem; opacity: 0.9; font-weight: 500; }

        .qr-section { background: #fff; padding: 20px; text-align: center; border-bottom: 1px solid #eee; }
        .qr-section img { width: 180px; height: 180px; display: block; margin: 0 auto; }
        .qr-note { color: #333; margin-top: 10px; font-size: 0.85rem; font-weight: 500; }

        .details { padding: 24px; }
        .detail-row { display: flex; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid #333; padding-bottom: 8px; }
        .detail-row:last-child { border-bottom: none; }
        .label { color: #aaa; font-size: 0.9rem; }
        .value { font-weight: 600; text-align: right; color: #eee; font-size: 1rem; }
        
        .section-title { color: #9bd08f; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; font-weight: 700; }

        .actions { padding: 20px; background: #252525; text-align: center; }
        .btn { display: block; width: 100%; padding: 16px; border-radius: 12px; border: none; font-size: 1.1rem; font-weight: 700; cursor: pointer; transition: transform 0.2s; text-decoration: none; color: #fff; }
        .btn:active { transform: scale(0.98); }
        .btn-confirm { background: #22c55e; color: #000; box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3); }
        .btn-disabled { background: #444; color: #888; cursor: not-allowed; }
        .btn-download { background: #3b82f6; color: #fff; margin-top: 10px; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }

        .footer { margin-top: 20px; color: #666; font-size: 0.8rem; text-align: center; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
</head>
<body>

    <?php if ($error): ?>
        <div class="container" style="padding:20px; text-align:center;">
            <h2 style="color:#ef4444;">Error</h2>
            <p><?php echo htmlspecialchars($error); ?></p>
            <a href="javascript:history.back()" style="color:#aaa;">Go Back</a>
        </div>
    <?php elseif ($data): ?>
        <div class="container" id="passCard">
            <!-- Header -->
            <div class="header">
                <img src="images/logo.svg" alt="Logo">
                <span>Victorian Heights</span>
            </div>

            <!-- Status Banner -->
            <div class="status-banner" style="background-color: <?php echo $data['ui_color']; ?>;">
                <div class="status-title"><?php echo htmlspecialchars($data['ui_title']); ?></div>
                <div class="status-msg"><?php echo htmlspecialchars($data['ui_msg']); ?></div>
            </div>

            <!-- QR Code (Visible only if Valid/Pending) -->
            <?php if ($data['ui_state'] === 'valid' || $data['ui_state'] === 'pending'): ?>
            <div class="qr-section">
                <img src="<?php echo $qrImgUrl; ?>" alt="QR Code">
                <div class="qr-note">Present this QR code to the guard</div>
            </div>
            <?php endif; ?>

            <!-- Pass Details -->
            <div class="details">
                <div class="section-title">Pass Details</div>
                
                <div class="detail-row">
                    <span class="label">Pass Type</span>
                    <span class="value"><?php echo htmlspecialchars($data['type_label']); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="label">Name</span>
                    <span class="value"><?php echo htmlspecialchars($data['name']); ?></span>
                </div>

                <?php if (!empty($data['resident_name'])): ?>
                <div class="detail-row">
                    <span class="label">Referred by Resident</span>
                    <span class="value"><?php echo htmlspecialchars($data['resident_name']); ?></span>
                </div>
                <?php endif; ?>

                <div class="detail-row">
                    <span class="label">Ref Code</span>
                    <span class="value" style="font-family:monospace; letter-spacing:1px;"><?php echo htmlspecialchars($data['code']); ?></span>
                </div>

                <div class="detail-row">
                    <span class="label">Validity</span>
                    <span class="value"><?php echo htmlspecialchars($data['valid_date']); ?></span>
                </div>

                <!-- Extra Details -->
                <?php foreach ($data['details'] as $k => $v): if ($v && $v !== '-'): ?>
                <div class="detail-row">
                    <span class="label"><?php echo htmlspecialchars($k); ?></span>
                    <span class="value"><?php echo htmlspecialchars($v); ?></span>
                </div>
                <?php endif; endforeach; ?>

            </div>

            <!-- Actions -->
            <div class="actions">
                <?php if ($data['ui_state'] === 'valid'): ?>
                    <!-- Download Button for Residents/Visitors -->
                    <button onclick="downloadPass()" class="btn btn-download">
                        <i class="fa-solid fa-download"></i> Download Entry Pass
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer">
            VictorianPass Validation System &copy; <?php echo date('Y'); ?>
        </div>

    <?php endif; ?>

    <script>
    function downloadPass() {
        var element = document.getElementById('passCard');
        if(!element) return;
        
        // Temporarily hide the download button during capture
        var btn = document.querySelector('.btn-download');
        if(btn) btn.style.display = 'none';

        html2canvas(element, {
            scale: 2, // Higher resolution
            useCORS: true,
            backgroundColor: null
        }).then(function(canvas) {
            var link = document.createElement('a');
            link.download = 'EntryPass_<?php echo $data['code'] ?? 'VP'; ?>.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
            
            // Show button again
            if(btn) btn.style.display = 'block';
        }).catch(function(err) {
            console.error('Download failed', err);
            if(btn) btn.style.display = 'block';
            alert('Could not generate image. Please screenshot instead.');
        });
    }
    </script>

</body>
</html>
