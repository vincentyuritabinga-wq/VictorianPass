<?php
include("connect.php");
session_start();

// Initialize error message for inline display
$error = '';
// Flash notice from payment confirmation
$flash = isset($_SESSION['flash_notice']) ? $_SESSION['flash_notice'] : '';
$flashRef = isset($_SESSION['flash_ref_code']) ? $_SESSION['flash_ref_code'] : '';
if ($flash !== '') { unset($_SESSION['flash_notice']); }
if ($flashRef !== '') { unset($_SESSION['flash_ref_code']); }

// Ensure entry_passes table exists
function ensureEntryPassesTable($con) {
  $con->query("CREATE TABLE IF NOT EXISTS entry_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    sex VARCHAR(10) NULL,
    birthdate DATE NULL,
    contact VARCHAR(50) NULL,
    email VARCHAR(120) NOT NULL,
    address VARCHAR(255) NOT NULL,
    valid_id_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensureEntryPassesTable($con);

$error = '';

// Load resident mini profile data (for dropdown)
$residentName = '';
$residentHouse = '';
$hasResidentProfile = false;
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident') {
  $uid = (int)$_SESSION['user_id'];
  if ($stmt = $con->prepare("SELECT first_name, middle_name, last_name, house_number FROM users WHERE id = ? LIMIT 1")) {
    $stmt->bind_param("i", $uid);
    if ($stmt->execute()) {
      $stmt->bind_result($first, $middle, $last, $house);
      if ($stmt->fetch()) {
        $residentName = trim($first . ' ' . (($middle ?? '') ? ($middle . ' ') : '') . $last);
        $residentHouse = $house ?? '';
        $hasResidentProfile = true;
      }
    }
    $stmt->close();
  }
}

// No longer storing downpayment on entry_passes; we link it to reservations via ref_code

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $error = '';
  $formErrors = [];
  // Collect form data
  $first = trim($_POST['first_name'] ?? '');
  $middle = trim($_POST['middle_name'] ?? '');
  $last = trim($_POST['last_name'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $sex = $_POST['sex'] ?? '';
  $birthdate = $_POST['birthdate'] ?? '';
  $contact = trim($_POST['contact'] ?? '');

  // Basic validation mirroring client rules
  if ($first === '' || preg_match('/\d/', $first)) { $formErrors[] = 'Please provide a valid First Name.'; }
  if ($last === '' || preg_match('/\d/', $last)) { $formErrors[] = 'Please provide a valid Last Name.'; }
  if ($address === '') { $formErrors[] = 'Address is required.'; }
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $formErrors[] = 'A valid email is required.'; }
  if ($sex === '') { $formErrors[] = 'Sex is required.'; }
  if ($birthdate === '') { $formErrors[] = 'Birthdate is required.'; }
  if ($contact !== '' && (!preg_match('/^09\d{9}$/', $contact) && !preg_match('/^\+639\d{9}$/', $contact))) { $formErrors[] = 'Use 09xxxxxxxxx or +639xxxxxxxxx for contact.'; }

  // Handle valid ID upload (REQUIRED)
  $validIdPath = null;
  if (!empty($_FILES['valid_id']['name']) && isset($_FILES['valid_id']['tmp_name']) && is_uploaded_file($_FILES['valid_id']['tmp_name'])) {
    $uploadDir = "uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir);
    $fileName = time() . "_" . basename($_FILES["valid_id"]["name"]);
    $targetFile = $uploadDir . $fileName;
    if (move_uploaded_file($_FILES["valid_id"]["tmp_name"], $targetFile)) {
      $validIdPath = $targetFile;
    } else {
      $formErrors[] = 'Failed to upload ID. Please try again.';
    }
  } else {
    $formErrors[] = 'Valid ID upload is required.';
  }

  if (empty($formErrors)) {
    // Insert into entry_passes ONLY when complete and validated
    $stmt = $con->prepare("INSERT INTO entry_passes (full_name, middle_name, last_name, sex, birthdate, contact, email, address, valid_id_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssss", $first, $middle, $last, $sex, $birthdate, $contact, $email, $address, $validIdPath);
    if ($stmt->execute()) {
      $entryPassId = $stmt->insert_id;
      $_SESSION['entry_pass_id'] = $entryPassId;
      $_SESSION['entry_pass_name'] = $first . ' ' . $last;
      header("Location: reserve.php?entry_pass_id=" . $entryPassId);
      exit;
    } else {
      $error = 'Failed to save entry pass. Please try again.';
    }
    $stmt->close();
  } else {
    // Aggregate errors for display
    $error = implode(' ', $formErrors);
  }
}
?>

<!DOCTYPE html> 
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
  <?php $mainCssVer = @filemtime(__DIR__ . '/css/mainpage.css') ?: time(); $respCssVer = @filemtime(__DIR__ . '/css/responsive.css') ?: time(); ?>
  <link rel="stylesheet" href="css/mainpage.css?v=<?php echo $mainCssVer; ?>">
  <link rel="stylesheet" href="css/responsive.css?v=<?php echo $respCssVer; ?>">
  
</head>
<body>
  <?php if (!empty($flash)) { ?>
    <div class="flash-overlay" id="flashNotice">
      <div class="flash-modal">
        <div class="title">Notification</div>
        <div class="text"><?php echo htmlspecialchars($flash); ?></div>
        <?php if(!empty($flashRef)){ ?><div class="code"><?php echo htmlspecialchars($flashRef); ?></div><?php } ?>
        <button type="button" class="flash-close" id="flashCloseBtn">Close</button>
      </div>
    </div>
    <script>
      (function(){
        var ov=document.getElementById('flashNotice');
        var btn=document.getElementById('flashCloseBtn');
        function close(){ if(ov) ov.style.display='none'; }
        if(btn) btn.addEventListener('click', close);
        if(ov) ov.addEventListener('click', function(e){ if(e.target===ov) close(); });
      })();
    </script>
  <?php } ?>
  <!-- HEADER -->
  <header class="navbar">
    <div class="logo">
      <a href="mainpage.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
      <div class="brand-text">
        <h1>VictorianPass</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>
    
    <button class="hamburger" id="navToggle" aria-label="Menu" aria-expanded="false" aria-controls="navCollapse"><span></span><span></span><span></span></button>
    <div class="nav-collapse" id="navCollapse">
      <nav class="page-nav" id="primaryNav">
        <a href="#home">Home</a>
        <a href="#about-us">About Us</a>
        <a href="#facilities">Amenities</a>
        <a href="#about-system">About the System</a>
      </nav>
      <div class="nav-actions">
        <a href="checkurstatus.php" class="btn-nav btn-status" id="checkStatusNav" style="display: none;">Check Status</a>
        <div class="nav-links" id="navLinks" style="display: none;">
          <a href="login.php" class="btn-nav btn-login">Login</a>
          <a href="signup.php" class="btn-nav btn-register">Register</a>
          <div id="profileIcon" class="profile-icon-wrap" style="display: none;">
            <img src="images/mainpage/profile'.jpg" alt="Profile" class="profile-icon">
            <?php if ($hasResidentProfile): ?>
            <div id="profileDropdown" class="profile-dropdown" role="dialog" aria-label="Resident quick profile">
              <div class="mini-profile">
                <img src="images/mainpage/profile'.jpg" alt="Avatar" class="mini-avatar">
                <div class="mini-text">
                  <span class="mini-name"><?php echo htmlspecialchars($residentName); ?></span>
                  <span class="mini-house">House No.: <?php echo htmlspecialchars($residentHouse); ?></span>
                </div>
              </div>
              <div class="actions">
                <a href="profileresident.php" class="btn btn-view">View More</a>
                <a href="logout.php" class="btn btn-logout">Log Out</a>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    
  </header>

  <!-- HERO SECTION -->
  <section class="hero" id="home">
    <?php if ($error !== '') { echo '<div class="error">' . htmlspecialchars($error) . '</div>'; } ?>
    <div class="hero-content">
      

      <div class="hero-icons" id="entryPassButtonWrapper" style="display:none;">
        <a href="entrypass.html" class="icon-box" id="entryFormButton">
          <img src="images/entrypass.svg" alt="Entry Pass">
          <span>Entry Pass Form</span>
        </a>
      
      </div>
      <br>
      <h2>WELCOME TO</h2>
      <div class="hero-brand">
        <h1>VictorianPass</h1>
      </div>

      <div class="hero-emblem">
        <span class="line"></span>
        <img src="images/logo.svg" alt="Emblem">
        <span class="line"></span>
      </div>
      <!--<h3 class="hero-subbrand">Victorian Heights Subdivision</h3>
      <p class="welcome-subtitle">
        VictorianPass: An Online Amenity Reservation System<br>
        with QR-based Entry Pass Security<br>
        for Victorian Heights Subdivision
      </p> -->      <!-- Moved User Type Dropdown to bottom of hero -->
      <div class="user-type-center">
        <div class="user-type-dropdown" id="userTypeDropdown">
          <button class="dropdown-btn" id="dropdownBtn">
            <span>Select User Type</span>
            <span class="dropdown-arrow">▼</span>
          </button>
          <div class="dropdown-content" id="dropdownContent">
            <a href="#" onclick="selectUserType('resident')">Resident</a>
            <?php if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident'): ?>
              <a href="#" onclick="return false" title="Log out to switch">Visitor</a>
            <?php else: ?>
              <a href="#" onclick="selectUserType('visitor')">Visitor</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <p class="tagline">
        Every home holds a story.<br>
        start yours in a place worth remembering.
      </p>

      



      <!-- Change user type helper (shown after selection) -->
      <div class="user-type-center" id="userTypeSwitch" style="display:none;">
        <button class="btn-change" onclick="resetUserType()">Change User Type</button>
      </div>
    </div>
  </section>

  <section id="about-us" class="section">
    <h2 class="section-title">About Us</h2>
    <div class="section-divider"></div>
    <div class="section-body">
      <p>Victorian Heights subdivision is a gated residence that offers accessibility located at Dahlia Fairview, BRGY. Sauyo, Quezon City. It is a residential development by Swire Land Corporation that provides accessibility and exclusivity with a gated community with 222 houses and an estimated 2,220 residents, making it secure against harm and vulnerability. Furthermore, beautifully designed houses that cater to thousands of residents live within reach of convenience and service while getting the experience of peace in a suburban community .</p>
      <img src="images/about subd.png" alt="Victorian Heights Subdivision" class="about-subdivision-photo">
    </div>
  </section>

  <section id="facilities" class="section">
    <h2 class="section-title">Amenities</h2>
    <div class="section-divider"></div>
    <div class="amenities-grid">
      <div class="amenity-card">
        <img src="images/pool.svg" alt="Community Pool">
        <h3 class="title">Community Pool</h3>
        <p class="desc">Relax and enjoy the pool with convenient reservation options.</p>
      </div>
      <div class="amenity-card">
        <img src="images/clubhouse.svg" alt="Clubhouse">
        <h3 class="title">Clubhouse</h3>
        <p class="desc">Host gatherings and events in the subdivision clubhouse.</p>
      </div>
      <div class="amenity-card">
        <img src="images/basketball.svg" alt="Basketball Court">
        <h3 class="title">Basketball Court</h3>
        <p class="desc">Play and practice on our outdoor basketball court.</p>
      </div>
      <div class="amenity-card">
        <img src="images/tennis.jpg" alt="Tennis Court">
        <h3 class="title">Tennis Court</h3>
        <p class="desc">Reserve time to enjoy a game at the tennis court.</p>
      </div>
    </div>
  </section>
  <section id="about-system" class="section">
    <h2 class="section-title">About the System</h2>
    <div class="section-divider"></div>
    <div class="section-body">
      <p>Victorian Pass is a modern subdivision management system that utilizes QR technology to provide fast, secure, and seamless access for residents and visitors. Designed to enhance security and streamline daily processes, the system handles amenity reservations, entry pass requests, incident reporting, and user verification, all in one platform. By replacing manual checks with QR scanning, Victorian Pass ensures quicker entry, and secure access, while improved monitoring subdivision welfare. the system strengthens community safety while offering a more convenient experience for everyone in the subdivision.</p>
      <div class="about-intro"><h3>Experience peace of mind designed to safeguard your neighborhood.</h3></div>
      <div class="about-system-grid">
        <div class="about-card">
          <img src="images/as1.png" alt="Community Life">
          <h3>What You'll Find in Victorian Heights Subdivision?</h3>
          <p>VictorianPass supports a connected and well-managed community. Residents enjoy secure living, convenient services, and organized processes that bring comfort, safety, and a sense of belonging.</p>
        </div>
        <div class="about-card">
          <img src="images/as2.png" alt="Quick Response">
          <h3>Quick response</h3>
          <p>QR-based entry and reservation workflows enable fast approvals and real-time updates for residents, visitors, and guards, streamlining actions for better control and safer operations.</p>
        </div>
        <div class="about-card">
          <img src="images/as3.png" alt="A Shelter">
          <h3>A Shelter</h3>
          <p>A welcoming, secure environment designed to offer comfort and confidence to every resident, with systems that protect and organize daily community life.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Visitor-friendly instructions box fixed at the bottom-left -->
   <br>
   <br>
  <div class="bottom-instructions" id="bottomInstructions" style="display: none;">
    <strong>Visitor Tips</strong><br>
    • Click <b>Entry Pass Form</b> to apply for a visitor pass.<br>
    • Use <b>Check Status</b> to look up your code or track your request.
  </div>

  <script>
    const isResidentLoggedIn = <?php echo (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident') ? 'true' : 'false'; ?>;
    function selectUserType(type) {
      const dropdown = document.getElementById('userTypeDropdown');
      const navLinks = document.getElementById('navLinks');
      const profileIcon = document.getElementById('profileIcon');
      const checkStatusNav = document.getElementById('checkStatusNav');
      const bottomInstructions = document.getElementById('bottomInstructions');
      const switcher = document.getElementById('userTypeSwitch');

      if (type === 'resident') {
        // Hide dropdown and show navigation links
        dropdown.style.display = 'none';
        navLinks.style.display = 'flex';
        if (checkStatusNav) checkStatusNav.style.display = 'none';
        if (bottomInstructions) bottomInstructions.style.display = 'none';
        switcher.style.display = 'block';
        // Hide Entry Pass button/instruction for residents
        var epBtn = document.getElementById('entryPassButtonWrapper');
        var epInst = document.getElementById('entryPassInstruction');
        if (epBtn) epBtn.style.display = 'none';
        if (epInst) epInst.style.display = 'none';
        try{ localStorage.setItem('vp_user_type','resident'); }catch(e){}

        // Check if user is logged in (you can modify this logic based on your session handling)
        <?php if (isset($_SESSION['user_id'])): ?>
          profileIcon.style.display = 'block';
        <?php endif; ?>

      } else if (type === 'visitor') {
        if (isResidentLoggedIn) { alert('You are logged in as a resident. Please log out to switch to Visitor.'); return; }
        // Hide dropdown and show entry form and check status
        dropdown.style.display = 'none';
        navLinks.style.display = 'none';
        profileIcon.style.display = 'none';
        if (checkStatusNav) checkStatusNav.style.display = 'inline-block';
        if (bottomInstructions) bottomInstructions.style.display = 'block';
        switcher.style.display = 'block';
        // Show Entry Pass button/instruction for visitors
        var epBtn = document.getElementById('entryPassButtonWrapper');
        var epInst = document.getElementById('entryPassInstruction');
        if (epBtn) epBtn.style.display = 'flex';
        if (epInst) epInst.style.display = 'block';
        try{ localStorage.setItem('vp_user_type','visitor'); }catch(e){}
      }
    }

    function resetUserType(){
      const dropdown = document.getElementById('userTypeDropdown');
      const navLinks = document.getElementById('navLinks');
      const profileIcon = document.getElementById('profileIcon');
      const checkStatusNav = document.getElementById('checkStatusNav');
      const bottomInstructions = document.getElementById('bottomInstructions');
      const switcher = document.getElementById('userTypeSwitch');
      const epBtn = document.getElementById('entryPassButtonWrapper');
      const epInst = document.getElementById('entryPassInstruction');

      // Reset to initial state
      dropdown.style.display = 'block';
      const content = document.getElementById('dropdownContent');
      if (content) content.style.display = 'none';
      navLinks.style.display = 'none';
      // No inline entry form on main page
      if (profileIcon) profileIcon.style.display = 'none';
      if (checkStatusNav) checkStatusNav.style.display = 'none';
      if (bottomInstructions) bottomInstructions.style.display = 'none';
      switcher.style.display = 'none';
      if (epBtn) epBtn.style.display = 'none';
      if (epInst) epInst.style.display = 'none';
      try{ localStorage.removeItem('vp_user_type'); }catch(e){}
    }

    // Toggle dropdown visibility
    document.getElementById('dropdownBtn').addEventListener('click', function(event) {
      event.stopPropagation();
      const wrap = document.getElementById('userTypeDropdown');
      const content = document.getElementById('dropdownContent');
      const open = wrap.classList.toggle('open');
      if (content) { content.style.display = open ? 'block' : 'none'; }
    });

    // Close dropdown when clicking outside
    window.addEventListener('click', function(event) {
      const wrap = document.getElementById('userTypeDropdown');
      if (wrap && !event.target.closest('#userTypeDropdown')) {
        wrap.classList.remove('open');
        const content = document.getElementById('dropdownContent');
        if (content) { content.style.display = 'none'; }
      }
    });
  </script>
  <script>
    // Persist selected user type across navigation
    document.addEventListener('DOMContentLoaded',function(){
      try{
        var saved = localStorage.getItem('vp_user_type');
        if(saved==='visitor' && isResidentLoggedIn){ selectUserType('resident'); return; }
        if(saved==='resident' || saved==='visitor'){ selectUserType(saved); }
      }catch(e){}
    });
  </script>
  <script>
    // Auto-show resident nav state after login
    document.addEventListener('DOMContentLoaded', function(){
      const dropdown = document.getElementById('userTypeDropdown');
      const navLinks = document.getElementById('navLinks');
      const profileIcon = document.getElementById('profileIcon');
      const loginBtn = document.querySelector('.btn-login');
      const registerBtn = document.querySelector('.btn-register');
      <?php if (isset($_SESSION['user_id'])): ?>
        if (dropdown) dropdown.style.display = 'none';
        if (navLinks) navLinks.style.display = 'flex';
        if (profileIcon) profileIcon.style.display = 'block';
        if (loginBtn) loginBtn.style.display = 'none';
        if (registerBtn) registerBtn.style.display = 'none';
      <?php endif; ?>
    });
  </script>
  <script>
    (function(){var t=document.getElementById('navToggle');var c=document.getElementById('navCollapse');if(!t||!c)return;t.addEventListener('click',function(){var o=c.classList.toggle('open');t.setAttribute('aria-expanded',o?'true':'false');});window.addEventListener('click',function(e){if(!c.contains(e.target)&&!t.contains(e.target)){c.classList.remove('open');t.setAttribute('aria-expanded','false');}});window.addEventListener('resize',function(){if(window.innerWidth>900){c.classList.remove('open');t.setAttribute('aria-expanded','false');}});})();
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      const iconWrap = document.getElementById('profileIcon');
      const dd = document.getElementById('profileDropdown');
      if (!iconWrap || !dd) return;

      const openDD = () => { dd.style.display = 'block'; };
      const closeDD = () => { dd.style.display = 'none'; };
      const toggleDD = () => { dd.style.display = (dd.style.display === 'block') ? 'none' : 'block'; };

      // Toggle when clicking the icon itself; allow clicks inside dropdown to navigate
      iconWrap.addEventListener('click', function(e){
        if (dd.contains(e.target)) return; // let dropdown links work normally
        e.stopPropagation();
        toggleDD();
      });
      iconWrap.addEventListener('mouseenter', function(){ openDD(); });
      iconWrap.addEventListener('mouseleave', function(){ setTimeout(function(){ if (!dd.matches(':hover')) closeDD(); }, 160); });
      dd.addEventListener('mouseleave', function(){ closeDD(); });
      window.addEventListener('click', function(e){ if (!iconWrap.contains(e.target) && !dd.contains(e.target)) closeDD(); });
    });
  </script>

</body>
</html>
