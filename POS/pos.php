<?php
// ============================================
// File: pos.php (Complete POS System with Add Product)
// ============================================

session_start();
include 'db_connect.php';

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Load products from database
$products = [];
$result = $conn->query("SELECT * FROM products ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $products[$row['id']] = [
        'name' => $row['name'],
        'price' => $row['price']
    ];
}

// Handle actions
$message = '';
$receipt_data = null;

// ADD NEW PRODUCT
if (isset($_POST['add_product'])) {
    $product_name = trim($_POST['product_name']);
    $product_price = (float)$_POST['product_price'];
    
    if (empty($product_name)) {
        $message = "Product name is required!";
    } elseif ($product_price <= 0) {
        $message = "Price must be greater than 0!";
    } else {
        $stmt = $conn->prepare("INSERT INTO products (name, price) VALUES (?, ?)");
        $stmt->bind_param("sd", $product_name, $product_price);
        
        if ($stmt->execute()) {
            $message = "Product '" . $product_name . "' added successfully!";
            // Reload products
            $products = [];
            $result = $conn->query("SELECT * FROM products ORDER BY name");
            while ($row = $result->fetch_assoc()) {
                $products[$row['id']] = [
                    'name' => $row['name'],
                    'price' => $row['price']
                ];
            }
        } else {
            $message = "Error adding product: " . $conn->error;
        }
        $stmt->close();
    }
}

// DELETE PRODUCT
if (isset($_GET['delete_product'])) {
    $product_id = (int)$_GET['delete_product'];
    
    // Check if product exists
    $check = $conn->query("SELECT id FROM products WHERE id = $product_id");
    if ($check->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        
        if ($stmt->execute()) {
            $message = "Product deleted successfully!";
            // Reload products
            $products = [];
            $result = $conn->query("SELECT * FROM products ORDER BY name");
            while ($row = $result->fetch_assoc()) {
                $products[$row['id']] = [
                    'name' => $row['name'],
                    'price' => $row['price']
                ];
            }
        } else {
            $message = "Error deleting product: " . $conn->error;
        }
        $stmt->close();
    } else {
        $message = "Product not found!";
    }
}

// EDIT PRODUCT
if (isset($_POST['edit_product'])) {
    $product_id = (int)$_POST['product_id'];
    $product_name = trim($_POST['product_name']);
    $product_price = (float)$_POST['product_price'];
    
    if (empty($product_name)) {
        $message = "Product name is required!";
    } elseif ($product_price <= 0) {
        $message = "Price must be greater than 0!";
    } else {
        $stmt = $conn->prepare("UPDATE products SET name = ?, price = ? WHERE id = ?");
        $stmt->bind_param("sdi", $product_name, $product_price, $product_id);
        
        if ($stmt->execute()) {
            $message = "Product updated successfully!";
            // Reload products
            $products = [];
            $result = $conn->query("SELECT * FROM products ORDER BY name");
            while ($row = $result->fetch_assoc()) {
                $products[$row['id']] = [
                    'name' => $row['name'],
                    'price' => $row['price']
                ];
            }
        } else {
            $message = "Error updating product: " . $conn->error;
        }
        $stmt->close();
    }
}

// Add to cart
if (isset($_POST['add'])) {
    $product_id = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    
    if ($quantity > 0 && isset($products[$product_id])) {
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$product_id] = [
                'name' => $products[$product_id]['name'],
                'price' => $products[$product_id]['price'],
                'quantity' => $quantity
            ];
        }
        $message = "Added " . $products[$product_id]['name'] . " x" . $quantity;
    }
}

// Remove from cart
if (isset($_GET['remove'])) {
    $product_id = (int)$_GET['remove'];
    if (isset($_SESSION['cart'][$product_id])) {
        unset($_SESSION['cart'][$product_id]);
        $message = "Item removed from cart";
    }
}

// Update quantity
if (isset($_POST['update'])) {
    $product_id = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    
    if (isset($_SESSION['cart'][$product_id])) {
        if ($quantity > 0) {
            $_SESSION['cart'][$product_id]['quantity'] = $quantity;
        } else {
            unset($_SESSION['cart'][$product_id]);
        }
        $message = "Cart updated";
    }
}

// Clear cart
if (isset($_POST['clear'])) {
    $_SESSION['cart'] = [];
    $message = "Cart cleared";
}

// Checkout
if (isset($_POST['checkout'])) {
    if (empty($_SESSION['cart'])) {
        $message = "Cart is empty!";
    } else {
        $is_senior = isset($_POST['is_senior']) ? 1 : 0;
        
        $subtotal = 0;
        $items = [];
        foreach ($_SESSION['cart'] as $id => $item) {
            $item_total = $item['price'] * $item['quantity'];
            $subtotal += $item_total;
            $items[] = [
                'product_id' => $id,
                'name' => $item['name'],
                'qty' => $item['quantity'],
                'price' => $item['price'],
                'total' => $item_total
            ];
        }
        
        $tax_rate = 0.02;
        $tax = $subtotal * $tax_rate;
        
        $discount_rate = 0.05;
        $discount = $is_senior ? ($subtotal * $discount_rate) : 0;
        
        $grand_total = $subtotal + $tax - $discount;
        
        $conn->begin_transaction();
        
        try {
            $stmt = $conn->prepare("INSERT INTO transactions (subtotal, tax, discount, grand_total, is_senior) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ddddi", $subtotal, $tax, $discount, $grand_total, $is_senior);
            $stmt->execute();
            $transaction_id = $conn->insert_id;
            
            foreach ($items as $item) {
                $stmt = $conn->prepare("INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iisidd", $transaction_id, $item['product_id'], $item['name'], $item['qty'], $item['price'], $item['total']);
                $stmt->execute();
            }
            
            $conn->commit();
            
            $receipt_data = [
                'items' => $items,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'tax_rate' => $tax_rate,
                'discount' => $discount,
                'discount_rate' => $discount_rate,
                'is_senior' => $is_senior,
                'grand_total' => $grand_total,
                'transaction_id' => $transaction_id
            ];
            
            $_SESSION['cart'] = [];
            $message = "Transaction complete! Receipt #" . $transaction_id;
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error saving transaction: " . $e->getMessage();
        }
    }
}

function getCartTotal() {
    $total = 0;
    foreach ($_SESSION['cart'] as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    return $total;
}

function formatMoney($amount) {
    return '₱' . number_format($amount, 2);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>POS Counter System</title>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #333;
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        h2 {
            color: #555;
            margin-top: 20px;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        h3 {
            color: #555;
            margin-top: 20px;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .message {
            padding: 12px 15px;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .message.error {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-inline {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        select, input[type="number"], input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        select {
            min-width: 200px;
        }
        
        input[type="number"] {
            width: 80px;
        }
        
        input[type="text"] {
            min-width: 200px;
        }
        
        button, .btn {
            padding: 8px 16px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        
        button:hover {
            background: #45a049;
        }
        
        .btn-danger {
            background: #f44336;
        }
        
        .btn-danger:hover {
            background: #da190b;
        }
        
        .btn-warning {
            background: #ff9800;
        }
        
        .btn-warning:hover {
            background: #e68900;
        }
        
        .btn-checkout {
            background: #2196F3;
            font-size: 16px;
            padding: 10px 30px;
        }
        
        .btn-checkout:hover {
            background: #0b7dda;
        }
        
        .btn-primary {
            background: #007bff;
        }
        
        .btn-primary:hover {
            background: #0069d9;
        }
        
        .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        table th {
            background: #f8f9fa;
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
            font-weight: bold;
        }
        
        table td {
            padding: 10px;
            border: 1px solid #ddd;
        }
        
        table tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .cart-total {
            font-size: 18px;
            font-weight: bold;
            color: #4CAF50;
        }
        
        .action-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .receipt-container {
            background: #f9f9f9;
            border: 2px solid #333;
            padding: 20px;
            max-width: 400px;
            margin: 20px 0;
            border-radius: 4px;
        }
        
        .receipt-container pre {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
            margin: 0;
        }
        
        .receipt-header {
            text-align: center;
            font-weight: bold;
            font-size: 16px;
            border-bottom: 2px dashed #333;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        
        .receipt-footer {
            text-align: center;
            border-top: 2px dashed #333;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .checkbox-group {
            margin: 15px 0;
        }
        
        .checkbox-group label {
            font-size: 16px;
            cursor: pointer;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 8px;
            cursor: pointer;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        
        .summary-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        
        .summary-box .label {
            font-size: 14px;
            color: #666;
        }
        
        .summary-box .value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 8px;
            max-width: 500px;
            position: relative;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .close {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #999;
        }
        
        .close:hover {
            color: #333;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .form-inline {
                flex-direction: column;
                align-items: stretch;
            }
            
            select, input[type="number"], input[type="text"] {
                width: 100%;
            }
            
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 14px;
            }
            
            table th, table td {
                padding: 6px;
            }
            
            .receipt-container {
                max-width: 100%;
            }
            
            .modal-content {
                margin: 20% 10px;
                padding: 20px;
            }
        }
    </style>
</head>
<body> 

<div class="container">
    <h1>TUNG TUNG TUNG SAHUR SUPERMARKET</h1>
    
    <?php if ($message): ?>
        <div class="message <?php echo strpos($message, 'Error') !== false || strpos($message, 'not found') !== false ? 'error' : ''; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <!-- Product Selection -->
    <div style="background: #f8f9fa; padding: 20px; border-radius: 4px; margin-bottom: 20px;">
        <h2>Add Product to Cart</h2>
        <form method="POST" class="form-inline">
            <select name="product_id" required>
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $id => $product): ?>
                    <option value="<?php echo $id; ?>">
                        <?php echo $product['name'] . ' - ' . formatMoney($product['price']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="quantity" value="1" min="1" required>
            <button type="submit" name="add">Add to Cart</button>
        </form>
    </div>
    
    <!-- Cart Display -->
    <h2>Shopping Cart</h2>
    <?php if (empty($_SESSION['cart'])): ?>
        <p style="color: #999; font-style: italic;">Cart is empty</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 35%;">Product</th>
                    <th style="width: 15%;">Price</th>
                    <th style="width: 25%;">Quantity</th>
                    <th style="width: 15%;">Subtotal</th>
                    <th style="width: 10%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($_SESSION['cart'] as $id => $item): ?>
                <tr>
                    <td><?php echo $item['name']; ?></td>
                    <td><?php echo formatMoney($item['price']); ?></td>
                    <td>
                        <form method="POST" style="display:flex; gap:5px; align-items:center;">
                            <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                            <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="0" style="width:60px;">
                            <button type="submit" name="update" class="btn btn-sm">Update</button>
                        </form>
                    </td>
                    <td><?php echo formatMoney($item['price'] * $item['quantity']); ?></td>
                    <td>
                        <a href="?remove=<?php echo $id; ?>" onclick="return confirm('Remove item?')" style="color:#f44336; text-decoration:none;">REMOVE</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background: #e8f5e9; font-weight: bold;">
                    <td colspan="3" align="right">TOTAL:</td>
                    <td class="cart-total"><?php echo formatMoney(getCartTotal()); ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        
        <div class="action-links">
            <form method="POST" style="display:inline;">
                <button type="submit" name="clear" class="btn btn-danger" onclick="return confirm('Clear all items?')">Clear Cart</button>
            </form>
        </div>
    <?php endif; ?>
    
    <!-- Checkout Section -->
    <div style="background: #e3f2fd; padding: 20px; border-radius: 4px; margin: 20px 0;">
        <h2>Checkout</h2>
        <form method="POST">
            <div class="checkbox-group">
                <label>
                    <input type="checkbox" name="is_senior" value="1">
                    Senior Citizen (5% Discount)
                </label>
            </div>
            <button type="submit" name="checkout" class="btn btn-checkout">Process Checkout</button>
        </form>
    </div>
    
    <!-- Receipt Display -->
    <?php if ($receipt_data): ?>
        <h2>RECEIPT #<?php echo $receipt_data['transaction_id']; ?></h2>
        <div class="receipt-container">
            <div class="receipt-header">
                SUPERMARKET POS<br>
                <?php echo date('Y-m-d H:i:s'); ?>
            </div>
            <pre><?php
                echo str_repeat('-', 38) . "\n";
                foreach ($receipt_data['items'] as $item) {
                    $name = substr($item['name'], 0, 20);
                    $qty_price = $item['qty'] . ' x ' . formatMoney($item['price']);
                    $total = formatMoney($item['total']);
                    printf("%-20s %12s %8s\n", $name, $qty_price, $total);
                }
                echo str_repeat('-', 38) . "\n";
                printf("%-20s %20s\n", "Subtotal:", formatMoney($receipt_data['subtotal']));
                printf("%-20s %20s\n", "Tax (2%):", formatMoney($receipt_data['tax']));
                if ($receipt_data['is_senior']) {
                    printf("%-20s %20s\n", "Discount (5%):", '-' . formatMoney($receipt_data['discount']));
                }
                echo str_repeat('-', 38) . "\n";
                printf("%-20s %20s\n", "GRAND TOTAL:", formatMoney($receipt_data['grand_total']));
                echo str_repeat('-', 38) . "\n";
            ?></pre>
            <div class="receipt-footer">
                Thank you for shopping!<br>
                <a href="" style="color:#2196F3;">New Transaction</a>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Product Management -->
    <div style="margin-top: 30px; border-top: 2px solid #eee; padding-top: 20px;">
        <h2>Product Management</h2>
        
        <!-- Add Product Form -->
        <div style="background: #fff3cd; padding: 20px; border-radius: 4px; border: 1px solid #ffeeba; margin-bottom: 20px;">
            <h3>Add New Product</h3>
            <form method="POST" class="form-inline">
                <input type="text" name="product_name" placeholder="Product Name" required>
                <input type="number" name="product_price" placeholder="Price" step="0.01" min="0.01" required>
                <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
            </form>
        </div>
        
        <!-- Product List with Edit/Delete -->
        <h3>Product List</h3>
        <table>
            <thead>
                <tr>
                    <th style="width: 10%;">ID</th>
                    <th style="width: 45%;">Product</th>
                    <th style="width: 20%;">Price</th>
                    <th style="width: 25%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $id => $product): ?>
                <tr>
                    <td><?php echo $id; ?></td>
                    <td>
                        <?php if (isset($_GET['edit']) && $_GET['edit'] == $id): ?>
                            <form method="POST" style="display:flex; gap:5px; flex-wrap:wrap;">
                                <input type="hidden" name="product_id" value="<?php echo $id; ?>">
                                <input type="text" name="product_name" value="<?php echo $product['name']; ?>" required>
                                <input type="number" name="product_price" value="<?php echo $product['price']; ?>" step="0.01" min="0.01" required style="width:100px;">
                                <button type="submit" name="edit_product" class="btn btn-sm">Save</button>
                                <a href="?" class="btn btn-sm btn-danger">Cancel</a>
                            </form>
                        <?php else: ?>
                            <?php echo $product['name']; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo formatMoney($product['price']); ?></td>
                    <td>
                        <?php if (!isset($_GET['edit']) || $_GET['edit'] != $id): ?>
                            <a href="?edit=<?php echo $id; ?>" class="btn btn-warning btn-sm">Edit</a>
                            <a href="?delete_product=<?php echo $id; ?>" onclick="return confirm('Delete this product?')" class="btn btn-danger btn-sm">Delete</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Sales Summary -->
    <div style="margin-top: 30px;">
        <h2>Today's Sales Summary</h2>
        <div class="summary-grid">
            <?php
            $today = date('Y-m-d');
            $result = $conn->query("SELECT COUNT(*) as count, SUM(grand_total) as total FROM transactions WHERE DATE(transaction_date) = '$today'");
            $row = $result->fetch_assoc();
            ?>
            <div class="summary-box">
                <div class="label">Total Transactions</div>
                <div class="value"><?php echo $row['count'] ? $row['count'] : 0; ?></div>
            </div>
            <div class="summary-box">
                <div class="label">Total Sales</div>
                <div class="value"><?php echo formatMoney($row['total'] ? $row['total'] : 0); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Recent Transactions -->
    <h2>Recent Transactions</h2>
    <table>
        <thead>
            <tr>
                <th>Receipt #</th>
                <th>Date/Time</th>
                <th>Subtotal</th>
                <th>Tax</th>
                <th>Discount</th>
                <th>Grand Total</th>
                <th>Senior</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $result = $conn->query("SELECT * FROM transactions ORDER BY id DESC LIMIT 10");
            if ($result->num_rows > 0):
                while ($row = $result->fetch_assoc()):
            ?>
            <tr>
                <td><?php echo $row['id']; ?></td>
                <td><?php echo date('m/d/Y H:i', strtotime($row['transaction_date'])); ?></td>
                <td><?php echo formatMoney($row['subtotal']); ?></td>
                <td><?php echo formatMoney($row['tax']); ?></td>
                <td><?php echo formatMoney($row['discount']); ?></td>
                <td><strong><?php echo formatMoney($row['grand_total']); ?></strong></td>
                <td><?php echo $row['is_senior'] ? 'Yes' : 'No'; ?></td>
            </tr>
            <?php 
                endwhile;
            else:
            ?>
            <tr>
                <td colspan="7" style="text-align:center; color:#999;">No transactions yet</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>