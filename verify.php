<?php
include 'config.php';

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Find user with this token
    $stmt = $pdo->prepare("SELECT * FROM users WHERE verification_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Mark as verified and clear token
        $stmt = $pdo->prepare("UPDATE users SET is_verified = 1, verification_token = NULL, status = 'approved' WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        echo "Email verified successfully! You can now log in.";
        // Or redirect to login page
        // header("Location: login.php?verified=1");
    } else {
        echo "Invalid or expired verification link.";
    }
} else {
    echo "No verification token provided.";
}
?>