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

try {
    $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get user settings
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        // Create default settings if none exist
        $stmt = $pdo->prepare("
            INSERT INTO user_settings (user_id, theme_mode, notifications_enabled, timezone)
            VALUES (?, 'dark', TRUE, 'UTC')
        ");
        $stmt->execute([$user_id]);
        $settings = ['theme_mode' => 'dark', 'notifications_enabled' => 1, 'timezone' => 'UTC'];
    }
    
} catch (PDOException $e) {
    die("Database error. Please try again later.");
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($name)) {
            $error = "Name is required.";
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            try {
                // Check if email is already taken by another user
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $user_id]);
                if ($stmt->fetch()) {
                    $error = "This email is already in use by another account.";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $user_id]);
                    
                    // Update session data
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    
                    $success = "Profile updated successfully!";
                    $user['name'] = $name;
                    $user['email'] = $email;
                }
            } catch (PDOException $e) {
                $error = "System error. Please try again later.";
                error_log("Update Profile Error: " . $e->getMessage());
            }
        }
    }
    
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password)) {
            $error = "Current password is required.";
        } elseif (empty($new_password)) {
            $error = "New password is required.";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            try {
                // Verify current password
                if (!password_verify($current_password, $user['password_hash'])) {
                    $error = "Current password is incorrect.";
                } else {
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$new_password_hash, $user_id]);
                    
                    $success = "Password changed successfully!";
                }
            } catch (PDOException $e) {
                $error = "System error. Please try again later.";
                error_log("Change Password Error: " . $e->getMessage());
            }
        }
    }
    
    if (isset($_POST['update_settings'])) {
        $theme_mode = $_POST['theme_mode'] ?? 'dark';
        $notifications_enabled = isset($_POST['notifications_enabled']) ? 1 : 0;
        $timezone = $_POST['timezone'] ?? 'UTC';
        
        try {
            $stmt = $pdo->prepare("
                UPDATE user_settings 
                SET theme_mode = ?, notifications_enabled = ?, timezone = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$theme_mode, $notifications_enabled, $timezone, $user_id]);
            
            $success = "Settings updated successfully!";
            $settings['theme_mode'] = $theme_mode;
            $settings['notifications_enabled'] = $notifications_enabled;
            $settings['timezone'] = $timezone;
            
            // Update session if theme changed
            $_SESSION['theme_mode'] = $theme_mode;
            
        } catch (PDOException $e) {
            $error = "System error. Please try again later.";
            error_log("Update Settings Error: " . $e->getMessage());
        }
    }
    
    if (isset($_POST['delete_account'])) {
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($confirm_password)) {
            $error = "Please enter your password to confirm account deletion.";
        } else {
            try {
                // Verify password
                if (!password_verify($confirm_password, $user['password_hash'])) {
                    $error = "Password is incorrect.";
                } else {
                    // Delete all user data
                    // Delete attachments first
                    $stmt = $pdo->prepare("SELECT file_path FROM attachments WHERE capsule_id IN (SELECT id FROM capsules WHERE user_id = ? OR recipient_id = ?)");
                    $stmt->execute([$user_id, $user_id]);
                    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($attachments as $attachment) {
                        if (file_exists($attachment['file_path'])) {
                            unlink($attachment['file_path']);
                        }
                    }
                    
                    // Delete attachments from database
                    $stmt = $pdo->prepare("DELETE FROM attachments WHERE capsule_id IN (SELECT id FROM capsules WHERE user_id = ? OR recipient_id = ?)");
                    $stmt->execute([$user_id, $user_id]);
                    
                    // Delete capsules
                    $stmt = $pdo->prepare("DELETE FROM capsules WHERE user_id = ? OR recipient_id = ?");
                    $stmt->execute([$user_id, $user_id]);
                    
                    // Delete other related data
                    $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $pdo->prepare("DELETE FROM reports WHERE reporter_id = ? OR capsule_id IN (SELECT id FROM capsules WHERE user_id = ?)");
                    $stmt->execute([$user_id, $user_id]);
                    
                    $stmt = $pdo->prepare("DELETE FROM login_history WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $pdo->prepare("DELETE FROM email_verification WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $pdo->prepare("DELETE FROM user_settings WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    // Finally, delete user
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    
                    // Destroy session and redirect
                    session_destroy();
                    header("Location: login.php?deleted=success");
                    exit();
                }
            } catch (PDOException $e) {
                $error = "System error. Please try again later.";
                error_log("Delete Account Error: " . $e->getMessage());
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
  <title>Profile Settings • Time Capsule Messenger</title>
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

    /* Profile section */
    .profile-section {
      display: flex;
      gap: 40px;
      margin-bottom: 50px;
    }

    .profile-card {
      background: rgba(25, 25, 25, 0.8);
      backdrop-filter: blur(10px);
      border: 1px solid #2a2a2a;
      border-radius: 20px;
      padding: 40px;
      box-shadow: 0 5px 25px rgba(0, 0, 0, 0.2);
      flex: 1;
    }

    .profile-header {
      text-align: center;
      margin-bottom: 40px;
    }

    .profile-avatar {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      background: linear-gradient(135deg, #6c5ce7, #a29bfe);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 3rem;
      margin: 0 auto 25px;
      position: relative;
    }

    .avatar-initials {
      color: white;
    }

    .profile-name {
      font-size: 2rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 10px;
    }

    .profile-email {
      font-size: 1.1rem;
      color: #aaa;
      margin-bottom: 20px;
    }

    .profile-role {
      display: inline-block;
      padding: 6px 16px;
      background: rgba(108, 92, 231, 0.15);
      color: #6c5ce7;
      border: 1px solid rgba(108, 92, 231, 0.3);
      border-radius: 30px;
      font-size: 0.9rem;
      font-weight: 600;
      text-transform: capitalize;
    }

    .profile-stats {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
      margin-top: 30px;
    }

    .stat-item {
      text-align: center;
      padding: 20px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid #333;
      border-radius: 16px;
    }

    .stat-value {
      font-size: 2rem;
      font-weight: 600;
      color: #55efc4;
      margin-bottom: 8px;
    }

    .stat-label {
      font-size: 0.9rem;
      color: #888;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    /* Settings sections */
    .settings-section {
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
      background: rgba(108, 92, 231, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #6c5ce7;
      font-size: 1.2rem;
    }

    .section-title {
      font-size: 1.8rem;
      font-weight: 600;
      color: #fff;
    }

    .form-group {
      margin-bottom: 30px;
    }

    .form-label {
      display: block;
      margin-bottom: 12px;
      font-size: 1.1rem;
      font-weight: 500;
      color: #ccc;
    }

    .form-control {
      width: 100%;
      padding: 16px;
      background: #1a1a1a;
      border: 1px solid #333;
      border-radius: 12px;
      color: #fff;
      font-size: 1rem;
      font-family: 'Montserrat', sans-serif;
      transition: all 0.3s ease;
    }

    .form-control:focus {
      outline: none;
      border-color: #6c5ce7;
      box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.2);
    }

    .form-textarea {
      min-height: 120px;
      resize: vertical;
    }

    .form-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 25px;
    }

    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 60px;
      height: 30px;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .toggle-slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: #333;
      transition: .4s;
      border-radius: 34px;
    }

    .toggle-slider:before {
      position: absolute;
      content: "";
      height: 22px;
      width: 22px;
      left: 4px;
      bottom: 4px;
      background-color: white;
      transition: .4s;
      border-radius: 50%;
    }

    input:checked + .toggle-slider {
      background-color: #6c5ce7;
    }

    input:checked + .toggle-slider:before {
      transform: translateX(30px);
    }

    .toggle-label {
      display: flex;
      align-items: center;
      gap: 15px;
      font-size: 1.1rem;
      color: #fff;
    }

    /* Action buttons */
    .action-buttons {
      display: flex;
      gap: 20px;
      justify-content: flex-end;
      margin-top: 30px;
    }

    .btn {
      padding: 16px 36px;
      border-radius: 30px;
      font-size: 1.1rem;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 10px;
      border: none;
    }

    .btn-primary {
      background: linear-gradient(135deg, #6c5ce7, #a29bfe);
      color: white;
    }

    .btn-outline {
      background: transparent;
      border: 2px solid #6c5ce7;
      color: #6c5ce7;
    }

    .btn-danger {
      background: transparent;
      border: 2px solid #ff5959;
      color: #ff5959;
    }

    .btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, #5649c9, #8e80f5);
    }

    .btn-outline:hover {
      background: rgba(108, 92, 231, 0.1);
      color: #a29bfe;
    }

    .btn-danger:hover {
      background: rgba(255, 89, 89, 0.1);
      color: #ff7675;
    }

    /* Delete account section */
    .delete-section {
      background: rgba(255, 89, 89, 0.05);
      border: 1px dashed rgba(255, 89, 89, 0.3);
      border-radius: 20px;
      padding: 40px;
      margin-top: 40px;
    }

    .delete-warning {
      color: #ff5959;
      font-size: 1.2rem;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .delete-warning i {
      font-size: 1.4rem;
    }

    .delete-desc {
      color: #ccc;
      margin-bottom: 30px;
      line-height: 1.6;
    }

    /* Responsive Design */
    @media (max-width: 992px) {
      .profile-section {
        flex-direction: column;
      }
      
      .profile-card {
        width: 100%;
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
      
      .profile-avatar {
        width: 80px;
        height: 80px;
        font-size: 2rem;
        margin-bottom: 20px;
      }
      
      .profile-name {
        font-size: 1.5rem;
      }
      
      .profile-stats {
        grid-template-columns: 1fr;
      }
      
      .form-row {
        grid-template-columns: 1fr;
      }
      
      .action-buttons {
        flex-direction: column;
        gap: 15px;
      }
      
      .btn {
        width: 100%;
        justify-content: center;
      }
    }

    @media (max-width: 480px) {
      .header-title {
        font-size: 1.8rem;
      }
      
      .section-title {
        font-size: 1.5rem;
      }
      
      .settings-section, .profile-card, .delete-section {
        padding: 30px 20px;
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
        <h1 class="header-title">Profile Settings</h1>
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

    <div class="profile-section">
      <div class="profile-card">
        <div class="profile-header">
          <div class="profile-avatar">
            <span class="avatar-initials"><?= strtoupper(substr($user['name'], 0, 1)) ?></span>
          </div>
          <h2 class="profile-name"><?= htmlspecialchars($user['name']) ?></h2>
          <p class="profile-email"><?= htmlspecialchars($user['email']) ?></p>
          <span class="profile-role"><?= htmlspecialchars($user['role']) ?></span>
        </div>

        <?php
        // Get user stats
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as sent_count FROM capsules WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $sent_count = $stmt->fetch(PDO::FETCH_ASSOC)['sent_count'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as received_count FROM capsules WHERE recipient_id = ?");
            $stmt->execute([$user_id]);
            $received_count = $stmt->fetch(PDO::FETCH_ASSOC)['received_count'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as notification_count FROM notifications WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $notification_count = $stmt->fetch(PDO::FETCH_ASSOC)['notification_count'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as report_count FROM reports WHERE reporter_id = ?");
            $stmt->execute([$user_id]);
            $report_count = $stmt->fetch(PDO::FETCH_ASSOC)['report_count'];
        } catch (PDOException $e) {
            $sent_count = 0;
            $received_count = 0;
            $notification_count = 0;
            $report_count = 0;
        }
        ?>

        <div class="profile-stats">
          <div class="stat-item">
            <div class="stat-value"><?= $sent_count ?></div>
            <div class="stat-label">Capsules Sent</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $received_count ?></div>
            <div class="stat-label">Capsules Received</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $notification_count ?></div>
            <div class="stat-label">Notifications</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $report_count ?></div>
            <div class="stat-label">Reports Made</div>
          </div>
        </div>
      </div>

      <div style="flex: 2;">
        <!-- Profile Information Section -->
        <div class="settings-section">
          <div class="section-header">
            <div class="section-icon">
              <i class="fas fa-user"></i>
            </div>
            <h2 class="section-title">Profile Information</h2>
          </div>
          
          <form method="POST">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" 
                       value="<?= htmlspecialchars($user['name']) ?>" required>
              </div>
              
              <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" 
                       value="<?= htmlspecialchars($user['email']) ?>" required>
              </div>
            </div>
            
            <div class="action-buttons">
              <button type="submit" name="update_profile" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Changes
              </button>
            </div>
          </form>
        </div>

        <!-- Change Password Section -->
        <div class="settings-section">
          <div class="section-header">
            <div class="section-icon">
              <i class="fas fa-lock"></i>
            </div>
            <h2 class="section-title">Change Password</h2>
          </div>
          
          <form method="POST">
            <div class="form-group">
              <label class="form-label">Current Password</label>
              <input type="password" name="current_password" class="form-control" 
                     placeholder="Enter your current password" required>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-control" 
                       placeholder="Enter new password (min 6 characters)" required>
              </div>
              
              <div class="form-group">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" 
                       placeholder="Confirm new password" required>
              </div>
            </div>
            
            <div class="action-buttons">
              <button type="submit" name="change_password" class="btn btn-primary">
                <i class="fas fa-key"></i> Change Password
              </button>
            </div>
          </form>
        </div>

        <!-- Preferences Section -->
        <div class="settings-section">
          <div class="section-header">
            <div class="section-icon">
              <i class="fas fa-cog"></i>
            </div>
            <h2 class="section-title">Preferences</h2>
          </div>
          
          <form method="POST">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Theme Mode</label>
                <select name="theme_mode" class="form-control">
                  <option value="dark" <?= $settings['theme_mode'] === 'dark' ? 'selected' : '' ?>>Dark Mode</option>
                  <option value="light" <?= $settings['theme_mode'] === 'light' ? 'selected' : '' ?>>Light Mode</option>
                </select>
              </div>
              
              <div class="form-group">
                <label class="form-label">Timezone</label>
                <select name="timezone" class="form-control">
                  <option value="UTC" <?= $settings['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC (Coordinated Universal Time)</option>
                  <option value="America/New_York" <?= $settings['timezone'] === 'America/New_York' ? 'selected' : '' ?>>Eastern Time (US & Canada)</option>
                  <option value="America/Chicago" <?= $settings['timezone'] === 'America/Chicago' ? 'selected' : '' ?>>Central Time (US & Canada)</option>
                  <option value="America/Denver" <?= $settings['timezone'] === 'America/Denver' ? 'selected' : '' ?>>Mountain Time (US & Canada)</option>
                  <option value="America/Los_Angeles" <?= $settings['timezone'] === 'America/Los_Angeles' ? 'selected' : '' ?>>Pacific Time (US & Canada)</option>
                  <option value="Europe/London" <?= $settings['timezone'] === 'Europe/London' ? 'selected' : '' ?>>London (GMT/BST)</option>
                  <option value="Europe/Paris" <?= $settings['timezone'] === 'Europe/Paris' ? 'selected' : '' ?>>Central European Time</option>
                  <option value="Asia/Tokyo" <?= $settings['timezone'] === 'Asia/Tokyo' ? 'selected' : '' ?>>Tokyo (Japan)</option>
                  <option value="Asia/Shanghai" <?= $settings['timezone'] === 'Asia/Shanghai' ? 'selected' : '' ?>>China Standard Time</option>
                  <option value="Australia/Sydney" <?= $settings['timezone'] === 'Australia/Sydney' ? 'selected' : '' ?>>Sydney (Australia)</option>
                </select>
              </div>
            </div>
            
            <div class="form-group">
              <div class="toggle-label">
                <span>Enable Notifications</span>
                <label class="toggle-switch">
                  <input type="checkbox" name="notifications_enabled" <?= $settings['notifications_enabled'] ? 'checked' : '' ?>>
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <p style="font-size: 0.9rem; color: #888; margin-top: 10px;">
                Receive email and in-app notifications for important updates
              </p>
            </div>
            
            <div class="action-buttons">
              <button type="submit" name="update_settings" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Preferences
              </button>
            </div>
          </form>
        </div>

        <!-- Delete Account Section -->
        <div class="delete-section">
          <div class="delete-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Danger Zone: Delete Account</strong>
          </div>
          <p class="delete-desc">
            Deleting your account is permanent and cannot be undone. This will remove all your time capsules, 
            messages, attachments, and personal data from our system. Please enter your password to confirm 
            this action.
          </p>
          
          <form method="POST">
            <div class="form-group">
              <label class="form-label">Confirm Password</label>
              <input type="password" name="confirm_password" class="form-control" 
                     placeholder="Enter your password to confirm" required>
            </div>
            
            <div class="action-buttons">
              <button type="submit" name="delete_account" class="btn btn-danger" 
                      onclick="return confirm('Are you absolutely sure? This action cannot be undone and will permanently delete all your data.')">
                <i class="fas fa-trash"></i> Delete Account
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Add smooth animations to sections
    document.addEventListener('DOMContentLoaded', function() {
        const sections = document.querySelectorAll('.settings-section, .profile-card, .delete-section');
        sections.forEach((section, index) => {
            section.style.opacity = '0';
            section.style.transform = 'translateY(30px)';
            section.style.transition = 'all 0.5s ease';
            
            setTimeout(() => {
                section.style.opacity = '1';
                section.style.transform = 'translateY(0)';
            }, index * 150);
        });
    });
  </script>

</body>
</html>