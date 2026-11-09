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

    // Validar campos esperados
    if (is_array($data) &&
        filter_var($data['id'] ?? null, FILTER_VALIDATE_INT) &&
        is_string($data['name'] ?? null) &&
        is_numeric($data['price'] ?? null) && (float) $data['price'] >= 0 &&
        filter_var($data['quantity'] ?? null, FILTER_VALIDATE_INT) &&
        (int) $data['quantity'] > 0 &&
        is_numeric($data['tax_rate'] ?? null) && (float) $data['tax_rate'] >= 0 &&
        (float) $data['tax_rate'] <= 100) {
        if (!isset($_SESSION['sale_products'])) {
            $_SESSION['sale_products'] = [];
        }

        $db = new Database();
        $stmt = $db->connect()->prepare('SELECT id, name, price FROM products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $data['id']]);
        $product = $stmt->fetch();
        if (!$product) {
            http_response_code(422);
            echo json_encode(['error' => 'Producto inválido']);
            exit;
        }

        $_SESSION['sale_products'][] = [
            'id'       => (int) $product['id'],
            'name'     => $product['name'],
            'price'    => (float) $product['price'],
            'quantity' => (int) $data['quantity'],
            'tax_rate' => (float) $data['tax_rate']
        ];

        echo json_encode(['success' => true], JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos'], JSON_INVALID_UTF8_SUBSTITUTE);
    }
} else {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Método no permitido']);
}
?>
