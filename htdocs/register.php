<?php
// register.php
require_once __DIR__ . '/includes/functions.php';

// Si el usuario ya está logueado y está activo, lo mandamos al dashboard
if (usuario_autenticado() && isset($_SESSION['usuario']['status']) && $_SESSION['usuario']['status'] === 'active') {
    redirect(es_admin() ? 'admin.php' : 'dashboard.php');
}

$errores = [];
$campos = ['display_name' => '', 'username' => '', 'email' => '', 'date_of_birth' => ''];

if (is_post()) {
    verify_csrf();
    
    // Limpiar campos de texto
    foreach ($campos as $clave => $valor) {
        $campos[$clave] = trim((string)($_POST[$clave] ?? ''));
    }
    
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password_confirmation'] ?? '');
    
    // Validaciones básicas
    if (mb_strlen($campos['display_name']) < 3) $errores[] = 'El nombre visible debe tener al menos 3 caracteres.';
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $campos['username'])) $errores[] = 'El usuario solo puede tener letras, números y guion bajo.';
    if (!validar_email($campos['email'])) $errores[] = 'El correo electrónico no es válido.';
    if (strlen($password) < 6) $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    if ($password !== $password2) $errores[] = 'Las contraseñas no coinciden.';
    if (!edad_minima_ok($campos['date_of_birth'])) $errores[] = 'No cumples con la edad mínima requerida.';
    
    // ======================================================================
    // PROCESAMIENTO DE LA IMAGEN DE PERFIL
    // ======================================================================
    $avatarPath = 'public/img/default-avatar.png'; 

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['avatar']['tmp_name'];
        $fileName = $_FILES['avatar']['name'];
        $fileSize = $_FILES['avatar']['size'];
        
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        
        if (in_array($ext, $extensionesPermitidas)) {
            if ($fileSize <= 2 * 1024 * 1024) {
                
                $subcarpeta = 'public/img/';
                $directorioCompleto = __DIR__ . '/' . $subcarpeta;
                
                if (!is_dir($directorioCompleto)) {
                    mkdir($directorioCompleto, 0755, true);
                }
                
                $nuevoNombreArchivo = md5(time() . $fileName) . '.' . $ext;
                $rutaDestinoFinal = $directorioCompleto . $nuevoNombreArchivo;
                
                if (move_uploaded_file($fileTmpPath, $rutaDestinoFinal)) {
                    $avatarPath = $subcarpeta . $nuevoNombreArchivo;
                } else {
                    $errores[] = 'Error físico al guardar la imagen en el servidor.';
                }
                
            } else {
                $errores[] = 'La imagen es muy pesada. Máximo 2MB.';
            }
        } else {
            $errores[] = 'Formato no válido. Solo se admiten: ' . implode(', ', $extensionesPermitidas);
        }
    }

    // Si no hay errores de validación, procedemos a chequear duplicados
    if (!$errores) {
        $pdo = db();
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $stmt->execute(['email' => $campos['email']]);
        if ((int)$stmt->fetchColumn() > 0) $errores[] = 'Ese correo ya está registrado.';
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_profiles WHERE username = :username');
        $stmt->execute(['username' => $campos['username']]);
        if ((int)$stmt->fetchColumn() > 0) $errores[] = 'Ese nombre de usuario ya existe.';
    }
    
    if (!$errores) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            
            // 1. Insertar en la tabla 'users' (Modificado: Se guarda en 'pending' y sin fecha de verificación)
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, date_of_birth, email_verified_at, status, created_at, updated_at) VALUES (:email, :password_hash, :date_of_birth, NULL, 'pending', NOW(), NOW())");
            $stmt->execute([
                'email' => $campos['email'], 
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 
                'date_of_birth' => $campos['date_of_birth']
            ]);
            
            $userId = (int)$pdo->lastInsertId();
            
            // 2. Insertar en 'user_profiles'
            $stmt = $pdo->prepare("INSERT INTO user_profiles (user_id, username, display_name, bio, avatar_media_id, profile_visibility, location_visibility, show_age, city_id, created_at, updated_at) VALUES (:user_id, :username, :display_name, NULL, :avatar_media_id, 'public', 'friends', 0, NULL, NOW(), NOW())");
            $stmt->execute([
                'user_id' => $userId, 
                'username' => $campos['username'], 
                'display_name' => $campos['display_name'],
                'avatar_media_id' => $avatarPath
            ]);
            
            // ======================================================================
            // GENERACIÓN Y ENVÍO DEL CÓDIGO DE VERIFICACIÓN VÍA SMTP
            // ======================================================================
            $codigo_verificacion = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // Guardamos el código en la sesión para que reconfirm.php pueda validarlo
            $_SESSION['codigo_verificacion_temporal'] = $codigo_verificacion;

            // Creamos el diseño del correo electrónico
            $asuntoCorreo = "Verifica tu cuenta en TurSegInfo";
            $cuerpoCorreo = "
                <div style='font-family: Arial, sans-serif; max-width: 550px; margin: 0 auto; padding: 25px; border: 1px solid #e9ecef; border-radius: 12px;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #fd7e14; margin: 0; font-size: 24px;'>¡Hola, " . e($campos['display_name']) . "!</h2>
                    </div>
                    <p style='color: #495057; font-size: 16px; line-height: 1.5;'>Gracias por registrarte en <strong>TurSegInfo</strong>. Para poder activar tu cuenta, por favor ingresa el siguiente código de confirmación en la plataforma:</p>
                    
                    <div style='background-color: #f8f9fa; border: 2px dashed #fd7e14; padding: 15px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #212529; border-radius: 8px; margin: 25px 0;'>
                        {$codigo_verificacion}
                    </div>
                    
                    <p style='font-size: 13px; color: #6c757d; text-align: center; margin-top: 25px;'>Este código de seguridad es de un solo uso. Si tú no has solicitado esta cuenta, puedes ignorar este mensaje.</p>
                </div>
            ";

            // Se envía el correo usando la función de tu functions.php
            enviar_correo_smtp($campos['email'], $asuntoCorreo, $cuerpoCorreo);

            // Guardamos los datos mínimos en la sesión (status pending)
            login_user([
                'id' => $userId, 
                'email' => $campos['email'],
                'display_name' => $campos['display_name'],
                'username' => $campos['username'],
                'status' => 'pending'
            ]);

            $pdo->commit();
            
            set_flash('info', 'Hemos enviado un código de verificación de 6 dígitos a tu correo.');
            redirect('reconfirm.php');
            
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errores[] = 'No se pudo completar el registro en la base de datos.';
        }
    }
}

$pageTitle = APP_NOMBRE . ' | Registro';
require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<style>
    .preview-container {
        max-width: 100%;
        max-height: 350px;
        overflow: hidden;
        margin-top: 15px;
        display: none;
        background-color: #f7f7f7;
        border-radius: 8px;
        padding: 10px;
    }
    .preview-container img {
        max-width: 100%;
        display: block;
    }
    .cropper-view-box,
    .cropper-face {
        border-radius: 50%;
    }
    .avatar-wrapper {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-top: 10px;
    }
    .avatar-circular-preview {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #ddd;
        background-color: #eaeaea;
    }
</style>

<section class="auth-section py-5">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="card auth-card shadow-lg border-0">
                    <div class="card-body p-4 p-md-5">
                        
                        <h1 class="h3 fw-bold mb-2">Crear cuenta</h1>
                        <p class="text-muted mb-4">Regístrate para guardar tus preferencias y acceder al panel.</p>
                        
                        <?php if ($errores): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errores as $error): ?>
                                        <li><?= e($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <form id="form-registro" method="post" enctype="multipart/form-data" class="row g-3 needs-validation" novalidate>
                            <?= csrf_field() ?>
                            
                            <div class="col-md-6">
                                <label class="form-label">Nombre visible</label>
                                <input type="text" name="display_name" class="form-control form-control-lg" value="<?= e($campos['display_name']) ?>" required minlength="3">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Usuario</label>
                                <input type="text" name="username" class="form-control form-control-lg" value="<?= e($campos['username']) ?>" required minlength="3" pattern="[a-zA-Z0-9_]{3,50}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Correo electrónico</label>
                                <input type="email" name="email" class="form-control form-control-lg" value="<?= e($campos['email']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha de nacimiento</label>
                                <input type="date" name="date_of_birth" class="form-control form-control-lg" value="<?= e($campos['date_of_birth']) ?>" required>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Foto de perfil</label>
                                <div class="avatar-wrapper">
                                    <img id="avatar-final-preview" class="avatar-circular-preview" src="public/img/default-avatar.png" alt="Avatar">
                                    <div>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('input-avatar').click();">
                                            Seleccionar Imagen
                                        </button>
                                        <input type="file" id="input-avatar" class="d-none" accept="image/*">
                                        <div class="text-muted small mt-1">Si no seleccionas ninguna, se usará el avatar por defecto.</div>
                                    </div>
                                </div>
                                <div class="preview-container" id="preview-wrapper">
                                    <img id="image-preview" src="" alt="Vista previa">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Contraseña</label>
                                <input type="password" name="password" class="form-control form-control-lg" required minlength="6">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Confirmar contraseña</label>
                                <input type="password" name="password_confirmation" class="form-control form-control-lg" required minlength="6">
                            </div>
                            
                            <div class="col-12 mt-4">
                                <button class="btn btn-orange btn-lg px-4" type="submit">Registrarme</button>
                            </div>
                        </form>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const inputAvatar = document.getElementById('input-avatar');
    const imagePreview = document.getElementById('image-preview');
    const previewWrapper = document.getElementById('preview-wrapper');
    const avatarFinalPreview = document.getElementById('avatar-final-preview');
    const formRegistro = document.getElementById('form-registro');
    let cropper = null;

    inputAvatar.addEventListener('change', function (e) {
        const files = e.target.files;
        if (files && files.length > 0) {
            const file = files[0];
            const reader = new FileReader();

            reader.onload = function (e) {
                if (cropper) {
                    cropper.destroy();
                }

                imagePreview.src = e.target.result;
                previewWrapper.style.display = 'block';

                cropper = new Cropper(imagePreview, {
                    aspectRatio: 1,
                    viewMode: 1,
                    autoCropArea: 1,
                    responsive: true,
                    crop() {
                        const canvas = cropper.getCroppedCanvas({ width: 120, height: 120 });
                        if(canvas) {
                            avatarFinalPreview.src = canvas.toDataURL();
                        }
                    }
                });
            };
            reader.readAsDataURL(file);
        }
    });

    formRegistro.addEventListener('submit', function (e) {
        if (!cropper) return;

        e.preventDefault();

        cropper.getCroppedCanvas({
            width: 400,
            height: 400,
            imageSmoothingQuality: 'high'
        }).toBlob(function (blob) {
            const dataTransfer = new DataTransfer();
            const fileRecortado = new File([blob], "avatar.png", { type: "image/png" });
            
            dataTransfer.items.add(fileRecortado);
            
            inputAvatar.name = 'avatar'; 
            inputAvatar.files = dataTransfer.files;

            formRegistro.submit();
        }, 'image/png');
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>