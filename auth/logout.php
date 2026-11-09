<?php
require_once '../config/setup.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    destroySession();

    redirect('/swift_invoice/auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Método no permitido.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cerrar sesión - Swift Invoice</title>
</head>
<body>
    <main>
        <h1>¿Deseas cerrar sesión?</h1>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
            <button type="submit">Cerrar sesión</button>
            <a href="/swift_invoice/">Cancelar</a>
        </form>
    </main>
</body>
</html>
