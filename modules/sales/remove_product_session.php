<?php
require_once '../../config/setup.php';
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Autenticación requerida.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf(true);
    $data = json_decode(file_get_contents('php://input'), true);

    if (
        is_array($data) &&
        isset($_SESSION['sale_products']) &&
        filter_var($data['index'] ?? null, FILTER_VALIDATE_INT) !== false &&
        (int) $data['index'] >= 0 &&
        isset($_SESSION['sale_products'][(int) $data['index']])
    ) {
        array_splice($_SESSION['sale_products'], (int) $data['index'], 1);

        // Reindexar el arreglo para evitar huecos
        $_SESSION['sale_products'] = array_values($_SESSION['sale_products']);

        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
    }
} else {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Método no permitido']);
}
?>
