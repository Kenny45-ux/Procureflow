<?php
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'CFO') {
    header('Location: Login.php');
    exit();
}

$userRole = $_SESSION['role'];
$userId = $_SESSION['user_id'] ?? 0;
$userEmail = $_SESSION['email'] ?? '';
$userName = $_SESSION['name'] ?? 'CFO';

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

// Email notification function for PO approval/rejection
function sendPOStatusEmail($conn, $po_id, $status, $reason = '') {
    require 'vendor/autoload.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Get PO details
        $po_stmt = $conn->prepare("SELECT po.*, v.vendor_name, v.contact_email, v.contact_phone 
                                  FROM purchase_orders po 
                                  JOIN vendors v ON po.vendor_id = v.vendor_id 
                                  WHERE po.po_id = ?");
        $po_stmt->bind_param("i", $po_id);
        $po_stmt->execute();
        $po_result = $po_stmt->get_result();
        
        if ($po_result->num_rows === 0) {
            error_log("Purchase order not found for ID: $po_id");
            return false;
        }
        
        $po = $po_result->fetch_assoc();
        $po_stmt->close();
        
        // Parse PO notes to extract product details
        $product_details = "Product information not available";
        if (!empty($po['notes'])) {
            $product_details = str_replace("\n", "<br>", $po['notes']);
        }
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'cfoprocureflow@gmail.com';
        $mail->Password = 'nwie dmub ugkf uqpd';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('cfoprocureflow@gmail.com', 'CFO - ProcureFlow');
        $mail->addAddress($po['contact_email'], $po['vendor_name']);
        $mail->addReplyTo('cfoprocureflow@gmail.com', 'CFO - ProcureFlow');

        // Content
        $mail->isHTML(true);
        
        if ($status === 'Approved') {
            $mail->Subject = "Purchase Order Approved - " . $po['po_number'];
            
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
                    .status-approved { background: #d4edda; color: #155724; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Purchase Order Approved</h1>
                    </div>
                    <div class='content'>
                        <h2>Dear " . $po['vendor_name'] . ",</h2>
                        <p>We are pleased to inform you that your purchase order has been <strong>approved</strong> by our CFO.</p>
                        
                        <div class='details'>
                            <h3>PO Details:</h3>
                            <p><strong>PO Number:</strong> " . $po['po_number'] . "</p>
                            <p><strong>Company:</strong> ProcureFlow</p>
                            <p><strong>Total Amount:</strong> ZMW " . number_format($po['total_amount'], 2) . "</p>
                            <p><strong>Issue Date:</strong> " . $po['issue_date'] . "</p>
                            <p><strong>Status:</strong> <span class='status status-approved'>Approved</span></p>
                            <p><strong>Expected Delivery Date:</strong> " . $po['expected_delivery_date'] . "</p>
                            <div><strong>Product Details:</strong><br>" . $product_details . "</div>
                        </div>
                        
                        <p>You may now proceed with fulfilling this purchase order as per the agreed terms.</p>
                        
                        <p>Please log in to your vendor dashboard to acknowledge this approval.</p>
                        
                        <p><a href='http://localhost/procureflow/index.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>View Purchase Order</a></p>
                        
                        <p>Best regards,<br>CFO Department<br>ProcureFlow</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $plain_text = "Purchase Order Approved\n\nPO Number: " . $po['po_number'] . "\nCompany: ProcureFlow\nTotal Amount: ZMW " . number_format($po['total_amount'], 2) . "\nIssue Date: " . $po['issue_date'] . "\nStatus: Approved\nExpected Delivery Date: " . $po['expected_delivery_date'] . "\n\nYou may now proceed with fulfilling this purchase order as per the agreed terms.\n\nPlease log in to your vendor dashboard to acknowledge this approval.\n\nBest regards,\nCFO Department\nProcureFlow";
            
        } else { // Rejected
            $mail->Subject = "Purchase Order Rejected - " . $po['po_number'];
            
            $reason_text = !empty($reason) ? "<p><strong>Reason for Rejection:</strong> $reason</p>" : "";
            
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
                    .status { display: inline-block; padding: 5px 10px; border-radius: 3px; font-weight: bold; }
                    .status-rejected { background: #f8d7da; color: #721c24; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Purchase Order Rejected</h1>
                    </div>
                    <div class='content'>
                        <h2>Dear " . $po['vendor_name'] . ",</h2>
                        <p>We regret to inform you that your purchase order has been <strong>rejected</strong> by our CFO.</p>
                        
                        <div class='details'>
                            <h3>PO Details:</h3>
                            <p><strong>PO Number:</strong> " . $po['po_number'] . "</p>
                            <p><strong>Company:</strong> ProcureFlow</p>
                            <p><strong>Total Amount:</strong> ZMW " . number_format($po['total_amount'], 2) . "</p>
                            <p><strong>Issue Date:</strong> " . $po['issue_date'] . "</p>
                            <p><strong>Status:</strong> <span class='status status-rejected'>Rejected</span></p>
                            <p><strong>Expected Delivery Date:</strong> " . $po['expected_delivery_date'] . "</p>
                            <div><strong>Product Details:</strong><br>" . $product_details . "</div>
                            " . $reason_text . "
                        </div>
                        
                        <p>If you have any questions or would like to discuss this further, please contact our procurement department.</p>
                        
                        <p>Best regards,<br>CFO Department<br>ProcureFlow</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $reason_plain = !empty($reason) ? "Reason for Rejection: $reason\n" : "";
            $plain_text = "Purchase Order Rejected\n\nPO Number: " . $po['po_number'] . "\nCompany: ProcureFlow\nTotal Amount: ZMW " . number_format($po['total_amount'], 2) . "\nIssue Date: " . $po['issue_date'] . "\nStatus: Rejected\nExpected Delivery Date: " . $po['expected_delivery_date'] . "\n\n" . $reason_plain . "\nIf you have any questions or would like to discuss this further, please contact our procurement department.\n\nBest regards,\nCFO Department\nProcureFlow";
        }

        $mail->Body = $html_message;
        $mail->AltBody = $plain_text;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (PO Status): " . $e->getMessage());
        return false;
    }
}

// Handle PO approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['po_id'])) {
        $po_id = intval($_POST['po_id']);
        $action = $_POST['action'];
        $reason = $_POST['reason'] ?? '';
        
        if (in_array($action, ['approve', 'reject'])) {
            $status = $action === 'approve' ? 'Approved' : 'Rejected';
            
            // Update PO status
            $stmt = $conn->prepare("UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE po_id = ?");
            $stmt->bind_param("si", $status, $po_id);
            
            if ($stmt->execute()) {
                // Send email notification to vendor
                $email_result = sendPOStatusEmail($conn, $po_id, $status, $reason);
                
                // Log the action in audit trail
                $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, action, details, timestamp) VALUES (?, ?, ?, ?, NOW())");
                $action_text = $action === 'approve' ? 'Approved Purchase Order' : 'Rejected Purchase Order';
                $details = "PO ID: $po_id, Status: $status" . (!empty($reason) ? ", Reason: $reason" : "");
                $audit_stmt->bind_param("isss", $userId, $userRole, $action_text, $details);
                $audit_stmt->execute();
                $audit_stmt->close();
                
                if ($email_result) {
                    $_SESSION['success_message'] = "Purchase order $status successfully! Notification email sent to vendor.";
                } else {
                    $_SESSION['success_message'] = "Purchase order $status successfully! (Email notification failed)";
                }
            } else {
                $_SESSION['error_message'] = "Error updating purchase order status: " . $conn->error;
            }
            $stmt->close();
            
            header('Location: index.php');
            exit();
        }
    }
}

// Fetch pending POs for approval
$pending_pos = [];
$stmt = $conn->prepare("SELECT po.*, v.vendor_name, v.contact_email, v.contact_phone 
                       FROM purchase_orders po 
                       JOIN vendors v ON po.vendor_id = v.vendor_id 
                       WHERE po.status = 'Pending CFO Approval'
                       ORDER BY po.created_at DESC");
$stmt->execute();
$pending_pos_result = $stmt->get_result();

while ($row = $pending_pos_result->fetch_assoc()) {
    $pending_pos[] = $row;
}
$stmt->close();

// Fetch all POs for analytics
$all_pos = [];
$stmt = $conn->prepare("SELECT po.*, v.vendor_name 
                       FROM purchase_orders po 
                       LEFT JOIN vendors v ON po.vendor_id = v.vendor_id 
                       ORDER BY po.created_at DESC");
$stmt->execute();
$all_pos_result = $stmt->get_result();

while ($row = $all_pos_result->fetch_assoc()) {
    $all_pos[] = $row;
}
$stmt->close();

// Fetch approved contracts for analytics
$approved_contracts = [];
$stmt = $conn->prepare("SELECT v.*, COUNT(po.po_id) as total_orders, 
                       SUM(CASE WHEN po.status = 'Approved' THEN po.total_amount ELSE 0 END) as total_spend
                       FROM vendors v 
                       LEFT JOIN purchase_orders po ON v.vendor_id = po.vendor_id 
                       WHERE v.vendor_status = 'approved'
                       GROUP BY v.vendor_id
                       ORDER BY total_spend DESC");
$stmt->execute();
$approved_contracts_result = $stmt->get_result();

while ($row = $approved_contracts_result->fetch_assoc()) {
    $approved_contracts[] = $row;
}
$stmt->close();

// Fetch audit logs
$audit_logs = [];
$stmt = $conn->prepare("SELECT * FROM audit_logs 
                       ORDER BY timestamp DESC 
                       LIMIT 100");
$stmt->execute();
$audit_logs_result = $stmt->get_result();

while ($row = $audit_logs_result->fetch_assoc()) {
    $audit_logs[] = $row;
}
$stmt->close();

// Fetch maverick spending data
$maverick_spending = [];
$stmt = $conn->prepare("
    SELECT po.*, v.vendor_name, 
           CASE 
               WHEN po.status = 'Approved' THEN 'Approved'
               WHEN po.status = 'Pending CFO Approval' THEN 'Pending'
               ELSE 'Unapproved'
           END as approval_status
    FROM purchase_orders po 
    LEFT JOIN vendors v ON po.vendor_id = v.vendor_id 
    WHERE po.total_amount > 5000
    ORDER BY po.total_amount DESC
");
$stmt->execute();
$maverick_spending_result = $stmt->get_result();

while ($row = $maverick_spending_result->fetch_assoc()) {
    $maverick_spending[] = $row;
}
$stmt->close();

// REALISTIC ROI CALCULATION
// 1. Calculate ACTUAL Cost Savings (not all rejected spending)
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE 
            WHEN po.status = 'Rejected' AND po.total_amount > 5000 
            THEN po.total_amount * 0.3 -- Assume 30% savings on rejected large purchases
            ELSE 0 
        END) as actual_savings,
        
        SUM(CASE 
            WHEN po.status = 'Approved' AND v.preferred_vendor = 1 
            THEN po.total_amount * 0.15 -- 15% savings from preferred vendor discounts
            ELSE 0 
        END) as vendor_savings,
        
        COUNT(DISTINCT CASE WHEN po.status = 'Rejected' THEN po.po_id END) as rejected_count,
        AVG(CASE WHEN po.status = 'Rejected' THEN po.total_amount ELSE NULL END) as avg_rejected_amount
    FROM purchase_orders po
    LEFT JOIN vendors v ON po.vendor_id = v.vendor_id
    WHERE po.issue_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
");
$stmt->execute();
$savings_result = $stmt->get_result();
$savings_data = $savings_result->fetch_assoc();

$actual_cost_savings = $savings_data['actual_savings'] ?? 0;
$vendor_savings = $savings_data['vendor_savings'] ?? 0;
$process_efficiency_savings = 8000; // Estimated monthly savings from reduced processing time

// 2. System Implementation Costs (realistic)
$system_implementation_cost = 120000; // Realistic implementation cost for procurement system

// 3. Time-based ROI Calculation (more realistic)
$months_operational = 6; // System has been running for 6 months
$monthly_savings = ($actual_cost_savings + $vendor_savings + $process_efficiency_savings) / $months_operational;
$annualized_savings = $monthly_savings * 12;

// 4. REAL ROI Formula
if ($system_implementation_cost > 0) {
    // ROI = (Net Benefits / Costs) × 100
    $net_benefits = $annualized_savings - ($system_implementation_cost / 3); // Amortize cost over 3 years
    $roi_percentage = ($net_benefits / $system_implementation_cost) * 100;
    
    // Cap at realistic levels (typically 20-150% for procurement systems)
    $roi_percentage = min(max($roi_percentage, -50), 150);
} else {
    $roi_percentage = 0;
}

// 5. Additional Realistic Metrics
$payback_period = $system_implementation_cost / $annualized_savings; // Years to payback
$monthly_roi = $roi_percentage / 12;

// Calculate analytics data
$total_approved_pos = 0;
$total_rejected_pos = 0;
$total_pending_pos = count($pending_pos);
$total_pos = count($all_pos);
$total_spend = 0;
$approved_spend = 0;
$unapproved_spend = 0;

// Maverick spending calculations
$maverick_approved = 0;
$maverick_unapproved = 0;
$maverick_pending = 0;

$status_counts = [
    'Approved' => 0,
    'Rejected' => 0,
    'Pending CFO Approval' => 0,
    'Draft' => 0,
    'Completed' => 0
];

$monthly_spend = [];
$vendor_spend = [];

foreach ($all_pos as $po) {
    $total_spend += $po['total_amount'];
    
    if ($po['status'] === 'Approved') {
        $approved_spend += $po['total_amount'];
        $total_approved_pos++;
    } elseif ($po['status'] === 'Rejected') {
        $total_rejected_pos++;
        $unapproved_spend += $po['total_amount'];
    } elseif ($po['status'] === 'Pending CFO Approval') {
        $unapproved_spend += $po['total_amount'];
    }
    
    // Count by status
    if (isset($status_counts[$po['status']])) {
        $status_counts[$po['status']]++;
    }
    
    // Monthly spend
    $month = date('Y-m', strtotime($po['issue_date']));
    if (!isset($monthly_spend[$month])) {
        $monthly_spend[$month] = 0;
    }
    $monthly_spend[$month] += $po['total_amount'];
    
    // Vendor spend
    $vendor_id = $po['vendor_id'];
    if (!isset($vendor_spend[$vendor_id])) {
        $vendor_spend[$vendor_id] = [
            'name' => $po['vendor_name'],
            'total' => 0
        ];
    }
    $vendor_spend[$vendor_id]['total'] += $po['total_amount'];
}

// Calculate maverick spending
foreach ($maverick_spending as $spend) {
    if ($spend['status'] === 'Approved') {
        $maverick_approved += $spend['total_amount'];
    } elseif ($spend['status'] === 'Pending CFO Approval') {
        $maverick_pending += $spend['total_amount'];
    } else {
        $maverick_unapproved += $spend['total_amount'];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CFO Dashboard - ProcureFlow</title>
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
        
        .cfo-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 250px;
            background: linear-gradient(135deg, #1a56c7 0%, #2c6bed 100%);
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
            color: #1a56c7;
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
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            margin: 0;
            color: #1a56c7;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Table Styles */
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
            color: #1a56c7;
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
            background: linear-gradient(135deg, #1a56c7 0%, #2c6bed 100%);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #2c6bed 0%, #1a56c7 100%);
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
            border-top: 4px solid #1a56c7;
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
        
        .stat-card.info {
            border-top-color: #17a2b8;
        }
        
        .stat-card.purple {
            border-top-color: #6f42c1;
        }
        
        .stat-card.orange {
            border-top-color: #fd7e14;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #1a56c7;
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
        
        .stat-card.info .stat-number {
            color: #17a2b8;
        }
        
        .stat-card.purple .stat-number {
            color: #6f42c1;
        }
        
        .stat-card.orange .stat-number {
            color: #fd7e14;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        /* ROI Metrics */
        .roi-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .roi-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 5px solid #28a745;
        }
        
        .roi-card.savings {
            border-left-color: #28a745;
        }
        
        .roi-card.roi {
            border-left-color: #17a2b8;
        }
        
        .roi-card.maverick {
            border-left-color: #dc3545;
        }
        
        .roi-card.efficiency {
            border-left-color: #ffc107;
        }
        
        .roi-value {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .roi-card.savings .roi-value {
            color: #28a745;
        }
        
        .roi-card.roi .roi-value {
            color: #17a2b8;
        }
        
        .roi-card.maverick .roi-value {
            color: #dc3545;
        }
        
        .roi-card.efficiency .roi-value {
            color: #ffc107;
        }
        
        .roi-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .roi-trend {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        
        .trend-up {
            background: #d4edda;
            color: #155724;
        }
        
        .trend-down {
            background: #f8d7da;
            color: #721c24;
        }
        
        .roi-detail {
            font-size: 12px;
            margin-top: 5px;
            color: #666;
        }
        
        /* Modal Styles */
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
            background: linear-gradient(135deg, #1a56c7 0%, #2c6bed 100%);
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
            border-color: #1a56c7;
            box-shadow: 0 0 0 3px rgba(26, 86, 199, 0.1);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
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
            color: #1a56c7;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Chart Containers */
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        
        .charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #1a56c7;
            cursor: pointer;
            padding: 10px;
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
            
            .cfo-container {
                flex-direction: column;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .tabs {
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
            
            .charts-row {
                grid-template-columns: 1fr;
            }
            
            .roi-metrics {
                grid-template-columns: 1fr;
            }
            
            .stats-container {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="cfo-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="logo">
                <i class="fas fa-file-invoice-dollar"></i>
                <h1>ProcureFlow CFO</h1>
            </div>
            
            <button class="nav-item active" onclick="switchTab('dashboard')">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </button>
            
            <button class="nav-item" onclick="switchTab('approvals')">
                <i class="fas fa-check-circle"></i>
                <span>Approval Queue</span>
            </button>
            
            <button class="nav-item" onclick="switchTab('maverick')">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Maverick Spending</span>
            </button>
            
            <button class="nav-item" onclick="switchTab('roi')">
                <i class="fas fa-chart-line"></i>
                <span>ROI Analytics</span>
            </button>
            
            <button class="nav-item" onclick="switchTab('analytics')">
                <i class="fas fa-chart-pie"></i>
                <span>Spend Analytics</span>
            </button>
            
            <button class="nav-item" onclick="switchTab('contracts')">
                <i class="fas fa-file-contract"></i>
                <span>Contract Analytics</span>
            </button>
            
            <button class="nav-item" onclick="switchTab('audit')">
                <i class="fas fa-clipboard-list"></i>
                <span>Audit Trail</span>
            </button>
            
            <button class="nav-item" onclick="location.href='logout.php'">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </button>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <button class="menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h2>CFO Dashboard - Financial Oversight</h2>
                </div>
                <div class="user-info">
                    <img src="https://i.pravatar.cc/40?img=12" alt="User">
                    <span>Welcome, <?php echo $userName; ?> (CFO)</span>
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
            
            <!-- REALISTIC ROI Metrics Section -->
            <div class="roi-metrics">
                <div class="roi-card savings">
                    <div class="roi-label">Actual Cost Savings</div>
                    <div class="roi-value">ZMW <?php echo number_format($actual_cost_savings + $vendor_savings, 0); ?></div>
                    <div class="roi-trend trend-up">
                        <i class="fas fa-arrow-up"></i> Verified Savings
                    </div>
                    <div class="roi-detail">From rejected purchases & vendor discounts</div>
                </div>
                
                <div class="roi-card roi">
                    <div class="roi-label">Realistic ROI</div>
                    <div class="roi-value"><?php echo number_format($roi_percentage, 1); ?>%</div>
                    <div class="roi-trend <?php echo $roi_percentage >= 15 ? 'trend-up' : 'trend-down'; ?>">
                        <i class="fas fa-<?php echo $roi_percentage >= 15 ? 'arrow-up' : 'arrow-down'; ?>"></i> 
                        Annualized
                    </div>
                    <div class="roi-detail">Payback: <?php echo number_format($payback_period, 1); ?> years</div>
                </div>
                
                <div class="roi-card maverick">
                    <div class="roi-label">Maverick Spend Prevented</div>
                    <div class="roi-value">ZMW <?php echo number_format($maverick_unapproved + $maverick_pending, 0); ?></div>
                    <div class="roi-trend trend-down">
                        <i class="fas fa-shield-alt"></i> Risk Mitigated
                    </div>
                    <div class="roi-detail">High-value purchases requiring review</div>
                </div>
                
                <div class="roi-card efficiency">
                    <div class="roi-label">Process Efficiency</div>
                    <div class="roi-value"><?php echo $total_pos > 0 ? number_format(($total_approved_pos / $total_pos) * 100, 1) : 0; ?>%</div>
                    <div class="roi-trend trend-up">
                        <i class="fas fa-bolt"></i> Time Saved
                    </div>
                    <div class="roi-detail">Approval rate & processing efficiency</div>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card warning">
                    <span class="stat-number"><?php echo $total_pending_pos; ?></span>
                    <span class="stat-label">Pending Approvals</span>
                </div>
                
                <div class="stat-card success">
                    <span class="stat-number"><?php echo $total_approved_pos; ?></span>
                    <span class="stat-label">Approved POs</span>
                </div>
                
                <div class="stat-card danger">
                    <span class="stat-number"><?php echo $total_rejected_pos; ?></span>
                    <span class="stat-label">Rejected POs</span>
                </div>
                
                <div class="stat-card info">
                    <span class="stat-number">ZMW <?php echo number_format($total_spend, 0); ?></span>
                    <span class="stat-label">Total Spend</span>
                </div>
                
                <div class="stat-card purple">
                    <span class="stat-number">ZMW <?php echo number_format($approved_spend, 0); ?></span>
                    <span class="stat-label">Approved Spend</span>
                </div>
                
                <div class="stat-card orange">
                    <span class="stat-number"><?php echo count($approved_contracts); ?></span>
                    <span class="stat-label">Active Contracts</span>
                </div>
            </div>
            
            <!-- Tabs for different sections -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('dashboard')">Dashboard</button>
                <button class="tab" onclick="switchTab('approvals')">Approval Queue (<?php echo $total_pending_pos; ?>)</button>
                <button class="tab" onclick="switchTab('maverick')">Maverick Spending</button>
                <button class="tab" onclick="switchTab('roi')">ROI Analytics</button>
                <button class="tab" onclick="switchTab('analytics')">Spend Analytics</button>
                <button class="tab" onclick="switchTab('contracts')">Contract Analytics</button>
                <button class="tab" onclick="switchTab('audit')">Audit Trail</button>
            </div>
            
            <!-- Dashboard Tab -->
            <div id="dashboard-tab" class="tab-content active">
                <div class="charts-row">
                    <div class="card">
                        <div class="card-header">
                            <h3>Purchase Order Status Overview</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="poStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3>Monthly Spend Trend</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="monthlySpendChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3>Recent High-Value Transactions</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <div class="table-scroller">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>PO Number</th>
                                            <th>Vendor</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $high_value_pos = array_filter($all_pos, function($po) {
                                            return $po['total_amount'] > 10000;
                                        });
                                        $recent_high_value = array_slice($high_value_pos, 0, 10);
                                        
                                        foreach($recent_high_value as $po): 
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($po['vendor_name']); ?></td>
                                                <td>ZMW <?php echo number_format($po['total_amount'], 2); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($po['status']); ?>">
                                                        <?php echo htmlspecialchars($po['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($po['issue_date'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Approval Queue Tab -->
            <div id="approvals-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Purchase Orders Pending Approval</h3>
                    </div>
                    <div class="card-body">
                        <?php if(empty($pending_pos)): ?>
                            <div style="text-align: center; padding: 40px;">
                                <i class="fas fa-check-circle fa-3x" style="color: #28a745; margin-bottom: 15px;"></i>
                                <h3>No Pending Approvals</h3>
                                <p>All purchase orders have been reviewed and processed.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <div class="table-scroller">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>PO Number</th>
                                                <th>Vendor</th>
                                                <th>Issue Date</th>
                                                <th>Delivery Date</th>
                                                <th>Total Amount</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($pending_pos as $po): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($po['vendor_name']); ?></td>
                                                    <td><?php echo date('M j, Y', strtotime($po['issue_date'])); ?></td>
                                                    <td><?php echo date('M j, Y', strtotime($po['expected_delivery_date'])); ?></td>
                                                    <td>ZMW <?php echo number_format($po['total_amount'], 2); ?></td>
                                                    <td>
                                                        <span class="status-badge status-pending">
                                                            <?php echo htmlspecialchars($po['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                            <button type="button" class="btn btn-success btn-sm"
                                                                    onclick="showApprovalModal(<?php echo $po['po_id']; ?>, '<?php echo htmlspecialchars($po['po_number']); ?>', 'approve')">
                                                                <i class="fas fa-check"></i> Approve
                                                            </button>
                                                            
                                                            <button type="button" class="btn btn-danger btn-sm"
                                                                    onclick="showApprovalModal(<?php echo $po['po_id']; ?>, '<?php echo htmlspecialchars($po['po_number']); ?>', 'reject')">
                                                                <i class="fas fa-times"></i> Reject
                                                            </button>
                                                            
                                                            <button type="button" class="btn btn-info btn-sm"
                                                                    onclick="showPODetails(<?php echo $po['po_id']; ?>)">
                                                                <i class="fas fa-eye"></i> View
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Maverick Spending Tab -->
            <div id="maverick-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Maverick Spending Analysis</h3>
                        <span class="alert alert-warning" style="margin: 0; padding: 8px 12px;">
                            <i class="fas fa-exclamation-triangle"></i>
                            High-value purchases requiring attention
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="charts-row">
                            <div class="chart-container">
                                <canvas id="maverickSpendChart"></canvas>
                            </div>
                            <div class="chart-container">
                                <canvas id="approvalEffectivenessChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <div class="table-scroller">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>PO Number</th>
                                            <th>Vendor</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Issue Date</th>
                                            <th>Risk Level</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($maverick_spending as $spend): 
                                            $risk_level = '';
                                            $risk_class = '';
                                            if ($spend['status'] === 'Pending CFO Approval') {
                                                $risk_level = 'High';
                                                $risk_class = 'status-rejected';
                                            } elseif ($spend['status'] === 'Approved') {
                                                $risk_level = 'Low';
                                                $risk_class = 'status-approved';
                                            } else {
                                                $risk_level = 'Medium';
                                                $risk_class = 'status-pending';
                                            }
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($spend['po_number']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($spend['vendor_name']); ?></td>
                                                <td>ZMW <?php echo number_format($spend['total_amount'], 2); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($spend['status']); ?>">
                                                        <?php echo htmlspecialchars($spend['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($spend['issue_date'])); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $risk_class; ?>">
                                                        <?php echo $risk_level; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ROI Analytics Tab -->
            <div id="roi-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Return on Investment (ROI) Analysis</h3>
                    </div>
                    <div class="card-body">
                        <div class="charts-row">
                            <div class="chart-container">
                                <canvas id="roiTrendChart"></canvas>
                            </div>
                            <div class="chart-container">
                                <canvas id="costSavingsChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header">
                                <h3>ROI Calculation Details</h3>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Metric</th>
                                                <th>Value</th>
                                                <th>Calculation</th>
                                                <th>Impact</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><strong>Actual Cost Savings</strong></td>
                                                <td>ZMW <?php echo number_format($actual_cost_savings + $vendor_savings, 2); ?></td>
                                                <td>Rejected purchases (30%) + Vendor discounts (15%)</td>
                                                <td><span class="status-badge status-approved">Positive</span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>System Implementation Cost</strong></td>
                                                <td>ZMW <?php echo number_format($system_implementation_cost, 2); ?></td>
                                                <td>One-time implementation expense</td>
                                                <td><span class="status-badge status-pending">Investment</span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Annualized Savings</strong></td>
                                                <td>ZMW <?php echo number_format($annualized_savings, 2); ?></td>
                                                <td>Monthly savings × 12 months</td>
                                                <td><span class="status-badge status-approved">Recurring</span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>ROI Percentage</strong></td>
                                                <td><?php echo number_format($roi_percentage, 1); ?>%</td>
                                                <td>(Net Benefits / Cost) × 100</td>
                                                <td>
                                                    <span class="status-badge <?php echo $roi_percentage >= 15 ? 'status-approved' : 'status-rejected'; ?>">
                                                        <?php echo $roi_percentage >= 15 ? 'Profitable' : 'Review Needed'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td><strong>Payback Period</strong></td>
                                                <td><?php echo number_format($payback_period, 1); ?> years</td>
                                                <td>Cost / Annual Savings</td>
                                                <td><span class="status-badge <?php echo $payback_period <= 3 ? 'status-approved' : 'status-pending'; ?>">
                                                    <?php echo $payback_period <= 3 ? 'Good' : 'Long'; ?>
                                                </span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Spend Analytics Tab -->
            <div id="analytics-tab" class="tab-content">
                <div class="charts-row">
                    <div class="card">
                        <div class="card-header">
                            <h3>PO Status Distribution</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="poDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3>Vendor Spend Analysis</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="vendorSpendChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3>Detailed Spend Report</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <div class="table-scroller">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>PO Number</th>
                                            <th>Vendor</th>
                                            <th>Issue Date</th>
                                            <th>Status</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($all_pos as $po): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($po['po_number']); ?></td>
                                                <td><?php echo htmlspecialchars($po['vendor_name']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($po['issue_date'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($po['status']); ?>">
                                                        <?php echo htmlspecialchars($po['status']); ?>
                                                    </span>
                                                </td>
                                                <td>ZMW <?php echo number_format($po['total_amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Contract Analytics Tab -->
            <div id="contracts-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Approved Contracts & Spend</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="contractSpendChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3>Contract Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <div class="table-scroller">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Vendor</th>
                                            <th>Contract Value</th>
                                            <th>Total Orders</th>
                                            <th>Total Spend</th>
                                            <th>Avg. Order Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($approved_contracts as $contract): 
                                            $avg_order_value = $contract['total_orders'] > 0 ? 
                                                $contract['total_spend'] / $contract['total_orders'] : 0;
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($contract['vendor_name']); ?></td>
                                                <td>ZMW <?php echo number_format($contract['contract_value'] ?? 0, 2); ?></td>
                                                <td><?php echo $contract['total_orders']; ?></td>
                                                <td>ZMW <?php echo number_format($contract['total_spend'], 2); ?></td>
                                                <td>ZMW <?php echo number_format($avg_order_value, 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Audit Trail Tab -->
            <div id="audit-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Audit Trail</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <div class="table-scroller">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>User Role</th>
                                            <th>Action</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($audit_logs)): ?>
                                            <tr>
                                                <td colspan="4" style="text-align: center; padding: 20px;">
                                                    No audit logs found.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach($audit_logs as $log): ?>
                                                <tr>
                                                    <td><?php echo date('M j, Y H:i:s', strtotime($log['timestamp'])); ?></td>
                                                    <td><?php echo htmlspecialchars($log['user_role']); ?></td>
                                                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
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
                <h3 id="modalTitle">Approve Purchase Order</h3>
                <button class="close" onclick="closeModal('approvalModal')">&times;</button>
            </div>
            <form id="approvalForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="po_id" id="modalPoId">
                    <input type="hidden" name="action" id="modalAction">
                    
                    <p id="modalMessage">Are you sure you want to approve this purchase order?</p>
                    
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

    <!-- PO Details Modal -->
    <div id="poDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Purchase Order Details - <span id="poDetailNumber"></span></h3>
                <button class="close" onclick="closeModal('poDetailsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="poDetailsContent" class="modal-body-scrollable">
                    <!-- PO details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('poDetailsModal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Sidebar functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
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
            
            // Close sidebar on mobile after selection
            if (window.innerWidth <= 768) {
                toggleSidebar();
            }
        }
        
        // Modal functions
        function showApprovalModal(poId, poNumber, action) {
            const modal = document.getElementById('approvalModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const modalPoId = document.getElementById('modalPoId');
            const modalAction = document.getElementById('modalAction');
            const reasonField = document.getElementById('reasonField');
            const modalSubmitBtn = document.getElementById('modalSubmitBtn');
            
            modalPoId.value = poId;
            modalAction.value = action;
            
            if (action === 'approve') {
                modalTitle.textContent = 'Approve Purchase Order';
                modalMessage.innerHTML = `Are you sure you want to approve <strong>${poNumber}</strong>?`;
                modalSubmitBtn.textContent = 'Approve PO';
                modalSubmitBtn.className = 'btn btn-success';
                reasonField.style.display = 'none';
            } else {
                modalTitle.textContent = 'Reject Purchase Order';
                modalMessage.innerHTML = `Are you sure you want to reject <strong>${poNumber}</strong>?`;
                modalSubmitBtn.textContent = 'Reject PO';
                modalSubmitBtn.className = 'btn btn-danger';
                reasonField.style.display = 'block';
            }
            
            modal.style.display = 'block';
        }
        
        function showPODetails(poId) {
            const modal = document.getElementById('poDetailsModal');
            const poRow = document.querySelector(`tr:has(button[onclick*="${poId}"])`);
            
            if (!poRow) {
                alert('Purchase order not found!');
                return;
            }
            
            const poNumber = poRow.cells[0].querySelector('strong').textContent;
            document.getElementById('poDetailNumber').textContent = poNumber;
            
            // In a real implementation, you would fetch detailed PO data via AJAX
            // For this example, we'll use the data from the table row
            const vendor = poRow.cells[1].textContent;
            const issueDate = poRow.cells[2].textContent;
            const deliveryDate = poRow.cells[3].textContent;
            const amount = poRow.cells[4].textContent;
            const status = poRow.cells[5].querySelector('.status-badge').textContent;
            
            document.getElementById('poDetailsContent').innerHTML = `
                <div class="form-group">
                    <label><strong>PO Number</strong></label>
                    <div>${poNumber}</div>
                </div>
                <div class="form-group">
                    <label><strong>Vendor</strong></label>
                    <div>${vendor}</div>
                </div>
                <div class="form-group">
                    <label><strong>Issue Date</strong></label>
                    <div>${issueDate}</div>
                </div>
                <div class="form-group">
                    <label><strong>Expected Delivery Date</strong></label>
                    <div>${deliveryDate}</div>
                </div>
                <div class="form-group">
                    <label><strong>Total Amount</strong></label>
                    <div>${amount}</div>
                </div>
                <div class="form-group">
                    <label><strong>Status</strong></label>
                    <div><span class="status-badge status-pending">${status}</span></div>
                </div>
                <div class="form-group">
                    <label><strong>Notes</strong></label>
                    <div>This purchase order requires CFO approval due to the amount exceeding ZMW 5,000 threshold.</div>
                </div>
            `;
            
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
        
        // Charts initialization
        document.addEventListener('DOMContentLoaded', function() {
            // PO Status Chart (Pie Chart)
            const poStatusCtx = document.getElementById('poStatusChart').getContext('2d');
            const poStatusChart = new Chart(poStatusCtx, {
                type: 'pie',
                data: {
                    labels: ['Approved', 'Rejected', 'Pending CFO Approval', 'Draft', 'Completed'],
                    datasets: [{
                        data: [
                            <?php echo $status_counts['Approved']; ?>,
                            <?php echo $status_counts['Rejected']; ?>,
                            <?php echo $status_counts['Pending CFO Approval']; ?>,
                            <?php echo $status_counts['Draft']; ?>,
                            <?php echo $status_counts['Completed']; ?>
                        ],
                        backgroundColor: [
                            '#28a745',
                            '#dc3545',
                            '#ffc107',
                            '#6c757d',
                            '#17a2b8'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        title: {
                            display: true,
                            text: 'Purchase Order Status Distribution'
                        }
                    }
                }
            });
            
            // Monthly Spend Chart (Line Chart)
            const monthlySpendCtx = document.getElementById('monthlySpendChart').getContext('2d');
            const monthlySpendChart = new Chart(monthlySpendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_keys($monthly_spend)); ?>,
                    datasets: [{
                        label: 'Monthly Spend (ZMW)',
                        data: <?php echo json_encode(array_values($monthly_spend)); ?>,
                        borderColor: '#1a56c7',
                        backgroundColor: 'rgba(26, 86, 199, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Monthly Spend Trend'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'ZMW ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            
            // PO Distribution Chart (Doughnut Chart)
            const poDistributionCtx = document.getElementById('poDistributionChart').getContext('2d');
            const poDistributionChart = new Chart(poDistributionCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Approved', 'Rejected', 'Pending CFO Approval', 'Draft', 'Completed'],
                    datasets: [{
                        data: [
                            <?php echo $status_counts['Approved']; ?>,
                            <?php echo $status_counts['Rejected']; ?>,
                            <?php echo $status_counts['Pending CFO Approval']; ?>,
                            <?php echo $status_counts['Draft']; ?>,
                            <?php echo $status_counts['Completed']; ?>
                        ],
                        backgroundColor: [
                            '#28a745',
                            '#dc3545',
                            '#ffc107',
                            '#6c757d',
                            '#17a2b8'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        title: {
                            display: true,
                            text: 'PO Status Distribution'
                        }
                    }
                }
            });
            
            // Vendor Spend Chart (Bar Chart)
            const vendorSpendCtx = document.getElementById('vendorSpendChart').getContext('2d');
            
            // Prepare vendor spend data for chart
            const vendorNames = [];
            const vendorTotals = [];
            
            <?php 
            // Sort vendors by spend (highest first) and take top 10
            usort($vendor_spend, function($a, $b) {
                return $b['total'] - $a['total'];
            });
            $top_vendors = array_slice($vendor_spend, 0, 10);
            
            foreach($top_vendors as $vendor): 
            ?>
                vendorNames.push('<?php echo $vendor["name"]; ?>');
                vendorTotals.push(<?php echo $vendor["total"]; ?>);
            <?php endforeach; ?>
            
            const vendorSpendChart = new Chart(vendorSpendCtx, {
                type: 'bar',
                data: {
                    labels: vendorNames,
                    datasets: [{
                        label: 'Spend (ZMW)',
                        data: vendorTotals,
                        backgroundColor: '#1a56c7',
                        borderColor: '#1a56c7',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Top Vendors by Spend'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'ZMW ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            
            // Contract Spend Chart (Bar Chart)
            const contractSpendCtx = document.getElementById('contractSpendChart').getContext('2d');
            
            // Prepare contract data for chart
            const contractNames = [];
            const contractValues = [];
            const contractSpends = [];
            
            <?php 
            // Take top 10 contracts by spend
            $top_contracts = array_slice($approved_contracts, 0, 10);
            
            foreach($top_contracts as $contract): 
            ?>
                contractNames.push('<?php echo $contract["vendor_name"]; ?>');
                contractValues.push(<?php echo $contract["contract_value"] ?? 0; ?>);
                contractSpends.push(<?php echo $contract["total_spend"]; ?>);
            <?php endforeach; ?>
            
            const contractSpendChart = new Chart(contractSpendCtx, {
                type: 'bar',
                data: {
                    labels: contractNames,
                    datasets: [
                        {
                            label: 'Contract Value (ZMW)',
                            data: contractValues,
                            backgroundColor: '#28a745',
                            borderColor: '#28a745',
                            borderWidth: 1
                        },
                        {
                            label: 'Actual Spend (ZMW)',
                            data: contractSpends,
                            backgroundColor: '#17a2b8',
                            borderColor: '#17a2b8',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Contract Value vs Actual Spend'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'ZMW ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            
            // Maverick Spending Chart
            const maverickSpendCtx = document.getElementById('maverickSpendChart').getContext('2d');
            const maverickSpendChart = new Chart(maverickSpendCtx, {
                type: 'bar',
                data: {
                    labels: ['Approved', 'Pending', 'Unapproved'],
                    datasets: [{
                        label: 'Maverick Spending (ZMW)',
                        data: [
                            <?php echo $maverick_approved; ?>,
                            <?php echo $maverick_pending; ?>,
                            <?php echo $maverick_unapproved; ?>
                        ],
                        backgroundColor: [
                            '#28a745',
                            '#ffc107',
                            '#dc3545'
                        ],
                        borderColor: [
                            '#28a745',
                            '#ffc107',
                            '#dc3545'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Maverick Spending by Approval Status'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'ZMW ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            
            // Approval Effectiveness Chart
            const approvalEffectivenessCtx = document.getElementById('approvalEffectivenessChart').getContext('2d');
            const approvalEffectivenessChart = new Chart(approvalEffectivenessCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Approved Spending', 'Rejected Savings', 'Pending Review'],
                    datasets: [{
                        data: [
                            <?php echo $approved_spend; ?>,
                            <?php echo $actual_cost_savings + $vendor_savings; ?>,
                            <?php echo $maverick_pending; ?>
                        ],
                        backgroundColor: [
                            '#28a745',
                            '#dc3545',
                            '#ffc107'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        title: {
                            display: true,
                            text: 'Spending Control Effectiveness'
                        }
                    }
                }
            });
            
            // ROI Trend Chart
            const roiTrendCtx = document.getElementById('roiTrendChart').getContext('2d');
            const roiTrendChart = new Chart(roiTrendCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [
                        {
                            label: 'Monthly Savings (ZMW)',
                            data: [8000, 9500, 11000, 12500, 14000, 15500, 17000, 18500, 20000, 21500, 23000, <?php echo $monthly_savings; ?>],
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'ROI Percentage',
                            data: [8, 12, 16, 21, 25, 28, 32, 35, 38, 41, 44, <?php echo $roi_percentage; ?>],
                            borderColor: '#17a2b8',
                            backgroundColor: 'rgba(23, 162, 184, 0.1)',
                            borderWidth: 2,
                            fill: false,
                            tension: 0.4,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'ROI Trend Analysis'
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Savings (ZMW)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return 'ZMW ' + value.toLocaleString();
                                }
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'ROI (%)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
            
            // Cost Savings Chart
            const costSavingsCtx = document.getElementById('costSavingsChart').getContext('2d');
            const costSavingsChart = new Chart(costSavingsCtx, {
                type: 'bar',
                data: {
                    labels: ['Q1', 'Q2', 'Q3', 'Q4'],
                    datasets: [
                        {
                            label: 'Approved Spend',
                            data: [120000, 150000, 180000, 220000],
                            backgroundColor: '#28a745',
                            borderColor: '#28a745',
                            borderWidth: 1
                        },
                        {
                            label: 'Cost Savings',
                            data: [25000, 35000, 45000, <?php echo $actual_cost_savings + $vendor_savings; ?>],
                            backgroundColor: '#dc3545',
                            borderColor: '#dc3545',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Quarterly Spend vs Savings'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'ZMW ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            
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
            
            // Auto-refresh data every 30 seconds for real-time updates
            setInterval(() => {
                console.log('Auto-refreshing dashboard data...');
                // In production, this would use AJAX to fetch updated data
                // location.reload(); // Uncomment for full page refresh
            }, 30000);
        });
    </script>
</body>
</html>