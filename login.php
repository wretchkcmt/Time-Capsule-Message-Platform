<?php
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT id, email, password_hash, name, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_email'] = $user['email'];

                // Log login
                $logStmt = $pdo->prepare("
                    INSERT INTO login_history (user_id, ip_address, user_agent)
                    VALUES (?, ?, ?)
                ");
                $logStmt->execute([
                    $user['id'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);

                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $error = "System error. Please try again later.";
            error_log("Login DB Error: " . $e->getMessage());
        }
    }
}
?>
<?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
    <div style="color: #55efc4; text-align: center; margin-bottom: 20px; padding: 12px; background: rgba(85, 239, 196, 0.1); border: 1px solid rgba(85, 239, 196, 0.3); border-radius: 6px;">
        ✅ You've been successfully logged out.
    </div>
<?php endif; ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login • Time Capsule Messenger</title>
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

    .login-container {
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

    .login-box {
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

    .login-box h2 {
      font-family: 'Playfair Display', serif;
      font-size: 2rem;
      font-weight: 700;
      text-align: center;
      margin-bottom: 30px;
      color: #fff;
    }

    .form-group {
      margin-bottom: 25px;
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

    .login-btn {
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

    .login-btn::before {
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

    .login-btn:hover {
      color: #000;
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(255,255,255,0.2);
    }

    .login-btn:hover::before {
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

      .login-container {
        padding: 20px;
      }

      .login-box {
        padding: 30px 25px;
      }

      .login-box h2 {
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

      .login-box {
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

  <main class="login-container">
    <div class="clock-wrapper">
      <div class="clock-hand"></div>
    </div>

    <div class="login-box">
      <h2>Welcome Back</h2>

      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" required autofocus
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>

        <button type="submit" class="login-btn">Log In</button>
      </form>

      <div class="links">
        <p>Don't have an account? <a href="signup.php">Sign Up</a></p>
        <p><a href="#">Forgot Password?</a></p>
      </div>
    </div>
  </main>

  <footer>
    &copy; <?= date('Y') ?> Time Capsule Messenger. Crafted with clarity.
  </footer>

</body>
</html>