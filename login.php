<?php
session_start();
include("connect.php");  

$loginError = '';
$loginErrorMessage = '';
$loginSuccessMessage = '';
$loginRedirect = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['vp_account']) && isset($_POST['password'])) {
        $email = trim($_POST['vp_account']);
        $password = trim($_POST['password']);

        // Step 1: Check if account exists in staff (admin/guard)
        $sql_staff = "SELECT * FROM staff WHERE email = ?";
        $stmt_staff = $con->prepare($sql_staff);
        $stmt_staff->bind_param("s", $email);
        $stmt_staff->execute();
        $result_staff = $stmt_staff->get_result();

        $skipUserCheck = false;
        if ($result_staff->num_rows > 0) {
            $row = $result_staff->fetch_assoc();

            if ($password === $row['password']) {
                $_SESSION['email'] = $row['email'];
                $_SESSION['role']  = $row['role'];
                $_SESSION['staff_id'] = $row['id'];

                if ($row['role'] === "admin") {
                    $loginSuccessMessage = 'Login successful!';
                    $loginRedirect = 'admin.php';
                } elseif ($row['role'] === "guard") {
                    $con->query("CREATE TABLE IF NOT EXISTS login_history (id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT NOT NULL, login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, logout_time DATETIME NULL, INDEX idx_staff_id (staff_id)) ENGINE=InnoDB");
                    $stmtLog = $con->prepare("INSERT INTO login_history (staff_id) VALUES (?)");
                    $stmtLog->bind_param('i', $_SESSION['staff_id']);
                    $stmtLog->execute();
                    $_SESSION['login_history_id'] = $stmtLog->insert_id;
                    $stmtLog->close();
                    $local = explode('@', $_SESSION['email'])[0] ?? '';
                    $s = $local;
                    if (strpos($local, '_') !== false) { $parts = explode('_', $local); $s = end($parts); }
                    if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
                    $s = preg_replace('/[^a-zA-Z]/', '', $s);
                    $s = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
                    $_SESSION['guard_surname'] = $s;
                    $loginSuccessMessage = 'Login successful!';
                    $loginRedirect = 'guard.php';
                }
                $skipUserCheck = true;
            } else {
                $loginError = 'invalid_password';
                $loginErrorMessage = 'Incorrect password.';
                $skipUserCheck = true;
            }
        }
        $stmt_staff->close();

        // ✅ Step 2: Check if account exists in users (resident/visitor)
        if (!$skipUserCheck) {
            $sql_user = "SELECT * FROM users WHERE email = ?";
            $stmt_user = $con->prepare($sql_user);
            $stmt_user->bind_param("s", $email);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();

            if ($result_user->num_rows > 0) {
                $row = $result_user->fetch_assoc();

                if (password_verify($password, $row['password'])) {
                    $_SESSION['user_id']   = $row['id'];
                    $_SESSION['email']     = $row['email'];
                    $_SESSION['user_type'] = $row['user_type'];
                    // Keep a generic role key for parts of the site that expect it
                    $_SESSION['role']      = $row['user_type'];

                    if ($row['user_type'] === 'resident') {
                        $loginSuccessMessage = 'Login successful!';
                        $loginRedirect = 'profileresident.php';
                    } else {
                        $loginSuccessMessage = 'Login successful!';
                        $loginRedirect = 'mainpage.php';
                    }
                } else {
                    $loginError = 'invalid_password';
                    $loginErrorMessage = 'Incorrect password.';
                }
            } else {
                $loginError = 'account_not_found';
                $loginErrorMessage = 'This account doesn’t exist';
            }
            $stmt_user->close();
        }

    } else {
        $loginError = 'missing_fields';
        $loginErrorMessage = 'Please enter both email and password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login</title>
  <link rel="icon" type="image/png" href="images/logo.svg">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
        body {
  animation: fadeIn 0.6s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }

    body {
      display: flex;
      min-height: 100vh;
    }

    .login-modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(0,0,0,0.5);
      z-index: 9999;
    }
    .login-modal .modal-content {
      background: #fff;
      border-radius: 12px;
      padding: 28px 24px;
      width: 90%;
      max-width: 380px;
      text-align: center;
      position: relative;
      box-shadow: 0 12px 30px rgba(0,0,0,0.18);
    }
    .login-modal .modal-close {
      position: absolute;
      right: 12px;
      top: 10px;
      background: transparent;
      border: 0;
      font-size: 22px;
      cursor: pointer;
      color: #666;
    }
    .login-modal .modal-title {
      font-size: 1.15rem;
      font-weight: 700;
      color: #c0392b;
      margin-bottom: 10px;
    }
    .login-modal .modal-message {
      color: #444;
      font-size: 0.95rem;
      margin-bottom: 18px;
    }
    .login-modal .modal-btn {
      border: 0;
      background: #c0392b;
      color: #fff;
      padding: 10px 18px;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      width: 100%;
    }
    .login-modal.success .modal-title {
      color: #1e8f3e;
    }
    .login-modal.success .modal-btn {
      background: #1e8f3e;
    }

    .login-wrapper {
      display: flex;
      width: 100%;
    }

    /* LEFT SIDE */
    .login-left {
      flex: 1;
      background: url("images/signuppage/loginsingup.png") center/cover no-repeat;
      display: flex;
      align-items: center;
      padding: 60px;
      position: relative;
      color: #fff;
    }

    .login-left::before {
      content: "";
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.45);
    }

    .branding {
      position: relative;
      z-index: 2;
      max-width: 400px;
    }

    .branding h1 {
      font-size: 2.4rem;
      font-weight: 700;
      margin-bottom: 15px;
    }

    .branding p {
      font-size: 1rem;
      line-height: 1.5;
    }

    /* RIGHT SIDE */
    .login-right {
      flex: 1;
      background: #fff;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 40px;
      position: relative;
    }

.back-arrow {
  position: absolute;
  top: 1.5rem;
  left: 1.5rem;
}
.back-arrow img {
  width: 24px;
  cursor: pointer;
}

    .login-box {
      width: 100%;
      max-width: 420px; /* ✅ wider form */
    }

    .login-box img.biglogo {
      width: 80px;
      display: block;
      margin: 0 auto 15px;
    }

    .login-box h2 {
      text-align: center;
      font-size: 1.8rem;
      margin-bottom: 5px;
    }

    .subtitle {
      text-align: center;
      margin-bottom: 25px;
      color: #666;
    }

    .login-box form {
      display: flex;
      flex-direction: column;
    }

    .login-box label {
      font-size: 0.9rem;
      margin-bottom: 6px;
      font-weight: 500;
    }

    .login-box input {
      padding: 14px;
      margin-bottom: 18px;
      border-radius: 8px;
      border: 1px solid #ccc;
      font-size: 1rem;
    }

    .login-box input:focus {
      border-color: #23412e;
    }

    .password-field {
      position: relative;
    }

    .password-field input {
      width: 100%;
      padding-right: 40px;
    }

    .toggle-password {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      font-size: 1rem;
      color: #666;
    }

    .forgot {
      text-align: right;
      margin-bottom: 20px;
    }

    .forgot a {
      color: #23412e;
      font-size: 0.85rem;
      text-decoration: none;
    }

    .btn-login {
      padding: 14px;
      border: none;
      border-radius: 8px;
      background: #23412e;
      color: #fff;
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      transition: 0.2s;
    }

    .btn-login:hover {
      transform: scale(1.05);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .signup-link {
      margin-top: 15px;
      text-align: center;
      font-size: 0.9rem;
    }

    .signup-link a {
      color: #23412e;
      font-weight: 600;
      text-decoration: none;
    }

    .signup-link a:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>
  <div class="login-wrapper">
    <!-- Left Side -->
    <div class="login-left">
    </div>

    <!-- Right Side -->
    <div class="login-right">
      <a href="mainpage.php" class="back-arrow">
        <img src="images/signuppage/back.svg" alt="Back">
      </a>

      <div class="login-box">
        <img src="images/loginpage/biglogo.svg" alt="Logo" class="biglogo">
        <h2>Welcome Back!</h2>
        <p class="subtitle">To Victorian Heights</p>

        <form action="login.php" method="POST">
          <label for="vp_account">Email</label>
          <input type="email" id="vp_account" name="vp_account" placeholder="Email*" required>

          <label for="password">Password</label>
          <div class="password-field">
            <input type="password" id="password" name="password" placeholder="Password*" required>
            <span class="toggle-password" onclick="togglePassword('password', this)">👁️</span>
          </div>

          <div class="forgot">
            <a href="#">Forgot Password?</a>
          </div>

          <button type="submit" class="btn-login">Login</button>
        </form>

        <p class="signup-link">
          Don’t have an account yet? <a href="signup.php">Sign up</a>
        </p>
      </div>
    </div>
  </div>

  <div id="loginErrorModal" class="login-modal">
    <div class="modal-content">
      <button type="button" class="modal-close" onclick="closeLoginError()">&times;</button>
      <div class="modal-title">Error</div>
      <div class="modal-message" id="loginErrorMessage"></div>
      <button type="button" class="modal-btn" onclick="closeLoginError()">OK</button>
    </div>
  </div>
  <div id="loginSuccessModal" class="login-modal success">
    <div class="modal-content">
      <button type="button" class="modal-close" onclick="closeLoginSuccess()">&times;</button>
      <div class="modal-title">Success</div>
      <div class="modal-message" id="loginSuccessMessage"></div>
      <button type="button" class="modal-btn" onclick="closeLoginSuccess()">Continue</button>
    </div>
  </div>

  <script>
    function togglePassword(id, el) {
      const input = document.getElementById(id);
      if (input.type === "password") {
        input.type = "text";
        el.textContent = "🙈";
      } else {
        input.type = "password";
        el.textContent = "👁️";
      }
    }

    function openLoginError(message) {
      const modal = document.getElementById('loginErrorModal');
      const msg = document.getElementById('loginErrorMessage');
      if (msg) msg.textContent = message;
      if (modal) modal.style.display = 'flex';
    }

    function closeLoginError() {
      const modal = document.getElementById('loginErrorModal');
      if (modal) modal.style.display = 'none';
    }
    function openLoginSuccess(message) {
      const modal = document.getElementById('loginSuccessModal');
      const msg = document.getElementById('loginSuccessMessage');
      if (msg) msg.textContent = message;
      if (modal) modal.style.display = 'flex';
    }
    function closeLoginSuccess() {
      const modal = document.getElementById('loginSuccessModal');
      if (modal) modal.style.display = 'none';
      if (window._loginRedirect) {
        window.location.href = window._loginRedirect;
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const errMsg = "<?php echo htmlspecialchars($loginErrorMessage, ENT_QUOTES); ?>";
      if (errMsg) {
        openLoginError(errMsg);
      }
      const successMsg = "<?php echo htmlspecialchars($loginSuccessMessage, ENT_QUOTES); ?>";
      const redirectTo = "<?php echo htmlspecialchars($loginRedirect, ENT_QUOTES); ?>";
      if (successMsg) {
        window._loginRedirect = redirectTo || '';
        openLoginSuccess(successMsg);
      }
    });
  </script>
</body>
</html>
