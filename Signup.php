<?php
include 'config.php'; // PDO connection
require 'vendor/autoload.php'; // PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Start session for CSRF protection
session_start();

$error = '';
$success = '';

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Functions ---

function sendVendorApprovalEmail($email, $name) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'cfoprocureflow@gmail.com';
        $mail->Password = 'nwie dmub ugkf uqpd';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('noreply@procureflow.com', 'ProcureFlow');
        $mail->addAddress($email, $name);
        $mail->addReplyTo('support@procureflow.com', 'ProcureFlow Support');

        $mail->isHTML(true);
        $mail->Subject = 'Vendor Registration Under Review - ProcureFlow';
        $mail->Body = "<p>Hi $name,</p>
            <p>Your vendor registration is under review. You will be notified once approved.</p>
            <p>You can track your application status by logging into your account.</p>";
        $mail->AltBody = "Hi $name,\n\nYour vendor registration is under review. You will be notified once approved.\n\nYou can track your application status by logging into your account.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        file_put_contents('email_log.txt', "To: $email\nName: $name\nBody: " . $mail->Body . "\n\n", FILE_APPEND);
        return false;
    }
}

// --- Signup processing ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission.";
    } else {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm = $_POST['confirm'];
        $company_name = trim($_POST['company_name']);
        $phone = trim($_POST['phone']);
        $business_type = $_POST['business_type'] ?? '';
        $address = trim($_POST['address'] ?? '');
        $business_registration = trim($_POST['business_registration'] ?? '');
        $tax_id = trim($_POST['tax_id'] ?? '');
        $commodities = isset($_POST['commodities']) ? $_POST['commodities'] : [];
        $coverage = isset($_POST['coverage']) ? $_POST['coverage'] : [];

        if (empty($name)) {
            $error = "Please enter your name.";
        } elseif (empty($company_name)) {
            $error = "Please enter your company name.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif ($password !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            // Check if email exists
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email=?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = "Email already registered.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $verificationToken = bin2hex(random_bytes(50));

                $pdo->beginTransaction();
                try {
                    // Insert user
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status, verification_token) VALUES (?, ?, ?, 'Vendor', 'pending', ?)");
                    $stmt->execute([$name, $email, $hash, $verificationToken]);
                    $user_id = $pdo->lastInsertId();

                    // Insert into vendors table with enhanced fields
                    $stmt_check_vendors = $pdo->prepare("SHOW TABLES LIKE 'vendors'");
                    $stmt_check_vendors->execute();
                    if ($stmt_check_vendors->fetch()) {
                        // Check columns and insert with available fields
                        $stmt_check_cols = $pdo->prepare("SHOW COLUMNS FROM vendors");
                        $stmt_check_cols->execute();
                        $cols = $stmt_check_cols->fetchAll(PDO::FETCH_COLUMN);

                        $vendor_status = in_array('vendor_status', $cols) ? 'pending' : null;
                        $performance_score = in_array('performance_score', $cols) ? 0 : null;

                        // Build dynamic insert based on available columns
                        $insert_fields = ['user_id', 'vendor_name', 'contact_email', 'contact_phone', 'business_type', 'address'];
                        $insert_values = [$user_id, $company_name, $email, $phone, $business_type, $address];
                        $placeholders = ['?', '?', '?', '?', '?', '?'];

                        // Add enhanced fields if columns exist
                        $enhanced_fields = [
                            'business_registration' => $business_registration,
                            'tax_id' => $tax_id,
                            'commodities' => json_encode($commodities),
                            'geographic_coverage' => json_encode($coverage),
                            'vendor_status' => $vendor_status,
                            'performance_score' => $performance_score,
                            'onboarding_stage' => 'registered'
                        ];

                        foreach ($enhanced_fields as $field => $value) {
                            if (in_array($field, $cols)) {
                                $insert_fields[] = $field;
                                $insert_values[] = $value;
                                $placeholders[] = '?';
                            }
                        }

                        if (in_array('created_at', $cols)) {
                            $insert_fields[] = 'created_at';
                            $insert_values[] = date('Y-m-d H:i:s');
                            $placeholders[] = '?';
                        }

                        if (!empty($insert_fields)) {
                            $sql = "INSERT INTO vendors (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($insert_values);
                        }
                    }

                    $pdo->commit();

                    if (sendVendorApprovalEmail($email, $name)) {
                        $success = "Registration submitted! You will receive an email once approved.";
                    } else {
                        $success = "Registration submitted! Email could not be sent, but your request is recorded.";
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Registration failed. Please try again.";
                    error_log("Signup Error: " . $e->getMessage());
                }
            }
        }
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - ProcureFlow</title>
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
        
        .signup-container {
            width: 100%;
            max-width: 600px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .signup-container:hover {
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
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon input, .input-with-icon select, .input-with-icon textarea {
            width: 100%;
            padding: 14px 14px 14px 45px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .input-with-icon input:focus, .input-with-icon select:focus, .input-with-icon textarea:focus {
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
        
        .signup-btn {
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
        }
        
        .signup-btn:hover {
            background: linear-gradient(135deg, #1a56c7 0%, #2c6bed 100%);
            box-shadow: 0 4px 12px rgba(44, 107, 237, 0.3);
        }
        
        .signup-btn:active {
            transform: scale(0.98);
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #4a5568;
        }
        
        .login-link a {
            color: #2c6bed;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .login-link a:hover {
            color: #1a56c7;
            text-decoration: underline;
        }
        
        .email-note {
            font-size: 12px;
            color: #666;
            margin-top: 10px;
            text-align: center;
            font-style: italic;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .email-note i {
            margin-right: 5px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .form-section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .form-section h3 {
            color: #2c6bed;
            margin-bottom: 15px;
            font-size: 1.2rem;
        }
        
        .form-section:last-child {
            border-bottom: none;
        }
        
        select[multiple] {
            height: auto;
            min-height: 100px;
        }
        
        .form-help {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .signup-container {
                max-width: 100%;
            }
            
            .form-container {
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="signup-container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-file-contract"></i>
            </div>
            <h1>Join ProcureFlow as Vendor</h1>
            <p>Create your vendor account to get started</p>
        </div>
        
        <div class="form-container">
            <?php if($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?=htmlspecialchars($error)?>
                </div>
            <?php endif; ?>
            
            <?php if($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?=htmlspecialchars($success)?>
                </div>
                <div class="email-note">
                    <i class="fas fa-info-circle"></i> You'll receive an email once your account is approved.
                </div>
            <?php endif; ?>
            
            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                
                <div class="form-section">
                    <h3>Basic Information</h3>
                    
                    <div class="form-group">
                        <div class="input-with-icon">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" name="name" placeholder="Full Name" required value="<?=isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="input-with-icon">
                            <i class="fas fa-building input-icon"></i>
                            <input type="text" name="company_name" placeholder="Company Name" required value="<?=isset($_POST['company_name']) ? htmlspecialchars($_POST['company_name']) : ''?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="input-with-icon">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" name="email" placeholder="Email" required value="<?=isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <div class="input-with-icon">
                                <i class="fas fa-phone input-icon"></i>
                                <input type="tel" name="phone" placeholder="Phone Number" value="<?=isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="input-with-icon">
                                <i class="fas fa-briefcase input-icon"></i>
                                <select name="business_type">
                                    <option value="">Business Type</option>
                                    <option value="Manufacturer" <?=isset($_POST['business_type']) && $_POST['business_type'] == 'Manufacturer' ? 'selected' : ''?>>Manufacturer</option>
                                    <option value="Supplier" <?=isset($_POST['business_type']) && $_POST['business_type'] == 'Supplier' ? 'selected' : ''?>>Supplier</option>
                                    <option value="Service Provider" <?=isset($_POST['business_type']) && $_POST['business_type'] == 'Service Provider' ? 'selected' : ''?>>Service Provider</option>
                                    <option value="Distributor" <?=isset($_POST['business_type']) && $_POST['business_type'] == 'Distributor' ? 'selected' : ''?>>Distributor</option>
                                    <option value="Consultant" <?=isset($_POST['business_type']) && $_POST['business_type'] == 'Consultant' ? 'selected' : ''?>>Consultant</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="input-with-icon">
                            <i class="fas fa-map-marker-alt input-icon"></i>
                            <textarea name="address" placeholder="Business Address" rows="2"><?=isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''?></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Business Details</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <div class="input-with-icon">
                                <i class="fas fa-id-card input-icon"></i>
                                <input type="text" name="business_registration" placeholder="Business Registration Number" value="<?=isset($_POST['business_registration']) ? htmlspecialchars($_POST['business_registration']) : ''?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="input-with-icon">
                                <i class="fas fa-percent input-icon"></i>
                                <input type="text" name="tax_id" placeholder="Tax ID/VAT Number" value="<?=isset($_POST['tax_id']) ? htmlspecialchars($_POST['tax_id']) : ''?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="input-with-icon">
                            <i class="fas fa-industry input-icon"></i>
                            <select name="commodities[]" multiple>
                                <option value="IT Equipment">IT Equipment</option>
                                <option value="Office Supplies">Office Supplies</option>
                                <option value="Raw Materials">Raw Materials</option>
                                <option value="Professional Services">Professional Services</option>
                                <option value="Construction">Construction</option>
                                <option value="Logistics">Logistics</option>
                                <option value="Manufacturing">Manufacturing</option>
                                <option value="Consulting">Consulting</option>
                            </select>
                        </div>
                        <div class="form-help">Hold Ctrl to select multiple commodities</div>
                    </div>
                    
                    <div class="form-group">
                        <div class="input-with-icon">
                            <i class="fas fa-globe input-icon"></i>
                            <select name="coverage[]" multiple>
                                <option value="Local">Local</option>
                                <option value="Regional">Regional</option>
                                <option value="National">National</option>
                                <option value="International">International</option>
                            </select>
                        </div>
                        <div class="form-help">Select geographic coverage areas</div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Account Security</h3>
                    
                    <div class="form-group">
                        <div class="input-with-icon">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="password" id="password" placeholder="Password" required>
                            <span class="password-toggle" id="passwordToggle">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="input-with-icon">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="confirm" id="confirm" placeholder="Confirm Password" required>
                            <span class="password-toggle" id="confirmPasswordToggle">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="signup-btn">Sign Up as Vendor</button>
            </form>
            
            <div class="login-link">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('password');
        const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
        const confirmInput = document.getElementById('confirm');
        
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
        
        confirmPasswordToggle.addEventListener('click', function() {
            const type = confirmInput.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmInput.setAttribute('type', type);
            
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
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm').value;
            
            if (password !== confirm) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long!');
                return false;
            }
        });
    </script>
</body>
</html>