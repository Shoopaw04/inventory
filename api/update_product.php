<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your database connection
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/Auth.php';
try {
    // Ensure user is authenticated (so audit logs can attribute)
    $actor = requireAuth();

    // Get database connection using your Singleton pattern
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    try {
        // Get JSON input
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!$data) {
            throw new Exception('Invalid JSON data');
        }

        // Validate required fields
        if (!isset($data['product_id']) || empty($data['product_id'])) {
            throw new Exception('Product ID is required');
        }

        $product_id = intval($data['product_id']);
        $updates = [];
        $params = [];
        $types = '';

        // Check if product exists and is not deleted - STORE the result
        $check_sql = "SELECT Product_ID, Name, Retail_Price, Unit_measure, Reorder_Level FROM product WHERE Product_ID = ? AND Is_discontinued != 1";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$product_id]);
        $existing_product = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing_product) {
            throw new Exception('Product not found or is inactive');
        }

        // Build dynamic update query based on provided fields
        $beforeReorder = null;
        if (isset($data['name']) && !empty(trim($data['name']))) {
            $name = trim($data['name']);

            // Validate name
            if (strlen($name) < 2) {
                throw new Exception('Product name must be at least 2 characters long');
            }
            if (strlen($name) > 100) {
                throw new Exception('Product name cannot exceed 100 characters');
            }

            // Check for duplicates only if the name is actually different
            if (strcasecmp($name, $existing_product['Name']) !== 0) {
                $duplicate_sql = "SELECT Product_ID FROM product WHERE Name = ? AND Product_ID != ? AND Is_discontinued != 1";
                $duplicate_stmt = $pdo->prepare($duplicate_sql);
                $duplicate_stmt->execute([$name, $product_id]);

                if ($duplicate_stmt->fetch()) {
                    throw new Exception('A product with this name already exists');
                }
            }

            $updates[] = "Name = ?";
            $params[] = $name;
            $types .= 's';
        }

        if (isset($data['price']) || isset($data['Retail_Price'])) {
            $price = isset($data['price']) ? floatval($data['price']) : floatval($data['Retail_Price']);

            // Validate price
            if ($price < 0) {
                throw new Exception('Price cannot be negative');
            }
            if ($price > 999999.99) {
                throw new Exception('Price cannot exceed ₱999,999.99');
            }

            $updates[] = "Retail_Price = ?";
            $params[] = $price;
            $types .= 'd';
        }

        if (isset($data['unit_measure']) || isset($data['Unit_measure'])) {
            $unit = isset($data['unit_measure']) ? trim($data['unit_measure']) : trim($data['Unit_measure']);
            $updates[] = "Unit_measure = ?";
            $params[] = $unit;
            $types .= 's';
        }

        if (isset($data['reorder_level']) || isset($data['Reorder_Level'])) {
            $reorder_level = isset($data['reorder_level']) ? intval($data['reorder_level']) : intval($data['Reorder_Level']);

            // Validate reorder level
            if ($reorder_level < 0) {
                throw new Exception('Reorder level cannot be negative');
            }
            if ($reorder_level > 99999) {
                throw new Exception('Reorder level cannot exceed 99,999');
            }

            // Load current level for audit comparison
            $curStmt = $pdo->prepare("SELECT Reorder_Level FROM product WHERE Product_ID = ?");
            $curStmt->execute([$product_id]);
            $beforeReorder = (int)($curStmt->fetchColumn());

            $updates[] = "Reorder_Level = ?";
            $params[] = $reorder_level;
            $types .= 'i';
        }

        // Check if there are any updates to make
        if (empty($updates)) {
            throw new Exception('No valid fields to update');
        }

        // Add last_update timestamp
       

        // Add product_id for WHERE clause
        $params[] = $product_id;
        $types .= 'i';

        // Build and execute update query
        $sql = "UPDATE product SET " . implode(', ', $updates) . " WHERE Product_ID = ?";
        $stmt = $pdo->prepare($sql);

        // Execute with parameters
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            // Price change audit
            if (isset($price)) {
                $oldPrice = isset($existing_product['Retail_Price']) ? (float)$existing_product['Retail_Price'] : null;
                if ($oldPrice !== null && $oldPrice !== (float)$price) {
                    logAudit(
                        'Product',
                        (string)$product_id,
                        'PRICE_UPDATE',
                        ['Retail_Price' => $oldPrice],
                        ['Retail_Price' => (float)$price],
                        'Updated via update_product'
                    );
                }
            }
            // Audit when reorder level changed via this endpoint
            if ($beforeReorder !== null) {
                $newLevel = isset($data['reorder_level']) ? (int)$data['reorder_level'] : (isset($data['Reorder_Level']) ? (int)$data['Reorder_Level'] : $beforeReorder);
                if ($beforeReorder !== $newLevel) {
                    logAudit(
                        'Product',
                        (string)$product_id,
                        'REORDER_LEVEL_UPDATE',
                        ['Reorder_Level' => (int)$beforeReorder],
                        ['Reorder_Level' => (int)$newLevel],
                        'Updated via update_product'
                    );
                }
            }
            echo json_encode([
                'success' => true,
                'message' => 'Product updated successfully',
                'product_id' => $product_id
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'No changes were made or product not found'
            ]);
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Connection error: ' . $e->getMessage()
    ]);
}
?>