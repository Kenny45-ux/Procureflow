<?php
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'Vendor') {
    header('Location: Login.php');
    exit();
}

$userRole = $_SESSION['role'];
$userId = $_SESSION['user_id'] ?? 0;
$userEmail = $_SESSION['email'] ?? '';
$userName = $_SESSION['name'] ?? 'Vendor';

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

// Get vendor ID from the vendors table
$vendor_id = 0;
$vendor_name = '';
$vendor_details = [];
$stmt = $conn->prepare("SELECT * FROM vendors WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $vendor_details = $result->fetch_assoc();
    $vendor_id = $vendor_details['vendor_id'];
    $vendor_name = $vendor_details['vendor_name'];
}
$stmt->close();

// Parse certifications
$certifications = [];
if (!empty($vendor_details['certifications'])) {
    $certs_data = json_decode($vendor_details['certifications'], true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $certifications = $certs_data;
    } else {
        $cert_list = explode(',', $vendor_details['certifications']);
        foreach ($cert_list as $cert) {
            $cert = trim($cert);
            if (!empty($cert)) {
                $certifications[] = ['certification_name' => $cert];
            }
        }
    }
}

// Handle PO acknowledgment and pricing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge_po'])) {
    $po_id = intval($_POST['po_id']);
    $unit_price = floatval($_POST['unit_price']);
    $total_amount = floatval($_POST['total_amount']);
    
    // Determine status based on amount
    $status = ($total_amount >= 5000) ? 'Pending CFO Approval' : 'Approved';
    
    // Update PO with vendor pricing and status
    $stmt = $conn->prepare("UPDATE purchase_orders SET 
        unit_price = ?, 
        total_amount = ?, 
        status = ?,
        acknowledged_at = NOW()
        WHERE po_id = ? AND vendor_id = ?");
    
    $stmt->bind_param("ddsii", $unit_price, $total_amount, $status, $po_id, $vendor_id);
    
    if ($stmt->execute()) {
        // Update vendor order counts
        updateVendorOrderCounts($conn, $vendor_id);
        
        // Send email notifications
        $email_sent = sendPOAcknowledgmentEmail($conn, $po_id, $vendor_name, $unit_price, $total_amount, $status);
        
        if ($email_sent) {
            $_SESSION['success_message'] = "Purchase order acknowledged successfully! " . 
                ($status === 'Pending CFO Approval' ? "CFO approval required (amount ≥ ZMW 5,000)." : "PO approved automatically.");
        } else {
            $_SESSION['success_message'] = "Purchase order acknowledged successfully! " . 
                ($status === 'Pending CFO Approval' ? "CFO approval required (amount ≥ ZMW 5,000)." : "PO approved automatically.") . 
                " (Email notification failed)";
        }
    } else {
        $_SESSION['error_message'] = "Error acknowledging purchase order: " . $conn->error;
    }
    $stmt->close();
    
    header('Location: vendor_dashboard.php');
    exit();
}

// Handle delivery completion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_delivery'])) {
    $po_id = intval($_POST['po_id']);
    $actual_delivery_date = $_POST['actual_delivery_date'];
    
    // Update PO with delivery information
    $stmt = $conn->prepare("UPDATE purchase_orders SET 
        actual_delivery_date = ?, 
        delivery_status = 'delivered',
        status = 'Completed'
        WHERE po_id = ? AND vendor_id = ?");
    
    $stmt->bind_param("sii", $actual_delivery_date, $po_id, $vendor_id);
    
    if ($stmt->execute()) {
        // Update vendor's order counts in vendors table
        updateVendorOrderCounts($conn, $vendor_id);
        
        // Send email to CFO
        $email_sent = sendDeliveryCompletionEmail($conn, $po_id, $vendor_name);
        
        if ($email_sent) {
            $_SESSION['success_message'] = "Delivery marked as completed successfully! CFO has been notified and vendor statistics updated.";
        } else {
            $_SESSION['success_message'] = "Delivery marked as completed successfully! (Email notification failed)";
        }
    } else {
        $_SESSION['error_message'] = "Error completing delivery: " . $conn->error;
    }
    $stmt->close();
    
    header('Location: vendor_dashboard.php');
    exit();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $contact_phone = $_POST['contact_phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $business_type = $_POST['business_type'] ?? '';
    $commodities = $_POST['commodities'] ?? '';
    
    // Handle certifications
    $certifications_input = $_POST['certifications'] ?? [];
    $certifications_json = json_encode($certifications_input);
    
    $stmt = $conn->prepare("UPDATE vendors SET 
        contact_phone = ?, 
        address = ?, 
        business_type = ?, 
        commodities = ?, 
        certifications = ?
        WHERE vendor_id = ?");
    
    $stmt->bind_param("sssssi", $contact_phone, $address, $business_type, $commodities, $certifications_json, $vendor_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Profile updated successfully!";
        // Refresh vendor details
        $stmt = $conn->prepare("SELECT * FROM vendors WHERE vendor_id = ?");
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $vendor_details = $result->fetch_assoc();
        }
        $stmt->close();
        
        // Update certifications array
        $certifications = [];
        if (!empty($vendor_details['certifications'])) {
            $certs_data = json_decode($vendor_details['certifications'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $certifications = $certs_data;
            } else {
                $cert_list = explode(',', $vendor_details['certifications']);
                foreach ($cert_list as $cert) {
                    $cert = trim($cert);
                    if (!empty($cert)) {
                        $certifications[] = ['certification_name' => $cert];
                    }
                }
            }
        }
    } else {
        $_SESSION['error_message'] = "Error updating profile: " . $conn->error;
    }
    
    header('Location: vendor_dashboard.php#profile');
    exit();
}

// Handle product catalog management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $product_name = $_POST['product_name'] ?? '';
    $product_category = $_POST['product_category'] ?? '';
    $unit_price = $_POST['unit_price'] ?? 0;
    $description = $_POST['description'] ?? '';
    $lead_time_days = $_POST['lead_time_days'] ?? 0;
    
    $stmt = $conn->prepare("INSERT INTO vendor_products (vendor_id, product_name, product_category, unit_price, description, lead_time_days) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issdsi", $vendor_id, $product_name, $product_category, $unit_price, $description, $lead_time_days);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Product added to catalog successfully!";
    } else {
        $_SESSION['error_message'] = "Error adding product: " . $conn->error;
    }
    $stmt->close();
    
    header('Location: vendor_dashboard.php#catalog');
    exit();
}

// Handle product update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $product_id = $_POST['product_id'] ?? '';
    $product_name = $_POST['product_name'] ?? '';
    $product_category = $_POST['product_category'] ?? '';
    $unit_price = $_POST['unit_price'] ?? 0;
    $description = $_POST['description'] ?? '';
    $lead_time_days = $_POST['lead_time_days'] ?? 0;
    
    $stmt = $conn->prepare("UPDATE vendor_products SET product_name=?, product_category=?, unit_price=?, description=?, lead_time_days=? WHERE product_id=? AND vendor_id=?");
    $stmt->bind_param("ssdsiii", $product_name, $product_category, $unit_price, $description, $lead_time_days, $product_id, $vendor_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Product updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating product: " . $conn->error;
    }
    $stmt->close();
    
    header('Location: vendor_dashboard.php#catalog');
    exit();
}

// Handle product deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    $product_id = $_POST['product_id'] ?? '';
    
    $stmt = $conn->prepare("DELETE FROM vendor_products WHERE product_id=? AND vendor_id=?");
    $stmt->bind_param("ii", $product_id, $vendor_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Product deleted successfully!";
    } else {
        $_SESSION['error_message'] = "Error deleting product: " . $conn->error;
    }
    $stmt->close();
    
    header('Location: vendor_dashboard.php#catalog');
    exit();
}

// Create vendor_products table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS vendor_products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT,
    product_name VARCHAR(255) NOT NULL,
    product_category VARCHAR(100) NOT NULL,
    unit_price DECIMAL(10,2) DEFAULT 0,
    description TEXT,
    lead_time_days INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE CASCADE
)");

// Function to update vendor order counts
function updateVendorOrderCounts($conn, $vendor_id) {
    // Calculate total orders (all orders regardless of status)
    $stmt = $conn->prepare("SELECT COUNT(*) as total_orders FROM purchase_orders WHERE vendor_id = ?");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $total_orders = $result->fetch_assoc()['total_orders'];
    $stmt->close();
    
    // Calculate completed orders
    $stmt = $conn->prepare("SELECT COUNT(*) as completed_orders FROM purchase_orders WHERE vendor_id = ? AND status = 'Completed'");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $completed_orders = $result->fetch_assoc()['completed_orders'];
    $stmt->close();
    
    // Update vendor table
    $stmt = $conn->prepare("UPDATE vendors SET total_orders = ?, total_orders_completed = ? WHERE vendor_id = ?");
    $stmt->bind_param("iii", $total_orders, $completed_orders, $vendor_id);
    $stmt->execute();
    $stmt->close();
}

// Update vendor counts on page load to ensure they are current
if ($vendor_id > 0) {
    updateVendorOrderCounts($conn, $vendor_id);
    
    // Refresh vendor details to get updated counts
    $stmt = $conn->prepare("SELECT * FROM vendors WHERE vendor_id = ?");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $vendor_details = $result->fetch_assoc();
    }
    $stmt->close();
}

// Fetch vendor's purchase orders
$purchase_orders = [];
if ($vendor_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM purchase_orders WHERE vendor_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $purchase_orders[] = $row;
    }
    $stmt->close();
}

// Fetch vendor's product catalog
$vendor_products = [];
if ($vendor_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM vendor_products WHERE vendor_id = ? ORDER BY product_name");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $vendor_products[] = $row;
    }
    $stmt->close();
}

// Calculate vendor performance metrics
$performance_metrics = [];
if ($vendor_id > 0) {
    // Get values directly from vendor table
    $performance_metrics['total_orders'] = $vendor_details['total_orders'] ?? 0;
    $performance_metrics['completed_orders'] = $vendor_details['total_orders_completed'] ?? 0;
    
    // Pending orders (calculated from purchase_orders table)
    $stmt = $conn->prepare("SELECT COUNT(*) as pending_orders FROM purchase_orders WHERE vendor_id = ? AND status IN ('Pending CFO Approval', 'Approved', 'Acknowledged', 'Pending Vendor Pricing')");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $performance_metrics['pending_orders'] = $result->fetch_assoc()['pending_orders'];
    $stmt->close();
    
    // Calculate on-time delivery rate
    $stmt = $conn->prepare("SELECT 
        COUNT(*) as total_delivered,
        SUM(CASE WHEN actual_delivery_date <= expected_delivery_date THEN 1 ELSE 0 END) as on_time_deliveries
        FROM purchase_orders 
        WHERE vendor_id = ? AND delivery_status = 'delivered'");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $delivery_data = $result->fetch_assoc();
    $stmt->close();
    
    $performance_metrics['on_time_rate'] = $delivery_data['total_delivered'] > 0 
        ? round(($delivery_data['on_time_deliveries'] / $delivery_data['total_delivered']) * 100, 1)
        : 0;
    
    // Calculate average delivery time (in days)
    $stmt = $conn->prepare("SELECT 
        AVG(DATEDIFF(actual_delivery_date, expected_delivery_date)) as avg_delivery_time
        FROM purchase_orders 
        WHERE vendor_id = ? AND delivery_status = 'delivered' AND actual_delivery_date IS NOT NULL");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $avg_delivery_data = $result->fetch_assoc();
    $stmt->close();
    
    $performance_metrics['avg_delivery_time'] = $avg_delivery_data['avg_delivery_time'] 
        ? round($avg_delivery_data['avg_delivery_time'], 1) 
        : 0;
}

$conn->close();

// Email function for PO acknowledgment with pricing
function sendPOAcknowledgmentEmail($conn, $po_id, $vendor_name, $unit_price, $total_amount, $status) {
    require 'vendor/autoload.php';
    
    // Get PO details
    $stmt = $conn->prepare("SELECT po_number, issue_date, expected_delivery_date, product_name, quantity FROM purchase_orders WHERE po_id = ?");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $po = $result->fetch_assoc();
    $stmt->close();
    
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

        // Send to CFO if amount requires approval
        if ($total_amount >= 5000) {
            $cfo_email = 'cfoprocureflow@gmail.com';
            
            $mail->clearAddresses();
            $mail->addAddress($cfo_email, 'CFO');
            $mail->addReplyTo('procurementdepartmenta@gmail.com', 'Procurement Department');

            $mail->Subject = "Purchase Order Approval Required - " . $po['po_number'];
            
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
                        <p>A purchase order has been priced by the vendor and requires your approval.</p>
                        
                        <div class='details'>
                            <h3>PO Details:</h3>
                            <p><strong>PO Number:</strong> " . $po['po_number'] . "</p>
                            <p><strong>Vendor:</strong> " . $vendor_name . "</p>
                            <p><strong>Total Amount:</strong> ZMW " . number_format($total_amount, 2) . "</p>
                            <p><strong>Issue Date:</strong> " . $po['issue_date'] . "</p>
                            <p><strong>Status:</strong> <span class='status status-pending'>" . $status . "</span></p>
                            <p><strong>Product:</strong> " . $po['product_name'] . "</p>
                            <p><strong>Quantity:</strong> " . $po['quantity'] . "</p>
                            <p><strong>Unit Price:</strong> ZMW " . number_format($unit_price, 2) . "</p>
                            <p><strong>Delivery Date:</strong> " . $po['expected_delivery_date'] . "</p>
                        </div>
                        
                        <p>Please log in to the ProcureFlow CFO Dashboard to review and approve this purchase order.</p>
                        
                        <p><a href='http://localhost/procureflow/index.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Review Purchase Order</a></p>
                        
                        <p>Best regards,<br>Procurement Department<br>ProcureFlow</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $plain_text = "Purchase Order Approval Required\n\nPO Number: " . $po['po_number'] . "\nVendor: " . $vendor_name . "\nTotal Amount: ZMW " . number_format($total_amount, 2) . "\nIssue Date: " . $po['issue_date'] . "\nStatus: " . $status . "\nProduct: " . $po['product_name'] . "\nQuantity: " . $po['quantity'] . "\nUnit Price: ZMW " . number_format($unit_price, 2) . "\nDelivery Date: " . $po['expected_delivery_date'] . "\n\nPlease log in to the ProcureFlow CFO Dashboard to review and approve this purchase order.\n\nBest regards,\nProcurement Department\nProcureFlow";

            $mail->Body = $html_message;
            $mail->AltBody = $plain_text;

            $cfo_result = $mail->send();
            
            // Reset for procurement department email
            $mail->clearAddresses();
        }
        
        // Send to Procurement Department
        $mail->addAddress('procurementdepartmenta@gmail.com', 'Procurement Department');
        $mail->addReplyTo('procurementdepartmenta@gmail.com', 'Procurement Department');

        $mail->Subject = "Purchase Order Priced - " . $po['po_number'];
        
        $html_message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #17a2b8; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
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
                    <h1>Purchase Order Priced by Vendor</h1>
                </div>
                <div class='content'>
                    <h2>Vendor Pricing Update</h2>
                    <p>The following purchase order has been priced by the vendor.</p>
                    
                    <div class='details'>
                        <h3>PO Details:</h3>
                        <p><strong>PO Number:</strong> " . $po['po_number'] . "</p>
                        <p><strong>Vendor:</strong> " . $vendor_name . "</p>
                        <p><strong>Total Amount:</strong> ZMW " . number_format($total_amount, 2) . "</p>
                        <p><strong>Issue Date:</strong> " . $po['issue_date'] . "</p>
                        <p><strong>Status:</strong> <span class='status " . ($status === 'Approved' ? 'status-approved' : 'status-pending') . "'>" . $status . "</span></p>
                        <p><strong>Product:</strong> " . $po['product_name'] . "</p>
                        <p><strong>Quantity:</strong> " . $po['quantity'] . "</p>
                        <p><strong>Unit Price:</strong> ZMW " . number_format($unit_price, 2) . "</p>
                        <p><strong>Delivery Date:</strong> " . $po['expected_delivery_date'] . "</p>
                    </div>
                    
                    <p><strong>Note:</strong> " . ($status === 'Pending CFO Approval' 
                        ? "This purchase order requires CFO approval (amount ≥ ZMW 5,000)." 
                        : "This purchase order has been auto-approved (amount < ZMW 5,000).") . "</p>
                    
                    <p>You can view the updated status in the Procurement Dashboard.</p>
                    
                    <p>Best regards,<br>Procurement Department<br>ProcureFlow</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $status_note = $status === 'Pending CFO Approval' 
            ? "This purchase order requires CFO approval (amount ≥ ZMW 5,000)." 
            : "This purchase order has been auto-approved (amount < ZMW 5,000).";
            
        $plain_text = "Purchase Order Priced by Vendor\n\nPO Number: " . $po['po_number'] . "\nVendor: " . $vendor_name . "\nTotal Amount: ZMW " . number_format($total_amount, 2) . "\nIssue Date: " . $po['issue_date'] . "\nStatus: " . $status . "\nProduct: " . $po['product_name'] . "\nQuantity: " . $po['quantity'] . "\nUnit Price: ZMW " . number_format($unit_price, 2) . "\nDelivery Date: " . $po['expected_delivery_date'] . "\n\nNote: " . $status_note . "\n\nYou can view the updated status in the Procurement Dashboard.\n\nBest regards,\nProcurement Department\nProcureFlow";

        $mail->Body = $html_message;
        $mail->AltBody = $plain_text;

        $procurement_result = $mail->send();
        
        return [
            'cfo_sent' => isset($cfo_result) ? $cfo_result : true,
            'procurement_sent' => $procurement_result
        ];
        
    } catch (Exception $e) {
        error_log("PHPMailer Error (PO Acknowledgment): " . $e->getMessage());
        return false;
    }
}

// Email function for delivery completion
function sendDeliveryCompletionEmail($conn, $po_id, $vendor_name) {
    require 'vendor/autoload.php';
    
    // Get PO details
    $stmt = $conn->prepare("SELECT po_number, total_amount, issue_date, expected_delivery_date, actual_delivery_date FROM purchase_orders WHERE po_id = ?");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $po = $result->fetch_assoc();
    $stmt->close();
    
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
        $mail->addAddress('cfoprocureflow@gmail.com', 'CFO');
        $mail->addReplyTo('procurementdepartmenta@gmail.com', 'Procurement Department');

        // Content
        $mail->isHTML(true);
        $mail->Subject = "Delivery Completed - " . $po['po_number'];
        
        // Calculate if delivery was on time
        $delivery_status = 'On Time';
        if ($po['actual_delivery_date'] > $po['expected_delivery_date']) {
            $delivery_status = 'Late';
        }
        
        $html_message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #17a2b8; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px; border: 1px solid #e2e8f0; }
                .details { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .status { display: inline-block; padding: 5px 10px; border-radius: 3px; font-weight: bold; }
                .status-ontime { background: #d4edda; color: #155724; }
                .status-late { background: #f8d7da; color: #721c24; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Delivery Completed</h1>
                </div>
                <div class='content'>
                    <h2>Vendor Delivery Completion Notification</h2>
                    <p>The following purchase order delivery has been marked as completed by the vendor.</p>
                    
                    <div class='details'>
                        <h3>PO Details:</h3>
                        <p><strong>PO Number:</strong> " . $po['po_number'] . "</p>
                        <p><strong>Vendor:</strong> " . $vendor_name . "</p>
                        <p><strong>Total Amount:</strong> ZMW " . number_format($po['total_amount'], 2) . "</p>
                        <p><strong>Issue Date:</strong> " . $po['issue_date'] . "</p>
                        <p><strong>Expected Delivery:</strong> " . $po['expected_delivery_date'] . "</p>
                        <p><strong>Actual Delivery:</strong> " . $po['actual_delivery_date'] . "</p>
                        <p><strong>Delivery Status:</strong> <span class='status status-" . strtolower(str_replace(' ', '', $delivery_status)) . "'>" . $delivery_status . "</span></p>
                    </div>
                    
                    <p>The vendor has completed delivery of this purchase order.</p>
                    
                    <p>This delivery performance will be reflected in the vendor's performance metrics.</p>
                    
                    <p>Best regards,<br>Procurement Department<br>ProcureFlow</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $plain_text = "Delivery Completed\n\nPO Number: " . $po['po_number'] . "\nVendor: " . $vendor_name . "\nTotal Amount: ZMW " . number_format($po['total_amount'], 2) . "\nIssue Date: " . $po['issue_date'] . "\nExpected Delivery: " . $po['expected_delivery_date'] . "\nActual Delivery: " . $po['actual_delivery_date'] . "\nDelivery Status: " . $delivery_status . "\n\nThe vendor has completed delivery of this purchase order.\n\nThis delivery performance will be reflected in the vendor's performance metrics.\n\nBest regards,\nProcurement Department\nProcureFlow";

        $mail->Body = $html_message;
        $mail->AltBody = $plain_text;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (Delivery Completion): " . $e->getMessage());
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Dashboard - ProcureFlow</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }
        
        .vendor-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
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
        
        /* Cards */
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
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            margin: 0;
            color: #2c6bed;
        }
        
        .card-body {
            padding: 20px;
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
        
        .stat-card.success {
            border-top-color: #28a745;
        }
        
        .stat-card.info {
            border-top-color: #17a2b8;
        }
        
        .stat-card.danger {
            border-top-color: #dc3545;
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
        
        .stat-card.success .stat-number {
            color: #28a745;
        }
        
        .stat-card.info .stat-number {
            color: #17a2b8;
        }
        
        .stat-card.danger .stat-number {
            color: #dc3545;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        /* Tables */
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
            background: #fff3cd; 
            color: #856404; 
        }
        
        .status-approved { 
            background: #d4edda; 
            color: #155724; 
        }
        
        .status-acknowledged { 
            background: #cce7ff; 
            color: #004085; 
        }
        
        .status-completed { 
            background: #d4edda; 
            color: #155724; 
        }
        
        .status-rejected { 
            background: #f8d7da; 
            color: #721c24; 
        }
        
        .status-pending-pricing {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-pending-cfo {
            background: #fff3cd;
            color: #856404;
        }
        
        .delivery-pending { 
            background: #fff3cd; 
            color: #856404; 
        }
        
        .delivery-delivered { 
            background: #d4edda; 
            color: #155724; 
        }
        
        .delivery-late { 
            background: #f8d7da; 
            color: #721c24; 
        }
        
        .contract-active {
            background: #d4edda;
            color: #155724;
        }
        
        .contract-expired {
            background: #f8d7da;
            color: #721c24;
        }
        
        .contract-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .product-active {
            background: #d4edda;
            color: #155724;
        }
        
        .product-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Buttons */
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-primary {
            background: #2c6bed;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1a56c7;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
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
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease-out;
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
            background: #2c6bed;
            color: white;
            padding: 15px 20px;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
        }
        
        .close {
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 14px;
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
            flex-wrap: wrap;
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
            min-width: 120px;
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
        
        /* Profile Section */
        .profile-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .profile-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
        }
        
        .profile-info h4 {
            margin-bottom: 15px;
            color: #2c6bed;
        }
        
        .info-item {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
        }
        
        .info-value {
            color: #6c757d;
        }
        
        /* Certifications */
        .certifications-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .certification-item {
            background: white;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        
        .certification-input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .certification-input-group input {
            flex: 1;
        }
        
        .no-certifications {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }
        
        .no-certifications i {
            font-size: 2rem;
            margin-bottom: 10px;
            color: #6c757d;
        }
        
        /* Contract Section */
        .contract-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .contract-status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
        }
        
        .contract-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .contract-metric {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .contract-metric-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c6bed;
            display: block;
        }
        
        .contract-metric-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .no-contract {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .no-contract i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #dee2e6;
        }
        
        /* Product Catalog */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .product-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            border-top: 4px solid #2c6bed;
        }
        
        .product-card h4 {
            margin: 0 0 10px 0;
            color: #2c6bed;
        }
        
        .product-category {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .product-price {
            font-size: 1.25rem;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 10px;
        }
        
        .product-description {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .product-meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #6c757d;
        }
        
        .no-products {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .no-products i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #dee2e6;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .stats-container {
                grid-template-columns: 1fr 1fr;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
            
            .profile-section {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .contract-metrics {
                grid-template-columns: 1fr 1fr;
            }
            
            .product-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="vendor-container">
        <!-- Header -->
        <div class="header">
            <h2>Vendor Dashboard - <?php echo htmlspecialchars($vendor_name); ?></h2>
            <div class="user-info">
                <img src="https://i.pravatar.cc/40?img=5" alt="User">
                <span>Welcome, <?php echo $userName; ?></span>
                <button class="btn btn-secondary" onclick="location.href='logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
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
        
        <!-- Performance Stats -->
        <div class="stats-container">
            <div class="stat-card">
                <span class="stat-number"><?php echo $vendor_details['total_orders'] ?? 0; ?></span>
                <span class="stat-label">Total Orders</span>
            </div>
            
            <div class="stat-card success">
                <span class="stat-number"><?php echo $vendor_details['total_orders_completed'] ?? 0; ?></span>
                <span class="stat-label">Completed Orders</span>
            </div>
            
            <div class="stat-card warning">
                <span class="stat-number"><?php echo $performance_metrics['pending_orders'] ?? 0; ?></span>
                <span class="stat-label">Pending Orders</span>
            </div>
            
            <div class="stat-card info">
                <span class="stat-number"><?php echo $performance_metrics['on_time_rate'] ?? 0; ?>%</span>
                <span class="stat-label">On-Time Delivery Rate</span>
            </div>
            
            <div class="stat-card">
                <span class="stat-number"><?php echo $performance_metrics['avg_delivery_time'] ?? 0; ?></span>
                <span class="stat-label">Avg. Delivery Time (Days)</span>
            </div>
            
            <div class="stat-card success">
                <span class="stat-number"><?php echo count($vendor_products); ?></span>
                <span class="stat-label">Products in Catalog</span>
            </div>
        </div>
        
        <!-- Tabs for different sections -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('orders')">Purchase Orders</button>
            <button class="tab" onclick="switchTab('catalog')">Product Catalog</button>
            <button class="tab" onclick="switchTab('profile')">Vendor Profile</button>
            <button class="tab" onclick="switchTab('contracts')">Contract Details</button>
        </div>
        
        <!-- Purchase Orders Tab -->
        <div id="orders-tab" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <h3>Your Purchase Orders</h3>
                </div>
                <div class="card-body">
                    <?php if(empty($purchase_orders)): ?>
                        <p style="text-align: center; padding: 20px;">No purchase orders found.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total Amount</th>
                                    <th>Issue Date</th>
                                    <th>Expected Delivery</th>
                                    <th>Status</th>
                                    <th>Delivery Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($purchase_orders as $po): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
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
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $po['status'])); ?>">
                                                <?php echo htmlspecialchars($po['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if($po['delivery_status']): ?>
                                                <span class="status-badge delivery-<?php echo $po['delivery_status']; ?>">
                                                    <?php echo ucfirst($po['delivery_status']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge delivery-pending">
                                                    Pending
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px;">
                                                <?php if($po['status'] === 'Pending Vendor Pricing'): ?>
                                                    <button class="btn btn-primary btn-sm" onclick="providePricing(<?php echo $po['po_id']; ?>, '<?php echo htmlspecialchars($po['product_name']); ?>', <?php echo $po['quantity']; ?>)">
                                                        <i class="fas fa-dollar-sign"></i> Provide Pricing
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if($po['status'] === 'Approved' && !$po['actual_delivery_date']): ?>
                                                    <button class="btn btn-success btn-sm" onclick="completeDelivery(<?php echo $po['po_id']; ?>)">
                                                        <i class="fas fa-truck"></i> Complete Delivery
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Product Catalog Tab -->
        <div id="catalog-tab" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>Product Catalog</h3>
                    <button class="btn btn-primary" onclick="openAddProductModal()">
                        <i class="fas fa-plus"></i> Add Product
                    </button>
                </div>
                <div class="card-body">
                    <?php if(empty($vendor_products)): ?>
                        <div class="no-products">
                            <i class="fas fa-box-open"></i>
                            <h4>No Products in Catalog</h4>
                            <p>Start by adding products that you can supply to the procurement team.</p>
                            <button class="btn btn-primary" onclick="openAddProductModal()" style="margin-top: 15px;">
                                <i class="fas fa-plus"></i> Add Your First Product
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="product-grid">
                            <?php foreach($vendor_products as $product): ?>
                                <div class="product-card">
                                    <h4><?php echo htmlspecialchars($product['product_name']); ?></h4>
                                    <div class="product-category">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($product['product_category']); ?>
                                    </div>
                                    <div class="product-price">
                                        ZMW <?php echo number_format($product['unit_price'], 2); ?>
                                    </div>
                                    <div class="product-description">
                                        <?php echo htmlspecialchars($product['description'] ?: 'No description provided'); ?>
                                    </div>
                                    <div class="product-meta">
                                        <span>
                                            <i class="fas fa-clock"></i> 
                                            <?php echo $product['lead_time_days']; ?> day lead time
                                        </span>
                                        <span class="status-badge product-<?php echo $product['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $product['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                    <div style="margin-top: 15px; display: flex; gap: 5px;">
                                        <button class="btn btn-warning btn-sm" onclick="editProduct(<?php echo $product['product_id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="deleteProduct(<?php echo $product['product_id']; ?>)">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Vendor Profile Tab -->
        <div id="profile-tab" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>Vendor Profile</h3>
                </div>
                <div class="card-body">
                    <div class="profile-section">
                        <div class="profile-info">
                            <h4>Company Information</h4>
                            <div class="info-item">
                                <div class="info-label">Company Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($vendor_details['vendor_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Business Type</div>
                                <div class="info-value"><?php echo htmlspecialchars($vendor_details['business_type'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Contact Email</div>
                                <div class="info-value"><?php echo htmlspecialchars($userEmail); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Contact Phone</div>
                                <div class="info-value"><?php echo htmlspecialchars($vendor_details['contact_phone'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Address</div>
                                <div class="info-value"><?php echo htmlspecialchars($vendor_details['address'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Business Registration</div>
                                <div class="info-value"><?php echo htmlspecialchars($vendor_details['business_registration'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Tax ID</div>
                                <div class="info-value"><?php echo htmlspecialchars($vendor_details['tax_id'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                        
                        <div class="profile-info">
                            <h4>Business Details</h4>
                            <div class="info-item">
                                <div class="info-label">Commodities</div>
                                <div class="info-value"><?php echo htmlspecialchars($vendor_details['commodities'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Vendor Status</div>
                                <div class="info-value">
                                    <span class="status-badge status-<?php echo $vendor_details['vendor_status'] ?? 'pending'; ?>">
                                        <?php echo ucfirst($vendor_details['vendor_status'] ?? 'Pending'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Onboarding Stage</div>
                                <div class="info-value">
                                    <span class="status-badge status-<?php echo $vendor_details['onboarding_stage'] ?? 'registered'; ?>">
                                        <?php echo ucfirst($vendor_details['onboarding_stage'] ?? 'Registered'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Registration Date</div>
                                <div class="info-value"><?php echo $vendor_details['created_at'] ? date('M j, Y', strtotime($vendor_details['created_at'])) : 'N/A'; ?></div>
                            </div>
                            
                            <h4 style="margin-top: 20px;">Vendor Statistics</h4>
                            <div class="info-item">
                                <div class="info-label">Total Orders</div>
                                <div class="info-value"><?php echo $vendor_details['total_orders'] ?? 0; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Completed Orders</div>
                                <div class="info-value"><?php echo $vendor_details['total_orders_completed'] ?? 0; ?></div>
                            </div>
                            
                            <h4 style="margin-top: 20px;">Certifications</h4>
                            <?php if(!empty($certifications)): ?>
                                <div class="certifications-list">
                                    <?php foreach($certifications as $cert): ?>
                                        <div class="certification-item">
                                            <i class="fas fa-certificate" style="color: #2c6bed;"></i>
                                            <div><?php echo htmlspecialchars($cert['certification_name'] ?? $cert); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="no-certifications">
                                    <i class="fas fa-certificate fa-2x"></i>
                                    <p>No certifications added yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                        <h4>Update Profile Information</h4>
                        <form method="POST">
                            <input type="hidden" name="update_profile" value="1">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="contact_phone">Contact Phone</label>
                                    <input type="text" class="form-control" id="contact_phone" name="contact_phone" value="<?php echo htmlspecialchars($vendor_details['contact_phone'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="business_type">Business Type</label>
                                    <input type="text" class="form-control" id="business_type" name="business_type" value="<?php echo htmlspecialchars($vendor_details['business_type'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="address">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($vendor_details['address'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="commodities">Commodities/Services</label>
                                <input type="text" class="form-control" id="commodities" name="commodities" value="<?php echo htmlspecialchars($vendor_details['commodities'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Certifications</label>
                                <div id="certifications-container">
                                    <?php if(!empty($certifications)): ?>
                                        <?php foreach($certifications as $index => $cert): ?>
                                            <div class="certification-input-group">
                                                <input type="text" class="form-control" name="certifications[]" value="<?php echo htmlspecialchars($cert['certification_name'] ?? $cert); ?>" placeholder="Certification name">
                                                <button type="button" class="btn btn-danger btn-sm" onclick="removeCertification(this)"><i class="fas fa-times"></i></button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="certification-input-group">
                                            <input type="text" class="form-control" name="certifications[]" placeholder="Certification name">
                                            <button type="button" class="btn btn-danger btn-sm" onclick="removeCertification(this)"><i class="fas fa-times"></i></button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="addCertification()" style="margin-top: 10px;">
                                    <i class="fas fa-plus"></i> Add Certification
                                </button>
                            </div>
                            
                            <div class="modal-footer" style="padding: 20px 0 0 0; border-top: 1px solid #e2e8f0; margin-top: 20px;">
                                <button type="submit" class="btn btn-primary">Update Profile</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Contract Details Tab -->
        <div id="contracts-tab" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>Contract Details</h3>
                </div>
                <div class="card-body">
                    <?php if(!empty($vendor_details['contract_start']) && !empty($vendor_details['contract_end'])): 
                        $contract_start = $vendor_details['contract_start'];
                        $contract_end = $vendor_details['contract_end'];
                        $contract_value = $vendor_details['contract_value'] ?? 0;
                        $payment_terms = $vendor_details['payment_terms'] ?? 'N/A';
                        
                        // Calculate contract status
                        $today = new DateTime();
                        $start_date = new DateTime($contract_start);
                        $end_date = new DateTime($contract_end);
                        
                        $contract_status = 'active';
                        if ($today < $start_date) {
                            $contract_status = 'pending';
                        } elseif ($today > $end_date) {
                            $contract_status = 'expired';
                        }
                        
                        // Calculate days remaining
                        $days_remaining = $today->diff($end_date)->days;
                        if ($today > $end_date) {
                            $days_remaining = 0;
                        }
                        
                        // Calculate total contract duration
                        $total_duration = $start_date->diff($end_date)->days;
                        
                        // Calculate progress percentage
                        $progress_percentage = 0;
                        if ($total_duration > 0) {
                            $days_passed = $start_date->diff($today)->days;
                            if ($days_passed < 0) $days_passed = 0;
                            if ($days_passed > $total_duration) $days_passed = $total_duration;
                            $progress_percentage = round(($days_passed / $total_duration) * 100);
                        }
                    ?>
                        <div class="contract-details">
                            <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 20px;">
                                <h4 style="margin: 0;">Active Contract</h4>
                                <span class="contract-status contract-<?php echo $contract_status; ?>">
                                    <?php echo ucfirst($contract_status); ?>
                                </span>
                            </div>
                            
                            <div class="contract-metrics">
                                <div class="contract-metric">
                                    <span class="contract-metric-value">ZMW <?php echo number_format($contract_value, 2); ?></span>
                                    <span class="contract-metric-label">Contract Value</span>
                                </div>
                                <div class="contract-metric">
                                    <span class="contract-metric-value"><?php echo $days_remaining; ?></span>
                                    <span class="contract-metric-label">Days Remaining</span>
                                </div>
                                <div class="contract-metric">
                                    <span class="contract-metric-value"><?php echo $total_duration; ?></span>
                                    <span class="contract-metric-label">Total Duration (Days)</span>
                                </div>
                                <div class="contract-metric">
                                    <span class="contract-metric-value"><?php echo $progress_percentage; ?>%</span>
                                    <span class="contract-metric-label">Progress</span>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <div style="margin: 20px 0;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                    <span>Contract Progress</span>
                                    <span><?php echo $progress_percentage; ?>%</span>
                                </div>
                                <div style="background: #e9ecef; height: 8px; border-radius: 4px; overflow: hidden;">
                                    <div style="background: #2c6bed; height: 100%; width: <?php echo $progress_percentage; ?>%; transition: width 0.3s ease;"></div>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-top: 5px; font-size: 12px; color: #6c757d;">
                                    <span>Start: <?php echo date('M j, Y', strtotime($contract_start)); ?></span>
                                    <span>End: <?php echo date('M j, Y', strtotime($contract_end)); ?></span>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="info-label">Contract Start Date</label>
                                    <div class="info-value"><?php echo date('F j, Y', strtotime($contract_start)); ?></div>
                                </div>
                                <div class="form-group">
                                    <label class="info-label">Contract End Date</label>
                                    <div class="info-value"><?php echo date('F j, Y', strtotime($contract_end)); ?></div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="info-label">Contract Value</label>
                                    <div class="info-value">ZMW <?php echo number_format($contract_value, 2); ?></div>
                                </div>
                                <div class="form-group">
                                    <label class="info-label">Payment Terms</label>
                                    <div class="info-value"><?php echo htmlspecialchars($payment_terms); ?></div>
                                </div>
                            </div>
                            
                            <?php if($contract_status === 'expired'): ?>
                                <div class="alert alert-error" style="margin-top: 20px;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong>Contract Expired:</strong> This contract has expired. Please contact the procurement department for renewal.
                                </div>
                            <?php elseif($contract_status === 'pending'): ?>
                                <div class="alert alert-warning" style="margin-top: 20px;">
                                    <i class="fas fa-clock"></i>
                                    <strong>Contract Pending:</strong> This contract will become active on <?php echo date('F j, Y', strtotime($contract_start)); ?>.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success" style="margin-top: 20px;">
                                    <i class="fas fa-check-circle"></i>
                                    <strong>Contract Active:</strong> This contract is currently active and valid.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-contract">
                            <i class="fas fa-file-contract"></i>
                            <h4>No Active Contract</h4>
                            <p>You don't have an active contract with ProcureFlow.</p>
                            <p style="font-size: 14px; margin-top: 10px;">Please contact the procurement department to establish a contract.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Provide Pricing Modal -->
    <div id="pricingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Provide Pricing for Purchase Order</h3>
                <span class="close" onclick="closeModal('pricingModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="acknowledge_po" value="1">
                <input type="hidden" name="po_id" id="pricingPOId">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="product_name">Product Name</label>
                        <input type="text" class="form-control" id="pricingProductName" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="quantity">Quantity</label>
                        <input type="number" class="form-control" id="pricingQuantity" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="unit_price">Unit Price (ZMW) *</label>
                        <input type="number" class="form-control" id="unit_price" name="unit_price" step="0.01" min="0" required oninput="calculateTotal()">
                    </div>
                    
                    <div class="form-group">
                        <label for="total_amount">Total Amount (ZMW)</label>
                        <input type="number" class="form-control" id="total_amount" name="total_amount" step="0.01" min="0" readonly>
                    </div>
                    
                    <div id="approval-notice" style="background: #fff3cd; padding: 10px; border-radius: 5px; margin-top: 10px; display: none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Note:</strong> This purchase order will require CFO approval (amount ≥ ZMW 5,000).
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('pricingModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Pricing</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Complete Delivery Modal -->
    <div id="deliveryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Complete Delivery</h3>
                <span class="close" onclick="closeModal('deliveryModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="complete_delivery" value="1">
                <input type="hidden" name="po_id" id="deliveryPOId">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="actual_delivery_date">Actual Delivery Date *</label>
                        <input type="date" class="form-control" id="actual_delivery_date" name="actual_delivery_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 10px;">
                        <p><strong>Note:</strong> Completing this delivery will update your performance metrics including on-time delivery rate and average delivery time.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deliveryModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark as Delivered</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit Product Modal -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="productModalTitle">Add Product to Catalog</h3>
                <span class="close" onclick="closeModal('productModal')">&times;</span>
            </div>
            <form method="POST" id="productForm">
                <input type="hidden" name="product_id" id="productId">
                <input type="hidden" name="add_product" id="addProductFlag" value="1">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="product_name">Product Name *</label>
                        <input type="text" class="form-control" id="product_name" name="product_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="product_category">Product Category *</label>
                        <select class="form-control" id="product_category" name="product_category" required>
                            <option value="">Select Category</option>
                            <option value="Electronics">Electronics</option>
                            <option value="Office Supplies">Office Supplies</option>
                            <option value="Raw Materials">Raw Materials</option>
                            <option value="Finished Goods">Finished Goods</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="IT Equipment">IT Equipment</option>
                            <option value="Furniture">Furniture</option>
                            <option value="Safety Equipment">Safety Equipment</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="unit_price">Unit Price (ZMW) *</label>
                            <input type="number" class="form-control" id="unit_price" name="unit_price" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="lead_time_days">Lead Time (Days) *</label>
                            <input type="number" class="form-control" id="lead_time_days" name="lead_time_days" min="0" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Product Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Describe the product, specifications, features, etc."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('productModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="productSubmitBtn">Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Product Confirmation Modal -->
    <div id="deleteProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Product</h3>
                <span class="close" onclick="closeModal('deleteProductModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="delete_product" value="1">
                <input type="hidden" name="product_id" id="deleteProductId">
                
                <div class="modal-body">
                    <p>Are you sure you want to delete this product from your catalog?</p>
                    <p>This action cannot be undone and will remove the product from the procurement team's view.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteProductModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Product</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function providePricing(poId, productName, quantity) {
            document.getElementById('pricingPOId').value = poId;
            document.getElementById('pricingProductName').value = productName;
            document.getElementById('pricingQuantity').value = quantity;
            
            // Reset form
            document.getElementById('unit_price').value = '';
            document.getElementById('total_amount').value = '';
            document.getElementById('approval-notice').style.display = 'none';
            
            document.getElementById('pricingModal').style.display = 'block';
        }
        
        function calculateTotal() {
            const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
            const quantity = parseFloat(document.getElementById('pricingQuantity').value) || 0;
            const totalAmount = unitPrice * quantity;
            
            document.getElementById('total_amount').value = totalAmount.toFixed(2);
            
            // Show/hide approval notice
            const approvalNotice = document.getElementById('approval-notice');
            if (totalAmount >= 5000) {
                approvalNotice.style.display = 'block';
            } else {
                approvalNotice.style.display = 'none';
            }
        }
        
        function completeDelivery(poId) {
            document.getElementById('deliveryPOId').value = poId;
            document.getElementById('deliveryModal').style.display = 'block';
        }
        
        function openAddProductModal() {
            document.getElementById('productModalTitle').textContent = 'Add Product to Catalog';
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
            document.getElementById('addProductFlag').value = '1';
            document.getElementById('addProductFlag').name = 'add_product';
            document.getElementById('productSubmitBtn').textContent = 'Add Product';
            document.getElementById('productModal').style.display = 'block';
        }
        
        function editProduct(productId) {
            // In a real application, you would fetch product details via AJAX
            // For now, we'll redirect to a separate edit page or show a modal with pre-filled data
            document.getElementById('productModalTitle').textContent = 'Edit Product';
            document.getElementById('productId').value = productId;
            document.getElementById('addProductFlag').value = '';
            document.getElementById('addProductFlag').name = 'update_product';
            document.getElementById('productSubmitBtn').textContent = 'Update Product';
            
            // Here you would populate the form with existing product data
            // For demo purposes, we'll just show the modal
            document.getElementById('productModal').style.display = 'block';
        }
        
        function deleteProduct(productId) {
            document.getElementById('deleteProductId').value = productId;
            document.getElementById('deleteProductModal').style.display = 'block';
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
            
            // Update URL hash for deep linking
            window.location.hash = tabName;
        }
        
        // Check URL hash on page load
        window.addEventListener('DOMContentLoaded', function() {
            if (window.location.hash) {
                const tabName = window.location.hash.substring(1);
                if (tabName === 'catalog' || tabName === 'profile' || tabName === 'contracts') {
                    switchTab(tabName);
                }
            }
        });
        
        // Certification management
        function addCertification() {
            const container = document.getElementById('certifications-container');
            const newInput = document.createElement('div');
            newInput.className = 'certification-input-group';
            newInput.innerHTML = `
                <input type="text" class="form-control" name="certifications[]" placeholder="Certification name">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeCertification(this)"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(newInput);
        }
        
        function removeCertification(button) {
            const container = document.getElementById('certifications-container');
            if (container.children.length > 1) {
                button.parentElement.remove();
            } else {
                // If it's the last one, just clear the input
                button.previousElementSibling.value = '';
            }
        }
    </script>
</body>
</html>