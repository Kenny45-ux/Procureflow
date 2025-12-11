<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    
    // Calculate maverick spending (off-contract purchases)
    $stmt = $pdo->prepare("
        SELECT 
            SUM(po.total_amount) as total_spend,
            SUM(CASE WHEN v.approved = 1 AND v.contract_end >= CURDATE() 
                THEN po.total_amount ELSE 0 END) as contract_spend,
            SUM(CASE WHEN v.approved = 0 OR v.contract_end < CURDATE() 
                THEN po.total_amount ELSE 0 END) as maverick_spend
        FROM purchase_orders po
        LEFT JOIN vendors v ON po.vendor_id = v.id
        WHERE po.issue_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        AND po.status NOT IN ('Cancelled', 'Draft')
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $totalSpend = $result['total_spend'] ?? 1;
    $contractSpend = $result['contract_spend'] ?? 0;
    $maverickSpend = $result['maverick_spend'] ?? 0;
    
    $maverickPercentage = $totalSpend > 0 ? ($maverickSpend / $totalSpend) * 100 : 0;
    $contractPercentage = $totalSpend > 0 ? ($contractSpend / $totalSpend) * 100 : 0;
    
    echo json_encode([
        'success' => true,
        'maverickPercentage' => round($maverickPercentage, 1),
        'contractPercentage' => round($contractPercentage, 1),
        'totalSpend' => $totalSpend,
        'maverickSpend' => $maverickSpend,
        'baseline' => 25 // 25% baseline
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>