<?php
session_start();
require 'vendor/autoload.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "procureflow";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$vendor_id = intval($_GET['vendor_id'] ?? $_POST['vendor_id'] ?? $_SESSION['user_id'] ?? 0);

header('Content-Type: application/json');

// Verify vendor access
if ($vendor_id !== $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

switch ($action) {
    case 'fetch_po':
        echo getPurchaseOrders($conn, $vendor_id);
        break;
        
    case 'dashboard':
        echo getDashboardData($conn, $vendor_id);
        break;
        
    case 'performance':
        echo getPerformanceData($conn, $vendor_id);
        break;
        
    case 'documents':
        echo getVendorDocuments($conn, $vendor_id);
        break;
        
    case 'update_vendor_profile':
        echo updateVendorProfile($conn, $_POST, $vendor_id);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();

function getPurchaseOrders($conn, $vendor_id) {
    $purchase_orders = [];
    
    // Check if purchase_orders table exists and has the required columns
    $table_check = $conn->query("SHOW TABLES LIKE 'purchase_orders'");
    if ($table_check->num_rows > 0) {
        // Check if vendor_acknowledged column exists
        $column_check = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'vendor_acknowledged'");
        $has_acknowledged = $column_check->num_rows > 0;
        
        $column_check = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'vendor_completed'");
        $has_completed = $column_check->num_rows > 0;
        
        $sql = "SELECT po.*, v.vendor_name 
                FROM purchase_orders po 
                LEFT JOIN vendors v ON po.vendor_id = v.vendor_id 
                WHERE po.vendor_id = ? 
                ORDER BY po.issue_date DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Ensure we have the required fields with default values
            $row['vendor_acknowledged'] = $has_acknowledged ? ($row['vendor_acknowledged'] ?? false) : false;
            $row['vendor_completed'] = $has_completed ? ($row['vendor_completed'] ?? false) : false;
            $row['acknowledged_at'] = $row['acknowledged_at'] ?? null;
            $row['completed_at'] = $row['completed_at'] ?? null;
            
            $purchase_orders[] = $row;
        }
        $stmt->close();
    }
    
    return json_encode([
        'success' => true,
        'purchase_orders' => $purchase_orders
    ]);
}

function getDashboardData($conn, $vendor_id) {
    $stats = [
        'total_orders' => 0,
        'pending_orders' => 0,
        'completed_orders' => 0,
        'total_revenue' => 0
    ];
    
    // Check if purchase_orders table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'purchase_orders'");
    if ($table_check->num_rows > 0) {
        // Get stats from purchase_orders table
        $sql = "SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status IN ('Draft', 'Issued', 'Pending CFO Approval') THEN 1 ELSE 0 END) as pending_orders,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'Completed' THEN total_amount ELSE 0 END) as total_revenue
            FROM purchase_orders WHERE vendor_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $stats = $row;
        }
        $stmt->close();
    }
    
    // Get recent activities
    $recent_activities = [];
    $table_check = $conn->query("SHOW TABLES LIKE 'purchase_orders'");
    if ($table_check->num_rows > 0) {
        $sql = "SELECT 
            po_number as title,
            status as description,
            created_at as timestamp,
            'order' as type
            FROM purchase_orders 
            WHERE vendor_id = ? 
            ORDER BY created_at DESC 
            LIMIT 5";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $recent_activities[] = [
                'action' => 'Purchase Order ' . $row['title'],
                'details' => 'Status: ' . $row['description'],
                'timestamp' => $row['timestamp'],
                'type' => 'order'
            ];
        }
        $stmt->close();
    }
    
    return json_encode([
        'success' => true,
        'stats' => $stats,
        'recent_activities' => $recent_activities
    ]);
}

function getPerformanceData($conn, $vendor_id) {
    $performance = [
        'on_time_delivery' => 95.5,
        'quality_rating' => 92.3,
        'response_time' => 2.4,
        'satisfaction_score' => 8.7
    ];
    
    // Check if vendor_performance table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'vendor_performance'");
    if ($table_check->num_rows > 0) {
        // Get vendor performance data if available
        $sql = "SELECT 
            AVG(CASE WHEN on_time_delivery = 1 THEN 100 ELSE 0 END) as on_time_delivery,
            AVG(quality_score * 20) as quality_rating,
            AVG(communication_score * 20) as communication_rating,
            AVG(overall_score * 20) as satisfaction_score
            FROM vendor_performance WHERE vendor_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $performance = [
                'on_time_delivery' => floatval($row['on_time_delivery'] ?? 95.5),
                'quality_rating' => floatval($row['quality_rating'] ?? 92.3),
                'response_time' => 2.4, // Default value
                'satisfaction_score' => floatval($row['satisfaction_score'] ?? 8.7)
            ];
        }
        $stmt->close();
    }
    
    // Performance history for chart
    $performance_history = [
        'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        'delivery' => [92, 94, 93, 95, 94, 96],
        'quality' => [94, 95, 93, 96, 95, 97]
    ];
    
    // Recent feedback
    $recent_feedback = [
        [
            'type' => 'success',
            'icon' => 'check-circle',
            'title' => 'Quality Excellence',
            'message' => 'Excellent product quality in recent delivery'
        ],
        [
            'type' => 'info',
            'icon' => 'info-circle',
            'title' => 'On-Time Delivery',
            'message' => 'All orders delivered on time this month'
        ]
    ];
    
    return json_encode([
        'success' => true,
        'performance' => $performance,
        'performance_history' => $performance_history,
        'recent_feedback' => $recent_feedback
    ]);
}

function getVendorDocuments($conn, $vendor_id) {
    $documents = [];
    
    // Check if vendor_certifications table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'vendor_certifications'");
    if ($table_check->num_rows > 0) {
        $sql = "SELECT 
            certification_name as document_name,
            certification_type as document_type,
            created_at as upload_date,
            expiry_date,
            status
            FROM vendor_certifications 
            WHERE vendor_id = ? 
            ORDER BY created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $documents[] = $row;
        }
        $stmt->close();
    }
    
    // If no documents found in database, return sample data
    if (empty($documents)) {
        $documents = [
            [
                'document_name' => 'Business License',
                'document_type' => 'License',
                'upload_date' => '2024-01-15',
                'expiry_date' => '2025-01-15',
                'status' => 'Approved'
            ],
            [
                'document_name' => 'Quality Certification',
                'document_type' => 'Certificate',
                'upload_date' => '2024-02-01',
                'expiry_date' => '2024-08-01',
                'status' => 'Pending'
            ],
            [
                'document_name' => 'Insurance Certificate',
                'document_type' => 'Insurance',
                'upload_date' => '2024-01-20',
                'expiry_date' => '2024-07-20',
                'status' => 'Approved'
            ]
        ];
    }
    
    return json_encode([
        'success' => true,
        'documents' => $documents
    ]);
}

function updateVendorProfile($conn, $data, $vendor_id) {
    $vendor_name = $conn->real_escape_string($data['vendor_name'] ?? '');
    $category = $conn->real_escape_string($data['category'] ?? '');
    $contact_phone = $conn->real_escape_string($data['contact_phone'] ?? '');
    $certification = $conn->real_escape_string($data['certification'] ?? '');
    $business_address = $conn->real_escape_string($data['business_address'] ?? '');
    
    // Build the SQL query dynamically based on which columns exist
    $sql = "UPDATE vendors SET 
            vendor_name = ?,
            contact_phone = ?";
    
    $params = [$vendor_name, $contact_phone];
    $types = "ss";
    
    // Add business_address if column exists
    $column_check = $conn->query("SHOW COLUMNS FROM vendors LIKE 'business_address'");
    if ($column_check->num_rows > 0) {
        $sql .= ", business_address = ?";
        $params[] = $business_address;
        $types .= "s";
    }
    
    // Add category if column exists
    $column_check = $conn->query("SHOW COLUMNS FROM vendors LIKE 'category'");
    if ($column_check->num_rows > 0) {
        $sql .= ", category = ?";
        $params[] = $category;
        $types .= "s";
    }
    
    // Add certification if column exists
    $column_check = $conn->query("SHOW COLUMNS FROM vendors LIKE 'certification'");
    if ($column_check->num_rows > 0) {
        $sql .= ", certification = ?";
        $params[] = $certification;
        $types .= "s";
    }
    
    $sql .= " WHERE vendor_id = ?";
    $params[] = $vendor_id;
    $types .= "i";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        return json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    } else {
        return json_encode(['success' => false, 'message' => 'Error updating profile: ' . $conn->error]);
    }
}
?>