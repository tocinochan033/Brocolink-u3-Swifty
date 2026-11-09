<?php
require_once '../../config/setup.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
requireCsrf();

$isUpdate = isset($_GET['id']);
$saleId = $isUpdate ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
$conn = null;
if ($isUpdate && (!$saleId || $saleId < 1)) {
    http_response_code(400);
    exit('Identificador de venta no válido.');
}
$redirectTo = $saleId
    ? "/swift_invoice/modules/sales/edit.php?id={$saleId}"
    : '/swift_invoice/modules/sales/create.php';

try {
    $clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT);
    $clientType = $_POST['client_type'] ?? '';
    $saleDate = $_POST['sale_date'] ?? '';
    $products = $_POST['products'] ?? null;

    if (!$clientId || !in_array($clientType, ['person', 'company'], true) ||
        !is_string($saleDate) || !is_array($products) ||
        count($products) < 1 || count($products) > 100) {
        throw new InvalidArgumentException('Datos de venta inválidos.');
    }

    $date = DateTime::createFromFormat('!Y-m-d', $saleDate);
    if (!$date || $date->format('Y-m-d') !== $saleDate) {
        throw new InvalidArgumentException('La fecha de venta no es válida.');
    }

    $db = new Database();
    $conn = $db->connect();
    $clientTable = $clientType === 'person' ? 'clients' : 'companies';
    $clientCheck = $conn->prepare("SELECT 1 FROM {$clientTable} WHERE id = :id LIMIT 1");
    $clientCheck->execute([':id' => $clientId]);
    if (!$clientCheck->fetchColumn()) {
        throw new InvalidArgumentException('El cliente seleccionado no existe.');
    }

    $productQuery = $conn->prepare('SELECT id, price FROM products WHERE id = :id LIMIT 1');
    $normalizedProducts = [];
    $subtotal = 0.0;
    $taxAmount = 0.0;
    $maxAmount = 99999999.99;

    foreach ($products as $product) {
        if (!is_array($product)) {
            throw new InvalidArgumentException('Hay un producto inválido.');
        }

        $productId = filter_var($product['id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($product['quantity'] ?? null, FILTER_VALIDATE_INT);
        $taxRate = $product['tax_rate'] ?? 0;
        if (!$productId || !$quantity || $quantity > 100000 ||
            !is_numeric($taxRate) || (float) $taxRate < 0 || (float) $taxRate > 100) {
            throw new InvalidArgumentException('Hay cantidades o impuestos inválidos.');
        }

        $productQuery->execute([':id' => $productId]);
        $catalogProduct = $productQuery->fetch();
        if (!$catalogProduct) {
            throw new InvalidArgumentException('Uno de los productos ya no está disponible.');
        }

        $unitPrice = (float) $catalogProduct['price'];
        $lineSubtotal = round($unitPrice * $quantity, 2);
        $lineTaxRate = (float) $taxRate;
        $lineTax = round($lineSubtotal * ($lineTaxRate / 100), 2);
        $nextSubtotal = round($subtotal + $lineSubtotal, 2);
        $nextTaxAmount = round($taxAmount + $lineTax, 2);
        if ($nextSubtotal > $maxAmount || $nextTaxAmount > $maxAmount ||
            round($nextSubtotal + $nextTaxAmount, 2) > $maxAmount) {
            throw new InvalidArgumentException('El total de la venta supera el máximo permitido.');
        }
        $subtotal = $nextSubtotal;
        $taxAmount = $nextTaxAmount;
        $normalizedProducts[] = [
            'id' => $productId,
            'quantity' => $quantity,
            'price' => $unitPrice,
            'subtotal' => $lineSubtotal,
            'tax_rate' => $lineTaxRate
        ];
    }

    $subtotal = round($subtotal, 2);
    $taxAmount = round($taxAmount, 2);
    $total = round($subtotal + $taxAmount, 2);
    $taxPercentage = $subtotal > 0 ? round(($taxAmount / $subtotal) * 100, 2) : 0;
    $username = $_SESSION['username'] ?? 'Sistema';

    $conn->beginTransaction();
    $audit = $conn->prepare('INSERT INTO current_audit_user (username) VALUES (:username)');
    $audit->execute([':username' => $username]);

    if ($isUpdate) {
        $update = $conn->prepare(
            'UPDATE sales
             SET client_id = :client_id, client_type = :client_type, sale_date = :sale_date,
                 subtotal = :subtotal, tax_percentage = :tax_percentage,
                 tax_amount = :tax_amount, total = :total
             WHERE id = :sale_id'
        );
        $update->execute([
            ':client_id' => $clientId,
            ':client_type' => $clientType,
            ':sale_date' => $saleDate,
            ':subtotal' => $subtotal,
            ':tax_percentage' => $taxPercentage,
            ':tax_amount' => $taxAmount,
            ':total' => $total,
            ':sale_id' => $saleId
        ]);
        if ($update->rowCount() === 0) {
            $exists = $conn->prepare('SELECT 1 FROM sales WHERE id = :id LIMIT 1');
            $exists->execute([':id' => $saleId]);
            if (!$exists->fetchColumn()) {
                throw new InvalidArgumentException('La venta solicitada no existe.');
            }
        }
        $conn->prepare('DELETE FROM sale_details WHERE sale_id = :sale_id')
            ->execute([':sale_id' => $saleId]);
    } else {
        $insert = $conn->prepare(
            'INSERT INTO sales
                (client_id, client_type, sale_date, subtotal, tax_percentage, tax_amount, total)
             VALUES
                (:client_id, :client_type, :sale_date, :subtotal, :tax_percentage, :tax_amount, :total)'
        );
        $insert->execute([
            ':client_id' => $clientId,
            ':client_type' => $clientType,
            ':sale_date' => $saleDate,
            ':subtotal' => $subtotal,
            ':tax_percentage' => $taxPercentage,
            ':tax_amount' => $taxAmount,
            ':total' => $total
        ]);
        $saleId = (int) $conn->lastInsertId();
    }

    $detail = $conn->prepare(
        'INSERT INTO sale_details (sale_id, product_id, quantity, unit_price, subtotal, tax_rate)
         VALUES (:sale_id, :product_id, :quantity, :unit_price, :subtotal, :tax_rate)'
    );
    foreach ($normalizedProducts as $product) {
        $detail->execute([
            ':sale_id' => $saleId,
            ':product_id' => $product['id'],
            ':quantity' => $product['quantity'],
            ':unit_price' => $product['price'],
            ':subtotal' => $product['subtotal'],
            ':tax_rate' => $product['tax_rate']
        ]);
    }

    $conn->commit();
    unset($_SESSION['sale_products']);
    $_SESSION['success_message'] = $isUpdate
        ? 'Venta actualizada correctamente'
        : 'Venta registrada correctamente';
    redirect($isUpdate
        ? "/swift_invoice/modules/sales/edit.php?id={$saleId}"
        : '/swift_invoice/modules/sales/create.php');
} catch (InvalidArgumentException $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['error_message'] = $e->getMessage();
    redirect($redirectTo);
} catch (PDOException $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Sale processing failed: ' . $e->getMessage());
    $_SESSION['error_message'] = 'No se pudo procesar la venta. Inténtalo de nuevo.';
    redirect($redirectTo);
}
