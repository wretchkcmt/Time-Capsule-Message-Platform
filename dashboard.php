<?php
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];

try {
    $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get user stats
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM capsules WHERE user_id = ? AND status = 'scheduled') as scheduled_count,
            (SELECT COUNT(*) FROM capsules WHERE recipient_id = ? AND status = 'delivered' AND delivered_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_delivered,
            (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE) as unread_notifications
    ");
    $stmt->execute([$user_id, $user_id, $user_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get upcoming deliveries (next 30 days)
    $stmt = $pdo->prepare("
        SELECT c.*, u.name as sender_name
        FROM capsules c
        JOIN users u ON c.user_id = u.id
        WHERE c.recipient_id = ? 
        AND c.status = 'scheduled'
        AND c.delivery_time > NOW()
        ORDER BY c.delivery_time ASC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $upcoming = $stmt->fetchAll();

    // Get recently delivered (last 7 days)
    $stmt = $pdo->prepare("
        SELECT c.*, u.name as sender_name
        FROM capsules c
        JOIN users u ON c.user_id = u.id
        WHERE c.recipient_id = ? 
        AND c.status = 'delivered'
        AND c.delivered_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY c.delivered_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent = $stmt->fetchAll();

    // Get draft capsules
    $stmt = $pdo->prepare("
        SELECT * FROM capsules 
        WHERE user_id = ? AND is_draft = TRUE
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $stmt->execute([$user_id]);
    $drafts = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database error. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard • Time Capsule Messenger</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
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
      min-height: 100vh;
      overflow-x: hidden;
    }

    .container {
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar */
    .sidebar {
      width: 260px;
      background-color: #000;
      border-right: 1px solid #222;
      padding: 40px 25px 40px 40px;
      display: flex;
      flex-direction: column;
      position: fixed;
      height: 100vh;
      overflow-y: auto;
      z-index: 100;
    }

    .logo-section {
      display: flex;
      align-items: center;
      margin-bottom: 50px;
    }

    .clock-mini {
      width: 45px;
      height: 45px;
      border: 2px solid #fff;
      border-radius: 50%;
      position: relative;
      margin-right: 16px;
      opacity: 0.7;
    }

    .clock-hand-mini {
      position: absolute;
      top: 20%;
      left: 50%;
      width: 2px;
      height: 45%;
      background-color: #fff;
      transform-origin: bottom center;
      animation: spin-hand 10s linear infinite;
      transform: translateX(-50%);
    }

    @keyframes spin-hand {
      0% { transform: translateX(-50%) rotate(0deg); }
      100% { transform: translateX(-50%) rotate(360deg); }
    }

    .logo-text {
      font-family: 'Playfair Display', serif;
      font-size: 1.5rem;
      font-weight: 600;
      color: #fff;
      letter-spacing: -0.5px;
      white-space: nowrap;
    }

    .user-section {
      display: flex;
      align-items: center;
      margin-bottom: 40px;
      padding: 12px 0;
    }

    .user-avatar {
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: linear-gradient(135deg, #6c5ce7, #a29bfe);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 1.3rem;
      margin-right: 16px;
      flex-shrink: 0;
    }

    .user-details {
      flex: 1;
    }

    .user-name {
      font-size: 1.1rem;
      font-weight: 600;
      margin-bottom: 4px;
      color: #fff;
    }

    .user-role {
      font-size: 0.85rem;
      color: #aaa;
      display: flex;
      align-items: center;
    }

    .admin-badge {
      background: rgba(255, 89, 89, 0.2);
      color: #ff5959;
      font-size: 0.7rem;
      padding: 3px 8px;
      border-radius: 4px;
      margin-left: 8px;
      border: 1px solid rgba(255, 89, 89, 0.3);
    }

    .nav-section {
      flex: 1;
    }

    .nav-title {
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #555;
      margin: 25px 0 15px 0;
      padding-left: 5px;
    }

    .nav-item {
      display: flex;
      align-items: center;
      padding: 12px 16px;
      margin-bottom: 6px;
      color: #aaa;
      text-decoration: none;
      font-weight: 500;
      border-radius: 8px;
      transition: all 0.3s ease;
      position: relative;
    }

    .nav-item:hover, .nav-item.active {
      color: #fff;
      background-color: rgba(108, 92, 231, 0.15);
    }

    .nav-item i {
      width: 24px;
      font-size: 1.1rem;
      margin-right: 14px;
      text-align: center;
    }

    .nav-badge {
      margin-left: auto;
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
      font-size: 0.75rem;
      padding: 3px 10px;
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .logout-item {
      padding: 12px 16px;
      margin-top: auto;
      color: #ff5959;
      text-decoration: none;
      font-weight: 500;
      border-radius: 8px;
      display: flex;
      align-items: center;
      transition: all 0.3s ease;
    }

    .logout-item:hover {
      background-color: rgba(255, 89, 89, 0.1);
    }

    .logout-item i {
      width: 24px;
      font-size: 1.1rem;
      margin-right: 14px;
      text-align: center;
    }

    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: 260px;
      padding: 40px;
    }

    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 40px;
    }

    .greeting {
      font-family: 'Playfair Display', serif;
      font-size: 2.3rem;
      font-weight: 600;
      color: #fff;
      line-height: 1.2;
    }

    .header-actions .btn {
      padding: 10px 24px;
      background: transparent;
      border: 1px solid #444;
      color: #aaa;
      border-radius: 30px;
      font-size: 0.95rem;
      font-weight: 500;
      text-decoration: none;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .header-actions .btn:hover {
      border-color: #fff;
      color: #fff;
      background: rgba(255, 255, 255, 0.05);
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 30px;
      margin-bottom: 50px;
    }

    .stat-card {
      background: rgba(30, 30, 30, 0.5);
      border: 1px solid #222;
      border-radius: 16px;
      padding: 30px;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .stat-card:hover {
      border-color: #444;
      transform: translateY(-3px);
    }

    .stat-label {
      font-size: 0.9rem;
      color: #888;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 12px;
    }

    .stat-value {
      font-size: 2.8rem;
      font-weight: 600;
      background: linear-gradient(to right, #fff, #bbb);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 8px;
    }

    .stat-desc {
      font-size: 1rem;
      color: #ccc;
      font-weight: 500;
    }

    .section {
      margin-bottom: 60px;
    }

    .section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 30px;
    }

    .section-title {
      font-family: 'Playfair Display', serif;
      font-size: 1.8rem;
      font-weight: 600;
      color: #fff;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .section-title i {
      color: #6c5ce7;
      font-size: 1.4rem;
    }

    .view-all {
      color: #6c5ce7;
      text-decoration: none;
      font-weight: 500;
      font-size: 0.95rem;
      transition: color 0.3s ease;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .view-all:hover {
      color: #a29bfe;
    }

    .capsule-grid {
      display: grid;
      gap: 25px;
    }

    .capsule-card {
      background: rgba(25, 25, 25, 0.7);
      border: 1px solid #2a2a2a;
      border-radius: 16px;
      padding: 28px;
      transition: all 0.3s ease;
      position: relative;
    }

    .capsule-card:hover {
      border-color: #444;
      transform: translateY(-2px);
    }

    .capsule-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 20px;
    }

    .sender-info {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .sender-avatar {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: linear-gradient(135deg, #55efc4, #74b9ff);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 1.2rem;
      flex-shrink: 0;
    }

    .sender-meta h3 {
      font-size: 1.2rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 4px;
    }

    .sender-meta p {
      font-size: 0.9rem;
      color: #999;
    }

    .capsule-status {
      padding: 6px 16px;
      border-radius: 30px;
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .status-scheduled {
      background: rgba(255, 205, 86, 0.15);
      color: #ffcd56;
      border: 1px solid rgba(255, 205, 86, 0.3);
    }

    .status-delivered {
      background: rgba(85, 239, 196, 0.15);
      color: #55efc4;
      border: 1px solid rgba(85, 239, 196, 0.3);
    }

    .status-draft {
      background: rgba(255, 159, 67, 0.15);
      color: #ff9f43;
      border: 1px solid rgba(255, 159, 67, 0.3);
    }

    .capsule-title {
      font-size: 1.4rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 16px;
      line-height: 1.3;
    }

    .capsule-content {
      font-size: 1rem;
      color: #ccc;
      line-height: 1.6;
      margin-bottom: 24px;
      max-height: 90px;
      overflow: hidden;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
    }

    .capsule-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.9rem;
      color: #777;
    }

    .capsule-actions {
      display: flex;
      gap: 12px;
    }

    .action-btn {
      padding: 8px 18px;
      background: transparent;
      border: 1px solid #444;
      color: #aaa;
      border-radius: 30px;
      font-size: 0.85rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .action-btn:hover {
      border-color: #fff;
      color: #fff;
      background: rgba(255, 255, 255, 0.05);
    }

    .action-btn.primary {
      border-color: #6c5ce7;
      color: #6c5ce7;
    }

    .action-btn.primary:hover {
      background: rgba(108, 92, 231, 0.1);
      color: #a29bfe;
    }

    .empty-state {
      text-align: center;
      padding: 80px 40px;
      color: #777;
    }

    .empty-icon {
      font-size: 5rem;
      margin-bottom: 25px;
      opacity: 0.3;
      color: #444;
    }

    .empty-title {
      font-size: 1.8rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 15px;
    }

    .empty-desc {
      font-size: 1.1rem;
      color: #999;
      max-width: 500px;
      margin: 0 auto 30px;
      line-height: 1.6;
    }

    .empty-cta {
      display: inline-block;
      padding: 14px 36px;
      background: transparent;
      border: 2px solid #fff;
      color: #fff;
      border-radius: 30px;
      font-size: 1.05rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.3s ease;
    }

    .empty-cta:hover {
      background: #fff;
      color: #000;
    }

    .mod-section {
      background: rgba(255, 89, 89, 0.05);
      border: 1px dashed rgba(255, 89, 89, 0.3);
      border-radius: 16px;
      padding: 30px;
      margin: 60px 0;
    }

    .mod-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 20px;
      color: #ff5959;
    }

    .mod-header i {
      font-size: 1.4rem;
    }

    .mod-desc {
      color: #ccc;
      margin-bottom: 25px;
      line-height: 1.6;
    }

    .mod-actions {
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
    }

    .mod-btn {
      padding: 12px 28px;
      background: transparent;
      border: 1px solid #ff5959;
      color: #ff5959;
      border-radius: 30px;
      font-size: 0.95rem;
      font-weight: 500;
      text-decoration: none;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .mod-btn:hover {
      background: rgba(255, 89, 89, 0.1);
    }

    .compose-fab {
      position: fixed;
      bottom: 40px;
      right: 40px;
      width: 65px;
      height: 65px;
      border-radius: 50%;
      background: linear-gradient(135deg, #6c5ce7, #a29bfe);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1.8rem;
      box-shadow: 0 6px 25px rgba(108, 92, 231, 0.4);
      cursor: pointer;
      transition: all 0.3s ease;
      z-index: 999;
      border: none;
    }

    .compose-fab:hover {
      transform: scale(1.05) rotate(15deg);
      box-shadow: 0 8px 35px rgba(108, 92, 231, 0.6);
    }

    /* Responsive Design */
    @media (max-width: 1200px) {
      .main-content {
        padding: 30px;
      }
      .sidebar {
        padding: 30px 20px 30px 30px;
      }
      .logo-text {
        font-size: 1.3rem;
      }
    }

    @media (max-width: 992px) {
      .container {
        flex-direction: column;
      }
      .sidebar {
        width: 100%;
        height: auto;
        position: relative;
        padding: 25px 20px;
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 30px;
        overflow-x: auto;
      }
      .logo-section {
        margin-bottom: 0;
      }
      .user-section {
        margin-bottom: 0;
      }
      .nav-section {
        display: flex;
        overflow-x: auto;
        padding: 10px 0;
        margin: 0;
        flex: 1;
      }
      .nav-title {
        display: none;
      }
      .nav-item {
        flex-direction: column;
        padding: 15px 12px;
        min-width: 80px;
        text-align: center;
        white-space: nowrap;
      }
      .nav-item i {
        margin-right: 0;
        margin-bottom: 6px;
        font-size: 1.2rem;
      }
      .main-content {
        margin-left: 0;
        padding: 25px;
      }
      .stats-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 576px) {
      .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
      }
      .greeting {
        font-size: 1.8rem;
      }
      .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
      }
      .capsule-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
      }
      .capsule-footer {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
      }
      .capsule-actions {
        width: 100%;
        justify-content: flex-start;
        gap: 10px;
      }
      .action-btn {
        flex: 1;
        text-align: center;
      }
      .compose-fab {
        width: 60px;
        height: 60px;
        font-size: 1.6rem;
      }
    }
  </style>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

  <div class="container">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="logo-section">
        <div class="clock-mini">
          <div class="clock-hand-mini"></div>
        </div>
        <div class="logo-text">Time Capsule</div>
      </div>

      <div class="user-section">
        <div class="user-avatar"><?= strtoupper(substr($user_name, 0, 1)) ?></div>
        <div class="user-details">
          <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
          <div class="user-role">
            <?= htmlspecialchars(ucfirst($user_role)) ?>
            <?php if ($user_role === 'admin'): ?>
              <span class="admin-badge">ADMIN</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="nav-section">
        <div class="nav-title">MAIN</div>
        <a href="dashboard.php" class="nav-item active">
          <i class="fas fa-home"></i>
          <span>Dashboard</span>
        </a>
        <a href="compose.php" class="nav-item">
          <i class="fas fa-pen"></i>
          <span>New Capsule</span>
        </a>
        <a href="scheduled.php" class="nav-item">
          <i class="fas fa-clock"></i>
          <span>Scheduled</span>
          <?php if ($stats['scheduled_count'] > 0): ?>
          <span class="nav-badge"><?= $stats['scheduled_count'] ?></span>
          <?php endif; ?>
        </a>
        <a href="inbox.php" class="nav-item">
          <i class="fas fa-inbox"></i>
          <span>Inbox</span>
          <?php if ($stats['recent_delivered'] > 0): ?>
          <span class="nav-badge"><?= $stats['recent_delivered'] ?></span>
          <?php endif; ?>
        </a>
        <a href="notifications.php" class="nav-item">
          <i class="fas fa-bell"></i>
          <span>Notifications</span>
          <?php if ($stats['unread_notifications'] > 0): ?>
          <span class="nav-badge"><?= $stats['unread_notifications'] ?></span>
          <?php endif; ?>
        </a>

        <?php if ($user_role === 'moderator' || $user_role === 'admin'): ?>
        <div class="nav-title">MODERATION</div>
        <a href="moderation.php" class="nav-item">
          <i class="fas fa-flag"></i>
          <span>Reports</span>
        </a>
        <?php endif; ?>

        <?php if ($user_role === 'admin'): ?>
        <a href="admin.php" class="nav-item">
          <i class="fas fa-user-shield"></i>
          <span>Admin Panel</span>
        </a>
        <?php endif; ?>
      </div>

      <a href="logout.php" class="logout-item">
        <i class="fas fa-sign-out-alt"></i>
        <span>Log Out</span>
      </a>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
      <div class="header">
        <h1 class="greeting">Welcome back, <?= htmlspecialchars(explode(' ', $user_name)[0]) ?>.</h1>
        <div class="header-actions">
          <a href="profile.php" class="btn"><i class="fas fa-cog"></i> Settings</a>
        </div>
      </div>

      <!-- Stats Overview -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">SCHEDULED</div>
          <div class="stat-value"><?= $stats['scheduled_count'] ?></div>
          <div class="stat-desc">Messages awaiting delivery</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">RECENTLY DELIVERED</div>
          <div class="stat-value"><?= $stats['recent_delivered'] ?></div>
          <div class="stat-desc">In the last 7 days</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">UNREAD</div>
          <div class="stat-value"><?= $stats['unread_notifications'] ?></div>
          <div class="stat-desc">Pending notifications</div>
        </div>
      </div>

      <!-- Recently Delivered Section -->
      <div class="section">
        <div class="section-header">
          <h2 class="section-title"><i class="fas fa-envelope-open-text"></i> Recently Delivered</h2>
          <a href="inbox.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        
        <?php if (empty($recent)): ?>
          <div class="empty-state">
            <i class="fas fa-hourglass-end empty-icon"></i>
            <h3 class="empty-title">No messages received recently</h3>
            <p class="empty-desc">Time capsules sent to you will appear here when they're delivered from the future.</p>
            <a href="compose.php" class="empty-cta">Send a Capsule to Yourself</a>
          </div>
        <?php else: ?>
          <div class="capsule-grid">
            <?php foreach ($recent as $capsule): ?>
            <div class="capsule-card">
              <div class="capsule-header">
                <div class="sender-info">
                  <div class="sender-avatar"><?= strtoupper(substr($capsule['sender_name'], 0, 1)) ?></div>
                  <div class="sender-meta">
                    <h3><?= htmlspecialchars($capsule['sender_name']) ?></h3>
                    <p>Delivered <?= date('M j, Y', strtotime($capsule['delivered_at'])) ?></p>
                  </div>
                </div>
                <span class="capsule-status status-delivered">Delivered</span>
              </div>
              <h3 class="capsule-title"><?= htmlspecialchars($capsule['title'] ?: 'Untitled Message') ?></h3>
              <div class="capsule-content"><?= htmlspecialchars(substr($capsule['content'], 0, 250)) ?><?= strlen($capsule['content']) > 250 ? '...' : '' ?></div>
              <div class="capsule-footer">
                <span><i class="far fa-calendar-check"></i> Sent on <?= date('M j, Y', strtotime($capsule['created_at'])) ?></span>
                <div class="capsule-actions">
                  <button class="action-btn"><i class="fas fa-eye"></i> View</button>
                  <button class="action-btn"><i class="fas fa-share"></i> Share</button>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Upcoming Deliveries Section -->
      <div class="section">
        <div class="section-header">
          <h2 class="section-title"><i class="fas fa-clock"></i> Upcoming Deliveries</h2>
          <a href="scheduled.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        
        <?php if (empty($upcoming)): ?>
          <div class="empty-state">
            <i class="fas fa-stopwatch empty-icon"></i>
            <h3 class="empty-title">No upcoming deliveries</h3>
            <p class="empty-desc">Create a time capsule to send messages to your future self or others.</p>
            <a href="compose.php" class="empty-cta">Create New Capsule</a>
          </div>
        <?php else: ?>
          <div class="capsule-grid">
            <?php foreach ($upcoming as $capsule): ?>
            <div class="capsule-card">
              <div class="capsule-header">
                <div class="sender-info">
                  <div class="sender-avatar"><?= strtoupper(substr($capsule['sender_name'], 0, 1)) ?></div>
                  <div class="sender-meta">
                    <h3><?= htmlspecialchars($capsule['sender_name']) ?></h3>
                    <p>Scheduled for <?= date('M j, Y', strtotime($capsule['delivery_time'])) ?></p>
                  </div>
                </div>
                <span class="capsule-status status-scheduled">Scheduled</span>
              </div>
              <h3 class="capsule-title"><?= htmlspecialchars($capsule['title'] ?: 'Untitled Message') ?></h3>
              <div class="capsule-content"><?= htmlspecialchars(substr($capsule['content'], 0, 250)) ?><?= strlen($capsule['content']) > 250 ? '...' : '' ?></div>
              <div class="capsule-footer">
                <span><i class="far fa-calendar-plus"></i> Created <?= date('M j, Y', strtotime($capsule['created_at'])) ?></span>
                <div class="capsule-actions">
                  <button class="action-btn"><i class="fas fa-edit"></i> Edit</button>
                  <button class="action-btn"><i class="fas fa-trash"></i> Delete</button>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Drafts Section -->
      <?php if (!empty($drafts)): ?>
      <div class="section">
        <div class="section-header">
          <h2 class="section-title"><i class="fas fa-pen"></i> Your Drafts</h2>
          <a href="drafts.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        
        <div class="capsule-grid">
          <?php foreach ($drafts as $draft): ?>
          <div class="capsule-card">
            <div class="capsule-header">
              <div class="sender-info">
                <div class="sender-avatar"><?= strtoupper(substr($user_name, 0, 1)) ?></div>
                <div class="sender-meta">
                  <h3><?= htmlspecialchars($draft['title'] ?: 'Untitled Draft') ?></h3>
                  <p>Last edited <?= date('M j, Y', strtotime($draft['created_at'])) ?></p>
                </div>
              </div>
              <span class="capsule-status status-draft">Draft</span>
            </div>
            <div class="capsule-content"><?= htmlspecialchars(substr($draft['content'], 0, 250)) ?><?= strlen($draft['content']) > 250 ? '...' : '' ?></div>
            <div class="capsule-footer">
              <span><i class="far fa-clock"></i> Created <?= date('M j, Y', strtotime($draft['created_at'])) ?></span>
              <div class="capsule-actions">
                <a href="compose.php?id=<?= $draft['id'] ?>" class="action-btn primary"><i class="fas fa-pen"></i> Continue</a>
                <button class="action-btn"><i class="fas fa-trash"></i> Delete</button>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Moderator/Admin Section -->
      <?php if ($user_role === 'moderator' || $user_role === 'admin'): ?>
      <div class="mod-section">
        <div class="mod-header">
          <i class="fas fa-shield-alt"></i>
          <h3>Moderation Tools</h3>
        </div>
        <p class="mod-desc">You have special privileges to help maintain the integrity of our time capsule community.</p>
        <div class="mod-actions">
          <a href="moderation.php" class="mod-btn"><i class="fas fa-flag"></i> Review Reports</a>
          <?php if ($user_role === 'admin'): ?>
          <a href="admin.php" class="mod-btn"><i class="fas fa-users-cog"></i> User Management</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </main>

    <!-- Floating Action Button -->
    <a href="compose.php" class="compose-fab">
      <i class="fas fa-plus"></i>
    </a>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Add subtle hover effects to cards
        const cards = document.querySelectorAll('.capsule-card, .stat-card');
        cards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
                this.style.boxShadow = '0 10px 30px rgba(0, 0, 0, 0.2)';
            });
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'none';
            });
        });
    });
  </script>

</body>
</html>