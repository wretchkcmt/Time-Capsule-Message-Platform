<?php
session_start();

// Redirect if not logged in or not admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$error = '';
$success = '';

try {
    $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    die("Database error. Please try again later.");
}

// Handle user role changes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['change_role']) && !empty($_POST['user_id']) && !empty($_POST['new_role'])) {
        $target_user_id = $_POST['user_id'];
        $new_role = $_POST['new_role'];
        
        // Validate new role
        if (!in_array($new_role, ['registered', 'moderator'])) {
            $error = "Invalid role specified.";
        } else {
            try {
                // Don't allow changing own role
                if ($target_user_id == $user_id) {
                    $error = "You cannot change your own role.";
                } else {
                    // Update user role
                    $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                    $stmt->execute([$new_role, $target_user_id]);
                    
                    // Log the action
                    $stmt = $pdo->prepare("INSERT INTO moderation_logs (moderator_id, target_user_id, action, reason) VALUES (?, ?, 'role_changed', ?)");
                    $stmt->execute([$user_id, $target_user_id, "Role changed to {$new_role}"]);
                    
                    $success = "User role updated successfully!";
                }
            } catch (PDOException $e) {
                $error = "System error. Please try again later.";
                error_log("Change Role Error: " . $e->getMessage());
            }
        }
    }
    
    if (isset($_POST['suspend_user']) && !empty($_POST['user_id'])) {
        $target_user_id = $_POST['user_id'];
        $action = $_POST['action'] ?? 'suspend';
        $reason = $_POST['reason'] ?? ($action === 'suspend' ? 'Administrator suspension' : 'Suspension lifted');
        
        try {
            // Don't allow suspending yourself
            if ($target_user_id == $user_id) {
                $error = "You cannot suspend your own account.";
            } else {
                // For full suspension functionality, you would need to add an 'is_suspended' column to users table
                // For now, we'll just log the action
                
                $action_type = $action === 'suspend' ? 'suspended' : 'unsuspended';
                $stmt = $pdo->prepare("INSERT INTO moderation_logs (moderator_id, target_user_id, action, reason) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $target_user_id, $action_type, $reason]);
                
                $success = $action === 'suspend' ? "User suspended successfully!" : "User suspension lifted!";
            }
        } catch (PDOException $e) {
            $error = "System error. Please try again later.";
            error_log("Suspend User Error: " . $e->getMessage());
        }
    }
}

// Get filter parameters
$filter_role = $_GET['role'] ?? '';
$filter_search = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build query for users
$query = "SELECT u.*, 
          (SELECT COUNT(*) FROM capsules WHERE user_id = u.id) as capsules_sent,
          (SELECT COUNT(*) FROM capsules WHERE recipient_id = u.id) as capsules_received,
          (SELECT COUNT(*) FROM reports WHERE reporter_id = u.id) as reports_made,
          (SELECT COUNT(*) FROM moderation_logs WHERE target_user_id = u.id AND action = 'suspended') as times_suspended
          FROM users u 
          WHERE u.id != ?"; // Don't show current admin in list

$params = [$user_id];

if (!empty($filter_role)) {
    $query .= " AND u.role = ?";
    $params[] = $filter_role;
}

if (!empty($filter_search)) {
    $query .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $search_term = "%{$filter_search}%";
    $params[] = $search_term;
    $params[] = $search_term;
}

$query .= " ORDER BY {$sort_by} {$sort_order}";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get system statistics
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_users FROM users");
    $stmt->execute();
    $total_users = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_capsules FROM capsules");
    $stmt->execute();
    $total_capsules = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_reports FROM reports");
    $stmt->execute();
    $total_reports = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_reports FROM reports WHERE status = 'pending'");
    $stmt->execute();
    $pending_reports = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_moderators FROM users WHERE role = 'moderator'");
    $stmt->execute();
    $total_moderators = $stmt->fetchColumn();
    
    // Get recent admin actions
    $stmt = $pdo->prepare("
        SELECT ml.*, u.name as moderator_name, u2.name as target_user_name
        FROM moderation_logs ml
        JOIN users u ON ml.moderator_id = u.id
        LEFT JOIN users u2 ON ml.target_user_id = u2.id
        WHERE ml.action IN ('role_changed', 'suspended', 'unsuspended')
        ORDER BY ml.timestamp DESC
        LIMIT 10
    ");
    $stmt->execute();
    $admin_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database error. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Panel • Time Capsule Messenger</title>
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

    .admin-badge {
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
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 25px;
      margin-bottom: 40px;
    }

    .stat-card {
      background: rgba(25, 25, 25, 0.8);
      border: 1px solid #2a2a2a;
      border-radius: 20px;
      padding: 30px;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .stat-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    }

    .stat-card.admin {
      background: rgba(108, 92, 231, 0.1);
      border-color: rgba(108, 92, 231, 0.3);
    }

    .stat-card.moderator {
      background: rgba(255, 189, 89, 0.1);
      border-color: rgba(255, 189, 89, 0.3);
    }

    .stat-card.danger {
      background: rgba(255, 89, 89, 0.1);
      border-color: rgba(255, 89, 89, 0.3);
    }

    .stat-label {
      font-size: 0.9rem;
      color: #888;
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
      margin-bottom: 8px;
    }

    .stat-desc {
      font-size: 1rem;
      color: #ccc;
      font-weight: 500;
    }

    /* Filters and search */
    .filters-section {
      background: rgba(25, 25, 25, 0.8);
      border: 1px solid #2a2a2a;
      border-radius: 20px;
      padding: 30px;
      margin-bottom: 40px;
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
      align-items: flex-end;
    }

    .filter-group {
      flex: 1;
      min-width: 200px;
    }

    .filter-label {
      display: block;
      margin-bottom: 8px;
      font-size: 0.95rem;
      color: #aaa;
    }

    .filter-control {
      width: 100%;
      padding: 14px;
      background: #1a1a1a;
      border: 1px solid #333;
      border-radius: 12px;
      color: #fff;
      font-size: 1rem;
      font-family: 'Montserrat', sans-serif;
      transition: all 0.3s ease;
    }

    .filter-control:focus {
      outline: none;
      border-color: #6c5ce7;
      box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.2);
    }

    .filter-btn {
      padding: 14px 28px;
      background: linear-gradient(135deg, #6c5ce7, #a29bfe);
      color: white;
      border: none;
      border-radius: 30px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .filter-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(108, 92, 231, 0.4);
    }

    /* Users table */
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

    .users-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 30px;
    }

    .users-table th {
      text-align: left;
      padding: 16px 20px;
      font-size: 1.1rem;
      font-weight: 600;
      color: #fff;
      border-bottom: 2px solid #333;
      cursor: pointer;
      position: relative;
    }

    .users-table th:hover {
      color: #6c5ce7;
    }

    .users-table th.sortable::after {
      content: ' ↕️';
      font-size: 0.8rem;
      opacity: 0.5;
    }

    .users-table th.active::after {
      opacity: 1;
      color: #6c5ce7;
    }

    .users-table td {
      padding: 16px 20px;
      font-size: 1rem;
      color: #ccc;
      border-bottom: 1px solid #333;
      vertical-align: middle;
    }

    .users-table tr:hover {
      background: rgba(108, 92, 231, 0.05);
    }

    .user-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, #6c5ce7, #a29bfe);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 1.2rem;
      color: white;
      margin-right: 15px;
    }

    .user-info {
      display: flex;
      align-items: center;
    }

    .user-name {
      font-weight: 600;
      color: #fff;
      margin-bottom: 5px;
    }

    .user-email {
      font-size: 0.9rem;
      color: #888;
    }

    .role-badge {
      padding: 6px 16px;
      border-radius: 30px;
      font-size: 0.85rem;
      font-weight: 600;
      text-transform: capitalize;
    }

    .role-registered {
      background: rgba(85, 239, 196, 0.15);
      color: #55efc4;
      border: 1px solid rgba(85, 239, 196, 0.3);
    }

    .role-moderator {
      background: rgba(255, 189, 89, 0.15);
      color: #ffbd59;
      border: 1px solid rgba(255, 189, 89, 0.3);
    }

    .role-admin {
      background: rgba(255, 89, 89, 0.15);
      color: #ff5959;
      border: 1px solid rgba(255, 89, 89, 0.3);
    }

    .stats-badge {
      background: rgba(108, 92, 231, 0.1);
      color: #6c5ce7;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      margin-right: 10px;
    }

    .action-select {
      padding: 10px;
      background: #1a1a1a;
      border: 1px solid #333;
      border-radius: 8px;
      color: #fff;
      font-size: 0.9rem;
      margin-right: 10px;
    }

    .action-btn {
      padding: 10px 20px;
      background: #6c5ce7;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .action-btn:hover {
      background: #5649c9;
    }

    .danger-btn {
      background: #ff5959;
    }

    .danger-btn:hover {
      background: #ff7675;
    }

    /* Admin Logs */
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
      background: rgba(108, 92, 231, 0.05);
    }

    /* Modal for suspend actions */
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

    .btn-confirm-suspend {
      background: #ff5959;
      color: white;
      border: none;
    }

    .btn-confirm-suspend:hover {
      background: #ff7675;
      transform: translateY(-2px);
    }

    .btn-confirm-unsuspend {
      background: #55efc4;
      color: #000;
      border: none;
    }

    .btn-confirm-unsuspend:hover {
      background: #74b9ff;
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
      
      .filters-section {
        flex-direction: column;
        align-items: stretch;
      }
      
      .filter-group {
        min-width: auto;
      }
      
      .stats-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 480px) {
      .header-title {
        font-size: 1.8rem;
      }
      
      .section {
        padding: 30px 20px;
      }
      
      .users-table th, .users-table td {
        padding: 12px 15px;
        font-size: 0.9rem;
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
        <h1 class="header-title">Admin Panel</h1>
        <div class="admin-badge">
          <i class="fas fa-crown"></i>
          Administrator
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

    <!-- System Statistics -->
    <div class="stats-grid">
      <div class="stat-card admin">
        <div class="stat-label">
          <i class="fas fa-users"></i>
          TOTAL USERS
        </div>
        <div class="stat-value"><?= $total_users ?></div>
        <div class="stat-desc">All registered accounts</div>
      </div>
      
      <div class="stat-card moderator">
        <div class="stat-label">
          <i class="fas fa-shield-alt"></i>
          MODERATORS
        </div>
        <div class="stat-value"><?= $total_moderators ?></div>
        <div class="stat-desc">Trusted community managers</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-label">
          <i class="fas fa-envelope"></i>
          TOTAL CAPSULES
        </div>
        <div class="stat-value"><?= $total_capsules ?></div>
        <div class="stat-desc">Messages across time</div>
      </div>
      
      <div class="stat-card danger">
        <div class="stat-label">
          <i class="fas fa-flag"></i>
          PENDING REPORTS
        </div>
        <div class="stat-value"><?= $pending_reports ?></div>
        <div class="stat-desc">Awaiting moderation</div>
      </div>
    </div>

    <!-- Filters and Search -->
    <div class="filters-section">
      <form method="GET" class="filter-group">
        <label class="filter-label">Filter by Role</label>
        <select name="role" class="filter-control">
          <option value="">All Roles</option>
          <option value="registered" <?= $filter_role === 'registered' ? 'selected' : '' ?>>Registered Users</option>
          <option value="moderator" <?= $filter_role === 'moderator' ? 'selected' : '' ?>>Moderators</option>
        </select>
      </form>
      
      <form method="GET" class="filter-group">
        <label class="filter-label">Search Users</label>
        <input type="text" name="search" class="filter-control" 
               placeholder="Search by name or email" 
               value="<?= htmlspecialchars($filter_search) ?>">
        <input type="hidden" name="role" value="<?= htmlspecialchars($filter_role) ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($sort_order) ?>">
      </form>
      
      <button type="submit" class="filter-btn">
        <i class="fas fa-filter"></i> Apply Filters
      </button>
    </div>

    <!-- Users Management Section -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon">
          <i class="fas fa-users-cog"></i>
        </div>
        <h2 class="section-title">User Management</h2>
      </div>
      
      <div style="overflow-x: auto;">
        <table class="users-table">
          <thead>
            <tr>
              <th class="sortable <?= $sort_by === 'name' ? 'active' : '' ?>">
                <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'name', 'order' => $sort_by === 'name' && $sort_order === 'ASC' ? 'DESC' : 'ASC'])) ?>">
                  User
                </a>
              </th>
              <th class="sortable <?= $sort_by === 'role' ? 'active' : '' ?>">
                <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'role', 'order' => $sort_by === 'role' && $sort_order === 'ASC' ? 'DESC' : 'ASC'])) ?>">
                  Role
                </a>
              </th>
              <th class="sortable <?= $sort_by === 'created_at' ? 'active' : '' ?>">
                <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'created_at', 'order' => $sort_by === 'created_at' && $sort_order === 'ASC' ? 'DESC' : 'ASC'])) ?>">
                  Joined
                </a>
              </th>
              <th>Activity</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
              <tr>
                <td colspan="5" style="text-align: center; padding: 40px; color: #888;">
                  No users found matching your criteria.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($users as $user): ?>
                <tr>
                  <td>
                    <div class="user-info">
                      <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
                      <div>
                        <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                        <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="role-badge role-<?= $user['role'] ?>">
                      <?= ucfirst($user['role']) ?>
                    </span>
                  </td>
                  <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                  <td>
                    <span class="stats-badge">Sent: <?= $user['capsules_sent'] ?></span>
                    <span class="stats-badge">Received: <?= $user['capsules_received'] ?></span>
                    <span class="stats-badge">Reports: <?= $user['reports_made'] ?></span>
                    <?php if ($user['times_suspended'] > 0): ?>
                    <span class="stats-badge" style="background: rgba(255, 89, 89, 0.15); color: #ff5959; border: 1px solid rgba(255, 89, 89, 0.3);">
                      Suspended: <?= $user['times_suspended'] ?>x
                    </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <form method="POST" style="display: flex; align-items: center; gap: 10px;">
                      <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                      <select name="new_role" class="action-select" <?= $user['role'] === 'admin' ? 'disabled' : '' ?>>
                        <option value="registered" <?= $user['role'] === 'registered' ? 'selected' : '' ?>>Registered</option>
                        <option value="moderator" <?= $user['role'] === 'moderator' ? 'selected' : '' ?>>Moderator</option>
                      </select>
                      <button type="submit" name="change_role" class="action-btn" <?= $user['role'] === 'admin' ? 'disabled' : '' ?>>
                        <i class="fas fa-sync"></i> Update
                      </button>
                    </form>
                    
                    <!-- Suspend/Unsuspend button (placeholder for full suspension system) -->
                    <form method="POST" style="margin-top: 10px;">
                      <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                      <input type="hidden" name="action" value="suspend">
                      <button type="button" class="action-btn danger-btn" onclick="showSuspendModal(<?= $user['id'] ?>, '<?= addslashes($user['name']) ?>')">
                        <i class="fas fa-user-slash"></i> Suspend
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Admin Logs Section -->
    <div class="section">
      <div class="section-header">
        <div class="section-icon">
          <i class="fas fa-clipboard-list"></i>
        </div>
        <h2 class="section-title">Recent Admin Actions</h2>
      </div>
      
      <div style="overflow-x: auto;">
        <table class="logs-table">
          <thead>
            <tr>
              <th>Date & Time</th>
              <th>Admin</th>
              <th>Action</th>
              <th>Target</th>
              <th>Reason</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($admin_logs)): ?>
              <tr>
                <td colspan="5" style="text-align: center; padding: 40px; color: #888;">
                  No admin actions logged yet.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($admin_logs as $log): ?>
                <tr>
                  <td><?= date('M j, Y \a\t g:i A', strtotime($log['timestamp'])) ?></td>
                  <td><?= htmlspecialchars($log['moderator_name']) ?></td>
                  <td>
                    <span style="color: 
                      <?= $log['action'] === 'role_changed' ? '#55efc4' : 
                         ($log['action'] === 'suspended' ? '#ff5959' : '#ffcd56') ?>;">
                      <?= ucfirst(str_replace('_', ' ', $log['action'])) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($log['target_user_name']): ?>
                      <?= htmlspecialchars($log['target_user_name']) ?>
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
        <input type="hidden" name="action" value="suspend">
        <div class="form-group">
          <label class="modal-label">User to Suspend</label>
          <div style="background: #2a2a2a; border: 1px solid #444; border-radius: 12px; padding: 16px; color: #fff; margin-bottom: 20px;">
            <strong id="suspendUserName">User Name</strong>
          </div>
        </div>
        <div class="form-group">
          <label class="modal-label">Reason for Suspension (required)</label>
          <textarea name="reason" class="modal-input" rows="4" placeholder="Explain why this user is being suspended..." required>Administrator suspension</textarea>
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
    let currentSuspendId = null;
    let currentSuspendName = null;
    
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
    
    // Close modal when clicking outside
    document.getElementById('suspendModal').addEventListener('click', function(e) {
        if (e.target === this) {
            hideSuspendModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('suspendModal').classList.contains('active')) {
            hideSuspendModal();
        }
    });
    
    // Add smooth animations to table rows
    document.addEventListener('DOMContentLoaded', function() {
        const rows = document.querySelectorAll('.users-table tbody tr');
        rows.forEach((row, index) => {
            row.style.opacity = '0';
            row.style.transform = 'translateX(-30px)';
            row.style.transition = 'all 0.5s ease';
            
            setTimeout(() => {
                row.style.opacity = '1';
                row.style.transform = 'translateX(0)';
            }, index * 100);
        });
    });
  </script>

</body>
</html>