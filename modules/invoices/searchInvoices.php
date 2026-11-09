<?php

require_once '../../config/setup.php';
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'Autenticación requerida.']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => ['message' => 'Método no permitido.']]);
    exit;
}

$tipo = $_GET['tipo'] ?? '';
$valor = $_GET['valor'] ?? '';
if (!is_string($tipo) || !in_array($tipo, ['folio', 'fecha'], true) ||
    !is_string($valor) || $valor === '' || strlen($valor) > 100) {
    http_response_code(422);
    echo json_encode(['error' => ['message' => 'Parámetros de búsqueda no válidos.']]);
    exit;
}
if ($tipo === 'fecha') {
    $date = DateTime::createFromFormat('!Y-m-d', $valor);
    if (!$date || $date->format('Y-m-d') !== $valor) {
        http_response_code(422);
        echo json_encode(['error' => ['message' => 'La fecha de búsqueda no es válida.']]);
        exit;
    }
}

try {
    $db = new Database();
    $conn = $db->connect();
    $stmt = $conn->prepare("CALL sp_buscar_facturas(?, ?)");
    $stmt->execute([$tipo, $valor]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    echo json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE);
} catch (PDOException $e) {
    error_log('Invoice search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => ['message' => 'No se pudo completar la búsqueda.']], JSON_INVALID_UTF8_SUBSTITUTE);
}