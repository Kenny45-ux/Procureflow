<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

function getKpiMetrics($pdo) {
    $metrics = [];
    
    // Procurement Cycle Time
    $stmt = $pdo->query("
        SELECT AVG(DATEDIFF(completion_date, creation_date)) as avg_cycle_time 
        FROM purchase_orders 
        WHERE completion_date IS NOT NULL 
        AND creation_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    ");
    $metrics['procurementCycle'] = $stmt->fetch(PDO::FETCH_ASSOC)['avg_cycle_time'] ?? 45;
    
    // Maverick Spending
    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN v.approved = 1 THEN po.total_amount ELSE 0 END) as contract_spend,
            SUM(po.total_amount) as total_spend
        FROM purchase_orders po
        LEFT JOIN vendors v ON po.vendor_id = v.id
        WHERE po.issue_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    ");
    $spending = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalSpend = $spending['total_spend'] ?? 1;
    $contractSpend = $spending['contract_spend'] ?? 0;
    $metrics['maverickSpending'] = (($totalSpend - $contractSpend) / $totalSpend) * 100;
    
    // Cost Savings
    $stmt = $pdo->query("
        SELECT SUM(cost_savings) as total_savings 
        FROM cost_savings 
        WHERE savings_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
    ");
    $metrics['costSavings'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_savings'] ?? 0;
    
    // Efficiency Gain (simplified calculation)
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_processes,
            SUM(CASE WHEN automated = 1 THEN 1 ELSE 0 END) as automated_processes
        FROM business_processes
    ");
    $efficiency = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalProcesses = $efficiency['total_processes'] ?? 1;
    $automatedProcesses = $efficiency['automated_processes'] ?? 0;
    $metrics['efficiencyGain'] = ($automatedProcesses / $totalProcesses) * 100;
    
    return $metrics;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $metrics = getKpiMetrics($pdo);
    
    echo json_encode([
        'success' => true,
        'metrics' => $metrics,
        'timestamp' => time()
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>