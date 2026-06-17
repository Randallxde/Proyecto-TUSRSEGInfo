<?php
// reset-password.php
require_once __DIR__ . '/includes/functions.php';

if (usuario_autenticado()) {
    redirect('dashboard.php');
}

$errores = [];
$exito = false;

if (is_post()) {
    verify_csrf();
    
    // Si no hay sesión activa de recuperación, bloqueamos el procesamiento del POST
    if (!isset($_SESSION['recuperacion_email']) || !isset($_SESSION['recuperacion_codigo'])) {
        $errores[] = 'Tu sesión ha expirado o no has solicitado un código. Por favor, vuelve a solicitarlo.';
    } else {
        // Capturar el código de las 6 casillas
        $digitos = $_POST['code'] ?? [];
        $codigo_ingresado = implode('', $digitos);
        
        $password = (string)($_POST['password'] ?? '');
        $password_conf = (string)($_POST['password_confirmation'] ?? '');
        
        // 1. Validar el código de seguridad
        if ($codigo_ingresado !== $_SESSION['recuperacion_codigo']) {
            $errores[] = 'El código de verificación ingresado es incorrecto.';
        }
        // 2. Validar contraseñas
        if (strlen($password) < 6) {
            $errores[] = 'La nueva contraseña debe tener al menos 6 caracteres.';
        } elseif ($password !== $password_conf) {
            $errores[] = 'Las contraseñas no coinciden.';
        }
        
        if (!$errores) {
            try {
                $pdo = db();
                
                // Actualizar la contraseña en la base de datos
                $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE email = :email');
                $stmt->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'email' => $_SESSION['recuperacion_email']
                ]);
                
                // Limpiar variables de recuperación
                unset($_SESSION['recuperacion_email']);
                unset($_SESSION['recuperacion_codigo']);
                
                $exito = true;
                set_flash('success', 'Contraseña restablecida correctamente. Ya puedes iniciar sesión.');
                
            } catch (Throwable $e) {
                $errores[] = 'Ocurrió un error al actualizar tu contraseña.';
            }
        }
    }
}

$pageTitle = APP_NOMBRE . ' | Restablecer Contraseña';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .code-input-group {
        display: flex;
        gap: 10px;
        justify-content: center;
        margin: 20px 0;
    }
    .code-digit {
        width: 45px;
        height: 55px;
        font-size: 22px;
        font-weight: 700;
        text-align: center;
        border: 2px solid #ced4da;
        border-radius: 8px;
    }
    .code-digit:focus {
        border-color: #fd7e14;
        outline: 0;
        box-shadow: 0 0 0 0.25rem rgba(253, 126, 20, 0.25);
    }
    .code-digit::-webkit-outer-spin-button,
    .code-digit::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    .code-digit[type=number] {
        -moz-appearance: textfield;
    }
</style>

<section class="auth-section py-5">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card auth-card shadow-lg border-0">
                    <div class="card-body p-4 p-md-5">
                        
                        <h1 class="h3 fw-bold mb-2">Restablecer clave</h1>
                        <p class="text-muted mb-4">Ingresa el código que llegó a tu correo electrónico junto con tu nueva contraseña.</p>
                        
                        <?php if ($errores): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errores as $error): ?>
                                        <li><?= e($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($exito): ?>
                            <div class="alert alert-success">
                                ¡Tu contraseña ha sido cambiada de forma exitosa!
                            </div>
                            <div class="d-grid gap-2 mt-4">
                                <a href="login.php" class="btn btn-orange btn-lg text-white" style="background-color: #fd7e14;">Iniciar Sesión</a>
                            </div>
                        <?php else: ?>
                            <form id="form-reset" method="post" class="needs-validation" novalidate>
                                <?= csrf_field() ?>
                                
                                <label class="form-label d-block text-center fw-bold">Código de Seguridad</label>
                                <div class="code-input-group">
                                    <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off">
                                    <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                                    <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                                    <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                                    <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                                    <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Nueva contraseña</label>
                                    <input type="password" name="password" class="form-control form-control-lg" required minlength="6">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Confirmar nueva contraseña</label>
                                    <input type="password" name="password_confirmation" class="form-control form-control-lg" required minlength="6">
                                </div>
                                
                                <div class="d-grid gap-2 mt-4">
                                    <button class="btn btn-orange btn-lg text-white" style="background-color: #fd7e14; border-color: #fd7e14;" type="submit">
                                        Cambiar contraseña
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const inputs = document.querySelectorAll('.code-digit');

    inputs.forEach((input, index) => {
        input.addEventListener('input', function () {
            if (this.value.length > 1) this.value = this.value.slice(0, 1);
            if (this.value !== '') {
                if (index < inputs.length - 1) {
                    inputs[index + 1].removeAttribute('disabled');
                    inputs[index + 1].focus();
                }
            }
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace') {
                if (this.value === '') {
                    if (index > 0) {
                        inputs[index - 1].focus();
                        inputs[index].setAttribute('disabled', 'true');
                    }
                } else {
                    this.value = '';
                }
                e.preventDefault();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>