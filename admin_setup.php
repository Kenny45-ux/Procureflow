<?php
$host = 'localhost';
$dbname = 'procureflow';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ DB connection failed: " . htmlspecialchars($e->getMessage()));
}

$email = 'admin@procureflow.com';
$password = 'StrongPass@2025';
$role = 'Admin';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) die("❌ Invalid email format");
if (strlen($password) < 8) die("❌ Password must be at least 8 characters");

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, created_at) VALUES (:email, :password_hash, :role, NOW())");
    $stmt->execute([
        ':email' => $email,
        ':password_hash' => $hashedPassword,
        ':role' => $role
    ]);
    echo "✅ Admin created successfully!";
} catch (PDOException $e) {
    echo ($e->getCode() == 23000)
        ? "⚠️ Email already exists."
        : "❌ DB error: " . htmlspecialchars($e->getMessage());
}
?>
