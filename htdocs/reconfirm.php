<?php
// reconfirm.php
require_once __DIR__ . '/includes/functions.php';

// Si el usuario no está logueado en la sesión, redirigir al login
if (!usuario_autenticado()) {
    redirect('login.php');
}

// Si el usuario ya está activo según la base de datos, lo mandamos al dashboard
if (isset($_SESSION['usuario']['status']) && $_SESSION['usuario']['status'] === 'active') {
    redirect('dashboard.php');
}

$errores = [];

if (is_post()) {
    verify_csrf();
    
    // Capturar las cajas del arreglo code[] enviado por el formulario
    $digitos = $_POST['code'] ?? [];
    $codigo_ingresado = implode('', $digitos);
    
    // Validar que sean exactamente 6 dígitos numéricos
    if (!preg_match('/^[0-9]{6}$/', $codigo_ingresado)) {
        $errores[] = 'El código de verificación debe contener exactamente 6 números.';
    } else {
        $pdo = db();
        try {
            // Buscamos los datos actuales del usuario autenticado en la sesión
            $stmt = $pdo->prepare('SELECT id, email, status FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $_SESSION['usuario']['id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                // ======================================================================
                // CONTROL Y VALIDACIÓN DEL CÓDIGO
                // ======================================================================
                // NOTA: Como la tabla 'users' de tu esquema actual no guarda un token único por fila,
                // puedes sustituir '123456' por la validación que uses en tu lógica de envío de correo
                // (Por ejemplo, guardarlo temporalmente en $_SESSION['codigo_correo_enviado']).
                
$codigo_correcto = $_SESSION['codigo_verificacion_temporal'] ?? '';
                
                if ($codigo_ingresado !== $codigo_correcto) {
                    $errores[] = 'El código de verificación ingresado es incorrecto.';
                }
                
            } else {
                $errores[] = 'El usuario no existe en el sistema.';
            }
            
            // Si no hay errores, procedemos a activar la cuenta actualizando la tabla `users`
            if (!$errores) {
                // Actualizamos el status a 'active' y definimos la fecha de verificación en NOW()
                $stmt = $pdo->prepare("UPDATE users SET status = 'active', email_verified_at = NOW(), updated_at = NOW() WHERE id = :id");
                $stmt->execute(['id' => $_SESSION['usuario']['id']]);
                
                // Sincronizamos el nuevo estado en la sesión global del usuario
                $_SESSION['usuario']['status'] = 'active';
                
                set_flash('success', '¡Tu cuenta ha sido verificada correctamente! Bienvenido.');
                redirect('dashboard.php');
            }
            
        } catch (Throwable $e) {
            $errores[] = 'Ocurrió un error inesperado al procesar la verificación de tu cuenta.';
        }
    }
}

$pageTitle = APP_NOMBRE . ' | Confirmar Cuenta';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Estilos del contenedor de los 6 cuadros de entrada */
    .code-input-group {
        display: flex;
        gap: 10px;
        justify-content: center;
        margin: 25px 0;
    }
    .code-digit {
        width: 48px;
        height: 58px;
        font-size: 24px;
        font-weight: 700;
        text-align: center;
        border: 2px solid #ced4da;
        border-radius: 8px;
        transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
    }
    .code-digit:focus {
        border-color: #fd7e14; /* Tono naranja dinámico */
        outline: 0;
        box-shadow: 0 0 0 0.25rem rgba(253, 126, 20, 0.25);
    }
    /* Eliminar controles nativos de incremento en navegadores */
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
                    <div class="card-body p-4 p-md-5 text-center">
                        
                        <div class="mb-4 text-orange">
                            <i class="bi bi-shield-check" style="font-size: 3.5rem; color: #fd7e14;"></i>
                        </div>
                        
                        <h1 class="h3 fw-bold mb-2">Verifica tu correo</h1>
                        <p class="text-muted mb-4">
                            Ingresa el código de 6 números enviado a:<br>
                            <strong><?= e($_SESSION['usuario']['email'] ?? 'tu correo registrado') ?></strong>
                        </p>
                        
                        <?php if ($errores): ?>
                            <div class="alert alert-danger text-start">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errores as $error): ?>
                                        <li><?= e($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <form id="form-reconfirm" method="post" class="needs-validation" novalidate>
                            <?= csrf_field() ?>
                            
                            <div class="code-input-group">
                                <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off">
                                <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                                <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                                <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                                <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                                <input type="number" name="code[]" class="code-digit" maxlength="1" pattern="[0-9]" required autocomplete="off" disabled>
                            </div>
                            
                            <div class="d-grid gap-2 mt-4">
                                <button class="btn btn-orange btn-lg text-white" style="background-color: #fd7e14; border-color: #fd7e14;" type="submit">
                                    Confirmar Código
                                </button>
                            </div>
                        </form>
                        
                        <div class="mt-4 pt-3 border-top">
                            <p class="text-muted small mb-0">
                                ¿No te llegó el mensaje? <br>
                                <a href="resend-code.php" class="fw-bold text-decoration-none" style="color: #fd7e14;">Reenviar un nuevo código</a>
                            </p>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const inputs = document.querySelectorAll('.code-digit');
    const form = document.getElementById('form-reconfirm');

    inputs.forEach((input, index) => {
        // Evento al escribir el número
        input.addEventListener('input', function () {
            if (this.value.length > 1) {
                this.value = this.value.slice(0, 1);
            }

            // Habilitar la siguiente casilla e inmediatamente poner el cursor allí
            if (this.value !== '') {
                if (index < inputs.length - 1) {
                    inputs[index + 1].removeAttribute('disabled');
                    inputs[index + 1].focus();
                }
            }
        });

        // Evento para gestionar retrocesos inteligentes mediante Backspace
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

        // Permitir que el usuario pegue (Ctrl+V) el código completo de 6 cifras directamente
        input.addEventListener('paste', function (e) {
            const data = e.clipboardData.getData('text').trim();
            if (data.length === inputs.length && /^[0-9]+$/.test(data)) {
                inputs.forEach((inp, idx) => {
                    inp.removeAttribute('disabled');
                    inp.value = data[idx];
                });
                inputs[inputs.length - 1].focus();
            }
            e.preventDefault();
        });
    });

    // Controlar el evento submit para impedir envíos vacíos
    form.addEventListener('submit', function (e) {
        let completo = true;
        inputs.forEach(inp => {
            if (inp.value === '') completo = false;
        });

        if (!completo) {
            e.preventDefault();
            alert('Por favor, rellene las 6 casillas del código de verificación.');
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>