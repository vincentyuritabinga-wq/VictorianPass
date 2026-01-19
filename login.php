<?php
session_start();
include("connect.php");  

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

        if ($result_staff->num_rows > 0) {
            $row = $result_staff->fetch_assoc();

            if ($password === $row['password']) {
                $_SESSION['email'] = $row['email'];
                $_SESSION['role']  = $row['role'];
                $_SESSION['staff_id'] = $row['id'];

                echo "<script>alert('Login successful!');</script>";
                if ($row['role'] === "admin") {
                    echo "<script>window.location.href='admin.php';</script>";
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
                    echo "<script>window.location.href='guard.php';</script>";
                }
                exit();
            } else {
                echo "<script>alert('Incorrect password!'); window.history.back();</script>";
                exit();
            }
        }
        $stmt_staff->close();

        // ✅ Step 2: Check if account exists in users (resident/visitor)
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

                echo "<script>alert('Login successful!');</script>";
                if ($row['user_type'] === 'resident') {
                    echo "<script>window.location.href='profileresident.php';</script>";
                } else {
                    echo "<script>window.location.href='mainpage.php';</script>";
                }
                exit();
            } else {
                echo "<script>alert('Incorrect password!'); window.history.back();</script>";
                exit();
            }
        } else {
            echo "<script>alert('Account not found! Please register first.'); window.history.back();</script>";
            exit();
        }
        $stmt_user->close();

    } else {
        echo "<script>alert('Please enter both email and password!'); window.history.back();</script>";
        exit();
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
  </script>
</body>
</html>