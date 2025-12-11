<?php
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header('Location: Login.php');
    exit();
}

$userRole = $_SESSION['role'];
$userId = $_SESSION['user_id'] ?? 0;
$userEmail = $_SESSION['email'] ?? '';
$userName = $_SESSION['name'] ?? 'Procurement Officer';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "procureflow";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Include performance calculator
include 'vendor_performance_calculator.php';

// Include inventory manager
include 'inventory_manager.php';

// Check for low stock items and send alerts (only check once per hour to avoid spam)
$last_alert_check = $_SESSION['last_alert_check'] ?? 0;
$current_time = time();

// Check every hour (3600 seconds)
if ($current_time - $last_alert_check > 3600) {
    $alerts_sent = checkAndSendLowStockAlerts($conn);
    
    if ($alerts_sent > 0) {
        error_log("Sent $alerts_sent low stock alert(s) to procurement department");
    }
    
    $_SESSION['last_alert_check'] = $current_time;
}

// Handle AJAX requests for vendor data - must be before any redirects
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_vendor_data') {
    // For AJAX requests, don't redirect, just return JSON error if not authorized
    if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $vendor_id = intval($_GET['vendor_id']);
    
    try {
        // Fetch vendor basic info including certifications
        $stmt = $conn->prepare("SELECT v.*, u.name as user_name, u.email as user_email FROM vendors v JOIN users u ON v.user_id = u.id WHERE v.vendor_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $vendor_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $vendor_result = $stmt->get_result();
        $vendor = $vendor_result->fetch_assoc();
        $stmt->close();
        
        if (!$vendor) {
            throw new Exception("Vendor not found");
        }
        
        // Parse certifications from the certifications column
        $certifications = [];
        if (!empty($vendor['certifications'])) {
            // Assuming certifications are stored as JSON or comma-separated
            $certs_data = json_decode($vendor['certifications'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $certifications = $certs_data;
            } else {
                // If not JSON, treat as comma-separated
                $cert_list = explode(',', $vendor['certifications']);
                foreach ($cert_list as $cert) {
                    $certifications[] = ['certification_name' => trim($cert)];
                }
            }
        }
        
        // Calculate vendor performance
        $performance_data = calculateVendorPerformance($vendor_id, $conn);
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'basic_info' => $vendor,
            'certifications' => $certifications,
            'performance' => $performance_data
        ]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// AJAX for inventory data
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_inventory_data') {
    if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $product_id = intval($_GET['product_id']);
    
    try {
        // Fetch product details
        $stmt = $conn->prepare("SELECT p.*, v.vendor_name 
                               FROM products p 
                               LEFT JOIN vendors v ON p.preferred_vendor_id = v.vendor_id 
                               WHERE p.product_id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $product_result = $stmt->get_result();
        $product = $product_result->fetch_assoc();
        $stmt->close();
        
        if (!$product) {
            throw new Exception("Product not found");
        }
        
        // Get inventory movements
        $stmt = $conn->prepare("SELECT * FROM inventory_movements 
                               WHERE product_id = ? 
                               ORDER BY created_at DESC 
                               LIMIT 10");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $movements_result = $stmt->get_result();
        $movements = [];
        while ($row = $movements_result->fetch_assoc()) {
            $movements[] = $row;
        }
        $stmt->close();
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'product' => $product,
            'movements' => $movements
        ]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// AJAX for vendor products
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_vendor_products') {
    if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $vendor_id = intval($_GET['vendor_id']);
    
    try {
        // Fetch vendor products
        $stmt = $conn->prepare("SELECT * FROM vendor_products WHERE vendor_id = ? AND is_active = 1 ORDER BY product_name");
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $stmt->close();
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'products' => $products
        ]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Function to generate unique PO number
function generatePONumber($conn) {
    $prefix = "PO";
    $year = date('Y');
    $month = date('m');
    
    // Get the latest PO number for this year and month
    $stmt = $conn->prepare("SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY po_id DESC LIMIT 1");
    $likePattern = $prefix . $year . $month . '%';
    $stmt->bind_param("s", $likePattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $lastPO = $result->fetch_assoc();
        $lastNumber = intval(substr($lastPO['po_number'], -4));
        $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $newNumber = '0001';
    }
    
    $stmt->close();
    return $prefix . $year . $month . $newNumber;
}

// Helper function to get vendor name
function getVendorName($conn, $vendor_id) {
    $stmt = $conn->prepare("SELECT vendor_name FROM vendors WHERE vendor_id = ?");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['vendor_name'];
    }
    return 'Unknown Vendor';
}

// Email notification function for PO creation - Notify both CFO and Vendor
function sendPONotifications($conn, $po_number, $vendor_id, $total_amount, $issue_date, $product_name, $quantity, $unit_price, $delivery_date, $notes = '', $status = 'Pending CFO Approval') {
    require 'vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Get vendor details
        $vendor_stmt = $conn->prepare("SELECT vendor_name, contact_email FROM vendors WHERE vendor_id = ?");
        $vendor_stmt->bind_param("i", $vendor_id);
        $vendor_stmt->execute();
        $vendor_result = $vendor_stmt->get_result();
        
        if ($vendor_result->num_rows === 0) {
            error_log("Vendor not found for ID: $vendor_id");
            return false;
        }
        
        $vendor = $vendor_result->fetch_assoc();
        $vendor_name = $vendor['vendor_name'];
        $vendor_email = $vendor['contact_email'];
        $vendor_stmt->close();
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'procurementdepartmenta@gmail.com';
        $mail->Password = 'jsdb wluy rfdq kjzm';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Set from address as admin email
        $mail->setFrom('procurementdepartmenta@gmail.com', 'Procurement Department');
        $mail->addReplyTo('procurementdepartmenta@gmail.com', 'Procurement Department');

        // Send to CFO if amount requires approval
        if ($total_amount >= 5000) {
            $cfo_email = 'cfoprocureflow@gmail.com';
            
            $mail->clearAddresses();
            $mail->addAddress($cfo_email, 'CFO');
            
            $mail->Subject = "Purchase Order Approval Required - $po_number";
            
            $html_message = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #ffc107; color: #333; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; border: 1px solid #e2e8f0; }
                    .details { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                    .status { display: inline-block; padding: 5px 10px; border-radius: 3px; font-weight: bold; }
                    .status-pending { background: #fff3cd; color: #856404; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>ProcureFlow - PO Approval Required</h1>
                    </div>
                    <div class='content'>
                        <h2>Purchase Order Requires Your Approval</h2>
                        <p>A new purchase order has been created that requires your approval.</p>
                        
                        <div class='details'>
                            <h3>PO Details:</h3>
                            <p><strong>PO Number:</strong> $po_number</p>
                            <p><strong>Vendor:</strong> $vendor_name</p>
                            <p><strong>Total Amount:</strong> ZMW " . number_format($total_amount, 2) . "</p>
                            <p><strong>Issue Date:</strong> $issue_date</p>
                            <p><strong>Status:</strong> <span class='status status-pending'>$status</span></p>
                            <p><strong>Product:</strong> $product_name</p>
                            <p><strong>Quantity:</strong> $quantity</p>
                            <p><strong>Unit Price:</strong> ZMW " . number_format($unit_price, 2) . "</p>
                            <p><strong>Delivery Date:</strong> $delivery_date</p>
                            " . (!empty($notes) ? "<p><strong>Notes:</strong> $notes</p>" : "") . "
                        </div>
                        
                        <p>Please log in to the ProcureFlow CFO Dashboard to review and approve this purchase order.</p>
                        
                        <p><a href='http://localhost/procureflow/index.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Review Purchase Order</a></p>
                        
                        <p>Best regards,<br>Procurement Department<br>ProcureFlow</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $plain_text = "Purchase Order Approval Required\n\nPO Number: $po_number\nVendor: $vendor_name\nTotal Amount: ZMW " . number_format($total_amount, 2) . "\nIssue Date: $issue_date\nStatus: $status\nProduct: $product_name\nQuantity: $quantity\nUnit Price: ZMW " . number_format($unit_price, 2) . "\nDelivery Date: $delivery_date" . (!empty($notes) ? "\nNotes: $notes" : "") . "\n\nPlease log in to the ProcureFlow CFO Dashboard to review and approve this purchase order.\n\nBest regards,\nProcurement Department\nProcureFlow";

            $mail->Body = $html_message;
            $mail->AltBody = $plain_text;

            $cfo_result = $mail->send();
            
            // Reset for vendor email
            $mail->clearAddresses();
        }
        
        // Send to Vendor
        $mail->addAddress($vendor_email, $vendor_name);
        
        $mail->Subject = "New Purchase Order Received - $po_number";
        
        $html_message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; border: 1px solid #e2e8f0; }
                .details { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .status { display: inline-block; padding: 5px 10px; border-radius: 3px; font-weight: bold; }
                .status-pending { background: #fff3cd; color: #856404; }
                .status-approved { background: #d4edda; color: #155724; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>New Purchase Order - ProcureFlow</h1>
                </div>
                <div class='content'>
                    <h2>Dear $vendor_name,</h2>
                    <p>You have received a new purchase order from our procurement department.</p>
                    
                    <div class='details'>
                        <h3>PO Details:</h3>
                        <p><strong>PO Number:</strong> $po_number</p>
                        <p><strong>Company:</strong> ProcureFlow</p>
                        <p><strong>Total Amount:</strong> ZMW " . number_format($total_amount, 2) . "</p>
                        <p><strong>Issue Date:</strong> $issue_date</p>
                        <p><strong>Status:</strong> <span class='status " . ($status === 'Approved' ? 'status-approved' : 'status-pending') . "'>$status</span></p>
                        <p><strong>Product:</strong> $product_name</p>
                        <p><strong>Quantity:</strong> $quantity</p>
                        <p><strong>Unit Price:</strong> ZMW " . number_format($unit_price, 2) . "</p>
                        <p><strong>Delivery Date:</strong> $delivery_date</p>
                        " . (!empty($notes) ? "<p><strong>Notes:</strong> $notes</p>" : "") . "
                    </div>
                    
                    <p><strong>Important:</strong> " . ($status === 'Pending CFO Approval' 
                        ? "This purchase order is pending CFO approval. We will notify you once it's approved." 
                        : "This purchase order has been approved. Please proceed with fulfillment.") . "</p>
                    
                    <p>Please log in to your vendor dashboard to acknowledge this purchase order and provide pricing.</p>
                    
                    <p><a href='http://localhost/procureflow/vendor_dashboard.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>View Purchase Order</a></p>
                    
                    <p>Best regards,<br>Procurement Department<br>ProcureFlow</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $status_note = $status === 'Pending CFO Approval' 
            ? "This purchase order is pending CFO approval. We will notify you once it's approved." 
            : "This purchase order has been approved. Please proceed with fulfillment.";
            
        $plain_text = "New Purchase Order Received\n\nPO Number: $po_number\nCompany: ProcureFlow\nTotal Amount: ZMW " . number_format($total_amount, 2) . "\nIssue Date: $issue_date\nStatus: $status\nProduct: $product_name\nQuantity: $quantity\nUnit Price: ZMW " . number_format($unit_price, 2) . "\nDelivery Date: $delivery_date" . (!empty($notes) ? "\nNotes: $notes" : "") . "\n\nImportant: $status_note\n\nPlease log in to your vendor dashboard to acknowledge this purchase order and provide pricing.\n\nBest regards,\nProcurement Department\nProcureFlow";

        $mail->Body = $html_message;
        $mail->AltBody = $plain_text;

        $vendor_result = $mail->send();
        
        return [
            'cfo_sent' => isset($cfo_result) ? $cfo_result : true,
            'vendor_sent' => $vendor_result
        ];
        
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        return false;
    }
}

// Email notification function for vendor status updates
function sendVendorStatusEmail($vendor_email, $vendor_name, $status, $reason = '') {
    require 'vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'cfoprocureflow@gmail.com';
        $mail->Password = 'nwie dmub ugkf uqpd';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('procurementdepartment@gmail.com', 'Procurement Department');
        $mail->addAddress($vendor_email, $vendor_name);
        $mail->addReplyTo('procurementdepartment@gmail.com', 'Procurement Department');

        // Content
        $mail->isHTML(true);
        
        if ($status === 'approved') {
            $mail->Subject = "Vendor Registration Approved - ProcureFlow";
            
            $html_message = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; border: 1px solid #e2e8f0; }
                    .details { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Vendor Registration Approved</h1>
                    </div>
                    <div class='content'>
                        <h2>Congratulations, $vendor_name!</h2>
                        <p>Your vendor registration with ProcureFlow has been <strong>approved</strong>.</p>
                        
                        <div class='details'>
                            <h3>What's Next?</h3>
                            <p>You can now:</p>
                            <ul>
                                <li>Log in to your vendor dashboard</li>
                                <li>View and respond to purchase orders</li>
                                <li>Update your company profile</li>
                                <li>Manage your products and services</li>
                            </ul>
                        </div>
                        
                        <p><a href='http://localhost/procureflow/index.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Access Your Dashboard</a></p>
                        
                        <p>If you have any questions, please contact our support team.</p>
                        
                        <p>Best regards,<br>Procurement Department<br>ProcureFlow</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $plain_text = "Vendor Registration Approved\n\nCongratulations, $vendor_name!\n\nYour vendor registration with ProcureFlow has been approved.\n\nWhat's Next?\nYou can now:\n- Log in to your vendor dashboard\n- View and respond to purchase orders\n- Update your company profile\n- Manage your products and services\n\nAccess Your Dashboard: http://localhost/procureflow/index.php\n\nIf you have any questions, please contact our support team.\n\nBest regards,\nProcurement Department\nProcureFlow";
            
        } else { // rejected
            $mail->Subject = "Vendor Registration Update - ProcureFlow";
            
            $reason_text = !empty($reason) ? "<p><strong>Reason:</strong> $reason</p>" : "";
            
            $html_message = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; border: 1px solid #e2e8f0; }
                    .details { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Vendor Registration Update</h1>
                    </div>
                    <div class='content'>
                        <h2>Vendor Registration Status Update</h2>
                        <p>Dear $vendor_name,</p>
                        
                        <div class='details'>
                            <p>After reviewing your vendor registration application, we regret to inform you that your registration has been <strong>rejected</strong>.</p>
                            $reason_text
                            <p>You may submit a new application with updated information if you wish to be reconsidered.</p>
                        </div>
                        
                        <p>If you believe this is an error or would like more information, please contact our procurement team.</p>
                        
                        <p>Best regards,<br>Procurement Department<br>ProcureFlow</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $reason_plain = !empty($reason) ? "Reason: $reason\n" : "";
            $plain_text = "Vendor Registration Update\n\nDear $vendor_name,\n\nAfter reviewing your vendor registration application, we regret to inform you that your registration has been rejected.\n\n$reason_plain\nYou may submit a new application with updated information if you wish to be reconsidered.\n\nIf you believe this is an error or would like more information, please contact our procurement team.\n\nBest regards,\nProcurement Department\nProcureFlow";
        }

        $mail->Body = $html_message;
        $mail->AltBody = $plain_text;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (Vendor Status): " . $e->getMessage());
        return false;
    }
}

// Email notification function for low stock alerts
function sendLowStockEmail($conn, $product_id, $product_name, $current_stock, $reorder_level) {
    require 'vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'procurementdepartmenta@gmail.com';
        $mail->Password = 'jsdb wluy rfdq kjzm';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('procurementdepartmenta@gmail.com', 'Procurement Department');
        $mail->addAddress('procurementdepartmenta@gmail.com', 'Procurement Department');
        $mail->addReplyTo('procurementdepartmenta@gmail.com', 'Procurement Department');

        // Get product details for more context
        $product_stmt = $conn->prepare("SELECT p.*, v.vendor_name, v.contact_email 
                                       FROM products p 
                                       LEFT JOIN vendors v ON p.preferred_vendor_id = v.vendor_id 
                                       WHERE p.product_id = ?");
        $product_stmt->bind_param("i", $product_id);
        $product_stmt->execute();
        $product_result = $product_stmt->get_result();
        $product_details = $product_result->fetch_assoc();
        $product_stmt->close();

        $vendor_name = $product_details['vendor_name'] ?? 'Not specified';
        $vendor_email = $product_details['contact_email'] ?? 'Not specified';
        $category = $product_details['category'] ?? 'N/A';
        $safety_stock = $product_details['safety_stock'] ?? 0;
        $sku = $product_details['sku'] ?? 'N/A';

        // Content
        $mail->isHTML(true);
        $mail->Subject = "LOW STOCK ALERT: $product_name - ProcureFlow";
        
        $stock_status = 'CRITICAL';
        $status_color = '#dc3545';
        if ($current_stock <= $safety_stock) {
            $stock_status = 'CRITICAL - Below Safety Stock';
            $status_color = '#dc3545';
        } elseif ($current_stock <= $reorder_level) {
            $stock_status = 'LOW - At Reorder Level';
            $status_color = '#ffc107';
        }

        $html_message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; border: 1px solid #e2e8f0; }
                .alert-box { background: #fff3cd; border: 1px solid #ffeaa7; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; border-radius: 5px; }
                .critical-alert { background: #f8d7da; border: 1px solid #f5c6cb; border-left: 4px solid #dc3545; }
                .details { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .status-badge { display: inline-block; padding: 8px 15px; border-radius: 20px; font-weight: bold; color: white; }
                .action-buttons { margin-top: 20px; text-align: center; }
                .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 0 5px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1><i class='fas fa-exclamation-triangle'></i> Low Stock Alert</h1>
                </div>
                <div class='content'>
                    <div class='alert-box " . ($current_stock <= $safety_stock ? 'critical-alert' : '') . "'>
                        <h2 style='margin: 0; color: " . $status_color . ";'>$stock_status</h2>
                        <p style='margin: 10px 0 0 0; font-size: 16px;'><strong>$product_name</strong> is running low on stock and requires immediate attention.</p>
                    </div>
                    
                    <div class='details'>
                        <h3>Product Details:</h3>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Product Name:</strong></td>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'>$product_name</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>SKU:</strong></td>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'>$sku</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Category:</strong></td>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'>$category</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Current Stock:</strong></td>
                                <td style='padding: 8px; border-bottom: 1px solid #eee; color: #dc3545; font-weight: bold;'>$current_stock units</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Reorder Level:</strong></td>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'>$reorder_level units</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Safety Stock:</strong></td>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'>$safety_stock units</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Preferred Vendor:</strong></td>
                                <td style='padding: 8px; border-bottom: 1px solid #eee;'>$vendor_name</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px;'><strong>Vendor Contact:</strong></td>
                                <td style='padding: 8px;'>$vendor_email</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class='action-buttons'>
                        <p><strong>Recommended Actions:</strong></p>
                        <a href='http://localhost/procureflow/admin_dashboard.php' class='btn' style='background: #28a745;'>View Dashboard</a>
                        <a href='http://localhost/procureflow/admin_dashboard.php' class='btn' style='background: #ffc107; color: #212529;'>Create Purchase Order</a>
                        <a href='http://localhost/procureflow/admin_dashboard.php' class='btn' style='background: #17a2b8;'>Adjust Inventory</a>
                    </div>
                    
                    <p style='margin-top: 20px; font-size: 12px; color: #666;'>
                        This is an automated alert from ProcureFlow Inventory Management System.<br>
                        Please take appropriate action to replenish stock to avoid disruptions.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $plain_text = "LOW STOCK ALERT: $product_name\n\n"
                    . "Stock Status: $stock_status\n"
                    . "Current Stock: $current_stock units\n"
                    . "Reorder Level: $reorder_level units\n"
                    . "Safety Stock: $safety_stock units\n"
                    . "SKU: $sku\n"
                    . "Category: $category\n"
                    . "Preferred Vendor: $vendor_name\n"
                    . "Vendor Contact: $vendor_email\n\n"
                    . "Please log in to the ProcureFlow dashboard to create a purchase order or adjust inventory.\n\n"
                    . "This is an automated alert from ProcureFlow Inventory Management System.";

        $mail->Body = $html_message;
        $mail->AltBody = $plain_text;

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("PHPMailer Error (Low Stock Alert): " . $e->getMessage());
        return false;
    }
}

// Function to check for low stock items and send alerts
function checkAndSendLowStockAlerts($conn) {
    $low_stock_alerts = getLowStockAlerts($conn);
    $alerts_sent = 0;
    
    foreach ($low_stock_alerts as $alert) {
        // Check if we've already sent an alert for this product recently (within 24 hours)
        $recent_alert_stmt = $conn->prepare("SELECT COUNT(*) as alert_count FROM low_stock_alerts 
                                           WHERE product_id = ? AND alert_sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $recent_alert_stmt->bind_param("i", $alert['product_id']);
        $recent_alert_stmt->execute();
        $recent_result = $recent_alert_stmt->get_result();
        $recent_data = $recent_result->fetch_assoc();
        $recent_alert_stmt->close();
        
        // Only send alert if we haven't sent one in the last 24 hours
        if ($recent_data['alert_count'] == 0) {
            $email_sent = sendLowStockEmail($conn, $alert['product_id'], $alert['product_name'], 
                                          $alert['current_stock'], $alert['reorder_level']);
            
            if ($email_sent) {
                // Record that we sent this alert
                $record_stmt = $conn->prepare("INSERT INTO low_stock_alerts (product_id, current_stock, reorder_level, alert_sent_at) 
                                             VALUES (?, ?, ?, NOW())");
                $record_stmt->bind_param("iii", $alert['product_id'], $alert['current_stock'], $alert['reorder_level']);
                $record_stmt->execute();
                $record_stmt->close();
                
                $alerts_sent++;
            }
        }
    }
    
    return $alerts_sent;
}

// Handle vendor approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['vendor_id'])) {
        $vendor_id = intval($_POST['vendor_id']);
        $action = $_POST['action'];
        $reason = $_POST['reason'] ?? '';
        
        if (in_array($action, ['approve', 'reject'])) {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            
            // Get vendor details before update
            $stmt = $conn->prepare("SELECT v.*, u.email, u.name, u.id as user_id FROM vendors v JOIN users u ON v.user_id = u.id WHERE v.vendor_id = ?");
            $stmt->bind_param("i", $vendor_id);
            $stmt->execute();
            $vendor_result = $stmt->get_result();
            
            if ($vendor_result->num_rows > 0) {
                $vendor = $vendor_result->fetch_assoc();
                $stmt->close();
                
                // Update vendor status (removed onboarding_completed_at)
                if ($action === 'approve') {
                    $stmt = $conn->prepare("UPDATE vendors SET vendor_status = ?, onboarding_stage = ? WHERE vendor_id = ?");
                    $onboarding_stage = 'approved';
                    $stmt->bind_param("ssi", $status, $onboarding_stage, $vendor_id);
                } else {
                    $stmt = $conn->prepare("UPDATE vendors SET vendor_status = ?, onboarding_stage = ? WHERE vendor_id = ?");
                    $onboarding_stage = 'rejected';
                    $stmt->bind_param("ssi", $status, $onboarding_stage, $vendor_id);
                }
                
                if ($stmt->execute()) {
                    // Also update user status
                    $user_stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
                    $user_stmt->bind_param("si", $status, $vendor['user_id']);
                    $user_stmt->execute();
                    $user_stmt->close();
                    
                    // Send email notification to vendor
                    $email_result = sendVendorStatusEmail(
                        $vendor['email'], 
                        $vendor['vendor_name'], 
                        $status, 
                        $reason
                    );
                    
                    if ($email_result) {
                        $_SESSION['success_message'] = "Vendor $status successfully! Notification email sent to vendor.";
                    } else {
                        $_SESSION['success_message'] = "Vendor $status successfully! (Email notification failed)";
                    }
                } else {
                    $_SESSION['error_message'] = "Error updating vendor status: " . $conn->error;
                }
                $stmt->close();
            } else {
                $_SESSION['error_message'] = "Vendor not found!";
            }
            
            header('Location: admin_dashboard.php');
            exit();
        }
    }
    
    // Handle vendor contract creation
    if (isset($_POST['create_vendor_contract'])) {
        $vendor_id = intval($_POST['vendor_id']);
        $contract_start = $_POST['contract_start'];
        $contract_end = $_POST['contract_end'];
        $contract_value = floatval($_POST['contract_value']);
        $payment_terms = $_POST['payment_terms'];
        
        // Update vendor contract information
        $stmt = $conn->prepare("UPDATE vendors SET 
            contract_start = ?, 
            contract_end = ?, 
            contract_value = ?,
            payment_terms = ?,
            onboarding_stage = 'contracted',
            updated_at = NOW()
            WHERE vendor_id = ?");
        
        $stmt->bind_param("ssdsi", 
            $contract_start, 
            $contract_end, 
            $contract_value, 
            $payment_terms,
            $vendor_id
        );
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Vendor contract created successfully!";
        } else {
            $_SESSION['error_message'] = "Error creating vendor contract: " . $conn->error;
        }
        $stmt->close();
        
        header('Location: admin_dashboard.php');
        exit();
    }
    
    // Handle purchase order creation
    if (isset($_POST['create_purchase_order'])) {
        $vendor_id = intval($_POST['vendor_id']);
        $product_name = $_POST['product_name'];
        $quantity = intval($_POST['quantity']);
        $delivery_date = $_POST['delivery_date'];
        $notes = $_POST['notes'] ?? '';
        
        // Generate PO number
        $po_number = generatePONumber($conn);
        $issue_date = date('Y-m-d');
        
        // Initial status - vendor needs to provide pricing
        $status = 'Pending Vendor Pricing';
        $total_amount = 0; // Will be set by vendor
        
        $stmt = $conn->prepare("INSERT INTO purchase_orders (po_number, vendor_id, issue_date, total_amount, status, expected_delivery_date, product_name, quantity, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisdsssis", $po_number, $vendor_id, $issue_date, $total_amount, $status, $delivery_date, $product_name, $quantity, $notes);
        
        if ($stmt->execute()) {
            $po_id = $stmt->insert_id;
            
            // Send email notifications to Vendor only (no CFO notification yet)
            $email_results = sendPONotifications(
                $conn,
                $po_number, 
                $vendor_id, 
                $total_amount, 
                $issue_date, 
                $product_name, 
                $quantity, 
                0, // Unit price not set yet
                $delivery_date,
                $notes,
                $status
            );
            
            if ($email_results !== false) {
                $_SESSION['success_message'] = "Purchase order $po_number created successfully! Vendor has been notified to provide pricing.";
            } else {
                $_SESSION['success_message'] = "Purchase order $po_number created successfully! Vendor notification failed.";
            }
        } else {
            $_SESSION['error_message'] = "Error creating purchase order: " . $conn->error;
        }
        $stmt->close();
        
        header('Location: admin_dashboard.php');
        exit();
    }
    
    // Handle delivery recording
    if (isset($_POST['record_delivery'])) {
        $po_id = intval($_POST['po_id']);
        $actual_delivery_date = $_POST['actual_delivery_date'];
        $quality_status = $_POST['quality_status'];
        $defect_count = intval($_POST['defect_count'] ?? 0);
        $total_items = intval($_POST['total_items'] ?? 1);
        $delivery_notes = $_POST['delivery_notes'] ?? '';
        
        // Calculate defect rate
        $defect_rate = $total_items > 0 ? ($defect_count / $total_items) * 100 : 0;
        
        // Determine delivery status
        $expected_stmt = $conn->prepare("SELECT expected_delivery_date FROM purchase_orders WHERE po_id = ?");
        $expected_stmt->bind_param("i", $po_id);
        $expected_stmt->execute();
        $expected_result = $expected_stmt->get_result();
        $expected_data = $expected_result->fetch_assoc();
        $expected_stmt->close();
        
        $delivery_status = 'delivered';
        if ($actual_delivery_date > $expected_data['expected_delivery_date']) {
            $delivery_status = 'late';
        }
        
        // Update purchase order with delivery information
        $update_sql = "UPDATE purchase_orders SET 
            actual_delivery_date = ?, 
            delivery_status = ?,
            quality_status = ?,
            defect_count = ?,
            total_items = ?,
            defect_rate = ?,
            delivery_notes = ?,
            status = 'Completed',
            updated_at = NOW()
            WHERE po_id = ?";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sssiidsi", 
            $actual_delivery_date, 
            $delivery_status,
            $quality_status,
            $defect_count,
            $total_items,
            $defect_rate,
            $delivery_notes,
            $po_id
        );
        
        if ($stmt->execute()) {
            // Get vendor ID for performance update
            $vendor_stmt = $conn->prepare("SELECT vendor_id FROM purchase_orders WHERE po_id = ?");
            $vendor_stmt->bind_param("i", $po_id);
            $vendor_stmt->execute();
            $vendor_result = $vendor_stmt->get_result();
            $vendor_data = $vendor_result->fetch_assoc();
            $vendor_stmt->close();
            
            if ($vendor_data) {
                // Recalculate vendor performance
                calculateVendorPerformance($vendor_data['vendor_id'], $conn);
            }
            
            $_SESSION['success_message'] = "Delivery recorded successfully! Vendor performance metrics updated.";
        } else {
            $_SESSION['error_message'] = "Error recording delivery: " . $conn->error;
        }
        $stmt->close();
        
        header('Location: admin_dashboard.php');
        exit();
    }
    
    // Handle inventory updates
    if (isset($_POST['update_inventory'])) {
        $product_id = intval($_POST['product_id']);
        $adjustment_type = $_POST['adjustment_type'];
        $quantity = intval($_POST['quantity']);
        $notes = $_POST['notes'] ?? '';
        
        // Get current stock level before update for comparison
        $current_stmt = $conn->prepare("SELECT current_stock, reorder_level, safety_stock, product_name FROM products WHERE product_id = ?");
        $current_stmt->bind_param("i", $product_id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        $current_data = $current_result->fetch_assoc();
        $current_stmt->close();
        
        $old_stock = $current_data['current_stock'];
        $reorder_level = $current_data['reorder_level'];
        $safety_stock = $current_data['safety_stock'];
        $product_name = $current_data['product_name'];
        
        // Update inventory
        $result = updateInventory($conn, $product_id, $adjustment_type, $quantity, $notes, $userId);
        
        if ($result['success']) {
            $new_stock = $result['new_stock'];
            
            // Check if stock is now low and send email alert
            if ($new_stock <= $reorder_level && $old_stock > $reorder_level) {
                // Stock just dropped to or below reorder level
                $email_sent = sendLowStockEmail($conn, $product_id, $product_name, $new_stock, $reorder_level);
                
                if ($email_sent) {
                    $_SESSION['success_message'] = "Inventory updated successfully! Stock level: " . $new_stock . ". Low stock alert sent to procurement department.";
                } else {
                    $_SESSION['success_message'] = "Inventory updated successfully! Stock level: " . $new_stock . ". Low stock alert failed to send.";
                }
            } else {
                $_SESSION['success_message'] = "Inventory updated successfully! Stock level: " . $new_stock;
            }
        } else {
            $_SESSION['error_message'] = "Error updating inventory: " . $result['error'];
        }
        
        header('Location: admin_dashboard.php');
        exit();
    }
    
    // Handle product creation
    if (isset($_POST['create_product'])) {
        $product_name = $_POST['product_name'];
        $description = $_POST['description'] ?? '';
        $category = $_POST['category'];
        $sku = $_POST['sku'];
        $reorder_level = intval($_POST['reorder_level']);
        $safety_stock = intval($_POST['safety_stock']);
        $unit_cost = floatval($_POST['unit_cost']);
        $preferred_vendor_id = intval($_POST['preferred_vendor_id']) ?: NULL;
        
        $stmt = $conn->prepare("INSERT INTO products (product_name, description, category, sku, reorder_level, safety_stock, unit_cost, preferred_vendor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssiidi", $product_name, $description, $category, $sku, $reorder_level, $safety_stock, $unit_cost, $preferred_vendor_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Product created successfully!";
        } else {
            $_SESSION['error_message'] = "Error creating product: " . $conn->error;
        }
        $stmt->close();
        
        header('Location: admin_dashboard.php');
        exit();
    }
    
    // Handle dead stock resolution
    if (isset($_POST['resolve_dead_stock'])) {
        $alert_id = intval($_POST['alert_id']);
        $resolution_action = $_POST['resolution_action'];
        $resolution_notes = $_POST['resolution_notes'] ?? '';
        
        $stmt = $conn->prepare("UPDATE dead_stock_alerts SET is_resolved = TRUE, resolved_at = NOW(), resolved_by = ?, resolution_notes = ? WHERE alert_id = ?");
        $stmt->bind_param("isi", $userId, $resolution_notes, $alert_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Dead stock alert resolved successfully!";
        } else {
            $_SESSION['error_message'] = "Error resolving dead stock alert: " . $conn->error;
        }
        $stmt->close();
        
        header('Location: admin_dashboard.php');
        exit();
    }
}

// Fetch vendors from database with enhanced fields
$vendors = [];
$stmt = $conn->prepare("SELECT v.*, u.name as user_name, u.email as user_email,
                       COUNT(po.po_id) as total_orders,
                       SUM(CASE WHEN po.status = 'Completed' THEN 1 ELSE 0 END) as completed_orders
                       FROM vendors v 
                       JOIN users u ON v.user_id = u.id 
                       LEFT JOIN purchase_orders po ON v.vendor_id = po.vendor_id
                       GROUP BY v.vendor_id
                       ORDER BY v.vendor_status, v.vendor_name");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Calculate performance for each vendor if they have completed orders
    if ($row['completed_orders'] > 0) {
        $performance_data = calculateVendorPerformance($row['vendor_id'], $conn);
        $row = array_merge($row, $performance_data);
    }
    $vendors[] = $row;
}
$stmt->close();

// Fetch approved vendors for purchase orders
$approved_vendors = [];
$stmt = $conn->prepare("SELECT vendor_id, vendor_name FROM vendors WHERE vendor_status = 'approved' ORDER BY vendor_name");
$stmt->execute();
$approved_vendors_result = $stmt->get_result();

while ($row = $approved_vendors_result->fetch_assoc()) {
    $approved_vendors[] = $row;
}
$stmt->close();

// Fetch purchase orders with delivery status
$purchase_orders = [];
$stmt = $conn->prepare("SELECT po.*, v.vendor_name 
                       FROM purchase_orders po 
                       LEFT JOIN vendors v ON po.vendor_id = v.vendor_id 
                       ORDER BY po.created_at DESC");
$stmt->execute();
$purchase_orders_result = $stmt->get_result();

while ($row = $purchase_orders_result->fetch_assoc()) {
    $purchase_orders[] = $row;
}
$stmt->close();

// Fetch pending deliveries for the delivery tracking modal
$pending_deliveries = [];
$stmt = $conn->prepare("SELECT po.po_id, po.po_number, v.vendor_name, po.expected_delivery_date 
                       FROM purchase_orders po 
                       JOIN vendors v ON po.vendor_id = v.vendor_id 
                       WHERE po.delivery_status IS NULL AND po.status = 'Approved'
                       ORDER BY po.expected_delivery_date ASC");
$stmt->execute();
$pending_deliveries_result = $stmt->get_result();

while ($row = $pending_deliveries_result->fetch_assoc()) {
    $pending_deliveries[] = $row;
}
$stmt->close();

// Fetch products for inventory management
$products = [];
$stmt = $conn->prepare("SELECT p.*, v.vendor_name 
                       FROM products p 
                       LEFT JOIN vendors v ON p.preferred_vendor_id = v.vendor_id 
                       ORDER BY p.product_name");
$stmt->execute();
$products_result = $stmt->get_result();

while ($row = $products_result->fetch_assoc()) {
    $products[] = $row;
}
$stmt->close();

// Get low stock alerts
$low_stock_alerts = getLowStockAlerts($conn);

// Get dead stock alerts
$dead_stock_alerts = getDeadStockAlerts($conn);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement Officer Dashboard - ProcureFlow</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: #f5f7fa;
            color: #333;
            overflow-x: hidden;
        }
        
        .admin-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 250px;
            background: linear-gradient(135deg, #2c6bed 0%, #1a56c7 100%);
            color: white;
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
        }
        
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 3px;
        }
        
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.5);
        }
        
        .logo {
            display: flex;
            align-items: center;
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .logo i {
            font-size: 2rem;
            margin-right: 10px;
        }
        
        .logo h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .nav-item {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            color: white;
            text-decoration: none;
            transition: background 0.3s ease;
            border-left: 4px solid transparent;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }
        
        .nav-item.active {
            background: rgba(255,255,255,0.1);
            border-left-color: white;
        }
        
        .nav-item:hover {
            background: rgba(255,255,255,0.1);
        }
        
        .nav-item i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s ease;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h2 {
            color: #2c6bed;
            margin: 0;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }
        
        /* Cards and Tables */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .card-header h3 {
            margin: 0;
            color: #2c6bed;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Enhanced Table Styles with Scrollbars */
        .table-responsive {
            overflow-x: auto;
        }
        
        .table-scroller {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            position: relative;
        }
        
        .table-scroller table {
            margin-bottom: 0;
            width: 100%;
        }
        
        .table-scroller thead th {
            position: sticky;
            top: 0;
            background: #f8f9fa;
            z-index: 10;
            box-shadow: 0 1px 0 #e2e8f0;
        }
        
        .table-scroller::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .table-scroller::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 0 8px 8px 0;
        }
        
        .table-scroller::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .table-scroller::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c6bed;
            position: sticky;
            top: 0;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-pending { 
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
            color: #856404; 
            border: 1px solid #ffeaa7;
        }
        
        .status-approved { 
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        
        .status-rejected { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); 
            color: #721c24; 
            border: 1px solid #f5c6cb;
        }
        
        .status-contracted { 
            background: linear-gradient(135deg, #cce7ff 0%, #99ceff 100%); 
            color: #004085; 
            border: 1px solid #99ceff;
        }
        
        .status-pending-pricing {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
            color: #856404; 
            border: 1px solid #ffeaa7;
        }
        
        .status-pending-cfo {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
            color: #856404; 
            border: 1px solid #ffeaa7;
        }
        
        .onboarding-stage {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .stage-registered { background: #e2e3e5; color: #383d41; }
        .stage-under-review { background: #fff3cd; color: #856404; }
        .stage-approved { background: #d4edda; color: #155724; }
        .stage-contracted { background: #cce7ff; color: #004085; }
        .stage-rejected { background: #f8d7da; color: #721c24; }
        
        /* Performance Score */
        .performance-score {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 12px;
        }
        
        .score-excellent { background: #d4edda; color: #155724; }
        .score-good { background: #d1ecf1; color: #0c5460; }
        .score-fair { background: #fff3cd; color: #856404; }
        .score-poor { background: #f8d7da; color: #721c24; }
        
        /* Inventory Status */
        .stock-status-low { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); 
            color: #721c24; 
        }
        
        .stock-status-adequate { 
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); 
            color: #155724; 
        }
        
        .stock-status-high { 
            background: linear-gradient(135deg, #cce7ff 0%, #99ceff 100%); 
            color: #004085; 
        }
        
        .stock-level-critical {
            background: #dc3545;
            color: white;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #2c6bed 0%, #1a56c7 100%);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #1a56c7 0%, #2c6bed 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #20c997 0%, #28a745 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, #dc3545 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, #6c757d 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        
        .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }
        
        .btn-info:hover {
            background: linear-gradient(135deg, #138496 0%, #17a2b8 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #212529;
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, #ffc107 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 4px solid #2c6bed;
        }
        
        .stat-card.warning {
            border-top-color: #ffc107;
        }
        
        .stat-card.danger {
            border-top-color: #dc3545;
        }
        
        .stat-card.success {
            border-top-color: #28a745;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2c6bed;
            display: block;
        }
        
        .stat-card.warning .stat-number {
            color: #ffc107;
        }
        
        .stat-card.danger .stat-number {
            color: #dc3545;
        }
        
        .stat-card.success .stat-number {
            color: #28a745;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        /* Filter Bar */
        .filter-bar {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .filter-bar select, .filter-bar input {
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 14px;
        }
        
        /* Enhanced Modal Styles with Scrollbars */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease-out;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        
        .modal-lg {
            max-width: 800px;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #2c6bed 0%, #1a56c7 100%);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }
        
        .close {
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.3s ease;
        }
        
        .close:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .modal-body {
            padding: 25px;
            overflow-y: auto;
            flex-grow: 1;
        }
        
        .modal-body-scrollable {
            max-height: 60vh;
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .modal-body-scrollable::-webkit-scrollbar {
            width: 6px;
        }
        
        .modal-body-scrollable::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .modal-body-scrollable::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .modal-body-scrollable::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #2c6bed;
            box-shadow: 0 0 0 3px rgba(44, 107, 237, 0.1);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 5px;
            margin-bottom: 20px;
        }
        
        .tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: none;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            color: #2c6bed;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Purchase Order Status */
        .po-status-draft { 
            background: linear-gradient(135deg, #e2e3e5 0%, #d3d6d8 100%); 
            color: #383d41; 
        }
        
        .po-status-pending { 
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
            color: #856404; 
        }
        
        .po-status-approved { 
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); 
            color: #155724; 
        }
        
        .po-status-rejected { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); 
            color: #721c24; 
        }
        
        .po-status-delivered { 
            background: linear-gradient(135deg, #cce7ff 0%, #99ceff 100%); 
            color: #004085; 
        }
        
        .po-status-late { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); 
            color: #721c24; 
        }
        
        .po-status-pending-pricing {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
            color: #856404; 
        }
        
        .po-status-pending-cfo {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
            color: #856404; 
        }
        
        /* Vendor Performance Metrics */
        .performance-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .metric-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-top: 4px solid #007bff;
        }
        
        .metric-value {
            display: block;
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }
        
        .metric-label {
            color: #666;
            font-size: 14px;
        }
        
        /* Progress bars */
        .progress {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        /* Enhanced Vendor Details Tabs */
        .vendor-tabs {
            display: flex;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 5px;
            margin-bottom: 20px;
            overflow-x: auto;
        }
        
        .vendor-tabs::-webkit-scrollbar {
            height: 6px;
        }
        
        .vendor-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .vendor-tabs::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .vendor-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: none;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            white-space: nowrap;
            min-width: 120px;
        }
        
        .vendor-tab.active {
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            color: #2c6bed;
        }
        
        .vendor-tab-content {
            display: none;
        }
        
        .vendor-tab-content.active {
            display: block;
        }
        
        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #2c6bed;
            cursor: pointer;
            padding: 10px;
        }
        
        /* Real-time alert styles */
        .real-time-alert {
            border-left: 4px solid #dc3545;
            background: #f8d7da;
            padding: 10px 15px;
            margin: 10px 0;
            border-radius: 0 5px 5px 0;
        }
        
        .real-time-alert.warning {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        
        .inventory-alert-panel {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .inventory-alert-panel.warning {
            border-left-color: #ffc107;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .admin-container {
                flex-direction: column;
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .tabs, .vendor-tabs {
                flex-direction: column;
            }
            
            .table-scroller {
                max-height: 400px;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
                max-height: 85vh;
            }
            
            .modal-body-scrollable {
                max-height: 50vh;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="logo">
                <i class="fas fa-file-invoice-dollar"></i>
                <h1>ProcureFlow</h1>
            </div>
            
            <button class="nav-item active" onclick="switchTab('vendors')">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </button>
            
            <button class="nav-item" onclick="switchTab('vendors')">
                <i class="fas fa-building"></i>
                <span>Vendor Management</span>
            </button>
            
            <button class="nav-item" onclick="switchTab('purchase-orders')">
                <i class="fas fa-shopping-cart"></i>
                <span>Purchase Orders</span>
            </button>
            
            <button class="nav-item" onclick="switchTab('inventory')">
                <i class="fas fa-boxes"></i>
                <span>Inventory Management</span>
            </button>
            
            <button class="nav-item" onclick="showComingSoon()">
                <i class="fas fa-chart-bar"></i>
                <span>Reports</span>
            </button>
            
            <button class="nav-item" onclick="showComingSoon()">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </button>
            
            <button class="nav-item" onclick="location.href='logout.php'">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </button>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header with Create PO Button -->
            <div class="header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <button class="menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h2>Procurement Officer Dashboard</h2>
                </div>
                <div style="display: flex; gap: 15px; align-items: center;">
                    <button class="btn btn-primary" onclick="openCreatePOModal()">
                        <i class="fas fa-plus"></i> Create Purchase Order
                    </button>
                    <button class="btn btn-success" onclick="openDeliveryTrackingModal()">
                        <i class="fas fa-truck"></i> Record Delivery
                    </button>
                    <button class="btn btn-warning" onclick="openCreateProductModal()">
                        <i class="fas fa-box"></i> Add Product
                    </button>
                    <div class="user-info">
                        <img src="https://i.pravatar.cc/40?img=3" alt="User">
                        <span>Welcome, <?php echo $userName; ?></span>
                        <button class="btn btn-secondary" onclick="location.href='logout.php'">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Success/Error Messages -->
            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>
            
            <!-- Real-time Inventory Alerts -->
            <?php if(count($low_stock_alerts) > 0): ?>
                <div class="inventory-alert-panel">
                    <h3 style="color: #dc3545; margin-bottom: 10px;">
                        <i class="fas fa-exclamation-triangle"></i> Low Stock Alerts
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px;">
                        <?php foreach($low_stock_alerts as $alert): ?>
                            <div class="real-time-alert">
                                <strong><?php echo htmlspecialchars($alert['product_name']); ?></strong>
                                <br>
                                Stock: <?php echo $alert['current_stock']; ?> | 
                                Reorder Level: <?php echo $alert['reorder_level']; ?>
                                <br>
                                <button class="btn btn-primary btn-sm" style="margin-top: 5px;" 
                                        onclick="createPOFromAlert(<?php echo $alert['product_id']; ?>, '<?php echo htmlspecialchars($alert['product_name']); ?>', <?php echo $alert['preferred_vendor_id'] ?? 0; ?>)">
                                    <i class="fas fa-cart-plus"></i> Create PO
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="stats-container">
                <?php
                $pending_count = array_reduce($vendors, function($carry, $vendor) {
                    return $carry + ($vendor['vendor_status'] === 'pending' ? 1 : 0);
                }, 0);
                
                $approved_count = array_reduce($vendors, function($carry, $vendor) {
                    return $carry + ($vendor['vendor_status'] === 'approved' ? 1 : 0);
                }, 0);
                
                $rejected_count = array_reduce($vendors, function($carry, $vendor) {
                    return $carry + ($vendor['vendor_status'] === 'rejected' ? 1 : 0);
                }, 0);
                
                $total_count = count($vendors);
                
                // Calculate average performance score
                $total_performance = 0;
                $vendors_with_score = 0;
                foreach($vendors as $vendor) {
                    if(isset($vendor['performance_score']) && $vendor['performance_score'] > 0) {
                        $total_performance += $vendor['performance_score'];
                        $vendors_with_score++;
                    }
                }
                $avg_performance = $vendors_with_score > 0 ? round($total_performance / $vendors_with_score, 1) : 0;
                
                // Purchase Order Stats
                $po_pending_pricing = array_reduce($purchase_orders, function($carry, $po) {
                    return $carry + ($po['status'] === 'Pending Vendor Pricing' ? 1 : 0);
                }, 0);
                
                $po_pending_cfo = array_reduce($purchase_orders, function($carry, $po) {
                    return $carry + ($po['status'] === 'Pending CFO Approval' ? 1 : 0);
                }, 0);
                
                $po_approved_count = array_reduce($purchase_orders, function($carry, $po) {
                    return $carry + ($po['status'] === 'Approved' ? 1 : 0);
                }, 0);
                
                $po_delivered_count = array_reduce($purchase_orders, function($carry, $po) {
                    return $carry + (($po['delivery_status'] === 'delivered' || $po['delivery_status'] === 'late') ? 1 : 0);
                }, 0);
                
                $po_total_count = count($purchase_orders);
                
                // Inventory Stats
                $low_stock_count = count($low_stock_alerts);
                $dead_stock_count = count($dead_stock_alerts);
                $total_products = count($products);
                $out_of_stock_count = array_reduce($products, function($carry, $product) {
                    return $carry + ($product['current_stock'] <= 0 ? 1 : 0);
                }, 0);
                ?>
                
                <div class="stat-card">
                    <span class="stat-number"><?php echo $total_count; ?></span>
                    <span class="stat-label">Total Vendors</span>
                </div>
                
                <div class="stat-card">
                    <span class="stat-number"><?php echo $pending_count; ?></span>
                    <span class="stat-label">Pending Approval</span>
                </div>
                
                <div class="stat-card">
                    <span class="stat-number"><?php echo $approved_count; ?></span>
                    <span class="stat-label">Approved Vendors</span>
                </div>
                
                <div class="stat-card">
                    <span class="stat-number"><?php echo $avg_performance; ?>%</span>
                    <span class="stat-label">Avg Performance</span>
                </div>
                
                <div class="stat-card warning">
                    <span class="stat-number"><?php echo $po_pending_pricing; ?></span>
                    <span class="stat-label">Pending Pricing</span>
                </div>
                
                <div class="stat-card warning">
                    <span class="stat-number"><?php echo $po_pending_cfo; ?></span>
                    <span class="stat-label">Pending CFO Approval</span>
                </div>
                
                <div class="stat-card success">
                    <span class="stat-number"><?php echo $total_products; ?></span>
                    <span class="stat-label">Total Products</span>
                </div>
                
                <div class="stat-card danger">
                    <span class="stat-number"><?php echo $out_of_stock_count; ?></span>
                    <span class="stat-label">Out of Stock</span>
                </div>
            </div>
            
            <!-- Tabs for different sections -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('vendors')">Vendor Management</button>
                <button class="tab" onclick="switchTab('purchase-orders')">Purchase Orders</button>
                <button class="tab" onclick="switchTab('inventory')">Inventory Management</button>
            </div>
            
            <!-- Vendors Tab -->
            <div id="vendors-tab" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>Vendor Management Hub</h3>
                        <div class="filter-bar">
                            <select id="status-filter">
                                <option value="">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            <select id="performance-filter">
                                <option value="">All Performance</option>
                                <option value="excellent">Excellent (90-100%)</option>
                                <option value="good">Good (75-89%)</option>
                                <option value="fair">Fair (60-74%)</option>
                                <option value="poor">Poor (Below 60%)</option>
                            </select>
                            <input type="text" id="vendor-search" placeholder="Search vendors...">
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <div class="table-responsive">
                            <div class="table-scroller">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Vendor Name</th>
                                            <th>Contact</th>
                                            <th>Business Type</th>
                                            <th>Onboarding Stage</th>
                                            <th>Performance Score</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($vendors)): ?>
                                            <tr>
                                                <td colspan="7" style="text-align: center; padding: 20px;">
                                                    No vendors found in the database.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach($vendors as $vendor): 
                                                $performance_score = $vendor['performance_score'] ?? 0;
                                                $onboarding_stage = $vendor['onboarding_stage'] ?? 'registered';
                                                $on_time_rate = $vendor['on_time_delivery_rate'] ?? 0;
                                                $quality_rate = $vendor['quality_acceptance_rate'] ?? 0;
                                                $defect_rate = $vendor['avg_defect_rate'] ?? 0;
                                                
                                                // Determine performance class
                                                $performance_class = '';
                                                if ($performance_score >= 90) $performance_class = 'score-excellent';
                                                elseif ($performance_score >= 75) $performance_class = 'score-good';
                                                elseif ($performance_score >= 60) $performance_class = 'score-fair';
                                                else $performance_class = 'score-poor';
                                            ?>
                                                <tr data-status="<?php echo $vendor['vendor_status']; ?>" data-performance="<?php echo $performance_score; ?>" data-vendor-id="<?php echo $vendor['vendor_id']; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($vendor['vendor_name']); ?></strong>
                                                        <br><small><?php echo htmlspecialchars($vendor['user_email']); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($vendor['contact_phone'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($vendor['business_type'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <span class="onboarding-stage stage-<?php echo $onboarding_stage; ?>">
                                                            <?php echo ucfirst(str_replace('-', ' ', $onboarding_stage)); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if($vendor['completed_orders'] > 0): ?>
                                                            <span class="performance-score <?php echo $performance_class; ?>">
                                                                <?php echo number_format($performance_score, 1); ?>%
                                                            </span>
                                                            <div class="progress">
                                                                <div class="progress-bar" style="width: <?php echo $performance_score; ?>%"></div>
                                                            </div>
                                                            <div style="font-size: 11px; margin-top: 5px;">
                                                                <div>On-Time: <?php echo number_format($on_time_rate, 1); ?>%</div>
                                                                <div>Quality: <?php echo number_format($quality_rate, 1); ?>%</div>
                                                                <div>Defects: <?php echo number_format($defect_rate, 1); ?>%</div>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="performance-score">No Data</span>
                                                            <div style="font-size: 11px; margin-top: 5px;">No completed orders</div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $vendor['vendor_status']; ?>">
                                                            <?php echo ucfirst($vendor['vendor_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                            <?php if($vendor['vendor_status'] === 'pending'): ?>
                                                                <button type="button" class="btn btn-success btn-sm" 
                                                                        onclick="showApprovalModal(<?php echo $vendor['vendor_id']; ?>, '<?php echo htmlspecialchars($vendor['vendor_name']); ?>', 'approve')">
                                                                    <i class="fas fa-check"></i> Approve
                                                                </button>
                                                                
                                                                <button type="button" class="btn btn-danger btn-sm"
                                                                        onclick="showApprovalModal(<?php echo $vendor['vendor_id']; ?>, '<?php echo htmlspecialchars($vendor['vendor_name']); ?>', 'reject')">
                                                                    <i class="fas fa-times"></i> Reject
                                                                </button>
                                                            <?php endif; ?>
                                                            
                                                            <button type="button" class="btn btn-info btn-sm"
                                                                    onclick="showVendorDetails(<?php echo $vendor['vendor_id']; ?>)">
                                                                <i class="fas fa-eye"></i> View
                                                            </button>
                                                            
                                                            <?php if($vendor['vendor_status'] === 'approved'): ?>
                                                                <button type="button" class="btn btn-primary btn-sm"
                                                                        onclick="showContractModal(<?php echo $vendor['vendor_id']; ?>, '<?php echo htmlspecialchars($vendor['vendor_name']); ?>')">
                                                                    <i class="fas fa-file-contract"></i> Contract
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Purchase Orders Tab -->
            <div id="purchase-orders-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Purchase Orders</h3>
                        <div class="filter-bar">
                            <select id="po-status-filter">
                                <option value="">All Statuses</option>
                                <option value="Pending Vendor Pricing">Pending Vendor Pricing</option>
                                <option value="Pending CFO Approval">Pending CFO Approval</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                                <option value="delivered">Delivered</option>
                                <option value="late">Late</option>
                            </select>
                            <input type="text" id="po-search" placeholder="Search purchase orders...">
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <div class="table-scroller">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>PO Number</th>
                                            <th>Vendor</th>
                                            <th>Product</th>
                                            <th>Quantity</th>
                                            <th>Unit Price</th>
                                            <th>Total Amount</th>
                                            <th>Issue Date</th>
                                            <th>Delivery Date</th>
                                            <th>Status</th>
                                            <th>Delivery Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($purchase_orders)): ?>
                                            <tr>
                                                <td colspan="10" style="text-align: center; padding: 20px;">
                                                    No purchase orders found. <a href="javascript:void(0)" onclick="openCreatePOModal()" style="color: #2c6bed; text-decoration: none;">Create your first purchase order</a>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach($purchase_orders as $po): 
                                                $delivery_status = $po['delivery_status'] ?? 'pending';
                                                $delivery_status_class = '';
                                                if ($delivery_status === 'delivered') $delivery_status_class = 'po-status-delivered';
                                                elseif ($delivery_status === 'late') $delivery_status_class = 'po-status-late';
                                                elseif ($delivery_status === 'pending') $delivery_status_class = 'po-status-pending';
                                                
                                                $status_class = 'po-status-' . strtolower(str_replace(' ', '-', $po['status']));
                                            ?>
                                                <tr data-po-status="<?php echo $po['status']; ?>" data-delivery-status="<?php echo $delivery_status; ?>">
                                                    <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($po['vendor_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($po['product_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo $po['quantity'] ?? 'N/A'; ?></td>
                                                    <td>
                                                        <?php if($po['unit_price'] > 0): ?>
                                                            ZMW <?php echo number_format($po['unit_price'], 2); ?>
                                                        <?php else: ?>
                                                            <span class="status-badge status-pending-pricing">Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if($po['total_amount'] > 0): ?>
                                                            ZMW <?php echo number_format($po['total_amount'], 2); ?>
                                                        <?php else: ?>
                                                            <span class="status-badge status-pending-pricing">Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($po['issue_date'])); ?></td>
                                                    <td>
                                                        <?php echo date('M j, Y', strtotime($po['expected_delivery_date'])); ?>
                                                        <?php if($po['actual_delivery_date']): ?>
                                                            <br><small>Actual: <?php echo date('M j, Y', strtotime($po['actual_delivery_date'])); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $status_class; ?>">
                                                            <?php echo htmlspecialchars($po['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $delivery_status_class; ?>">
                                                            <?php echo ucfirst($delivery_status); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Inventory Management Tab -->
            <div id="inventory-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Inventory Management</h3>
                        <div class="filter-bar">
                            <select id="inventory-status-filter">
                                <option value="">All Statuses</option>
                                <option value="low">Low Stock</option>
                                <option value="adequate">Adequate Stock</option>
                                <option value="high">High Stock</option>
                                <option value="out">Out of Stock</option>
                            </select>
                            <input type="text" id="inventory-search" placeholder="Search products...">
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if(count($dead_stock_alerts) > 0): ?>
                            <div class="inventory-alert-panel warning">
                                <h3 style="color: #856404; margin-bottom: 10px;">
                                    <i class="fas fa-exclamation-circle"></i> Dead Stock Alerts
                                </h3>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px;">
                                    <?php foreach($dead_stock_alerts as $alert): ?>
                                        <div class="real-time-alert warning">
                                            <strong><?php echo htmlspecialchars($alert['product_name']); ?></strong>
                                            <br>
                                            Stock: <?php echo $alert['current_stock']; ?> | 
                                            Days Since Movement: <?php echo $alert['days_since_movement']; ?>+
                                            <br>
                                            <button class="btn btn-warning btn-sm" style="margin-top: 5px;" 
                                                    onclick="showDeadStockResolution(<?php echo $alert['alert_id']; ?>, '<?php echo htmlspecialchars($alert['product_name']); ?>')">
                                                <i class="fas fa-lightbulb"></i> Resolve
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <div class="table-scroller">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Product Name</th>
                                            <th>SKU</th>
                                            <th>Category</th>
                                            <th>Current Stock</th>
                                            <th>Reorder Level</th>
                                            <th>Safety Stock</th>
                                            <th>Unit Cost</th>
                                            <th>Stock Status</th>
                                            <th>Preferred Vendor</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($products)): ?>
                                            <tr>
                                                <td colspan="10" style="text-align: center; padding: 20px;">
                                                    No products found in inventory. <a href="javascript:void(0)" onclick="openCreateProductModal()" style="color: #2c6bed; text-decoration: none;">Add your first product</a>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach($products as $product): 
                                                $stock_status = '';
                                                $stock_class = '';
                                                
                                                if ($product['current_stock'] <= 0) {
                                                    $stock_status = 'Out of Stock';
                                                    $stock_class = 'stock-level-critical';
                                                } elseif ($product['current_stock'] <= $product['safety_stock']) {
                                                    $stock_status = 'Critical';
                                                    $stock_class = 'stock-status-low';
                                                } elseif ($product['current_stock'] <= $product['reorder_level']) {
                                                    $stock_status = 'Low';
                                                    $stock_class = 'stock-status-low';
                                                } elseif ($product['current_stock'] > $product['reorder_level'] * 2) {
                                                    $stock_status = 'High';
                                                    $stock_class = 'stock-status-high';
                                                } else {
                                                    $stock_status = 'Adequate';
                                                    $stock_class = 'stock-status-adequate';
                                                }
                                            ?>
                                                <tr data-stock-status="<?php echo strtolower($stock_status); ?>" data-product-id="<?php echo $product['product_id']; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                                        <?php if(!empty($product['description'])): ?>
                                                            <br><small><?php echo htmlspecialchars($product['description']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                                    <td><?php echo htmlspecialchars($product['category']); ?></td>
                                                    <td>
                                                        <strong><?php echo $product['current_stock']; ?></strong>
                                                        <?php if($product['current_stock'] <= $product['safety_stock']): ?>
                                                            <div class="stock-level-critical" style="padding: 2px 5px; border-radius: 3px; font-size: 10px; margin-top: 2px;">
                                                                CRITICAL
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo $product['reorder_level']; ?></td>
                                                    <td><?php echo $product['safety_stock']; ?></td>
                                                    <td>ZMW <?php echo number_format($product['unit_cost'], 2); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $stock_class; ?>">
                                                            <?php echo $stock_status; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($product['vendor_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                            <button type="button" class="btn btn-info btn-sm"
                                                                    onclick="showInventoryDetails(<?php echo $product['product_id']; ?>)">
                                                                <i class="fas fa-eye"></i> View
                                                            </button>
                                                            
                                                            <button type="button" class="btn btn-primary btn-sm"
                                                                    onclick="showInventoryAdjustment(<?php echo $product['product_id']; ?>, '<?php echo htmlspecialchars($product['product_name']); ?>')">
                                                                <i class="fas fa-edit"></i> Adjust
                                                            </button>
                                                            
                                                            <?php if($product['current_stock'] <= $product['reorder_level'] && $product['preferred_vendor_id']): ?>
                                                                <button type="button" class="btn btn-success btn-sm"
                                                                        onclick="createPOFromProduct(<?php echo $product['product_id']; ?>, '<?php echo htmlspecialchars($product['product_name']); ?>', <?php echo $product['preferred_vendor_id']; ?>)">
                                                                    <i class="fas fa-cart-plus"></i> Reorder
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Approval Modal -->
    <div id="approvalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Approve Vendor</h3>
                <button class="close" onclick="closeModal('approvalModal')">&times;</button>
            </div>
            <form id="approvalForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="vendor_id" id="modalVendorId">
                    <input type="hidden" name="action" id="modalAction">
                    
                    <p id="modalMessage">Are you sure you want to approve this vendor?</p>
                    
                    <div class="form-group" id="reasonField" style="display: none;">
                        <label for="rejectionReason">Reason for Rejection (Optional):</label>
                        <textarea class="form-control" id="rejectionReason" name="reason" placeholder="Provide reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('approvalModal')">Cancel</button>
                    <button type="submit" class="btn" id="modalSubmitBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Enhanced Vendor Details Modal -->
    <div id="vendorDetailsModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3>Vendor Details - <span id="vendorDetailName"></span></h3>
                <button class="close" onclick="closeModal('vendorDetailsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="vendor-tabs">
                    <button class="vendor-tab active" onclick="switchVendorTab('basic')">Basic Info</button>
                    <button class="vendor-tab" onclick="switchVendorTab('contract')">Contract</button>
                    <button class="vendor-tab" onclick="switchVendorTab('certifications')">Certifications</button>
                    <button class="vendor-tab" onclick="switchVendorTab('performance')">Performance</button>
                </div>
                
                <div id="vendorBasicTab" class="vendor-tab-content active">
                    <div id="vendorBasicContent" class="modal-body-scrollable">
                        <!-- Basic info will be loaded here -->
                    </div>
                </div>
                
                <div id="vendorContractTab" class="vendor-tab-content">
                    <div id="vendorContractContent" class="modal-body-scrollable">
                        <!-- Contract info will be loaded here -->
                    </div>
                </div>
                
                <div id="vendorCertificationsTab" class="vendor-tab-content">
                    <div id="vendorCertificationsContent" class="modal-body-scrollable">
                        <!-- Certifications will be loaded here -->
                    </div>
                </div>
                
                <div id="vendorPerformanceTab" class="vendor-tab-content">
                    <div id="vendorPerformanceContent" class="modal-body-scrollable">
                        <!-- Performance data will be loaded here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('vendorDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Vendor Contract Modal -->
    <div id="vendorContractModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="contractModalTitle">Create Vendor Contract</h3>
                <button class="close" onclick="closeModal('vendorContractModal')">&times;</button>
            </div>
            <form method="POST" id="contractForm">
                <input type="hidden" name="create_vendor_contract" value="1">
                <input type="hidden" name="vendor_id" id="contractVendorId">
                
                <div class="modal-body">
                    <div class="modal-body-scrollable">
                        <div class="form-group">
                            <label for="contract_start">Contract Start Date *</label>
                            <input type="date" class="form-control" id="contract_start" name="contract_start" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="contract_end">Contract End Date *</label>
                            <input type="date" class="form-control" id="contract_end" name="contract_end" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="contract_value">Contract Value (ZMW) *</label>
                            <input type="number" class="form-control" id="contract_value" name="contract_value" min="0" step="0.01" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="payment_terms">Payment Terms *</label>
                            <select class="form-control" id="payment_terms" name="payment_terms" required>
                                <option value="">Select Terms</option>
                                <option value="Net 30">Net 30</option>
                                <option value="Net 60">Net 60</option>
                                <option value="Net 90">Net 90</option>
                                <option value="Upon Delivery">Upon Delivery</option>
                                <option value="50% Advance">50% Advance</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('vendorContractModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Contract</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Enhanced Delivery Tracking Modal -->
    <div id="deliveryTrackingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Record Delivery & Quality Check</h3>
                <button class="close" onclick="closeModal('deliveryTrackingModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="record_delivery" value="1">
                <div class="modal-body">
                    <div class="modal-body-scrollable">
                        <div class="form-group">
                            <label for="po_id">Purchase Order *</label>
                            <select class="form-control" id="po_id" name="po_id" required>
                                <option value="">Select Purchase Order</option>
                                <?php foreach($pending_deliveries as $delivery): ?>
                                    <option value="<?php echo $delivery['po_id']; ?>">
                                        <?php echo htmlspecialchars($delivery['po_number']); ?> - <?php echo htmlspecialchars($delivery['vendor_name']); ?> (Expected: <?php echo date('M j, Y', strtotime($delivery['expected_delivery_date'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="actual_delivery_date">Actual Delivery Date *</label>
                            <input type="date" class="form-control" id="actual_delivery_date" name="actual_delivery_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="quality_status">Quality Check Result *</label>
                            <select class="form-control" id="quality_status" name="quality_status" required>
                                <option value="">Select Quality Result</option>
                                <option value="passed">Passed - All items acceptable</option>
                                <option value="partial">Partial - Some items acceptable</option>
                                <option value="failed">Failed - No items acceptable</option>
                            </select>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="defect_count">Number of Defective Items</label>
                                <input type="number" class="form-control" id="defect_count" name="defect_count" min="0" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="total_items">Total Items Received</label>
                                <input type="number" class="form-control" id="total_items" name="total_items" min="1" value="1">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="delivery_notes">Delivery Notes</label>
                            <textarea class="form-control" id="delivery_notes" name="delivery_notes" rows="3" placeholder="Any notes about the delivery or quality..."></textarea>
                        </div>
                        
                        <div id="defect-rate-display" style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 10px; display: none;">
                            <strong>Calculated Defect Rate: <span id="defect-rate-value">0%</span></strong>
                            <div style="font-size: 12px; margin-top: 5px;">
                                This will be used to calculate vendor performance metrics.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deliveryTrackingModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Record Delivery</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Purchase Order Modal -->
    <div id="createPOModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Purchase Order</h3>
                <button class="close" onclick="closeModal('createPOModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="create_purchase_order" value="1">
                <div class="modal-body">
                    <div class="modal-body-scrollable">
                        <div class="form-group">
                            <label for="vendor_id">Vendor *</label>
                            <select class="form-control" id="vendor_id" name="vendor_id" required onchange="loadVendorProducts(this.value)">
                                <option value="">Select Vendor</option>
                                <?php foreach($approved_vendors as $vendor): ?>
                                    <option value="<?php echo $vendor['vendor_id']; ?>"><?php echo htmlspecialchars($vendor['vendor_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="product_name">Product Name *</label>
                            <input type="text" class="form-control" id="product_name" name="product_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantity">Quantity *</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" min="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="delivery_date">Expected Delivery Date *</label>
                            <input type="date" class="form-control" id="delivery_date" name="delivery_date" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Additional Notes</label>
                            <textarea class="form-control" id="notes" name="notes" placeholder="Additional notes or specifications..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 10px;">
                                <strong>Note:</strong> The vendor will provide pricing for this purchase order. If the total amount exceeds ZMW 5,000, it will require CFO approval.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createPOModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Purchase Order</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Product Modal -->
    <div id="createProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Product</h3>
                <button class="close" onclick="closeModal('createProductModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="create_product" value="1">
                <div class="modal-body">
                    <div class="modal-body-scrollable">
                        <div class="form-group">
                            <label for="product_name">Product Name *</label>
                            <input type="text" class="form-control" id="product_name" name="product_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" placeholder="Product description..."></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="category">Category *</label>
                                <input type="text" class="form-control" id="category" name="category" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="sku">SKU *</label>
                                <input type="text" class="form-control" id="sku" name="sku" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="reorder_level">Reorder Level *</label>
                                <input type="number" class="form-control" id="reorder_level" name="reorder_level" min="1" required value="10">
                            </div>
                            
                            <div class="form-group">
                                <label for="safety_stock">Safety Stock *</label>
                                <input type="number" class="form-control" id="safety_stock" name="safety_stock" min="1" required value="5">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="unit_cost">Unit Cost (ZMW) *</label>
                                <input type="number" class="form-control" id="unit_cost" name="unit_cost" step="0.01" min="0" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="preferred_vendor_id">Preferred Vendor</label>
                                <select class="form-control" id="preferred_vendor_id" name="preferred_vendor_id">
                                    <option value="">Select Vendor</option>
                                    <?php foreach($approved_vendors as $vendor): ?>
                                        <option value="<?php echo $vendor['vendor_id']; ?>"><?php echo htmlspecialchars($vendor['vendor_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createProductModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Inventory Details Modal -->
    <div id="inventoryDetailsModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3>Inventory Details - <span id="inventoryDetailName"></span></h3>
                <button class="close" onclick="closeModal('inventoryDetailsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="inventoryDetailsContent" class="modal-body-scrollable">
                    <!-- Inventory details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('inventoryDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Inventory Adjustment Modal -->
    <div id="inventoryAdjustmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Adjust Inventory - <span id="adjustmentProductName"></span></h3>
                <button class="close" onclick="closeModal('inventoryAdjustmentModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="update_inventory" value="1">
                <input type="hidden" name="product_id" id="adjustmentProductId">
                
                <div class="modal-body">
                    <div class="modal-body-scrollable">
                        <div class="form-group">
                            <label for="adjustment_type">Adjustment Type *</label>
                            <select class="form-control" id="adjustment_type" name="adjustment_type" required>
                                <option value="">Select Type</option>
                                <option value="in">Stock In (Add)</option>
                                <option value="out">Stock Out (Remove)</option>
                                <option value="adjustment">Adjustment (Set)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantity">Quantity *</label>
                            <input type="number" class="form-control" id="adjustment_quantity" name="quantity" min="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Adjustment Notes</label>
                            <textarea class="form-control" id="adjustment_notes" name="notes" placeholder="Reason for adjustment..."></textarea>
                        </div>
                        
                        <div id="current-stock-info" style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 10px; display: none;">
                            <strong>Current Stock: <span id="current-stock-value">0</span></strong>
                            <br>
                            <strong>New Stock After Adjustment: <span id="new-stock-value">0</span></strong>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('inventoryAdjustmentModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Inventory</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Dead Stock Resolution Modal -->
    <div id="deadStockResolutionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Resolve Dead Stock - <span id="deadStockProductName"></span></h3>
                <button class="close" onclick="closeModal('deadStockResolutionModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="resolve_dead_stock" value="1">
                <input type="hidden" name="alert_id" id="deadStockAlertId">
                
                <div class="modal-body">
                    <div class="modal-body-scrollable">
                        <div class="form-group">
                            <label for="resolution_action">Resolution Action *</label>
                            <select class="form-control" id="resolution_action" name="resolution_action" required>
                                <option value="">Select Action</option>
                                <option value="discount">Put on Discount/Sale</option>
                                <option value="bundle">Create Bundle Package</option>
                                <option value="return">Return to Vendor</option>
                                <option value="donate">Donate/Write Off</option>
                                <option value="other">Other Action</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="resolution_notes">Resolution Notes *</label>
                            <textarea class="form-control" id="resolution_notes" name="resolution_notes" placeholder="Describe how you're resolving this dead stock issue..." required></textarea>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Note:</strong> This will mark the dead stock alert as resolved. Make sure to take appropriate action to clear the stagnant inventory.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deadStockResolutionModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Mark as Resolved</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Sidebar functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        }

        // Update active nav item based on current tab
        function updateActiveNav() {
            const currentTab = document.querySelector('.tab.active').textContent.toLowerCase();
            const navItems = document.querySelectorAll('.nav-item');
            
            navItems.forEach(item => {
                item.classList.remove('active');
                if (item.textContent.toLowerCase().includes(currentTab)) {
                    item.classList.add('active');
                }
            });
            
            // Default to dashboard if no match
            if (!document.querySelector('.nav-item.active')) {
                document.querySelector('.nav-item').classList.add('active');
            }
        }

        // Filter functionality for vendors
        document.getElementById('status-filter').addEventListener('change', filterVendors);
        document.getElementById('performance-filter').addEventListener('change', filterVendors);
        document.getElementById('vendor-search').addEventListener('input', filterVendors);
        
        // Filter functionality for purchase orders
        document.getElementById('po-status-filter').addEventListener('change', filterPurchaseOrders);
        document.getElementById('po-search').addEventListener('input', filterPurchaseOrders);
        
        // Filter functionality for inventory
        document.getElementById('inventory-status-filter').addEventListener('change', filterInventory);
        document.getElementById('inventory-search').addEventListener('input', filterInventory);
        
        function filterVendors() {
            const statusFilter = document.getElementById('status-filter').value;
            const performanceFilter = document.getElementById('performance-filter').value;
            const searchText = document.getElementById('vendor-search').value.toLowerCase();
            const rows = document.querySelectorAll('#vendors-tab tbody tr');
            
            rows.forEach(row => {
                const status = row.getAttribute('data-status');
                const performance = parseFloat(row.getAttribute('data-performance')) || 0;
                const vendorName = row.cells[0].textContent.toLowerCase();
                
                const matchesStatus = statusFilter === '' || status === statusFilter;
                const matchesSearch = searchText === '' || vendorName.includes(searchText);
                
                let matchesPerformance = true;
                if (performanceFilter !== '') {
                    switch(performanceFilter) {
                        case 'excellent':
                            matchesPerformance = performance >= 90;
                            break;
                        case 'good':
                            matchesPerformance = performance >= 75 && performance < 90;
                            break;
                        case 'fair':
                            matchesPerformance = performance >= 60 && performance < 75;
                            break;
                        case 'poor':
                            matchesPerformance = performance < 60 && performance > 0;
                            break;
                    }
                }
                
                if (matchesStatus && matchesSearch && matchesPerformance) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function filterPurchaseOrders() {
            const statusFilter = document.getElementById('po-status-filter').value;
            const searchText = document.getElementById('po-search').value.toLowerCase();
            const rows = document.querySelectorAll('#purchase-orders-tab tbody tr');
            
            rows.forEach(row => {
                const status = row.getAttribute('data-po-status');
                const deliveryStatus = row.getAttribute('data-delivery-status');
                const poNumber = row.cells[0].textContent.toLowerCase();
                const vendorName = row.cells[1].textContent.toLowerCase();
                
                let matchesStatus = true;
                if (statusFilter !== '') {
                    if (statusFilter === 'delivered' || statusFilter === 'late') {
                        matchesStatus = deliveryStatus === statusFilter;
                    } else {
                        matchesStatus = status.includes(statusFilter);
                    }
                }
                
                const matchesSearch = searchText === '' || 
                                    poNumber.includes(searchText) || 
                                    vendorName.includes(searchText);
                
                if (matchesStatus && matchesSearch) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function filterInventory() {
            const statusFilter = document.getElementById('inventory-status-filter').value;
            const searchText = document.getElementById('inventory-search').value.toLowerCase();
            const rows = document.querySelectorAll('#inventory-tab tbody tr');
            
            rows.forEach(row => {
                const status = row.getAttribute('data-stock-status');
                const productName = row.cells[0].textContent.toLowerCase();
                const sku = row.cells[1].textContent.toLowerCase();
                
                const matchesStatus = statusFilter === '' || status === statusFilter;
                const matchesSearch = searchText === '' || 
                                    productName.includes(searchText) || 
                                    sku.includes(searchText);
                
                if (matchesStatus && matchesSearch) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // Tab switching
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Update active nav item
            updateActiveNav();
            
            // Close sidebar on mobile after selection
            if (window.innerWidth <= 768) {
                toggleSidebar();
            }
        }
        
        // Modal functions
        function showApprovalModal(vendorId, vendorName, action) {
            const modal = document.getElementById('approvalModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const modalVendorId = document.getElementById('modalVendorId');
            const modalAction = document.getElementById('modalAction');
            const reasonField = document.getElementById('reasonField');
            const modalSubmitBtn = document.getElementById('modalSubmitBtn');
            
            modalVendorId.value = vendorId;
            modalAction.value = action;
            
            if (action === 'approve') {
                modalTitle.textContent = 'Approve Vendor';
                modalMessage.innerHTML = `Are you sure you want to approve <strong>${vendorName}</strong>?`;
                modalSubmitBtn.textContent = 'Approve Vendor';
                modalSubmitBtn.className = 'btn btn-success';
                reasonField.style.display = 'none';
            } else {
                modalTitle.textContent = 'Reject Vendor';
                modalMessage.innerHTML = `Are you sure you want to reject <strong>${vendorName}</strong>?`;
                modalSubmitBtn.textContent = 'Reject Vendor';
                modalSubmitBtn.className = 'btn btn-danger';
                reasonField.style.display = 'block';
            }
            
            modal.style.display = 'block';
        }
        
        // Enhanced vendor details view with real-time data
        function showVendorDetails(vendorId) {
            const modal = document.getElementById('vendorDetailsModal');
            const vendorRow = document.querySelector(`tr[data-vendor-id="${vendorId}"]`);
            
            if (!vendorRow) {
                alert('Vendor not found!');
                return;
            }
            
            const vendorName = vendorRow.querySelector('td:first-child strong').textContent;
            
            document.getElementById('vendorDetailName').textContent = vendorName;
            modal.setAttribute('data-vendor-id', vendorId);
            
            // Show loading for all tabs
            showLoadingForAllTabs();
            
            // Fetch vendor data via AJAX
            fetch(`admin_dashboard.php?ajax=get_vendor_data&vendor_id=${vendorId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success === false) {
                        throw new Error(data.error || 'Failed to load vendor data');
                    }
                    
                    // Load all tabs with real data
                    loadVendorBasicInfo(data.basic_info);
                    loadVendorContract(data.basic_info);
                    loadVendorCertifications(data.certifications, vendorId);
                    loadVendorPerformance(data.performance, vendorId);
                })
                .catch(error => {
                    console.error('Error fetching vendor data:', error);
                    showErrorForAllTabs(error.message);
                });
            
            modal.style.display = 'block';
        }
        
        function showLoadingForAllTabs() {
            const loadingHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #2c6bed;"></i>
                    <p style="margin-top: 15px;">Loading vendor data...</p>
                </div>
            `;
            
            document.getElementById('vendorBasicContent').innerHTML = loadingHTML;
            document.getElementById('vendorContractContent').innerHTML = loadingHTML;
            document.getElementById('vendorCertificationsContent').innerHTML = loadingHTML;
            document.getElementById('vendorPerformanceContent').innerHTML = loadingHTML;
        }
        
        function showErrorForAllTabs(errorMessage = 'Error loading vendor data. Please try again.') {
            const errorHTML = `
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    ${errorMessage}
                </div>
            `;
            
            document.getElementById('vendorBasicContent').innerHTML = errorHTML;
            document.getElementById('vendorContractContent').innerHTML = errorHTML;
            document.getElementById('vendorCertificationsContent').innerHTML = errorHTML;
            document.getElementById('vendorPerformanceContent').innerHTML = errorHTML;
        }
        
        function switchVendorTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('#vendorDetailsModal .vendor-tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById('vendor' + capitalizeFirst(tabName) + 'Tab').classList.add('active');
            
            // Update tab buttons
            document.querySelectorAll('#vendorDetailsModal .vendor-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
        }
        
        function capitalizeFirst(string) {
            return string.charAt(0).toUpperCase() + string.slice(1);
        }
        
        function loadVendorBasicInfo(vendor) {
            const content = document.getElementById('vendorBasicContent');
            
            if (!vendor) {
                content.innerHTML = '<div class="alert alert-error">Vendor data not found</div>';
                return;
            }
            
            content.innerHTML = `
                <div class="form-row">
                    <div class="form-group">
                        <label><strong>Company Name</strong></label>
                        <div>${vendor.vendor_name || 'N/A'}</div>
                    </div>
                    <div class="form-group">
                        <label><strong>Contact Email</strong></label>
                        <div>${vendor.user_email || 'N/A'}</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label><strong>Phone Number</strong></label>
                        <div>${vendor.contact_phone || 'N/A'}</div>
                    </div>
                    <div class="form-group">
                        <label><strong>Business Type</strong></label>
                        <div>${vendor.business_type || 'N/A'}</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label><strong>Business Registration</strong></label>
                        <div>${vendor.business_registration || 'N/A'}</div>
                    </div>
                    <div class="form-group">
                        <label><strong>Tax ID</strong></label>
                        <div>${vendor.tax_id || 'N/A'}</div>
                    </div>
                </div>
                <div class="form-group">
                    <label><strong>Address</strong></label>
                    <div>${vendor.address || 'N/A'}</div>
                </div>
                <div class="form-group">
                    <label><strong>Commodities</strong></label>
                    <div>${vendor.commodities || 'N/A'}</div>
                </div>
                <div class="form-group">
                    <label><strong>Geographic Coverage</strong></label>
                    <div>${vendor.geographic_coverage || 'N/A'}</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label><strong>Vendor Status</strong></label>
                        <div><span class="status-badge status-${vendor.vendor_status}">${vendor.vendor_status}</span></div>
                    </div>
                    <div class="form-group">
                        <label><strong>Onboarding Stage</strong></label>
                        <div><span class="onboarding-stage stage-${vendor.onboarding_stage}">${vendor.onboarding_stage}</span></div>
                    </div>
                </div>
                <div class="form-group">
                    <label><strong>Registration Date</strong></label>
                    <div>${vendor.created_at ? new Date(vendor.created_at).toLocaleDateString() : 'N/A'}</div>
                </div>
            `;
        }
        
        function loadVendorContract(vendor) {
            const content = document.getElementById('vendorContractContent');
            
            if (vendor.contract_start && vendor.contract_end) {
                content.innerHTML = `
                    <div class="form-row">
                        <div class="form-group">
                            <label><strong>Contract Start Date</strong></label>
                            <div>${vendor.contract_start}</div>
                        </div>
                        <div class="form-group">
                            <label><strong>Contract End Date</strong></label>
                            <div>${vendor.contract_end}</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><strong>Contract Value</strong></label>
                            <div>ZMW ${parseFloat(vendor.contract_value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        </div>
                        <div class="form-group">
                            <label><strong>Payment Terms</strong></label>
                            <div>${vendor.payment_terms || 'N/A'}</div>
                        </div>
                    </div>
                    <div style="margin-top: 20px;">
                        <button class="btn btn-primary" onclick="showContractModal(${vendor.vendor_id}, '${vendor.vendor_name}')">
                            <i class="fas fa-edit"></i> Update Contract
                        </button>
                    </div>
                `;
            } else {
                content.innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <i class="fas fa-file-contract fa-3x" style="color: #6c757d; margin-bottom: 15px;"></i>
                        <h4>No Contract Information</h4>
                        <p>This vendor does not have a contract yet.</p>
                        <button class="btn btn-primary" onclick="showContractModal(${vendor.vendor_id}, '${vendor.vendor_name}')">
                            <i class="fas fa-plus"></i> Create Contract
                        </button>
                    </div>
                `;
            }
        }
        
        function loadVendorCertifications(certifications, vendorId) {
            const content = document.getElementById('vendorCertificationsContent');
            
            if (certifications.length > 0) {
                let certificationsHtml = '<h4>Vendor Certifications</h4>';
                
                certifications.forEach(cert => {
                    certificationsHtml += `
                        <div class="certification-item" style="display: flex; justify-content: space-between; align-items: center; padding: 10px; border: 1px solid #e2e8f0; border-radius: 5px; margin-bottom: 10px;">
                            <div style="flex: 1;">
                                <strong>${cert.certification_name || cert}</strong>
                            </div>
                        </div>
                    `;
                });
                
                content.innerHTML = certificationsHtml;
            } else {
                content.innerHTML = `
                    <div style="text-align: center; padding: 20px;">
                        <p>No certifications found for this vendor.</p>
                    </div>
                `;
            }
        }
        
        function loadVendorPerformance(performance, vendorId) {
            const content = document.getElementById('vendorPerformanceContent');
            
            if (!performance || Object.keys(performance).length === 0) {
                content.innerHTML = `
                    <div style="text-align: center; padding: 20px;">
                        <p>No performance data available for this vendor.</p>
                        <p style="font-size: 12px; color: #666;">Performance data is generated after completed orders.</p>
                    </div>
                `;
                return;
            }
            
            const performanceScore = performance.performance_score || 0;
            const onTimeRate = performance.on_time_delivery_rate || 0;
            const qualityRate = performance.quality_acceptance_rate || 0;
            const defectRate = performance.avg_defect_rate || 0;
            const totalOrders = performance.total_orders || 0;
            const completedOrders = performance.completed_orders || 0;
            
            // Determine performance class
            let performanceClass = '';
            if (performanceScore >= 90) performanceClass = 'score-excellent';
            else if (performanceScore >= 75) performanceClass = 'score-good';
            else if (performanceScore >= 60) performanceClass = 'score-fair';
            else performanceClass = 'score-poor';
            
            let performanceHtml = `
                <div class="performance-metrics">
                    <div class="metric-card">
                        <span class="metric-value">${performanceScore.toFixed(1)}%</span>
                        <span class="metric-label">Overall Score</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-value">${completedOrders}</span>
                        <span class="metric-label">Completed Orders</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-value">${onTimeRate.toFixed(1)}%</span>
                        <span class="metric-label">On-Time Delivery</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-value">${qualityRate.toFixed(1)}%</span>
                        <span class="metric-label">Quality Rating</span>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <h4>Performance Details</h4>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
                        <div class="form-row">
                            <div class="form-group">
                                <label><strong>Total Orders</strong></label>
                                <div>${totalOrders}</div>
                            </div>
                            <div class="form-group">
                                <label><strong>Completed Orders</strong></label>
                                <div>
                                    <strong style="font-size: 18px; color: #28a745;">${completedOrders}</strong>
                                    ${totalOrders > 0 ? 
                                        `(${Math.round((completedOrders / totalOrders) * 100)}% completion rate)` : 
                                        '(No orders)'}
                                </div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><strong>On-Time Delivery Rate</strong></label>
                                <div>
                                    ${onTimeRate.toFixed(1)}%
                                    <div class="progress" style="margin-top: 5px; height: 6px;">
                                        <div class="progress-bar" style="width: ${onTimeRate}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><strong>Quality Acceptance Rate</strong></label>
                                <div>
                                    ${qualityRate.toFixed(1)}%
                                    <div class="progress" style="margin-top: 5px; height: 6px;">
                                        <div class="progress-bar" style="width: ${qualityRate}%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><strong>Average Defect Rate</strong></label>
                                <div>
                                    ${defectRate.toFixed(1)}%
                                    <div class="progress" style="margin-top: 5px; height: 6px;">
                                        <div class="progress-bar" style="width: ${defectRate}%; background: ${defectRate > 10 ? '#dc3545' : (defectRate > 5 ? '#ffc107' : '#28a745')};"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><strong>Performance Rating</strong></label>
                                <div><span class="performance-score ${performanceClass}">${performanceScore.toFixed(1)}%</span></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${completedOrders > 0 ? `
                <div style="margin-top: 20px;">
                    <h4>Order Completion Summary</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px;">
                        <div style="background: #e8f5e8; padding: 15px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: #28a745;">${completedOrders}</div>
                            <div style="font-size: 14px; color: #155724;">Completed Orders</div>
                        </div>
                        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 24px; font-weight: bold; color: #856404;">${totalOrders - completedOrders}</div>
                            <div style="font-size: 14px; color: #856404;">Pending/Other Orders</div>
                        </div>
                    </div>
                </div>
                ` : ''}
            `;
            
            content.innerHTML = performanceHtml;
        }
        
        function showContractModal(vendorId) {
            const modal = document.getElementById('vendorContractModal');
            const vendorName = document.querySelector(`tr[data-vendor-id="${vendorId}"] td:first-child strong`).textContent;
            
            document.getElementById('contractModalTitle').textContent = `Create Contract - ${vendorName}`;
            document.getElementById('contractVendorId').value = vendorId;
            
            // Set default dates
            const today = new Date();
            const oneYearLater = new Date(today);
            oneYearLater.setFullYear(today.getFullYear() + 1);
            
            document.getElementById('contract_start').value = today.toISOString().split('T')[0];
            document.getElementById('contract_end').value = oneYearLater.toISOString().split('T')[0];
            
            modal.style.display = 'block';
        }
        
        function openDeliveryTrackingModal() {
            const modal = document.getElementById('deliveryTrackingModal');
            
            // Calculate defect rate in real-time
            const defectCount = document.getElementById('defect_count');
            const totalItems = document.getElementById('total_items');
            const defectDisplay = document.getElementById('defect-rate-display');
            const defectValue = document.getElementById('defect-rate-value');
            
            function updateDefectRate() {
                const defects = parseInt(defectCount.value) || 0;
                const total = parseInt(totalItems.value) || 1;
                const rate = total > 0 ? (defects / total) * 100 : 0;
                
                defectValue.textContent = rate.toFixed(1) + '%';
                defectDisplay.style.display = 'block';
            }
            
            defectCount.addEventListener('input', updateDefectRate);
            totalItems.addEventListener('input', updateDefectRate);
            
            modal.style.display = 'block';
        }
        
        function openCreatePOModal() {
            const modal = document.getElementById('createPOModal');
            
            // Set minimum date to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('delivery_date').min = today;
            
            // Reset form
            document.getElementById('createPOModal').querySelector('form').reset();
            
            modal.style.display = 'block';
        }
        
        function loadVendorProducts(vendorId) {
            if (!vendorId) return;
            
            // Fetch vendor products via AJAX
            fetch(`admin_dashboard.php?ajax=get_vendor_products&vendor_id=${vendorId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success === false) {
                        throw new Error(data.error || 'Failed to load vendor products');
                    }
                    
                    const productNameInput = document.getElementById('product_name');
                    if (data.products.length > 0) {
                        // Create a datalist for product suggestions
                        let datalist = document.getElementById('vendor-products-datalist');
                        if (!datalist) {
                            datalist = document.createElement('datalist');
                            datalist.id = 'vendor-products-datalist';
                            productNameInput.parentNode.appendChild(datalist);
                        }
                        
                        datalist.innerHTML = '';
                        data.products.forEach(product => {
                            const option = document.createElement('option');
                            option.value = product.product_name;
                            option.textContent = product.product_name;
                            datalist.appendChild(option);
                        });
                        
                        productNameInput.setAttribute('list', 'vendor-products-datalist');
                    } else {
                        productNameInput.removeAttribute('list');
                    }
                })
                .catch(error => {
                    console.error('Error fetching vendor products:', error);
                });
        }
        
        function openCreateProductModal() {
            const modal = document.getElementById('createProductModal');
            document.getElementById('createProductModal').querySelector('form').reset();
            modal.style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        }
        
        // Auto-hide success messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
        
        // Inventory management functions
        function showInventoryDetails(productId) {
            const modal = document.getElementById('inventoryDetailsModal');
            const productRow = document.querySelector(`tr[data-product-id="${productId}"]`);
            
            if (!productRow) {
                alert('Product not found!');
                return;
            }
            
            const productName = productRow.querySelector('td:first-child strong').textContent;
            document.getElementById('inventoryDetailName').textContent = productName;
            
            // Show loading
            document.getElementById('inventoryDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #2c6bed;"></i>
                    <p style="margin-top: 15px;">Loading inventory data...</p>
                </div>
            `;
            
            // Fetch inventory data via AJAX
            fetch(`admin_dashboard.php?ajax=get_inventory_data&product_id=${productId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success === false) {
                        throw new Error(data.error || 'Failed to load inventory data');
                    }
                    
                    loadInventoryDetails(data.product, data.movements);
                })
                .catch(error => {
                    console.error('Error fetching inventory data:', error);
                    document.getElementById('inventoryDetailsContent').innerHTML = `
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            ${error.message}
                        </div>
                    `;
                });
            
            modal.style.display = 'block';
        }
        
        function loadInventoryDetails(product, movements) {
            const content = document.getElementById('inventoryDetailsContent');
            
            if (!product) {
                content.innerHTML = '<div class="alert alert-error">Product data not found</div>';
                return;
            }
            
            let movementsHtml = '';
            if (movements.length > 0) {
                movementsHtml = `
                    <h4>Recent Inventory Movements</h4>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Quantity</th>
                                    <th>Previous</th>
                                    <th>New</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                movements.forEach(movement => {
                    const movementType = movement.movement_type === 'in' ? 'Stock In' : 
                                       movement.movement_type === 'out' ? 'Stock Out' : 'Adjustment';
                    
                    movementsHtml += `
                        <tr>
                            <td>${new Date(movement.created_at).toLocaleDateString()}</td>
                            <td>${movementType}</td>
                            <td>${movement.quantity}</td>
                            <td>${movement.previous_stock}</td>
                            <td>${movement.new_stock}</td>
                            <td>${movement.notes || 'N/A'}</td>
                        </tr>
                    `;
                });
                
                movementsHtml += `
                            </tbody>
                        </table>
                    </div>
                `;
            } else {
                movementsHtml = '<p>No inventory movements recorded yet.</p>';
            }
            
            content.innerHTML = `
                <div class="form-row">
                    <div class="form-group">
                        <label><strong>Product Name</strong></label>
                        <div>${product.product_name}</div>
                    </div>
                    <div class="form-group">
                        <label><strong>SKU</strong></label>
                        <div>${product.sku}</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><strong>Category</strong></label>
                        <div>${product.category}</div>
                    </div>
                    <div class="form-group">
                        <label><strong>Unit Cost</strong></label>
                        <div>ZMW ${parseFloat(product.unit_cost).toFixed(2)}</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><strong>Current Stock</strong></label>
                        <div><strong>${product.current_stock}</strong></div>
                    </div>
                    <div class="form-group">
                        <label><strong>Reorder Level</strong></label>
                        <div>${product.reorder_level}</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><strong>Safety Stock</strong></label>
                        <div>${product.safety_stock}</div>
                    </div>
                    <div class="form-group">
                        <label><strong>Preferred Vendor</strong></label>
                        <div>${product.vendor_name || 'N/A'}</div>
                    </div>
                </div>
                
                ${product.description ? `
                <div class="form-group">
                    <label><strong>Description</strong></label>
                    <div>${product.description}</div>
                </div>
                ` : ''}
                
                ${movementsHtml}
            `;
        }
        
        function showInventoryAdjustment(productId, productName) {
            const modal = document.getElementById('inventoryAdjustmentModal');
            const productRow = document.querySelector(`tr[data-product-id="${productId}"]`);
            
            if (!productRow) {
                alert('Product not found!');
                return;
            }
            
            document.getElementById('adjustmentProductName').textContent = productName;
            document.getElementById('adjustmentProductId').value = productId;
            
            // Get current stock from the table
            const currentStock = parseInt(productRow.cells[3].querySelector('strong').textContent);
            document.getElementById('current-stock-value').textContent = currentStock;
            
            // Reset form
            document.getElementById('inventoryAdjustmentModal').querySelector('form').reset();
            
            // Show current stock info
            document.getElementById('current-stock-info').style.display = 'block';
            updateNewStockValue();
            
            modal.style.display = 'block';
        }
        
        function updateNewStockValue() {
            const adjustmentType = document.getElementById('adjustment_type').value;
            const quantity = parseInt(document.getElementById('adjustment_quantity').value) || 0;
            const currentStock = parseInt(document.getElementById('current-stock-value').textContent);
            
            let newStock = currentStock;
            
            if (adjustmentType === 'in') {
                newStock = currentStock + quantity;
            } else if (adjustmentType === 'out') {
                newStock = currentStock - quantity;
            } else if (adjustmentType === 'adjustment') {
                newStock = quantity;
            }
            
            document.getElementById('new-stock-value').textContent = newStock;
        }
        
        function showDeadStockResolution(alertId, productName) {
            const modal = document.getElementById('deadStockResolutionModal');
            
            document.getElementById('deadStockProductName').textContent = productName;
            document.getElementById('deadStockAlertId').value = alertId;
            
            // Reset form
            document.getElementById('deadStockResolutionModal').querySelector('form').reset();
            
            modal.style.display = 'block';
        }
        
        function createPOFromAlert(productId, productName, vendorId) {
            // Pre-fill the PO creation form
            openCreatePOModal();
            
            setTimeout(() => {
                document.getElementById('vendor_id').value = vendorId || '';
                document.getElementById('product_name').value = productName;
                
                // Calculate suggested quantity (reorder level + safety stock - current stock)
                const productRow = document.querySelector(`tr[data-product-id="${productId}"]`);
                if (productRow) {
                    const currentStock = parseInt(productRow.cells[3].querySelector('strong').textContent);
                    const reorderLevel = parseInt(productRow.cells[4].textContent);
                    const safetyStock = parseInt(productRow.cells[5].textContent);
                    
                    const suggestedQuantity = Math.max(reorderLevel + safetyStock - currentStock, safetyStock);
                    document.getElementById('quantity').value = suggestedQuantity;
                }
            }, 500);
        }
        
        function createPOFromProduct(productId, productName, vendorId) {
            createPOFromAlert(productId, productName, vendorId);
        }
        
        // Coming soon notification
        function showComingSoon() {
            alert('This feature is coming soon!');
            // Close sidebar on mobile
            if (window.innerWidth <= 768) {
                toggleSidebar();
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize active nav item
            updateActiveNav();
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const menuToggle = document.querySelector('.menu-toggle');
                
                if (window.innerWidth <= 768 && 
                    sidebar.classList.contains('active') &&
                    !sidebar.contains(event.target) &&
                    !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            });
            
            // Real-time inventory adjustment calculations
            const adjustmentType = document.getElementById('adjustment_type');
            const adjustmentQuantity = document.getElementById('adjustment_quantity');
            
            if (adjustmentType && adjustmentQuantity) {
                adjustmentType.addEventListener('change', updateNewStockValue);
                adjustmentQuantity.addEventListener('input', updateNewStockValue);
            }
            
            // Auto-refresh inventory alerts every 30 seconds
            setInterval(() => {
                // This would typically make an AJAX call to check for new alerts
                console.log('Checking for new inventory alerts...');
            }, 30000);
        });
    </script>
</body>
</html>