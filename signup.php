<?php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

// Common typo domains to flag
$typoDomains = [
    'gmal.com' => 'gmail.com',
    'gnail.com' => 'gmail.com',
    'gamil.com' => 'gmail.com',
    'gmail.con' => 'gmail.com',
    'gmail.co' => 'gmail.com',
    'yahoo.con' => 'yahoo.com',
    'hotmail.con' => 'hotmail.com',
    'outlook.con' => 'outlook.com'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate inputs
    if (empty($name) || empty($email) || empty($password)) {
        $error = "All fields are required.";
    } elseif (strlen($name) < 2) {
        $error = "Name must be at least 2 characters.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Check for common email typos
        $domain = substr(strrchr($email, "@"), 1);
        if (isset($typoDomains[$domain])) {
            $corrected = strstr($email, '@', true) . '@' . $typoDomains[$domain];
            $error = "Did you mean: {$corrected}? Please fix your email domain.";
        } else {
            try {
                $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Check if email already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = "An account with this email already exists.";
                } else {
                    // Create user
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, email, password_hash, role, created_at)
                        VALUES (?, ?, ?, 'registered', NOW())
                    ");
                    $stmt->execute([$name, $email, $password_hash]);
                    $user_id = $pdo->lastInsertId();

                    // Create default settings
                    $stmt = $pdo->prepare("
                        INSERT INTO user_settings (user_id, theme_mode, notifications_enabled, timezone)
                        VALUES (?, 'dark', TRUE, 'UTC')
                    ");
                    $stmt->execute([$user_id]);

                    // Create email verification token (optional enhancement)
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+1 day'));
                    $stmt = $pdo->prepare("
                        INSERT INTO email_verification (user_id, token, expires_at)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$user_id, $token, $expires]);

                    $success = "Account created successfully! You can now log in.";
                    
                    // Optional: Auto-login or redirect after 2 seconds
                    // header("refresh:2;url=login.php");
                }
            } catch (PDOException $e) {
                $error = "System error. Please try again later.";
                error_log("Signup DB Error: " . $e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sign Up • Time Capsule Messenger</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Montserrat', sans-serif;
      background-color: #000;
      color: #fff;
      height: 100vh;
      overflow-x: hidden;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 25px 60px;
      width: 100%;
      background-color: #000;
      border-bottom: 1px solid #222;
      position: relative;
      z-index: 10;
    }

    header h1 {
      font-family: 'Playfair Display', serif;
      font-size: 2rem;
      font-weight: 700;
      color: #fff;
      letter-spacing: -0.5px;
    }

    nav a {
      color: #aaa;
      text-decoration: none;
      margin-left: 30px;
      font-weight: 500;
      font-size: 1rem;
      transition: color 0.3s, transform 0.2s;
      position: relative;
    }

    nav a:hover {
      color: #fff;
      transform: translateY(-2px);
    }

    .signup-container {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 40px 20px;
      width: 100%;
      max-width: 500px;
      position: relative;
      z-index: 2;
    }

    .clock-wrapper {
      margin-bottom: 30px;
      width: 80px;
      height: 80px;
      border: 2px solid rgba(255,255,255,0.3);
      border-radius: 50%;
      position: relative;
      opacity: 0.8;
      box-shadow: 0 0 20px rgba(255,255,255,0.1);
    }

    .clock-hand {
      position: absolute;
      top: 15%;
      left: 50%;
      width: 2px;
      height: 35%;
      background: linear-gradient(to top, #fff, transparent);
      transform-origin: bottom center;
      animation: spin-hand 8s linear infinite;
      box-shadow: 0 0 10px #fff;
      transform: translateX(-50%);
    }

    @keyframes spin-hand {
      0% { transform: translateX(-50%) rotate(0deg); }
      100% { transform: translateX(-50%) rotate(360deg); }
    }

    .signup-box {
      background: rgba(20, 20, 20, 0.7);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 16px;
      padding: 40px;
      width: 100%;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
      animation: fadeInUp 0.8s ease-out;
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .signup-box h2 {
      font-family: 'Playfair Display', serif;
      font-size: 2rem;
      font-weight: 700;
      text-align: center;
      margin-bottom: 30px;
      color: #fff;
    }

    .form-group {
      margin-bottom: 25px;
      position: relative;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-size: 0.95rem;
      color: #bbb;
      font-weight: 500;
    }

    .form-group input {
      width: 100%;
      padding: 14px;
      padding-right: 40px;
      border: 1px solid #333;
      background: #111;
      border-radius: 8px;
      color: #fff;
      font-size: 1rem;
      font-family: 'Montserrat', sans-serif;
      transition: border-color 0.3s, box-shadow 0.3s;
    }

    .form-group input:focus {
      outline: none;
      border-color: #555;
      box-shadow: 0 0 0 2px rgba(255,255,255,0.1);
    }

    .toggle-password {
      position: absolute;
      right: 12px;
      top: 42px;
      cursor: pointer;
      color: #777;
      font-size: 1.2rem;
      transition: color 0.3s;
    }

    .toggle-password:hover {
      color: #fff;
    }

    .error {
      color: #ff6b6b;
      font-size: 0.9rem;
      margin-bottom: 20px;
      padding: 12px;
      background: rgba(255, 107, 107, 0.1);
      border: 1px solid rgba(255, 107, 107, 0.3);
      border-radius: 6px;
      text-align: center;
    }

    .success {
      color: #51cf66;
      font-size: 0.9rem;
      margin-bottom: 20px;
      padding: 12px;
      background: rgba(81, 207, 102, 0.1);
      border: 1px solid rgba(81, 207, 102, 0.3);
      border-radius: 6px;
      text-align: center;
    }

    .signup-btn {
      width: 100%;
      padding: 16px;
      background: transparent;
      border: 2px solid #fff;
      color: #fff;
      border-radius: 50px;
      font-size: 1.1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      letter-spacing: 0.5px;
      position: relative;
      overflow: hidden;
      z-index: 1;
    }

    .signup-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 0;
      height: 100%;
      background-color: #fff;
      transition: width 0.4s ease;
      z-index: -1;
    }

    .signup-btn:hover {
      color: #000;
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(255,255,255,0.2);
    }

    .signup-btn:hover::before {
      width: 100%;
    }

    .links {
      text-align: center;
      margin-top: 25px;
      font-size: 0.95rem;
    }

    .links a {
      color: #aaa;
      text-decoration: none;
      transition: color 0.3s;
    }

    .links a:hover {
      color: #fff;
    }

    footer {
      text-align: center;
      padding: 25px;
      font-size: 0.9rem;
      color: #555;
      background: #000;
      width: 100%;
      border-top: 1px solid #1a1a1a;
      font-weight: 400;
      letter-spacing: 0.5px;
    }

    @media (max-width: 768px) {
      header {
        padding: 20px;
        flex-direction: column;
        gap: 15px;
      }

      .signup-container {
        padding: 20px;
      }

      .signup-box {
        padding: 30px 25px;
      }

      .signup-box h2 {
        font-size: 1.8rem;
      }

      .clock-wrapper {
        width: 60px;
        height: 60px;
      }
    }

    @media (max-width: 480px) {
      .clock-wrapper {
        display: none;
      }

      .signup-box {
        margin: 0 15px;
      }
    }
  </style>
</head>
<body>

  <header>
    <h1>Time Capsule Messenger</h1>
    <nav>
      <a href="index.php">Home</a>
      <a href="#">About</a>
      <a href="login.php">Login</a>
      <a href="signup.php">Sign Up</a>
    </nav>
  </header>

  <main class="signup-container">
    <div class="clock-wrapper">
      <div class="clock-hand"></div>
    </div>

    <div class="signup-box">
      <h2>Create Your Account</h2>

      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label for="name">Full Name</label>
          <input type="text" id="name" name="name" required 
                 value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                 placeholder="John Doe">
        </div>

        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" required
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 placeholder="you@example.com">
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required
                 placeholder="••••••••">
          <span class="toggle-password" onclick="togglePasswordVisibility('password')">👁️</span>
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm Password</label>
          <input type="password" id="confirm_password" name="confirm_password" required
                 placeholder="••••••••">
          <span class="toggle-password" onclick="togglePasswordVisibility('confirm_password')">👁️</span>
        </div>

        <button type="submit" class="signup-btn">Create Account</button>
      </form>

      <div class="links">
        <p>Already have an account? <a href="login.php">Log In</a></p>
      </div>
    </div>
  </main>

  <footer>
    &copy; <?= date('Y') ?> Time Capsule Messenger. Crafted with clarity.
  </footer>

  <script>
    function togglePasswordVisibility(fieldId) {
      const field = document.getElementById(fieldId);
      if (field.type === "password") {
        field.type = "text";
      } else {
        field.type = "password";
      }
    }
  </script>

</body>
</html>