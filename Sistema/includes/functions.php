<?php
require_once __DIR__ . '/db.php';

// Evita inyecciones XSS limpiando las cadenas de texto antes de mostrarlas en el HTML
function e(?string $valor): string { 
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8'); 
}

// Genera URLs absolutas anteponiendo la ruta base del proyecto
function url(string $ruta = ''): string { 
    return BASE_PATH . ltrim($ruta, '/'); 
}

// Redirecciona al usuario a una ruta específica y detiene la ejecución del script
function redirect(string $ruta): void { 
    header('Location: ' . url($ruta)); 
    exit; 
}

// Guarda un mensaje temporal (flash) en la sesión para mostrar notificaciones en la siguiente carga
function set_flash(string $tipo, string $mensaje): void { 
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje]; 
}

// Recupera y elimina el mensaje flash de la sesión para que solo se muestre una vez
function get_flash(): ?array { 
    if (!isset($_SESSION['flash'])) return null; 
    $f = $_SESSION['flash']; 
    unset($_SESSION['flash']); 
    return $f; 
}

// Genera un token CSRF único si no existe uno en la sesión actual
function csrf_token(): string { 
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
    }
    return $_SESSION['csrf_token']; 
}

// Renderiza un campo input oculto con el token CSRF para formularios POST
function csrf_field(): string { 
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'; 
}

// Verifica que el token CSRF enviado coincida con el guardado en la sesión (Protección de seguridad)
function verify_csrf(): void { 
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return; 
    $token = $_POST['csrf_token'] ?? ''; 
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) $token)) { 
        http_response_code(419); 
        exit('Token CSRF inválido.'); 
    } 
}

// Comprueba si la petición actual se realizó mediante el método POST
function is_post(): bool { 
    return $_SERVER['REQUEST_METHOD'] === 'POST'; 
}

// Registra los datos esenciales del usuario en la sesión al iniciar sesión correctamente
function login_user(array $usuario): void { 
    session_regenerate_id(true); 
    
    // Mapeo flexible: Si en el login solo consultaste 'role_id' de la tabla 'users', 
    // determinamos si es 'admin' (ID 1) o 'user' (ID 2). Si ya viene el texto, usa el texto.
    $rolTexto = $usuario['role'] ?? $usuario['nombre_rol'] ?? null;
    if (!$rolTexto && isset($usuario['role_id'])) {
        $rolTexto = ((int)$usuario['role_id'] === 1) ? 'admin' : 'user';
    }

    $_SESSION['usuario'] = [
        'id' => (int)$usuario['id'], 
        'email' => $usuario['email'], 
        'rol' => $rolTexto ?? 'user', 
        'nombre' => $usuario['display_name'] ?? $usuario['username'] ?? $usuario['email']
    ]; 
}

// Comprueba si hay un usuario logueado en la sesión activa
function usuario_autenticado(): bool { 
    return !empty($_SESSION['usuario']['id']); 
}

// MODIFICADO: Ahora selecciona 'p.avatar_media_id' para traer la foto de perfil en tiempo real
function usuario_actual(): ?array
{
    if (!usuario_autenticado()) return null;
    
    static $cache = null;
    if ($cache !== null) return $cache;
    
    // CAMBIO AQUÍ: Se agregó p.avatar_media_id a los campos del SELECT
    $sql = "SELECT 
                u.id, 
                u.email, 
                u.status, 
                r.nombre_rol AS role, 
                u.date_of_birth, 
                u.created_at, 
                p.username, 
                p.display_name, 
                p.bio, 
                p.avatar_media_id, 
                p.profile_visibility, 
                p.location_visibility, 
                p.show_age, 
                p.city_id, 
                c.city_name, 
                c.department_name 
            FROM users u 
            INNER JOIN roles r ON r.id = u.role_id
            LEFT JOIN user_profiles p ON p.user_id = u.id 
            LEFT JOIN cities c ON c.id = p.city_id 
            WHERE u.id = :id 
            LIMIT 1";

    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $_SESSION['usuario']['id']]);
    $cache = $stmt->fetch() ?: null;
    
    return $cache;
}

// Verifica si el usuario autenticado tiene asignado el rol de administrador
function es_admin(): bool { 
    return (($_SESSION['usuario']['rol'] ?? 'user') === 'admin'); 
}

// Restringe el acceso a páginas públicas; redirecciona al login si no se ha iniciado sesión
function require_login(): void { 
    if (!usuario_autenticado()) { 
        set_flash('warning', 'Debes iniciar sesión para continuar.'); 
        redirect('login.php'); 
    } 
}

// Restringe el acceso exclusivo a administradores; lanza un error 403 si no cumple el rol
function require_admin(): void { 
    require_login(); 
    if (!es_admin()) { 
        http_response_code(403); 
        exit('Acceso denegado.'); 
    } 
}

// Evalúa si un usuario cumple con la edad mínima requerida configurada en el sistema
function edad_minima_ok(string $fechaNacimiento): bool { 
    try { 
        $n = new DateTime($fechaNacimiento); 
    } catch (Exception $e) { 
        return false; 
    } 
    return (new DateTime('today'))->diff($n)->y >= EDAD_MINIMA; 
}

// Valida si una cadena de texto tiene un formato de correo electrónico correcto
function validar_email(string $email): bool { 
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; 
}

// MODIFICADO: Ahora incluye un LEFT JOIN con media_assets para obtener la ruta de la imagen de portada
function lugares_destacados(int $limite = 8): array
{
    $sql = "SELECT 
                p.id, 
                p.name, 
                p.description, 
                p.entry_cost, 
                p.currency_code, 
                p.average_rating, 
                p.rating_count, 
                p.address_text,
                ST_Y(p.geo_point) AS lat, 
                ST_X(p.geo_point) AS lng, 
                c.city_name, 
                c.department_name,
                m.storage_url
            FROM places p 
            LEFT JOIN cities c ON c.id = p.city_id 
            LEFT JOIN media_assets m ON m.id = p.cover_media_id
            WHERE p.status = 'active' 
              AND p.moderation_status IN ('approved','flagged') 
            ORDER BY p.average_rating DESC, p.rating_count DESC, p.created_at DESC 
            LIMIT :limite";
            
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Genera métricas cuantitativas globales de las principales tablas para el panel administrativo (Dashboard)
function conteo_dashboard(): array
{
    $pdo = db();
    return [
        'usuarios'    => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn(),
        'sitios'      => (int)$pdo->query("SELECT COUNT(*) FROM places WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn(),
        'comentarios' => (int)$pdo->query("SELECT COUNT(*) FROM place_comments WHERE moderation_status <> 'removed' AND deleted_at IS NULL")->fetchColumn(),
        'mensajes'    => (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
    ];
}

// ======================================================================
// CONFIGURACIÓN Y MÉTODO PARA ENVÍO DE CORREOS VÍA SMTP (SIN COMPOSER)
// ======================================================================

// Importamos las clases necesarias al espacio de nombres global
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envía un correo electrónico utilizando la librería PHPMailer cargada manualmente.
 *
 * @param string $para Correo electrónico del destinatario.
 * @param string $asunto Asunto del mensaje.
 * @param string $cuerpo Contenido del mensaje en formato HTML.
 * @return bool True si se envió con éxito, False si falló.
 */
function enviar_correo_smtp(string $para, string $asunto, string $cuerpo): bool {
    // Requerimos los archivos necesarios apuntando a la subcarpeta dentro de includes/
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';

    $mail = new PHPMailer(true);

    try {
        // --- Configuración del Servidor SMTP ---
        // $mail->SMTPDebug = 2;                                 // Descomenta esta línea para ver errores detallados en la pantalla
        $mail->isSMTP();                                         
        $mail->Host       = 'smtp.gmail.com';                     // Servidor SMTP de Gmail (o el de tu hosting)
        $mail->SMTPAuth   = true;                                 
        $mail->Username   = 'tursegcorp@gmail.com';               // Tu correo electrónico real
        $mail->Password   = 'cvahbvjhasnmjwlu'; // Tu contraseña de aplicación de 16 letras
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;       // Cifrado TLS seguro
        $mail->Port       = 587;                                  // Puerto estándar para TLS
        $mail->CharSet    = 'UTF-8';                             // Evita problemas con eñes y tildes

        // --- Remitente y Destinatario ---
        $mail->setFrom('tursegcorp@gmail.com');
        $mail->addAddress($para);                                

        // --- Contenido del Mensaje ---
        $mail->isHTML(true);                                     // Habilitar formato HTML
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpo;
        $mail->AltBody = strip_tags($cuerpo);                     // Versión en texto plano para lectores antiguos

        $mail->send();
        return true;

    } catch (Exception $e) {
        // En caso de error, puedes registrarlo internamente para no romper la experiencia del usuario
        error_log("Error al enviar correo con PHPMailer: " . $mail->ErrorInfo);
        return false;
    }
}