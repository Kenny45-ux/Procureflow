<?php
session_start();
require 'config.php';

if (isset($_POST['password']) && isset($_SESSION['email'])) {
    $email = $_SESSION['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE admins SET password=?, reset_code=NULL, reset_expire=NULL WHERE email=?");
    $stmt->bind_param("ss", $password, $email);
    $stmt->execute();

    session_destroy();
    echo "✅ Password reset successfully! <a href='Login.php'>Login</a>";
}
