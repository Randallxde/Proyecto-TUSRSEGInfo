<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$usuario = usuario_actual();

// Control de seguridad: Si la sesión está activa pero el usuario no existe en la BD
if ($usuario === null) {
    session_destroy();
    if (session_status() === PHP_SESSION_NONE) {
        session_start(); 
    }
    set_flash('danger', 'Error al cargar los datos de tu cuenta. Por favor, inicia sesión nuevamente.');
    redirect('login.php');
}

$errores = [];

// Tasa de cambio estática de respaldo (1 USD = 4000 COP) por si el API JS falla en el cliente
define('TRM_PREDETERMINADA', 4000.00);

// ======================================================================
// CONSULTA: OBTENER LOS SITIOS AÑADIDOS POR ESTE USUARIO (INCLUYE COORDENADAS)
// ======================================================================
$sitios_propios = [];
try {
    $pdo = db();
    $stmtPropios = $pdo->prepare('
        SELECT p.*, c.city_name, c.department_name, m.storage_url,
               ST_X(p.geo_point) AS longitude, ST_Y(p.geo_point) AS latitude
        FROM places p
        LEFT JOIN cities c ON p.city_id = c.id
        LEFT JOIN media_assets m ON p.cover_media_id = m.id
        WHERE p.creator_user_id = :user_id 
        ORDER BY p.created_at DESC
    ');
    $stmtPropios->execute(['user_id' => $usuario['id']]);
    $sitios_propios = $stmtPropios->fetchAll();
} catch (Throwable $e) {
    $sitios_propios = [];
}

// ======================================================================
// PROCESAMIENTO DE FORMULARIOS (POST)
// ======================================================================
if (is_post() && isset($_POST['action'])) {
    verify_csrf();

    // ------------------------------------------------------------------
    // APARTADO 1: ACTUALIZAR DATOS DE PERFIL Y FOTO
    // ------------------------------------------------------------------
    if ($_POST['action'] === 'update_profile') {
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));

        if (mb_strlen($displayName) < 3) $errores[] = 'El nombre visible debe tener al menos 3 caracteres.';
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) $errores[] = 'El usuario solo puede tener letras, números y guion bajo.';

        if (!$errores) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_profiles WHERE username = :username AND user_id != :id');
                $stmt->execute(['username' => $username, 'id' => $usuario['id']]);
                if ((int)$stmt->fetchColumn() > 0) $errores[] = 'Ese nombre de usuario ya está en uso.';
            } catch (Throwable $e) {
                $errores[] = 'No se pudo validar el nombre de usuario.';
            }
        }

        $nuevoAvatarPath = null;
        if (!$errores && isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['avatar']['tmp_name'];
            $fileName = $_FILES['avatar']['name'];
            $fileSize = $_FILES['avatar']['size'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            
            if (in_array($ext, $extensionesPermitidas, true)) {
                if ($fileSize <= 5 * 1024 * 1024) {
                    $subcarpeta = 'public/img/';
                    $directorioCompleto = __DIR__ . '/' . $subcarpeta;
                    if (!is_dir($directorioCompleto)) mkdir($directorioCompleto, 0755, true);
                    
                    $nuevoNombreArchivo = md5(time() . $fileName) . '.' . $ext;
                    $rutaDestinoFinal = $directorioCompleto . $nuevoNombreArchivo;
                    
                    if (move_uploaded_file($fileTmpPath, $rutaDestinoFinal)) {
                        $nuevoAvatarPath = $subcarpeta . $nuevoNombreArchivo;
                    } else {
                        $errores[] = 'Error al mover el archivo de avatar.';
                    }
                } else { $errores[] = 'El avatar supera el límite de 5MB.'; }
            } else { $errores[] = 'Formato de imagen no válido para avatar.'; }
        }

        if (!$errores) {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                if ($nuevoAvatarPath !== null) {
                    $stmt = $pdo->prepare('UPDATE user_profiles SET display_name = :display_name, username = :username, avatar_media_id = :avatar, updated_at = NOW() WHERE user_id = :id');
                    $stmt->execute(['display_name' => $displayName, 'username' => $username, 'avatar' => $nuevoAvatarPath, 'id' => $usuario['id']]);
                } else {
                    $stmt = $pdo->prepare('UPDATE user_profiles SET display_name = :display_name, username = :username, updated_at = NOW() WHERE user_id = :id');
                    $stmt->execute(['display_name' => $displayName, 'username' => $username, 'id' => $usuario['id']]);
                }
                $pdo->commit();
                $_SESSION['user']['display_name'] = $displayName;
                $_SESSION['user']['username'] = $username;
                if ($nuevoAvatarPath !== null) $_SESSION['user']['avatar_media_id'] = $nuevoAvatarPath;
                set_flash('success', 'Tus datos de perfil han sido actualizados correctamente.');
                redirect('dashboard.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errores[] = 'Error al guardar el perfil: ' . $e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------------
    // APARTADO 2: ACTUALIZAR CUENTA (CORREO Y CONTRASEÑA)
    // ------------------------------------------------------------------
    if ($_POST['action'] === 'update_password') {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if (!validar_email($email)) {
            $errores[] = 'El correo electrónico no es válido.';
        } else {
            try {
                $pdo = db();
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND id != :id');
                $stmt->execute(['email' => $email, 'id' => $usuario['id']]);
                if ((int)$stmt->fetchColumn() > 0) $errores[] = 'El correo ya está registrado.';
            } catch (Throwable $e) { $errores[] = 'Error al validar el correo.'; }
        }

        $cambiarPassword = !empty($password);
        if ($cambiarPassword) {
            if (mb_strlen($password) < 6) { $errores[] = 'La contraseña debe tener al menos 6 caracteres.'; }
            elseif ($password !== $passwordConfirm) { $errores[] = 'Las contraseñas no coinciden.'; }
        }

        if (!$errores) {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
                $stmt->execute(['email' => $email, 'id' => $usuario['id']]);
                if ($cambiarPassword) {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = :password WHERE id = :id');
                    $stmt->execute(['password' => $passwordHash, 'id' => $usuario['id']]);
                }
                $pdo->commit();
                $_SESSION['user']['email'] = $email;
                set_flash('success', 'Los datos de acceso han sido actualizados.');
                redirect('dashboard.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errores[] = 'Error en la base de datos: ' . $e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------------
    // APARTADO 3: AÑADIR PUNTO DE INTERÉS (TABLA PLACES)
    // ------------------------------------------------------------------
    if ($_POST['action'] === 'add_place') {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $city_id = !empty($_POST['city_id']) ? (int)$_POST['city_id'] : null;
        $address_text = trim((string)($_POST['address_text'] ?? ''));
        $entry_cost = !empty($_POST['entry_cost']) ? (float)$_POST['entry_cost'] : 0.00;
        $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        if (mb_strlen($name) < 3) $errores[] = 'El nombre debe tener al menos 3 caracteres.';
        if (empty($city_id)) $errores[] = 'Debes seleccionar una ciudad válida.';
        if ($latitude === null || $longitude === null) $errores[] = 'Debes marcar una ubicación en el mapa.';

        $cover_media_id = null;
        if (!$errores && isset($_FILES['place_image']) && $_FILES['place_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['place_image']['tmp_name'];
            $fileName = $_FILES['place_image']['name'];
            $fileSize = $_FILES['place_image']['size'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) && $fileSize <= 5 * 1024 * 1024) {
                $subcarpeta = 'public/img/';
                $directorioCompleto = __DIR__ . '/' . $subcarpeta;
                if (!is_dir($directorioCompleto)) mkdir($directorioCompleto, 0755, true);
                
                $nuevoNombreArchivo = 'place_' . md5(time() . $fileName) . '.' . $ext;
                $rutaDestinoFinal = $directorioCompleto . $nuevoNombreArchivo;
                
                if (move_uploaded_file($fileTmpPath, $rutaDestinoFinal)) {
                    $storage_url = $subcarpeta . $nuevoNombreArchivo;
                    $info_img = @getimagesize($rutaDestinoFinal);
                    $width = $info_img ? $info_img[0] : null;
                    $height = $info_img ? $info_img[1] : null;
                    $mime_type = $info_img ? $info_img['mime'] : 'image/' . $ext;
                    $checksum = hash_file('sha256', $rutaDestinoFinal);

                    try {
                        $pdo = db();
                        $pdo->beginTransaction();
                        $stmtAsset = $pdo->prepare('INSERT INTO media_assets (owner_user_id, storage_key, storage_url, mime_type, size_bytes, width_px, height_px, checksum, usage_scope, status, created_at, updated_at) VALUES (:owner_user_id, :storage_key, :storage_url, :mime_type, :size_bytes, :width_px, :height_px, :checksum, "place", "active", NOW(), NOW())');
                        $stmtAsset->execute(['owner_user_id' => $usuario['id'], 'storage_key' => $nuevoNombreArchivo, 'storage_url' => $storage_url, 'mime_type' => $mime_type, 'size_bytes' => $fileSize, 'width_px' => $width, 'height_px' => $height, 'checksum' => $checksum]);
                        $cover_media_id = $pdo->lastInsertId();
                    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errores[] = 'Error multimedia: ' . $e->getMessage(); }
                }
            } else { $errores[] = 'Imagen de sitio no válida o muy pesada.'; }
        }

        if (!$errores) {
            try {
                $pdo = db();
                if (!$pdo->inTransaction()) $pdo->beginTransaction();
                $wktPoint = "POINT($longitude $latitude)";
                $stmt = $pdo->prepare('INSERT INTO places (creator_user_id, city_id, name, description, geo_point, address_text, cover_media_id, entry_cost, currency_code, moderation_status, status, created_at) VALUES (:creator_user_id, :city_id, :name, :description, ST_GeomFromText(:geo_point), :address_text, :cover_media_id, :entry_cost, "COP", "pending", "active", NOW())');
                $stmt->execute(['creator_user_id' => $usuario['id'], 'city_id' => $city_id, 'name' => $name, 'description' => $description, 'geo_point' => $wktPoint, 'address_text' => $address_text, 'cover_media_id' => $cover_media_id, 'entry_cost' => $entry_cost]);
                $pdo->commit();
                set_flash('success', 'El punto de interés se ha registrado y está en espera de moderación.');
                redirect('dashboard.php');
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errores[] = 'Error al registrar: ' . $e->getMessage(); }
        }
    }

    // ------------------------------------------------------------------
    // APARTADO 4: ACTUALIZAR UN PUNTO DE INTERÉS EXISTENTE
    // ------------------------------------------------------------------
    if ($_POST['action'] === 'edit_place') {
        $place_id = !empty($_POST['place_id']) ? (int)$_POST['place_id'] : null;
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $city_id = !empty($_POST['city_id']) ? (int)$_POST['city_id'] : null;
        $address_text = trim((string)($_POST['address_text'] ?? ''));
        $entry_cost = !empty($_POST['entry_cost']) ? (float)$_POST['entry_cost'] : 0.00;
        $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        if (empty($place_id)) $errores[] = 'Identificador de sitio no válido.';
        if (mb_strlen($name) < 3) $errores[] = 'El nombre debe tener al menos 3 caracteres.';
        if (empty($city_id)) $errores[] = 'Debes seleccionar una ciudad.';
        if ($latitude === null || $longitude === null) $errores[] = 'Debes marcar una ubicación en el mapa.';

        if (!$errores) {
            try {
                $pdo = db();
                $stmtCheck = $pdo->prepare('SELECT cover_media_id FROM places WHERE id = :id AND creator_user_id = :user_id');
                $stmtCheck->execute(['id' => $place_id, 'user_id' => $usuario['id']]);
                $sitioExistente = $stmtCheck->fetch();
                if (!$sitioExistente) { $errores[] = 'No tienes permisos para modificar este punto.'; }
            } catch (Throwable $e) { $errores[] = 'Error de validación de propiedad.'; }
        }

        $cover_media_id = !empty($sitioExistente) ? $sitioExistente['cover_media_id'] : null;
        if (!$errores && isset($_FILES['place_image']) && $_FILES['place_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['place_image']['tmp_name'];
            $fileName = $_FILES['place_image']['name'];
            $fileSize = $_FILES['place_image']['size'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) && $fileSize <= 5 * 1024 * 1024) {
                $subcarpeta = 'public/img/';
                $directorioCompleto = __DIR__ . '/' . $subcarpeta;
                if (!is_dir($directorioCompleto)) mkdir($directorioCompleto, 0755, true);
                
                $nuevoNombreArchivo = 'place_update_' . md5(time() . $fileName) . '.' . $ext;
                $rutaDestinoFinal = $directorioCompleto . $nuevoNombreArchivo;
                
                if (move_uploaded_file($fileTmpPath, $rutaDestinoFinal)) {
                    $storage_url = $subcarpeta . $nuevoNombreArchivo;
                    $info_img = @getimagesize($rutaDestinoFinal);
                    $width = $info_img ? $info_img[0] : null;
                    $height = $info_img ? $info_img[1] : null;
                    $mime_type = $info_img ? $info_img['mime'] : 'image/' . $ext;
                    $checksum = hash_file('sha256', $rutaDestinoFinal);

                    try {
                        $pdo = db();
                        $pdo->beginTransaction();
                        $stmtAsset = $pdo->prepare('INSERT INTO media_assets (owner_user_id, storage_key, storage_url, mime_type, size_bytes, width_px, height_px, checksum, usage_scope, status, created_at, updated_at) VALUES (:owner_user_id, :storage_key, :storage_url, :mime_type, :size_bytes, :width_px, :height_px, :checksum, "place", "active", NOW(), NOW())');
                        $stmtAsset->execute(['owner_user_id' => $usuario['id'], 'storage_key' => $nuevoNombreArchivo, 'storage_url' => $storage_url, 'mime_type' => $mime_type, 'size_bytes' => $fileSize, 'width_px' => $width, 'height_px' => $height, 'checksum' => $checksum]);
                        $cover_media_id = $pdo->lastInsertId();
                    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errores[] = 'Error multimedia: ' . $e->getMessage(); }
                }
            }
        }

        if (!$errores) {
            try {
                $pdo = db();
                if (!$pdo->inTransaction()) $pdo->beginTransaction();
                $wktPoint = "POINT($longitude $latitude)";
                
                $stmt = $pdo->prepare('UPDATE places SET city_id = :city_id, name = :name, description = :description, geo_point = ST_GeomFromText(:geo_point), address_text = :address_text, cover_media_id = :cover_media_id, entry_cost = :entry_cost, moderation_status = "pending", updated_at = NOW() WHERE id = :id AND creator_user_id = :user_id');
                $stmt->execute(['city_id' => $city_id, 'name' => $name, 'description' => $description, 'geo_point' => $wktPoint, 'address_text' => $address_text, 'cover_media_id' => $cover_media_id, 'entry_cost' => $entry_cost, 'id' => $place_id, 'user_id' => $usuario['id']]);
                
                $pdo->commit();
                set_flash('success', 'El sitio ha sido actualizado correctamente y está en revisión para figurar en los listados generales.');
                redirect('dashboard.php');
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errores[] = 'Error al actualizar: ' . $e->getMessage(); }
        }
    }
}

try {
    $pdo = db();
    $stmtCiudades = $pdo->query('SELECT id, city_name, department_name FROM cities ORDER BY city_name ASC');
    $ciudades = $stmtCiudades->fetchAll();
} catch (Throwable $e) { $ciudades = []; }

$conteos = conteo_dashboard();
$lugares = lugares_destacados(4);
$pageTitle = APP_NOMBRE . ' | Dashboard';

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<style>
    .preview-container { max-width: 100%; max-height: 280px; overflow: hidden; margin-top: 15px; display: none; background-color: #f7f7f7; border-radius: 8px; padding: 5px; }
    .preview-container img { max-width: 100%; display: block; }
    .cropper-view-box, .cropper-face { border-radius: 50%; }
    .avatar-wrapper { display: flex; align-items: center; gap: 15px; }
    .avatar-circular-preview { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #ddd; background-color: #eaeaea; }
    .nav-tabs .nav-link { color: #6c757d; font-weight: 500; }
    .nav-tabs .nav-link.active { color: #ff6b00 !important; font-weight: 600; border-color: #dee2e6 #dee2e6 #fff; }
    .btn-naranja-solido { background-color: #ff6b00 !important; border-color: #ff6b00 !important; color: #ffffff !important; font-weight: 600; transition: all 0.3s ease-in-out; }
    .btn-naranja-solido:hover { background-color: #e55a00 !important; border-color: #e55a00 !important; box-shadow: 0 4px 12px rgba(255, 107, 0, 0.35); transform: translateY(-1px); }
    .btn-naranja-solido:active { transform: translateY(0); }
    
    /* Estilos base consistentes con las tarjetas de index para Sitios Recomendados */
    .place-card-img-wrap { position: relative; height: 180px; overflow: hidden; border-radius: 8px 8px 0 0; }
    .place-card-img-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
    .place-card-img-wrap:hover img { transform: scale(1.05); }
    .badge-cost-tag { position: absolute; top: 12px; right: 12px; background-color: rgba(0, 0, 0, 0.7); color: #fff; padding: 0.35rem 0.65rem; font-size: 0.75rem; font-weight: 600; border-radius: 4px; backdrop-filter: blur(2px); }
    .text-orange { color: #ff6b00 !important; }
</style>

<section class="py-5">
    <div class="container">
        
        <?php if (!empty($errores)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errores as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="panel-card shadow-sm h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php 
                                $avatarBase = !empty($usuario['avatar_media_id']) ? trim($usuario['avatar_media_id']) : '';
                                $rutaImagen = 'public/img/image.png';
                                if (!empty($avatarBase)) {
                                    $ext = strtolower(pathinfo($avatarBase, PATHINFO_EXTENSION));
                                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) { $rutaImagen = $avatarBase; }
                                    else {
                                        $nombreCortado = basename($avatarBase);
                                        foreach ([__DIR__ . '/public/uploads/', __DIR__ . '/public/img/'] as $dir) {
                                            if (is_dir($dir)) {
                                                $archivosEncontrados = glob($dir . $nombreCortado . '*');
                                                if (!empty($archivosEncontrados)) {
                                                    $rutaImagen = (strpos($dir, 'uploads') !== false) ? 'public/uploads/' . basename($archivosEncontrados[0]) : 'public/img/' . basename($archivosEncontrados[0]);
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                }
                            ?>
                            <img src="<?= url($rutaImagen) ?>" id="avatar-dashboard-view" class="rounded-circle" width="50" height="50" style="object-fit: cover;" alt="Avatar">
                            <div>
                                <h1 class="h4 mb-1"><?= e($usuario['display_name'] ?? $usuario['email']) ?></h1>
                                <div class="text-muted small">@<?= e($usuario['username'] ?? 'usuario') ?></div>
                            </div>
                        </div>
                        
                        <ul class="list-unstyled mb-4 text-muted small pb-3 border-bottom">
                            <li class="mb-2"><i class="fa-regular fa-envelope me-2 text-secondary"></i><strong>Correo:</strong> <?= e($usuario['email']) ?></li>
                            <li class="mb-2"><i class="fa-regular fa-eye me-2 text-secondary"></i><strong>Perfil:</strong> <?= e($usuario['profile_visibility'] ?? 'public') ?></li>
                            <li class="mb-2"><i class="fa-solid fa-city me-2 text-secondary"></i><strong>Ciudad:</strong> <?= e($usuario['city_name'] ?? 'Sin definir') ?></li>
                            <li class="mb-2"><i class="fa-solid fa-location-dot me-2 text-secondary"></i><strong>Ubicación:</strong> <?= e($usuario['location_visibility'] ?? 'friends') ?></li>
                        </ul>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="h6 fw-bold mb-0 text-dark"><i class="fa-solid fa-map-location-dot me-2" style="color: #ff6b00;"></i>Mis Sitios Añadidos</h5>
                                <span class="badge bg-light text-dark border" style="font-size: 0.7rem;"><?= count($sitios_propios) ?></span>
                            </div>
                            
                            <?php if (empty($sitios_propios)): ?>
                                <div class="text-center py-4 border border-dashed rounded bg-light">
                                    <i class="fa-solid fa-folder-open text-muted mb-2 d-block fs-4"></i>
                                    <p class="text-muted small mb-0 italic">Aún no has propuesto puntos de interés.</p>
                                </div>
                            <?php else: ?>
                                <div class="pe-1" style="max-height: 260px; overflow-y: auto; scrollbar-width: thin;">
                                    <?php foreach ($sitios_propios as $propio): 
                                        $statusClass = 'warning'; $statusIcon = 'fa-clock'; $statusText = 'Pendiente';
                                        if ($propio['moderation_status'] === 'approved') { $statusClass = 'success'; $statusIcon = 'fa-circle-check'; $statusText = 'Aprobado'; }
                                        elseif ($propio['moderation_status'] === 'rejected') { $statusClass = 'danger'; $statusIcon = 'fa-circle-xmark'; $statusText = 'Rechazado'; }
                                    ?>
                                        <div class="card border-0 shadow-sm mb-2 bg-light bg-gradient" style="border-left: 3px solid var(--bs-<?= $statusClass ?>) !important;">
                                            <div class="card-body p-2 d-flex align-items-center justify-content-between gap-2">
                                                <div class="text-truncate" style="max-width: 60%;">
                                                    <div class="fw-bold text-dark text-truncate small"><?= e($propio['name']) ?></div>
                                                    <div class="text-muted text-truncate d-flex align-items-center gap-1" style="font-size: 0.68rem;">
                                                        <i class="fa-solid fa-location-crosshairs opacity-75"></i><?= e(($propio['city_name'] ?? '') . ', ' . ($propio['department_name'] ?? '')) ?>
                                                    </div>
                                                </div>
                                                <div class="text-end d-flex flex-column align-items-end gap-1 shrink-0">
                                                    <span class="badge bg-<?= $statusClass ?>-subtle text-<?= $statusClass ?> border border-<?= $statusClass ?>-subtle rounded-pill" style="font-size: 0.62rem; padding: 0.2rem 0.4rem;">
                                                        <i class="fa-solid <?= $statusIcon ?>"></i> <?= $statusText ?>
                                                    </span>
                                                    <button type="button" class="btn btn-outline-secondary btn-xs py-0 px-1.5" style="font-size: 0.65rem;" data-bs-toggle="modal" data-bs-target="#modalEditarLugar" 
                                                            data-id="<?= (int)$propio['id'] ?>" 
                                                            data-name="<?= e($propio['name']) ?>" 
                                                            data-city="<?= (int)$propio['city_id'] ?>" 
                                                            data-address="<?= e($propio['address_text']) ?>" 
                                                            data-cost="<?= (float)$propio['entry_cost'] ?>" 
                                                            data-desc="<?= e($propio['description']) ?>" 
                                                            data-lat="<?= $propio['latitude'] ?>" 
                                                            data-lng="<?= $propio['longitude'] ?>">
                                                        <i class="fa-solid fa-pen-to-square me-0.5"></i>Editar
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pt-3 border-top">
                        <button type="button" class="btn btn-naranja-solido btn-sm w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modalActualizarDatos"><i class="fa-solid fa-user-pen me-2"></i>Gestionar Configuración</button>
                        <button type="button" class="btn btn-naranja-solido btn-sm w-100" data-bs-toggle="modal" data-bs-target="#modalAnadirLugar"><i class="fa-solid fa-map-location-dot me-2"></i>Añadir Punto de Interés</button>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-8">
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-num"><?= (int)$conteos['usuarios'] ?></div><div class="stat-label">Usuarios</div></div></div>
                    <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-num"><?= (int)$conteos['sitios'] ?></div><div class="stat-label">Sitios</div></div></div>
                    <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-num"><?= (int)$conteos['comentarios'] ?></div><div class="stat-label">Comentarios</div></div></div>
                    <div class="col-md-3 col-6"><div class="stat-card"><div class="stat-num"><?= (int)$conteos['mensajes'] ?></div><div class="stat-label">Mensajes</div></div></div>
                </div>
                
                <div class="panel-card shadow-sm">
                    <h2 class="h5 mb-4 fw-bold text-dark"><i class="fa-solid fa-star text-orange me-2"></i>Sitios recomendados</h2>
                    <div class="row g-3">
                        <?php if (empty($lugares)): ?>
                            <div class="col-12"><p class="text-muted small mb-0">No hay sitios recomendados.</p></div>
                        <?php else: ?>
                            <?php foreach ($lugares as $sitio): 
                                // Determinar la imagen de portada o asignar una de respaldo
                                $imagenSitio = !empty($sitio['storage_url']) ? url($sitio['storage_url']) : url('public/img/place_default.png');
                            ?>
                                <div class="col-md-6">
                                    <div class="card h-100 border-0 shadow-sm overflow-hidden bg-white">
                                        <div class="place-card-img-wrap">
                                            <img src="<?= $imagenSitio ?>" alt="<?= e($sitio['name']) ?>" loading="lazy">
                                            <div class="badge-cost-tag">
                                                <?= $sitio['entry_cost'] > 0 ? '$' . number_format((float)$sitio['entry_cost'], 0, ',', '.') : 'Gratis' ?>
                                            </div>
                                        </div>
                                        <div class="card-body d-flex flex-column justify-content-between p-3">
                                            <div>
                                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                                    <h3 class="h6 fw-bold mb-0 text-dark text-truncate" style="max-width: 80%;"><?= e($sitio['name']) ?></h3>
                                                    <span class="small fw-semibold shrink-0"><i class="fa-solid fa-star text-orange me-1"></i><?= number_format((float)$sitio['average_rating'], 1) ?></span>
                                                </div>
                                                <p class="text-muted small mb-0"><i class="fa-solid fa-location-dot opacity-75 me-1"></i><?= e(($sitio['city_name'] ?? '') . ' · ' . ($sitio['department_name'] ?? '')) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- [Los modales y scripts inferiores permanecen idénticos para preservar la funcionalidad] -->
<div class="modal fade" id="modalActualizarDatos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light pb-0 border-bottom-0">
                <div class="w-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="modal-title fw-bold">Configuración de Cuenta</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                        <li class="nav-item"><button class="nav-link active" id="datos-tab" data-bs-toggle="tab" data-bs-target="#datos-panel" type="button" role="tab"><i class="fa-solid fa-user me-1"></i> Datos</button></li>
                        <li class="nav-item"><button class="nav-link" id="cuenta-tab" data-bs-toggle="tab" data-bs-target="#cuenta-panel" type="button" role="tab"><i class="fa-solid fa-shield-halved me-1"></i> Cuenta</button></li>
                    </ul>
                </div>
            </div>
            
            <div class="tab-content" id="profileTabsContent">
                <div class="tab-pane fade show active" id="datos-panel" role="tabpanel">
                    <form id="form-actualizar-datos" action="" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="modal-body p-4">
                            <div class="mb-3 pb-3 border-bottom">
                                <label class="form-label fw-semibold text-muted small d-block">Foto de perfil</label>
                                <div class="avatar-wrapper">
                                    <img id="avatar-final-preview" class="avatar-circular-preview" src="<?= url($rutaImagen) ?>" alt="Avatar">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('input-avatar').click();">Cambiar Foto</button>
                                    <input type="file" id="input-avatar" name="avatar" class="d-none" accept="image/*">
                                </div>
                                <div class="preview-container" id="preview-wrapper"><img id="image-preview" src=""></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">Nombre visible</label>
                                <input type="text" name="display_name" class="form-control" value="<?= e($usuario['display_name'] ?? '') ?>" required minlength="3">
                            </div>
                            <div class="mb-0">
                                <label class="form-label fw-semibold text-muted small">Nombre de Usuario (@)</label>
                                <input type="text" name="username" class="form-control" value="<?= e($usuario['username'] ?? '') ?>" required minlength="3" pattern="[a-zA-Z0-9_]{3,50}">
                            </div>
                        </div>
                        <div class="modal-footer bg-light"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-naranja-solido btn-sm">Guardar</button></div>
                    </form>
                </div>

                <div class="tab-pane fade" id="cuenta-panel" role="tabpanel">
                    <form id="form-actualizar-cuenta" action="" method="post" class="needs-validation" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_password">
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">Correo electrónico</label>
                                <input type="email" name="email" class="form-control" value="<?= e($usuario['email'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">Nueva Contraseña</label>
                                <input type="password" name="password" id="password" class="form-control" minlength="6">
                            </div>
                            <div class="mb-0">
                                <label class="form-label fw-semibold text-muted small">Confirmar Contraseña</label>
                                <input type="password" name="password_confirm" id="password_confirm" class="form-control">
                                <div class="invalid-feedback" id="confirm-feedback">Las contraseñas no coinciden.</div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-naranja-solido btn-sm">Actualizar</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAnadirLugar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-map-location-dot me-2 text-orange"></i>Proponer Nuevo Punto de Interés</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="form-anadir-lugar" action="" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_place">
                <input type="hidden" name="latitude" id="geo_lat">
                <input type="hidden" name="longitude" id="geo_lng">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-semibold text-muted small">Nombre del sitio *</label><input type="text" name="name" class="form-control" required minlength="3" maxlength="150"></div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted small">Ciudad / Municipio *</label>
                            <select name="city_id" class="form-select" required>
                                <option value="" selected disabled>Selecciona la ubicación...</option>
                                <?php foreach ($ciudades as $cd): ?><option value="<?= (int)$cd['id'] ?>"><?= e($cd['city_name']) ?> (<?= e($cd['department_name']) ?>)</option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted small mb-1"><i class="fa-solid fa-location-crosshairs text-orange me-1"></i> Ubicación en el mapa (Haz clic para marcar) *</label>
                            <div id="mapa-registro" style="width: 100%; height: 260px; border-radius: 6px; border: 1px solid #ced4da;"></div>
                            <div class="text-muted small mt-1" id="coords-display">Coordenadas: No seleccionadas.</div>
                        </div>
                        <div class="col-12"><label class="form-label fw-semibold text-muted small">Indicaciones para llegar</label><input type="text" name="address_text" class="form-control" maxlength="255"></div>
                        <div class="col-12"><label class="form-label fw-semibold text-muted small">Imagen representativa del lugar</label><input type="file" name="place_image" class="form-control" accept="image/*"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold text-muted small">Costo de Entrada (COP)</label><input type="number" name="entry_cost" id="entry_cost" class="form-control" placeholder="0" min="0"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold text-muted small">Divisa</label><input type="text" class="form-control" value="COP" readonly disabled></div>
                        <div class="col-12"><div class="alert alert-secondary py-2 small mb-0"><i class="fa-solid fa-calculator me-1"></i>Equivalencia aproximada: <strong id="usd_calc">0.00</strong> USD</div></div>
                        <div class="col-12"><label class="form-label fw-semibold text-muted small">Descripción del lugar</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer bg-light"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-naranja-solido btn-sm">Enviar Propuesta</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarLugar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-orange"></i>Actualizar Sitio Propuesto</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="form-editar-lugar" action="" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_place">
                <input type="hidden" name="place_id" id="edit_place_id">
                <input type="hidden" name="latitude" id="edit_geo_lat">
                <input type="hidden" name="longitude" id="edit_geo_lng">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-semibold text-muted small">Nombre del sitio *</label><input type="text" name="name" id="edit_name" class="form-control" required minlength="3" maxlength="150"></div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted small">Ciudad / Municipio *</label>
                            <select name="city_id" id="edit_city_id" class="form-select" required>
                                <?php foreach ($ciudades as $cd): ?><option value="<?= (int)$cd['id'] ?>"><?= e($cd['city_name']) ?> (<?= e($cd['department_name']) ?>)</option><?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted small mb-1"><i class="fa-solid fa-location-crosshairs text-orange me-1"></i> Ubicación en el mapa (Haz clic para marcar) *</label>
                            <div id="mapa-edicion" style="width: 100%; height: 260px; border-radius: 6px; border: 1px solid #ced4da;"></div>
                            <div class="text-muted small mt-1" id="edit_coords-display">Coordenadas: No seleccionadas.</div>
                        </div>

                        <div class="col-12"><label class="form-label fw-semibold text-muted small">Indicaciones para llegar</label><input type="text" name="address_text" id="edit_address_text" class="form-control" maxlength="255"></div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted small">Nueva imagen del sitio</label>
                            <input type="file" name="place_image" class="form-control" accept="image/*">
                            <div class="text-muted small mt-1">Deja este campo vacío si no deseas cambiar la foto actual del sitio.</div>
                        </div>
                        <div class="col-md-6"><label class="form-label fw-semibold text-muted small">Costo de Entrada (COP)</label><input type="number" name="entry_cost" id="edit_entry_cost" class="form-control" min="0"></div>
                        <div class="col-md-6"><label class="form-label fw-semibold text-muted small">Divisa</label><input type="text" class="form-control" value="COP" readonly disabled></div>
                        <div class="col-12"><div class="alert alert-secondary py-2 small mb-0"><i class="fa-solid fa-calculator me-1"></i>Equivalencia aproximada: <strong id="edit_usd_calc">0.00</strong> USD</div></div>
                        <div class="col-12"><label class="form-label fw-semibold text-muted small">Descripción del lugar</label><textarea name="description" id="edit_description" class="form-control" rows="3"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer bg-light"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-naranja-solido btn-sm">Actualizar Sitio</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let trmActual = <?= TRM_PREDETERMINADA ?>;

    // ==========================================
    // TRM EN VIVO COMPARTIDA
    // ==========================================
    async function cargarTRM() {
        try {
            const res = await fetch('https://open.er-api.com/v6/latest/USD', { cache: 'no-store' });
            const data = await res.json();
            if (data && data.result === 'success' && data.rates && data.rates.COP) { trmActual = Number(data.rates.COP) || trmActual; }
        } catch (e) {} finally { calcularEquivalenciaRegistro(); calcularEquivalenciaEdicion(); }
    }
    function calcularEquivalenciaRegistro() {
        const val = parseFloat(document.getElementById('entry_cost').value) || 0;
        document.getElementById('usd_calc').textContent = val > 0 ? (val / trmActual).toFixed(2) : '0.00';
    }
    function calcularEquivalenciaEdicion() {
        const val = parseFloat(document.getElementById('edit_entry_cost').value) || 0;
        document.getElementById('edit_usd_calc').textContent = val > 0 ? (val / trmActual).toFixed(2) : '0.00';
    }
    document.getElementById('entry_cost').addEventListener('input', calcularEquivalenciaRegistro);
    document.getElementById('edit_entry_cost').addEventListener('input', calcularEquivalenciaEdicion);
    cargarTRM();

    // ==========================================
    // FOTO PERFIL & CROPPER
    // ==========================================
    const inputAvatar = document.getElementById('input-avatar');
    const imagePreview = document.getElementById('image-preview');
    const previewWrapper = document.getElementById('preview-wrapper');
    const avatarFinalPreview = document.getElementById('avatar-final-preview');
    const formDatos = document.getElementById('form-actualizar-datos');
    let cropperInstancia = null;

    if (inputAvatar) {
        inputAvatar.addEventListener('change', function (e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function (ev) {
                    imagePreview.src = ev.target.result;
                    if (avatarFinalPreview) avatarFinalPreview.src = ev.target.result;
                    previewWrapper.style.display = 'block';
                    if (cropperInstancia) cropperInstancia.destroy();
                    cropperInstancia = new Cropper(imagePreview, { aspectRatio: 1, viewMode: 1, autoCropArea: 1 });
                };
                reader.readAsDataURL(e.target.files[0]);
            }
        });
    }
    if (formDatos) {
        formDatos.addEventListener('submit', function (e) {
            if (cropperInstancia) {
                e.preventDefault();
                cropperInstancia.getCroppedCanvas({ width: 300, height: 300 }).toBlob(function (blob) {
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(new File([blob], "avatar.png", { type: "image/png" }));
                    inputAvatar.files = dataTransfer.files;
                    cropperInstancia.destroy(); cropperInstancia = null;
                    HTMLFormElement.prototype.submit.call(formDatos);
                }, 'image/png');
            }
        });
    }

    // ==========================================
    // MAPA 1: NUEVO LUGAR (REGISTRO)
    // ==========================================
    let mapaInstancia = null; let marcadorMapa = null;
    document.getElementById('modalAnadirLugar').addEventListener('shown.bs.modal', function () {
        if (!mapaInstancia) {
            mapaInstancia = L.map('mapa-registro').setView([4.6097, -74.0817], 6);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(mapaInstancia);
            mapaInstancia.on('click', function (e) {
                document.getElementById('geo_lat').value = e.latlng.lat;
                document.getElementById('geo_lng').value = e.latlng.lng;
                document.getElementById('coords-display').innerHTML = `Lat: ${e.latlng.lat.toFixed(5)}, Lng: ${e.latlng.lng.toFixed(5)}`;
                if (marcadorMapa) marcadorMapa.setLatLng(e.latlng); else marcadorMapa = L.marker(e.latlng).addTo(mapaInstancia);
            });
        } else { mapaInstancia.invalidateSize(); }
    });

    // ==========================================
    // MAPA 2 & SELECT: EDICIÓN DE LUGAR SINCRO
    // ==========================================
    let mapaEdicion = null; let marcadorEdicion = null;
    const modalEditarLugar = document.getElementById('modalEditarLugar');

    modalEditarLugar.addEventListener('shown.bs.modal', function (event) {
        const btn = event.relatedTarget;
        if (!btn) return;
        
        // Cargar variables de texto en el formulario
        document.getElementById('edit_place_id').value = btn.getAttribute('data-id');
        document.getElementById('edit_name').value = btn.getAttribute('data-name');
        document.getElementById('edit_address_text').value = btn.getAttribute('data-address');
        document.getElementById('edit_entry_cost').value = btn.getAttribute('data-cost');
        document.getElementById('edit_description').value = btn.getAttribute('data-desc');
        
        // CORRECCIÓN CLAVE: Seleccionar automáticamente la ciudad correspondiente en el <select>
        const ciudadIdGuardada = btn.getAttribute('data-city');
        if (ciudadIdGuardada) {
            document.getElementById('edit_city_id').value = ciudadIdGuardada;
        }
        
        const lat = parseFloat(btn.getAttribute('data-lat')) || 4.6097;
        const lng = parseFloat(btn.getAttribute('data-lng')) || -74.0817;

        document.getElementById('edit_geo_lat').value = lat;
        document.getElementById('edit_geo_lng').value = lng;
        document.getElementById('edit_coords-display').innerHTML = `Lat: ${lat.toFixed(5)}, Lng: ${lng.toFixed(5)}`;
        calcularEquivalenciaEdicion();

        // Inicializar o redibujar el mapa Leaflet en la vista visible
        if (!mapaEdicion) {
            mapaEdicion = L.map('mapa-edicion').setView([lat, lng], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(mapaEdicion);
            
            marcadorEdicion = L.marker([lat, lng]).addTo(mapaEdicion);
            
            mapaEdicion.on('click', function (e) {
                marcadorEdicion.setLatLng(e.latlng);
                document.getElementById('edit_geo_lat').value = e.latlng.lat;
                document.getElementById('edit_geo_lng').value = e.latlng.lng;
                document.getElementById('edit_coords-display').innerHTML = `Lat: ${e.latlng.lat.toFixed(5)}, Lng: ${e.latlng.lng.toFixed(5)}`;
            });
        } else {
            mapaEdicion.invalidateSize();
            mapaEdicion.setView([lat, lng], 13);
            marcadorEdicion.setLatLng([lat, lng]);
        }
    });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>