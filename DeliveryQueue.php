<?php
class DeliveryQueue extends SplPriorityQueue {
    public function compare($priority1, $priority2) {
        // Higher priority = earlier delivery time
        if ($priority1 === $priority2) return 0;
        return $priority1 < $priority2 ? 1 : -1; // Min-heap: earliest first
    }
}

// Usage in scheduler:
$queue = new DeliveryQueue();
foreach ($pending as $capsule) {
    $timestamp = strtotime($capsule['delivery_time']);
    $queue->insert($capsule, $timestamp);
}

while (!$queue->isEmpty()) {
    $capsule = $queue->extract();
    // Process delivery...
}