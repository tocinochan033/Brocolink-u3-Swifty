<?php
require_once __DIR__ . '/../../config/setup.php';
requireAuth();
requireCsrf();

$upload_result = ['success' => false];
$upload_dir = __DIR__ . '/../../assets/images/logos/';

if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['logo'];
    if (!is_uploaded_file($file['tmp_name']) || $file['size'] < 1 || $file['size'] > 2097152) {
        $upload_result['error'] = 'El archivo no es válido o supera el máximo de 2 MB.';
        return;
    }

    $image = getimagesize($file['tmp_name']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif'
    ];
    if ($image === false || !isset($extensions[$mime]) ||
        $image[0] > 5000 || $image[1] > 5000 || $image[0] * $image[1] > 20000000) {
        $upload_result['error'] = 'Solo se permiten imágenes JPEG, PNG o GIF válidas.';
        return;
    }

    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0750, true) && !is_dir($upload_dir)) {
        throw new RuntimeException('No se pudo preparar el directorio de imágenes.');
    }

    $file_name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $upload_dir . $file_name;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $upload_result['error'] = 'No se pudo guardar la imagen.';
        return;
    }

    $upload_result['success'] = true;
    $upload_result['path'] = 'assets/images/logos/' . $file_name;

    if (!empty($company_exists['id'])) {
        $stmt = $conn->prepare('SELECT logo_path FROM companies WHERE id = :id');
        $stmt->execute([':id' => (int) $company_exists['id']]);
        $oldLogo = $stmt->fetchColumn();
        $logoRoot = realpath($upload_dir);
        $oldLogoPath = is_string($oldLogo) ? realpath(__DIR__ . '/../../' . $oldLogo) : false;
        if ($logoRoot && $oldLogoPath &&
            strpos($oldLogoPath, $logoRoot . DIRECTORY_SEPARATOR) === 0 &&
            is_file($oldLogoPath)) {
            unlink($oldLogoPath);
        }
    }
} elseif (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload_result['error'] = 'No se pudo recibir el archivo.';
}
