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
        // 1. Acción de Usuarios
        if ($accion === 'actualizar_usuario') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) throw new Exception('ID de usuario no válido.');

            $roleId = (int)($_POST['role_id'] ?? 2) === 1 ? 1 : 2;
            $estado = in_array($_POST['estado'] ?? '', ['pending','active','suspended'], true) ? $_POST['estado'] : 'active';

            $stmt = $pdo->prepare('UPDATE users SET role_id = :role_id, status = :estado, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'role_id' => $roleId,
                'estado' => $estado,
                'id' => $userId
            ]);
            $mensaje = 'Datos del usuario actualizados correctamente.';
        }

        // 2. Acciones de Sitios Turísticos
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
                $mensaje = 'Comentario eliminado físicamente.';
            }
        }

    } catch (Throwable $e) {
        $errores[] = 'Error: ' . $e->getMessage();
    }
}

// --- CONTEOS TOTALES PARA LAS TARJETAS DE MÉTRICAS ---
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalPlaces = $pdo->query("SELECT COUNT(*) FROM places")->fetchColumn();
$totalComments = $pdo->query("SELECT COUNT(*) FROM place_comments")->fetchColumn();

// --- CONFIGURACIÓN DE FILTROS Y BÚSQUEDAS (GET) ---
$tab_actual = $_GET['tab'] ?? 'usuarios';

$usuarios = [];
$lugares = [];
$comentarios = [];

// 1. Carga de Usuarios
if ($tab_actual === 'usuarios') {
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
}

// 2. Carga de Sitios (CORREGIDO: p.creator_user_id en lugar de p.user_id)
if ($tab_actual === 'sitios') {
    $buscar_lugar = trim($_GET['buscar_lugar'] ?? '');
    $sql_l = "SELECT p.id, p.name, p.status, p.moderation_status, p.created_at, c.city_name, 
                     u.email AS creador_email, up.username AS creador_username
              FROM places p 
              LEFT JOIN cities c ON c.id = p.city_id 
              LEFT JOIN users u ON u.id = p.creator_user_id
              LEFT JOIN user_profiles up ON up.user_id = u.id
              WHERE 1=1";
    $params_l = [];

    if (!empty($buscar_lugar)) {
        $sql_l .= " AND (p.name LIKE :buscar_lugar_name OR c.city_name LIKE :buscar_lugar_ciudad)";
        $params_l['buscar_lugar_name']   = "%$buscar_lugar%";
        $params_l['buscar_lugar_ciudad'] = "%$buscar_lugar%";
    }
    $sql_l .= " ORDER BY p.created_at DESC LIMIT 50";
    $stmt_l = $pdo->prepare($sql_l);
    $stmt_l->execute($params_l);
    $lugares = $stmt_l->fetchAll();
}

// 3. Carga de Comentarios
if ($tab_actual === 'comentarios') {
    $buscar_comentario = trim($_GET['buscar_comentario'] ?? '');
    $sql_c = "SELECT co.id, co.place_id, co.body, co.moderation_status, co.created_at, u.email, p.name AS lugar_name 
              FROM place_comments co 
              LEFT JOIN users u ON u.id = co.user_id 
              LEFT JOIN places p ON p.id = co.place_id WHERE 1=1";
    $params_c = [];

    if (!empty($buscar_comentario)) {
        $sql_c .= " AND (co.body LIKE :buscar_com_body OR u.email LIKE :buscar_com_email)";
        $params_c['buscar_com_body']  = "%$buscar_comentario%";
        $params_c['buscar_com_email'] = "%$buscar_comentario%";
    }
    $sql_c .= " ORDER BY co.id DESC LIMIT 50";
    $stmt_c = $pdo->prepare($sql_c);
    $stmt_c->execute($params_c);
    $comentarios = $stmt_c->fetchAll();
}

$pageTitle = APP_NOMBRE . ' | Panel de Control';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    :root {
        --admin-accent: #f97316;
        --admin-accent-hover: #ea580c;
        --canvas-bg: #f8fafc;
        --card-border: #e2e8f0;
    }
    body { background-color: var(--canvas-bg); font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    
    /* Dashboard Cards */
    .metric-card { background: #ffffff; border: 1px solid var(--card-border); border-radius: 16px; transition: transform 0.2s, box-shadow 0.2s; }
    .metric-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    
    /* Panel Structural Elements */
    .panel-card { background: #ffffff; border-radius: 16px; border: 1px solid var(--card-border); padding: 1.75rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
    
    /* Custom Navigation Tabs */
    .nav-tabs-custom { gap: 0.5rem; }
    .nav-tabs-custom .nav-link { color: #64748b; font-weight: 600; border: none; border-radius: 8px; padding: 0.6rem 1.2rem; transition: all 0.2s; }
    .nav-tabs-custom .nav-link:hover { background-color: #f1f5f9; color: #334155; }
    .nav-tabs-custom .nav-link.active { color: #ffffff; background-color: var(--admin-accent); box-shadow: 0 4px 12px rgba(249, 115, 22, 0.25); }
    
    /* Tables Styling */
    .table-responsive { border-radius: 12px; overflow: hidden; border: 1px solid #f1f5f9; }
    .table thead th { background-color: #f8fafc; color: #475569; font-weight: 600; text-transform: uppercase; font-size: 0.78rem; letter-spacing: 0.05em; padding: 1rem; border-bottom: 2px solid #edf2f7; }
    .table tbody td { padding: 1rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
    .table tbody tr:hover { background-color: #fdfefe; }
    
    /* Form elements */
    .form-select-sm, .form-control-sm { border-radius: 8px; border-color: #cbd5e1; padding-top: 0.4rem; padding-bottom: 0.4rem; }
    .form-select-sm:focus, .form-control-sm:focus { border-color: var(--admin-accent); box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15); }
    
    /* Custom Badges */
    .badge-status-lg { font-size: 0.75rem; font-weight: 600; padding: 0.35rem 0.7rem; border-radius: 8px; }
    .bg-user-tag { background-color: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
</style>

<section class="py-5">
<div class="container-fluid px-md-5">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-5">
        <div>
            <h1 class="h2 fw-bold text-slate-900 mb-1">Panel de Control General</h1>
            <p class="text-muted mb-0">Módulos avanzados de administración y auditoría para la plataforma TURSEG.</p>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card p-4 d-flex align-items-center gap-3">
                <div class="p-3 bg-primary-subtle text-primary fs-3" style="border-radius:12px;"><i class="fa-solid fa-users"></i></div>
                <div>
                    <span class="text-muted small fw-medium d-block">Usuarios Totales</span>
                    <span class="h3 fw-bold m-0"><?= $totalUsers ?></span>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="metric-card p-4 d-flex align-items-center gap-3">
                <div class="p-3 bg-warning-subtle text-warning fs-3" style="border-radius:12px;"><i class="fa-solid fa-map-location-dot"></i></div>
                <div>
                    <span class="text-muted small fw-medium d-block">Sitios Mapeados</span>
                    <span class="h3 fw-bold m-0"><?= $totalPlaces ?></span>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="metric-card p-4 d-flex align-items-center gap-3">
                <div class="p-3 bg-success-subtle text-success fs-3" style="border-radius:12px;"><i class="fa-solid fa-comments"></i></div>
                <div>
                    <span class="text-muted small fw-medium d-block">Reseñas Compartidas</span>
                    <span class="h3 fw-bold m-0"><?= $totalComments ?></span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-success border-0 shadow-sm alert-dismissible fade show p-3 mb-4" role="alert" style="border-left: 5px solid #22c55e !important; background: #fff;">
        <div class="d-flex align-items-center gap-2 text-success fw-medium">
            <i class="fa-solid fa-circle-check fs-5"></i> <span><?= e($mensaje) ?></span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs nav-tabs-custom mb-4 border-0" id="adminTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?= $tab_actual === 'usuarios' ? 'active' : '' ?>" href="?tab=usuarios">
                <i class="fa-solid fa-user-shield me-2"></i>Cuentas de Usuario
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab_actual === 'sitios' ? 'active' : '' ?>" href="?tab=sitios">
                <i class="fa-solid fa-earth-americas me-2"></i>Sitios Turísticos
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab_actual === 'comentarios' ? 'active' : '' ?>" href="?tab=comentarios">
                <i class="fa-solid fa-comment-dots me-2"></i>Comentarios
            </a>
        </li>
    </ul>

    <div class="tab-content" id="adminTabsContent">

        <?php if ($tab_actual === 'usuarios'): ?>
        <div class="panel-card tab-pane fade show active">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h5 fw-bold mb-0 text-dark">Gestión de Accesos</h2>
                    <a href="generar_reporte.php?tipo=usuarios" target="_blank" class="btn btn-xs btn-outline-secondary px-2 py-1 small text-xs">
                        <i class="fa-solid fa-file-pdf me-1"></i>PDF
                    </a>
                </div>
                <form method="get" class="d-flex gap-2 flex-wrap">
                    <input type="hidden" name="tab" value="usuarios">
                    <input type="text" name="buscar_usuario" class="form-control form-control-sm" placeholder="Buscar email o alias..." value="<?= e($buscar_usuario) ?>" style="min-width: 200px;">
                    <select name="filtro_estado" class="form-select form-select-sm w-auto">
                        <option value="">Todos los estados</option>
                        <option value="active" <?= ($filtro_estado === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="pending" <?= ($filtro_estado === 'pending') ? 'selected' : '' ?>>Pending</option>
                        <option value="suspended" <?= ($filtro_estado === 'suspended') ? 'selected' : '' ?>>Suspended</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-dark px-3 fw-medium">Filtrar</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 80px;">ID</th>
                            <th style="width: 60px;">Foto</th>
                            <th>Perfil / Usuario</th>
                            <th>Correo Electrónico</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th class="text-end" style="min-width: 260px;">Acción de Moderación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): 
                            $roleId = (int)($u['role_id'] ?? 2);
                            $status = $u['status'] ?? 'pending';
                            $avatarUrl = !empty($u['avatar_media_id']) ? e($u['avatar_media_id']) : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
                        ?>
                        <tr>
                            <td class="text-muted fw-bold">#<?= (int)$u['id'] ?></td>
                            <td>
                                <img src="<?= $avatarUrl ?>" alt="Avatar" class="rounded-circle border" width="38" height="38" style="object-fit: cover;">
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= e($u['display_name'] ?? 'Sin Perfil') ?></div>
                                <small class="text-muted">@<?= e($u['username'] ?? 'sin_username') ?></small>
                            </td>
                            <td class="text-secondary fw-medium"><?= e($u['email']) ?></td>
                            <td>
<span class="badge badge-status-lg <?= ($roleId === 1) ? 'bg-danger-subtle text-danger' : 'bg-dark text-white' ?> border">
    <?= ($roleId === 1) ? 'Administrador' : 'Usuario' ?>
</span>
                            </td>
                            <td>
                                <span class="badge badge-status-lg <?php 
                                    echo match($status) {
                                        'active' => 'bg-success-subtle text-success',
                                        'pending' => 'bg-warning-subtle text-warning-emphasis',
                                        'suspended' => 'bg-dark-subtle text-dark-emphasis',
                                        default => 'bg-light text-muted'
                                    };
                                ?>">
                                    <?= e($status) ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex justify-content-end">
                                    <form method="post" class="d-flex align-items-center gap-2 m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <select name="role_id" class="form-select form-select-sm w-auto">
                                            <option value="2" <?= ($roleId === 2) ? 'selected' : '' ?>>user</option>
                                            <option value="1" <?= ($roleId === 1) ? 'selected' : '' ?>>admin</option>
                                        </select>
                                        <select name="estado" class="form-select form-select-sm w-auto">
                                            <option value="active" <?= ($status === 'active') ? 'selected' : '' ?>>active</option>
                                            <option value="pending" <?= ($status === 'pending') ? 'selected' : '' ?>>pending</option>
                                            <option value="suspended" <?= ($status === 'suspended') ? 'selected' : '' ?>>suspended</option>
                                        </select>
                                        <button type="submit" name="accion" value="actualizar_usuario" class="btn btn-sm btn-success py-1.5" onclick="return confirm('¿Actualizar rol y estado de este usuario?');">
                                            <i class="fa-solid fa-floppy-disk"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tab_actual === 'sitios'): ?>
        <div class="panel-card tab-pane fade show active">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h5 fw-bold mb-0 text-dark">Lugares y Puntos de Interés</h2>
                    <a href="generar_reporte.php?tipo=sitios" target="_blank" class="btn btn-xs btn-outline-secondary px-2 py-1 small text-xs">
                        <i class="fa-solid fa-file-pdf me-1"></i>PDF
                    </a>
                </div>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="sitios">
                    <input type="text" name="buscar_lugar" class="form-control form-control-sm" placeholder="Buscar sitio o municipio..." value="<?= e($buscar_lugar) ?>">
                    <button type="submit" class="btn btn-sm btn-dark px-3">Buscar</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Nombre del Sitio</th>
                        <th>Ubicación</th>
                        <th>Subido por</th>
                        <th>Moderación</th>
                        <th>Visibilidad</th>
                        <th class="text-end" style="min-width: 320px;">Gestión de Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lugares as $l): ?>
                    <tr>
                        <td class="text-muted fw-bold">#<?= (int)$l['id'] ?></td>
                        <td><span class="fw-bold text-dark fs-6"><?= e($l['name']) ?></span></td>
                        <td class="text-secondary fw-medium"><i class="fa-solid fa-location-dot text-danger me-1 small"></i><?= e($l['city_name'] ?? 'Colombia') ?></td>
                        
                        <td>
                            <?php if (!empty($l['creador_email'])): ?>
                                <span class="badge bg-user-tag px-2 py-1.5 rounded d-inline-block">
                                    <i class="fa-regular fa-user me-1"></i><?= e($l['creador_username'] ?? explode('@', $l['creador_email'])[0]) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted px-2 py-1.5 border rounded">
                                    <i class="fa-solid fa-robot me-1"></i>Sistema
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge badge-status-lg <?php 
                                echo match($l['moderation_status']) {
                                    'approved' => 'bg-success-subtle text-success',
                                    'pending' => 'bg-warning-subtle text-warning-emphasis',
                                    'flagged' => 'bg-danger-subtle text-danger',
                                    'rejected' => 'bg-dark-subtle text-dark-emphasis',
                                    default => 'bg-light text-muted'
                                };
                             ?>">
                                <?= e($l['moderation_status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-status-lg <?= $l['status'] === 'active' ? 'bg-info-subtle text-info-emphasis' : 'bg-light text-muted' ?>">
                                <?= e($l['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex justify-content-end">
                                <form method="post" class="d-flex align-items-center gap-1.5 m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="lugar_id" value="<?= (int)$l['id'] ?>">

                                    <select name="moderation_status" class="form-select form-select-sm w-auto">
                                        <option value="pending" <?= $l['moderation_status'] === 'pending' ? 'selected' : '' ?>>pending</option>
                                        <option value="approved" <?= $l['moderation_status'] === 'approved' ? 'selected' : '' ?>>approved</option>
                                        <option value="flagged" <?= $l['moderation_status'] === 'flagged' ? 'selected' : '' ?>>flagged</option>
                                        <option value="rejected" <?= $l['moderation_status'] === 'rejected' ? 'selected' : '' ?>>rejected</option>
                                    </select>

                                    <select name="status" class="form-select form-select-sm w-auto">
                                        <option value="active" <?= $l['status'] === 'active' ? 'selected' : '' ?>>active</option>
                                        <option value="inactive" <?= $l['status'] === 'inactive' ? 'selected' : '' ?>>inactive</option>
                                    </select>

                                    <button type="submit" name="accion" value="cambiar_estado_lugar" class="btn btn-sm btn-success px-2 py-1.5" onclick="return confirm('¿Aplicar estos cambios al sitio turístico?');">Guardar</button>
                                    <button type="submit" name="accion" value="eliminar_lugar" class="btn btn-sm btn-outline-danger px-2 py-1.5" onclick="return confirm('¿Deseas eliminar este sitio por completo?');"><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tab_actual === 'comentarios'): ?>
        <div class="panel-card tab-pane fade show active">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h5 fw-bold mb-0 text-dark">Auditoría de Reseñas</h2>
                    <a href="generar_reporte.php?tipo=comentarios" target="_blank" class="btn btn-xs btn-outline-secondary px-2 py-1 small text-xs">
                        <i class="fa-solid fa-file-pdf me-1"></i>PDF
                    </a>
                </div>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="comentarios">
                    <input type="text" name="buscar_comentario" class="form-control form-control-sm" placeholder="Buscar por texto..." value="<?= e($buscar_comentario) ?>">
                    <button type="submit" class="btn btn-sm btn-dark px-3">Filtrar</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Autor</th>
                        <th>Sitio Destino</th>
                        <th>Comentario</th>
                        <th>Moderación</th>
                        <th class="text-end" style="min-width: 200px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($comentarios as $c): ?>
                    <tr>
                        <td class="text-muted fw-bold">#<?= (int)$c['id'] ?></td>
                        <td class="fw-semibold text-slate-800"><small><?= e($c['email'] ?? 'Anónimo') ?></small></td>
                        <td>
                            <div class="text-truncate text-secondary fw-medium small" style="max-width: 140px;">
                                <?= e($c['lugar_name'] ?? 'ID: '.$c['place_id']) ?>
                            </div>
                        </td>
                        <td class="text-wrap" style="max-width: 320px;">
                            <span class="text-muted font-monospace small">"<?= e($c['body']) ?>"</span>
                        </td>
                        <td>
                            <span class="badge badge-status-lg <?php 
                                echo match($c['moderation_status']) {
                                    'approved' => 'bg-success-subtle text-success',
                                    'pending' => 'bg-warning-subtle text-warning-emphasis',
                                    'hidden' => 'bg-slate-200 text-slate-600',
                                    'removed' => 'bg-danger-subtle text-danger',
                                    default => 'bg-light text-dark'
                                };
                            ?>">
                                <?= e($c['moderation_status'] ?? 'pending') ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex justify-content-end">
                                <form method="post" class="d-flex align-items-center gap-1.5 m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="comentario_id" value="<?= (int)$c['id'] ?>">

                                    <select name="moderation_status" class="form-select form-select-sm w-auto">
                                        <option value="pending" <?= ($c['moderation_status'] === 'pending') ? 'selected' : '' ?>>pending</option>
                                        <option value="approved" <?= ($c['moderation_status'] === 'approved') ? 'selected' : '' ?>>approved</option>
                                        <option value="hidden" <?= ($c['moderation_status'] === 'hidden') ? 'selected' : '' ?>>hidden</option>
                                        <option value="removed" <?= ($c['moderation_status'] === 'removed') ? 'selected' : '' ?>>removed</option>
                                    </select>

                                    <button type="submit" name="accion" value="moderar_comentario" class="btn btn-sm btn-success py-1.5">Aplicar</button>
                                    <button type="submit" name="accion" value="eliminar_comentario" class="btn btn-sm btn-outline-danger py-1.5" onclick="return confirm('¿Eliminar de la BD?');"><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>