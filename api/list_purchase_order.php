<?php
require_once __DIR__ . '/Database.php';

try {
   
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $stmt = $pdo->query("\n        SELECT \n            po.PO_ID,\n            po.Supplier_ID,\n            s.Name AS supplier_name,\n            po.Order_date,\n            po.Expected_date,\n            po.Priority,\n            po.Notes,\n            po.Status,\n            po.Total_amount,\n            IFNULL((SELECT SUM(Stock_in_Quantity) FROM Stockin_Inventory si WHERE si.PO_ID = po.PO_ID), 0) AS received,\n            GROUP_CONCAT(DISTINCT p.Name ORDER BY p.Name SEPARATOR ', ') AS products_involved,\n            GROUP_CONCAT(DISTINCT sup.Name ORDER BY sup.Name SEPARATOR ', ') AS suppliers_involved\n        FROM Purchase_Order po\n        LEFT JOIN Supplier s ON po.Supplier_ID = s.Supplier_ID\n        LEFT JOIN Purchase_Order_Item poi ON poi.PO_ID = po.PO_ID\n        LEFT JOIN Product p ON p.Product_ID = poi.Product_ID\n        LEFT JOIN Supplier sup ON sup.Supplier_ID = p.Supplier_ID\n        GROUP BY po.PO_ID\n        ORDER BY po.PO_ID DESC\n    ");
    $pos = $stmt->fetchAll();
    echo json_encode(['success' => true, 'pos' => $pos]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>