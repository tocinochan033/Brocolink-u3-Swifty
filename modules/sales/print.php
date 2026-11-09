<?php
require_once '../../config/setup.php';
requireAuth();

if (!isset($_GET['id'])) {
    die("Venta no encontrada");
}

$saleId = (int) $_GET['id'];

$db = new Database();
$conn = $db->connect();

/*
|--------------------------------------------------------------------------
| CONSULTAR VENTA
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
SELECT 
    s.*,

    CONCAT(
        c.first_name, ' ',
        c.last_name, ' ',
        c.mother_last_name
    ) AS client_name,

    c.phone,
    c.email,
    c.address,
    c.rfc

FROM sales s

LEFT JOIN clients c 
    ON c.id = s.client_id

WHERE s.id = :id
");

$stmt->bindParam(':id', $saleId, PDO::PARAM_INT);
$stmt->execute();

$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    die("Venta no encontrada");
}

/*
|--------------------------------------------------------------------------
| CONSULTAR PRODUCTOS
|--------------------------------------------------------------------------
*/

$details = $conn->prepare("
    SELECT 
        sd.*,
        p.name AS product_name
    FROM sale_details sd
    INNER JOIN products p ON p.id = sd.product_id
    WHERE sd.sale_id = :sale_id
");

$details->bindParam(':sale_id', $saleId, PDO::PARAM_INT);
$details->execute();

$products = $details->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Imprimir Venta</title>


<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:'Segoe UI',sans-serif;
    background:#f4f6f9;
    padding:40px;
    color:#333;
}

.invoice-container{
    max-width:950px;
    margin:auto;
    background:#fff;
    border-radius:14px;
    padding:40px;
    box-shadow:0 5px 20px rgba(0,0,0,0.12);
}

.invoice-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:2px solid #eee;
    padding-bottom:20px;
    margin-bottom:35px;
}

.logo-section h1{
    color:#0d6efd;
    font-size:34px;
}

.logo-section p{
    color:#777;
    margin-top:5px;
}

.invoice-info{
    text-align:right;
}

.invoice-info h2{
    margin-bottom:10px;
    color:#222;
}

.client-section{
    margin-bottom:30px;
}

.client-section h3{
    margin-bottom:12px;
    color:#0d6efd;
}

.client-box{
    background:#f8f9fa;
    border:1px solid #ddd;
    border-radius:10px;
    padding:20px;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}

table thead{
    background:#0d6efd;
    color:white;
}

table th{
    padding:15px;
    text-align:left;
    font-size:14px;
}

table td{
    padding:15px;
    border-bottom:1px solid #eee;
}

table tbody tr:hover{
    background:#f9f9f9;
}

.total-section{
    display:flex;
    justify-content:flex-end;
    margin-top:30px;
}

.total-box{
    width:340px;
    background:#f8f9fa;
    border:1px solid #ddd;
    border-radius:10px;
    padding:20px;
}

.total-row{
    display:flex;
    justify-content:space-between;
    margin-bottom:12px;
    font-size:16px;
}

.total-final{
    font-size:24px;
    font-weight:bold;
    color:#0d6efd;
    border-top:2px solid #ccc;
    padding-top:15px;
    margin-top:15px;
}

.actions{
    margin-top:40px;
    display:flex;
    justify-content:space-between;
}

.btn{
    border:none;
    border-radius:8px;
    padding:12px 22px;
    cursor:pointer;
    text-decoration:none;
    font-size:15px;
}

.btn-back{
    background:#6c757d;
    color:white;
}

.btn-print{
    background:#0d6efd;
    color:white;
}

@media print{

    body{
        background:white;
        padding:0;
    }

    .invoice-container{
        box-shadow:none;
        border:none;
    }

    .actions{
        display:none;
    }

}

</style>

</head>
<body>

<div class="invoice-container">

    <div class="invoice-header">
        <div class="logo-section">
    <img src="../../assets/img/logo.png" 
         alt="Logo"
         style="width:250px; margin-bottom:5px;">
    <p>Sistema de Facturación</p>

</div>
        <div class="invoice-info">
            <h2>VENTA #<?php echo $sale['id']; ?></h2>

            <p>
                <strong>Fecha:</strong>
                <?php echo $sale['sale_date']; ?>
            </p>
        </div>

    </div>
<div class="client-section">

    <h3>Información del Cliente</h3>

    <div class="client-box">

        <p>
            <strong>Nombre:</strong>
            <?php echo htmlspecialchars($sale['client_name']); ?>
        </p>

        <p>
            <strong>RFC:</strong>
            <?php echo htmlspecialchars($sale['rfc']); ?>
        </p>

        <p>
            <strong>Teléfono:</strong>
            <?php echo htmlspecialchars($sale['phone']); ?>
        </p>

        <p>
            <strong>Email:</strong>
            <?php echo htmlspecialchars($sale['email']); ?>
        </p>

        <p>
            <strong>Dirección:</strong>
            <?php echo htmlspecialchars($sale['address']); ?>
        </p>

        <p>
            <strong>Tipo de Cliente:</strong>
            <?php echo ucfirst($sale['client_type']); ?>
        </p>

    </div>

</div>

    <table>

        <thead>
            <tr>
                <th>Producto</th>
                <th>Cantidad</th>
                <th>Precio Unitario</th>
                <th>Subtotal</th>
            </tr>
        </thead>

        <tbody>

            <?php foreach($products as $p): ?>

            <tr>

                <td>
                    <?php echo htmlspecialchars($p['product_name']); ?>
                </td>

                <td>
                    <?php echo $p['quantity']; ?>
                </td>

                <td>
                    $<?php echo number_format($p['unit_price'],2); ?>
                </td>

                <td>
                    $<?php echo number_format($p['subtotal'],2); ?>
                </td>

            </tr>

            <?php endforeach; ?>

        </tbody>

    </table>

    <div class="total-section">

        <div class="total-box">

            <div class="total-row">
                <span>Subtotal:</span>

                <span>
                    $<?php echo number_format($sale['subtotal'],2); ?>
                </span>
            </div>

            <div class="total-row">
                <span>IVA:</span>

                <span>
                    $<?php echo number_format($sale['tax_amount'],2); ?>
                </span>
            </div>

            <div class="total-row total-final">

                <span>Total:</span>

                <span>
                    $<?php echo number_format($sale['total'],2); ?>
                </span>

            </div>

        </div>

    </div>

    <div class="actions">

        <a href="/swift_invoice/modules/sales/index.php"
           class="btn btn-back">
           ← Volver
        </a>

        <button class="btn btn-print" onclick="window.print()">
            🖨 Imprimir
        </button>

    </div>

</div>

</body>
</html>
