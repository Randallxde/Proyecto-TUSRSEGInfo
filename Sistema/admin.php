<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();
$pdo = db();
$mensaje = null;
$errores = [];

// --- PROCESAMIENTO DE ACCIONES (POST) ---
if (is_post()) {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    
    try {
        // 1. Acción de Usuarios (Unificada: Actualizar Rol y Estado juntos)
        if ($accion === 'actualizar_usuario') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) throw new Exception('ID de usuario no válido.');

            // Validar y limpiar el Rol (1 = admin, 2 = user)
            $roleId = (int)($_POST['role_id'] ?? 2) === 1 ? 1 : 2;

            // Validar y limpiar el Estado
            $estado = in_array($_POST['estado'] ?? '', ['pending','active','suspended','deleted'], true) ? $_POST['estado'] : 'active';

            // Ejecutar una única consulta de actualización
            $stmt = $pdo->prepare('UPDATE users SET role_id = :role_id, status = :estado, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'role_id' => $roleId,
                'estado' => $estado,
                'id' => $userId
            ]);
            $mensaje = 'Datos del usuario (rol y estado) actualizados correctamente.';
        }

        // 2. Acciones de Puntos de Interés / Sitios
        if (in_array($accion, ['cambiar_estado_lugar', 'eliminar_lugar'])) {
            $lugarId = (int)($_POST['lugar_id'] ?? 0);
            if ($lugarId <= 0) throw new Exception('ID de sitio no válido.');

            if ($accion === 'cambiar_estado_lugar') {
                $status = in_array($_POST['status'] ?? '', ['active','inactive','suspended','deleted'], true) ? $_POST['status'] : 'active';
                $modStatus = in_array($_POST['moderation_status'] ?? '', ['pending','approved','flagged','rejected','hidden'], true) ? $_POST['moderation_status'] : 'pending';
                
                $stmt = $pdo->prepare('UPDATE places SET status = :status, moderation_status = :mod_status, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['status' => $status, 'mod_status' => $modStatus, 'id' => $lugarId]);
                $mensaje = 'Sitio turístico y su estado de moderación actualizados.';
            }

            if ($accion === 'eliminar_lugar') {
                $stmt = $pdo->prepare('DELETE FROM places WHERE id = :id');
                $stmt->execute(['id' => $lugarId]);
                $mensaje = 'Punto de interés eliminado correctamente.';
            }
        }

        // 3. Acciones de Comentarios
        if (in_array($accion, ['moderar_comentario', 'eliminar_comentario'])) {
            $comentarioId = (int)($_POST['comentario_id'] ?? 0);
            if ($comentarioId <= 0) throw new Exception('ID de comentario no válido.');

            if ($accion === 'moderar_comentario') {
                $modStatus = in_array($_POST['moderation_status'] ?? '', ['pending','approved','hidden','removed'], true) ? $_POST['moderation_status'] : 'pending';
                $stmt = $pdo->prepare('UPDATE place_comments SET moderation_status = :mod_status, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['mod_status' => $modStatus, 'id' => $comentarioId]);
                $mensaje = 'Estado de moderación del comentario actualizado.';
            }

            if ($accion === 'eliminar_comentario') {
                $stmt = $pdo->prepare('DELETE FROM place_comments WHERE id = :id');
                $stmt->execute(['id' => $comentarioId]);
                $mensaje = 'Comentario eliminado físicamente de la base de datos.';
            }
        }

    } catch (Throwable $e) {
        $errores[] = 'Error al procesar la solicitud: ' . $e->getMessage();
    }
}

// --- CONFIGURACIÓN DE FILTROS Y BÚSQUEDAS (GET) ---
$tab_actual = $_GET['tab'] ?? 'usuarios';

// Filtro Usuarios
$buscar_usuario = trim($_GET['buscar_usuario'] ?? '');
$filtro_estado = trim($_GET['filtro_estado'] ?? '');

$sql_u = "SELECT u.id, u.email, u.role_id, u.status, u.created_at, p.display_name, p.username, p.avatar_media_id 
          FROM users u 
          LEFT JOIN user_profiles p ON p.user_id = u.id WHERE 1=1";
$params_u = [];

if (!empty($buscar_usuario)) {
    $sql_u .= " AND (u.email LIKE :buscar_email OR p.username LIKE :buscar_user OR p.display_name LIKE :buscar_name)";
    $params_u['buscar_email'] = "%$buscar_usuario%";
    $params_u['buscar_user']  = "%$buscar_usuario%";
    $params_u['buscar_name']  = "%$buscar_usuario%";
}

if (!empty($filtro_estado)) {
    $sql_u .= " AND u.status = :filtro_estado";
    $params_u['filtro_estado'] = $filtro_estado;
}

$sql_u .= " ORDER BY u.created_at DESC LIMIT 50";
$stmt_u = $pdo->prepare($sql_u);
$stmt_u->execute($params_u);
$usuarios = $stmt_u->fetchAll();


// Filtro Sitios
$buscar_lugar = trim($_GET['buscar_lugar'] ?? '');
if (!empty($buscar_lugar)) {
    $stmt_l = $pdo->prepare("SELECT p.id, p.name, p.status, p.moderation_status, c.city_name 
                             FROM places p 
                             LEFT JOIN cities c ON c.id = p.city_id 
                             WHERE p.name LIKE :buscar_lugar_name 
                                OR c.city_name LIKE :buscar_lugar_ciudad 
                             ORDER BY p.created_at DESC");
    $stmt_l->execute([
        'buscar_lugar_name'   => "%$buscar_lugar%",
        'buscar_lugar_ciudad' => "%$buscar_lugar%"
    ]);
    $lugares = $stmt_l->fetchAll();
} else {
    $lugares = $pdo->query("SELECT p.id, p.name, p.status, p.moderation_status, c.city_name FROM places p LEFT JOIN cities c ON c.id = p.city_id ORDER BY p.created_at DESC LIMIT 50")->fetchAll();
}

// Filtro Comentarios
$buscar_comentario = trim($_GET['buscar_comentario'] ?? '');
if (!empty($buscar_comentario)) {
    $stmt_c = $pdo->prepare("SELECT co.id, co.body, co.moderation_status, co.created_at, u.email, p.name AS lugar_name 
                             FROM place_comments co 
                             LEFT JOIN users u ON u.id = co.user_id 
                             LEFT JOIN places p ON p.id = co.place_id 
                             WHERE co.body LIKE :buscar_com_body 
                                OR u.email LIKE :buscar_com_email 
                             ORDER BY co.id DESC");
    $stmt_c->execute([
        'buscar_com_body'  => "%$buscar_comentario%",
        'buscar_com_email' => "%$buscar_comentario%"
    ]);
    $comentarios = $stmt_c->fetchAll();
} else {
    $comentarios = $pdo->query("SELECT co.id, co.body, co.moderation_status, co.created_at, u.email, p.name AS lugar_name FROM place_comments co LEFT JOIN users u ON u.id = co.user_id LEFT JOIN places p ON p.id = co.place_id ORDER BY co.id DESC LIMIT 50")->fetchAll();
}

$pageTitle = APP_NOMBRE . ' | Panel de Control';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    :root {
        --admin-accent: #f97316;
        --canvas-bg: #f8fafc;
    }
    body { background-color: var(--canvas-bg); }
    .panel-card { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 1.5rem; }
    .nav-tabs-custom .nav-link { color: #64748b; font-weight: 500; border: none; padding: 0.75rem 1.25rem; }
    .nav-tabs-custom .nav-link.active { color: var(--admin-accent); border-bottom: 3px solid var(--admin-accent); background: none; font-weight: 600; }
    .badge-soft-orange { background-color: rgba(249, 115, 22, 0.1); color: var(--admin-accent); font-weight: 600; }
</style>

<section class="py-5">
<div class="container">

    <div class="mb-4">
        <span class="badge badge-soft-orange px-3 py-2 rounded">Panel de Control General</span>
        <h1 class="h3 fw-bold mt-2 mb-0">Gestión de Usuarios y Contenidos</h1>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <?= e($mensaje) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if ($errores): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <?= e(implode(' ', $errores)) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs nav-tabs-custom mb-4 border-bottom" id="adminTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?= $tab_actual === 'usuarios' ? 'active' : '' ?>" href="?tab=usuarios">
                Usuarios Registrados (<?= count($usuarios) ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab_actual === 'sitios' ? 'active' : '' ?>" href="?tab=sitios">
                Sitios Turísticos (<?= count($lugares) ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab_actual === 'comentarios' ? 'active' : '' ?>" href="?tab=comentarios">
                Comentarios (<?= count($comentarios) ?>)
            </a>
        </li>
    </ul>

    <div class="tab-content" id="adminTabsContent">

        <?php if ($tab_actual === 'usuarios'): ?>
        <div class="panel-card shadow-sm tab-pane fade show active">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <div class="d-flex align-items-center gap-3">
                    <h2 class="h5 fw-bold mb-0 text-dark">Cuentas del Sistema</h2>
                    <!-- BOTÓN REPORTES PDF USUARIOS -->
                    <a href="generar_reporte.php?tipo=usuarios" target="_blank" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" class="bi bi-file-earmark-pdf-fill" viewBox="0 0 16 16">
                            <path d="M5.523 12.424q.21-.124.459-.238a8 8 0 0 1-.45.606c-.28.33-.35.495-.35.644 0 .144.073.238.172.238.15 0 .3-.153.667-.594.58-.697 1.477-1.42 2.22-1.724.037-.015.076-.032.115-.048l.01-.004c.15-.062.302-.118.453-.164.05-.015.1-.03.15-.044a18 18 0 0 1 2.518-.426c.023-.001.046-.002.068-.002.531 0 .756.193.756.518 0 .254-.154.486-.421.661-.277.182-.69.319-1.212.399a21 21 0 0 1-2.947-.044c-.7-.154-1.341-.41-1.841-.673a11 11 0 0 0-1.25.742c-.542.387-.98.717-1.358.981-.311.216-.61.352-.878.352-.45 0-.714-.307-.714-.686 0-.518.36-.977.996-1.694q.15-.171.32-.347m.5-.472c.025-.028.05-.057.077-.087a14 14 0 0 1 .432-.436c.19-.174.348-.298.473-.396q.084-.065.153-.111l.007-.005a7 7 0 0 0-.44.742 15 15 0 0 0-.432.858c-.056-.174-.15-.359-.27-.564M10.289 11.85q.39-.06.741-.174q.14-.047.204-.1.04-.033.04-.08 0-.106-.255-.106a4 4 0 0 0-.442.033c-.304.047-.64.13-1 .247l-.04.012c.382.07.746.12 1.098.12zm-1.241-2.857a7 7 0 0 1 .311-.804c.105-.333.161-.595.161-.79 0-.27-.106-.4-.322-.4-.215 0-.427.214-.639.642a3 3 0 0 0-.154.388c-.166.307-.365.617-.563.887.182.028.36.06.53.09q.318.06.676.077M7.564 12.24c.159.085.38.105.674.105q.43-.003.88-.041l-.04-.016a7.5 7.5 0 0 0-.728-.094 14 14 0 0 0-.786-.01c-.15 0-.21.003-.223.012a.14.14 0 0 0-.01.01c.002-.008.055.017.133.048z"/>
                            <path d="M14 14V4.5L9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2M9.5 1.5 14 6h-4.5z"/>
                        </svg>
                        Reporte PDF
                    </a>
                </div>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="usuarios">
                    <input type="text" name="buscar_usuario" class="form-control form-control-sm" placeholder="Buscar por email o nick..." value="<?= e($buscar_usuario) ?>">
                    
                    <select name="filtro_estado" class="form-select form-select-sm w-auto">
                        <option value="">Todos los estados</option>
                        <option value="active" <?= ($filtro_estado === 'active') ? 'selected' : '' ?>>active</option>
                        <option value="pending" <?= ($filtro_estado === 'pending') ? 'selected' : '' ?>>pending</option>
                        <option value="suspended" <?= ($filtro_estado === 'suspended') ? 'selected' : '' ?>>suspended</option>
                    </select>

                    <button type="submit" class="btn btn-sm btn-dark">Filtrar</button>
                    <?php if(!empty($buscar_usuario) || !empty($filtro_estado)): ?>
                        <a href="?tab=usuarios" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr class="table-light">
                            <th>ID</th>
                            <th>Foto</th>
                            <th>Perfil</th>
                            <th>Correo Electrónico</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th class="text-end">Configuración de Cuenta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): 
                            $roleId = (int)($u['role_id'] ?? 2);
                            $status = $u['status'] ?? 'pending';
                            $avatarUrl = !empty($u['avatar_media_id']) ? e($u['avatar_media_id']) : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
                        ?>
                        <tr>
                            <td class="text-muted fw-bold"><?= (int)$u['id'] ?></td>
                            
                            <td>
                                <img src="<?= $avatarUrl ?>" 
                                     alt="Foto de <?= e($u['display_name'] ?? 'Usuario') ?>" 
                                     class="rounded-circle border" 
                                     width="40" 
                                     height="40" 
                                     style="object-fit: cover;">
                            </td>
                            
                            <td>
                                <div class="fw-bold"><?= e($u['display_name'] ?? '-') ?></div>
                                <small class="text-muted">@<?= e($u['username'] ?? 'sin_username') ?></small>
                            </td>
                            
                            <td><?= e($u['email'] ?? '-') ?></td>
                            
                            <td>
                                <span class="badge <?= ($roleId === 1) ? 'bg-danger' : 'bg-secondary' ?>">
                                    <?= ($roleId === 1) ? 'admin' : 'user' ?>
                                </span>
                            </td>
                            
                            <td>
                                <span class="badge <?php 
                                    echo match($status) {
                                        'active' => 'bg-success',
                                        'pending' => 'bg-warning text-dark',
                                        'suspended' => 'bg-dark',
                                        default => 'bg-secondary'
                                    };
                                ?>">
                                    <?= e($status) ?>
                                </span>
                            </td>
                            
                            <td>
                                <div class="d-flex justify-content-end">
                                    <form method="post" class="d-flex flex-wrap align-items-center gap-2 m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

                                        <select name="role_id" class="form-select form-select-sm w-auto" title="Seleccionar Rol">
                                            <option value="2" <?= ($roleId === 2) ? 'selected' : '' ?>>user</option>
                                            <option value="1" <?= ($roleId === 1) ? 'selected' : '' ?>>admin</option>
                                        </select>

                                        <select name="estado" class="form-select form-select-sm w-auto" title="Seleccionar Estado">
                                            <option value="active" <?= ($status === 'active') ? 'selected' : '' ?>>active</option>
                                            <option value="pending" <?= ($status === 'pending') ? 'selected' : '' ?>>pending</option>
                                            <option value="suspended" <?= ($status === 'suspended') ? 'selected' : '' ?>>suspended</option>
                                        </select>

                                        <button type="submit" name="accion" value="actualizar_usuario" class="btn btn-sm btn-success py-1">
                                            Actualizar
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($usuarios)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No se hallaron coincidencias de usuarios.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tab_actual === 'sitios'): ?>
        <div class="panel-card shadow-sm tab-pane fade show active">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <div class="d-flex align-items-center gap-3">
                    <h2 class="h5 fw-bold mb-0 text-dark">Puntos de Interés</h2>
                    <!-- BOTÓN REPORTES PDF SITIOS -->
                    <a href="generar_reporte.php?tipo=sitios" target="_blank" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" class="bi bi-file-earmark-pdf-fill" viewBox="0 0 16 16">
                            <path d="M5.523 12.424q.21-.124.459-.238a8 8 0 0 1-.45.606c-.28.33-.35.495-.35.644 0 .144.073.238.172.238.15 0 .3-.153.667-.594.58-.697 1.477-1.42 2.22-1.724.037-.015.076-.032.115-.048l.01-.004c.15-.062.302-.118.453-.164.05-.015.1-.03.15-.044a18 18 0 0 1 2.518-.426c.023-.001.046-.002.068-.002.531 0 .756.193.756.518 0 .254-.154.486-.421.661-.277.182-.69.319-1.212.399a21 21 0 0 1-2.947-.044c-.7-.154-1.341-.41-1.841-.673a11 11 0 0 0-1.25.742c-.542.387-.98.717-1.358.981-.311.216-.61.352-.878.352-.45 0-.714-.307-.714-.686 0-.518.36-.977.996-1.694q.15-.171.32-.347m.5-.472c.025-.028.05-.057.077-.087a14 14 0 0 1 .432-.436c.19-.174.348-.298.473-.396q.084-.065.153-.111l.007-.005a7 7 0 0 0-.44.742 15 15 0 0 0-.432.858c-.056-.174-.15-.359-.27-.564M10.289 11.85q.39-.06.741-.174q.14-.047.204-.1.04-.033.04-.08 0-.106-.255-.106a4 4 0 0 0-.442.033c-.304.047-.64.13-1 .247l-.04.012c.382.07.746.12 1.098.12zm-1.241-2.857a7 7 0 0 1 .311-.804c.105-.333.161-.595.161-.79 0-.27-.106-.4-.322-.4-.215 0-.427.214-.639.642a3 3 0 0 0-.154.388c-.166.307-.365.617-.563.887.182.028.36.06.53.09q.318.06.676.077M7.564 12.24c.159.085.38.105.674.105q.43-.003.88-.041l-.04-.016a7.5 7.5 0 0 0-.728-.094 14 14 0 0 0-.786-.01c-.15 0-.21.003-.223.012a.14.14 0 0 0-.01.01c.002-.008.055.017.133.048z"/>
                            <path d="M14 14V4.5L9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2M9.5 1.5 14 6h-4.5z"/>
                        </svg>
                        Reporte PDF
                    </a>
                </div>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="sitios">
                    <input type="text" name="buscar_lugar" class="form-control form-control-sm" placeholder="Buscar sitio o ciudad..." value="<?= e($buscar_lugar) ?>">
                    <button type="submit" class="btn btn-sm btn-dark">Filtrar</button>
                    <?php if(!empty($buscar_lugar)): ?>
                        <a href="?tab=sitios" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                <thead>
                    <tr class="table-light">
                        <th>ID</th>
                        <th>Nombre del Sitio</th>
                        <th>Ciudad</th>
                        <th>Moderación</th>
                        <th>Visibilidad</th>
                        <th class="text-end">Acciones de Control</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lugares as $l): ?>
                    <tr>
                        <td class="text-muted fw-bold"><?= (int)$l['id'] ?></td>
                        <td class="fw-bold"><?= e($l['name']) ?></td>
                        <td><?= e($l['city_name'] ?? '-') ?></td>
                        <td>
                            <span class="badge <?php 
                                echo match($l['moderation_status']) {
                                    'approved' => 'bg-success',
                                    'pending' => 'bg-warning text-dark',
                                    'flagged' => 'bg-danger',
                                    'rejected' => 'bg-dark',
                                    'hidden' => 'bg-secondary',
                                    default => 'bg-light text-dark'
                                };
                             ?>">
                                <?= e($l['moderation_status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $l['status'] === 'active' ? 'bg-info text-dark' : 'bg-secondary' ?>">
                                <?= e($l['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex justify-content-end gap-1">
                                <form method="post" class="d-flex align-items-center gap-1 m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="lugar_id" value="<?= (int)$l['id'] ?>">

                                    <select name="moderation_status" class="form-select form-select-sm w-auto">
                                        <option value="pending" <?= $l['moderation_status'] === 'pending' ? 'selected' : '' ?>>pending</option>
                                        <option value="approved" <?= $l['moderation_status'] === 'approved' ? 'selected' : '' ?>>approved</option>
                                        <option value="flagged" <?= $l['moderation_status'] === 'flagged' ? 'selected' : '' ?>>flagged</option>
                                        <option value="rejected" <?= $l['moderation_status'] === 'rejected' ? 'selected' : '' ?>>rejected</option>
                                        <option value="hidden" <?= $l['moderation_status'] === 'hidden' ? 'selected' : '' ?>>hidden</option>
                                    </select>

                                    <select name="status" class="form-select form-select-sm w-auto">
                                        <option value="active" <?= $l['status'] === 'active' ? 'selected' : '' ?>>active</option>
                                        <option value="inactive" <?= $l['status'] === 'inactive' ? 'selected' : '' ?>>inactive</option>
                                        <option value="suspended" <?= $l['status'] === 'suspended' ? 'selected' : '' ?>>suspended</option>
                                        <option value="deleted" <?= $l['status'] === 'deleted' ? 'selected' : '' ?>>deleted</option>
                                    </select>

                                    <button type="submit" name="accion" value="cambiar_estado_lugar" class="btn btn-sm btn-success py-1">Guardar</button>
                                    <button type="submit" name="accion" value="eliminar_lugar" class="btn btn-sm btn-outline-danger py-1" onclick="return confirm('¿Deseas eliminar este sitio por completo?');">Borrar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($lugares)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No se encontraron sitios cargados.</td></tr>
                    <?php endif; ?>
                </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tab_actual === 'comentarios'): ?>
        <div class="panel-card shadow-sm tab-pane fade show active">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <div class="d-flex align-items-center gap-3">
                    <h2 class="h5 fw-bold mb-0 text-dark">Moderación de Comentarios</h2>
                    <!-- BOTÓN REPORTES PDF COMENTARIOS -->
                    <a href="generar_reporte.php?tipo=comentarios" target="_blank" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" class="bi bi-file-earmark-pdf-fill" viewBox="0 0 16 16">
                            <path d="M5.523 12.424q.21-.124.459-.238a8 8 0 0 1-.45.606c-.28.33-.35.495-.35.644 0 .144.073.238.172.238.15 0 .3-.153.667-.594.58-.697 1.477-1.42 2.22-1.724.037-.015.076-.032.115-.048l.01-.004c.15-.062.302-.118.453-.164.05-.015.1-.03.15-.044a18 18 0 0 1 2.518-.426c.023-.001.046-.002.068-.002.531 0 .756.193.756.518 0 .254-.154.486-.421.661-.277.182-.69.319-1.212.399a21 21 0 0 1-2.947-.044c-.7-.154-1.341-.41-1.841-.673a11 11 0 0 0-1.25.742c-.542.387-.98.717-1.358.981-.311.216-.61.352-.878.352-.45 0-.714-.307-.714-.686 0-.518.36-.977.996-1.694q.15-.171.32-.347m.5-.472c.025-.028.05-.057.077-.087a14 14 0 0 1 .432-.436c.19-.174.348-.298.473-.396q.084-.065.153-.111l.007-.005a7 7 0 0 0-.44.742 15 15 0 0 0-.432.858c-.056-.174-.15-.359-.27-.564M10.289 11.85q.39-.06.741-.174q.14-.047.204-.1.04-.033.04-.08 0-.106-.255-.106a4 4 0 0 0-.442.033c-.304.047-.64.13-1 .247l-.04.012c.382.07.746.12 1.098.12zm-1.241-2.857a7 7 0 0 1 .311-.804c.105-.333.161-.595.161-.79 0-.27-.106-.4-.322-.4-.215 0-.427.214-.639.642a3 3 0 0 0-.154.388c-.166.307-.365.617-.563.887.182.028.36.06.53.09q.318.06.676.077M7.564 12.24c.159.085.38.105.674.105q.43-.003.88-.041l-.04-.016a7.5 7.5 0 0 0-.728-.094 14 14 0 0 0-.786-.01c-.15 0-.21.003-.223.012a.14.14 0 0 0-.01.01c.002-.008.055.017.133.048z"/>
                            <path d="M14 14V4.5L9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2M9.5 1.5 14 6h-4.5z"/>
                        </svg>
                        Reporte PDF
                    </a>
                </div>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="comentarios">
                    <input type="text" name="buscar_comentario" class="form-control form-control-sm" placeholder="Buscar texto o autor..." value="<?= e($buscar_comentario) ?>">
                    <button type="submit" class="btn btn-sm btn-dark">Filtrar</button>
                    <?php if(!empty($buscar_comentario)): ?>
                        <a href="?tab=comentarios" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                <thead>
                    <tr class="table-light">
                        <th>ID</th>
                        <th>Autor</th>
                        <th>Destino</th>
                        <th>Contenido del Mensaje</th>
                        <th>Moderación</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($comentarios as $c): ?>
                    <tr>
                        <td class="text-muted fw-bold"><?= (int)$c['id'] ?></td>
                        <td><small class="fw-semibold"><?= e($c['email'] ?? 'Desconocido') ?></small></td>
                        <td class="small text-truncate" style="max-width: 140px;"><?= e($c['lugar_name'] ?? 'ID: '.$c['place_id']) ?></td>
                        <td class="text-wrap" style="max-width: 320px;"><small class="text-muted">"<?= e($c['body']) ?>"</small></td>
                        <td>
                            <span class="badge <?php 
                                echo match($c['moderation_status']) {
                                    'approved' => 'bg-success',
                                    'pending' => 'bg-warning text-dark',
                                    'hidden' => 'bg-secondary',
                                    'removed' => 'bg-danger',
                                    default => 'bg-light text-dark'
                                };
                            ?>">
                                <?= e($c['moderation_status'] ?? 'pending') ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex justify-content-end gap-1">
                                <form method="post" class="d-flex align-items-center gap-1 m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="comentario_id" value="<?= (int)$c['id'] ?>">

                                    <select name="moderation_status" class="form-select form-select-sm w-auto">
                                        <option value="pending" <?= ($c['moderation_status'] === 'pending') ? 'selected' : '' ?>>pending</option>
                                        <option value="approved" <?= ($c['moderation_status'] === 'approved') ? 'selected' : '' ?>>approved</option>
                                        <option value="hidden" <?= ($c['moderation_status'] === 'hidden') ? 'selected' : '' ?>>hidden</option>
                                        <option value="removed" <?= ($c['moderation_status'] === 'removed') ? 'selected' : '' ?>>removed</option>
                                    </select>

                                    <button type="submit" name="accion" value="moderar_comentario" class="btn btn-sm btn-success py-1">Aplicar</button>
                                    <button type="submit" name="accion" value="eliminar_comentario" class="btn btn-sm btn-outline-danger py-1" onclick="return confirm('¿Eliminar por completo de la BD?');">Borrar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($comentarios)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No se hallaron comentarios en el sistema.</td></tr>
                    <?php endif; ?>
                </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>