<?php
class User {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    public function findById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function findByEmail($email) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch();
    }

    public function updateLastLogin($userId, $ip, $userAgent) {
        $stmt = $this->pdo->prepare("
            INSERT INTO login_history (user_id, ip_address, user_agent)
            VALUES (?, ?, ?)
        ");
        return $stmt->execute([$userId, $ip, $userAgent]);
    }

    // Add more methods: create, updateSettings, promoteToMod, etc.
}