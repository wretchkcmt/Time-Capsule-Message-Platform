<?php
session_start();

// Redirect if not logged in or not moderator/admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'moderator' && $_SESSION['user_role'] !== 'admin')) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

$error = '';
$success = '';

try {
    $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    die("Database error. Please try again later.");
}

// Handle moderation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_capsule']) && !empty($_POST['capsule_id'])) {
        $capsule_id = $_POST['capsule_id'];
        
        try {
            // Update report status
            $stmt = $pdo->prepare("UPDATE reports SET status = 'reviewed', moderator_id = ?, resolved_at = NOW() WHERE capsule_id = ? AND status = 'pending'");
            $stmt->execute([$user_id, $capsule_id]);
            
            // Log moderation action
            $stmt = $pdo->prepare("INSERT INTO moderation_logs (moderator_id, capsule_id, action, reason) VALUES (?, ?, 'approved', 'Content reviewed and approved')");
            $stmt->execute([$user_id, $capsule_id]);
            
            $success = "Capsule approved and report resolved.";
        } catch (PDOException $e) {
            $error = "System error. Please try again later.";
            error_log("Approve Capsule Error: " . $e->getMessage());
        }
    }
    
    if (isset($_POST['remove_capsule']) && !empty($_POST['capsule_id'])) {
        $capsule_id = $_POST['capsule_id'];
        $reason = $_POST['reason'] ?? 'Content violated community guidelines';
        
        try {
            // Update capsule status
            $stmt = $pdo->prepare("UPDATE capsules SET status = 'removed' WHERE id = ?");
            $stmt->execute([$capsule_id]);
            
            // Update report status
            $stmt = $pdo->prepare("UPDATE reports SET status = 'reviewed', moderator_id = ?, resolved_at = NOW() WHERE capsule_id = ? AND status = 'pending'");
            $stmt->execute([$user_id, $capsule_id]);
            
            // Log moderation action
            $stmt = $pdo->prepare("INSERT INTO moderation_logs (moderator_id, capsule_id, action, reason) VALUES (?, ?, 'removed', ?)");
            $stmt->execute([$user_id, $capsule_id, $reason]);
            
            $success = "Capsule removed and report resolved.";
        } catch (PDOException $e) {
            $error = "System error. Please try again later.";
            error_log("Remove Capsule Error: " . $e->getMessage());
        }
    }
    
    if (isset($_POST['suspend_user']) && !empty($_POST['user_id'])) {
        $target_user_id = $_POST['user_id'];
        $reason = $_POST['reason'] ?? 'Violated community guidelines';
        
        try {
            // Add moderation log for user suspension
            $stmt = $pdo->prepare("INSERT INTO moderation_logs (moderator_id, target_user_id, action, reason) VALUES (?, ?, 'suspended', ?)");
            $stmt->execute([$user_id, $target_user_id, $reason]);
            
            // You could also update user status in users table if you add an 'is_suspended' column
            // For now, we'll just log the action
            
            $success = "User suspension logged. (Note: Implement user suspension in users table for full functionality)";
        } catch (PDOException $e) {
            $error = "System error. Please try again later.";
            error_log("Suspend User Error: " . $e->getMessage());
        }
    }
}

// Get pending reports
try {
    $stmt = $pdo->prepare("
        SELECT r.*, 
               c.title, 
               c.content, 
               c.delivery_time, 
               c.status as capsule_status,
               u.name as reporter_name,
               u2.name as sender_name,
               u3.name as recipient_name,
               (SELECT COUNT(*) FROM attachments WHERE capsule_id = c.id) as attachment_count
        FROM reports r
        JOIN capsules c ON r.capsule_id = c.id
        JOIN users u ON r.reporter_id = u.id
        JOIN users u2 ON c.user_id = u2.id
        LEFT JOIN users u3 ON c.recipient_id = u3.id
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $pending_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent moderation logs
    $stmt = $pdo->prepare("
        SELECT ml.*, 
               u.name as moderator_name,
               u2.name as target_user_name,
               c.title as capsule_title
        FROM moderation_logs ml
        JOIN users u ON ml.moderator_id = u.id
        LEFT JOIN users u2 ON ml.target_user_id = u2.id
        LEFT JOIN capsules c ON ml.capsule_id = c.id
        ORDER BY ml.timestamp DESC
        LIMIT 20
    ");
    $moderation_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database error. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Moderation Panel • Time Capsule Messenger</title>
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
      max-width: 1400px;
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

    .mod-badge {
      background: linear-gradient(135deg, #ff5959, #ff7675);
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

    /* Stats cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 25px;
      margin-bottom: 40px;
    }

    .stat-card {
      background: rgba(255, 89, 89, 0.05);
      border: 1px solid rgba(255, 89, 89, 0.2);
      border-radius: 20px;
      padding: 30px;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .stat-card:hover {
      border-color: rgba(255, 89, 89, 0.4);
      transform: translateY(-3px);
    }

    .stat-label {
      font-size: 0.9rem;
      color: #ff5959;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .stat-value {
      font-size: 2.5rem;
      font-weight: 600;
      color: #ff5959;
      margin-bottom: 8px;
    }

    .stat-desc {
      font-size: 1rem;
      color: #ccc;
      font-weight: 500;
    }

    /* Pending reports section */
    .section {
      background: rgba(25, 25, 25, 0.8);
      backdrop-filter: blur(10px);
      border: 1px solid #2a2a2a;
      border-radius: 20px;
      padding: 40px;
      box-shadow: 0 5px 25px rgba(0, 0, 0, 0.2);
      margin-bottom: 40px;
    }

    .section-header {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 30px;
      padding-bottom: 20px;
      border-bottom: 1px solid #333;
    }

    .section-icon {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: rgba(255, 89, 89, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ff5959;
      font-size: 1.2rem;
    }

    .section-title {
      font-size: 1.8rem;
      font-weight: 600;
      color: #fff;
    }

    /* Reports list */
    .reports-list {
      display: grid;
      gap: 30px;
    }

    .report-card {
      background: rgba(40, 20, 20, 0.6);
      border: 1px solid #442222;
      border-radius: 20px;
      padding: 35px;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .report-card:hover {
      border-color: #ff5959;
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(255, 89, 89, 0.2);
    }

    .report-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 25px;
      flex-wrap: wrap;
      gap: 20px;
    }

    .report-info {
      flex: 1;
    }

    .report-id {
      font-size: 0.9rem;
      color: #888;
      margin-bottom: 10px;
    }

    .report-title {
      font-size: 1.6rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 15px;
      line-height: 1.3;
    }

    .report-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
      margin-bottom: 25px;
      padding: 15px 0;
      border-top: 1px solid #442222;
      border-bottom: 1px solid #442222;
    }

    .meta-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.95rem;
      color: #aaa;
    }

    .meta-item i {
      color: #ff5959;
      font-size: 1.1rem;
    }

    .reporter-info, .sender-info, .recipient-info {
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(255, 89, 89, 0.05);
      border: 1px solid rgba(255, 89, 89, 0.2);
      border-radius: 12px;
      padding: 12px 16px;
      width: fit-content;
    }

    .user-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: linear-gradient(135deg, #ff5959, #ff7675);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 1rem;
      flex-shrink: 0;
      color: white;
    }

    .user-details h4 {
      font-size: 1.1rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 3px;
    }

    .user-details p {
      font-size: 0.9rem;
      color: #ff9f9f;
    }

    .report-reason {
      background: rgba(255, 89, 89, 0.1);
      border: 1px solid rgba(255, 89, 89, 0.2);
      border-radius: 16px;
      padding: 20px;
      margin-bottom: 30px;
    }

    .reason-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: #ff5959;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .reason-content {
      font-size: 1rem;
      color: #fff;
      line-height: 1.6;
      padding-left: 10px;
      border-left: 3px solid #ff5959;
    }

    .report-content {
      background: rgba(30, 30, 30, 0.7);
      border: 1px solid #444;
      border-radius: 16px;
      padding: 25px;
      margin-bottom: 30px;
    }

    .content-title {
      font-size: 1.2rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 15px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .content-text {
      font-size: 1.05rem;
      color: #ccc;
      line-height: 1.7;
      white-space: pre-wrap;
      word-break: break-word;
    }

    .attachments-info {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.95rem;
      color: #aaa;
      margin-bottom: 20px;
    }

    .attachments-info i {
      color: #55efc4;
    }

    /* Moderation actions */
    .moderation-actions {
      display: flex;
      gap: 20px;
      justify-content: flex-end;
      flex-wrap: wrap;
      margin-top: 30px;
      padding-top: 20px;
      border-top: 1px solid #442222;
    }

    .action-btn {
      padding: 14px 28px;
      border-radius: 30px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 10px;
      border: none;
    }

    .btn-approve {
      background: transparent;
      border: 2px solid #55efc4;
      color: #55efc4;
    }

    .btn-approve:hover {
      background: rgba(85, 239, 196, 0.1);
    }

    .btn-remove {
      background: transparent;
      border: 2px solid #ff5959;
      color: #ff5959;
    }

    .btn-remove:hover {
      background: rgba(255, 89, 89, 0.1);
    }

    .btn-suspend {
      background: transparent;
      border: 2px solid #ff9f43;
      color: #ff9f43;
    }

    .btn-suspend:hover {
      background: rgba(255, 159, 67, 0.1);
    }

    /* Moderation logs */
    .logs-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 30px;
    }

    .logs-table th {
      text-align: left;
      padding: 16px 20px;
      font-size: 1.1rem;
      font-weight: 600;
      color: #fff;
      border-bottom: 2px solid #333;
    }

    .logs-table td {
      padding: 16px 20px;
      font-size: 1rem;
      color: #ccc;
      border-bottom: 1px solid #333;
    }

    .logs-table tr:hover {
      background: rgba(255, 89, 89, 0.05);
    }

    .action-approved { color: #55efc4; }
    .action-removed { color: #ff5959; }
    .action-suspended { color: #ff9f43; }
    .action-flagged { color: #ffcd56; }

    /* Modal for remove/suspend actions */
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
      max-width: 600px;
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

    .modal-subtitle {
      font-size: 1.2rem;
      color: #fff;
      margin-bottom: 25px;
    }

    .modal-form {
      margin-bottom: 30px;
    }

    .modal-label {
      display: block;
      margin-bottom: 12px;
      font-size: 1.1rem;
      font-weight: 500;
      color: #ccc;
    }

    .modal-input {
      width: 100%;
      padding: 16px;
      background: #2a2a2a;
      border: 1px solid #444;
      border-radius: 12px;
      color: #fff;
      font-size: 1rem;
      font-family: 'Montserrat', sans-serif;
      transition: all 0.3s ease;
    }

    .modal-input:focus {
      outline: none;
      border-color: #ff5959;
      box-shadow: 0 0 0 3px rgba(255, 89, 89, 0.2);
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

    .btn-confirm-remove {
      background: #ff5959;
      color: white;
      border: none;
    }

    .btn-confirm-remove:hover {
      background: #ff7675;
      transform: translateY(-2px);
    }

    .btn-confirm-suspend {
      background: #ff9f43;
      color: white;
      border: none;
    }

    .btn-confirm-suspend:hover {
      background: #ffbf69;
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

    /* Responsive Design */
    @media (max-width: 992px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }
    }

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
      
      .report-header {
        flex-direction: column;
        align-items: flex-start;
      }
      
      .moderation-actions {
        justify-content: flex-start;
      }
      
      .action-btn {
        flex: 1;
        text-align: center;
      }
    }

    @media (max-width: 480px) {
      .header-title {
        font-size: 1.8rem;
      }
      
      .section {
        padding: 30px 20px;
      }
      
      .report-card {
        padding: 25px;
      }
      
      .report-title {
        font-size: 1.4rem;
      }
      
      .modal {
        padding: 30px 20px;
      }
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
        <h1 class="header-title">Moderation Panel</h1>
        <div class="mod-badge">
          <i class="fas fa-shield-alt"></i>
          <?= ucfirst($_SESSION['user_role']) ?>
        </div>
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

    <!-- Stats Overview -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">
          <i class="fas fa-flag"></i>
          PENDING REPORTS
        </div>
        <div class="stat-value"><?= count($pending_reports) ?></div>
        <div class="stat-desc">Reports awaiting review</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-label">
          <i class="fas fa-ban"></i>
          USER SUSPENSIONS
        </div>
        <div class="stat-value">
          <?php
          try {
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM moderation_logs WHERE action = 'suspended'");
              $stmt->execute();
              echo $stmt->fetchColumn();
          } catch (PDOException $e) {
              echo "0";
          }
          ?>
        </div>
        <div class="stat-desc">Total user suspensions</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-label">
          <i class="fas fa-trash"></i>
          CONTENT REMOVED
        </div>
        <div class="stat-value">
          <?php
          try {
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM moderation_logs WHERE action = 'removed'");
              $stmt->execute();
              echo $stmt->fetchColumn();
          } catch (PDOException $e) {
              echo "0";
          }
          ?>
        </div>
        <div class="stat-desc">Capsules removed</div>
      </div>
    </div>

    <!-- Pending Reports Section -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon">
          <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h2 class="section-title">Pending Reports</h2>
      </div>
      
      <?php if (empty($pending_reports)): ?>
        <div style="text-align: center; padding: 60px 20px; color: #888;">
          <i class="fas fa-check-circle" style="font-size: 4rem; margin-bottom: 20px; color: #55efc4; opacity: 0.5;"></i>
          <h3 style="font-size: 1.5rem; color: #fff; margin-bottom: 15px;">No Pending Reports</h3>
          <p>All reports have been reviewed. Great job keeping the community safe!</p>
        </div>
      <?php else: ?>
        <div class="reports-list">
          <?php foreach ($pending_reports as $report): ?>
            <div class="report-card">
              <div class="report-header">
                <div class="report-info">
                  <div class="report-id">Report ID: #<?= $report['id'] ?> • <?= date('M j, Y \a\t g:i A', strtotime($report['created_at'])) ?></div>
                  <h2 class="report-title"><?= htmlspecialchars($report['title'] ?: 'Untitled Message') ?></h2>
                  
                  <div class="report-meta">
                    <div class="meta-item">
                      <i class="fas fa-flag"></i>
                      <span>Status: <strong style="color: #ffcd56;">Pending Review</strong></span>
                    </div>
                    <div class="meta-item">
                      <i class="fas fa-clock"></i>
                      <span>Scheduled: <?= date('M j, Y \a\t g:i A', strtotime($report['delivery_time'])) ?></span>
                    </div>
                    <?php if ($report['attachment_count'] > 0): ?>
                    <div class="meta-item">
                      <i class="fas fa-paperclip"></i>
                      <span><?= $report['attachment_count'] ?> attachment<?= $report['attachment_count'] > 1 ? 's' : '' ?></span>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              
              <!-- Reporter Information -->
              <div class="reporter-info">
                <div class="user-avatar"><?= strtoupper(substr($report['reporter_name'], 0, 1)) ?></div>
                <div class="user-details">
                  <h4><?= htmlspecialchars($report['reporter_name']) ?></h4>
                  <p>Reported this content</p>
                </div>
              </div>
              
              <!-- Sender Information -->
              <div class="sender-info" style="margin: 20px 0;">
                <div class="user-avatar"><?= strtoupper(substr($report['sender_name'], 0, 1)) ?></div>
                <div class="user-details">
                  <h4><?= htmlspecialchars($report['sender_name']) ?></h4>
                  <p>Original sender</p>
                </div>
              </div>
              
              <!-- Recipient Information (if applicable) -->
              <?php if (!empty($report['recipient_name'])): ?>
              <div class="recipient-info" style="margin-bottom: 20px;">
                <div class="user-avatar"><?= strtoupper(substr($report['recipient_name'], 0, 1)) ?></div>
                <div class="user-details">
                  <h4><?= htmlspecialchars($report['recipient_name']) ?></h4>
                  <p>Intended recipient</p>
                </div>
              </div>
              <?php endif; ?>
              
              <!-- Report Reason -->
              <div class="report-reason">
                <h3 class="reason-title">
                  <i class="fas fa-exclamation-circle"></i>
                  Reason for Reporting
                </h3>
                <p class="reason-content"><?= htmlspecialchars($report['reason']) ?></p>
              </div>
              
              <!-- Reported Content -->
              <div class="report-content">
                <h3 class="content-title">
                  <i class="fas fa-envelope"></i>
                  Reported Content
                </h3>
                <div class="content-text"><?= nl2br(htmlspecialchars($report['content'])) ?></div>
              </div>
              
              <!-- Moderation Actions -->
              <div class="moderation-actions">
                <form method="POST" style="display: inline;">
                  <input type="hidden" name="capsule_id" value="<?= $report['id'] ?>">
                  <button type="submit" name="approve_capsule" class="action-btn btn-approve">
                    <i class="fas fa-check"></i> Approve Content
                  </button>
                </form>
                
                <button type="button" class="action-btn btn-remove" onclick="showRemoveModal(<?= $report['id'] ?>)">
                  <i class="fas fa-trash"></i> Remove Content
                </button>
                
                <button type="button" class="action-btn btn-suspend" onclick="showSuspendModal(<?= $report['user_id'] ?>, '<?= addslashes($report['sender_name']) ?>')">
                  <i class="fas fa-user-slash"></i> Suspend User
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Moderation Logs Section -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon">
          <i class="fas fa-clipboard-list"></i>
        </div>
        <h2 class="section-title">Recent Moderation Logs</h2>
      </div>
      
      <div style="overflow-x: auto;">
        <table class="logs-table">
          <thead>
            <tr>
              <th>Date & Time</th>
              <th>Moderator</th>
              <th>Action</th>
              <th>Target</th>
              <th>Reason</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($moderation_logs)): ?>
              <tr>
                <td colspan="5" style="text-align: center; padding: 40px; color: #888;">
                  No moderation logs yet.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($moderation_logs as $log): ?>
                <tr>
                  <td><?= date('M j, Y \a\t g:i A', strtotime($log['timestamp'])) ?></td>
                  <td><?= htmlspecialchars($log['moderator_name']) ?></td>
                  <td>
                    <span class="action-<?= $log['action'] ?>">
                      <?= ucfirst($log['action']) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($log['target_user_name']): ?>
                      User: <?= htmlspecialchars($log['target_user_name']) ?>
                    <?php elseif ($log['capsule_title']): ?>
                      Capsule: "<?= htmlspecialchars($log['capsule_title']) ?>"
                    <?php else: ?>
                      System
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($log['reason']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Remove Content Modal -->
  <div class="modal-overlay" id="removeModal">
    <div class="modal">
      <button class="close-modal" onclick="hideRemoveModal()">&times;</button>
      <div class="modal-header">
        <h3 class="modal-title">Remove Content</h3>
        <p class="modal-subtitle">This will permanently remove the content and resolve the report.</p>
      </div>
      <form method="POST" class="modal-form">
        <input type="hidden" name="capsule_id" id="removeCapsuleId">
        <div class="form-group">
          <label class="modal-label">Reason for Removal (optional)</label>
          <textarea name="reason" class="modal-input" rows="4" placeholder="Explain why this content is being removed...">Content violated community guidelines</textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="modal-btn btn-cancel" onclick="hideRemoveModal()">Cancel</button>
          <button type="submit" name="remove_capsule" class="modal-btn btn-confirm-remove">
            <i class="fas fa-trash"></i> Remove Content
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Suspend User Modal -->
  <div class="modal-overlay" id="suspendModal">
    <div class="modal">
      <button class="close-modal" onclick="hideSuspendModal()">&times;</button>
      <div class="modal-header">
        <h3 class="modal-title">Suspend User</h3>
        <p class="modal-subtitle">This will log a suspension action against the user.</p>
      </div>
      <form method="POST" class="modal-form">
        <input type="hidden" name="user_id" id="suspendUserId">
        <div class="form-group">
          <label class="modal-label">User to Suspend</label>
          <div style="background: #2a2a2a; border: 1px solid #444; border-radius: 12px; padding: 16px; color: #fff; margin-bottom: 20px;">
            <strong id="suspendUserName">User Name</strong>
          </div>
        </div>
        <div class="form-group">
          <label class="modal-label">Reason for Suspension (required)</label>
          <textarea name="reason" class="modal-input" rows="4" placeholder="Explain why this user is being suspended..." required></textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="modal-btn btn-cancel" onclick="hideSuspendModal()">Cancel</button>
          <button type="submit" name="suspend_user" class="modal-btn btn-confirm-suspend">
            <i class="fas fa-user-slash"></i> Suspend User
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    let currentRemoveId = null;
    let currentSuspendId = null;
    let currentSuspendName = null;
    
    function showRemoveModal(capsuleId) {
        currentRemoveId = capsuleId;
        document.getElementById('removeCapsuleId').value = capsuleId;
        document.getElementById('removeModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function hideRemoveModal() {
        document.getElementById('removeModal').classList.remove('active');
        document.body.style.overflow = 'auto';
        currentRemoveId = null;
    }
    
    function showSuspendModal(userId, userName) {
        currentSuspendId = userId;
        currentSuspendName = userName;
        document.getElementById('suspendUserId').value = userId;
        document.getElementById('suspendUserName').textContent = userName;
        document.getElementById('suspendModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function hideSuspendModal() {
        document.getElementById('suspendModal').classList.remove('active');
        document.body.style.overflow = 'auto';
        currentSuspendId = null;
        currentSuspendName = null;
    }
    
    // Close modals when clicking outside
    document.getElementById('removeModal').addEventListener('click', function(e) {
        if (e.target === this) {
            hideRemoveModal();
        }
    });
    
    document.getElementById('suspendModal').addEventListener('click', function(e) {
        if (e.target === this) {
            hideSuspendModal();
        }
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (document.getElementById('removeModal').classList.contains('active')) {
                hideRemoveModal();
            } else if (document.getElementById('suspendModal').classList.contains('active')) {
                hideSuspendModal();
            }
        }
    });
    
    // Add smooth animations to reports
    document.addEventListener('DOMContentLoaded', function() {
        const reports = document.querySelectorAll('.report-card');
        reports.forEach((report, index) => {
            report.style.opacity = '0';
            report.style.transform = 'translateY(30px)';
            report.style.transition = 'all 0.5s ease';
            
            setTimeout(() => {
                report.style.opacity = '1';
                report.style.transform = 'translateY(0)';
            }, index * 150);
        });
    });
  </script>

</body>
</html>