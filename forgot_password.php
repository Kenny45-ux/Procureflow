<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/smtp_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Database connection
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "procureflow";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<div class='error-message'>Database Connection Failed: " . $conn->connect_error . "</div>");
}

if (isset($_POST['send_code'])) {
    $email = trim($_POST['email']);

    // Check if email exists in admins table
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $code = rand(100000, 999999);
        $expire = date("Y-m-d H:i:s", strtotime("+10 minutes"));

        // Update DB with code & expiry
        $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?");
        $update->bind_param("sss", $code, $expire, $email);
        $update->execute();

        // Send email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $smtpConfig['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpConfig['username'];
            $mail->Password   = $smtpConfig['password'];
            $mail->SMTPSecure = $smtpConfig['security'];
            $mail->Port       = $smtpConfig['port'];

            $mail->setFrom($smtpConfig['username'], 'ProcureFlow Support');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Code';
            $mail->Body    = "
                <p>Hello,</p>
                <p>Your password reset code is:</p>
                <h2 style='color:#667eea;'>$code</h2>
                <p>This code will expire in 10 minutes.</p>
                <br>
                <p>– The ProcureFlow Team</p>
            ";

            $mail->send();
            echo "<div class='success-message'>Code sent successfully! Check your email. <a href='verify_code.php'>Enter Code</a></div>";
        } catch (Exception $e) {
            echo "<div class='error-message'>Mailer Error: {$mail->ErrorInfo}</div>";
        }
    } else {
        echo "<div class='error-message'>Email not found in admin records!</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .forgot-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            transition: transform 0.3s ease;
        }

        .forgot-container:hover {
            transform: translateY(-5px);
        }

        .forgot-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .forgot-header h1 {
            color: #333;
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .forgot-header p {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 25px;
        }

        input[type="email"] {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
        }

        input[type="email"]:focus {
            outline: none;
            border-color: #667eea;
            background-color: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        input[type="email"]::placeholder {
            color: #a0a4a8;
        }

        .send-code-btn {
            width: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .send-code-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(102, 126, 234, 0.3);
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #c3e6cb;
            margin-bottom: 20px;
            text-align: center;
        }

        .success-message a {
            color: #155724;
            font-weight: 600;
            text-decoration: none;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #f5c6cb;
            margin-bottom: 20px;
            text-align: center;
        }

        .info-box {
            background: #e7f3ff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .info-box h3 {
            color: #333;
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-box p {
            color: #666;
            font-size: 13px;
            line-height: 1.4;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .forgot-container {
                padding: 30px 25px;
            }

            .forgot-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <div class="forgot-container">
        <div class="forgot-header">
            <h1>Forgot Password</h1>
            <p>Enter your registered email address and we'll send you a verification code to reset your password.</p>
        </div>

        <div class="info-box">
            <h3>📧 Email Verification</h3>
            <p>A 6-digit code will be sent to your email. The code expires in 10 minutes.</p>
        </div>

        <form method="POST">
            <div class="form-group">
                <input type="email" name="email" placeholder="Enter your registered email address" required>
            </div>

            <button type="submit" name="send_code" class="send-code-btn">Send Verification Code</button>
        </form>

        <div class="back-link">
            <a href="Login.php">← Back to Login</a>
        </div>
    </div>
</body>

</html>
