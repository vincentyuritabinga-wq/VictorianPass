<?php
session_start();
require_once 'connect.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
  header('Location: login.php');
  exit;
}

$userId = intval($_SESSION['user_id']);
$user = null;

// Fetch resident details for the form
if ($con) {
  $stmt = mysqli_prepare($con, "SELECT id, first_name, middle_name, last_name, email, phone, birthdate, house_number, address, status FROM users WHERE id = ?");
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) === 1) {
      $user = mysqli_fetch_assoc($result);
    }
    mysqli_stmt_close($stmt);
  }
}

if (!$user) {
  header('Location: mainpage.php');
  exit;
}

$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

// Handle Incident Report Submission
$reportSuccess = false;
$reportSuccessMessage = '';
$reportErrors = [];
$reportValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_report') {
    $complainant = trim($_POST['complainant'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $report_date = trim($_POST['report_date'] ?? '');
    $addr = trim($_POST['address'] ?? '');
    $other = trim($_POST['other'] ?? '');
    $natureArr = isset($_POST['nature']) && is_array($_POST['nature']) ? $_POST['nature'] : [];

    $reportValues = [
        'subject' => $subject,
        'report_date' => $report_date,
        'address' => $addr,
        'other' => $other,
        'nature' => $natureArr
    ];

    if ($subject === '') { $reportErrors[] = 'Complainee is required.'; }
    if ($addr === '') { $reportErrors[] = 'Address is required.'; }
    if ($report_date === '') { $reportErrors[] = 'Date is required.'; }
    if ($report_date !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $report_date);
        if (!($dt && $dt->format('Y-m-d') === $report_date)) { $reportErrors[] = 'Date format is invalid.'; }
    }
    if (count($natureArr) === 0) { $reportErrors[] = 'Please select at least one nature of concern.'; }

    $uploadDir = 'resident_reports_uploads/';
    $allowed_exts = ['jpg','jpeg','png','pdf','docx'];
    $saved_files = [];
    $upload_errors = [];
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
    
    if (!empty($_FILES['proof']) && is_array($_FILES['proof']['name'])) {
        $names = $_FILES['proof']['name'];
        $tmps  = $_FILES['proof']['tmp_name'];
        $errs  = $_FILES['proof']['error'];
        $sizes = $_FILES['proof']['size'];
        for ($i = 0; $i < count($names); $i++) {
            if ($errs[$i] !== UPLOAD_ERR_OK) { continue; }
            $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) { $upload_errors[] = 'File type is not allowed: ' . $names[$i]; continue; }
            if ($sizes[$i] > 10 * 1024 * 1024) { $upload_errors[] = 'File size exceeds 10 MB: ' . $names[$i]; continue; }
            $safeName = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $names[$i]);
            $newName = 'report_' . time() . '_' . $safeName;
            $dest = $uploadDir . $newName;
            if (move_uploaded_file($tmps[$i], $dest)) { $saved_files[] = $dest; }
        }
    }
    if (!empty($upload_errors)) { $reportErrors = array_merge($reportErrors, $upload_errors); }

    if (empty($reportErrors)) {
        // Ensure tables exist
        $con->query("CREATE TABLE IF NOT EXISTS incident_reports (
          id INT AUTO_INCREMENT PRIMARY KEY,
          complainant VARCHAR(150) NOT NULL,
          subject VARCHAR(150) NULL,
          address VARCHAR(255) NOT NULL,
          nature VARCHAR(255) NULL,
          other_concern VARCHAR(255) NULL,
          user_id INT NULL,
          report_date DATE NULL,
          status ENUM('new','in_progress','resolved','rejected','cancelled') DEFAULT 'new',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL,
          escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0,
          escalated_by_guard_id INT NULL,
          escalated_at DATETIME NULL,
          handled_by_guard_id INT NULL,
          handled_at DATETIME NULL,
          INDEX idx_status (status),
          INDEX idx_user_id (user_id),
          INDEX idx_escalated (escalated_to_admin)
        ) ENGINE=InnoDB");
        
        // Ensure columns exist if table was already created without them
        $colsToCheck = [
            'subject' => "VARCHAR(150) NULL",
            'report_date' => "DATE NULL",
            'escalated_to_admin' => "TINYINT(1) NOT NULL DEFAULT 0",
            'escalated_by_guard_id' => "INT NULL",
            'escalated_at' => "DATETIME NULL",
            'handled_by_guard_id' => "INT NULL",
            'handled_at' => "DATETIME NULL"
        ];
        foreach ($colsToCheck as $col => $def) {
            $check = $con->query("SHOW COLUMNS FROM incident_reports LIKE '$col'");
            if ($check && $check->num_rows === 0) {
                $con->query("ALTER TABLE incident_reports ADD COLUMN $col $def");
            }
        }

        $con->query("CREATE TABLE IF NOT EXISTS incident_proofs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          report_id INT NOT NULL,
          file_path VARCHAR(255) NOT NULL,
          uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_report_id (report_id),
          FOREIGN KEY (report_id) REFERENCES incident_reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $natureStr = implode(', ', array_map('strval', $natureArr));
        $uID = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
        $stmtR = $con->prepare("INSERT INTO incident_reports (complainant, subject, address, nature, other_concern, user_id, report_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmtR) {
            $stmtR->bind_param('sssssis', $complainant, $subject, $addr, $natureStr, $other, $uID, $report_date);
            if ($stmtR->execute()) {
                $report_id = $stmtR->insert_id;
                $stmtR->close();
                if (!empty($saved_files)) {
                    foreach ($saved_files as $p) {
                        $stmtP = $con->prepare("INSERT INTO incident_proofs (report_id, file_path) VALUES (?, ?)");
                        if ($stmtP) { $stmtP->bind_param('is', $report_id, $p); $stmtP->execute(); $stmtP->close(); }
                    }
                }
                $reportSuccess = true;
                $reportSuccessMessage = 'Your report has been successfully submitted. The Guard will review it shortly.';
                $reportValues = [];
            } else {
                $reportErrors[] = 'Failed to save report database entry.';
            }
        } else {
            $reportErrors[] = 'Failed to prepare report statement.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report Incident - Victorian Heights</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/guestform.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  /* Custom styles for Report Incident */
  .explanation-panel {
      background-color: #f9f9f9;
      border-left: 4px solid #23412e;
      padding: 20px;
      border-radius: 4px;
      margin-bottom: 30px;
  }
  .explanation-panel h3 { margin-top: 0; color: #23412e; font-size: 1.2rem; }
  .explanation-panel p { color: #555; font-size: 0.95rem; line-height: 1.6; margin-bottom: 15px; }
  .explanation-links a { color: #23412e; font-weight: 600; margin-right: 20px; text-decoration: none; border-bottom: 1px dashed #23412e; }
  
  .report-header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #f0f0f0; }
  .report-header h2 { margin: 0 0 5px 0; color: #23412e; font-size: 1.5rem; }
  .report-sub { color: #777; font-size: 0.9rem; margin-bottom: 15px; }
  .report-title { background: #23412e; color: #fff; display: inline-block; padding: 8px 24px; border-radius: 20px; font-weight: 600; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; }

  .checkbox-group { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 5px; }
  .checkbox-item { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer; }
  .checkbox-item input { width: 18px; height: 18px; margin: 0; accent-color: #23412e; }

  .error-box { background-color: #fee2e2; border: 1px solid #fecaca; color: #b91c1c; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
  
  .entry-form { max-width: 800px; }
  .top-actions { width: 100%; max-width: 800px; margin-bottom: 20px; }
  .static-group { margin-bottom: 20px; }
  .static-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #23412e; }
</style>
</head>
<body>

    <header class="navbar">
      <div class="logo-wrap">
        <div class="logo">
          <img src="images/logo.svg" alt="Logo">
          <div class="brand-text">
            <h1>VictorianPass</h1>
            <p>Victorian Heights Subdivision</p>
          </div>
        </div>
      </div>
    </header>

    <div class="hero">
        <div class="top-actions">
            <button type="button" class="btn-secondary back-account-btn" onclick="window.location.href='profileresident.php'">&#8592; Back to Account</button>
        </div>

        <div class="entry-form">
            <!-- Explanation / Intro -->
            <div class="explanation-panel">
                <h3>Resident Incident Report</h3>
                <p>Reporting complaints ensures that every resident’s voice is heard and that community standards are properly maintained. Please be responsible when filing a complaint.</p>
                <div class="explanation-links">
                    <a href="#" onclick="alert('Terms and Conditions'); return false;">Terms and Conditions</a>
                    <a href="#" onclick="alert('Rules and Regulations'); return false;">Rules and Regulations</a>
                </div>
            </div>

            <?php if (!empty($reportErrors)): ?>
                <div class="error-box">
                    <?php foreach ($reportErrors as $e): ?><div><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($reportSuccess): ?>
                <div style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; padding:15px; border-radius:8px; margin-bottom:20px; text-align:center;">
                    <i class="fa-solid fa-check-circle" style="font-size:1.2rem; margin-bottom:8px; display:block;"></i>
                    <strong><?php echo htmlspecialchars($reportSuccessMessage); ?></strong>
                </div>
            <?php endif; ?>

            <form id="reportForm" method="POST" enctype="multipart/form-data">
              <input type="hidden" name="action" value="submit_report">
              <div class="report-header">
                <h2>Victorian Heights Subdivision</h2>
                <div class="report-sub">Dahlia Fairview, BRGY. Sauyo, Quezon City</div>
                <div class="report-title">CASE REPORT FORM</div>
              </div>

              <input type="hidden" id="complainant" name="complainant" value="<?php echo htmlspecialchars($fullName); ?>">

              <div class="form-group">
                  <input type="text" id="subject" name="subject" placeholder=" " value="<?php echo htmlspecialchars($reportValues['subject'] ?? ''); ?>" required>
                  <label for="subject">Complainee / Subject Name*</label>
              </div>

          <div class="form-row">
              <div class="form-group">
                  <input type="text" id="address" name="address" placeholder=" " value="<?php echo htmlspecialchars($reportValues['address'] ?? ''); ?>" required>
                  <label for="address">Address / Location*</label>
              </div>
              <div class="form-group">
                  <input type="date" id="report_date" name="report_date" value="<?php echo htmlspecialchars($reportValues['report_date'] ?? ''); ?>" required>
                  <label for="report_date">Date of Incident*</label>
              </div>
          </div>

          <div class="static-group">
              <label>Nature of Concern / Complaint*</label>
              <div class="checkbox-group">
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Noise"> Noise</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Harassment"> Harassment</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Vandalism"> Vandalism</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Theft"> Theft</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Parking"> Parking</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Pet Issue"> Pet Issue</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Garbage"> Garbage</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Other"> Other</label>
              </div>
          </div>

          <div class="static-group">
              <label for="other">If Other, please specify details:</label>
              <textarea id="other" name="other" rows="4" placeholder="Describe the incident..."><?php echo htmlspecialchars($reportValues['other'] ?? ''); ?></textarea>
          </div>

          <div class="static-group">
              <label>Attach Proof (Images/Docs)</label>
              <label class="upload-box">
                  <input type="file" name="proof[]" multiple accept=".jpg,.jpeg,.png,.pdf,.docx" style="display:none;" onchange="updateFileList(this)">
                  <div class="upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                  <div class="upload-text">Click to upload files</div>
                  <div class="upload-hint">Max 10MB per file (JPG, PNG, PDF, DOCX)</div>
                  <div id="fileList" style="margin-top:10px; font-size:0.85rem; color:#23412e;"></div>
              </label>
          </div>

          <button type="submit" class="btn btn-login" style="width:100%; margin-top:20px; font-size:1.1rem; padding:15px;">Submit Report</button>
        </form>
    </div>

<script>
function updateFileList(input) {
    const list = document.getElementById('fileList');
    list.innerHTML = '';
    if (input.files && input.files.length > 0) {
        let ul = document.createElement('ul');
        ul.style.listStyle = 'none';
        ul.style.padding = '0';
        for (let i = 0; i < input.files.length; i++) {
            let li = document.createElement('li');
            li.textContent = input.files[i].name;
            ul.appendChild(li);
        }
        list.appendChild(ul);
    }
}

function openModal(id) {
    document.getElementById(id).style.display = "flex";
}

function closeModal(id) {
    document.getElementById(id).style.display = "none";
}

window.onclick = function(e) {
    if (e.target.className === 'modal') {
        e.target.style.display = "none";
    }
}
</script>

<!-- Terms Modal -->
<div class="modal" id="termsModal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('termsModal')">&times;</span>
        <h3>Terms and Conditions</h3>
        <p>By submitting this complaint form, you agree to the following:</p>
        <p style="text-align: left; font-size: 0.9rem;">
            1. Complaints must be submitted in good faith and based on facts.<br>
            2. False or malicious complaints may result in penalties.<br>
            3. The HOA reserves the right to investigate and validate all concerns.<br>
            4. Confidentiality will be respected, but information may be shared when necessary.<br>
            5. Filing a complaint does not guarantee resolution in favor of the complainant.<br>
            6. All parties involved will be treated fairly during the resolution process.
        </p>
    </div>
</div>

<!-- Rules Modal -->
<div class="modal" id="rulesModal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('rulesModal')">&times;</span>
        <h3>Rules and Regulations</h3>
        <p>In using this website you are deemed to have read and agreed to the following rules and regulations:</p>
        <div style="text-align: left; max-height: 300px; overflow-y: auto; font-size: 0.9rem;">
            <p><strong>1. Use Accurate Information</strong><br>All users must provide truthful and complete information when signing up.</p>
            <p><strong>2. Respect Assigned Roles</strong><br>Users must act according to their roles: Residents may book amenities/register visitors; Visitors must present valid QR codes.</p>
            <p><strong>3. QR Code Use</strong><br>QR codes are personal, time-bound, and must not be shared or reused.</p>
            <p><strong>4. Follow Subdivision Policies</strong><br>All users must follow community rules. Misuse of the system may lead to suspension or blacklisting.</p>
        </div>
    </div>
</div>
</body>
</html>
