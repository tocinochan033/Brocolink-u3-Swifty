<?php
require_once '../../config/setup.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    requireCsrf();
    $saleId = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    if (!$saleId || $saleId < 1) {
        $_SESSION['error_message'] = 'Identificador de venta no válido.';
        header('Location: index.php');
        exit;
    }

    $db   = new Database();
    $conn = $db->connect();

    try {
        $conn->beginTransaction();

        $usuario = $_SESSION['username'] ?? 'Sistema';

        $stmtAudit = $conn->prepare("
            INSERT INTO current_audit_user (username)
            VALUES (:username)
        ");

        $stmtAudit->bindParam(':username', $usuario);
        $stmtAudit->execute();

        // 1) Borrar detalles de la venta
        $delDetails = $conn->prepare("DELETE FROM sale_details WHERE sale_id = :id");
        $delDetails->bindParam(':id', $saleId, PDO::PARAM_INT);
        $delDetails->execute();

        // 2) Borrar la venta
        $delSale = $conn->prepare("DELETE FROM sales WHERE id = :id");
        $delSale->bindParam(':id', $saleId, PDO::PARAM_INT);
        $delSale->execute();
        if ($delSale->rowCount() === 0) {
            throw new RuntimeException('La venta no existe.');
        }

        // Confirmar cambios
        $conn->commit();
        $_SESSION['success_message'] = "Venta eliminada correctamente.";
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Sale delete failed: ' . $e->getMessage());
        $_SESSION['error_message'] = 'No se pudo eliminar la venta.';
    }
}

header("Location: index.php");
exit;
