
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Time Capsule Messenger</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
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
      overflow: hidden;
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
    }

    header h1 {
      font-family: 'Playfair Display', serif;
      font-size: 2rem;
      color: #fff;
    }

    nav a {
      color: #aaa;
      text-decoration: none;
      margin-left: 30px;
      font-weight: 500;
      transition: color 0.3s;
    }

    nav a:hover {
      color: #fff;
    }

    .hero {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      padding: 60px 20px;
      position: relative;
      z-index: 2;
    }

    .clock-wrapper {
      margin-bottom: 30px;
      width: 100px;
      height: 100px;
      border: 2px solid #fff;
      border-radius: 50%;
      position: relative;
      opacity: 0.6;
    }

    .clock-hand {
      position: absolute;
      top: 15%;
      left: 50%;
      width: 2px;
      height: 35%;
      background-color: #fff;
      transform-origin: bottom center;
      animation: spin-hand 8s linear infinite;
    }

    @keyframes spin-hand {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .hero h2 {
      font-size: 3rem;
      font-family: 'Playfair Display', serif;
      color: #fff;
      margin-bottom: 25px;
      max-width: 800px;
      line-height: 1.3;
    }

    .hero p {
      font-size: 1.15rem;
      color: #ccc;
      max-width: 600px;
      margin-bottom: 45px;
      line-height: 1.6;
    }

    .cta-button {
      padding: 14px 36px;
      background: transparent;
      border: 2px solid #fff;
      color: #fff;
      border-radius: 30px;
      font-size: 1.05rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.3s ease-in-out;
    }

    .cta-button:hover {
      background-color: #fff;
      color: #000;
    }

    footer {
      text-align: center;
      padding: 20px;
      font-size: 0.9rem;
      color: #777;
      background: #000;
      width: 100%;
      border-top: 1px solid #222;
    }

    @media (max-width: 768px) {
      .clock-wrapper {
        display: none;
      }

      header {
        flex-direction: column;
        gap: 10px;
      }

      .hero h2 {
        font-size: 2.2rem;
      }

      nav a {
        margin-left: 15px;
        font-size: 0.95rem;
      }
    }
  </style>
</head>
<body>

  <header>
    <h1>Time Capsule Messenger</h1>
    <nav>
      <a href="#">Home</a>
      <a href="#">About</a>
      <a href="login.php">Login</a>
      <a href="signup.php">Sign Up</a>
    </nav>
  </header>

  <section class="hero">
    <div class="clock-wrapper">
      <div class="clock-hand"></div>
    </div>
    <h2>Preserve Words. Deliver Emotion. Across Time.</h2>
    <p>The most elegant way to send your thoughts forward — time-sealed and intention-driven.</p>
    <a href="login.php" class="cta-button">Begin Your Journey</a>
  </section>

  <footer>
    &copy; 2025 Time Capsule Messenger. Crafted with clarity.
  </footer>

</body>
</html>