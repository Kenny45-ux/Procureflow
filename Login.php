<?php
session_start();
require_once 'config.php'; // Must contain your $pdo connection

// Configuration
define('MAX_FAILED_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 15 * 60); // 15 minutes

$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // Fetch user record
            $stmt = $pdo->prepare("
                SELECT * FROM users WHERE email = :email
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Check if account is locked
                if ($user['lockout_time'] && (time() - strtotime($user['lockout_time']) < LOCKOUT_DURATION)) {
                    $remaining = LOCKOUT_DURATION - (time() - strtotime($user['lockout_time']));
                    $error = "Account locked. Try again in " . ceil($remaining / 60) . " minutes.";
                } else {
                    // Verify password
                    if (password_verify($password, $user['password_hash'])) {
                        // Successful login: reset failed attempts
                        $update = $pdo->prepare("UPDATE users SET failed_attempts = 0, lockout_time = NULL WHERE id = :id");
                        $update->execute([':id' => $user['id']]);

                        // Set session
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];

                        // Redirect based on role
                        switch ($user['role']) {
                            case 'Admin':
                                header('Location: admin_dashboard.php');
                                exit();
                            case 'CFO':
                                header('Location: index.php');
                                exit();
                            case 'Vendor':
                                header('Location: vendor_dashboard.php');
                                exit();
                            default:
                                header('Location: index.php');
                                exit();
                        }
                    } else {
                        // Wrong password: increment failed attempts
                        $failed = $user['failed_attempts'] + 1;
                        $lockout_time = null;

                        if ($failed >= MAX_FAILED_ATTEMPTS) {
                            $lockout_time = date('Y-m-d H:i:s');
                            $error = "Too many failed attempts. Account locked for " . (LOCKOUT_DURATION / 60) . " minutes.";
                        } else {
                            $remaining = MAX_FAILED_ATTEMPTS - $failed;
                            $error = "Invalid password. $remaining attempts remaining.";
                        }

                        $update = $pdo->prepare("UPDATE users SET failed_attempts = :failed, lockout_time = :lock WHERE id = :id");
                        $update->execute([
                            ':failed' => $failed,
                            ':lock' => $lockout_time,
                            ':id' => $user['id']
                        ]);
                    }
                }
            } else {
                $error = "No account found with this email address.";
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ProcureFlow</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 450px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
        }
        
        .header {
            background: linear-gradient(135deg, #2c6bed 0%, #1a56c7 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        
        .logo {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 1rem;
        }
        
        .form-container {
            padding: 30px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
        }
        
        .alert i {
            margin-right: 10px;
        }
        
        .alert-error {
            background-color: #fde8e8;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background-color: #e6f4ea;
            color: #28a745;
            border: 1px solid #c3e6cb;
        }
        
        .alert-info {
            background-color: #e6f3ff;
            color: #0066cc;
            border: 1px solid #b3d9ff;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c6bed;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon input {
            width: 100%;
            padding: 14px 14px 14px 45px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .input-with-icon input:focus {
            border-color: #2c6bed;
            outline: none;
            box-shadow: 0 0 0 3px rgba(44, 107, 237, 0.2);
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: #2c6bed;
        }
        
        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            font-size: 14px;
        }
        
        .remember {
            display: flex;
            align-items: center;
        }
        
        .remember input {
            margin-right: 8px;
            accent-color: #2c6bed;
        }
        
        .forgot-password {
            color: #2c6bed;
            text-decoration: none;
            transition: color 0.3s ease;
            font-weight: 500;
        }
        
        .forgot-password:hover {
            color: #1a56c7;
            text-decoration: underline;
        }
        
        .login-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #2c6bed 0%, #1a56c7 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .login-btn:hover {
            background: linear-gradient(135deg, #1a56c7 0%, #2c6bed 100%);
            box-shadow: 0 4px 12px rgba(44, 107, 237, 0.3);
        }
        
        .login-btn:active {
            transform: scale(0.98);
        }
        
        .login-btn.loading {
            pointer-events: none;
            opacity: 0.8;
        }
        
        .login-btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.8s ease infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 25px 0;
            color: #a0aec0;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        
        .divider span {
            padding: 0 15px;
            font-size: 14px;
        }
        
        .signup-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #4a5568;
        }
        
        .signup-link a {
            color: #2c6bed;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .signup-link a:hover {
            color: #1a56c7;
            text-decoration: underline;
        }
        
        .security-notice {
            margin-top: 15px;
            padding: 10px;
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            font-size: 12px;
            color: #856404;
            text-align: center;
        }
        
        @media (max-width: 480px) {
            .login-container {
                max-width: 100%;
            }
            
            .form-container {
                padding: 20px;
            }
            
            .remember-forgot {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-file-contract"></i>
            </div>
            <h1>ProcureFlow Systems</h1>
            <p>Streamline your procurement process</p>
        </div>
        
        <div class="form-container">
            <?php if($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?=htmlspecialchars($error)?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['registered']) && $_GET['registered'] == 'true'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Registration successful! Please login.
                </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['logout']) && $_GET['logout'] == 'true'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    You have been successfully logged out.
                </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['reset']) && $_GET['reset'] == 'success'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Password reset successful! Please login with your new password.
                </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['session_expired']) && $_GET['session_expired'] == 'true'): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Your session has expired. Please login again.
                </div>
            <?php endif; ?>
            
            <form method="post" novalidate id="loginForm">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-with-icon">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" name="email" id="email" placeholder="Enter your email" required value="<?=isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" id="password" placeholder="Enter your password" required minlength="6">
                        <span class="password-toggle" id="passwordToggle">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                </div>
                
                <div class="remember-forgot">
                    <label class="remember">
                        <input type="checkbox" name="remember" id="remember"> Remember me
                    </label>
                    <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
                </div>
                
                <button type="submit" class="login-btn" id="loginBtn">
                    <span class="btn-text">Sign In</span>
                </button>
            </form>
            
            <div class="divider">
                <span>OR</span>
            </div>
            
            <div class="signup-link">
                Don't have an account? <a href="signup.php">Create Account</a>
            </div>

            <!-- Security Notice -->
            <div class="security-notice">
                <i class="fas fa-shield-alt"></i>
                Secure login system with account lockout protection
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('password');
        
        passwordToggle.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle eye icon
            const eyeIcon = this.querySelector('i');
            if (type === 'password') {
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            } else {
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            }
        });

        // Form submission loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const loginBtn = document.getElementById('loginBtn');
            const btnText = loginBtn.querySelector('.btn-text');
            
            // Show loading state
            loginBtn.classList.add('loading');
            btnText.textContent = 'Signing In...';
            
            // Basic client-side validation
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            
            if (!email || !password) {
                e.preventDefault();
                loginBtn.classList.remove('loading');
                btnText.textContent = 'Sign In';
            }
            
            // If remember me is checked, set a cookie (optional)
            const rememberMe = document.getElementById('remember').checked;
            if (rememberMe) {
                // You could set a cookie here for "remember me" functionality
                console.log('Remember me enabled');
            }
        });

        // Check if there's a stored email in localStorage (for "remember me" functionality)
        document.addEventListener('DOMContentLoaded', function() {
            const storedEmail = localStorage.getItem('remembered_email');
            if (storedEmail) {
                document.getElementById('email').value = storedEmail;
                document.getElementById('remember').checked = true;
            }
        });

        // Store email if "remember me" is checked
        document.getElementById('remember').addEventListener('change', function() {
            const email = document.getElementById('email').value;
            if (this.checked && email) {
                localStorage.setItem('remembered_email', email);
            } else {
                localStorage.removeItem('remembered_email');
            }
        });

        // Auto-focus on email field
        document.getElementById('email').focus();
    </script>
</body>
</html>