<?php
// api/low_stock_alert.php - Get low stock products
header('Content-Type: application/json');
require_once __DIR__ . '/Database.php';

try {
    $db = Database::getInstance()->getConnection();

    $sql = "SELECT 
        p.Product_ID AS product_id,
        p.Name AS name,
        c.Category_Name AS category_name,
        COALESCE(i.Quantity, 0) AS warehouse_qty,
        COALESCE(p.Display_stocks, 0) AS display_qty,
        (COALESCE(i.Quantity, 0) + COALESCE(p.Display_stocks, 0)) AS total_stock,
        COALESCE(p.Reorder_Level, 5) AS reorder_level,
        p.Retail_Price AS price,
        s.Name AS supplier_name
    FROM Product p
    LEFT JOIN Inventory i ON i.Product_ID = p.Product_ID
    LEFT JOIN Category c ON c.Category_ID = p.Category_ID
    LEFT JOIN Supplier s ON s.Supplier_ID = p.Supplier_ID
    WHERE p.Is_discontinued = 0 
    AND (COALESCE(i.Quantity, 0) + COALESCE(p.Display_stocks, 0)) <= COALESCE(p.Reorder_Level, 5)
    ORDER BY 
        CASE 
            WHEN (COALESCE(i.Quantity, 0) + COALESCE(p.Display_stocks, 0)) = 0 THEN 1
            ELSE 2
        END,
        (COALESCE(i.Quantity, 0) + COALESCE(p.Display_stocks, 0)) ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'products' => $products,
        'count' => count($products)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>