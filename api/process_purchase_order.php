<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    exit(0);
}

// Adjust these paths to match your project structure
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/inventory.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests allowed');
    }

    // Get database connection
    $database = Database::getInstance();
    $pdo = $database->getConnection();

    // Initialize Inventory class
    $inventory = new Inventory($pdo);

    // FIXED: Handle both JSON and form data requests
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input && isset($input['action'])) {
        // JSON request (from goods receiving page)
        $action = $input['action'];
        $purchase_order_id = (int) ($input['purchase_order_id'] ?? 0);
    } else {
        // Form data request (from other parts of system)
        $action = $_POST['action'] ?? '';
        $purchase_order_id = (int) ($_POST['purchase_order_id'] ?? 0);
    }

    if (!$purchase_order_id) {
        throw new Exception('Purchase Order ID is required');
    }

    // Get current user ID (adjust based on your session structure)
    $user_id = $_SESSION['user_id'] ?? 1;

    switch ($action) {
        case 'complete':
            $pdo->beginTransaction();

            try {
                // Check if PO exists and can be completed
                $check_stmt = $pdo->prepare("
                    SELECT status, PO_ID 
                    FROM purchase_order 
                    WHERE PO_ID = ? AND status = 'Pending'
                ");
                $check_stmt->execute([$purchase_order_id]);
                $po = $check_stmt->fetch();

                if (!$po) {
                    throw new Exception('Purchase order not found or already processed');
                }

                // Get all items with supplier info
                $items_stmt = $pdo->prepare("
                    SELECT 
                        poi.product_id,
                        poi.quantity,
                        poi.purchase_price,
                        po.supplier_id,
                        p.name AS product_name
                    FROM purchase_order_item poi
                    JOIN purchase_order po ON poi.PO_ID = po.PO_ID
                    JOIN product p ON poi.product_id = p.product_id
                    WHERE poi.PO_ID = ?
                ");
                $items_stmt->execute([$purchase_order_id]);
                $po_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($po_items)) {
                    throw new Exception('No items found in this purchase order');
                }

                // FIXED: Create stockin records first, then process them
                foreach ($po_items as $item) {
                    // Step 1: Create stockin_inventory record
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO stockin_inventory (
                            Product_ID, 
                            Supplier_ID, 
                            Stock_in_Quantity,
                            Quantity_Ordered, 
                            Unit_Cost, 
                            Total_Cost,
                            Batch_Number,
                            Created_Date,
                            Status,
                            PO_ID
                        ) VALUES (?, ?, 0, ?, ?, 0, '', NOW(), 'Pending', ?)
                    ");

                    $insert_stmt->execute([
                        (int) $item['product_id'],
                        (int) $item['supplier_id'],
                        (int) $item['quantity'],
                        (float) $item['purchase_price'],
                        $purchase_order_id
                    ]);

                    // Get the auto-generated Stockin_ID
                    $stockin_id = (int) $pdo->lastInsertId();

                    // Step 2: Process the stock-in (this will update inventory)
                    $inventory->processStockIn(
                        $stockin_id,
                        (int) $item['quantity'],
                        $user_id,
                        "Stock-in from Purchase Order #" . $purchase_order_id,
                        'PO-' . $purchase_order_id . '-' . $item['product_id'], // Generate batch number
                        null
                    );
                }

                // Update purchase order status
                $update_stmt = $pdo->prepare("
                    UPDATE purchase_order 
                    SET status = 'Completed'
                    WHERE PO_ID = ?
                ");
                $update_stmt->execute([$purchase_order_id]);

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => '✅ Purchase order completed successfully! Inventory has been updated with ' . count($po_items) . ' items.'
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'complete_with_details':
            // New action for handling detailed stock-in information
            $pdo->beginTransaction();

            try {
                // Check if PO exists and can be completed
                $check_stmt = $pdo->prepare("
                    SELECT status, PO_ID 
                    FROM purchase_order 
                    WHERE PO_ID = ? AND status = 'Pending'
                ");
                $check_stmt->execute([$purchase_order_id]);
                $po = $check_stmt->fetch();

                if (!$po) {
                    throw new Exception('Purchase order not found or already processed');
                }

                if (!$input || !isset($input['items'])) {
                    throw new Exception('Items data is required for detailed completion');
                }

                $processed_count = 0;

                // FIXED: Create stockin records first if they don't exist
                foreach ($input['items'] as $item) {
                    if (!isset($item['product_id']) || !isset($item['quantity_received'])) {
                        throw new Exception('Missing required fields: product_id, quantity_received');
                    }

                    $stockin_id = null;

                    // Check if stockin record already exists for this product and PO
                    $check_stockin = $pdo->prepare("
                        SELECT Stockin_ID FROM stockin_inventory 
                        WHERE Product_ID = ? AND PO_ID = ? AND Status = 'Pending'
                    ");
                    $check_stockin->execute([$item['product_id'], $purchase_order_id]);
                    $existing_stockin = $check_stockin->fetch();

                    if ($existing_stockin) {
                        $stockin_id = (int) $existing_stockin['Stockin_ID'];
                    } else {
                        // Create new stockin record
                        $get_po_info = $pdo->prepare("
                            SELECT poi.purchase_price, po.supplier_id
                            FROM purchase_order_item poi
                            JOIN purchase_order po ON poi.PO_ID = po.PO_ID
                            WHERE poi.PO_ID = ? AND poi.product_id = ?
                        ");
                        $get_po_info->execute([$purchase_order_id, $item['product_id']]);
                        $po_info = $get_po_info->fetch();

                        if (!$po_info) {
                            throw new Exception('Product not found in purchase order');
                        }

                        $insert_stmt = $pdo->prepare("
                            INSERT INTO stockin_inventory (
                                Product_ID, 
                                Supplier_ID, 
                                Stock_in_Quantity,
                                Quantity_Ordered, 
                                Unit_Cost, 
                                Total_Cost,
                                Batch_Number,
                                Created_Date,
                                Status,
                                PO_ID
                            ) VALUES (?, ?, 0, ?, ?, 0, '', NOW(), 'Pending', ?)
                        ");

                        $insert_stmt->execute([
                            (int) $item['product_id'],
                            (int) $po_info['supplier_id'],
                            (int) $item['quantity_received'],
                            (float) $po_info['purchase_price'],
                            $purchase_order_id
                        ]);

                        $stockin_id = (int) $pdo->lastInsertId();
                    }

                    // Process the stock-in
                    $inventory->processStockIn(
                        $stockin_id,
                        (int) $item['quantity_received'],
                        $user_id,
                        $item['remarks'] ?? "Stock-in from Purchase Order #" . $purchase_order_id,
                        $item['batch_number'] ?? '',
                        $item['expiry_date'] ?? null
                    );

                    $processed_count++;
                }

                // Update purchase order status
                $update_stmt = $pdo->prepare("
                    UPDATE purchase_order 
                    SET status = 'Completed'
                    WHERE PO_ID = ?
                ");
                $update_stmt->execute([$purchase_order_id]);

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => "✅ Purchase order completed successfully! Processed $processed_count items into inventory."
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'cancel':
            $cancel_stmt = $pdo->prepare("
                UPDATE purchase_order 
                SET status = 'Cancelled'
                WHERE PO_ID = ? AND status = 'Pending'
            ");
            $cancel_stmt->execute([$purchase_order_id]);

            if ($cancel_stmt->rowCount() === 0) {
                throw new Exception('Purchase order not found or cannot be cancelled');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Purchase order cancelled successfully'
            ]);
            break;

        case 'reopen':
            $reopen_stmt = $pdo->prepare("
                UPDATE purchase_order 
                SET status = 'Pending'
                WHERE PO_ID = ? AND status IN ('Completed', 'Cancelled')
            ");
            $reopen_stmt->execute([$purchase_order_id]);

            if ($reopen_stmt->rowCount() === 0) {
                throw new Exception('Purchase order not found or cannot be reopened');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Purchase order reopened successfully'
            ]);
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }

} catch (Exception $e) {
    // Make sure to rollback if we're in a transaction
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Log the error for debugging
    error_log("Purchase Order Error: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}