<?php
require_once '../../config/setup.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    requireCsrf();
    $companyId = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    if (!$companyId || $companyId < 1) {
        $_SESSION['success_message'] = 'Identificador de empresa no válido.';
        header('Location: index.php');
        exit;
    }

    $db = new Database();
    $conn = $db->connect();

    try {
        // Verificar si hay ventas relacionadas con esta compañía
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM sales WHERE client_id = :id AND client_type = 'company'");
        $checkStmt->bindParam(':id', $companyId, PDO::PARAM_INT);
        $checkStmt->execute();
        $relatedSales = $checkStmt->fetchColumn();

        if ($relatedSales > 0) {
            $_SESSION['success_message'] = "No se puede eliminar esta compañía porque tiene ventas registradas. Elimine o reasigne las ventas antes de continuar.";
        } else {
            $stmt = $conn->prepare("DELETE FROM companies WHERE id = :id");
            $stmt->bindParam(':id', $companyId, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Empresa eliminada correctamente.";
            } else {
                $_SESSION['success_message'] = "Error al eliminar la empresa.";
            }
        }

    } catch (PDOException $e) {
        error_log('Company delete failed: ' . $e->getMessage());
        $_SESSION['success_message'] = 'No se pudo eliminar la empresa.';
    }
}

header("Location: index.php");
exit;
