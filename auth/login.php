<?php
require_once '../config/setup.php';

$db = new Database();
$conn = $db->connect();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $username = trim(postedString('username'));
    $password = postedString('password');

    if ($username === '' || $password === '' || strlen($username) > 50 || strlen($password) > 72) {
        $error = 'Por favor ingrese usuario y contraseña';
    } else {
        $rateLimitKey = loginRateLimitKey();
        if (isLoginRateLimited($conn, $rateLimitKey)) {
            http_response_code(429);
            $error = 'Demasiados intentos. Espera 15 minutos antes de volver a intentarlo.';
        } else {
            $stmt = $conn->prepare("SELECT id, username, first_name, password FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                clearLoginAttempts($conn, $rateLimitKey);
                session_regenerate_id(true);
                unset($_SESSION['csrf_token']);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_activity'] = time();
                csrfToken();
                
                redirect('/swift_invoice/index.php');
            }

            recordFailedLogin($conn, $rateLimitKey);
            $error = 'Credenciales incorrectas';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Swift Invoice - Login</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
    <div class="auth-container">
        <h1>SWIFT INVOICE</h1>
        <h2>INICIO DE SESIÓN</h2>
        
        <?php if ($error): ?>
            <div class="alert error"><?php echo e($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
            <div class="form-group">
                <label for="username">Usuario:</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn">Ingresar</button>
        </form>
        
        <div class="auth-links">
            <?php if (registrationEnabled()): ?>
                <a href="register.php">Registrarse</a>
            <?php endif; ?>
            <a href="reset_password.php">¿Olvidaste tu contraseña?</a>
        </div>
    </div>
</body>
</html>