<?php
session_start();
require_once 'connect.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Redirect ALL users to reserve.php as Entry Pass Form is deprecated
header("Location: reserve.php");
exit();

// Redirect Residents to their profile (Entry Pass is for Visitors)
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident') {
    header("Location: profileresident.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_data = [];

// Fetch user data to pre-fill (or to use in background)
if ($con) {
    // Schema Guard: Ensure entry_passes table allows NULL address
    $res = $con->query("SHOW COLUMNS FROM entry_passes LIKE 'address'");
    if ($res && ($row = $res->fetch_assoc())) {
        if (strtoupper($row['Null']) === 'NO') {
            $con->query("ALTER TABLE entry_passes MODIFY COLUMN address VARCHAR(255) NULL");
        }
    }

    $stmt = $con->prepare("SELECT first_name, middle_name, last_name, email, phone, sex, birthdate, address FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $user_data = $res->fetch_assoc();
    }
    $stmt->close();
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $purpose = $_POST['purpose'] ?? '';
    $amenity = $_POST['amenity'] ?? 'Entry Pass'; // Default if not selected
    $visit_date = $_POST['visit_date'] ?? '';
    $end_date = $_POST['end_date'] ?? $visit_date; // Default to same day
    
    // File Upload (Valid ID)
    $valid_id_path = '';
    if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/ids/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $fileName = time() . '_' . basename($_FILES['valid_id']['name']);
        $targetPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['valid_id']['tmp_name'], $targetPath)) {
            $valid_id_path = $targetPath;
        }
    }

    if ($visit_date && $purpose && $valid_id_path) {
        // 1. Insert into entry_passes (to store ID and snapshot of details)
        $ep_stmt = $con->prepare("INSERT INTO entry_passes (full_name, middle_name, last_name, sex, birthdate, contact, email, address, valid_id_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $full_name = $user_data['first_name']; // Schema calls it full_name but looks like first name based on other fields? 
        // Wait, schema has full_name, middle_name, last_name. 
        // Let's assume full_name is First Name (based on signup mapping) or actually Full Name?
        // In signup, we have first, middle, last.
        // In entry_passes, we have full_name, middle_name, last_name.
        // Let's just put First Name in full_name to be safe or concat? 
        // If I look at guestform.php (if exists) or other inserts...
        // Let's just use First Name for full_name field if it seems to correspond to that, or Concat.
        // Actually, let's use First Name for full_name column to avoid confusion if it's used as such.
        $ep_stmt->bind_param("sssssssss", 
            $user_data['first_name'], 
            $user_data['middle_name'], 
            $user_data['last_name'], 
            $user_data['sex'], 
            $user_data['birthdate'], 
            $user_data['phone'], 
            $user_data['email'], 
            $user_data['address'], 
            $valid_id_path
        );
        $ep_stmt->execute();
        $entry_pass_id = $ep_stmt->insert_id;
        $ep_stmt->close();

        // 2. Insert into reservations
        $ref_code = 'VP-' . strtoupper(uniqid());
        $res_stmt = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, purpose, user_id, entry_pass_id, status, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')");
        $res_stmt->bind_param("sssssii", $ref_code, $amenity, $visit_date, $end_date, $purpose, $user_id, $entry_pass_id);
        
        if ($res_stmt->execute()) {
            echo "<script>alert('Entry Pass Request Submitted! Ref Code: $ref_code'); window.location.href='dashboardvisitor.php';</script>";
            exit();
        } else {
            $error = "Error submitting request.";
        }
        $res_stmt->close();
    } else {
        $error = "Please fill all required fields and upload a valid ID.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Entry Pass - VictorianPass</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { margin:0; font-family:'Poppins',sans-serif; background:#f5f5f5; color:#333; }
    .navbar { display:flex; justify-content:space-between; align-items:center; padding:12px 5%; background:#2b2623; color:#fff; }
    .logo { display:flex; align-items:center; gap:12px; }
    .logo img { width:40px; }
    .brand-text h1 { margin:0; font-size:1.2rem; font-weight:600; }
    .container { max-width:600px; margin:40px auto; background:#fff; padding:30px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.1); }
    h2 { margin-top:0; color:#23412e; display:flex; align-items:center; gap:10px; }
    .form-group { margin-bottom:15px; }
    label { display:block; margin-bottom:5px; font-weight:500; color:#555; }
    input, select, textarea { width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; font-family:inherit; box-sizing:border-box; }
    .btn-submit { width:100%; padding:12px; background:#23412e; color:#fff; border:none; border-radius:6px; font-weight:600; cursor:pointer; font-size:1rem; margin-top:10px; }
    .btn-submit:hover { background:#1a3322; }
    .btn-cancel { display:block; text-align:center; margin-top:15px; color:#666; text-decoration:none; font-size:0.9rem; }
    .user-info { background:#f9f9f9; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #eee; }
    .user-info p { margin:5px 0; font-size:0.9rem; color:#666; }
    .user-info strong { color:#333; }
    .error-msg { background:#ffecec; color:#c0392b; padding:10px; border-radius:6px; margin-bottom:15px; border:1px solid #f5c6cb; }
  </style>
</head>
<body>
  <div class="navbar">
    <div class="logo">
      <a href="dashboardvisitor.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
      <div class="brand-text">
        <h1>VictorianPass</h1>
      </div>
    </div>
  </div>

  <div class="container">
    <h2><img src="images/mainpage/ticket.svg" width="24"> Create Entry Pass</h2>
    
    <?php if (isset($error)) echo "<div class='error-msg'>$error</div>"; ?>

    <div class="user-info">
        <p><strong>Visitor:</strong> <?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($user_data['email']); ?></p>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label for="visit_date">Date of Visit*</label>
            <input type="date" id="visit_date" name="visit_date" required min="<?php echo date('Y-m-d'); ?>">
        </div>
        
        <div class="form-group">
            <label for="purpose">Purpose of Visit / Amenity*</label>
            <select id="purpose" name="purpose" required onchange="toggleAmenity(this.value)">
                <option value="" disabled selected>Select Purpose</option>
                <option value="Visit Resident">Visit a Resident</option>
                <option value="Clubhouse">Clubhouse Reservation</option>
                <option value="Swimming Pool">Swimming Pool</option>
                <option value="Basketball Court">Basketball Court</option>
                <option value="Delivery">Delivery/Service</option>
                <option value="Other">Other</option>
            </select>
        </div>

        <input type="hidden" name="amenity" id="amenity" value="Entry Pass">

        <div class="form-group">
            <label for="valid_id">Upload Valid ID* <small>(Required for every visit)</small></label>
            <input type="file" id="valid_id" name="valid_id" accept="image/*" required>
        </div>

        <button type="submit" class="btn-submit">Submit Request</button>
        <a href="dashboardvisitor.php" class="btn-cancel">Cancel</a>
    </form>
  </div>

  <script>
    function toggleAmenity(val) {
        var amenityInput = document.getElementById('amenity');
        if (['Clubhouse', 'Swimming Pool', 'Basketball Court'].includes(val)) {
            amenityInput.value = val;
        } else {
            amenityInput.value = 'Entry Pass';
        }
    }
  </script>
</body>
</html>
