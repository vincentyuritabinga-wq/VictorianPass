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

// Ensure users table schema supports visitors
function ensureUserSchema($con){
  // Add 'visitor' to user_type enum if missing
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'user_type'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strpos($row['Type'], "visitor") === false) {
      $con->query("ALTER TABLE users MODIFY COLUMN user_type ENUM('resident','visitor') DEFAULT 'resident'");
    }
  }
  // Make house_number nullable
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'house_number'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strtoupper($row['Null']) === 'NO') {
      $con->query("ALTER TABLE users MODIFY COLUMN house_number VARCHAR(50) NULL");
    }
  }
  // Make address nullable
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'address'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strtoupper($row['Null']) === 'NO') {
      $con->query("ALTER TABLE users MODIFY COLUMN address VARCHAR(255) NULL");
    }
  }
}
ensureUserSchema($con);

$error = '';

// Load user profile data (resident or visitor)
$userName = '';
$userHouse = '';
$userType = '';
$userEmail = '';
$userPhone = '';
$userAddress = '';
$userSex = '';
$userBirthdate = '';
$isLoggedIn = false;
$isResident = false;
$isVisitor = false;

if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
  $isLoggedIn = true;
  $uid = (int)$_SESSION['user_id'];
  $userType = $_SESSION['user_type'];
  
  if ($userType === 'resident') {
    $isResident = true;
    if ($stmt = $con->prepare("SELECT first_name, middle_name, last_name, house_number, email, phone, address, sex, birthdate FROM users WHERE id = ? LIMIT 1")) {
      $stmt->bind_param("i", $uid);
      if ($stmt->execute()) {
        $stmt->bind_result($first, $middle, $last, $house, $email, $phone, $address, $sex, $birthdate);
        if ($stmt->fetch()) {
          $userName = trim($first . ' ' . (($middle ?? '') ? ($middle . ' ') : '') . $last);
          $userHouse = $house ?? '';
          $userEmail = $email ?? '';
          $userPhone = $phone ?? '';
          $userAddress = $address ?? '';
          $userSex = $sex ?? '';
          $userBirthdate = $birthdate ?? '';
        }
      }
      $stmt->close();
    }
  } elseif ($userType === 'visitor') {
    $isVisitor = true;
    if ($stmt = $con->prepare("SELECT first_name, middle_name, last_name, email, phone, address, sex, birthdate FROM users WHERE id = ? LIMIT 1")) {
      $stmt->bind_param("i", $uid);
      if ($stmt->execute()) {
        $stmt->bind_result($first, $middle, $last, $email, $phone, $address, $sex, $birthdate);
        if ($stmt->fetch()) {
          $userName = trim($first . ' ' . (($middle ?? '') ? ($middle . ' ') : '') . $last);
          $userHouse = 'Visitor';
          $userEmail = $email ?? '';
          $userPhone = $phone ?? '';
          $userAddress = $address ?? '';
          $userSex = $sex ?? '';
          $userBirthdate = $birthdate ?? '';
        }
      }
      $stmt->close();
    }
  }

  // Profile Picture Logic
  $profilePicPath = 'images/mainpage/profile\'.jpg'; // Default
  if (file_exists('uploads/profiles/user_' . $uid . '.jpg')) {
      $profilePicPath = 'uploads/profiles/user_' . $uid . '.jpg';
  } elseif (file_exists('uploads/profiles/user_' . $uid . '.png')) {
      $profilePicPath = 'uploads/profiles/user_' . $uid . '.png';
  } elseif (file_exists('uploads/profiles/user_' . $uid . '.jpeg')) {
      $profilePicPath = 'uploads/profiles/user_' . $uid . '.jpeg';
  }
  $profilePicUrl = $profilePicPath . '?t=' . time();
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
  <?php if (!empty($flash) && !(isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident')) { ?>
    <div class="flash-overlay" id="flashNotice">
      <div class="flash-modal">
        <div class="title">!!!</div>
        <div class="text"><?php echo htmlspecialchars($flash); ?></div>
        
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
        <?php if ($isLoggedIn): ?>
          <div style="display:flex; align-items:center; gap:12px; color:#f4f4f4; font-weight:600;">
             <span>Hi, <?php echo htmlspecialchars($userName ?: 'User'); ?> <small style="font-weight:400; opacity:0.8;">(<?php echo ucfirst($userType); ?>)</small></span>
             <div class="profile-icon-wrap" id="profileWrap">
               <button id="profileAccountTrigger" type="button" class="profile-account-btn" style="background:none;border:none;padding:0;cursor:pointer;">
                 <img src="<?php echo $profilePicUrl; ?>" alt="Profile" class="profile-icon">
               </button>
               <div class="profile-dropdown" id="profileDropdown">
                 <div class="mini-profile">
                    <img src="<?php echo $profilePicUrl; ?>" alt="Profile" class="mini-avatar">
                    <div class="mini-text" style="text-align:left;">
                      <div class="mini-name" style="color:#222;"><?php echo htmlspecialchars($userName); ?></div>
                      <div style="font-size:0.8rem; color:#666;"><?php echo ucfirst($userType); ?></div>
                    </div>
                 </div>
                 <div class="profile-dropdown-actions">
                    <a href="<?php echo $userType === 'resident' ? 'profileresident.php' : 'dashboardvisitor.php'; ?>" class="btn-dashboard-view">
                      Open Full View of Profile Dashboard
                    </a>
                 </div>
               </div>
             </div>
          </div>
        <?php else: ?>
          <div class="nav-links" style="display:flex;">
            <a href="login.php" class="btn-nav btn-login">Login</a>
            <a href="signup.php" class="btn-nav btn-register">Register</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    
  </header>

  <!-- HERO SECTION -->
  <section class="hero" id="home">
    <?php if ($error !== '') { echo '<div class="error">' . htmlspecialchars($error) . '</div>'; } ?>
    <div class="hero-content reveal-on-scroll is-visible">
      
      <h2>WELCOME TO</h2>
      <div class="hero-brand">
        <h1>VictorianPass</h1>
      </div>

      <div class="hero-emblem">
        <span class="line"></span>
        <img src="images/logo.svg" alt="Emblem">
        <span class="line"></span>
      </div>

      <p class="tagline">Every home has a Story. Starts Your in a Place Worth Remembering</p>

      <div class="action-buttons" style="margin-top: 30px; display:flex; gap:15px; flex-wrap:wrap;">
        <?php if (!$isLoggedIn): ?>
          <button class="btn-change" onclick="window.location.href='login.php'" style="padding: 16px 40px; font-size: 1.2rem; border-radius: 40px; background: #f2c24f; color: #23412e; box-shadow: 0 4px 15px rgba(242, 194, 79, 0.4); border:none; cursor:pointer; font-weight:600;">Let’s Start</button>
          <!-- Check Status button removed per UX update -->
        <?php else: ?>
          <?php if ($isVisitor): ?>
             <button class="btn-change" onclick="window.location.href='reserve.php'" style="padding: 16px 32px; font-size: 1.1rem; border-radius: 40px; background: #f2c24f; color: #23412e; box-shadow: 0 4px 15px rgba(242, 194, 79, 0.4); border:none; cursor:pointer; font-weight:600;">Reserve an Amenity</button>
             <!-- Check Status removed for visitors on landing page -->
          <?php else: ?>
             <button class="btn-change" onclick="window.location.href='profileresident.php'" style="padding: 16px 32px; font-size: 1.1rem; border-radius: 40px; background: #23412e; color: #fff; box-shadow: 0 4px 15px rgba(35, 65, 46, 0.4); border:1px solid #f2c24f; cursor:pointer; font-weight:600;">My Dashboard</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      
      <!-- Login Required Modal -->
      <div id="loginModal" class="flash-overlay" style="display:none;">
        <div class="flash-modal" style="text-align:center; padding:30px;">
          <div class="title" style="color:#23412e; font-size:1.5rem; margin-bottom:10px;">Login Required</div>
          <div class="text" style="color:#555; margin-bottom:20px;">Please login to view your status.</div>
          <div style="display:flex; gap:10px; justify-content:center;">
             <button onclick="window.location.href='login.php'" style="padding:10px 20px; background:#23412e; color:#fff; border:none; border-radius:5px; cursor:pointer;">Login</button>
             <button onclick="document.getElementById('loginModal').style.display='none'" style="padding:10px 20px; background:#ccc; color:#333; border:none; border-radius:5px; cursor:pointer;">Cancel</button>
          </div>
        </div>
      </div>

      <script>
         document.getElementById('loginModal').addEventListener('click', function(e) {
             if (e.target === this) this.style.display = 'none';
         });
      </script>
    </div>
  </section>

  <section id="about-us" class="section reveal-on-scroll">
    <h2 class="section-title">About Us</h2>
    <div class="section-divider"></div>
    <div class="section-body">
      <p>Victorian Heights subdivision is a gated residence that offers accessibility located at Dahlia Fairview, BRGY. Sauyo, Quezon City. It is a residential development by Swire Land Corporation that provides accessibility and exclusivity with a gated community with 222 houses and an estimated 2,220 residents, making it secure against harm and vulnerability. Furthermore, beautifully designed houses that cater to thousands of residents live within reach of convenience and service while getting the experience of peace in a suburban community .</p>
      <img src="images/about subd.jpg" alt="Victorian Heights Subdivision" class="about-subdivision-photo">
    </div>
  </section>

  <section id="facilities" class="section reveal-on-scroll">
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
  <section id="about-system" class="section reveal-on-scroll">
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



  <script src="js/logout-modal.js"></script>
  <script>
    (function(){var t=document.getElementById('navToggle');var c=document.getElementById('navCollapse');if(!t||!c)return;t.addEventListener('click',function(){var o=c.classList.toggle('open');t.setAttribute('aria-expanded',o?'true':'false');});window.addEventListener('click',function(e){if(!c.contains(e.target)&&!t.contains(e.target)){c.classList.remove('open');t.setAttribute('aria-expanded','false');}});window.addEventListener('resize',function(){if(window.innerWidth>900){c.classList.remove('open');t.setAttribute('aria-expanded','false');}});})();
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      var items = document.querySelectorAll('.reveal-on-scroll');
      if (!items.length) return;
      if (!('IntersectionObserver' in window)) {
        for (var i = 0; i < items.length; i++) {
          items[i].classList.add('is-visible');
        }
        return;
      }
      var observer = new IntersectionObserver(function(entries, obs){
        for (var i = 0; i < entries.length; i++) {
          var entry = entries[i];
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            obs.unobserve(entry.target);
          }
        }
      }, { threshold: 0.15 });
      for (var j = 0; j < items.length; j++) {
        observer.observe(items[j]);
      }
    });
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      // Profile Dropdown Logic
      var wrap = document.getElementById('profileWrap');
      var trigger = document.getElementById('profileAccountTrigger');
      var dropdown = document.getElementById('profileDropdown');
      
      if(wrap && dropdown && trigger) {
          var closeTimeout;

          function openDropdown() {
              clearTimeout(closeTimeout);
              dropdown.style.display = 'block';
              requestAnimationFrame(function() {
                  dropdown.classList.add('show');
              });
          }

          function closeDropdown() {
              closeTimeout = setTimeout(function() {
                  dropdown.classList.remove('show');
                  setTimeout(function() {
                      if (!dropdown.classList.contains('show')) {
                          dropdown.style.display = 'none';
                      }
                  }, 300); // Match CSS transition duration
              }, 200); // Small delay before closing to allow moving mouse
          }

          // Hover Events
          wrap.addEventListener('mouseenter', openDropdown);
          wrap.addEventListener('mouseleave', closeDropdown);

          // Click Toggle
          trigger.addEventListener('click', function(e) {
              e.preventDefault();
              e.stopPropagation();
              if (dropdown.classList.contains('show')) {
                  dropdown.classList.remove('show');
                  setTimeout(function() { dropdown.style.display = 'none'; }, 300);
              } else {
                  openDropdown();
              }
          });

          // Close when clicking outside
          window.addEventListener('click', function(e) {
              if (!wrap.contains(e.target)) {
                  if (dropdown.classList.contains('show')) {
                      dropdown.classList.remove('show');
                      setTimeout(function() { dropdown.style.display = 'none'; }, 300);
                  }
              }
          });
      }
    });
  </script>

</body>
</html>
