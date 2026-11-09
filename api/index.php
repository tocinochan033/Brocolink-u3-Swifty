<?php
require_once __DIR__ . '/../config/setup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function apiRespond($data, $status = 200, $meta = null) {
    http_response_code($status);
    if ($status === 204) {
        exit;
    }
    if ($meta !== null) {
        header('X-Total-Count: ' . $meta['total']);
        header('X-Limit: ' . $meta['limit']);
        header('X-Offset: ' . $meta['offset']);
    }
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function apiError($message, $status, $errors = null) {
    http_response_code($status);
    $response = ['error' => ['message' => $message]];
    if ($errors !== null) {
        $response['error']['fields'] = $errors;
    }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function apiRequireAuth() {
    if (!isLoggedIn()) {
        apiError('Autenticación requerida.', 401);
    }
}

function apiRequireCsrf() {
    $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submitted) || !is_string($stored) || $stored === '' ||
        !hash_equals($stored, $submitted)) {
        apiError('Token CSRF inválido o ausente.', 403);
    }
}

function apiJsonBody() {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
        apiError('El cuerpo de la solicitud supera el límite permitido.', 413);
    }
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        apiError('El Content-Type debe ser application/json.', 415);
    }
    $input = fopen('php://input', 'rb');
    if ($input === false) {
        apiError('No se pudo leer el cuerpo de la solicitud.', 400);
    }
    $rawBody = stream_get_contents($input, 1048577);
    fclose($input);
    if ($rawBody === false) {
        apiError('No se pudo leer el cuerpo de la solicitud.', 400);
    }
    if (strlen($rawBody) > 1048576) {
        apiError('El cuerpo de la solicitud supera el límite permitido.', 413);
    }
    $data = json_decode($rawBody, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        apiError('El cuerpo JSON no es válido.', 400);
    }
    return $data;
}

function apiValidate($input, $schema) {
    $errors = [];
    foreach ($input as $key => $value) {
        if (!array_key_exists($key, $schema)) {
            $errors[$key][] = 'Campo no permitido.';
        }
    }

    $result = [];
    foreach ($schema as $name => $rule) {
        $provided = array_key_exists($name, $input);
        $value = $provided ? $input[$name] : null;
        if (!$provided && !empty($rule['required'])) {
            $errors[$name][] = 'Este campo es requerido.';
            continue;
        }
        if (!$provided && !empty($rule['nullable'])) {
            $result[$name] = null;
            continue;
        }
        if (!$provided) {
            continue;
        }
        if ($value === null && !empty($rule['nullable'])) {
            $result[$name] = null;
            continue;
        }
        if ($value === null) {
            $errors[$name][] = 'No puede ser nulo.';
            continue;
        }

        switch ($rule['type']) {
            case 'string':
                if (!is_string($value)) {
                    $errors[$name][] = 'Debe ser texto.';
                    continue 2;
                }
                if (!isset($rule['trim']) || $rule['trim']) {
                    $value = trim($value);
                }
                if (!empty($rule['required']) && $value === '') {
                    $errors[$name][] = 'No puede estar vacío.';
                }
                if ($value === '' && !empty($rule['nullable'])) {
                    $result[$name] = null;
                    continue 2;
                }
                if (isset($rule['max']) && mb_strlen($value, 'UTF-8') > $rule['max']) {
                    $errors[$name][] = 'Supera la longitud máxima permitida.';
                }
                if (isset($rule['maxBytes']) && strlen($value) > $rule['maxBytes']) {
                    $errors[$name][] = 'Supera la longitud máxima permitida.';
                }
                if (isset($rule['min']) && $value !== '' &&
                    mb_strlen($value, 'UTF-8') < $rule['min']) {
                    $errors[$name][] = 'No alcanza la longitud mínima permitida.';
                }
                if (isset($rule['pattern']) && $value !== '' &&
                    !preg_match($rule['pattern'], $value)) {
                    $errors[$name][] = 'El formato no es válido.';
                }
                if (!empty($rule['email']) && $value !== '' &&
                    !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$name][] = 'Debe ser un correo válido.';
                }
                if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) {
                    $errors[$name][] = 'El valor seleccionado no es válido.';
                }
                $result[$name] = $value;
                break;
            case 'integer':
                $validated = filter_var($value, FILTER_VALIDATE_INT);
                if ($validated === false || $validated < ($rule['min'] ?? 0)) {
                    $errors[$name][] = 'Debe ser un número entero válido.';
                } else {
                    $result[$name] = $validated;
                }
                break;
            case 'number':
                if (!is_numeric($value) || !is_finite((float) $value) ||
                    (float) $value < ($rule['min'] ?? 0) ||
                    (isset($rule['max']) && (float) $value > $rule['max'])) {
                    $errors[$name][] = 'Debe ser un número dentro del rango permitido.';
                } else {
                    $result[$name] = (float) $value;
                }
                break;
        }
    }
    if ($errors) {
        apiError('Los datos enviados no son válidos.', 422, $errors);
    }
    return $result;
}

function apiPagination() {
    $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT);
    $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1 || $limit > 100 ||
        $offset === false || $offset < 0) {
        apiError('Los parámetros de paginación no son válidos.', 422);
    }
    return [$limit, $offset];
}

function apiList($conn, $table, $columns) {
    list($limit, $offset) = apiPagination();
    $total = (int) $conn->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    $statement = $conn->prepare(
        "SELECT {$columns} FROM {$table} ORDER BY id DESC LIMIT :limit OFFSET :offset"
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    apiRespond($statement->fetchAll(), 200, [
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total
    ]);
}

function apiResourceSchema($resource) {
    $schemas = [
        'clients' => [
            'first_name' => ['type' => 'string', 'required' => true, 'max' => 50],
            'last_name' => ['type' => 'string', 'required' => true, 'max' => 50],
            'mother_last_name' => ['type' => 'string', 'nullable' => true, 'max' => 50],
            'phone' => ['type' => 'string', 'nullable' => true, 'max' => 20],
            'email' => ['type' => 'string', 'nullable' => true, 'email' => true, 'max' => 100],
            'rfc' => ['type' => 'string', 'nullable' => true, 'min' => 12, 'max' => 13, 'pattern' => '/\A[A-ZÑ&0-9]{12,13}\z/i'],
            'address' => ['type' => 'string', 'nullable' => true, 'max' => 500],
            'tax_regime_id' => ['type' => 'integer', 'nullable' => true, 'min' => 1]
        ],
        'companies' => [
            'business_name' => ['type' => 'string', 'required' => true, 'max' => 100],
            'rfc' => ['type' => 'string', 'required' => true, 'min' => 12, 'max' => 13, 'pattern' => '/\A[A-ZÑ&0-9]{12,13}\z/i'],
            'fiscal_address' => ['type' => 'string', 'required' => true, 'max' => 500],
            'phone' => ['type' => 'string', 'nullable' => true, 'max' => 20],
            'email' => ['type' => 'string', 'nullable' => true, 'email' => true, 'max' => 100],
            'legal_representative' => ['type' => 'string', 'nullable' => true, 'max' => 100],
            'business_type_id' => ['type' => 'integer', 'nullable' => true, 'min' => 1],
            'tax_regime_id' => ['type' => 'integer', 'nullable' => true, 'min' => 1]
        ],
        'products' => [
            'name' => ['type' => 'string', 'required' => true, 'max' => 100],
            'description' => ['type' => 'string', 'nullable' => true, 'max' => 10000],
            'type' => ['type' => 'string', 'required' => true, 'enum' => ['Producto', 'Servicio']],
            'price' => ['type' => 'number', 'required' => true, 'min' => 0, 'max' => 99999999.99]
        ]
    ];
    return $schemas[$resource];
}

function apiValidateReferences($conn, $resource, $data) {
    $references = [
        'clients' => ['tax_regime_id' => 'tax_regimes'],
        'companies' => [
            'tax_regime_id' => 'tax_regimes',
            'business_type_id' => 'business_types'
        ]
    ];
    foreach ($references[$resource] ?? [] as $field => $table) {
        if ($data[$field] === null) {
            continue;
        }
        $statement = $conn->prepare("SELECT 1 FROM {$table} WHERE id = :id LIMIT 1");
        $statement->execute([':id' => $data[$field]]);
        if (!$statement->fetchColumn()) {
            apiError('Una referencia seleccionada no existe.', 422, [
                $field => ['El valor no corresponde a un registro existente.']
            ]);
        }
    }
}

function apiEntity($conn, $resource, $id) {
    $statement = $conn->prepare("SELECT * FROM {$resource} WHERE id = :id LIMIT 1");
    $statement->execute([':id' => $id]);
    $entity = $statement->fetch();
    if (!$entity) {
        apiError('El recurso solicitado no existe.', 404);
    }
    return $entity;
}

function apiSaveEntity($conn, $resource, $id = null) {
    $data = apiValidate(apiJsonBody(), apiResourceSchema($resource));
    apiValidateReferences($conn, $resource, $data);
    if ($resource === 'clients' && !empty($data['rfc'])) {
        $data['rfc'] = mb_strtoupper($data['rfc'], 'UTF-8');
    }
    if ($resource === 'companies') {
        $data['rfc'] = mb_strtoupper($data['rfc'], 'UTF-8');
        $data['business_name'] = mb_strtoupper($data['business_name'], 'UTF-8');
        $data['fiscal_address'] = mb_strtoupper($data['fiscal_address'], 'UTF-8');
    }
    $columns = array_keys($data);
    if ($resource === 'clients') {
        $conn->beginTransaction();
        $audit = $conn->prepare('INSERT INTO current_audit_user (username) VALUES (:username)');
        $audit->execute([':username' => $_SESSION['username'] ?? 'Sistema']);
    }

    if ($id === null) {
        $names = implode(', ', $columns);
        $placeholders = implode(', ', array_map(function ($column) {
            return ':' . $column;
        }, $columns));
        $statement = $conn->prepare("INSERT INTO {$resource} ({$names}) VALUES ({$placeholders})");
        $statement->execute($data);
        $id = (int) $conn->lastInsertId();
        if ($resource === 'clients') {
            $conn->commit();
        }
        apiRespond(apiEntity($conn, $resource, $id), 201);
    }

    $assignments = implode(', ', array_map(function ($column) {
        return "{$column} = :{$column}";
    }, $columns));
    $data['id'] = $id;
    $statement = $conn->prepare("UPDATE {$resource} SET {$assignments} WHERE id = :id");
    $statement->execute($data);
    if ($resource === 'clients') {
        $conn->commit();
    }
    apiRespond(apiEntity($conn, $resource, $id));
}

function apiDeleteEntity($conn, $resource, $id) {
    if ($resource === 'clients' || $resource === 'companies') {
        $type = $resource === 'clients' ? 'person' : 'company';
        $check = $conn->prepare(
            'SELECT 1 FROM sales WHERE client_id = :id AND client_type = :type LIMIT 1'
        );
        $check->execute([':id' => $id, ':type' => $type]);
        if ($check->fetchColumn()) {
            apiError('No se puede eliminar un cliente con ventas asociadas.', 409);
        }
    } elseif ($resource === 'products') {
        $check = $conn->prepare('SELECT 1 FROM sale_details WHERE product_id = :id LIMIT 1');
        $check->execute([':id' => $id]);
        if ($check->fetchColumn()) {
            apiError('No se puede eliminar un producto utilizado en ventas.', 409);
        }
    }
    $auditsClient = $resource === 'clients';
    if ($auditsClient) {
        $conn->beginTransaction();
        $audit = $conn->prepare('INSERT INTO current_audit_user (username) VALUES (:username)');
        $audit->execute([':username' => $_SESSION['username'] ?? 'Sistema']);
    }
    $statement = $conn->prepare("DELETE FROM {$resource} WHERE id = :id");
    $statement->execute([':id' => $id]);
    if ($statement->rowCount() === 0) {
        if ($auditsClient) {
            $conn->rollBack();
        }
        apiError('El recurso solicitado no existe.', 404);
    }
    if ($auditsClient) {
        $conn->commit();
    }
    http_response_code(204);
    exit;
}

function apiSale($conn, $id) {
    $sale = apiEntity($conn, 'sales', $id);
    $details = $conn->prepare(
        'SELECT id, product_id, quantity, unit_price, subtotal, tax_rate
         FROM sale_details WHERE sale_id = :sale_id ORDER BY id'
    );
    $details->execute([':sale_id' => $id]);
    $sale['details'] = $details->fetchAll();
    return $sale;
}

function apiCreateSale($conn) {
    $input = apiJsonBody();
    if (array_diff(array_keys($input), ['client_id', 'client_type', 'sale_date', 'tax_percentage', 'items'])) {
        apiError('La solicitud contiene campos no permitidos.', 422);
    }
    $sale = apiValidate([
        'client_id' => $input['client_id'] ?? null,
        'client_type' => $input['client_type'] ?? null,
        'sale_date' => $input['sale_date'] ?? null,
        'tax_percentage' => array_key_exists('tax_percentage', $input)
            ? $input['tax_percentage']
            : 16
    ], [
        'client_id' => ['type' => 'integer', 'required' => true, 'min' => 1],
        'client_type' => ['type' => 'string', 'required' => true, 'enum' => ['person', 'company']],
        'sale_date' => ['type' => 'string', 'required' => true, 'max' => 10],
        'tax_percentage' => ['type' => 'number', 'required' => true, 'min' => 0, 'max' => 100]
    ]);
    $date = DateTime::createFromFormat('!Y-m-d', $sale['sale_date']);
    if (!$date || $date->format('Y-m-d') !== $sale['sale_date']) {
        apiError('La fecha de venta no es válida.', 422);
    }

    $items = $input['items'] ?? null;
    if (!is_array($items) || count($items) < 1 || count($items) > 100) {
        apiError('La venta debe incluir entre 1 y 100 productos.', 422);
    }
    $clientTable = $sale['client_type'] === 'person' ? 'clients' : 'companies';
    $client = $conn->prepare("SELECT 1 FROM {$clientTable} WHERE id = :id LIMIT 1");
    $client->execute([':id' => $sale['client_id']]);
    if (!$client->fetchColumn()) {
        apiError('El cliente seleccionado no existe.', 422);
    }

    $itemSchema = [
        'product_id' => ['type' => 'integer', 'required' => true, 'min' => 1],
        'quantity' => ['type' => 'integer', 'required' => true, 'min' => 1],
        'tax_rate' => ['type' => 'number', 'min' => 0, 'max' => 100]
    ];
    $productQuery = $conn->prepare('SELECT id, price FROM products WHERE id = :id LIMIT 1');
    $normalizedItems = [];
    $subtotal = 0.0;
    $taxAmount = 0.0;
    $maxAmount = 99999999.99;
    foreach ($items as $item) {
        if (!is_array($item)) {
            apiError('Hay un producto inválido en la venta.', 422);
        }
        if (!array_key_exists('tax_rate', $item)) {
            $item['tax_rate'] = $sale['tax_percentage'];
        }
        $validated = apiValidate($item, $itemSchema);
        if ($validated['quantity'] > 100000) {
            apiError('La cantidad supera el límite permitido.', 422);
        }
        $productQuery->execute([':id' => $validated['product_id']]);
        $product = $productQuery->fetch();
        if (!$product) {
            apiError('Uno de los productos no existe.', 422);
        }
        $price = (float) $product['price'];
        $lineSubtotal = round($price * $validated['quantity'], 2);
        $rate = $validated['tax_rate'];
        $lineTax = round($lineSubtotal * ($rate / 100), 2);
        $nextSubtotal = round($subtotal + $lineSubtotal, 2);
        $nextTaxAmount = round($taxAmount + $lineTax, 2);
        if ($nextSubtotal > $maxAmount || $nextTaxAmount > $maxAmount ||
            round($nextSubtotal + $nextTaxAmount, 2) > $maxAmount) {
            apiError('El total de la venta supera el máximo permitido.', 422);
        }
        $subtotal = $nextSubtotal;
        $taxAmount = $nextTaxAmount;
        $normalizedItems[] = [
            'product_id' => $validated['product_id'],
            'quantity' => $validated['quantity'],
            'unit_price' => $price,
            'subtotal' => $lineSubtotal,
            'tax_rate' => $rate
        ];
    }

    $subtotal = round($subtotal, 2);
    $taxAmount = round($taxAmount, 2);
    $taxPercentage = $subtotal > 0 ? round(($taxAmount / $subtotal) * 100, 2) : 0;
    $conn->beginTransaction();
    $audit = $conn->prepare('INSERT INTO current_audit_user (username) VALUES (:username)');
    $audit->execute([':username' => $_SESSION['username'] ?? 'Sistema']);
    $insert = $conn->prepare(
        'INSERT INTO sales
            (client_id, client_type, sale_date, subtotal, tax_percentage, tax_amount, total)
         VALUES (:client_id, :client_type, :sale_date, :subtotal, :tax_percentage, :tax_amount, :total)'
    );
    $insert->execute([
        ':client_id' => $sale['client_id'],
        ':client_type' => $sale['client_type'],
        ':sale_date' => $sale['sale_date'],
        ':subtotal' => $subtotal,
        ':tax_percentage' => $taxPercentage,
        ':tax_amount' => $taxAmount,
        ':total' => round($subtotal + $taxAmount, 2)
    ]);
    $id = (int) $conn->lastInsertId();
    $insertDetail = $conn->prepare(
        'INSERT INTO sale_details
            (sale_id, product_id, quantity, unit_price, subtotal, tax_rate)
         VALUES (:sale_id, :product_id, :quantity, :unit_price, :subtotal, :tax_rate)'
    );
    foreach ($normalizedItems as $item) {
        $insertDetail->execute([
            ':sale_id' => $id,
            ':product_id' => $item['product_id'],
            ':quantity' => $item['quantity'],
            ':unit_price' => $item['unit_price'],
            ':subtotal' => $item['subtotal'],
            ':tax_rate' => $item['tax_rate']
        ]);
    }
    $conn->commit();
    apiRespond(apiSale($conn, $id), 201);
}

function apiRoute($conn, $method, $segments) {
    if ($segments === ['auth', 'csrf'] && $method === 'GET') {
        apiRespond(['csrf_token' => csrfToken()]);
    }
    if ($segments === ['auth', 'login'] && $method === 'POST') {
        apiRequireCsrf();
        $validated = apiValidate(apiJsonBody(), [
            'username' => ['type' => 'string', 'required' => true, 'max' => 50],
            'password' => ['type' => 'string', 'required' => true, 'maxBytes' => 72, 'trim' => false]
        ]);
        $rateLimitKey = loginRateLimitKey();
        if (isLoginRateLimited($conn, $rateLimitKey)) {
            apiError('Demasiados intentos. Espera 15 minutos antes de volver a intentarlo.', 429);
        }
        $statement = $conn->prepare(
            'SELECT id, username, first_name, last_name, email, created_at, password
             FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute([':username' => $validated['username']]);
        $user = $statement->fetch();
        if (!$user || !password_verify($validated['password'], $user['password'])) {
            recordFailedLogin($conn, $rateLimitKey);
            apiError('Credenciales incorrectas.', 401);
        }
        clearLoginAttempts($conn, $rateLimitKey);
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_activity'] = time();
        unset($user['password']);
        apiRespond(['user' => $user, 'csrf_token' => csrfToken()]);
    }
    if ($segments === ['auth', 'logout'] && $method === 'POST') {
        apiRequireAuth();
        apiRequireCsrf();
        destroySession();
        apiRespond(null, 204);
    }

    $catalogs = [
        'business-types' => ['business_types', 'id, name'],
        'tax-regimes' => ['tax_regimes', 'id, name']
    ];
    if (count($segments) === 2 && $segments[0] === 'catalogs' &&
        isset($catalogs[$segments[1]]) && $method === 'GET') {
        list($table, $columns) = $catalogs[$segments[1]];
        $statement = $conn->query("SELECT {$columns} FROM {$table} ORDER BY name");
        apiRespond($statement->fetchAll());
    }

    $resources = ['clients', 'companies', 'products'];
    if (count($segments) >= 1 && in_array($segments[0], $resources, true)) {
        apiRequireAuth();
        $resource = $segments[0];
        $id = null;
        if (isset($segments[1])) {
            $id = filter_var($segments[1], FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || count($segments) !== 2) {
                apiError('La ruta solicitada no existe.', 404);
            }
        }
        if ($method === 'GET' && $id === null) {
            return apiList($conn, $resource, '*');
        }
        if ($method === 'GET') {
            return apiRespond(apiEntity($conn, $resource, $id));
        }
        if ($method === 'POST' && $id === null) {
            apiRequireCsrf();
            return apiSaveEntity($conn, $resource);
        }
        if ($method === 'PUT' && $id !== null) {
            apiRequireCsrf();
            apiEntity($conn, $resource, $id);
            return apiSaveEntity($conn, $resource, $id);
        }
        if ($method === 'DELETE' && $id !== null) {
            apiRequireCsrf();
            apiDeleteEntity($conn, $resource, $id);
        }
        apiError('Método no permitido.', 405);
    }

    if ($segments === ['sales']) {
        apiRequireAuth();
        if ($method === 'GET') {
            return apiList($conn, 'sales', '*');
        }
        if ($method === 'POST') {
            apiRequireCsrf();
            return apiCreateSale($conn);
        }
        apiError('Método no permitido.', 405);
    }
    if (count($segments) === 2 && $segments[0] === 'sales') {
        apiRequireAuth();
        $id = filter_var($segments[1], FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            apiError('La ruta solicitada no existe.', 404);
        }
        if ($method === 'GET') {
            return apiRespond(apiSale($conn, $id));
        }
        if ($method === 'DELETE') {
            apiRequireCsrf();
            $check = $conn->prepare('SELECT 1 FROM invoices WHERE sale_id = :id LIMIT 1');
            $check->execute([':id' => $id]);
            if ($check->fetchColumn()) {
                apiError('No se puede eliminar una venta con factura asociada.', 409);
            }
            $conn->beginTransaction();
            $audit = $conn->prepare('INSERT INTO current_audit_user (username) VALUES (:username)');
            $audit->execute([':username' => $_SESSION['username'] ?? 'Sistema']);
            $conn->prepare('DELETE FROM sale_details WHERE sale_id = :id')->execute([':id' => $id]);
            $delete = $conn->prepare('DELETE FROM sales WHERE id = :id');
            $delete->execute([':id' => $id]);
            if ($delete->rowCount() === 0) {
                $conn->rollBack();
                apiError('La venta solicitada no existe.', 404);
            }
            $conn->commit();
            http_response_code(204);
            exit;
        }
        apiError('Método no permitido.', 405);
    }

    if ($segments === ['invoices'] ||
        (count($segments) === 2 && $segments[0] === 'invoices')) {
        apiRequireAuth();
        if (count($segments) === 1 && $method === 'GET') {
            return apiList($conn, 'invoices', '*');
        }
        $id = filter_var($segments[1] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            apiError('La ruta solicitada no existe.', 404);
        }
        if ($method === 'GET') {
            $invoice = apiEntity($conn, 'invoices', $id);
            $invoice['sale'] = apiSale($conn, (int) $invoice['sale_id']);
            return apiRespond($invoice);
        }
    }

    apiError('La ruta solicitada no existe.', 404);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestPath = $_SERVER['PATH_INFO'] ?? '';
if ($requestPath === '') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $requestPath = preg_replace('#^.*/api/v1/?#', '', $requestPath);
}
$segments = array_values(array_filter(explode('/', trim($requestPath, '/')), 'strlen'));

$conn = null;
try {
    $db = new Database();
    $conn = $db->connect();
    apiRoute($conn, $method, $segments);
} catch (PDOException $e) {
    if ($conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('API database error: ' . $e->getMessage());
    if ($e->getCode() === '23000') {
        apiError('El recurso entra en conflicto con datos ya registrados.', 409);
    }
    apiError('Error interno del servidor.', 500);
} catch (Throwable $e) {
    if ($conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('API request error: ' . $e->getMessage());
    apiError('Error interno del servidor.', 500);
}
