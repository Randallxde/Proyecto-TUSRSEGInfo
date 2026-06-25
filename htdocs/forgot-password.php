<?php
// forgot-password.php
require_once __DIR__ . '/includes/functions.php';

if (usuario_autenticado()) {
    redirect('dashboard.php');
}

$errores = [];
$email = '';

if (is_post()) {
    verify_csrf();
    
    $email = trim((string)($_POST['email'] ?? ''));
    
    if (!validar_email($email)) {
        $errores[] = 'Por favor, ingresa un correo electrónico válido.';
    } else {
        $pdo = db();
        try {
            // Verificar si el correo existe
            $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Generar un código aleatorio de 6 dígitos
                $codigo_recuperacion = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
                
                // Guardamos los datos en la sesión temporal para recuperarlos en el siguiente paso
                $_SESSION['recuperacion_email'] = $user['email'];
                $_SESSION['recuperacion_codigo'] = $codigo_recuperacion;
                
                // Diseñar el correo electrónico con el código
                $asunto = "Código de recuperación de contraseña - TurSegInfo";
                $cuerpo = "
                    <div style='font-family: Arial, sans-serif; max-width: 550px; margin: 0 auto; padding: 25px; border: 1px solid #e9ecef; border-radius: 12px;'>
                        <h2 style='color: #fd7e14; text-align: center;'>Recuperación de Contraseña</h2>
                        <p style='color: #495057; font-size: 16px; line-height: 1.5;'>Has solicitado restablecer tu contraseña en <strong>TurSegInfo</strong>.</p>
                        <p style='color: #495057; font-size: 16px; line-height: 1.5;'>Ingresa el siguiente código de seguridad en la pantalla de validación:</p>
                        
                        <div style='background-color: #f8f9fa; border: 2px dashed #fd7e14; padding: 15px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #212529; border-radius: 8px; margin: 25px 0;'>
                            {$codigo_recuperacion}
                        </div>
                        
                        <p style='font-size: 12px; color: #6c757d; text-align: center;'>Este código es temporal. Si tú no solicitaste este cambio, puedes ignorar este correo.</p>
                    </div>
                ";
                
                // SE ENVÍA EL CORREO ÚNICAMENTE SI EL USUARIO EXISTE (DENTRO DEL IF)
                enviar_correo_smtp($email, $asunto, $cuerpo);
                
                // Forzar a PHP a escribir la sesión antes de redireccionar
                session_write_close(); 
            }
            
            // Redirigir siempre a la pantalla de verificación del código
            // (Hacerlo fuera del 'if($user)' es una buena práctica para que nadie sepa qué correos existen y cuáles no)
            set_flash('info', 'Si el correo existe, hemos enviado un código de seguridad de 6 dígitos.');
            redirect('reset-password.php');
            
        } catch (Throwable $e) {
            $errores[] = 'Ocurrió un error al procesar la solicitud.';
        }
    }
}

$pageTitle = APP_NOMBRE . ' | Recuperar Contraseña';
require_once __DIR__ . '/includes/header.php';
?>

<section class="auth-section py-5">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card auth-card shadow-lg border-0">
                    <div class="card-body p-4 p-md-5">
                        
                        <h1 class="h3 fw-bold mb-2">¿Olvidaste tu contraseña?</h1>
                        <p class="text-muted mb-4">Ingresa tu correo y te envaremos un código de seguridad para restaurar tu acceso.</p>
                        
                        <?php if ($errores): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errores as $error): ?>
                                        <li><?= e($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" class="needs-validation" novalidate>
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label class="form-label">Correo electrónico</label>
                                <input type="email" name="email" class="form-control form-control-lg" value="<?= e($email) ?>" required placeholder="ejemplo@correo.com">
                            </div>
                            
                            <div class="d-grid gap-2 mt-4">
                                <button class="btn btn-orange btn-lg text-white" style="background-color: #fd7e14; border-color: #fd7e14;" type="submit">
                                    Enviar código de seguridad
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center mt-4 pt-2 border-top">
                            <a href="login.php" class="text-muted small text-decoration-none">Volver al Inicio de Sesión</a>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>