<?php
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Initialize variables
$edit_mode = false;
$capsule_data = [
    'id' => '',
    'recipient_id' => $user_id, // Default to self
    'recipient_name' => $user_name,
    'title' => '',
    'content' => '',
    'delivery_time' => date('Y-m-d\TH:i', strtotime('+1 day')),
    'is_draft' => false,
    'attachments' => []
];

// Handle editing existing capsule (if ID is provided)
if (isset($_GET['id']) && !empty($_GET['id'])) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("
            SELECT c.*, u.name as recipient_name 
            FROM capsules c
            LEFT JOIN users u ON c.recipient_id = u.id
            WHERE c.id = ? AND c.user_id = ?
        ");
        $stmt->execute([$_GET['id'], $user_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $edit_mode = true;
            $capsule_data = [
                'id' => $existing['id'],
                'recipient_id' => $existing['recipient_id'],
                'recipient_name' => $existing['recipient_name'] ?? $user_name,
                'title' => $existing['title'],
                'content' => $existing['content'],
                'delivery_time' => date('Y-m-d\TH:i', strtotime($existing['delivery_time'])),
                'is_draft' => $existing['is_draft'],
                'attachments' => []
            ];
            
            // Get existing attachments
            $stmt = $pdo->prepare("SELECT * FROM attachments WHERE capsule_id = ?");
            $stmt->execute([$existing['id']]);
            $capsule_data['attachments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        die("Database error. Please try again later.");
    }
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_email = trim($_POST['recipient_email'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';
    $delivery_time = $_POST['delivery_time'] ?? '';
    $save_draft = isset($_POST['save_draft']);
    $remove_attachments = $_POST['remove_attachments'] ?? [];
    
    // Validate delivery time
    $delivery_timestamp = strtotime($delivery_time);
    $now = time();
    if ($delivery_timestamp <= $now) {
        $error = "Delivery time must be in the future.";
    } elseif (empty($content)) {
        $error = "Message content is required.";
    } else {
        try {
            $pdo = new PDO("mysql:host=localhost;dbname=time_capsule;charset=utf8mb4", 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get recipient ID
            $recipient_id = $user_id; // Default to self
            if (!empty($recipient_email) && $recipient_email !== $_SESSION['user_email']) {
                $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
                $stmt->execute([$recipient_email]);
                $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($recipient) {
                    $recipient_id = $recipient['id'];
                    $capsule_data['recipient_name'] = $recipient['name'];
                } else {
                    $error = "Recipient email not found. Please enter a valid registered email.";
                }
            }
            
            if (empty($error)) {
                if ($edit_mode && !empty($capsule_data['id'])) {
                    // Update existing capsule
                    $stmt = $pdo->prepare("
                        UPDATE capsules 
                        SET recipient_id = ?, title = ?, content = ?, delivery_time = ?, is_draft = ?
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([
                        $recipient_id, 
                        $title, 
                        $content, 
                        $delivery_time, 
                        $save_draft ? 1 : 0,
                        $capsule_data['id'],
                        $user_id
                    ]);
                    $capsule_id = $capsule_data['id'];
                } else {
                    // Create new capsule
                    $stmt = $pdo->prepare("
                        INSERT INTO capsules (user_id, recipient_id, title, content, delivery_time, is_draft, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $status = $save_draft ? 'draft' : 'scheduled';
                    $stmt->execute([
                        $user_id, 
                        $recipient_id, 
                        $title, 
                        $content, 
                        $delivery_time, 
                        $save_draft ? 1 : 0,
                        $status
                    ]);
                    $capsule_id = $pdo->lastInsertId();
                }
                
                // Handle file uploads
                if (isset($_FILES['attachments']) && $_FILES['attachments']['error'][0] !== 4) {
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'audio/mpeg', 'application/pdf'];
                    $max_size = 10 * 1024 * 1024; // 10MB
                    
                    foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                            $file_name = $_FILES['attachments']['name'][$key];
                            $file_type = $_FILES['attachments']['type'][$key];
                            $file_size = $_FILES['attachments']['size'][$key];
                            $file_tmp = $_FILES['attachments']['tmp_name'][$key];
                            
                            if (!in_array($file_type, $allowed_types)) {
                                $error = "File type not allowed. Please upload JPG, PNG, GIF, MP4, MP3, or PDF files.";
                                break;
                            }
                            
                            if ($file_size > $max_size) {
                                $error = "File too large. Maximum size is 10MB.";
                                break;
                            }
                            
                            // Create uploads directory if it doesn't exist
                            $upload_dir = 'uploads/';
                            if (!is_dir($upload_dir)) {
                                mkdir($upload_dir, 0777, true);
                            }
                            
                            // Generate unique filename
                            $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
                            $new_filename = uniqid() . '.' . $file_ext;
                            $file_path = $upload_dir . $new_filename;
                            
                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $stmt = $pdo->prepare("
                                    INSERT INTO attachments (capsule_id, file_path, file_type, file_size, uploaded_by)
                                    VALUES (?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $capsule_id,
                                    $file_path,
                                    $file_type,
                                    $file_size,
                                    $user_id
                                ]);
                            } else {
                                $error = "Failed to upload file.";
                                break;
                            }
                        }
                    }
                }
                
                // Handle attachment removal
                if (!empty($remove_attachments) && $edit_mode) {
                    foreach ($remove_attachments as $attachment_id) {
                        // Get file path before deletion
                        $stmt = $pdo->prepare("SELECT file_path FROM attachments WHERE id = ? AND capsule_id = ?");
                        $stmt->execute([$attachment_id, $capsule_id]);
                        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($attachment) {
                            // Delete file from server
                            if (file_exists($attachment['file_path'])) {
                                unlink($attachment['file_path']);
                            }
                            
                            // Delete from database
                            $stmt = $pdo->prepare("DELETE FROM attachments WHERE id = ?");
                            $stmt->execute([$attachment_id]);
                        }
                    }
                }
                
                if (empty($error)) {
                    $success = $save_draft ? "Draft saved successfully!" : "Time capsule scheduled successfully!";
                    
                    // Redirect after success
                    if (!$save_draft) {
                        header("refresh:2;url=dashboard.php");
                    }
                }
            }
        } catch (PDOException $e) {
            $error = "System error. Please try again later.";
            error_log("Compose DB Error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $edit_mode ? 'Edit' : 'Compose' ?> Time Capsule • Time Capsule Messenger</title>
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
      margin-bottom: 40px;
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
      margin-right: 25px;
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

    .compose-card {
      background: rgba(25, 25, 25, 0.8);
      backdrop-filter: blur(10px);
      border: 1px solid #2a2a2a;
      border-radius: 20px;
      padding: 40px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
      margin-bottom: 40px;
    }

    .form-section {
      margin-bottom: 40px;
      padding-bottom: 40px;
      border-bottom: 1px solid #333;
    }

    .form-section:last-child {
      border-bottom: none;
      margin-bottom: 0;
      padding-bottom: 0;
    }

    .section-title {
      font-size: 1.4rem;
      font-weight: 600;
      color: #fff;
      margin-bottom: 25px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .section-title i {
      color: #6c5ce7;
    }

    .form-group {
      margin-bottom: 25px;
    }

    .form-label {
      display: block;
      margin-bottom: 10px;
      font-size: 1rem;
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
      min-height: 200px;
      resize: vertical;
      line-height: 1.6;
    }

    .recipient-hint {
      font-size: 0.9rem;
      color: #888;
      margin-top: 8px;
      font-style: italic;
    }

    /* Datetime picker styling */
    .datetime-picker {
      position: relative;
    }

    .datetime-picker i {
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: #666;
      pointer-events: none;
    }

    /* File upload styling */
    .file-upload-container {
      border: 2px dashed #444;
      border-radius: 12px;
      padding: 30px;
      text-align: center;
      transition: all 0.3s ease;
      cursor: pointer;
      position: relative;
    }

    .file-upload-container:hover {
      border-color: #6c5ce7;
      background: rgba(108, 92, 231, 0.05);
    }

    .file-upload-icon {
      font-size: 3rem;
      color: #666;
      margin-bottom: 15px;
    }

    .file-upload-text {
      font-size: 1.1rem;
      color: #ccc;
      margin-bottom: 8px;
    }

    .file-upload-subtext {
      font-size: 0.9rem;
      color: #888;
      margin-bottom: 20px;
    }

    .file-upload-btn {
      background: #6c5ce7;
      color: white;
      border: none;
      padding: 12px 28px;
      border-radius: 30px;
      font-size: 1rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .file-upload-btn:hover {
      background: #5649c9;
      transform: translateY(-2px);
    }

    /* Hidden file input */
    #fileInput {
      display: none;
    }

    /* Attachment preview */
    .attachments-preview {
      margin-top: 30px;
    }

    .attachment-item {
      display: flex;
      align-items: center;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid #333;
      border-radius: 12px;
      padding: 15px;
      margin-bottom: 15px;
      transition: all 0.3s ease;
    }

    .attachment-item:hover {
      border-color: #6c5ce7;
    }

    .attachment-icon {
      width: 50px;
      height: 50px;
      border-radius: 8px;
      background: #333;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 15px;
      flex-shrink: 0;
    }

    .attachment-icon i {
      font-size: 1.5rem;
      color: #6c5ce7;
    }

    .attachment-info {
      flex: 1;
    }

    .attachment-name {
      font-weight: 500;
      color: #fff;
      margin-bottom: 5px;
      word-break: break-all;
    }

    .attachment-meta {
      font-size: 0.85rem;
      color: #888;
    }

    .remove-attachment {
      background: rgba(255, 89, 89, 0.2);
      color: #ff5959;
      border: none;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .remove-attachment:hover {
      background: rgba(255, 89, 89, 0.3);
      transform: scale(1.1);
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
    }

    .btn-primary {
      background: linear-gradient(135deg, #6c5ce7, #a29bfe);
      color: white;
      border: none;
    }

    .btn-outline {
      background: transparent;
      border: 2px solid #6c5ce7;
      color: #6c5ce7;
    }

    .btn-draft {
      background: transparent;
      border: 2px solid #ff9f43;
      color: #ff9f43;
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

    .btn-draft:hover {
      background: rgba(255, 159, 67, 0.1);
      color: #ffbf69;
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
      
      .back-btn {
        margin-right: 0;
      }
      
      .header-title {
        font-size: 2rem;
      }
      
      .compose-card {
        padding: 30px 20px;
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
        font-size: 1.2rem;
      }
      
      .form-control {
        padding: 14px;
      }
      
      .form-textarea {
        min-height: 150px;
      }
      
      .file-upload-container {
        padding: 20px;
      }
      
      .file-upload-icon {
        font-size: 2.5rem;
      }
    }

    /* File type icons */
    .file-icon-image { background: linear-gradient(135deg, #55efc4, #74b9ff); }
    .file-icon-video { background: linear-gradient(135deg, #fd79a8, #e84393); }
    .file-icon-audio { background: linear-gradient(135deg, #ffeaa7, #fab1a0); }
    .file-icon-pdf { background: linear-gradient(135deg, #a29bfe, #6c5ce7); }
  </style>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

  <div class="container">
    <div class="header">
      <a href="dashboard.php" class="back-btn">
        <i class="fas fa-arrow-left"></i>
      </a>
      <h1 class="header-title"><?= $edit_mode ? 'Edit Time Capsule' : 'Compose New Time Capsule' ?></h1>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        <?php if (strpos($success, 'scheduled') !== false): ?>
          <br>Redirecting to dashboard in 2 seconds...
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="compose-card">
      <form method="POST" enctype="multipart/form-data" id="composeForm">
        
        <!-- Recipient Section -->
        <div class="form-section">
          <h2 class="section-title"><i class="fas fa-user"></i> Recipient</h2>
          <div class="form-group">
            <label class="form-label">Send to (email address)</label>
            <input type="email" name="recipient_email" class="form-control" 
                   placeholder="Enter recipient's email (leave blank to send to yourself)"
                   value="<?= htmlspecialchars($_POST['recipient_email'] ?? '') ?>"
                   autocomplete="off">
            <p class="recipient-hint">Leave blank or enter your own email to send to yourself</p>
          </div>
        </div>

        <!-- Message Content Section -->
        <div class="form-section">
          <h2 class="section-title"><i class="fas fa-envelope"></i> Message</h2>
          <div class="form-group">
            <label class="form-label">Title (Optional)</label>
            <input type="text" name="title" class="form-control" 
                   placeholder="Give your time capsule a title"
                   value="<?= htmlspecialchars($_POST['title'] ?? $capsule_data['title']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Your Message *</label>
            <textarea name="content" class="form-control form-textarea" 
                      placeholder="Write your message here... This will be delivered at the scheduled time." 
                      required><?= htmlspecialchars($_POST['content'] ?? $capsule_data['content']) ?></textarea>
          </div>
        </div>

        <!-- Delivery Time Section -->
        <div class="form-section">
          <h2 class="section-title"><i class="fas fa-clock"></i> Schedule Delivery</h2>
          <div class="form-group">
            <label class="form-label">Deliver on *</label>
            <div class="datetime-picker">
              <input type="datetime-local" name="delivery_time" class="form-control" 
                     value="<?= htmlspecialchars($_POST['delivery_time'] ?? $capsule_data['delivery_time']) ?>" 
                     min="<?= date('Y-m-d\TH:i', strtotime('+10 minutes')) ?>" required>
              <i class="fas fa-calendar-alt"></i>
            </div>
            <p class="recipient-hint">Choose a future date and time for your message to be delivered</p>
          </div>
        </div>

        <!-- Attachments Section -->
        <div class="form-section">
          <h2 class="section-title"><i class="fas fa-paperclip"></i> Attachments</h2>
          
          <!-- File Upload Area -->
          <div class="file-upload-container" id="dropZone">
            <i class="fas fa-cloud-upload-alt file-upload-icon"></i>
            <div class="file-upload-text">Drag & drop files here or click to browse</div>
            <div class="file-upload-subtext">Supports images, videos, audio, PDFs (max 10MB each)</div>
            <button type="button" class="file-upload-btn" onclick="document.getElementById('fileInput').click()">
              <i class="fas fa-folder-open"></i> Choose Files
            </button>
            <input type="file" id="fileInput" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.mp4,.mp3,.pdf">
          </div>

          <!-- Existing Attachments Preview -->
          <?php if (!empty($capsule_data['attachments'])): ?>
          <div class="attachments-preview">
            <h3 class="form-label" style="margin: 30px 0 15px 0;">Current Attachments</h3>
            <?php foreach ($capsule_data['attachments'] as $attachment): ?>
            <div class="attachment-item">
              <div class="attachment-icon 
                <?= strpos($attachment['file_type'], 'image') !== false ? 'file-icon-image' : '' ?>
                <?= strpos($attachment['file_type'], 'video') !== false ? 'file-icon-video' : '' ?>
                <?= strpos($attachment['file_type'], 'audio') !== false ? 'file-icon-audio' : '' ?>
                <?= strpos($attachment['file_type'], 'pdf') !== false ? 'file-icon-pdf' : '' ?>">
                <?php if (strpos($attachment['file_type'], 'image') !== false): ?>
                  <i class="fas fa-image"></i>
                <?php elseif (strpos($attachment['file_type'], 'video') !== false): ?>
                  <i class="fas fa-video"></i>
                <?php elseif (strpos($attachment['file_type'], 'audio') !== false): ?>
                  <i class="fas fa-music"></i>
                <?php elseif (strpos($attachment['file_type'], 'pdf') !== false): ?>
                  <i class="fas fa-file-pdf"></i>
                <?php else: ?>
                  <i class="fas fa-file"></i>
                <?php endif; ?>
              </div>
              <div class="attachment-info">
                <div class="attachment-name"><?= htmlspecialchars(basename($attachment['file_path'])) ?></div>
                <div class="attachment-meta">
                  <?= round($attachment['file_size'] / 1024, 2) ?> KB • 
                  <?= date('M j, Y', filemtime($attachment['file_path'])) ?>
                </div>
              </div>
              <button type="button" class="remove-attachment" 
                      onclick="document.getElementById('remove_<?= $attachment['id'] ?>').checked = true; this.closest('.attachment-item').style.opacity = '0.5'; this.disabled = true;">
                <i class="fas fa-times"></i>
              </button>
              <input type="hidden" name="remove_attachments[]" id="remove_<?= $attachment['id'] ?>" value="<?= $attachment['id'] ?>">
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
          <button type="submit" name="save_draft" value="1" class="btn btn-draft">
            <i class="fas fa-save"></i> Save Draft
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-paper-plane"></i> Schedule Delivery
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        // File upload handling
        const fileInput = document.getElementById('fileInput');
        const dropZone = document.getElementById('dropZone');
        
        // Click on drop zone to trigger file input
        dropZone.addEventListener('click', function() {
            fileInput.click();
        });
        
        // Drag and drop functionality
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            dropZone.style.borderColor = '#6c5ce7';
            dropZone.style.background = 'rgba(108, 92, 231, 0.1)';
        }
        
        function unhighlight() {
            dropZone.style.borderColor = '#444';
            dropZone.style.background = '';
        }
        
        // Handle file drop
        dropZone.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            
            // Show file names (optional)
            if (files.length > 0) {
                const fileNames = Array.from(files).map(file => file.name).join(', ');
                dropZone.querySelector('.file-upload-text').textContent = `${files.length} file(s) selected`;
            }
        }
        
        // Real-time validation for delivery time
        const deliveryTimeInput = document.querySelector('input[name="delivery_time"]');
        deliveryTimeInput.addEventListener('change', function() {
            const selectedTime = new Date(this.value);
            const now = new Date();
            now.setMinutes(now.getMinutes() + 9); // 10 minutes from now (9 because of the +1 min below)
            
            if (selectedTime <= now) {
                alert('Please select a delivery time at least 10 minutes in the future.');
                this.value = '';
            }
        });
        
        // Form submission validation
        document.getElementById('composeForm').addEventListener('submit', function(e) {
            const content = document.querySelector('textarea[name="content"]').value.trim();
            if (content === '') {
                e.preventDefault();
                alert('Please enter your message content.');
                return false;
            }
        });
    });
  </script>

</body>
</html>