<?php
require_once __DIR__ . '/includes/functions.php';

// Si el usuario ya está logueado, lo mandamos directo a su panel
if (usuario_autenticado()) {
    redirect(es_admin() ? 'admin.php' : 'dashboard.php');
}

$errores = [];
$email = '';

if (is_post()) {
    verify_csrf();
    
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    
    // Validaciones básicas de entrada
    if (!validar_email($email)) {
        $errores[] = 'Ingresa un correo válido.';
    }
    if (strlen($password) < 6) {
        $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    }
    
    if (!$errores) {
        // Tu consulta estructurada (Mantenida a la perfección)
        $sql = "SELECT \n                    u.id, \n                    u.email, \n                    u.password_hash, \n                    u.status, \n                    u.role_id,\n                    r.nombre_rol, \n                    p.display_name,\n                    p.username\n                FROM users u \n                INNER JOIN roles r ON r.id = u.role_id\n                LEFT JOIN user_profiles p ON p.user_id = u.id \n                WHERE u.email = :email \n                LIMIT 1";
                
        $stmt = db()->prepare($sql);
        $stmt->execute(['email' => $email]);
        $usuario = $stmt->fetch();
        
        if ($usuario && password_verify($password, $usuario['password_hash'])) {
            // Verificar si la cuenta está suspendida
            if ($usuario['status'] === 'suspended') {
                $errores[] = 'Tu cuenta ha sido suspendida. Contacta al soporte.';
            } 
            // Si está pendiente, redirigir a reconfirmar antes de dejarlo entrar de lleno
            elseif ($usuario['status'] === 'pending') {
                login_user($usuario);
                set_flash('info', 'Tu cuenta aún no está verificada. Por favor ingresa el código.');
                redirect('reconfirm.php');
            } 
            else {
                // Loguear e iniciar sesión formalmente
                login_user($usuario);
                set_flash('success', '¡Bienvenido de nuevo!');
                redirect(es_admin() ? 'admin.php' : 'dashboard.php');
            }
        } else {
            $errores[] = 'El correo o la contraseña son incorrectos.';
        }
    }
}

$pageTitle = APP_NOMBRE . ' | Iniciar Sesión';
require_once __DIR__ . '/includes/header.php';
?>

<section class="auth-section py-5">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card auth-card shadow-lg border-0">
                    <div class="card-body p-4 p-md-5">
                        
                        <h1 class="h3 fw-bold mb-2">Iniciar sesión</h1>
                        <p class="text-muted mb-4">Ingresa tus credenciales para acceder a la plataforma.</p>
                        
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
                                <input type="email" name="email" class="form-control form-control-lg" value="<?= e($email) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <input type="password" name="password" class="form-control form-control-lg" minlength="6" required>
                            </div>
                            
                            <button class="btn btn-orange w-100 btn-lg" type="submit">Entrar</button>
                            
                            <div class="text-center mt-3">
                                <a href="<?= e(url('forgot-password.php')) ?>" class="text-orange text-decoration-none fw-bold small">
                                    ¿Olvidaste tu contraseña?
                                </a>
                            </div>
                        </form>
                        
                        <div class="mt-4 text-center border-top pt-3">
                            <small class="text-muted">                              ¿No tienes cuenta? <a href="<?= e(url('register.php')) ?>" class="text-orange fw-bold text-decoration-none">Regístrate</a>                      </small>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>