<?php
session_start();

// Database connection
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "procureflow";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<div class='error-message'>Database Connection Failed: " . $conn->connect_error . "</div>");
}

if (isset($_POST['reset'])) {
    if (!isset($_SESSION['reset_email'])) {
        echo "<div class='error-message'>No reset session found. Please try again.</div>";
        exit();
    }

    $email = $_SESSION['reset_email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Update users table (adjust table name if needed)
    $stmt = $conn->prepare("UPDATE users SET password_hash=?, reset_token=NULL, reset_expires=NULL WHERE email=?");
    $stmt->bind_param("ss", $password, $email);

    if ($stmt->execute()) {
        session_destroy();
        echo "<div class='success-message'>Password reset successful! <a href='login.php'>Login</a></div>";
    } else {
        echo "<div class='error-message'>Failed to reset password. Please try again.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <style>
        * {margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;}
        body {background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px;}
        .reset-container {background: white; border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); padding: 40px; width: 100%; max-width: 450px; transition: transform 0.3s ease;}
        .reset-container:hover {transform: translateY(-5px);}
        .reset-header {text-align: center; margin-bottom: 30px;}
        .reset-header h1 {color: #333; font-size: 28px; font-weight: 600; margin-bottom: 8px;}
        .reset-header p {color: #666; font-size: 14px; line-height: 1.5;}
        .form-group {margin-bottom: 25px;}
        input[type="password"] {width: 100%; padding: 15px 20px; border: 2px solid #e1e5e9; border-radius: 8px; font-size: 16px; transition: all 0.3s ease; background-color: #f8f9fa;}
        input[type="password"]:focus {outline: none; border-color: #667eea; background-color: white; box-shadow: 0 0 0 3px rgba(102,126,234,0.1);}
        input[type="password"]::placeholder {color: #a0a4a8;}
        .reset-btn {width: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 16px; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;}
        .reset-btn:hover {transform: translateY(-2px); box-shadow: 0 7px 14px rgba(102,126,234,0.3);}
        .success-message {background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; border: 1px solid #c3e6cb; margin-bottom: 20px; text-align: center;}
        .success-message a {color: #155724; font-weight: 600; text-decoration: none;}
        .success-message a:hover {text-decoration: underline;}
        .password-strength {margin-top: 10px; height: 4px; border-radius: 2px; background: #e1e5e9; overflow: hidden;}
        .strength-bar {height: 100%; width: 0%; transition: all 0.3s ease; border-radius: 2px;}
        .strength-weak {background: #e74c3c; width: 33%;}
        .strength-medium {background: #f39c12; width: 66%;}
        .strength-strong {background: #27ae60; width: 100%;}
        .back-link {text-align: center; margin-top: 20px;}
        .back-link a {color: #667eea; text-decoration: none; font-weight: 500;}
        .back-link a:hover {text-decoration: underline;}
        @media (max-width: 480px) {.reset-container {padding: 30px 25px;} .reset-header h1 {font-size: 24px;}}
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-header">
            <h1>Reset Password</h1>
            <p>Create a new secure password for your account</p>
        </div>
        <form method="POST">
            <div class="form-group">
                <input type="password" name="password" placeholder="Enter new password" required>
                <div class="password-strength"><div class="strength-bar" id="strengthBar"></div></div>
            </div>
            <button type="submit" name="reset" class="reset-btn">Reset Password</button>
        </form>
        <div class="back-link"><a href="login.php">← Back to Login</a></div>
    </div>
    <script>
        document.querySelector('input[name="password"]').addEventListener('input', function(e) {
            const password = e.target.value;
            const strengthBar = document.getElementById('strengthBar');
            let strength = 0;
            if (password.length >= 8) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            strengthBar.className = 'strength-bar';
            if (password.length > 0) {
                if (strength <= 1) strengthBar.classList.add('strength-weak');
                else if (strength <= 2) strengthBar.classList.add('strength-medium');
                else strengthBar.classList.add('strength-strong');
            }
        });
    </script>
</body>
</html>
