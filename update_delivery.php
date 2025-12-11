<?php
// update_delivery.php
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header('Location: login.php');
    exit();
}

include 'vendor_performance_calculator.php';

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "procureflow";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $po_id = intval($_POST['po_id']);
    $actual_delivery_date = $_POST['actual_delivery_date'];
    $quality_status = $_POST['quality_status'];
    $defect_count = intval($_POST['defect_count']);
    $total_items = intval($_POST['total_items']);
    $delivery_notes = $_POST['delivery_notes'] ?? '';

    // Update delivery status
    $success = updateDeliveryStatus($po_id, $actual_delivery_date, $quality_status, $defect_count, $total_items, $conn);

    if ($success) {
        $_SESSION['success_message'] = "Delivery recorded successfully! Vendor performance updated automatically.";
    } else {
        $_SESSION['error_message'] = "Error recording delivery. Please try again.";
    }
    
    $conn->close();
    header('Location: admin_dashboard.php');
    exit();
}
?>