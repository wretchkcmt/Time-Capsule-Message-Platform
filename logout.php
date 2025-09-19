<?php
session_start();

// Unset all session variables
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session
session_destroy();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Logged Out • Time Capsule Messenger</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Montserrat', sans-serif;
      background: linear-gradient(135deg, #0a0a0a, #1a1a2e);
      color: #fff;
      height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      position: relative;
    }

    .logout-container {
      text-align: center;
      padding: 40px;
      background: rgba(20, 20, 20, 0.8);
      backdrop-filter: blur(10px);
      border-radius: 20px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
      max-width: 500px;
      animation: fadeIn 0.8s ease-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .clock-wrapper {
      width: 80px;
      height: 80px;
      border: 2px solid rgba(255,255,255,0.3);
      border-radius: 50%;
      margin: 0 auto 30px;
      position: relative;
      opacity: 0.8;
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
      transform: translateX(-50%);
    }

    @keyframes spin-hand {
      0% { transform: translateX(-50%) rotate(0deg); }
      100% { transform: translateX(-50%) rotate(360deg); }
    }

    h1 {
      font-family: 'Playfair Display', serif;
      font-size: 2.2rem;
      font-weight: 700;
      margin-bottom: 20px;
      background: linear-gradient(to right, #ff7675, #fd79a8);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    p {
      font-size: 1.1rem;
      color: #bbb;
      margin-bottom: 30px;
      line-height: 1.6;
    }

    .redirect {
      color: #888;
      font-size: 0.95rem;
      margin-top: 20px;
    }

    .btn {
      display: inline-block;
      padding: 14px 32px;
      background: transparent;
      border: 2px solid #fff;
      color: #fff;
      border-radius: 50px;
      font-size: 1.05rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      margin-top: 10px;
    }

    .btn::before {
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

    .btn:hover {
      color: #000;
      transform: translateY(-3px);
      position: relative;
      overflow: hidden;
    }

    .btn:hover::before {
      width: 100%;
    }

    /* Floating particles for ambiance */
    .particle {
      position: absolute;
      width: 6px;
      height: 6px;
      background: rgba(255,255,255,0.2);
      border-radius: 50%;
      animation: float 6s infinite ease-in-out;
    }

    @keyframes float {
      0%, 100% { transform: translateY(0) translateX(0); opacity: 0.6; }
      50% { transform: translateY(-20px) translateX(10px); opacity: 0.2; }
    }
  </style>
</head>
<body>

  <!-- Ambient particles -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
        for (let i = 0; i < 15; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            particle.style.left = Math.random() * 100 + 'vw';
            particle.style.top = Math.random() * 100 + 'vh';
            particle.style.animationDelay = Math.random() * 5 + 's';
            document.body.appendChild(particle);
        }
    });
  </script>

  <div class="logout-container">
    <div class="clock-wrapper">
      <div class="clock-hand"></div>
    </div>
    <h1>Until We Meet Again</h1>
    <p>You've been safely logged out of Time Capsule Messenger.<br>Your messages remain sealed until their destined hour.</p>
    <a href="login.php" class="btn">Log Back In</a>
    <p class="redirect">Redirecting to login in 3 seconds...</p>
  </div>

  <script>
    // Redirect after 3 seconds
    setTimeout(function() {
        window.location.href = 'login.php';
    }, 3000);
  </script>

</body>
</html>