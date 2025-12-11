<?php
// Inventory management functions

/**
 * Update inventory levels and track movements
 */
function updateInventory($conn, $product_id, $adjustment_type, $quantity, $notes = '', $user_id = 0) {
    try {
        // Get current stock
        $stmt = $conn->prepare("SELECT current_stock FROM products WHERE product_id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        $stmt->close();
        
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found'];
        }
        
        $current_stock = $product['current_stock'];
        $previous_stock = $current_stock;
        
        // Calculate new stock based on adjustment type
        switch ($adjustment_type) {
            case 'in':
                $new_stock = $current_stock + $quantity;
                break;
            case 'out':
                $new_stock = $current_stock - $quantity;
                if ($new_stock < 0) $new_stock = 0;
                break;
            case 'adjustment':
                $new_stock = $quantity;
                break;
            default:
                return ['success' => false, 'error' => 'Invalid adjustment type'];
        }
        
        // Update product stock
        $stmt = $conn->prepare("UPDATE products SET current_stock = ?, updated_at = NOW() WHERE product_id = ?");
        $stmt->bind_param("ii", $new_stock, $product_id);
        
        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Failed to update product stock'];
        }
        $stmt->close();
        
        // Record inventory movement
        $stmt = $conn->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, previous_stock, new_stock, reference_type, notes, created_by) VALUES (?, ?, ?, ?, ?, 'adjustment', ?, ?)");
        $reference_type = 'adjustment';
        $stmt->bind_param("isiiisi", $product_id, $adjustment_type, $quantity, $previous_stock, $new_stock, $notes, $user_id);
        
        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Failed to record inventory movement'];
        }
        $stmt->close();
        
        // Check for low stock alert
        checkLowStockAlert($conn, $product_id);
        
        return [
            'success' => true,
            'previous_stock' => $previous_stock,
            'new_stock' => $new_stock,
            'movement_recorded' => true
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get low stock alerts
 */
function getLowStockAlerts($conn) {
    $alerts = [];
    
    $sql = "SELECT p.*, v.vendor_name 
            FROM products p 
            LEFT JOIN vendors v ON p.preferred_vendor_id = v.vendor_id 
            WHERE p.current_stock <= p.reorder_level 
            AND p.is_active = TRUE
            ORDER BY p.current_stock ASC";
    
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $alerts[] = $row;
        }
    }
    
    return $alerts;
}

/**
 * Get dead stock alerts (items with no movement in 90+ days)
 */
function getDeadStockAlerts($conn, $days_threshold = 90) {
    $alerts = [];
    
    $sql = "SELECT p.product_id, p.product_name, p.current_stock, p.unit_cost,
                   (p.current_stock * p.unit_cost) as stock_value,
                   DATEDIFF(NOW(), COALESCE(MAX(im.created_at), p.created_at)) as days_since_movement,
                   da.alert_id, da.is_resolved
            FROM products p
            LEFT JOIN inventory_movements im ON p.product_id = im.product_id
            LEFT JOIN dead_stock_alerts da ON p.product_id = da.product_id AND da.is_resolved = FALSE
            WHERE p.current_stock > 0
            AND p.is_active = TRUE
            GROUP BY p.product_id, p.product_name, p.current_stock, p.unit_cost, da.alert_id, da.is_resolved
            HAVING days_since_movement >= ? 
            AND (da.alert_id IS NULL OR da.is_resolved = FALSE)
            ORDER BY days_since_movement DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $days_threshold);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Create alert if it doesn't exist
            if (!$row['alert_id']) {
                createDeadStockAlert($conn, $row['product_id'], $row['days_since_movement'], $row['current_stock'], $row['stock_value']);
            }
            $alerts[] = $row;
        }
    }
    $stmt->close();
    
    return $alerts;
}

/**
 * Create dead stock alert
 */
function createDeadStockAlert($conn, $product_id, $days_since_movement, $current_stock, $stock_value) {
    $alert_type = $days_since_movement >= 180 ? 'dead_stock' : 'slow_moving';
    
    $stmt = $conn->prepare("INSERT INTO dead_stock_alerts (product_id, days_since_movement, current_stock, stock_value, alert_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiids", $product_id, $days_since_movement, $current_stock, $stock_value, $alert_type);
    $stmt->execute();
    $stmt->close();
    
    return true;
}

/**
 * Check and create low stock alert
 */
function checkLowStockAlert($conn, $product_id) {
    $sql = "SELECT p.* FROM products p WHERE p.product_id = ? AND p.current_stock <= p.reorder_level AND p.is_active = TRUE";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $is_low_stock = $result->num_rows > 0;
    $stmt->close();
    
    return $is_low_stock;
}

/**
 * Process delivery and update inventory
 */
function processDelivery($conn, $po_id, $product_name, $quantity, $quality_status, $defect_count) {
    try {
        // Find product by name (in a real system, you'd have product IDs in PO items)
        $stmt = $conn->prepare("SELECT product_id FROM products WHERE product_name = ?");
        $stmt->bind_param("s", $product_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        $stmt->close();
        
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found for delivery processing'];
        }
        
        $product_id = $product['product_id'];
        $good_quantity = $quantity - $defect_count;
        
        // Update inventory with good items
        if ($good_quantity > 0) {
            $result = updateInventory($conn, $product_id, 'in', $good_quantity, "Delivery from PO: $po_id - Quality: $quality_status");
            
            if (!$result['success']) {
                return $result;
            }
        }
        
        return [
            'success' => true,
            'product_id' => $product_id,
            'good_quantity' => $good_quantity,
            'defect_count' => $defect_count
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>