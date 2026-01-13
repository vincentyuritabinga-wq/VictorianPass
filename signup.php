<?php 
include("connect.php");
session_start();
$registration_success = false;

$verified_house = isset($_GET['house_number']) ? trim($_GET['house_number']) : '';

function ensureVisitorSchema($con){
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'user_type'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strpos($row['Type'], "visitor") === false) {
      $con->query("ALTER TABLE users MODIFY COLUMN user_type ENUM('resident','visitor') DEFAULT 'resident'");
    }
  }
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'house_number'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strtoupper($row['Null']) === 'NO') {
      $con->query("ALTER TABLE users MODIFY COLUMN house_number VARCHAR(50) NULL");
    }
  }
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'address'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strtoupper($row['Null']) === 'NO') {
      $con->query("ALTER TABLE users MODIFY COLUMN address VARCHAR(255) NULL");
    }
  }
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'valid_id_path'");
  if ($res && $res->num_rows === 0) {
    $con->query("ALTER TABLE users ADD COLUMN valid_id_path VARCHAR(255) NULL");
  }
}
ensureVisitorSchema($con);

// AJAX endpoint: check email availability
if (isset($_GET['action']) && $_GET['action'] === 'check_email') {
  header('Content-Type: application/json');
  $email = isset($_GET['email']) ? strtolower(trim($_GET['email'])) : '';
  $resp = ['ok' => false, 'message' => 'Invalid email'];
  if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
      $stmt = $con->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
      $stmt->bind_param('s', $email);
      $stmt->execute();
      $stmt->store_result();
      if ($stmt->num_rows > 0) {
        $resp = ['ok' => false, 'message' => 'Email already exists.'];
      } else {
        $resp = ['ok' => true, 'message' => 'Available'];
      }
      $stmt->close();
    } catch (Throwable $e) {
      $resp = ['ok' => false, 'message' => 'Lookup failed'];
    }
  }
  echo json_encode($resp);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $first_name = trim($_POST['first_name']);
  $middle_name = trim($_POST['middle_name']);
  $last_name = trim($_POST['last_name']);
  $phone = trim($_POST['phone']);
  $email = strtolower(trim($_POST['email']));
  $password = trim($_POST['password']);
  $confirm_password = trim($_POST['confirm_password']);
  $sex = $_POST['sex'];
  $birthdate = $_POST['birthdate'];
  $house_number = isset($_POST['house_number']) ? trim($_POST['house_number']) : '';
  $address = isset($_POST['address']) ? trim($_POST['address']) : '';
  $terms_agreed = isset($_POST['terms_agreed']) ? $_POST['terms_agreed'] : '0';
  $is_visitor = isset($_POST['is_visitor']) && $_POST['is_visitor'] === '1';
  $serverErrors = [];

  // File Upload Logic
  $valid_id_path = null;
  if ($is_visitor && isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
      $uploadDir = 'uploads/ids/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
      $fileName = time() . '_' . basename($_FILES['valid_id']['name']);
      $targetPath = $uploadDir . $fileName;
      if (move_uploaded_file($_FILES['valid_id']['tmp_name'], $targetPath)) {
          $valid_id_path = $targetPath;
      } else {
          $serverErrors['valid_id'] = 'Failed to upload Valid ID.';
      }
  } elseif ($is_visitor && (!isset($_FILES['valid_id']) || $_FILES['valid_id']['error'] !== UPLOAD_ERR_OK)) {
      $serverErrors['valid_id'] = 'Valid ID is required for visitors.';
  }

  if ($password !== $confirm_password) {
    $serverErrors['confirm_password'] = 'Passwords do not match.';
  }

  // Password strength validation
  $commonPasswords = file('common_passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (in_array($password, $commonPasswords)) {
    $serverErrors['password'] = 'Password is too weak.';
  }
  if (strlen($password) < 6) {
    $serverErrors['password'] = 'Password must be at least 6 characters long.';
  }
  if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
    $serverErrors['password'] = 'Password must include uppercase, lowercase, number, and special character.';
  }

  if ($terms_agreed !== '1') {
    $serverErrors['terms'] = 'Please read and agree to the Terms & Conditions.';
  }

  if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $serverErrors['email'] = 'Invalid email address.';
  } else {
    $checkEmail = $con->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();
    if ($checkEmail->num_rows > 0) {
      $serverErrors['email'] = 'This email is already registered.';
    }
    $checkEmail->close();
  }

  if (!$is_visitor) {
    if (empty($house_number)) {
      $serverErrors['houseHidden'] = 'Please verify your House Number before signing up.';
    } else {
      $checkHouse = $con->prepare("SELECT id FROM houses WHERE house_number = ?");
      $checkHouse->bind_param("s", $house_number);
      $checkHouse->execute();
      $checkHouse->store_result();
      if ($checkHouse->num_rows === 0) {
        $serverErrors['houseHidden'] = 'Invalid house number.';
      }
      $checkHouse->close();

      $checkDuplicate = $con->prepare("SELECT id FROM users WHERE house_number = ?");
      $checkDuplicate->bind_param("s", $house_number);
      $checkDuplicate->execute();
      $checkDuplicate->store_result();
      if ($checkDuplicate->num_rows > 0) {
        $serverErrors['houseHidden'] = 'This house number is already in use.';
      }
      $checkDuplicate->close();
    }
    
    if (empty($address)) {
      $serverErrors['addressField'] = 'Please enter your full address.';
    }
  } else {
    $house_number = null;
    $address = null;
  }

  if (empty($phone) || !preg_match('/^09\d{9}$/', $phone)) {
    $serverErrors['phone'] = 'Phone number must be 11 digits and start with 09.';
  }

  if (empty($serverErrors)) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $user_type = $is_visitor ? 'visitor' : 'resident';
    $stmt = $con->prepare("INSERT INTO users 
      (first_name, middle_name, last_name, phone, email, password, sex, birthdate, house_number, address, user_type, valid_id_path)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssssssss", $first_name, $middle_name, $last_name, $phone, $email, $hashed, $sex, $birthdate, $house_number, $address, $user_type, $valid_id_path);

    if ($stmt->execute()) {
      $newUserId = $stmt->insert_id;
      $_SESSION['user_id'] = $newUserId;
      $_SESSION['user_type'] = $user_type;
      
      $redirect = ($user_type === 'resident') ? 'profileresident.php' : 'mainpage.php';

      if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        echo json_encode(['success' => true, 'redirect' => $redirect]);
        exit;
      }

      header("Location: $redirect");
      exit;
    } else {
      if ($con->errno === 1062) {
        $serverErrors['email'] = 'This email is already registered.';
      } else {
        $serverErrors['form'] = 'An error occurred. Please try again.';
      }
    }
  }

  if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    echo json_encode(['success' => false, 'errors' => $serverErrors]);
    exit;
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign Up</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/signup.css">
  <style>
    .success-banner {
      background: #e6ffed;
      color: #135f2a;
      border: 1px solid #b7e3c3;
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 12px;
      text-align: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.12);
      font-weight: 600;
    }
    .form-group { position: relative; flex: 1; }
    .form-group input[type="date"], .form-group select {
      width: 100%; padding: 12px; border: 1px solid #ccc;
      border-radius: 8px; font-size: 0.95rem; font-family: 'Poppins', sans-serif;
    }
    .form-group label {
      position: absolute; left: 12px; top: 12px;
      color: #888; font-size: 0.95rem;
      transition: 0.2s ease all; background: #fff; padding: 0 4px;
    }
    .form-group input[type="date"]:focus + label,
    .form-group input[type="date"]:not(:placeholder-shown) + label,
    .form-group select:focus + label,
    .form-group select:valid + label {
      top: -8px; left: 8px; font-size: 0.75rem; color: #23412e;
    }

    .instructions {
      font-size: 0.75rem;
      color: #555;
      margin-top: 10px;
      text-align: center;
    }

    .verify-link {
      display: flex;
      align-items: center;
      gap: 8px;
      color: green;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
    }

    .verify-link img {
      width: 20px;
      height: 20px;
    }

    .verify-link:hover {
      text-decoration: underline;
    }

    .field-warning {
      color: #333;
      font-size: 0.85rem;
      margin-top: 6px;
      background: #fff;
      border-left: 4px solid #c0392b;
      box-shadow: 0 2px 8px rgba(0,0,0,0.12);
      border-radius: 8px;
      padding: 8px 10px;
      display: flex; /* notification-style inline card */
      align-items: flex-start;
      gap: 8px;
      position: relative;
      z-index: 2; /* ensure close button clickable over toggle icon */
    }
    .password-field { position: relative; display: block; }
    .toggle-password { 
      position: absolute; 
      right: 10px; 
      top: 50%; 
      transform: translateY(-50%); 
      z-index: 10; 
      cursor: pointer;
    }
    .field-warning .warn-icon {
      width: 18px; height: 18px; border-radius: 50%;
      background: #c0392b; color: #fff; display: inline-flex;
      align-items: center; justify-content: center; font-size: 0.75rem;
      flex-shrink: 0; line-height: 1;
    }
    .field-warning .msg { color: #333; }
    .field-warning .close-warn { margin-left: auto; background: transparent; border: 0; font-size: 1rem; }
    .field-warning:empty { display: none; }
    .close-warn { cursor: pointer; color: #888; line-height: 1; }
    .close-warn:hover { color: #555; }
    /* compact inline warning for house number to avoid layout shifts */
    .field-warning-inline {
      color: #333;
      font-size: 0.85rem;
      margin-top: 6px;
      background: #fff;
      border-left: 4px solid #c0392b;
      border-radius: 6px;
      padding: 6px 8px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      max-width: 220px;
      box-shadow: 0 1px 6px rgba(0,0,0,0.08);
    }
    .field-warning-inline .warn-icon{ width:14px; height:14px; font-size:0.7rem; }
    
    /* Center Modal Styles */
    .center-modal {
      display: none;
      position: fixed;
      z-index: 9999;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      align-items: center;
      justify-content: center;
    }
    .center-modal-content {
      background-color: #fff;
      padding: 30px;
      border-radius: 12px;
      width: 90%;
      max-width: 400px;
      text-align: center;
      position: relative;
      box-shadow: 0 4px 15px rgba(0,0,0,0.2);
      animation: fadeIn 0.3s;
    }
    .center-modal-content h3 {
      margin-top: 0;
      color: #c0392b;
    }
    .center-modal-content p {
      color: #333;
      margin: 15px 0;
    }
    .center-modal-btn {
      display: inline-block;
      margin-top: 15px;
      padding: 10px 20px;
      background-color: #23412e;
      color: white;
      text-decoration: none;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      font-weight: 500;
    }
    .center-modal-btn:hover {
      background-color: #1a3022;
    }
    .close-center {
      position: absolute;
      top: 10px;
      right: 15px;
      font-size: 24px;
      cursor: pointer;
      color: #888;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-20px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>

<body>
  <div class="page-wrapper">
    <div class="image-side">
      <img src="images/signuppage/sign up pic.jpg" alt="Victorian Heights Subdivision">
      <p class="branding">VictorianPass</p>
    </div>

    <div class="form-side">
      <a href="mainpage.php" class="back-arrow">
        <img src="images/signuppage/back.svg" alt="Back">
      </a>

      <?php if ($registration_success): ?>
        <div class="success-banner">✅ You have successfully registered! Redirecting to login…</div>
      <?php endif; ?>
      <h1>Sign Up</h1>
      <p class="subtitle">Create your Account</p>

      <form class="signup-form" id="signupForm" method="POST" action="signup.php" enctype="multipart/form-data" novalidate <?php if ($registration_success) echo 'style="display:none"'; ?>>
        <input type="hidden" id="terms_agreed" name="terms_agreed" value="0">
        <div class="form-row">
          <div class="input-wrap">
            <input type="text" name="first_name" id="first_name" placeholder="First Name*" required>
          </div>
          <div class="input-wrap">
            <input type="text" name="middle_name" id="middle_name" placeholder="Middle Name (optional)">
          </div>
          <div class="input-wrap">
            <input type="text" name="last_name" id="last_name" placeholder="Last Name*" required>
          </div>
        </div>

        <div class="form-row">
          <div class="input-wrap">
            <input type="tel" name="phone" id="phone" placeholder="Phone Number*" required>
          </div>
        </div>

        <div class="input-wrap">
          <input type="email" name="email" id="email" placeholder="Email*" required>
        </div>

        <div class="input-wrap" id="visitorToggleWrap" style="margin-bottom: 15px;">
          <div style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" id="isVisitor" name="is_visitor" value="1" style="width:auto; margin:0;">
            <label for="isVisitor" style="margin:0; font-weight:600; color:#23412e;">I am a Visitor</label>
          </div>
          <p style="font-size: 0.75rem; color: #777; margin: 4px 0 0 24px; line-height: 1.3;">
             Reminders: For Visitors, No House Verification Required.
          </p>
        </div>

        <div class="input-wrap" id="visitorIdWrap" style="display:none; margin-bottom: 15px;">
          <label for="valid_id" style="display:block; margin-bottom:5px; font-weight:600; color:#23412e;">Upload Valid ID (Required for Visitors)</label>
          <input type="file" name="valid_id" id="valid_id" accept="image/*,.pdf" style="padding: 10px; border: 1px solid #ddd; border-radius: 8px; width: 100%;">
          <?php if (isset($serverErrors['valid_id'])): ?>
            <div class="field-warning" role="alert" style="display: flex;">
              <span class="warn-icon">!</span>
              <span class="warn-msg"><?php echo htmlspecialchars($serverErrors['valid_id']); ?></span>
            </div>
          <?php endif; ?>
        </div>

        <!-- House verification section -->
        <div class="form-row homeowner" style="align-items: flex-start; justify-content: space-between; gap: 1rem; border: 1px solid #eee; padding: 10px; border-radius: 8px; background: #fafafa;">
          <div style="flex: 1;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
              <img src="images/signuppage/location.svg" alt="Location" style="width: 20px;">
              <span style="font-weight: 600; color: #23412e; font-size: 1.1rem;">Your House Number <span style="font-weight: 400; color: #999; font-size: 0.9em;">(Residents Only)</span></span>
            </div>
            <p style="font-size: 0.75rem; color: #777; margin: 0 0 0 28px; line-height: 1.3;">
              Reminders: For Residents, Please Use Your Designated House number Above.
            </p>
          </div>
          
          <input type="text" id="houseHidden" name="house_number" placeholder="VH-0000" value="<?php echo htmlspecialchars($verified_house); ?>" style="flex: 0 0 140px; padding: 12px; border: 1px solid #ddd; border-radius: 6px; background: #fff; color: #333; font-weight: 500; font-family: inherit; font-size: 0.9rem;">
        </div>

        <div class="input-wrap">
          <input type="text" id="addressField" name="address" placeholder="Enter your full address*" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <select name="sex" required>
              <option value="" disabled selected></option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
            </select>
            <label>Sex*</label>
          </div>
          <div class="form-group">
            <input type="date" name="birthdate" placeholder=" " required>
            <label>Birthdate*</label>
          </div>
        </div>

        <div class="password-field">
          <input type="password" id="password" name="password" placeholder="Password*" required>
          <span class="toggle-password" onclick="togglePassword('password', this)">👁️</span>
        </div>

        <div class="password-field">
          <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password*" required>
          <span class="toggle-password" onclick="togglePassword('confirm_password', this)">👁️</span>
        </div>

        <script>
          const passwordInput = document.getElementById('password');
          const confirmPwd = document.getElementById('confirm_password');

      // Password complexity check - removed real-time validation
      // passwordInput.addEventListener('blur', () => { ... });

      // Password match check - removed real-time validation
      // if (confirmPwd) { confirmPwd.addEventListener('blur', ...); }

        </script>

        <div class="terms">
          <input type="checkbox" id="terms" required disabled>
          <label for="terms">
            By using the <strong>VictorianPass</strong>, you agree to the rules set for security, privacy, and orderly access.
            <a onclick="openTerms()" style="text-decoration: underline; color: rgb(245, 63, 169);">Read Terms & Conditions</a>
          </label>

        </div>

        <div class="form-actions">
          <button type="button" class="btn cancel" onclick="window.location.href='mainpage.php'">Cancel</button>
          <button type="submit" class="btn confirm">Confirm</button>
        </div>

        <p class="login-link">Already have an account? <a href="login.php">Log in</a></p>
      </form>

      
    </div>

    <!-- Terms Modal -->
    <div id="termsModal" class="modal">
      <div class="modal-content" style="max-width: 700px; padding: 40px; border-radius: 20px;">
        <span class="close" onclick="closeTerms()" style="top: 20px; right: 25px;">&times;</span>
        <h2 style="text-align: center; font-weight: 700; font-size: 1.5rem; margin-bottom: 20px; color: #222;">Terms & Services</h2>
        
        <p style="text-align: center; font-weight: 600; margin-bottom: 25px; line-height: 1.5; color: #000;">
          In using this website you are deemed to have read and agreed to the following terms and conditions:
        </p>

        <div style="font-size: 0.95rem; color: #333; line-height: 1.6;">
          <p style="margin-bottom: 15px;">
            The following terminology applies to these Terms and Conditions, Privacy Statement and Disclaimer Notice and any or all Agreements: “Customer”, “You” and “Your” refers to you, the person accessing this website and accepting the Company’s terms and conditions.
          </p>
          <p style="margin-bottom: 25px;">Effective Date: [September 2026]</p>

          <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">User Roles</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>Residents: Must provide accurate info, manage guest entries responsibly, and use the system for valid purposes only.</li>
              <li>Visitors: Must present valid QR codes and follow subdivision rules.</li>
              <li>Admins/Guards/HOA: Manage logs, approve entries, and maintain system security.</li>
            </ul>
          </div>

          <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">Privacy and Data</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>Your data is used only for entry validation and amenity booking.</li>
              <li>No data will be shared without consent unless required by law.</li>
            </ul>
          </div>

          <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">Amenity Booking</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>Bookings are first-come, first-served.</li>
              <li>Cancel if unable to attend.</li>
              <li>Misuse may result in account restriction.</li>
              <li>All billings will be done by walk-in.</li>
            </ul>
          </div>

          <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">QR Code Rules</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>QR codes are unique and time-limited.</li>
              <li>Sharing or tampering with codes is prohibited.</li>
            </ul>
          </div>

          <div style="margin-bottom: 30px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">System Use</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>System may go offline for updates.</li>
              <li>Users accept possible downtime.</li>
            </ul>
          </div>
        </div>

        <button class="btn confirm" onclick="agreeTerms()" style="width: 100%; background-color: #355340; padding: 12px; border-radius: 6px; font-size: 1rem;">Confirm</button>
      </div>
    </div>

    
  </div>
  

  <script>
    function togglePassword(id, el) {
      const input = document.getElementById(id);
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
    }

    function openTerms() {
      const modal = document.getElementById('termsModal');
      if (modal) modal.style.display = 'block';
    }

    function closeTerms() {
      const modal = document.getElementById('termsModal');
      if (modal) modal.style.display = 'none';
    }

    function agreeTerms() {
      const terms = document.getElementById('terms');
      if (terms) {
        terms.checked = true;
        terms.disabled = false;
      }
      const ta = document.getElementById('terms_agreed');
      if (ta) { ta.value = '1'; }
      closeTerms();
      // Clear any popover on terms
      if (typeof setWarning === 'function') setWarning('terms', '');
    }

    // Floating popover warnings anchored under inputs
    const _popovers = {};
    function _ensureLayer(){ return document.getElementById('warnLayer'); }
    function _positionPopover(inputEl, pop){
      const rect = inputEl.getBoundingClientRect();
      const top = rect.bottom + 8 + window.scrollY;
      let left = rect.left + window.scrollX;
      // Clamp within viewport
      const vw = document.documentElement.clientWidth;
      const popW = pop.offsetWidth || 260;
      const minLeft = 12 + window.scrollX;
      const maxLeft = vw - popW - 12 + window.scrollX;
      left = Math.max(minLeft, Math.min(left, maxLeft));
      pop.style.top = top + 'px';
      pop.style.left = left + 'px';
    }
    function _removePopover(key){
      const layer = _ensureLayer();
      const pop = _popovers[key];
      if (pop && layer && pop.parentNode === layer) layer.removeChild(pop);
      delete _popovers[key];
    }
    function _showPopover(inputId, message){
      const inputEl = document.getElementById(inputId);
      if (!inputEl || !message) return;
      const layer = _ensureLayer();
      if (!layer) return;
      let pop = _popovers[inputId];
      if (!pop){
        pop = document.createElement('div');
        pop.className = 'field-popover';
        pop.setAttribute('role','alert');
        pop.dataset.key = inputId;
        pop.innerHTML = '<span class="popover-icon">!</span><span class="msg"></span><span class="popover-close" aria-label="Dismiss" title="Dismiss">&times;</span>';
        layer.appendChild(pop);
        _popovers[inputId] = pop;
        const closer = pop.querySelector('.popover-close');
        closer.setAttribute('tabindex','0');
        closer.addEventListener('click', function(){ _removePopover(inputId); });
        closer.addEventListener('keydown', function(evt){ if (evt.key==='Enter'||evt.key===' ') { evt.preventDefault(); _removePopover(inputId);} });
      }
      const msg = pop.querySelector('.msg');
      if (msg) msg.textContent = message;
      _positionPopover(inputEl, pop);
    }
    function _repositionAll(){
      Object.keys(_popovers).forEach(function(key){
        const inp = document.getElementById(key);
        const pop = _popovers[key];
        if (inp && pop) _positionPopover(inp, pop);
      });
    }
    window.addEventListener('scroll', _repositionAll, { passive: true });
    window.addEventListener('resize', _repositionAll);
    function setWarning(key, message){
      const inputEl = document.getElementById(key);
      let container = null;
      if (key === 'houseHidden') {
        container = document.querySelector('.homeowner');
      } else if (key === 'terms') {
        container = document.querySelector('.terms');
      } else if (inputEl) {
        container = inputEl.closest('.input-wrap') || inputEl.closest('.password-field') || inputEl.closest('.form-group');
      }
      if (!container) return;
      // Special-case: houseHidden should show a compact inline warning next to the input
      let warnEl;
      if (key === 'houseHidden') {
        const inputEl = document.getElementById('houseHidden');
        if (!inputEl) return;
        // attach after the input so it doesn't expand the whole homeowner container
        warnEl = inputEl.parentNode.querySelector('.field-warning-inline[data-for="'+key+'"]');
        if (message){
          if (!warnEl){
            warnEl = document.createElement('div');
            warnEl.className = 'field-warning-inline';
            warnEl.setAttribute('data-for', key);
            warnEl.setAttribute('role','alert');
            // insert after the input element
            if (inputEl.nextSibling) inputEl.parentNode.insertBefore(warnEl, inputEl.nextSibling);
            else inputEl.parentNode.appendChild(warnEl);
          }
        }
      } else {
        warnEl = container.querySelector('.field-warning[data-for="'+key+'"]');
        if (message){
          if (!warnEl){
            warnEl = document.createElement('div');
            warnEl.className = 'field-warning';
            warnEl.setAttribute('data-for', key);
            warnEl.setAttribute('role','alert');
            container.appendChild(warnEl);
          }
        }
      }
      if (message){
        // Build notification-style content
        let icon = warnEl.querySelector('.warn-icon');
        if (!icon){
          icon = document.createElement('span');
          icon.className = 'warn-icon';
          icon.textContent = '!';
          warnEl.appendChild(icon);
        }
        let msgSpan = warnEl.querySelector('.msg');
        if (!msgSpan){
          msgSpan = document.createElement('span');
          msgSpan.className = 'msg';
          warnEl.appendChild(msgSpan);
        }
        msgSpan.innerHTML = message;
        let closer = warnEl.querySelector('.close-warn');
        if (!closer){
          closer = document.createElement('button');
          closer.type = 'button';
          closer.className = 'close-warn';
          closer.setAttribute('aria-label','Dismiss');
          closer.textContent = '\u00d7';
          warnEl.appendChild(closer);
          closer.addEventListener('click', function(){ warnEl.remove(); });
          closer.addEventListener('keydown', function(evt){ if (evt.key==='Enter' || evt.key===' '){ evt.preventDefault(); warnEl.remove(); } });
        }
        // Auto-dismiss warning if input becomes valid
        if (inputEl && (key === 'phone' || key === 'email')) {
          inputEl.addEventListener('input', function autoDismiss(){
            if ((key === 'phone' && /^09\d{9}$/.test(inputEl.value.trim())) || (key === 'email' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(inputEl.value.trim()))) {
              if (warnEl) warnEl.remove();
              inputEl.removeEventListener('input', autoDismiss);
            }
          });
        }
      } else {
        if (warnEl) warnEl.remove();
      }
    }

    // Prevent Enter key from submitting form
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        return false;
      }
    });

    document.addEventListener('DOMContentLoaded', function() {
      const first = document.getElementById('first_name');
      const last = document.getElementById('last_name');
      const middle = document.getElementById('middle_name');
      const phone = document.getElementById('phone');
      const terms = document.getElementById('terms');
      const password = document.getElementById('password');
      const confirmPwd = document.getElementById('confirm_password');
      const houseHidden = document.getElementById('houseHidden');
      const isVisitor = document.getElementById('isVisitor');
      const homeownerRow = document.querySelector('.homeowner');
      const addressField = document.getElementById('addressField');
      const form = document.getElementById('signupForm');

      function blockDigits(e) {
        if (/[0-9]/.test(e.key)) {
          e.preventDefault();
          // setWarning(e.target.id, 'Numbers are not allowed in this field.'); // Disabled real-time warning
        }
      }

      function sanitizeNoDigits(e) {
        const val = e.target.value;
        const cleaned = val.replace(/[0-9]/g, '');
        if (val !== cleaned) {
          e.target.value = cleaned;
          // setWarning(e.target.id, 'Numbers were removed.'); // Disabled real-time warning
        } else {
          // no toast for clearing
        }
      }

      [first, last, middle].forEach(function(inp) {
        if (!inp) return;
        inp.addEventListener('keydown', blockDigits);
        inp.addEventListener('input', sanitizeNoDigits);
      });

      if (phone) {
        // Real-time phone validation removed
        /*
        phone.addEventListener('input', function(e) {
          const val = e.target.value.trim();
          if (!/^09\d{9}$/.test(val)) {
            setWarning('phone', 'Phone number must be 11 digits and start with 09.');
          } else {
            setWarning('phone', '');
          }
        });
        */
      }

        // Email format check + async existence check
        const emailEl = document.getElementById('email');
        let emailTimer = null;
        if (emailEl) {
          // Real-time email validation removed
          /*
          emailEl.addEventListener('input', function(e){ ... });
          */
        }



      // House number verification on blur - removed real-time validation
      /*
      if (houseHidden) {
        houseHidden.addEventListener('blur', function(e) {
             const val = e.target.value.trim();
             if (!val) {
                setWarning('houseHidden', '');
                return;
             }
             
             // Check against server
             fetch('verify_house.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ ajax:'1', house_number: val }).toString()
             })
             .then(r => r.json())
             .then(data => {
                if (!data.success) {
                    setWarning('houseHidden', data.message || 'Invalid House Number');
                } else {
                    setWarning('houseHidden', '');
                }
             })
             .catch(err => console.error(err));
        });
      }
      */

      function applyVisitorMode(){
        if (!isVisitor) return;
        const idWrap = document.getElementById('visitorIdWrap');
        if (isVisitor.checked) {
          if (homeownerRow) homeownerRow.style.display = 'none';
          if (addressField) { addressField.required = false; addressField.style.display = 'none'; addressField.value = ''; }
          if (houseHidden) { houseHidden.value = ''; }
          if (idWrap) idWrap.style.display = 'block';
          setWarning('houseHidden', '');
        } else {
          if (homeownerRow) homeownerRow.style.display = 'flex';
          if (addressField) { addressField.required = true; addressField.style.display = 'block'; }
          if (idWrap) idWrap.style.display = 'none';
        }
      }
      if (isVisitor) {
        isVisitor.addEventListener('change', applyVisitorMode);
        applyVisitorMode();
      }

      if (form) {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          let valid = true;

          // Names: require first and last; middle optional but must not contain digits
          [first, last].forEach(function(inp) {
            if (!inp) return;
            if (/\d/.test(inp.value)) {
              setWarning(inp.id, 'Numbers are not allowed in this field.');
              valid = false;
            }
            if (!inp.value.trim()) {
              setWarning(inp.id, 'This field is required.');
              valid = false;
            }
          });
          if (middle) {
            const val = middle.value || '';
            if (/\d/.test(val)) {
              setWarning('middle_name', 'Numbers are not allowed in this field.');
              valid = false;
            } else {
              setWarning('middle_name', '');
            }
          }

          // Phone format: 09 followed by 9 digits (PH mobile)
          if (phone) {
            const val = phone.value.trim();
            if (!/^09\d{9}$/.test(val)) {
              setWarning('phone', 'Phone number must be 11 digits and start with 09.');
              valid = false;
            }
          }

          // Password complexity check
          if (password) {
            const v = password.value || '';
            if (v.length < 6 || !/[a-zA-Z]/.test(v) || !/[0-9]/.test(v)) {
              setWarning('password', 'Password must be at least 6 characters and include letters and numbers.');
              valid = false;
            } else {
              setWarning('password', '');
            }
          }

          // Password match check
          if (password && confirmPwd && password.value !== confirmPwd.value) {
            setWarning('confirm_password', 'Passwords do not match.');
            valid = false;
          }

          if (!isVisitor || !isVisitor.checked) {
            if (houseHidden && !houseHidden.value.trim()) {
              setWarning('houseHidden', 'Please enter your House Number.');
              valid = false;
            }
            if (addressField && !addressField.value.trim()) {
              setWarning('addressField', 'Please enter your full address.');
              valid = false;
            }
          } else {
            setWarning('houseHidden', '');
            if (addressField) setWarning('addressField', '');
          }

          // Terms: show inline warning and block submit until agreed
          if (terms && !terms.checked) {
            setWarning('terms', 'Please read and agree to the Terms & Conditions.');
            valid = false;
          }

          if (!valid) return;

          // AJAX Submission
          const formData = new FormData(form);
          formData.append('ajax', '1');
          
          const submitBtn = form.querySelector('button[type="submit"]');
          const originalBtnText = submitBtn.textContent;
          submitBtn.disabled = true;
          submitBtn.textContent = 'Processing...';

          fetch('signup.php', {
            method: 'POST',
            body: formData
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
               window.location.href = data.redirect;
            } else {
               submitBtn.disabled = false;
               submitBtn.textContent = originalBtnText;
               
               const errors = data.errors || {};
               let modalShown = false;

               const hasHouseError = errors.houseHidden && (errors.houseHidden.includes('already in use') || errors.houseHidden.includes('Invalid'));
               const hasEmailError = errors.email && errors.email.includes('already registered');

               // Check for House Number Error
               if (hasHouseError) {
                 let cooldown = 30;
                 submitBtn.disabled = true;
                 
                 const updateCooldown = () => {
                   if (cooldown > 0) {
                     submitBtn.textContent = `Try again in ${cooldown}s`;
                     cooldown--;
                     setTimeout(updateCooldown, 1000);
                   } else {
                     submitBtn.disabled = false;
                     submitBtn.textContent = originalBtnText;
                   }
                 };
                 
                 // If email error also exists, chain it to show after house modal closes
                 const onCloseAction = hasEmailError ? () => {
                     showCenterModal('Email Already Exists', 
                       '<p>This email is already registered.</p><a href="login.php" class="center-modal-btn">Go to Login Page</a>'
                     );
                 } : null;

                 showCenterModal('House Number Error', 
                   `<p>${errors.houseHidden}</p><p>Please try again later.</p>`,
                   onCloseAction
                 );
                 updateCooldown();
                 modalShown = true;
               }
               // Check for Email Error (only if no house error, or handled via chain above)
               else if (hasEmailError) {
                 showCenterModal('Email Already Exists', 
                   '<p>This email is already registered.</p><a href="login.php" class="center-modal-btn">Go to Login Page</a>'
                 );
                 modalShown = true;
               }

               // Show inline warnings for all errors (including those in modal)
               Object.entries(errors).forEach(([key, msg]) => {
                 setWarning(key, msg);
               });
               
               if (!modalShown) {
                 const firstErrorKey = Object.keys(errors)[0];
                 const firstErrorEl = document.getElementById(firstErrorKey) || document.querySelector(`[name="${firstErrorKey}"]`);
                 if (firstErrorEl) firstErrorEl.scrollIntoView({behavior: 'smooth', block: 'center'});
               }
            }
          })
          .catch(err => {
            console.error(err);
            submitBtn.disabled = false;
            submitBtn.textContent = originalBtnText;
            alert('An error occurred. Please try again.');
          });
        });
      }
    });
  </script>

  <!-- House Verification Modal (Removed) -->
  <!-- 
  <div id="houseModal" class="center-modal">
    ...
  </div>
  -->

  <!-- Center Warning Modal -->
  <div id="centerWarningModal" class="center-modal">
    <div class="center-modal-content">
      <span class="close-center" onclick="closeCenterModal()">&times;</span>
      <h3 id="centerModalTitle">Warning</h3>
      <div id="centerModalBody"></div>
    </div>
  </div>

  <script>
    window._modalCloseCallback = null;

    function closeCenterModal() {
      document.getElementById('centerWarningModal').style.display = 'none';
      if (typeof window._modalCloseCallback === 'function') {
        const cb = window._modalCloseCallback;
        window._modalCloseCallback = null;
        cb();
      }
    }
    
    function showCenterModal(title, bodyHtml, onClose) {
      document.getElementById('centerModalTitle').textContent = title;
      document.getElementById('centerModalBody').innerHTML = bodyHtml;
      document.getElementById('centerWarningModal').style.display = 'flex';
      window._modalCloseCallback = onClose || null;
    }
  </script>

  <script>
    (function(){
      var form = document.getElementById('signupForm');
      var bd = document.querySelector('input[name="birthdate"]');
      if (bd) {
        var d = new Date(); d.setDate(d.getDate()-1);
        bd.setAttribute('max', d.toISOString().split('T')[0]);
      }
      if (form) {
        form.addEventListener('submit', function(e){
          if (bd && bd.value) {
            var todayStr = new Date().toISOString().split('T')[0];
            if (bd.value >= todayStr) {
              e.preventDefault();
              alert('Birthdate must be a past date.');
            }
          }
        });
      }
    })();
  </script>
  <!-- Inline warnings are inserted per field; floating layer removed -->
  <?php if (!empty($serverErrors ?? [])) { ?>
  <!-- Old server-side error rendering removed since we use AJAX now, but kept for fallback if needed -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const warnings = <?php echo json_encode($serverErrors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      
      // Check for errors that should be displayed in a modal
       let modalMessage = '';
       if (warnings.email && (warnings.email.includes('already exists') || warnings.email.includes('Invalid') || warnings.email.includes('valid') || warnings.email.includes('registered'))) {
           modalMessage = warnings.email;
       } else if (warnings.houseHidden && (warnings.houseHidden.includes('already registered') || warnings.houseHidden.includes('Invalid') || warnings.houseHidden.includes('registered') || warnings.houseHidden.includes('use'))) {
           modalMessage = warnings.houseHidden;
       }
 
       if (modalMessage) {
           const modal = document.getElementById('errorModal');
           const msg = document.getElementById('errorModalMessage');
           if (modal && msg) {
               msg.textContent = modalMessage;
               modal.style.display = 'block';
           }
       }
 
       Object.entries(warnings).forEach(function([key, msg]) {
         setWarning(key, msg);
       });
    });
  </script>
  <?php } ?>

  <?php if ($registration_success) { ?>
  <script>
    // Auto-redirect to login after brief success message
    setTimeout(function(){ window.location.href = 'login.php'; }, 2500);
  </script>
  <?php } ?>

  <script>
    function openHouseModal(){
      const modal = document.getElementById('houseModal');
      const hidden = document.getElementById('houseHidden').value;
      if(hidden){
        document.getElementById('houseInput').value = hidden;
      }
      document.getElementById('verifyStatus').style.display = 'none';
      document.getElementById('verifyStatus').textContent = '';
      modal.style.display = 'block';
    }
    function closeHouseModal(){
      document.getElementById('houseModal').style.display = 'none';
    }
    async function performHouseVerify(){
      const house = document.getElementById('houseInput').value.trim();
      const status = document.getElementById('verifyStatus');
      status.style.display = 'none';
      status.textContent = '';
      if(!house){
        status.style.display = 'block';
        status.style.color = '#c0392b';
        status.textContent = 'Please enter a house number.';
        return;
      }
      try{
        const res = await fetch('verify_house.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ ajax:'1', house_number: house }).toString()
        });
        const data = await res.json();
        if(data && data.success){
          document.getElementById('houseHidden').value = house;
          
          // Update button appearance
          const btn = document.getElementById('houseVerifyBtn');
          if(btn) {
            btn.textContent = house;
            btn.style.background = '#e6ffed';
            btn.style.borderColor = '#23412e';
            btn.style.color = '#23412e';
            btn.style.fontWeight = '600';
          }

          status.style.display = 'block';
          status.style.color = '#23412e';
          status.textContent = 'House number is valid and saved.';
          // Keep modal open briefly for feedback, then close
          setTimeout(closeHouseModal, 800);
        } else {
          status.style.display = 'block';
          status.style.color = '#c0392b';
          status.textContent = data && data.message ? data.message : 'Invalid or unregistered house number.';
        }
      } catch(err){
        status.style.display = 'block';
        status.style.color = '#c0392b';
        status.textContent = 'Verification failed. Please try again.';
      }
    }
  </script>


    <!-- Generic Error Modal -->
  <div id="errorModal" class="modal">
    <div class="modal-content" style="max-width: 400px; text-align: center; padding: 30px; border-radius: 12px; position: relative; top: 50%; transform: translateY(-50%); margin: auto;">
      <span class="close" onclick="document.getElementById('errorModal').style.display='none'">&times;</span>
      <div style="margin-bottom: 15px;">
         <span style="font-size: 3rem;">⚠️</span>
      </div>
      <h3 style="color: #c0392b; margin-bottom: 10px;">Error</h3>
      <div id="errorModalMessage" style="color: #555; font-size: 1rem;"></div>
      <button class="btn confirm" onclick="document.getElementById('errorModal').style.display='none'" style="margin-top: 20px; background-color: #c0392b; width: 100%;">OK</button>
    </div>
  </div>

</body>
</html>
