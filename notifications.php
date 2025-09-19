<?php
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$error = '';
$success = '';

// Handle mark as read
if (isset($_GET['mark_read']) && !empty($_GET['mark_read'])) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$_GET['mark_read'], $user_id]);
        
        if ($result) {
            $success = "Notification marked as read.";
        } else {
            $error = "Failed to update notification.";
        }
    } catch (PDOException $e) {
        $error = "System error. Please try again later.";
        error_log("Mark Read Error: " . $e->getMessage());
    }
}

// Handle mark all as read
if (isset($_GET['mark_all_read'])) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
        $stmt->execute([$user_id]);
        
        $success = "All notifications marked as read.";
    } catch (PDOException $e) {
        $error = "System error. Please try again later.";
        error_log("Mark All Read Error: " . $e->getMessage());
    }
}

// Handle delete notification
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$_GET['delete'], $user_id]);
        
        if ($result) {
            $success = "Notification deleted.";
        } else {
            $error = "Failed to delete notification.";
        }
    } catch (PDOException $e) {
        $error = "System error. Please try again later.";
        error_log("Delete Notification Error: " . $e->getMessage());
    }
}

// Handle delete all notifications
if (isset($_GET['delete_all'])) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        $success = "All notifications deleted.";
    } catch (PDOException $e) {
        $error = "System error. Please try again later.";
        error_log("Delete All Notifications Error: " . $e->getMessage());
    }
}

// Get all notifications for this user
try {
    $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unread count
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$user_id]);
    $unread_count = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
    
} catch (PDOException $e) {
    die("Database error. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Notifications • Time Capsule Messenger</title>
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
    }

    .container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 40px 20px;
    }

    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 40px;
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 25px;
    }

    .back-btn {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 1.2rem;
      text-decoration: none;
      transition: all 0.3s ease;
    }

    .back-btn:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: translateY(-2px);
    }

    .header-title {
      font-family: 'Playfair Display', serif;
      font-size: 2.5rem;
      font-weight: 600;
      color: #fff;
      letter-spacing: -0.5px;
    }

    .header-actions {
      display: flex;
      gap: 15px;
    }

    .header-actions .btn {
      padding: 12px 24px;
      border-radius: 30px;
      font-size: 0.95rem;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 8px;
      border: none;
    }

    .btn-mark-all {
      background: transparent;
      border: 2px solid #55efc4;
      color: #55efc4;
    }

    .btn-mark-all:hover {
      background: rgba(85, 239, 196, 0.1);
    }

    .btn-delete-all {
      background: transparent;
      border: 2px solid #ff5959;
      color: #ff5959;
    }

    .btn-delete-all:hover {
      background: rgba(255, 89, 89, 0.1);
    }

    /* Status messages */
    .alert {
      padding: 18px;
      border-radius: 12px;
      margin-bottom: 30px;
      font-size: 1.1rem;
      font-weight: 500;
      text-align: center;
    }

    .alert-error {
      background: rgba(255, 89, 89, 0.15);
      color: #ff5959;
      border: 1px solid rgba(255, 89, 89, 0.3);
    }

    .alert-success {
      background: rgba(85, 239, 196, 0.15);
      color: #55efc4;
      border: 1px solid rgba(85, 239, 196, 0.3);
    }

    /* Empty state */
    .empty-state {
      text-align: center;
      padding: 80px 40px;
      background: rgba(25, 25, 25, 0.5);
      border: 1px solid #2a2a2a;
      border-radius: 20px;
      margin: 40px 0;
    }

    .empty-icon {
      font-size: 5rem;
      margin-bottom: 25px;
      opacity: 0.3;
      color: #444;
    }

    .empty-title {
      font-size: 2rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 15px;
    }

    .empty-desc {
      font-size: 1.1rem;
      color: #999;
      max-width: 600px;
      margin: 0 auto 30px;
      line-height: 1.6;
    }

    /* Notifications counter */
    .notifications-counter {
      background: linear-gradient(135deg, #55efc4, #74b9ff);
      color: #000;
      font-size: 1rem;
      font-weight: 700;
      padding: 4px 16px;
      border-radius: 30px;
      margin-left: 15px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    /* Notifications list */
    .notifications-list {
      display: grid;
      gap: 20px;
    }

    .notification-card {
      background: rgba(25, 25, 25, 0.8);
      backdrop-filter: blur(10px);
      border: 1px solid #2a2a2a;
      border-radius: 20px;
      padding: 30px;
      box-shadow: 0 5px 25px rgba(0, 0, 0, 0.2);
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .notification-card:hover {
      border-color: #444;
      transform: translateY(-3px);
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
    }

    .notification-card.unread {
      border-left: 4px solid #55efc4;
      background: rgba(85, 239, 196, 0.05);
    }

    .notification-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 20px;
      flex-wrap: wrap;
      gap: 20px;
    }

    .notification-type {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .type-delivery {
      background: rgba(85, 239, 196, 0.15);
      color: #55efc4;
      border: 1px solid rgba(85, 239, 196, 0.3);
    }

    .type-report {
      background: rgba(255, 189, 89, 0.15);
      color: #ffbd59;
      border: 1px solid rgba(255, 189, 89, 0.3);
    }

    .type-system {
      background: rgba(108, 92, 231, 0.15);
      color: #6c5ce7;
      border: 1px solid rgba(108, 92, 231, 0.3);
    }

    .type-moderation {
      background: rgba(255, 89, 89, 0.15);
      color: #ff5959;
      border: 1px solid rgba(255, 89, 89, 0.3);
    }

    .notification-time {
      font-size: 0.9rem;
      color: #888;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .notification-time i {
      font-size: 1rem;
    }

    .notification-content {
      font-size: 1.1rem;
      color: #fff;
      line-height: 1.7;
      margin-bottom: 25px;
      word-break: break-word;
    }

    .notification-actions {
      display: flex;
      gap: 15px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }

    .notification-btn {
      padding: 8px 20px;
      border-radius: 30px;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 8px;
      border: none;
    }

    .btn-mark-read {
      background: transparent;
      border: 1px solid #55efc4;
      color: #55efc4;
    }

    .btn-mark-read:hover {
      background: rgba(85, 239, 196, 0.1);
    }

    .btn-delete {
      background: transparent;
      border: 1px solid #ff5959;
      color: #ff5959;
    }

    .btn-delete:hover {
      background: rgba(255, 89, 89, 0.1);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .container {
        padding: 20px;
      }
      
      .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
      }
      
      .header-left {
        gap: 15px;
      }
      
      .back-btn {
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
      }
      
      .header-title {
        font-size: 2rem;
      }
      
      .header-actions {
        width: 100%;
        justify-content: space-between;
      }
      
      .notification-header {
        flex-direction: column;
        align-items: flex-start;
      }
      
      .notification-actions {
        justify-content: flex-start;
      }
      
      .notification-btn {
        flex: 1;
        text-align: center;
      }
    }

    @media (max-width: 480px) {
      .header-title {
        font-size: 1.8rem;
      }
      
      .notification-card {
        padding: 25px;
      }
      
      .notification-content {
        font-size: 1rem;
      }
      
      .btn-mark-read, .btn-delete {
        padding: 8px 16px;
        font-size: 0.85rem;
      }
    }

    /* Confirmation modal */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: all 0.3s ease;
    }

    .modal-overlay.active {
      opacity: 1;
      visibility: visible;
    }

    .modal {
      background: #1a1a1a;
      border: 1px solid #333;
      border-radius: 20px;
      padding: 40px;
      width: 90%;
      max-width: 500px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
      transform: translateY(-50px);
      transition: all 0.3s ease;
    }

    .modal-overlay.active .modal {
      transform: translateY(0);
    }

    .modal-header {
      text-align: center;
      margin-bottom: 30px;
    }

    .modal-title {
      font-size: 1.8rem;
      font-weight: 600;
      color: #ff5959;
      margin-bottom: 15px;
    }

    .modal-message {
      font-size: 1.1rem;
      color: #ccc;
      line-height: 1.6;
      margin-bottom: 30px;
    }

    .modal-actions {
      display: flex;
      gap: 20px;
      justify-content: center;
    }

    .modal-btn {
      padding: 14px 32px;
      border-radius: 30px;
      font-size: 1.1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .btn-cancel {
      background: transparent;
      border: 2px solid #6c5ce7;
      color: #6c5ce7;
    }

    .btn-cancel:hover {
      background: rgba(108, 92, 231, 0.1);
      color: #a29bfe;
    }

    .btn-confirm {
      background: #ff5959;
      color: white;
      border: none;
    }

    .btn-confirm:hover {
      background: #ff7675;
      transform: translateY(-2px);
    }

    .close-modal {
      position: absolute;
      top: 20px;
      right: 20px;
      width: 36px;
      height: 36px;
      border: none;
      background: transparent;
      color: #888;
      font-size: 1.5rem;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .close-modal:hover {
      color: #fff;
    }
  </style>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

  <div class="container">
    <div class="header">
      <div class="header-left">
        <a href="dashboard.php" class="back-btn">
          <i class="fas fa-arrow-left"></i>
        </a>
        <h1 class="header-title">Notifications</h1>
        <?php if ($unread_count > 0): ?>
          <div class="notifications-counter">
            <i class="fas fa-bell"></i>
            <?= $unread_count ?> Unread
          </div>
        <?php endif; ?>
      </div>
      <div class="header-actions">
        <?php if ($unread_count > 0): ?>
        <a href="notifications.php?mark_all_read" class="btn btn-mark-all">
          <i class="fas fa-check-double"></i> Mark All Read
        </a>
        <?php endif; ?>
        <?php if (!empty($notifications)): ?>
        <a href="notifications.php?delete_all" class="btn btn-delete-all" onclick="return confirm('Are you sure you want to delete all notifications?')">
          <i class="fas fa-trash"></i> Delete All
        </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>

    <?php if (empty($notifications)): ?>
      <div class="empty-state">
        <i class="fas fa-bell-slash empty-icon"></i>
        <h2 class="empty-title">No Notifications</h2>
        <p class="empty-desc">You don't have any notifications yet. System alerts, delivery confirmations, and moderation updates will appear here.</p>
        <a href="compose.php" class="empty-cta" style="display: inline-block; padding: 16px 40px; background: linear-gradient(135deg, #6c5ce7, #a29bfe); color: white; border-radius: 30px; font-size: 1.1rem; font-weight: 600; text-decoration: none; transition: all 0.3s ease; display: flex; align-items: center; gap: 10px;">
          <i class="fas fa-pen"></i> Send Your First Capsule
        </a>
      </div>
    <?php else: ?>
      <div class="notifications-list">
        <?php foreach ($notifications as $notification): ?>
          <?php
          // Determine notification type for styling
          $type_class = 'type-system';
          switch ($notification['type']) {
              case 'delivery':
                  $type_class = 'type-delivery';
                  break;
              case 'report_update':
                  $type_class = 'type-report';
                  break;
              case 'moderation_action':
                  $type_class = 'type-moderation';
                  break;
              default:
                  $type_class = 'type-system';
          }
          
          // Format time
          $created_time = strtotime($notification['created_at']);
          $now = time();
          $diff = $now - $created_time;
          
          if ($diff < 60) {
              $time_ago = "Just now";
          } elseif ($diff < 3600) {
              $minutes = floor($diff / 60);
              $time_ago = "{$minutes} minute" . ($minutes > 1 ? "s" : "") . " ago";
          } elseif ($diff < 86400) {
              $hours = floor($diff / 3600);
              $time_ago = "{$hours} hour" . ($hours > 1 ? "s" : "") . " ago";
          } else {
              $days = floor($diff / 86400);
              $time_ago = "{$days} day" . ($days > 1 ? "s" : "") . " ago";
          }
          ?>
          
          <div class="notification-card <?= $notification['is_read'] ? '' : 'unread' ?>">
            <div class="notification-header">
              <span class="notification-type <?= $type_class ?>">
                <?= ucfirst(str_replace('_', ' ', $notification['type'])) ?>
              </span>
              <div class="notification-time">
                <i class="far fa-clock"></i>
                <span><?= $time_ago ?></span>
              </div>
            </div>
            
            <div class="notification-content">
              <?= nl2br(htmlspecialchars($notification['message'])) ?>
            </div>
            
            <div class="notification-actions">
              <?php if (!$notification['is_read']): ?>
              <a href="notifications.php?mark_read=<?= $notification['id'] ?>" class="notification-btn btn-mark-read">
                <i class="fas fa-check"></i> Mark Read
              </a>
              <?php endif; ?>
              <a href="notifications.php?delete=<?= $notification['id'] ?>" class="notification-btn btn-delete" onclick="return confirm('Are you sure you want to delete this notification?')">
                <i class="fas fa-trash"></i> Delete
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <script>
    // Add smooth animations to notifications
    document.addEventListener('DOMContentLoaded', function() {
        const notifications = document.querySelectorAll('.notification-card');
        notifications.forEach((notification, index) => {
            notification.style.opacity = '0';
            notification.style.transform = 'translateY(30px)';
            notification.style.transition = 'all 0.5s ease';
            
            setTimeout(() => {
                notification.style.opacity = '1';
                notification.style.transform = 'translateY(0)';
            }, index * 100);
        });
    });
  </script>

</body>
</html>