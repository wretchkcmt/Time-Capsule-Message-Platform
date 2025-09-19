<?php
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

$error = '';
$success = '';

// Handle delete request
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // First, get the capsule to verify ownership and get attachments
        $stmt = $pdo->prepare("SELECT * FROM capsules WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['delete'], $user_id]);
        $capsule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($capsule) {
            // Delete associated attachments
            $stmt = $pdo->prepare("SELECT file_path FROM attachments WHERE capsule_id = ?");
            $stmt->execute([$capsule['id']]);
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($attachments as $attachment) {
                if (file_exists($attachment['file_path'])) {
                    unlink($attachment['file_path']);
                }
            }
            
            // Delete attachments from database
            $stmt = $pdo->prepare("DELETE FROM attachments WHERE capsule_id = ?");
            $stmt->execute([$capsule['id']]);
            
            // Delete the capsule
            $stmt = $pdo->prepare("DELETE FROM capsules WHERE id = ?");
            $stmt->execute([$capsule['id']]);
            
            $success = "Time capsule deleted successfully!";
        } else {
            $error = "Time capsule not found or you don't have permission to delete it.";
        }
    } catch (PDOException $e) {
        $error = "System error. Please try again later.";
        error_log("Delete Capsule Error: " . $e->getMessage());
    }
}

// Get all scheduled capsules for this user
try {
    $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("
        SELECT c.*, 
               u.name as recipient_name,
               u2.name as sender_name,
               (SELECT COUNT(*) FROM attachments WHERE capsule_id = c.id) as attachment_count
        FROM capsules c
        LEFT JOIN users u ON c.recipient_id = u.id
        LEFT JOIN users u2 ON c.user_id = u2.id
        WHERE c.user_id = ? 
        AND c.status = 'scheduled'
        ORDER BY c.delivery_time ASC
    ");
    $stmt->execute([$user_id]);
    $scheduled_capsules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database error. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Scheduled Capsules • Time Capsule Messenger</title>
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

    .header-actions .btn {
      padding: 12px 28px;
      background: transparent;
      border: 2px solid #6c5ce7;
      color: #6c5ce7;
      border-radius: 30px;
      font-size: 1rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .header-actions .btn:hover {
      background: rgba(108, 92, 231, 0.1);
      color: #a29bfe;
      transform: translateY(-2px);
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

    .empty-cta {
      display: inline-block;
      padding: 16px 40px;
      background: linear-gradient(135deg, #6c5ce7, #a29bfe);
      color: white;
      border-radius: 30px;
      font-size: 1.1rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .empty-cta:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 30px rgba(108, 92, 231, 0.4);
    }

    /* Capsule list */
    .capsule-list {
      display: grid;
      gap: 25px;
    }

    .capsule-card {
      background: rgba(25, 25, 25, 0.8);
      backdrop-filter: blur(10px);
      border: 1px solid #2a2a2a;
      border-radius: 20px;
      padding: 35px;
      box-shadow: 0 5px 25px rgba(0, 0, 0, 0.2);
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .capsule-card:hover {
      border-color: #444;
      transform: translateY(-3px);
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
    }

    .capsule-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 25px;
      flex-wrap: wrap;
      gap: 20px;
    }

    .capsule-info {
      flex: 1;
      min-width: 300px;
    }

    .capsule-title {
      font-size: 1.6rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 15px;
      line-height: 1.3;
    }

    .capsule-content {
      font-size: 1.05rem;
      color: #ccc;
      line-height: 1.7;
      margin-bottom: 25px;
      max-height: 120px;
      overflow: hidden;
      display: -webkit-box;
      -webkit-line-clamp: 4;
      -webkit-box-orient: vertical;
    }

    .recipient-info {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 15px;
      padding: 12px 16px;
      background: rgba(108, 92, 231, 0.1);
      border: 1px solid rgba(108, 92, 231, 0.2);
      border-radius: 12px;
      width: fit-content;
    }

    .recipient-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: linear-gradient(135deg, #55efc4, #74b9ff);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 1rem;
      flex-shrink: 0;
    }

    .recipient-details h4 {
      font-size: 1.1rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 3px;
    }

    .recipient-details p {
      font-size: 0.9rem;
      color: #999;
    }

    .capsule-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
      margin-bottom: 25px;
      padding: 15px 0;
      border-top: 1px solid #333;
      border-bottom: 1px solid #333;
    }

    .meta-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.95rem;
      color: #aaa;
    }

    .meta-item i {
      color: #6c5ce7;
      font-size: 1.1rem;
    }

    .countdown {
      background: rgba(255, 205, 86, 0.15);
      color: #ffcd56;
      padding: 4px 12px;
      border-radius: 20px;
      font-weight: 600;
      font-size: 0.9rem;
      border: 1px solid rgba(255, 205, 86, 0.3);
    }

    .attachment-info {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.95rem;
      color: #aaa;
    }

    .attachment-info i {
      color: #55efc4;
    }

    .capsule-actions {
      display: flex;
      gap: 15px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }

    .action-btn {
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

    .btn-edit {
      background: transparent;
      border: 2px solid #6c5ce7;
      color: #6c5ce7;
    }

    .btn-edit:hover {
      background: rgba(108, 92, 231, 0.1);
      color: #a29bfe;
    }

    .btn-delete {
      background: transparent;
      border: 2px solid #ff5959;
      color: #ff5959;
    }

    .btn-delete:hover {
      background: rgba(255, 89, 89, 0.1);
      color: #ff7675;
    }

    .btn-view {
      background: transparent;
      border: 2px solid #55efc4;
      color: #55efc4;
    }

    .btn-view:hover {
      background: rgba(85, 239, 196, 0.1);
      color: #74b9ff;
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
      
      .capsule-header {
        flex-direction: column;
        align-items: flex-start;
      }
      
      .capsule-info {
        min-width: auto;
      }
      
      .capsule-meta {
        flex-direction: column;
        gap: 15px;
        padding: 15px 0;
      }
      
      .capsule-actions {
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
      
      .capsule-card {
        padding: 25px;
      }
      
      .capsule-title {
        font-size: 1.4rem;
      }
      
      .recipient-info {
        padding: 10px 12px;
      }
      
      .recipient-avatar {
        width: 32px;
        height: 32px;
        font-size: 0.9rem;
      }
      
      .recipient-details h4 {
        font-size: 1rem;
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
        <h1 class="header-title">Scheduled Capsules</h1>
      </div>
      <div class="header-actions">
        <a href="compose.php" class="btn">
          <i class="fas fa-plus"></i> New Capsule
        </a>
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

    <?php if (empty($scheduled_capsules)): ?>
      <div class="empty-state">
        <i class="fas fa-clock empty-icon"></i>
        <h2 class="empty-title">No Scheduled Capsules</h2>
        <p class="empty-desc">You haven't scheduled any time capsules yet. Create your first one to send messages to your future self or others.</p>
        <a href="compose.php" class="empty-cta">
          <i class="fas fa-pen"></i> Create New Capsule
        </a>
      </div>
    <?php else: ?>
      <div class="capsule-list">
        <?php foreach ($scheduled_capsules as $capsule): ?>
          <?php
          // Calculate time until delivery
          $delivery_time = strtotime($capsule['delivery_time']);
          $now = time();
          $diff = $delivery_time - $now;
          
          if ($diff > 0) {
              $days = floor($diff / (60 * 60 * 24));
              $hours = floor(($diff % (60 * 60 * 24)) / (60 * 60));
              $minutes = floor(($diff % (60 * 60)) / 60);
              
              if ($days > 0) {
                  $countdown = "{$days}d {$hours}h {$minutes}m";
              } elseif ($hours > 0) {
                  $countdown = "{$hours}h {$minutes}m";
              } else {
                  $countdown = "{$minutes}m";
              }
          } else {
              $countdown = "Delivering soon";
          }
          ?>
          
          <div class="capsule-card">
            <div class="capsule-header">
              <div class="capsule-info">
                <h2 class="capsule-title"><?= htmlspecialchars($capsule['title'] ?: 'Untitled Message') ?></h2>
                <div class="capsule-content"><?= htmlspecialchars($capsule['content']) ?></div>
                
                <div class="recipient-info">
                  <div class="recipient-avatar"><?= strtoupper(substr($capsule['recipient_name'], 0, 1)) ?></div>
                  <div class="recipient-details">
                    <h4><?= htmlspecialchars($capsule['recipient_name']) ?></h4>
                    <p>Recipient</p>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="capsule-meta">
              <div class="meta-item">
                <i class="fas fa-calendar-alt"></i>
                <span>Scheduled for: <?= date('M j, Y \a\t g:i A', $delivery_time) ?></span>
              </div>
              <div class="meta-item">
                <i class="fas fa-clock"></i>
                <span class="countdown">Delivers in: <?= $countdown ?></span>
              </div>
              <?php if ($capsule['attachment_count'] > 0): ?>
              <div class="meta-item attachment-info">
                <i class="fas fa-paperclip"></i>
                <span><?= $capsule['attachment_count'] ?> attachment<?= $capsule['attachment_count'] > 1 ? 's' : '' ?></span>
              </div>
              <?php endif; ?>
              <div class="meta-item">
                <i class="fas fa-calendar-plus"></i>
                <span>Created: <?= date('M j, Y', strtotime($capsule['created_at'])) ?></span>
              </div>
            </div>
            
            <div class="capsule-actions">
              <a href="view_capsule.php?id=<?= $capsule['id'] ?>" class="action-btn btn-view">
                <i class="fas fa-eye"></i> View
              </a>
              <a href="compose.php?id=<?= $capsule['id'] ?>" class="action-btn btn-edit">
                <i class="fas fa-edit"></i> Edit
              </a>
              <button type="button" class="action-btn btn-delete" onclick="showDeleteModal(<?= $capsule['id'] ?>)">
                <i class="fas fa-trash"></i> Delete
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Delete Confirmation Modal -->
  <div class="modal-overlay" id="deleteModal">
    <div class="modal">
      <button class="close-modal" onclick="hideDeleteModal()">&times;</button>
      <div class="modal-header">
        <h3 class="modal-title">Confirm Deletion</h3>
        <p class="modal-message">Are you sure you want to delete this time capsule? This action cannot be undone.</p>
      </div>
      <div class="modal-actions">
        <button class="modal-btn btn-cancel" onclick="hideDeleteModal()">Cancel</button>
        <a href="#" class="modal-btn btn-confirm" id="confirmDelete">Delete</a>
      </div>
    </div>
  </div>

  <script>
    let currentDeleteId = null;
    
    function showDeleteModal(capsuleId) {
        currentDeleteId = capsuleId;
        document.getElementById('confirmDelete').href = `scheduled.php?delete=${capsuleId}`;
        document.getElementById('deleteModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function hideDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
        document.body.style.overflow = 'auto';
        currentDeleteId = null;
    }
    
    // Close modal when clicking outside
    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) {
            hideDeleteModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('deleteModal').classList.contains('active')) {
            hideDeleteModal();
        }
    });
    
    // Add smooth animations to cards
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.capsule-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            card.style.transition = 'all 0.5s ease';
            
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });
    });
  </script>

</body>
</html>