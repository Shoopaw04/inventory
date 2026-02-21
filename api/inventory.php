<?php
// classes/Inventory.php
require_once __DIR__ . '/Database.php';

class Inventory
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

   
    public function adjustStock(
        int $product_id,
        int $delta,
        int $reference_id = null,
        string $movement_type = 'ADJUST',
        int $performed_by = null,
        string $source_table = null,
        int $terminal_id = null
    ): int {
        // Debug logging
        error_log("[INVENTORY] adjustStock called - Product: $product_id, Delta: $delta, Movement Type: '$movement_type', Source: '$source_table'");
        
        $ownTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownTransaction = true;
        }

        try {
            // 
            $stmt = $this->pdo->prepare("
            SELECT i.Inventory_ID, i.Quantity AS warehouse_qty, p.Display_stocks, p.Name
            FROM inventory i
            RIGHT JOIN product p ON p.Product_ID = i.Product_ID
            WHERE p.Product_ID = :pid FOR UPDATE
        ");
            $stmt->execute([':pid' => $product_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception("Product with ID {$product_id} not found");
            }

            // Create inventory row if missing
            if (!$row['Inventory_ID']) {
                $this->pdo->prepare("
                INSERT INTO inventory (Product_ID, Last_update, Quantity, Reorder_level) 
                VALUES (:pid, NOW(), 0, 0)
            ")->execute([':pid' => $product_id]);

                $row['warehouse_qty'] = 0;
            }

            $warehouseQty = (int) $row['warehouse_qty'];
            $displayQty = (int) $row['Display_stocks'];
            $productName = $row['Name'];

            // Handle different movement types properly
            if ($movement_type === 'SALE') {
                // For sales, deduct from display stock first (FIFO)
                $needed = abs($delta);
                if ($displayQty >= $needed) {
                    $displayQty -= $needed;
                } else {
                    $neededFromWarehouse = $needed - $displayQty;
                    if ($warehouseQty < $neededFromWarehouse) {
                        throw new Exception("Insufficient stock for {$productName}. Display: {$displayQty}, Warehouse: {$warehouseQty}, Requested: {$needed}");
                    }
                    $displayQty = 0;
                    $warehouseQty -= $neededFromWarehouse;
                }
            } elseif ($movement_type === 'REPLENISH_DISPLAY') {
                // For display replenishment, move from warehouse to display
                $replenishQty = abs($delta);
                if ($warehouseQty < $replenishQty) {
                    throw new Exception("Insufficient warehouse stock for replenishment of {$productName}. Available: {$warehouseQty}, Requested: {$replenishQty}");
                }
                $warehouseQty -= $replenishQty;
                // Note: Display stock update happens in calling method
            } elseif ($movement_type === 'TRANSFER_OUT') {
                // For transfer out, move from warehouse to display
                $transferQty = abs($delta);
                if ($warehouseQty < $transferQty) {
                    throw new Exception("Insufficient warehouse stock for transfer of {$productName}. Available: {$warehouseQty}, Requested: {$transferQty}");
                }
                $warehouseQty -= $transferQty;
                // Note: Display stock update happens in calling method
            } elseif ($delta < 0) {
                // Other negative movements (adjustments, damage, etc.)
                $needed = abs($delta);
                if ($displayQty >= $needed) {
                    $displayQty -= $needed;
                } else {
                    $neededFromWarehouse = $needed - $displayQty;
                    if ($warehouseQty < $neededFromWarehouse) {
                        throw new Exception("Insufficient stock for {$productName}. Display: {$displayQty}, Warehouse: {$warehouseQty}, Requested: {$needed}");
                    }
                    $displayQty = 0;
                    $warehouseQty -= $neededFromWarehouse;
                }
            } else {
                // Positive movements (stock in, returns, etc.) go to warehouse
                $warehouseQty += $delta;
            }

            // Update warehouse inventory
            $this->pdo->prepare("
            UPDATE inventory SET Quantity = :qty, Last_update = NOW() 
            WHERE Product_ID = :pid
        ")->execute([':qty' => $warehouseQty, ':pid' => $product_id]);

            // Update display stock (except for replenishment which is handled separately)
            if ($movement_type !== 'REPLENISH_DISPLAY' && $movement_type !== 'TRANSFER_OUT') {
                $this->pdo->prepare("
                UPDATE product SET Display_stocks = :ds 
                WHERE Product_ID = :pid
            ")->execute([':ds' => $displayQty, ':pid' => $product_id]);
            }

            // FIXED: Log to stock_movements with proper Terminal_ID handling
            $logQuantity = abs($delta);
            
            // Debug logging
            error_log("[INVENTORY] Logging movement - Type: '$movement_type', Quantity: $logQuantity, Product: $product_id");
            
            // Ensure movement type is not empty
            if (empty($movement_type)) {
                error_log("[INVENTORY] WARNING: Movement type is empty, using default 'ADJUSTMENT_IN'");
                $movement_type = 'ADJUSTMENT_IN';
            }

            // Prepare the INSERT statement with conditional Terminal_ID
            if ($terminal_id !== null && $terminal_id > 0) {
                // Include Terminal_ID if it's provided and valid
                $stmt = $this->pdo->prepare("
                INSERT INTO stock_movements 
                (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Updated_at, Source_Table, Terminal_ID, Timestamp)
                VALUES (:pid, :movetype, :qty, :ref, :performed_by, NOW(), :source, :terminal, NOW())
            ");
                $params = [
                    ':pid' => $product_id,
                    ':movetype' => $movement_type,
                    ':qty' => $logQuantity,
                    ':ref' => $reference_id,
                    ':performed_by' => $performed_by,
                    ':source' => $source_table,
                    ':terminal' => $terminal_id,
                ];
            } else {
                // Exclude Terminal_ID if it's null or 0 (assuming Terminal_ID column allows NULL)
                $stmt = $this->pdo->prepare("
                INSERT INTO stock_movements 
                (Product_ID, Movement_type, Quantity, Reference_ID, Performed_by, Updated_at, Source_Table, Timestamp)
                VALUES (:pid, :movetype, :qty, :ref, :performed_by, NOW(), :source, NOW())
            ");
                $params = [
                    ':pid' => $product_id,
                    ':movetype' => $movement_type,
                    ':qty' => $logQuantity,
                    ':ref' => $reference_id,
                    ':performed_by' => $performed_by,
                    ':source' => $source_table,
                ];
            }

            $stmt->execute($params);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $warehouseQty + $displayQty;

        } catch (Exception $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get current stock level for a product (warehouse only)
     */
    public function getStock(int $product_id): int
    {
        $stmt = $this->pdo->prepare("SELECT Quantity FROM inventory WHERE Product_ID = :pid");
        $stmt->execute([':pid' => $product_id]);
        $row = $stmt->fetch();

        return $row ? (int) $row['Quantity'] : 0;
    }

    /**
     * Get total stock level for a product (warehouse + display)
     */
    public function getTotalStock(int $product_id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COALESCE(i.Quantity, 0) as warehouse_qty,
                p.Display_stocks,
                p.Name
            FROM product p
            LEFT JOIN inventory i ON p.Product_ID = i.Product_ID
            WHERE p.Product_ID = :pid
        ");
        $stmt->execute([':pid' => $product_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("Product not found");
        }

        return [
            'warehouse_qty' => (int) $row['warehouse_qty'],
            'display_qty' => (int) $row['Display_stocks'],
            'total_qty' => (int) $row['warehouse_qty'] + (int) $row['Display_stocks'],
            'product_name' => $row['Name']
        ];
    }

    /**
     * Check if product has sufficient stock (warehouse only)
     */
    public function hasStock(int $product_id, int $required_quantity): bool
    {
        return $this->getStock($product_id) >= $required_quantity;
    }

    /**
     * Check if product has sufficient total stock (warehouse + display)
     */
    public function hasTotalStock(int $product_id, int $required_quantity): bool
    {
        $stock = $this->getTotalStock($product_id);
        return $stock['total_qty'] >= $required_quantity;
    }

    /**
     * Get products that are below reorder level
     */
    public function getLowStockProducts(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                i.*,
                p.Name as product_name, 
                p.Reorder_Level,
                p.Display_stocks,
                (COALESCE(i.Quantity, 0) + p.Display_stocks) as total_stock
            FROM inventory i
            RIGHT JOIN product p ON i.Product_ID = p.Product_ID
            WHERE (COALESCE(i.Quantity, 0) + p.Display_stocks) <= p.Reorder_Level 
            AND p.Is_discontinued = 0
            ORDER BY p.Name
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Create stock-in records from purchase order
     */
    public function createStockInFromPO(int $po_id, int $user_id): void
    {
        $stmt = $this->pdo->prepare("
            SELECT poi.Product_ID, poi.Quantity, poi.Purchase_price, po.Supplier_ID
            FROM purchase_order_item poi
            JOIN purchase_order po ON poi.PO_ID = po.PO_ID
            WHERE poi.PO_ID = ?
        ");
        $stmt->execute([$po_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            throw new Exception("No items found for Purchase Order ID: {$po_id}");
        }

        foreach ($items as $item) {
            $stmt = $this->pdo->prepare("
                INSERT INTO stockin_inventory (
                    PO_ID, Product_ID, Supplier_ID, Quantity_Ordered, 
                    Stock_in_Quantity, Unit_Cost, Total_Cost, Status, Created_Date
                ) VALUES (?, ?, ?, ?, 0, ?, 0, 'Pending', NOW())
            ");
            $stmt->execute([
                $po_id,
                (int) $item['Product_ID'],
                (int) $item['Supplier_ID'],
                (int) $item['Quantity'],
                (float) $item['Purchase_price']
            ]);
        }
    }

    /**
     * @deprecated Use createStockInFromPO followed by processStockIn
     */
    public function updateInventoryFromPO(int $po_id, int $user_id): void
    {
        $this->createStockInFromPO($po_id, $user_id);
        error_log("WARNING: updateInventoryFromPO is deprecated. Use createStockInFromPO followed by processStockIn.");
    }

    /**
     * Process stock-in and update inventory
     */
    public function processStockIn(int $stockin_id, int $quantity_received, int $user_id, string $remarks = '', string $batch_number = '', string $expiry_date = null): void
    {
        $ownTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownTransaction = true;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM stockin_inventory 
                WHERE Stockin_ID = ? AND Status = 'Pending'
            ");
            $stmt->execute([$stockin_id]);
            $stockin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$stockin) {
                throw new Exception("Stock-in record not found or already processed");
            }

            $status = 'Received';
            if ($quantity_received == 0) {
                $status = 'Rejected';
            } elseif ($quantity_received < $stockin['Quantity_Ordered']) {
                $status = 'Partial';
            }

            // Update stockin record
            $stmt = $this->pdo->prepare("
                UPDATE stockin_inventory 
                SET Stock_in_Quantity = ?, 
                    Total_Cost = ? * Unit_Cost,
                    Status = ?, 
                    Date_in = NOW(), 
                    Received_by = ?, 
                    Remarks = ?,
                    Batch_Number = ?,
                    Expiry_Date = ?,
                    Updated_Date = NOW()
                WHERE Stockin_ID = ?
            ");
            $stmt->execute([
                $quantity_received,
                $quantity_received,
                $status,
                $user_id,
                $remarks,
                $batch_number,
                $expiry_date,
                $stockin_id
            ]);

            // Add to inventory if quantity received > 0
            if ($quantity_received > 0) {
                $this->adjustStock(
                    (int) $stockin['Product_ID'],
                    $quantity_received,
                    $stockin_id,
                    'PURCHASE_RECEIPT',
                    $user_id,
                    'stockin_inventory',
                    null
                );
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }

        } catch (Exception $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get pending stock-in records
     */
    public function getPendingStockIn(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM stockin_details 
            WHERE Status = 'Pending' 
            ORDER BY Created_Date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get stock-in records by purchase order
     */
    public function getStockInByPO(int $po_id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM stockin_details 
            WHERE PO_ID = ? 
            ORDER BY Created_Date ASC
        ");
        $stmt->execute([$po_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Manual stock adjustment
     */
    public function adjustStockManual(int $product_id, int $new_quantity, int $user_id, string $reason, int $terminal_id = null): array
    {
        $currentStock = $this->getTotalStock($product_id);
        $difference = $new_quantity - $currentStock['total_qty'];

        if ($difference == 0) {
            throw new Exception("No adjustment needed. Current stock matches new quantity.");
        }

        $movement_type = $difference > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT';

        $this->adjustStock(
            $product_id,
            $difference,
            null,
            $movement_type,
            $user_id,
            'Manual Adjustment',
            $terminal_id
        );

        return [
            'product_id' => $product_id,
            'old_quantity' => $currentStock['total_qty'],
            'new_quantity' => $new_quantity,
            'difference' => $difference,
            'movement_type' => $movement_type
        ];
    }
}