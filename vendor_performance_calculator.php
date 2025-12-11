<?php
// vendor_performance_calculator.php

function calculateVendorPerformance($vendor_id, $conn) {
    // Calculate on-time delivery rate
    $delivery_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN delivery_status = 'delivered' AND actual_delivery_date <= expected_delivery_date THEN 1 ELSE 0 END) as on_time_orders,
            SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders
        FROM purchase_orders 
        WHERE vendor_id = ? AND delivery_status IN ('delivered', 'late')
    ");
    $delivery_stmt->bind_param("i", $vendor_id);
    $delivery_stmt->execute();
    $delivery_result = $delivery_stmt->get_result();
    $delivery_data = $delivery_result->fetch_assoc();
    $delivery_stmt->close();

    $on_time_rate = 0;
    if ($delivery_data['delivered_orders'] > 0) {
        $on_time_rate = ($delivery_data['on_time_orders'] / $delivery_data['delivered_orders']) * 100;
    }

    // Calculate average delivery time (in days)
    $delivery_time_stmt = $conn->prepare("
        SELECT AVG(DATEDIFF(actual_delivery_date, expected_delivery_date)) as avg_delivery_days
        FROM purchase_orders 
        WHERE vendor_id = ? AND delivery_status = 'delivered' AND actual_delivery_date IS NOT NULL
    ");
    $delivery_time_stmt->bind_param("i", $vendor_id);
    $delivery_time_stmt->execute();
    $delivery_time_result = $delivery_time_stmt->get_result();
    $delivery_time_data = $delivery_time_result->fetch_assoc();
    $delivery_time_stmt->close();

    $avg_delivery_time = $delivery_time_data['avg_delivery_days'] ?? 0;

    // Calculate quality acceptance rate and defect rate
    $quality_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_checks,
            SUM(CASE WHEN quality_status = 'passed' THEN 1 ELSE 0 END) as passed_checks,
            AVG(defect_rate) as avg_defect_rate
        FROM purchase_orders 
        WHERE vendor_id = ? AND quality_status != 'pending'
    ");
    $quality_stmt->bind_param("i", $vendor_id);
    $quality_stmt->execute();
    $quality_result = $quality_stmt->get_result();
    $quality_data = $quality_result->fetch_assoc();
    $quality_stmt->close();

    $quality_rate = 0;
    $defect_rate = 0;
    if ($quality_data['total_checks'] > 0) {
        $quality_rate = ($quality_data['passed_checks'] / $quality_data['total_checks']) * 100;
        $defect_rate = $quality_data['avg_defect_rate'] ?? 0;
    }

    // Calculate overall performance score (weighted average)
    $performance_score = (
        $on_time_rate * 0.35 + 
        $quality_rate * 0.35 + 
        (100 - $defect_rate) * 0.20 +
        (max(0, 100 - ($avg_delivery_time * 10)) * 0.10) // Penalize late deliveries
    );

    // Ensure score is between 0-100
    $performance_score = max(0, min(100, $performance_score));

    // Update vendor record
    $update_stmt = $conn->prepare("
        UPDATE vendors SET 
            performance_score = ?,
            on_time_delivery_rate = ?,
            avg_delivery_time = ?,
            quality_acceptance_rate = ?,
            avg_defect_rate = ?,
            total_orders_completed = ?,
            last_performance_update = NOW()
        WHERE vendor_id = ?
    ");
    
    $total_completed = $delivery_data['delivered_orders'] ?? 0;
    $update_stmt->bind_param(
        "ddddddi", 
        $performance_score, 
        $on_time_rate, 
        $avg_delivery_time, 
        $quality_rate, 
        $defect_rate, 
        $total_completed, 
        $vendor_id
    );
    
    $update_stmt->execute();
    $update_stmt->close();

    return [
        'performance_score' => round($performance_score, 1),
        'on_time_delivery_rate' => round($on_time_rate, 1),
        'avg_delivery_time' => round($avg_delivery_time, 1),
        'quality_acceptance_rate' => round($quality_rate, 1),
        'avg_defect_rate' => round($defect_rate, 1),
        'total_orders_completed' => $total_completed
    ];
}

// Function to update delivery status when goods are received
function updateDeliveryStatus($po_id, $actual_delivery_date, $quality_status, $defect_count, $total_items, $conn) {
    // Get PO details
    $po_stmt = $conn->prepare("SELECT vendor_id, expected_delivery_date FROM purchase_orders WHERE po_id = ?");
    $po_stmt->bind_param("i", $po_id);
    $po_stmt->execute();
    $po_result = $po_stmt->get_result();
    $po_data = $po_result->fetch_assoc();
    $po_stmt->close();

    if (!$po_data) return false;

    $vendor_id = $po_data['vendor_id'];
    $expected_date = $po_data['expected_delivery_date'];
    
    // Calculate defect rate
    $defect_rate = 0;
    if ($total_items > 0) {
        $defect_rate = ($defect_count / $total_items) * 100;
    }

    // Determine delivery status
    $delivery_status = 'delivered';
    if (strtotime($actual_delivery_date) > strtotime($expected_date)) {
        $delivery_status = 'late';
    }

    // Update PO with delivery information
    $update_po_stmt = $conn->prepare("
        UPDATE purchase_orders SET 
            actual_delivery_date = ?,
            delivery_status = ?,
            quality_status = ?,
            defect_rate = ?
        WHERE po_id = ?
    ");
    $update_po_stmt->bind_param("sssdi", $actual_delivery_date, $delivery_status, $quality_status, $defect_rate, $po_id);
    $update_po_stmt->execute();
    $update_po_stmt->close();

    // Recalculate vendor performance
    calculateVendorPerformance($vendor_id, $conn);

    return true;
}

// Function to get vendor performance summary
function getVendorPerformanceSummary($vendor_id, $conn) {
    $stmt = $conn->prepare("
        SELECT 
            performance_score,
            on_time_delivery_rate,
            avg_delivery_time,
            quality_acceptance_rate,
            avg_defect_rate,
            total_orders_completed,
            last_performance_update
        FROM vendors 
        WHERE vendor_id = ?
    ");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $performance_data = $result->fetch_assoc();
    $stmt->close();

    return $performance_data;
}

// Function to update all vendors' performance scores
function updateAllVendorPerformances($conn) {
    $vendors_stmt = $conn->prepare("SELECT vendor_id FROM vendors WHERE vendor_status = 'approved'");
    $vendors_stmt->execute();
    $vendors_result = $vendors_stmt->get_result();
    
    $updated_count = 0;
    while ($vendor = $vendors_result->fetch_assoc()) {
        calculateVendorPerformance($vendor['vendor_id'], $conn);
        $updated_count++;
    }
    
    $vendors_stmt->close();
    return $updated_count;
}
?>