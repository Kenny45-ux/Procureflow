<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    
    // Calculate average procurement cycle time
    $stmt = $pdo->prepare("
        SELECT 
            AVG(TIMESTAMPDIFF(DAY, po.creation_date, 
                COALESCE(po.completion_date, NOW()))) as avg_cycle_time,
            COUNT(*) as total_orders,
            SUM(CASE WHEN po.status = 'Completed' THEN 1 ELSE 0 END) as completed_orders
        FROM purchase_orders po
        WHERE po.creation_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $avgCycleTime = round($result['avg_cycle_time'] ?? 45, 1);
    $completionRate = $result['total_orders'] > 0 ? 
        ($result['completed_orders'] / $result['total_orders']) * 100 : 0;
    
    echo json_encode([
        'success' => true,
        'avgCycleTime' => $avgCycleTime,
        'completionRate' => round($completionRate, 1),
        'totalOrders' => $result['total_orders'],
        'baseline' => 45 // 45 days baseline
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>