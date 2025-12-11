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

if (isset($_POST['verify'])) {
    $email = trim($_POST['email']);
    $code = trim($_POST['code']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE email=? AND reset_token=? AND reset_expires >= NOW()");
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $_SESSION['reset_email'] = $email;
        header("Location: reset_password.php");
        exit();
    } else {
        echo "<div class='error-message'>Invalid or expired code!</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code</title>
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

        .verify-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            transition: transform 0.3s ease;
        }

        .verify-container:hover {
            transform: translateY(-5px);
        }

        .verify-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .verify-header h1 {
            color: #333;
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .verify-header p {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 25px;
        }

        input[type="email"],
        input[type="text"] {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
            margin-bottom: 15px;
        }

        input[type="email"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            background-color: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        input[type="email"]::placeholder,
        input[type="text"]::placeholder {
            color: #a0a4a8;
        }

        .verify-btn {
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

        .verify-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(102, 126, 234, 0.3);
        }

        .verify-btn:active {
            transform: translateY(0);
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

        .code-input {
            letter-spacing: 8px;
            font-size: 18px !important;
            font-weight: 600;
            text-align: center;
        }

        .code-input::placeholder {
            letter-spacing: normal;
            font-weight: normal;
        }

        @media (max-width: 480px) {
            .verify-container {
                padding: 30px 25px;
            }

            .verify-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <div class="verify-container">
        <div class="verify-header">
            <h1>Verify Code</h1>
            <p>Enter the 6-digit verification code sent to your email</p>
        </div>

        <div class="info-box">
            <h3>🔒 Security Code</h3>
            <p>Check your email for the 6-digit code. The code expires in 10 minutes for security reasons.</p>
        </div>

        <form method="POST">
            <div class="form-group">
                <input type="email" name="email" placeholder="Enter your email address" required>
                <input type="text" name="code" placeholder="Enter 6-digit code" maxlength="6" required class="code-input">
            </div>

            <button type="submit" name="verify" class="verify-btn">Verify Code</button>
        </form>

        <div class="back-link">
            <a href="forgot_password.php">← Back to Forgot Password</a>
        </div>
    </div>
</body>

</html>
