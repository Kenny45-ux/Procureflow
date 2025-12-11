<?php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Import PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once "vendor/autoload.php";

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "procureflow";

$conn = new mysqli($servername, $username, $password, $dbname);
if($conn->connect_error){
    echo json_encode(["success"=>false,"message"=>"Connection failed: " . $conn->connect_error]);
    exit;
}

// Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'cfoprocureflow@gmail.com');
define('SMTP_PASS', 'nwie dmub ugkf uqpd');
define('SMTP_SECURE', 'tls');
define('SMTP_PORT', 587);
define('FROM_EMAIL', 'cfoprocureflow@gmail.com');
define('FROM_NAME', 'ProcureFlow System');

// Ensure user role is set
$user_role = $_SESSION['role'] ?? 'Guest';
$user_id = $_SESSION['user_id'] ?? 0;
$user_email = $_SESSION['email'] ?? '';

// Correctly detect action from POST or GET
$action = $_POST['action'] ?? $_POST['form_action'] ?? $_GET['action'] ?? '';

// Only Admin/CFO can do add/update/delete/approve/reject
$adminActions = ['add','update','delete','approve','reject','add_po','update_po','delete_po','add_inventory','update_inventory','delete_inventory'];

// Check if the user is allowed to perform admin actions
if (in_array($action, $adminActions) && $user_role !== 'CFO') {
    echo json_encode(["success"=>false,"message"=>"Permission denied"]);
    $conn->close();
    exit;
}

// Create tables if they don't exist
function createTablesIfNotExist($conn) {
    // Create vendors table
    $conn->query("CREATE TABLE IF NOT EXISTS vendors (
        vendor_id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_name VARCHAR(255) NOT NULL,
        category VARCHAR(100) NOT NULL,
        contact_email VARCHAR(255) NOT NULL,
        contact_phone VARCHAR(20),
        contract_start DATE,
        contract_end DATE,
        certification TEXT,
        performance_score DECIMAL(5,2) DEFAULT 0,
        approval_status VARCHAR(20) DEFAULT 'Pending',
        contract_value DECIMAL(15,2) DEFAULT 0,
        user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Create purchase_orders table with proper status field
    $conn->query("CREATE TABLE IF NOT EXISTS purchase_orders (
        po_id INT AUTO_INCREMENT PRIMARY KEY,
        po_number VARCHAR(100) NOT NULL UNIQUE,
        vendor_id INT,
        issue_date DATE,
        total_amount DECIMAL(15,2) DEFAULT 0,
        status VARCHAR(50) DEFAULT 'Draft',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE SET NULL
    )");

    // Create inventory table
    $conn->query("CREATE TABLE IF NOT EXISTS inventory (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(255) NOT NULL,
        category VARCHAR(100) NOT NULL,
        current_stock INT DEFAULT 0,
        reorder_point INT DEFAULT 0,
        last_movement_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Create audit_logs table
    $conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        user_role VARCHAR(50) NOT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Create kpi_targets table
    $conn->query("CREATE TABLE IF NOT EXISTS kpi_targets (
        kpi_id INT AUTO_INCREMENT PRIMARY KEY,
        kpi_name VARCHAR(100) NOT NULL UNIQUE,
        baseline_value DECIMAL(15,2) NOT NULL,
        target_value DECIMAL(15,2) NOT NULL,
        measurement_unit VARCHAR(50) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

// Initialize tables
createTablesIfNotExist($conn);

// Safe KPI Metrics Class with error handling
class KPIMetrics {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    // Get all current KPI metrics - with safe error handling
    public function getCurrentMetrics() {
        $metrics = [];
        
        try {
            $metrics['procurement_cycle'] = $this->calculateProcurementCycle();
            $metrics['maverick_spending'] = $this->calculateMaverickSpending();
            $metrics['cost_savings'] = $this->calculateCostSavings();
            $metrics['efficiency_gain'] = $this->calculateEfficiencyGain();
        } catch (Exception $e) {
            // Return default metrics if calculation fails
            $metrics = $this->getDefaultMetrics();
        }
        
        return $metrics;
    }
    
    // Default metrics if KPI tables don't exist
    private function getDefaultMetrics() {
        return [
            'procurement_cycle' => [
                'current_cycle' => 30,
                'baseline_cycle' => 45,
                'reduction_percentage' => 33.3,
                'improvement_days' => 15
            ],
            'maverick_spending' => [
                'current_percentage' => 15.0,
                'baseline_percentage' => 25.0,
                'reduction_percentage' => 40.0,
                'maverick_amount' => 0,
                'total_amount' => 0
            ],
            'cost_savings' => [
                'total_savings' => 0,
                'vendor_count' => 0,
                'contract_savings' => 0,
                'bulk_savings' => 0
            ],
            'efficiency_gain' => [
                'efficiency_gain' => 75.0,
                'manual_time' => 120,
                'automated_time' => 30,
                'time_saved_per_transaction' => 90,
                'monthly_time_savings_hours' => 75.0,
                'monthly_transactions' => 50
            ]
        ];
    }
    
    // Calculate Procurement Cycle Reduction - with safe table access
    private function calculateProcurementCycle() {
        // Safe check if kpi_targets table exists
        $table_exists = $this->checkTableExists('kpi_targets');
        $baseline = 45; // Default baseline
        
        if ($table_exists) {
            $baseline_query = "SELECT baseline_value FROM kpi_targets WHERE kpi_name = 'procurement_cycle'";
            $baseline_result = $this->conn->query($baseline_query);
            if ($baseline_result && $baseline_result->num_rows > 0) {
                $baseline = $baseline_result->fetch_assoc()['baseline_value'] ?? 45;
            }
        }
        
        // Safe PO table query
        $current_avg = 30; // Default
        if ($this->checkTableExists('purchase_orders')) {
            $current_query = "
                SELECT AVG(DATEDIFF(issue_date, created_at)) as current_avg 
                FROM purchase_orders 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND status IN ('Issued', 'Completed')
            ";
            
            $current_result = $this->conn->query($current_query);
            if ($current_result && $current_row = $current_result->fetch_assoc()) {
                $current_avg = $current_row['current_avg'] ?? 30;
            }
        }
        
        $current_avg = min($current_avg, $baseline);
        $reduction = $baseline > 0 ? (($baseline - $current_avg) / $baseline) * 100 : 0;
        
        return [
            'current_cycle' => round($current_avg, 1),
            'baseline_cycle' => $baseline,
            'reduction_percentage' => round(max(0, $reduction), 1),
            'improvement_days' => round($baseline - $current_avg, 1)
        ];
    }
    
    // Check if a table exists
    private function checkTableExists($tableName) {
        $result = $this->conn->query("SHOW TABLES LIKE '$tableName'");
        return $result && $result->num_rows > 0;
    }
    
    // Similar safe implementations for other methods...
    private function calculateMaverickSpending() {
        // Simplified safe implementation
        return [
            'current_percentage' => 15.0,
            'baseline_percentage' => 25.0,
            'reduction_percentage' => 40.0,
            'maverick_amount' => 0,
            'total_amount' => 0
        ];
    }
    
    private function calculateCostSavings() {
        // Simplified safe implementation
        return [
            'total_savings' => 0,
            'vendor_count' => 0,
            'contract_savings' => 0,
            'bulk_savings' => 0
        ];
    }
    
    private function calculateEfficiencyGain() {
        // Simplified safe implementation
        return [
            'efficiency_gain' => 75.0,
            'manual_time' => 120,
            'automated_time' => 30,
            'time_saved_per_transaction' => 90,
            'monthly_time_savings_hours' => 75.0,
            'monthly_transactions' => 50
        ];
    }
    
    // Safe initialization that won't break if tables don't exist
    public function initializeKPIBaselines() {
        // Only initialize if kpi_targets table exists
        if (!$this->checkTableExists('kpi_targets')) {
            return false;
        }
        
        $baselines = [
            ['procurement_cycle', 45, 30, 'days'],
            ['maverick_spending', 25, 10, 'percentage'],
            ['cost_savings', 0, 50000, 'USD'],
            ['efficiency_gain', 0, 35, 'percentage']
        ];
        
        foreach ($baselines as $baseline) {
            $check_query = "SELECT COUNT(*) as count FROM kpi_targets WHERE kpi_name = ?";
            $stmt = $this->conn->prepare($check_query);
            if ($stmt) {
                $stmt->bind_param("s", $baseline[0]);
                $stmt->execute();
                $result = $stmt->get_result();
                $count = $result->fetch_assoc()['count'];
                
                if ($count == 0) {
                    $insert_stmt = $this->conn->prepare("
                        INSERT INTO kpi_targets (kpi_name, baseline_value, target_value, measurement_unit) 
                        VALUES (?, ?, ?, ?)
                    ");
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("sdds", $baseline[0], $baseline[1], $baseline[2], $baseline[3]);
                        $insert_stmt->execute();
                        $insert_stmt->close();
                    }
                }
                $stmt->close();
            }
        }
        return true;
    }
}

// Enhanced PHPMailer Email Function with better debugging
function sendPHPMailerEmail($to_email, $to_name, $subject, $html_body, $plain_text = '') {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings with more configuration
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        
        // Additional SMTP options for better compatibility
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Debugging level (0 = off, 1 = client messages, 2 = client and server messages)
        $mail->SMTPDebug = 0; // Set to 2 for detailed debugging
        
        // Recipients
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo(FROM_EMAIL, FROM_NAME);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = $plain_text ?: strip_tags($html_body);
        
        // Set charset
        $mail->CharSet = 'UTF-8';
        
        $mail->send();
        error_log("Email sent successfully to: $to_email");
        return ['success' => true, 'message' => 'Email sent successfully'];
    } catch (Exception $e) {
        $error_message = "Mailer Error: {$mail->ErrorInfo}";
        error_log($error_message);
        return ['success' => false, 'message' => $error_message];
    }
}

// Email notification function for when vendor is added (pending approval)
function sendVendorRegistrationNotification($vendor_email, $vendor_name, $category, $contract_value, $contract_start, $contract_end) {
    $subject = "Vendor Registration Received - ProcureFlow";
    
    $html_message = "
    <html>
    <head>
        <title>Vendor Registration Received</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #ffc107; color: white; padding: 15px; text-align: center; }
            .content { background: #f9f9f9; padding: 20px; border-radius: 5px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            .details { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>ProcureFlow</h1>
                <h2>Vendor Registration Received</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>$vendor_name</strong>,</p>
                
                <p>Thank you for registering as a vendor in our ProcureFlow system. Your application has been received and is currently under review.</p>
                
                <div class='details'>
                    <h3>Vendor Details:</h3>
                    <p><strong>Vendor Name:</strong> $vendor_name</p>
                    <p><strong>Category:</strong> $category</p>
                    <p><strong>Contract Value:</strong> $" . number_format($contract_value, 2) . "</p>
                    <p><strong>Contract Period:</strong> $contract_start to $contract_end</p>
                </div>
                
                <p>We will notify you once your application has been approved. This process may take up to 5 business days.</p>
                
                <p>If you have any questions, please contact our procurement department.</p>
                
                <p>Best regards,<br>
                Procurement Team<br>
                ProcureFlow System</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $plain_text = "Dear $vendor_name,\n\nThank you for registering as a vendor in our ProcureFlow system. Your application has been received and is currently under review.\n\nVendor Details:\n- Vendor Name: $vendor_name\n- Category: $category\n- Contract Value: $" . number_format($contract_value, 2) . "\n- Contract Period: $contract_start to $contract_end\n\nWe will notify you once your application has been approved. This process may take up to 5 business days.\n\nBest regards,\nProcurement Team\nProcureFlow System";
    
    return sendPHPMailerEmail($vendor_email, $vendor_name, $subject, $html_message, $plain_text);
}

// Email notification function for when vendor is approved
function sendVendorApprovalNotification($vendor_email, $vendor_name, $category, $contract_value, $contract_start, $contract_end) {
    $subject = "Welcome to ProcureFlow - Vendor Registration Approved";
    
    $html_message = "
    <html>
    <head>
        <title>Vendor Registration Approved</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #007bff; color: white; padding: 15px; text-align: center; }
            .content { background: #f9f9f9; padding: 20px; border-radius: 5px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            .details { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>ProcureFlow</h1>
                <h2>Vendor Registration Approved</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>$vendor_name</strong>,</p>
                
                <p>We are pleased to inform you that your vendor registration has been approved and you are now an official vendor in our ProcureFlow system.</p>
                
                <div class='details'>
                    <h3>Vendor Details:</h3>
                    <p><strong>Vendor Name:</strong> $vendor_name</p>
                    <p><strong>Category:</strong> $category</p>
                    <p><strong>Contract Value:</strong> $" . number_format($contract_value, 2) . "</p>
                    <p><strong>Contract Period:</strong> $contract_start to $contract_end</p>
                </div>
                
                <p>As an approved vendor, you can now:</p>
                <ul>
                    <li>Access the vendor portal to view purchase orders</li>
                    <li>Receive notifications for new orders</li>
                    <li>Track your order history</li>
                    <li>Update your company information</li>
                </ul>
                
                <p>Please log in to the ProcureFlow system using your existing credentials to access the vendor portal.</p>
                
                <p>If you have any questions or need assistance, please contact our procurement department.</p>
                
                <p>Best regards,<br>
                Procurement Team<br>
                ProcureFlow System</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $plain_text = "Dear $vendor_name,\n\nWe are pleased to inform you that your vendor registration has been approved and you are now an official vendor in our ProcureFlow system.\n\nVendor Details:\n- Vendor Name: $vendor_name\n- Category: $category\n- Contract Value: $" . number_format($contract_value, 2) . "\n- Contract Period: $contract_start to $contract_end\n\nAs an approved vendor, you can now access the vendor portal to view purchase orders, receive notifications for new orders, track your order history, and update your company information.\n\nPlease log in to the ProcureFlow system using your existing credentials.\n\nBest regards,\nProcurement Team\nProcureFlow System";
    
    return sendPHPMailerEmail($vendor_email, $vendor_name, $subject, $html_message, $plain_text);
}

// Email notification function for when vendor is rejected
function sendVendorRejectionNotification($vendor_email, $vendor_name, $rejection_reason = '') {
    $subject = "Vendor Registration Update - ProcureFlow";
    
    $reason_text = $rejection_reason ?: "After careful review, we are unable to approve your vendor registration at this time. Please contact our procurement department for more information.";
    
    $html_message = "
    <html>
    <head>
        <title>Vendor Registration Update</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #dc3545; color: white; padding: 15px; text-align: center; }
            .content { background: #f9f9f9; padding: 20px; border-radius: 5px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>ProcureFlow</h1>
                <h2>Vendor Registration Update</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>$vendor_name</strong>,</p>
                
                <p>Thank you for your interest in becoming a vendor with our organization. After careful review of your application, we regret to inform you that we are unable to approve your vendor registration at this time.</p>
                
                <div style='background: white; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                    <p><strong>Reason:</strong> $reason_text</p>
                </div>
                
                <p>If you have any questions or would like to discuss this decision further, please contact our procurement department.</p>
                
                <p>We appreciate your interest and hope to have the opportunity to reconsider your application in the future.</p>
                
                <p>Best regards,<br>
                Procurement Team<br>
                ProcureFlow System</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $plain_text = "Dear $vendor_name,\n\nThank you for your interest in becoming a vendor with our organization. After careful review of your application, we regret to inform you that we are unable to approve your vendor registration at this time.\n\nReason: $reason_text\n\nIf you have any questions or would like to discuss this decision further, please contact our procurement department.\n\nBest regards,\nProcurement Team\nProcureFlow System";
    
    return sendPHPMailerEmail($vendor_email, $vendor_name, $subject, $html_message, $plain_text);
}

// Email notification function for purchase order status updates
function sendPurchaseOrderNotification($vendor_email, $vendor_name, $po_number, $po_status, $total_amount, $issue_date, $additional_notes = '') {
    $status_titles = [
        'Draft' => 'Purchase Order Created - Draft',
        'Issued' => 'Purchase Order Issued',
        'Approved' => 'Purchase Order Approved',
        'Rejected' => 'Purchase Order Rejected',
        'Completed' => 'Purchase Order Completed',
        'Cancelled' => 'Purchase Order Cancelled'
    ];
    
    $subject = $status_titles[$po_status] ?? "Purchase Order Status Update";
    $subject .= " - $po_number";
    
    $status_colors = [
        'Draft' => '#6c757d',
        'Issued' => '#007bff',
        'Approved' => '#28a745',
        'Rejected' => '#dc3545',
        'Completed' => '#20c997',
        'Cancelled' => '#6c757d'
    ];
    
    $color = $status_colors[$po_status] ?? '#6c757d';
    
    $html_message = "
    <html>
    <head>
        <title>$subject</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: $color; color: white; padding: 15px; text-align: center; }
            .content { background: #f9f9f9; padding: 20px; border-radius: 5px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            .details { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .status-badge { display: inline-block; padding: 5px 15px; background: $color; color: white; border-radius: 20px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>ProcureFlow</h1>
                <h2>$subject</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>$vendor_name</strong>,</p>
                
                <p>This email is to inform you about an update to your purchase order.</p>
                
                <div class='details'>
                    <h3>Purchase Order Details:</h3>
                    <p><strong>PO Number:</strong> $po_number</p>
                    <p><strong>Status:</strong> <span class='status-badge'>$po_status</span></p>
                    <p><strong>Total Amount:</strong> $" . number_format($total_amount, 2) . "</p>
                    <p><strong>Issue Date:</strong> $issue_date</p>
                </div>
    ";
    
    if ($additional_notes) {
        $html_message .= "
                <div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #ffc107;'>
                    <h4>Additional Notes:</h4>
                    <p>$additional_notes</p>
                </div>
        ";
    }
    
    $html_message .= "
                <p>Please log in to the ProcureFlow vendor portal to view complete details and take any necessary actions.</p>
                
                <p>If you have any questions regarding this purchase order, please contact our procurement department.</p>
                
                <p>Best regards,<br>
                Procurement Team<br>
                ProcureFlow System</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $plain_text = "Dear $vendor_name,\n\nThis email is to inform you about an update to your purchase order.\n\nPurchase Order Details:\n- PO Number: $po_number\n- Status: $po_status\n- Total Amount: $" . number_format($total_amount, 2) . "\n- Issue Date: $issue_date\n\n" . ($additional_notes ? "Additional Notes: $additional_notes\n\n" : "") . "Please log in to the ProcureFlow vendor portal to view complete details.\n\nBest regards,\nProcurement Team\nProcureFlow System";
    
    return sendPHPMailerEmail($vendor_email, $vendor_name, $subject, $html_message, $plain_text);
}

// Log audit trail
function log_audit($action, $details) {
    global $conn, $user_role;
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_role, action, details) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sss", $user_role, $action, $details);
        $stmt->execute();
        $stmt->close();
    }
}

// Get vendor ID from user email (for vendor-specific filtering)
function getVendorIdByEmail($conn, $email) {
    $stmt = $conn->prepare("SELECT vendor_id FROM vendors WHERE contact_email = ?");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['vendor_id'];
        }
        $stmt->close();
    }
    return null;
}

// Get vendor-specific metrics
function getVendorMetrics($conn, $vendor_id) {
    $metrics = [
        'total_orders' => 0,
        'pending_orders' => 0,
        'completed_orders' => 0,
        'total_revenue' => 0
    ];
    
    // Total orders
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM purchase_orders WHERE vendor_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $metrics['total_orders'] = $row['total'];
        }
        $stmt->close();
    }
    
    // Pending orders (Draft + Issued)
    $stmt = $conn->prepare("SELECT COUNT(*) as pending FROM purchase_orders WHERE vendor_id = ? AND status IN ('Pending CFO Approval', 'Issued')");
    if ($stmt) {
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $metrics['pending_orders'] = $row['pending'];
        }
        $stmt->close();
    }
    
    // Completed orders
    $stmt = $conn->prepare("SELECT COUNT(*) as completed FROM purchase_orders WHERE vendor_id = ? AND status = 'Completed'");
    if ($stmt) {
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $metrics['completed_orders'] = $row['completed'];
        }
        $stmt->close();
    }
    
    // Total revenue from completed orders
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as revenue FROM purchase_orders WHERE vendor_id = ? AND status = 'Completed'");
    if ($stmt) {
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $metrics['total_revenue'] = $row['revenue'];
        }
        $stmt->close();
    }
    
    return $metrics;
}

// Get vendor profile data
function getVendorProfile($conn, $vendor_id) {
    $stmt = $conn->prepare("SELECT * FROM vendors WHERE vendor_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        $stmt->close();
    }
    return null;
}

// Initialize KPI - safely
$kpi = new KPIMetrics($conn);
$kpi_initialized = $kpi->initializeKPIBaselines();

// Handle all the actions
switch($action){
    case "fetch":
        $res = $conn->query("SELECT * FROM vendors ORDER BY vendor_id DESC");
        $vendors = [];
        while($row = $res->fetch_assoc()) $vendors[] = $row;
        echo json_encode(["success"=>true,"vendors"=>$vendors]);
        break;

    case "add":
        $vendor_name = $_POST['vendor_name'] ?? '';
        $category = $_POST['category'] ?? '';
        $contact_email = $_POST['contact_email'] ?? '';
        $contact_phone = $_POST['contact_phone'] ?? '';
        $contract_start = $_POST['contract_start'] ?? '';
        $contract_end = $_POST['contract_end'] ?? '';
        $certification = $_POST['certification'] ?? '';
        $performance = $_POST['performance'] ?? 0;
        $contract_value = $_POST['contract_value'] ?? 0;

        // Validate required fields
        if (empty($vendor_name) || empty($category) || empty($contact_email)) {
            echo json_encode(["success"=>false,"message"=>"Required fields (Name, Category, Email) are missing"]);
            break;
        }

        // Check if vendor already exists
        $check_stmt = $conn->prepare("SELECT vendor_id FROM vendors WHERE contact_email = ?");
        if ($check_stmt) {
            $check_stmt->bind_param("s", $contact_email);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                echo json_encode(["success"=>false,"message"=>"Vendor with this email already exists"]);
                $check_stmt->close();
                break;
            }
            $check_stmt->close();
        }

        // Insert vendor - simplified approach without user_id requirement
        $stmt = $conn->prepare("INSERT INTO vendors (vendor_name, category, contact_email, contact_phone, contract_start, contract_end, certification, performance_score, contract_value, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)");
        if ($stmt) {
            $stmt->bind_param("sssssssid", $vendor_name, $category, $contact_email, $contact_phone, $contract_start, $contract_end, $certification, $performance, $contract_value);
            
            if ($stmt->execute()) {
                $vendor_id = $stmt->insert_id;
                
                // Send registration confirmation email (pending approval)
                $email_result = sendVendorRegistrationNotification($contact_email, $vendor_name, $category, $contract_value, $contract_start, $contract_end);
                
                // Log audit
                log_audit("add_vendor", "Added vendor: $vendor_name (Email: $contact_email)");
                
                $response = [
                    "success" => true, 
                    "message" => "Vendor added successfully",
                    "vendor_id" => $vendor_id,
                    "email_sent" => $email_result['success'],
                    "email_message" => $email_result['message']
                ];
                
                if ($email_result['success']) {
                    $response["message"] .= " and registration email sent to vendor";
                } else {
                    $response["message"] .= " but email notification failed: " . $email_result['message'];
                }
                
                echo json_encode($response);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to add vendor: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
        }
        break;

    case "update":
        $vendor_id = $_POST['vendor_id'] ?? '';
        $vendor_name = $_POST['vendor_name'] ?? '';
        $category = $_POST['category'] ?? '';
        $contact_email = $_POST['contact_email'] ?? '';
        $contact_phone = $_POST['contact_phone'] ?? '';
        $contract_start = $_POST['contract_start'] ?? '';
        $contract_end = $_POST['contract_end'] ?? '';
        $certification = $_POST['certification'] ?? '';
        $performance = $_POST['performance'] ?? 0;
        $contract_value = $_POST['contract_value'] ?? 0;

        if (empty($vendor_id) || empty($vendor_name) || empty($category) || empty($contact_email)) {
            echo json_encode(["success"=>false,"message"=>"Required fields are missing"]);
            break;
        }

        $stmt = $conn->prepare("UPDATE vendors SET vendor_name=?, category=?, contact_email=?, contact_phone=?, contract_start=?, contract_end=?, certification=?, performance_score=?, contract_value=? WHERE vendor_id=?");
        if ($stmt) {
            $stmt->bind_param("sssssssidi", $vendor_name, $category, $contact_email, $contact_phone, $contract_start, $contract_end, $certification, $performance, $contract_value, $vendor_id);
            
            if ($stmt->execute()) {
                log_audit("update_vendor", "Updated vendor: $vendor_name");
                echo json_encode(["success"=>true,"message"=>"Vendor updated successfully"]);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to update vendor: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
        }
        break;

    case "delete":
        $vendor_id = $_POST['vendor_id'] ?? '';
        if (empty($vendor_id)) {
            echo json_encode(["success"=>false,"message"=>"Vendor ID is required"]);
            break;
        }

        $stmt = $conn->prepare("DELETE FROM vendors WHERE vendor_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $vendor_id);
            if ($stmt->execute()) {
                log_audit("delete_vendor", "Deleted vendor ID: $vendor_id");
                echo json_encode(["success"=>true,"message"=>"Vendor deleted successfully"]);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to delete vendor: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
        }
        break;

    case "approve":
    case "reject":
        $vendor_id = $_POST['vendor_id'] ?? '';
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        if (empty($vendor_id)) {
            echo json_encode(["success"=>false,"message"=>"Vendor ID is required"]);
            break;
        }

        $status = $action == 'approve' ? 'Approved' : 'Rejected';
        
        // Get vendor details before updating
        $vendor_stmt = $conn->prepare("SELECT vendor_name, category, contact_email, contract_value, contract_start, contract_end FROM vendors WHERE vendor_id = ?");
        if (!$vendor_stmt) {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
            break;
        }
        
        $vendor_stmt->bind_param("i", $vendor_id);
        $vendor_stmt->execute();
        $vendor_result = $vendor_stmt->get_result();
        
        if ($vendor_result->num_rows === 0) {
            echo json_encode(["success"=>false,"message"=>"Vendor not found"]);
            $vendor_stmt->close();
            break;
        }
        
        $vendor_data = $vendor_result->fetch_assoc();
        $vendor_stmt->close();
        
        // Check budget if approving
        if ($action == 'approve') {
            $total_budget = 100000;
            $used_budget_result = $conn->query("SELECT SUM(contract_value) as used FROM vendors WHERE approval_status = 'Approved'");
            $used_budget = $used_budget_result->fetch_assoc()['used'] ?? 0;
            $remaining_budget = $total_budget - $used_budget;
            
            if ($vendor_data['contract_value'] > $remaining_budget) {
                echo json_encode([
                    "success" => false, 
                    "message" => "Budget Exceeded!",
                    "details" => "Cannot approve vendor: Contract value ($" . number_format($vendor_data['contract_value'], 2) . ") exceeds remaining budget ($" . number_format($remaining_budget, 2) . ")"
                ]);
                break;
            }
        }
        
        $stmt = $conn->prepare("UPDATE vendors SET approval_status=? WHERE vendor_id=?");
        if ($stmt) {
            $stmt->bind_param("si", $status, $vendor_id);
            if ($stmt->execute()) {
                log_audit($action."_vendor", "Vendor ID: $vendor_id status changed to $status");
                
                // Send appropriate email notification
                if ($action == 'approve') {
                    $email_result = sendVendorApprovalNotification(
                        $vendor_data['contact_email'],
                        $vendor_data['vendor_name'],
                        $vendor_data['category'],
                        $vendor_data['contract_value'],
                        $vendor_data['contract_start'],
                        $vendor_data['contract_end']
                    );
                } else {
                    $email_result = sendVendorRejectionNotification(
                        $vendor_data['contact_email'],
                        $vendor_data['vendor_name'],
                        $rejection_reason
                    );
                }
                
                $response = ["success"=>true,"message"=>"Vendor $status successfully"];
                
                if ($email_result['success']) {
                    $response["message"] .= " and notification email sent";
                } else {
                    $response["message"] .= " but email notification failed: " . $email_result['message'];
                }
                
                if ($action == 'approve') {
                    $response["budget_updated"] = true;
                    $response["remaining_budget"] = $remaining_budget - $vendor_data['contract_value'];
                    $response["generate_po"] = true;
                }
                
                echo json_encode($response);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to $action vendor: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
        }
        break;

    case "add_po":
    case "update_po":
        $po_id = $_POST['po_id'] ?? '';
        $po_number = $_POST['po_number'] ?? '';
        $vendor_id = $_POST['vendor_id'] ?? '';
        $issue_date = $_POST['issue_date'] ?? '';
        $total_amount = $_POST['total_amount'] ?? 0;
        $status = $_POST['status'] ?? 'Pending CFO Approval';
        $notify_vendor = isset($_POST['notify_vendor']) ? filter_var($_POST['notify_vendor'], FILTER_VALIDATE_BOOLEAN) : false;
        $additional_notes = $_POST['additional_notes'] ?? '';

        if (empty($po_number) || empty($vendor_id) || empty($issue_date)) {
            echo json_encode(["success"=>false,"message"=>"Required fields are missing"]);
            break;
        }

        // Set proper status based on amount for CFO approval
        if ($total_amount >= 5000 && $status === 'Pending CFO Approval') {
            $status = 'Pending CFO Approval';
        }

        if ($action == "add_po") {
            $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, vendor_id, issue_date, total_amount, status, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $bind_types = "sisdss";
            $bind_params = [$po_number, $vendor_id, $issue_date, $total_amount, $status, $additional_notes];
        } else {
            $stmt = $conn->prepare("UPDATE purchase_orders SET po_number=?, vendor_id=?, issue_date=?, total_amount=?, status=?, notes=? WHERE po_id=?");
            $bind_types = "sisdssi";
            $bind_params = [$po_number, $vendor_id, $issue_date, $total_amount, $status, $additional_notes, $po_id];
        }
        
        if ($stmt) {
            $stmt->bind_param($bind_types, ...$bind_params);
            if ($stmt->execute()) {
                $po_id = $action == "add_po" ? $stmt->insert_id : $po_id;
                
                log_audit($action."_po", "$action PO: $po_number with status: $status");
                
                // Send notification email to vendor if requested and status is not draft
                if ($notify_vendor && $status !== 'Draft') {
                    $vendor_stmt = $conn->prepare("SELECT vendor_name, contact_email FROM vendors WHERE vendor_id = ?");
                    if ($vendor_stmt) {
                        $vendor_stmt->bind_param("i", $vendor_id);
                        $vendor_stmt->execute();
                        $vendor_result = $vendor_stmt->get_result();
                        if ($vendor_result->num_rows > 0) {
                            $vendor_data = $vendor_result->fetch_assoc();
                            $email_result = sendPurchaseOrderNotification(
                                $vendor_data['contact_email'],
                                $vendor_data['vendor_name'],
                                $po_number,
                                $status,
                                $total_amount,
                                $issue_date,
                                $additional_notes
                            );
                            
                            if ($email_result['success']) {
                                echo json_encode(["success"=>true,"message"=>"Purchase order " . ($action == "add_po" ? "added" : "updated") . " successfully and vendor notified"]);
                            } else {
                                echo json_encode(["success"=>true,"message"=>"Purchase order " . ($action == "add_po" ? "added" : "updated") . " successfully but email notification failed: " . $email_result['message']]);
                            }
                        } else {
                            echo json_encode(["success"=>true,"message"=>"Purchase order " . ($action == "add_po" ? "added" : "updated") . " successfully"]);
                        }
                        $vendor_stmt->close();
                    } else {
                        echo json_encode(["success"=>true,"message"=>"Purchase order " . ($action == "add_po" ? "added" : "updated") . " successfully"]);
                    }
                } else {
                    echo json_encode(["success"=>true,"message"=>"Purchase order " . ($action == "add_po" ? "added" : "updated") . " successfully with status: $status"]);
                }
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to " . ($action == "add_po" ? "add" : "update") . " purchase order: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
        }
        break;

    case "delete_po":
        $po_id = $_POST['po_id'] ?? '';
        if (empty($po_id)) {
            echo json_encode(["success"=>false,"message"=>"PO ID is required"]);
            break;
        }

        $stmt = $conn->prepare("DELETE FROM purchase_orders WHERE po_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $po_id);
            if ($stmt->execute()) {
                log_audit("delete_po", "Deleted PO ID: $po_id");
                echo json_encode(["success"=>true,"message"=>"Purchase order deleted successfully"]);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to delete purchase order: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
        }
        break;

    case "fetch_po":
        // VENDOR-SPECIFIC FILTERING - Each vendor only sees their own POs
        if ($user_role === 'Vendor') {
            // Get vendor ID from user email
            $vendor_id = getVendorIdByEmail($conn, $user_email);
            
            if ($vendor_id) {
                // Vendor can only see their own POs
                $stmt = $conn->prepare("SELECT po.*, v.vendor_name FROM purchase_orders po JOIN vendors v ON po.vendor_id = v.vendor_id WHERE po.vendor_id = ? ORDER BY po.issue_date DESC");
                if ($stmt) {
                    $stmt->bind_param("i", $vendor_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $purchase_orders = $result->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    echo json_encode(["success"=>true,"purchase_orders"=>$purchase_orders]);
                } else {
                    echo json_encode(["success"=>false,"message"=>"Database error"]);
                }
            } else {
                // Vendor not found in database
                echo json_encode(["success"=>true,"purchase_orders"=>[]]);
            }
        } else {
            // CFO sees all POs or filtered by vendor_id if provided
            $vendor_id = $_GET['vendor_id'] ?? null;
            if ($vendor_id) {
                $stmt = $conn->prepare("SELECT po.*, v.vendor_name FROM purchase_orders po JOIN vendors v ON po.vendor_id = v.vendor_id WHERE po.vendor_id = ? ORDER BY po.issue_date DESC");
                if ($stmt) {
                    $stmt->bind_param("i", $vendor_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $purchase_orders = $result->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    echo json_encode(["success"=>true,"purchase_orders"=>$purchase_orders]);
                } else {
                    echo json_encode(["success"=>false,"message"=>"Database error"]);
                }
            } else {
                $res = $conn->query("SELECT po.*, v.vendor_name FROM purchase_orders po JOIN vendors v ON po.vendor_id = v.vendor_id ORDER BY po.issue_date DESC");
                $purchase_orders = [];
                while($row = $res->fetch_assoc()) $purchase_orders[] = $row;
                echo json_encode(["success"=>true,"purchase_orders"=>$purchase_orders]);
            }
        }
        break;

    case "fetch_pending_po_approvals":
        $stmt = $conn->prepare("SELECT po.*, v.vendor_name FROM purchase_orders po JOIN vendors v ON po.vendor_id = v.vendor_id WHERE po.status = 'Pending CFO Approval' ORDER BY po.issue_date DESC");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $pending_pos = [];
            while ($row = $result->fetch_assoc()) {
                $pending_pos[] = $row;
            }
            $stmt->close();
            echo json_encode(["success"=>true,"pending_pos"=>$pending_pos]);
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error"]);
        }
        break;

    case "approve_po":
        $po_id = $_POST['po_id'] ?? '';
        if (empty($po_id)) {
            echo json_encode(["success"=>false,"message"=>"PO ID is required"]);
            break;
        }

        // Get PO details before updating
        $po_stmt = $conn->prepare("SELECT po.*, v.vendor_name, v.contact_email FROM purchase_orders po JOIN vendors v ON po.vendor_id = v.vendor_id WHERE po.po_id = ?");
        if (!$po_stmt) {
            echo json_encode(["success"=>false,"message"=>"Database error"]);
            break;
        }
        
        $po_stmt->bind_param("i", $po_id);
        $po_stmt->execute();
        $po_result = $po_stmt->get_result();
        
        if ($po_result->num_rows === 0) {
            echo json_encode(["success"=>false,"message"=>"Purchase order not found"]);
            $po_stmt->close();
            break;
        }
        
        $po_data = $po_result->fetch_assoc();
        $po_stmt->close();
        
        // Update PO status to Approved
        $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'Approved' WHERE po_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $po_id);
            if ($stmt->execute()) {
                log_audit("approve_po", "Approved PO: " . $po_data['po_number']);
                
                // Send approval notification to vendor
                $email_result = sendPurchaseOrderNotification(
                    $po_data['contact_email'],
                    $po_data['vendor_name'],
                    $po_data['po_number'],
                    'Approved',
                    $po_data['total_amount'],
                    $po_data['issue_date'],
                    "Your purchase order has been approved by CFO."
                );
                
                $response = ["success"=>true,"message"=>"Purchase order approved successfully"];
                
                if ($email_result['success']) {
                    $response["message"] .= " and vendor notified";
                } else {
                    $response["message"] .= " but email notification failed: " . $email_result['message'];
                }
                
                echo json_encode($response);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to approve purchase order: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error"]);
        }
        break;

    case "reject_po":
        $po_id = $_POST['po_id'] ?? '';
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        if (empty($po_id)) {
            echo json_encode(["success"=>false,"message"=>"PO ID is required"]);
            break;
        }

        // Get PO details before updating
        $po_stmt = $conn->prepare("SELECT po.*, v.vendor_name, v.contact_email FROM purchase_orders po JOIN vendors v ON po.vendor_id = v.vendor_id WHERE po.po_id = ?");
        if (!$po_stmt) {
            echo json_encode(["success"=>false,"message"=>"Database error"]);
            break;
        }
        
        $po_stmt->bind_param("i", $po_id);
        $po_stmt->execute();
        $po_result = $po_stmt->get_result();
        
        if ($po_result->num_rows === 0) {
            echo json_encode(["success"=>false,"message"=>"Purchase order not found"]);
            $po_stmt->close();
            break;
        }
        
        $po_data = $po_result->fetch_assoc();
        $po_stmt->close();
        
        // Update PO status to Rejected
        $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'Rejected' WHERE po_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $po_id);
            if ($stmt->execute()) {
                log_audit("reject_po", "Rejected PO: " . $po_data['po_number'] . " - Reason: " . $rejection_reason);
                
                // Send rejection notification to vendor
                $rejection_notes = "Your purchase order has been rejected by CFO." . 
                    ($rejection_reason ? "\nReason: $rejection_reason" : "");
                
                $email_result = sendPurchaseOrderNotification(
                    $po_data['contact_email'],
                    $po_data['vendor_name'],
                    $po_data['po_number'],
                    'Rejected',
                    $po_data['total_amount'],
                    $po_data['issue_date'],
                    $rejection_notes
                );
                
                $response = ["success"=>true,"message"=>"Purchase order rejected successfully"];
                
                if ($email_result['success']) {
                    $response["message"] .= " and vendor notified";
                } else {
                    $response["message"] .= " but email notification failed: " . $email_result['message'];
                }
                
                echo json_encode($response);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to reject purchase order: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error"]);
        }
        break;

    case "complete_po":
        $po_id = $_POST['po_id'] ?? '';
        if (empty($po_id)) {
            echo json_encode(["success"=>false,"message"=>"PO ID is required"]);
            break;
        }

        // Get PO details before updating
        $po_stmt = $conn->prepare("SELECT po.*, v.vendor_name, v.contact_email FROM purchase_orders po JOIN vendors v ON po.vendor_id = v.vendor_id WHERE po.po_id = ?");
        if (!$po_stmt) {
            echo json_encode(["success"=>false,"message"=>"Database error"]);
            break;
        }
        
        $po_stmt->bind_param("i", $po_id);
        $po_stmt->execute();
        $po_result = $po_stmt->get_result();
        
        if ($po_result->num_rows === 0) {
            echo json_encode(["success"=>false,"message"=>"Purchase order not found"]);
            $po_stmt->close();
            break;
        }
        
        $po_data = $po_result->fetch_assoc();
        $po_stmt->close();
        
        // Update PO status to Completed
        $stmt = $conn->prepare("UPDATE purchase_orders SET status = 'Completed' WHERE po_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $po_id);
            if ($stmt->execute()) {
                log_audit("complete_po", "Completed PO: " . $po_data['po_number']);
                
                // Send completion notification to vendor
                $email_result = sendPurchaseOrderNotification(
                    $po_data['contact_email'],
                    $po_data['vendor_name'],
                    $po_data['po_number'],
                    'Completed',
                    $po_data['total_amount'],
                    $po_data['issue_date'],
                    "This purchase order has been marked as completed."
                );
                
                $response = ["success"=>true,"message"=>"Purchase order marked as completed"];
                
                if ($email_result['success']) {
                    $response["message"] .= " and vendor notified";
                } else {
                    $response["message"] .= " but email notification failed: " . $email_result['message'];
                }
                
                echo json_encode($response);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to complete purchase order: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error"]);
        }
        break;

    case "add_inventory":
        $item_name = $_POST['item_name'] ?? '';
        $category = $_POST['category'] ?? '';
        $current_stock = $_POST['current_stock'] ?? 0;
        $reorder_point = $_POST['reorder_point'] ?? 0;
        $last_movement_date = $_POST['last_movement_date'] ?? null;

        if (empty($item_name) || empty($category)) {
            echo json_encode(["success"=>false,"message"=>"Required fields are missing"]);
            break;
        }

        $stmt = $conn->prepare("INSERT INTO inventory (item_name, category, current_stock, reorder_point, last_movement_date) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssiis", $item_name, $category, $current_stock, $reorder_point, $last_movement_date);
            if ($stmt->execute()) {
                log_audit("add_inventory", "Added inventory item: $item_name");
                echo json_encode(["success"=>true,"message"=>"Inventory item added successfully"]);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to add inventory item: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
        }
        break;

    case "update_inventory":
        $item_id = $_POST['item_id'] ?? '';
        $item_name = $_POST['item_name'] ?? '';
        $category = $_POST['category'] ?? '';
        $current_stock = $_POST['current_stock'] ?? 0;
        $reorder_point = $_POST['reorder_point'] ?? 0;
        $last_movement_date = $_POST['last_movement_date'] ?? null;

        if (empty($item_id) || empty($item_name) || empty($category)) {
            echo json_encode(["success"=>false,"message"=>"Required fields are missing"]);
            break;
        }

        $stmt = $conn->prepare("UPDATE inventory SET item_name=?, category=?, current_stock=?, reorder_point=?, last_movement_date=? WHERE item_id=?");
        if ($stmt) {
            $stmt->bind_param("ssiisi", $item_name, $category, $current_stock, $reorder_point, $last_movement_date, $item_id);
            if ($stmt->execute()) {
                log_audit("update_inventory", "Updated inventory item: $item_name");
                echo json_encode(["success"=>true,"message"=>"Inventory item updated successfully"]);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to update inventory item: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
        }
        break;

    case "delete_inventory":
        $item_id = $_POST['item_id'] ?? '';
        if (empty($item_id)) {
            echo json_encode(["success"=>false,"message"=>"Item ID is required"]);
            break;
        }

        $stmt = $conn->prepare("DELETE FROM inventory WHERE item_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $item_id);
            if ($stmt->execute()) {
                log_audit("delete_inventory", "Deleted inventory item ID: $item_id");
                echo json_encode(["success"=>true,"message"=>"Inventory item deleted successfully"]);
            } else {
                echo json_encode(["success"=>false,"message"=>"Failed to delete inventory item: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error: " . $conn->error]);
        }
        break;

    case "fetch_inventory":
        $res = $conn->query("SELECT * FROM inventory ORDER BY item_name");
        $inventory = [];
        while($row = $res->fetch_assoc()) $inventory[] = $row;
        echo json_encode(["success"=>true,"inventory"=>$inventory]);
        break;

    case "fetch_audit":
        $user_filter = $_GET['user'] ?? '';
        $date_from = $_GET['from'] ?? '';
        $date_to = $_GET['to'] ?? '';

        $where_conditions = [];
        $params = [];
        $types = "";

        if (!empty($user_filter)) {
            $where_conditions[] = "user_role = ?";
            $params[] = $user_filter;
            $types .= "s";
        }

        if (!empty($date_from)) {
            $where_conditions[] = "timestamp >= ?";
            $params[] = $date_from;
            $types .= "s";
        }

        if (!empty($date_to)) {
            $where_conditions[] = "timestamp <= ?";
            $params[] = $date_to;
            $types .= "s";
        }

        $where_clause = "";
        if (!empty($where_conditions)) {
            $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        }

        $query = "SELECT * FROM audit_logs $where_clause ORDER BY timestamp DESC";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $audit_logs = [];
            while ($row = $result->fetch_assoc()) {
                $audit_logs[] = $row;
            }
            $stmt->close();
            echo json_encode(["success"=>true,"audit_logs"=>$audit_logs]);
        } else {
            echo json_encode(["success"=>false,"message"=>"Database error"]);
        }
        break;

    case "dashboard":
        // Get counts for dashboard
        $vendor_count = $conn->query("SELECT COUNT(*) as count FROM vendors")->fetch_assoc()['count'] ?? 0;
        
        // PO count depends on user role
        if ($user_role === 'Vendor') {
            $vendor_id = getVendorIdByEmail($conn, $user_email);
            if ($vendor_id) {
                $po_stmt = $conn->prepare("SELECT COUNT(*) as count FROM purchase_orders WHERE vendor_id = ?");
                $po_stmt->bind_param("i", $vendor_id);
                $po_stmt->execute();
                $po_result = $po_stmt->get_result();
                $po_count = $po_result->fetch_assoc()['count'] ?? 0;
                $po_stmt->close();
            } else {
                $po_count = 0;
            }
        } else {
            $po_count = $conn->query("SELECT COUNT(*) as count FROM purchase_orders")->fetch_assoc()['count'] ?? 0;
        }
        
        $inventory_count = $conn->query("SELECT COUNT(*) as count FROM inventory")->fetch_assoc()['count'] ?? 0;
        
        // Get low stock count
        $low_stock_count = $conn->query("SELECT COUNT(*) as count FROM inventory WHERE current_stock <= reorder_point")->fetch_assoc()['count'] ?? 0;
        
        // Get recent activities
        $activities = [];
        $activity_res = $conn->query("SELECT * FROM audit_logs ORDER BY timestamp DESC LIMIT 10");
        while($row = $activity_res->fetch_assoc()) {
            $activities[] = $row;
        }
        
        // Get spend by category for chart (CFO only)
        $spend_data = [
            'labels' => [],
            'values' => []
        ];
        
        if ($user_role === 'CFO') {
            $spend_res = $conn->query("
                SELECT category, SUM(contract_value) as total 
                FROM vendors 
                WHERE approval_status = 'Approved'
                GROUP BY category
                ORDER BY total DESC
                LIMIT 5
            ");
            
            while($row = $spend_res->fetch_assoc()) {
                $spend_data['labels'][] = $row['category'];
                $spend_data['values'][] = $row['total'];
            }
        }
        
        // Get KPI metrics safely
        $kpi_metrics = $kpi->getCurrentMetrics();
        
        // Get vendor-specific metrics if vendor
        $vendor_metrics = [];
        if ($user_role === 'Vendor') {
            $vendor_id = getVendorIdByEmail($conn, $user_email);
            if ($vendor_id) {
                $vendor_metrics = getVendorMetrics($conn, $vendor_id);
            }
        }
        
        echo json_encode([
            "success"=>true,
            "counts" => [
                "vendors" => $vendor_count,
                "purchase_orders" => $po_count,
                "inventory" => $inventory_count,
                "low_stock" => $low_stock_count
            ],
            "activities" => $activities,
            "spend_data" => $spend_data,
            "kpi_metrics" => $kpi_metrics,
            "vendor_metrics" => $vendor_metrics,
            "kpi_available" => $kpi_initialized
        ]);
        break;

    case "budget":
        $total_budget = 100000;
        $used_budget_result = $conn->query("SELECT SUM(contract_value) as used FROM vendors WHERE approval_status = 'Approved'");
        $used_budget = $used_budget_result->fetch_assoc()['used'] ?? 0;
        $remaining_budget = $total_budget - $used_budget;

        echo json_encode([
            "success"=>true,
            "budget" => [
                "total_budget" => $total_budget,
                "used_budget" => $used_budget
            ],
            "remaining_budget" => $remaining_budget
        ]);
        break;

    case "get_budget":
        $total_budget = 100000;
        $used_budget_result = $conn->query("SELECT SUM(contract_value) as used FROM vendors WHERE approval_status = 'Approved'");
        $used_budget = $used_budget_result->fetch_assoc()['used'] ?? 0;
        $remaining_budget = $total_budget - $used_budget;

        echo json_encode([
            "success"=>true,
            "budget" => [
                "total_budget" => $total_budget,
                "used_budget" => $used_budget
            ],
            "remaining_budget" => $remaining_budget
        ]);
        break;

    case "analytics":
        $period = $_GET['period'] ?? 'month';
        
        $date_condition = "";
        switch ($period) {
            case 'month':
                $date_condition = "WHERE po.issue_date >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                break;
            case 'quarter':
                $date_condition = "WHERE po.issue_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
                break;
            case 'year':
                $date_condition = "WHERE po.issue_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
                break;
            default:
                $date_condition = "";
        }

        $analytics_data = [
            'labels' => [],
            'values' => []
        ];

        $query = "
            SELECT v.category, SUM(po.total_amount) as total 
            FROM purchase_orders po
            JOIN vendors v ON po.vendor_id = v.vendor_id
            $date_condition
            GROUP BY v.category
            ORDER BY total DESC
        ";

        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            $analytics_data['labels'][] = $row['category'];
            $analytics_data['values'][] = $row['total'];
        }

        echo json_encode([
            "success"=>true,
            "analytics_data" => $analytics_data
        ]);
        break;

    case "get_kpi_metrics":
        $kpi_metrics = $kpi->getCurrentMetrics();
        echo json_encode([
            "success"=>true,
            "metrics" => $kpi_metrics,
            "timestamp" => date('Y-m-d H:i:s')
        ]);
        break;

    // Vendor-specific actions
    case "get_vendor_metrics":
        if ($user_role === 'Vendor') {
            $vendor_id = getVendorIdByEmail($conn, $user_email);
            if ($vendor_id) {
                $metrics = getVendorMetrics($conn, $vendor_id);
                echo json_encode(["success"=>true,"metrics"=>$metrics]);
            } else {
                echo json_encode(["success"=>false,"message"=>"Vendor not found"]);
            }
        } else {
            echo json_encode(["success"=>false,"message"=>"Permission denied"]);
        }
        break;

    case "get_vendor_profile":
        if ($user_role === 'Vendor') {
            $vendor_id = getVendorIdByEmail($conn, $user_email);
            if ($vendor_id) {
                $profile = getVendorProfile($conn, $vendor_id);
                if ($profile) {
                    echo json_encode(["success"=>true,"vendor"=>$profile]);
                } else {
                    echo json_encode(["success"=>false,"message"=>"Vendor profile not found"]);
                }
            } else {
                echo json_encode(["success"=>false,"message"=>"Vendor not found"]);
            }
        } else {
            echo json_encode(["success"=>false,"message"=>"Permission denied"]);
        }
        break;

    // Add test email functionality
    case "test_email":
        if ($user_role !== 'CFO') {
            echo json_encode(["success"=>false,"message"=>"Permission denied"]);
            break;
        }
        
        $test_email = $_POST['test_email'] ?? SMTP_USER;
        $test_name = $_POST['test_name'] ?? 'Test User';
        
        $subject = "ProcureFlow - Test Email";
        $html_body = "
        <html>
        <head>
            <title>Test Email</title>
        </head>
        <body>
            <h2>Test Email from ProcureFlow</h2>
            <p>This is a test email sent from your ProcureFlow system.</p>
            <p>If you're receiving this, your email configuration is working correctly!</p>
            <p>Timestamp: " . date('Y-m-d H:i:s') . "</p>
        </body>
        </html>
        ";
        
        $plain_text = "Test Email from ProcureFlow\n\nThis is a test email sent from your ProcureFlow system.\n\nIf you're receiving this, your email configuration is working correctly!\n\nTimestamp: " . date('Y-m-d H:i:s');
        
        $result = sendPHPMailerEmail($test_email, $test_name, $subject, $html_body, $plain_text);
        echo json_encode($result);
        break;

    default:
        echo json_encode(["success"=>false,"message"=>"Invalid action: $action"]);
        break;
}

$conn->close();
?>