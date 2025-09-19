<?php
require_once '../app/models/TimeCapsule.php';
require_once '../app/models/Notification.php';

$capsuleModel = new TimeCapsule();
$notifyModel = new Notification();

$pending = $capsuleModel->getPendingDeliveries();

foreach ($pending as $capsule) {
    // Mark delivered
    $capsuleModel->markAsDelivered($capsule['id']);

    // Create notification for recipient
    $notifyModel->create([
        'user_id' => $capsule['recipient_id'],
        'message' => "📬 Time capsule \"{$capsule['title']}\" from {$capsule['sender_name']} has been delivered!",
        'type' => 'delivery',
        'related_capsule_id' => $capsule['id']
    ]);

    // Optional: Log job run
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare("
        UPDATE scheduled_jobs 
        SET last_run = NOW(), next_run = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
        WHERE job_type = 'deliver_capsules'
    ");
    $stmt->execute();
}

echo "✅ Processed " . count($pending) . " deliveries.\n";