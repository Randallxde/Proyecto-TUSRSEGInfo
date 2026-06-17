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

        // Validaciones básicas de longitud y formato
        if (mb_strlen($displayName) < 3) $errores[] = 'El nombre visible debe tener al menos 3 caracteres.';
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) $errores[] = 'El usuario solo puede tener letras, números y guion bajo.';

        if (!$errores) {
            try {
                $pdo = db();
                // Verificar que el username no esté tomado por OTRO perfil
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_profiles WHERE username = :username AND user_id != :id');
                $stmt->execute(['username' => $username, 'id' => $usuario['id']]);
                if ((int)$stmt->fetchColumn() > 0) $errores[] = 'Ese nombre de usuario ya está en uso.';
            } catch (Throwable $e) {
                $errores[] = 'No se pudo validar el nombre de usuario.';
            }
        }

        // Procesamiento de la imagen con Cropper
        $nuevoAvatarPath = null;
        if (!$errores && isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['avatar']['tmp_name'];
            $fileName = $_FILES['avatar']['name'];
            $fileSize = $_FILES['avatar']['size'];
            
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            
            if (in_array($ext, $extensionesPermitidas, true)) {
                if ($fileSize <= 5 * 1024 * 1024) { // Límite de 5MB para soportar el canvas procesado
                    
                    $subcarpeta = 'public/img/';
                    $directorioCompleto = __DIR__ . '/' . $subcarpeta;
                    
                    if (!is_dir($directorioCompleto)) {
                        mkdir($directorioCompleto, 0755, true);
                    }
                    
                    $nuevoNombreArchivo = md5(time() . $fileName) . '.' . $ext;
                    $rutaDestinoFinal = $directorioCompleto . $nuevoNombreArchivo;
                    
                    if (move_uploaded_file($fileTmpPath, $rutaDestinoFinal)) {
                        $nuevoAvatarPath = $subcarpeta . $nuevoNombreArchivo;
                    } else {
                        $errores[] = 'Error al mover el archivo al directorio "public/img/". Verifica los permisos de escritura.';
                    }
                } else {
                    $errores[] = 'La nueva imagen de perfil supera el límite de 5MB.';
                }
            } else {
                $errores[] = 'Formato de imagen no válido. Solo se admiten: ' . implode(', ', $extensionesPermitidas);
            }
        }

        // Guardar cambios si no hay errores
        if (!$errores) {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                if ($nuevoAvatarPath !== null) {
                    $stmt = $pdo->prepare('UPDATE user_profiles SET display_name = :display_name, username = :username, avatar_media_id = :avatar, updated_at = NOW() WHERE user_id = :id');
                    $stmt->execute([
                        'display_name' => $displayName,
                        'username' => $username,
                        'avatar' => $nuevoAvatarPath,
                        'id' => $usuario['id']
                    ]);
                } else {
                    $stmt = $pdo->prepare('UPDATE user_profiles SET display_name = :display_name, username = :username, updated_at = NOW() WHERE user_id = :id');
                    $stmt->execute([
                        'display_name' => $displayName,
                        'username' => $username,
                        'id' => $usuario['id']
                    ]);
                }

                $pdo->commit();

                // Actualizar la sesión en tiempo real
                $_SESSION['user']['display_name'] = $displayName;
                $_SESSION['user']['username'] = $username;
                if ($nuevoAvatarPath !== null) {
                    $_SESSION['user']['avatar_media_id'] = $nuevoAvatarPath;
                }

                set_flash('success', 'Tus datos de perfil han sido actualizados correctamente.');
                redirect('dashboard.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errores[] = 'Ocurrió un error al intentar guardar los cambios: ' . $e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------------
    // APARTADO 2: ACTUALIZAR CUENTA (CORREO Y CONTRASEÑA EN TU BD)
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
                if ((int)$stmt->fetchColumn() > 0) $errores[] = 'Ese correo electrónico ya está registrado por otro usuario.';
            } catch (Throwable $e) {
                $errores[] = 'No se pudo validar el correo electrónico.';
            }
        }

        $cambiarPassword = !empty($password);
        if ($cambiarPassword) {
            if (mb_strlen($password) < 6) {
                $errores[] = 'La nueva contraseña debe tener al menos 6 caracteres.';
            } elseif ($password !== $passwordConfirm) {
                $errores[] = 'Las contraseñas ingresadas no coinciden.';
            }
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

                set_flash('success', 'Los datos de acceso a tu cuenta han sido actualizados con éxito.');
                redirect('dashboard.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errores[] = 'Error crítico en la base de datos: ' . $e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------------
    // APARTADO 3: AÑADIR PUNTO DE INTERÉS (TABLA PLACES) CON IMAGEN
    // ------------------------------------------------------------------
    if ($_POST['action'] === 'add_place') {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $city_id = !empty($_POST['city_id']) ? (int)$_POST['city_id'] : null;
        $address_text = trim((string)($_POST['address_text'] ?? ''));
        $entry_cost = !empty($_POST['entry_cost']) ? (float)$_POST['entry_cost'] : 0.00;
        $currency_code = 'COP'; 
        $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        if (mb_strlen($name) < 3) $errores[] = 'El nombre del punto de interés debe tener al menos 3 caracteres.';
        if (empty($city_id)) $errores[] = 'Debes seleccionar una ciudad válida.';
        if ($latitude === null || $longitude === null) $errores[] = 'Debes seleccionar una ubicación en el mapa interactivo.';

        // Procesamiento y guardado de la imagen del sitio en la tabla media_assets
        $cover_media_id = null;
        if (!$errores && isset($_FILES['place_image']) && $_FILES['place_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['place_image']['tmp_name'];
            $fileName = $_FILES['place_image']['name'];
            $fileSize = $_FILES['place_image']['size'];
            
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            
            if (in_array($ext, $extensionesPermitidas, true)) {
                if ($fileSize <= 5 * 1024 * 1024) {
                    $subcarpeta = 'public/img/';
                    $directorioCompleto = __DIR__ . '/' . $subcarpeta;
                    
                    if (!is_dir($directorioCompleto)) {
                        mkdir($directorioCompleto, 0755, true);
                    }
                    
                    $nuevoNombreArchivo = 'place_' . md5(time() . $fileName) . '.' . $ext;
                    $rutaDestinoFinal = $directorioCompleto . $nuevoNombreArchivo;
                    
                    if (move_uploaded_file($fileTmpPath, $rutaDestinoFinal)) {
                        $storage_url = $subcarpeta . $nuevoNombreArchivo;
                        
                        // Extraer propiedades metadata de la imagen
                        $width = null;
                        $height = null;
                        $mime_type = 'image/' . $ext;
                        $info_img = @getimagesize($rutaDestinoFinal);
                        if ($info_img) {
                            $width = $info_img[0];
                            $height = $info_img[1];
                            $mime_type = $info_img['mime'];
                        }
                        $checksum = hash_file('sha256', $rutaDestinoFinal);

                        try {
                            $pdo = db();
                            $pdo->beginTransaction();

                            $stmtAsset = $pdo->prepare('INSERT INTO media_assets (owner_user_id, storage_key, storage_url, mime_type, size_bytes, width_px, height_px, checksum, usage_scope, status, created_at, updated_at) VALUES (:owner_user_id, :storage_key, :storage_url, :mime_type, :size_bytes, :width_px, :height_px, :checksum, "place", "active", NOW(), NOW())');
                            $stmtAsset->execute([
                                'owner_user_id' => $usuario['id'],
                                'storage_key'   => $nuevoNombreArchivo,
                                'storage_url'   => $storage_url,
                                'mime_type'     => $mime_type,
                                'size_bytes'    => $fileSize,
                                'width_px'      => $width,
                                'height_px'     => $height,
                                'checksum'      => $checksum
                            ]);
                            
                            $cover_media_id = $pdo->lastInsertId();
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $errores[] = 'Error al registrar el recurso multimedia: ' . $e->getMessage();
                        }
                    } else {
                        $errores[] = 'No se pudo mover el archivo de imagen al directorio del servidor.';
                    }
                } else {
                    $errores[] = 'La imagen del lugar supera el límite de tamaño permitido (5MB).';
                }
            } else {
                $errores[] = 'Formato de imagen para el sitio no válido. Formatos permitidos: ' . implode(', ', $extensionesPermitidas);
            }
        }

        if (!$errores) {
            try {
                $pdo = db();
                // Si la transacción no fue abierta en la sección multimedia anterior, la iniciamos aquí
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                }

                $wktPoint = "POINT($longitude $latitude)";
                
                $stmt = $pdo->prepare('INSERT INTO places (creator_user_id, city_id, name, description, geo_point, address_text, cover_media_id, entry_cost, currency_code, moderation_status, status, created_at) VALUES (:creator_user_id, :city_id, :name, :description, ST_GeomFromText(:geo_point), :address_text, :cover_media_id, :entry_cost, :currency_code, "pending", "active", NOW())');
                
                $stmt->execute([
                    'creator_user_id' => $usuario['id'],
                    'city_id'         => $city_id,
                    'name'            => $name,
                    'description'     => $description,
                    'geo_point'       => $wktPoint,
                    'address_text'    => $address_text,
                    'cover_media_id'  => $cover_media_id,
                    'entry_cost'      => $entry_cost,
                    'currency_code'   => $currency_code
                ]);

                $pdo->commit();

                set_flash('success', 'El punto de interés se ha registrado y está en espera de moderación.');
                redirect('dashboard.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errores[] = 'Error al registrar el sitio: ' . $e->getMessage();
            }
        }
    }
}

// Cargar ciudades para el select del formulario
try {
    $pdo = db();
    $stmtCiudades = $pdo->query('SELECT id, city_name, department_name FROM cities ORDER BY city_name ASC');
    $ciudades = $stmtCiudades->fetchAll();
} catch (Throwable $e) {
    $ciudades = [];
}

$conteos = conteo_dashboard();
$lugares = lugares_destacados(4);
$pageTitle = APP_NOMBRE . ' | Dashboard';

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<style>
    .preview-container {
        max-width: 100%;
        max-height: 280px;
        overflow: hidden;
        margin-top: 15px;
        display: none;
        background-color: #f7f7f7;
        border-radius: 8px;
        padding: 5px;
    }
    .preview-container img {
        max-width: 100%;
        display: block;
    }
    .cropper-view-box, .cropper-face {
        border-radius: 50%;
    }
    .avatar-wrapper {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    .avatar-circular-preview {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #ddd;
        background-color: #eaeaea;
    }
    .nav-tabs .nav-link {
        color: #6c757d;
        font-weight: 500;
    }
    .nav-tabs .nav-link.active {
        color: #ff6b00 !important;
        font-weight: 600;
        border-color: #dee2e6 #dee2e6 #fff;
    }
    .btn-naranja-solido {
        background-color: #ff6b00 !important;
        border-color: #ff6b00 !important;
        color: #ffffff !important;
        font-weight: 600;
        transition: all 0.3s ease-in-out;
    }
    .btn-naranja-solido:hover {
        background-color: #e55a00 !important;
        border-color: #e55a00 !important;
        box-shadow: 0 4px 12px rgba(255, 107, 0, 0.35);
        transform: translateY(-1px);
    }
    .btn-naranja-solido:active {
        transform: translateY(0);
    }
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
                                    $extensionesValidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                                    if (in_array($ext, $extensionesValidas, true)) {
                                        $rutaImagen = $avatarBase;
                                    } else {
                                        $nombreCortado = basename($avatarBase);
                                        $directoriosBusqueda = [__DIR__ . '/public/uploads/', __DIR__ . '/public/img/'];
                                        foreach ($directoriosBusqueda as $dir) {
                                            if (is_dir($dir)) {
                                                $archivosEncontrados = glob($dir . $nombreCortado . '*');
                                                if (!empty($archivosEncontrados)) {
                                                    $rutaFisicaReal = $archivosEncontrados[0];
                                                    $nombreArchivoReal = basename($rutaFisicaReal);
                                                    $rutaImagen = (strpos($dir, 'uploads') !== false) ? 'public/uploads/' . $nombreArchivoReal : 'public/img/' . $nombreArchivoReal;
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
                        
                        <ul class="list-unstyled mb-0 text-muted small">
                            <li class="mb-2"><strong>Correo:</strong> <?= e($usuario['email']) ?></li>
                            <li class="mb-2"><strong>Perfil:</strong> <?= e($usuario['profile_visibility'] ?? 'public') ?></li>
                            <li class="mb-2"><strong>Ciudad:</strong> <?= e($usuario['city_name'] ?? 'Sin definir') ?></li>
                            <li class="mb-2"><strong>Ubicación:</strong> <?= e($usuario['location_visibility'] ?? 'friends') ?></li>
                        </ul>
                    </div>

                    <div class="pt-3 border-top">
                        <button type="button" class="btn btn-naranja-solido btn-sm w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modalActualizarDatos">
                            <i class="fa-solid fa-user-pen me-2"></i>Gestionar Configuración
                        </button>
                        <button type="button" class="btn btn-naranja-solido btn-sm w-100" data-bs-toggle="modal" data-bs-target="#modalAnadirLugar">
                            <i class="fa-solid fa-map-location-dot me-2"></i>Añadir Punto de Interés
                        </button>
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
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h2 class="h5 mb-0">Sitios recomendados</h2>
                    </div>
                    <div class="row g-3">
                        <?php if (empty($lugares)): ?>
                            <div class="col-12"><p class="text-muted small mb-0">No hay sitios recomendados.</p></div>
                        <?php else: ?>
                            <?php foreach ($lugares as $sitio): ?>
                                <div class="col-md-6">
                                    <div class="card border-0 bg-light h-100">
                                        <div class="card-body">
                                            <h3 class="h6 fw-bold mb-1"><?= e($sitio['name']) ?></h3>
                                            <p class="text-muted small mb-2"><?= e(($sitio['city_name'] ?? '') . ' · ' . ($sitio['department_name'] ?? '')) ?></p>
                                            <div class="d-flex justify-content-between small">
                                                <span><i class="fa-solid fa-star text-orange me-1"></i><?= number_format((float)$sitio['average_rating'], 1) ?></span>
                                                <span><?= $sitio['entry_cost'] !== null ? '$' . number_format((float)$sitio['entry_cost'], 0, ',', '.') : 'Gratis' ?></span>
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

<div class="modal fade" id="modalActualizarDatos" tabindex="-1" aria-labelledby="modalActualizarDatosLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light pb-0 border-bottom-0">
                <div class="w-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="modal-title fw-bold" id="modalActualizarDatosLabel">Configuración de Cuenta</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="datos-tab" data-bs-toggle="tab" data-bs-target="#datos-panel" type="button" role="tab" aria-controls="datos-panel" aria-selected="true">
                                <i class="fa-solid fa-user me-1"></i> Actualizar Datos
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="cuenta-tab" data-bs-toggle="tab" data-bs-target="#cuenta-panel" type="button" role="tab" aria-controls="cuenta-panel" aria-selected="false">
                                <i class="fa-solid fa-shield-halved me-1"></i> Actualizar Cuenta
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
            
            <div class="tab-content" id="profileTabsContent">
                <div class="tab-pane fade show active" id="datos-panel" role="tabpanel" aria-labelledby="datos-tab">
                    <form id="form-actualizar-datos" action="" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="modal-body p-4">
                            <div class="mb-3 pb-3 border-bottom">
                                <label class="form-label fw-semibold text-muted small d-block">Foto de perfil</label>
                                <div class="avatar-wrapper">
                                    <img id="avatar-final-preview" class="avatar-circular-preview" src="<?= url($rutaImagen) ?>" alt="Avatar">
                                    <div>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('input-avatar').click();">
                                            Cambiar Foto
                                        </button>
                                        <input type="file" id="input-avatar" name="avatar" class="d-none" accept="image/*">
                                    </div>
                                </div>
                                <div class="preview-container" id="preview-wrapper">
                                    <img id="image-preview" src="" alt="Vista previa">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">Nombre visible</label>
                                <input type="text" name="display_name" class="form-control" value="<?= e($usuario['display_name'] ?? '') ?>" required minlength="3">
                                <div class="invalid-feedback">Por favor introduce al menos 3 caracteres.</div>
                            </div>
                            
                            <div class="mb-0">
                                <label class="form-label fw-semibold text-muted small">Nombre de Usuario (@)</label>
                                <input type="text" name="username" class="form-control" value="<?= e($usuario['username'] ?? '') ?>" required minlength="3" pattern="[a-zA-Z0-9_]{3,50}">
                                <div class="invalid-feedback">Solo letras, números y guiones bajos (mín. 3).</div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-naranja-solido btn-sm px-4">Guardar Datos</button>
                        </div>
                    </form>
                </div>

                <div class="tab-pane fade" id="cuenta-panel" role="tabpanel" aria-labelledby="cuenta-tab">
                    <form id="form-actualizar-cuenta" action="" method="post" class="needs-validation" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_password">

                        <div class="modal-body p-4">
                            <div class="mb-3 pb-3 border-bottom">
                                <label class="form-label fw-semibold text-muted small">Correo electrónico</label>
                                <input type="email" name="email" class="form-control" value="<?= e($usuario['email'] ?? '') ?>" required>
                                <div class="invalid-feedback">Introduce un correo electrónico válido.</div>
                            </div>

                            <div class="alert alert-info py-2 small mb-3">
                                <i class="fa-solid fa-circle-info me-1"></i> Deja los campos de contraseña en blanco si sólo deseas cambiar tu correo electrónico.
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">Nueva Contraseña</label>
                                <input type="password" name="password" id="password" class="form-control" minlength="6">
                                <div class="invalid-feedback">La contraseña debe tener al menos 6 caracteres.</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label fw-semibold text-muted small">Confirmar Nueva Contraseña</label>
                                <input type="password" name="password_confirm" id="password_confirm" class="form-control">
                                <div class="invalid-feedback" id="confirm-feedback">Las contraseñas no coinciden.</div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-naranja-solido btn-sm px-4">Actualizar Cuenta</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAnadirLugar" tabindex="-1" aria-labelledby="modalAnadirLugarLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold" id="modalAnadirLugarLabel">
                    <i class="fa-solid fa-map-location-dot me-2 text-orange"></i>Proponer Nuevo Punto de Interés
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form id="form-anadir-lugar" action="" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_place">
                
                <input type="hidden" name="latitude" id="geo_lat">
                <input type="hidden" name="longitude" id="geo_lng">
                <input type="hidden" name="currency_code" value="COP">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted small">Nombre del sitio *</label>
                            <input type="text" name="name" class="form-control" placeholder="Ej. Monserrate" required minlength="3" maxlength="150">
                            <div class="invalid-feedback">Por favor escribe un nombre válido (mín. 3 caracteres).</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted small">Ciudad / Municipio *</label>
                            <select name="city_id" class="form-select" required>
                                <option value="" selected disabled>Selecciona la ubicación...</option>
                                <?php foreach ($ciudades as $cd): ?>
                                    <option value="<?= (int)$cd['id'] ?>"><?= e($cd['city_name']) ?> (<?= e($cd['department_name']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Selecciona una ciudad de la lista.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted small mb-1">
                                <i class="fa-solid fa-location-crosshairs text-orange me-1"></i> Ubicación en el mapa (Haz un clic para marcar) *
                            </label>
                            <div id="mapa-registro" style="width: 100%; height: 300px; border-radius: 6px; border: 1px solid #ced4da; background-color: #f5f5f5;"></div>
                            <div class="text-muted small mt-1" id="coords-display">Coordenadas: No seleccionadas.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted small">Indicaciones para llegar</label>
                            <input type="text" name="address_text" class="form-control" placeholder="Indicaciones para llegar" maxlength="255">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted small">Imagen representativa del lugar</label>
                            <input type="file" name="place_image" class="form-control" accept="image/*">
                            <div class="text-muted small mt-1">Sube una fotografía del sitio (Formatos: JPG, PNG, WEBP, GIF. Máx. 5MB).</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted small">Costo de Entrada (COP)</label>
                            <input type="number" name="entry_cost" id="entry_cost" class="form-control" placeholder="0" step="1" min="0" inputmode="numeric">
                            <div class="text-muted small mt-1">El valor se guarda en COP. Abajo verás el equivalente en USD.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted small">Divisa</label>
                            <input type="text" class="form-control" value="COP" readonly disabled>
                        </div>

                        <div class="col-12">
                            <div class="alert alert-secondary py-2 small mb-0">
                                <i class="fa-solid fa-calculator me-1"></i>
                                Equivalencia aproximada: <strong id="usd_calc">0.00</strong> USD
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted small">Descripción del lugar</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Describe qué atractivos tiene el lugar, horarios recomendados, seguridad, etc."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-naranja-solido btn-sm px-4">Enviar Propuesta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const inputAvatar = document.getElementById('input-avatar');
    const imagePreview = document.getElementById('image-preview');
    const previewWrapper = document.getElementById('preview-wrapper');
    const avatarFinalPreview = document.getElementById('avatar-final-preview');
    const formDatos = document.getElementById('form-actualizar-datos');
    
    const password = document.getElementById('password');
    const passwordConfirm = document.getElementById('password_confirm');
    const confirmFeedback = document.getElementById('confirm-feedback');
    const formCuenta = document.getElementById('form-actualizar-cuenta');

    const formLugar = document.getElementById('form-anadir-lugar');
    const inputLat = document.getElementById('geo_lat');
    const inputLng = document.getElementById('geo_lng');
    const coordsDisplay = document.getElementById('coords-display');
    const entryCostInput = document.getElementById('entry_cost');
    const usdCalcSpan = document.getElementById('usd_calc');

    let cropperInstancia = null;
    let trmActual = <?= TRM_PREDETERMINADA ?>;

    // ==========================================
    // LÓGICA DE CROPPER.JS
    // ==========================================
    if (inputAvatar) {
        inputAvatar.addEventListener('change', function (e) {
            const archivos = e.target.files;
            if (archivos && archivos.length > 0) {
                const file = archivos[0];
                const reader = new FileReader();
                
                reader.onload = function (event) {
                    imagePreview.src = event.target.result;
                    if (avatarFinalPreview) avatarFinalPreview.src = event.target.result;
                    previewWrapper.style.display = 'block';
                    
                    if (cropperInstancia) {
                        cropperInstancia.destroy();
                    }
                    
                    cropperInstancia = new Cropper(imagePreview, {
                        aspectRatio: 1,
                        viewMode: 1,
                        background: false,
                        autoCropArea: 1
                    });
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (formDatos) {
        formDatos.addEventListener('submit', function (e) {
            if (!this.checkValidity()) {
                e.preventDefault();
                return;
            }

            if (cropperInstancia) {
                e.preventDefault(); 
                
                cropperInstancia.getCroppedCanvas({
                    width: 300,
                    height: 300
                }).toBlob(function (blob) {
                    if (!blob) {
                        alert('Error procesando el recorte.');
                        return;
                    }
                    
                    const dataTransfer = new DataTransfer();
                    const archivoRecortado = new File([blob], "avatar_recortado.png", { type: "image/png" });
                    dataTransfer.items.add(archivoRecortado);
                    inputAvatar.files = dataTransfer.files; 
                    
                    cropperInstancia.destroy();
                    cropperInstancia = null;
                    
                    HTMLFormElement.prototype.submit.call(formDatos);
                }, 'image/png');
            }
        });
    }

    // ==========================================
    // VALIDACIÓN DE CONTRASEÑAS COINCIDENTES
    // ==========================================
    if (formCuenta && password && passwordConfirm && confirmFeedback) {
        formCuenta.addEventListener('submit', function (e) {
            if (password.value !== '' || passwordConfirm.value !== '') {
                if (password.value !== passwordConfirm.value) {
                    e.preventDefault();
                    e.stopPropagation();
                    confirmFeedback.style.display = 'block';
                    passwordConfirm.classList.add('is-invalid');
                } else {
                    confirmFeedback.style.display = 'none';
                    passwordConfirm.classList.remove('is-invalid');
                }
            }
        });
    }

    // ==========================================
    // LÓGICA DE CONVERSIÓN CON TRM EN VIVO
    // ==========================================
    async function cargarTRM() {
        try {
            const res = await fetch('https://open.er-api.com/v6/latest/USD', { cache: 'no-store' });
            const data = await res.json();

            if (data && data.result === 'success' && data.rates && data.rates.COP) {
                trmActual = Number(data.rates.COP) || trmActual;
            }
        } catch (error) {
            console.warn("No se pudo obtener la TRM en vivo, usando respaldo.");
        } finally {
            calcularEquivalencia();
        }
    }

    function parseCOP(value) {
        const cleaned = String(value || '').replace(/[^0-9.,]/g, '').replace(',', '.');
        const num = parseFloat(cleaned);
        return Number.isFinite(num) ? num : 0;
    }

    function calcularEquivalencia() {
        if (!entryCostInput || !usdCalcSpan) return;
        const cop = parseCOP(entryCostInput.value);
        usdCalcSpan.textContent = cop > 0 ? (cop / trmActual).toFixed(2) : '0.00';
    }

    if (entryCostInput) {
        entryCostInput.addEventListener('input', calcularEquivalencia);
        cargarTRM();
        setInterval(cargarTRM, 30 * 60 * 1000); // Sincroniza cada 30 minutos
    }

    // ==========================================
    // MAPA LEAFLET EN MODAL
    // ==========================================
    let mapaInstancia = null;
    let marcadorMapa = null;
    const modalAnadirLugarEl = document.getElementById('modalAnadirLugar');

    if (modalAnadirLugarEl) {
        modalAnadirLugarEl.addEventListener('shown.bs.modal', function () {
            if (!mapaInstancia) {
                // Centrado inicial en Colombia
                mapaInstancia = L.map('mapa-registro').setView([4.6097, -74.0817], 6);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(mapaInstancia);

                mapaInstancia.on('click', function (e) {
                    const lat = e.latlng.lat;
                    const lng = e.latlng.lng;

                    inputLat.value = lat;
                    inputLng.value = lng;
                    coordsDisplay.innerHTML = `<strong>Ubicación seleccionada:</strong> Lat: ${lat.toFixed(5)}, Lng: ${lng.toFixed(5)}`;

                    if (marcadorMapa) {
                        marcadorMapa.setLatLng(e.latlng);
                    } else {
                        marcadorMapa = L.marker(e.latlng).addTo(mapaInstancia);
                    }
                });
            } else {
                // Corrige el bug visual de renderizado asíncrono en Bootstrap Modals
                mapaInstancia.invalidateSize();
            }
        });
    }

    // Validación extra antes de enviar para asegurar que se usó el mapa
    if (formLugar) {
        formLugar.addEventListener('submit', function (e) {
            if (this.checkValidity() && (!inputLat.value || !inputLng.value)) {
                e.preventDefault();
                e.stopPropagation();
                alert('Por favor, selecciona un punto preciso en el mapa haciendo un clic sobre él.');
            }
        });
    }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>