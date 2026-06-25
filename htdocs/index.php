<?php
require_once __DIR__ . '/includes/functions.php';

// =========================================================================
// API INTERNA: OBTENER COMENTARIOS EN JSON (SOLO APROBADOS PARA EL PÚBLICO)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'obtener_comentarios' && isset($_GET['place_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $place_id = (int)$_GET['place_id'];
    
    try {
        $pdo = db();
        $sql = "SELECT 
                    c.body, 
                    c.created_at, 
                    COALESCE(p.display_name, p.username, 'Turista') AS user_name,
                    COALESCE(r.rating, 0) AS rating
                FROM place_comments c
                LEFT JOIN user_profiles p ON c.user_id = p.user_id
                LEFT JOIN place_ratings r ON r.place_id = c.place_id AND r.user_id = c.user_id
                WHERE c.place_id = :place_id AND (c.status = 'aprobado' OR c.status = 'approved' OR c.moderation_status = 'approved')
                ORDER BY c.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':place_id' => $place_id]);
        $comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($comentarios, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

$pageTitle   = APP_NOMBRE . ' | Inicio';

$todos_los_sitios = lugares_destacados(32);
$destacados  = array_slice($todos_los_sitios, 0, 8);
$mas_sitios  = array_slice($todos_los_sitios, 8);

$conteos     = conteo_dashboard();
$placesJson  = json_encode($todos_los_sitios, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$status_success = false;
$status_pending = false;
$error_msg = null;

// =========================================================================
    // PROCESAMIENTO SEGURO Y DEFINITIVO DEL FORMULARIO DE COMENTARIOS
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_comentario') {
        if (usuario_autenticado()) {

            $user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['usuario']['id'] ?? $_SESSION['user']['id'] ?? null;

            $place_id     = isset($_POST['place_id']) ? (int)$_POST['place_id'] : 0;
            $rating_value = isset($_POST['rating_value']) ? (int)$_POST['rating_value'] : 0;
            $comment_text = isset($_POST['comment_text']) ? trim($_POST['comment_text']) : '';

            if (empty($user_id)) {
                $error_msg = "Error de Sesión: No se encontró tu ID de usuario en el sistema.";
            } elseif ($place_id <= 0) {
                $error_msg = "Error Técnico: El ID del sitio turístico llegó vacío.";
            } elseif ($rating_value < 1 || $rating_value > 5) {
                $error_msg = "Error de Formulario: Por favor selecciona una calificación en estrellas.";
            } elseif ($comment_text === '') {
                $error_msg = "Error de Formulario: El campo de comentario no puede estar vacío.";
            } else {
                $pdo = null;
                try {
                    $pdo = db();
                    $pdo->beginTransaction();

                    $stmtPlace = $pdo->prepare("SELECT rating_sum, rating_count FROM places WHERE id = ? FOR UPDATE");
                    $stmtPlace->execute([$place_id]);
                    $placeStats = $stmtPlace->fetch(PDO::FETCH_ASSOC);

                    if (!$placeStats) {
                        throw new Exception('El sitio turístico no existe.');
                    }

                    $stmtCheck = $pdo->prepare("SELECT id, rating FROM place_ratings WHERE place_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
                    $stmtCheck->execute([$place_id, $user_id]);
                    $ratingRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                    $oldRating = $ratingRow ? (int)$ratingRow['rating'] : false;

                    $stmtComment = $pdo->prepare("INSERT INTO place_comments (place_id, user_id, body, status, moderation_status, created_at, updated_at) VALUES (?, ?, ?, 'pending', 'pending', NOW(), NOW())");
                    $stmtComment->execute([$place_id, $user_id, $comment_text]);

                    $currentSum = (float)($placeStats['rating_sum'] ?? 0);
                    $currentCount = (int)($placeStats['rating_count'] ?? 0);

                    if ($oldRating !== false && $ratingRow) {
                        $newSum = $currentSum + ((float)$rating_value - (float)$oldRating);
                        $newCount = $currentCount;

                        $stmtRating = $pdo->prepare("UPDATE place_ratings SET rating = ?, updated_at = NOW() WHERE id = ?");
                        $stmtRating->execute([$rating_value, (int)$ratingRow['id']]);
                    } else {
                        $newSum = $currentSum + (float)$rating_value;
                        $newCount = $currentCount + 1;

                        $stmtRating = $pdo->prepare("INSERT INTO place_ratings (place_id, user_id, rating, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
                        $stmtRating->execute([$place_id, $user_id, $rating_value]);
                    }

                    $newAverage = $newCount > 0 ? ($newSum / $newCount) : 0;

                    $stmtUpdate = $pdo->prepare("UPDATE places SET rating_sum = ?, rating_count = ?, average_rating = ?, updated_at = NOW() WHERE id = ?");
                    $stmtUpdate->execute([$newSum, $newCount, $newAverage, $place_id]);

                    $pdo->commit();

                    header("Location: index.php?msg=pending&open_place=" . $place_id . "#destacados");
                    exit;

                } catch (Throwable $e) {
                    if ($pdo instanceof PDO && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    die("Error de Base de Datos al guardar: " . $e->getMessage());
                }
            }
        } else {
            $error_msg = "Acción denegada. Debes iniciar sesión primero para comentar.";
        }
    }
if (isset($_GET['msg']) && $_GET['msg'] === 'pending') {
    $status_pending = true;
}
if (isset($_GET['msg']) && $_GET['msg'] === 'success') {
    $status_success = true;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    :root {
        --orange-primary: #ff6f00;
        --orange-hover: #e65100;
        --blur-bg: rgba(255, 255, 255, 0.7);
        --radius-premium: 24px;
        --radius-inner: 16px;
    }
    
    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f3f4f6;
    }

    /* ==========================================
       1. HERO SPLIT DRÁSTICO (PANTALLA COMPLETA)
       ========================================== */
    .hero-split {
        min-height: calc(100vh - 56px);
        display: flex;
        align-items: stretch;
        background: #ffffff;
        overflow: hidden;
    }
    .hero-left-content {
        padding: 5rem 3.5rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .hero-right-map {
        background: #e5e7eb;
        position: relative;
        min-height: 450px;
    }
    #mapa {
        height: 100%;
        width: 100%;
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        right: 0;
    }

    /* Floating Search Panel inside Map */
    .floating-search-card {
        position: absolute;
        top: 20px;
        left: 20px;
        right: 20px;
        z-index: 1000;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(255,255,255,0.6);
        border-radius: 16px;
        padding: 0.75rem;
        box-shadow: 0 12px 32px rgba(0,0,0,0.12);
    }

    /* ==========================================
       2. COMPONENTES ULTRA MODERNOS
       ========================================== */
    .btn-orange {
        background-color: var(--orange-primary);
        color: white;
        border: none;
        font-weight: 600;
        border-radius: 12px;
        padding: 0.8rem 2rem;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .btn-orange:hover {
        background-color: var(--orange-hover);
        color: white;
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(255, 111, 0, 0.3);
    }
    .text-orange {
        color: var(--orange-primary);
    }
    .badge-soft {
        background-color: rgba(255, 111, 0, 0.08);
        color: var(--orange-primary);
        font-weight: 700;
        font-size: 0.75rem;
        padding: 0.5rem 1.2rem;
        border-radius: 50px;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        display: inline-block;
    }
    
    .stats-pill {
        background: #f8f9fa;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 0.75rem 1.5rem;
    }

    /* ==========================================
       3. INTERFAZ DE TARJETAS INMERSIVAS (OVERLAYS)
       ========================================== */
    .card-turismo {
        position: relative;
        border: none;
        border-radius: var(--radius-premium);
        overflow: hidden;
        height: 400px;
        background: #212529;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .card-img-bg {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-size: center;
        background-position: center;
        transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }
    /* Gradiente dinámico oscuro para que las letras se lean de forma perfecta */
    .card-gradient-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(180deg, rgba(0,0,0,0.2) 0%, rgba(0,0,0,0.4) 50%, rgba(0,0,0,0.85) 100%);
        z-index: 1;
    }
    .card-content-floating {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        padding: 2rem;
        z-index: 2;
        color: white;
    }
    .card-turismo:hover {
        transform: translateY(-8px) scale(1.01);
        box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    }
    .card-turismo:hover .card-img-bg {
        transform: scale(1.1);
    }
    
    .premium-badge-top {
        position: absolute;
        top: 20px;
        left: 20px;
        z-index: 2;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(255,255,255,0.2);
        color: #ffc107;
        padding: 0.4rem 0.8rem;
        border-radius: 30px;
        font-weight: 600;
        font-size: 0.8rem;
    }
    .premium-price-top {
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 2;
        background: rgba(255, 255, 255, 0.95);
        color: #111;
        padding: 0.4rem 0.8rem;
        border-radius: 30px;
        font-weight: 700;
        font-size: 0.8rem;
    }

    /* ==========================================
       4. VENTANAS MODALES PREMIUM
       ========================================== */
    .modal-content {
        border-radius: var(--radius-premium);
        border: none;
    }
    .comments-container {
        max-height: 300px;
        overflow-y: auto;
    }
    .rating-select label {
        font-size: 1.8rem;
        color: #e5e7eb;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .rating-select input:checked ~ label,
    .rating-select label:hover,
    .rating-select label:hover ~ label {
        color: #ffc107;
    }
</style>

<!-- CONTENEDOR HERO DIVISIONAL DRÁSTICO -->
<section class="hero-split border-bottom border-light">
    <div class="container-fluid p-0">
        <div class="row g-0 h-100">
            
            <!-- CONTENIDO DE TEXTOS (IZQUIERDA) -->
            <div class="col-lg-5 bg-white d-flex align-items-center">
                <div class="hero-left-content w-100">
                    <span class="badge badge-soft mb-3 align-self-start">Turismo inteligente en Colombia</span>
                    <h1 class="fw-extrabold text-dark mb-4 display-6 lh-sm" style="letter-spacing: -0.03em;">
                        Mapeo, seguridad y <br><span class="text-orange">experiencias</span> turísticas.
                    </h1>
                    <p class="text-muted mb-4 fs-5">Descubre destinos validados por la comunidad y viaja con total tranquilidad por todo el territorio nacional.</p>
                    
                    <div class="d-flex flex-wrap gap-3 mb-5">
                        <a href="#destacados" class="btn btn-orange shadow-sm"><i class="fa-solid fa-compass me-2"></i>Explorar Catálogo</a>
                        <?php if (!usuario_autenticado()): ?>
                            <a href="<?= e(url('register.php')) ?>" class="btn btn-outline-dark border-2 rounded-3 px-4">Crear cuenta</a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-4">
                            <div class="stats-pill text-center">
                                <div class="h4 fw-bold text-dark mb-0"><?= (int)$conteos['usuarios'] ?></div>
                                <small class="text-muted">Exploradores</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="stats-pill text-center">
                                <div class="h4 fw-bold text-dark mb-0"><?= (int)$conteos['sitios'] ?></div>
                                <small class="text-muted">Destinos</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="stats-pill text-center">
                                <div class="h4 fw-bold text-dark mb-0"><?= (int)$conteos['comentarios'] ?></div>
                                <small class="text-muted">Reseñas</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- MAPA INMERSIVO COMPLETO (DERECHA) -->
            <div class="col-lg-7 hero-right-map">
                <!-- Buscador Flotante Estilo Airbnb/Maps -->
                <div class="floating-search-card">
                    <div class="input-group search-wrapper">
                        <span class="input-group-text bg-transparent border-0 text-muted"><i class="fa-solid fa-magnifying-glass-location fs-5 text-orange"></i></span>
                        <input type="text" id="buscarSitio" class="form-control bg-transparent border-0 shadow-none ps-2" placeholder="Escribe un departamento, ciudad o lugar..." autocomplete="off">
                        <button class="btn btn-link text-muted border-0 d-none" type="button" id="limpiarBusqueda"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <div id="resultadosBusqueda" class="list-group d-none" style="max-height: 220px; overflow-y: auto; margin-top: 8px; border:none;"></div>
                </div>

                <div id="mapa"></div>
            </div>

        </div>
    </div>
</section>

<!-- SECCIÓN GRIDS DE SITIOS (DISEÑO INMERSIVO PANORÁMICO) -->
<section id="destacados" class="py-5">
    <div class="container py-4">

        <?php if (!empty($status_pending)): ?>
            <div class="alert alert-warning alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4 p-3" role="alert">
                <i class="fa-solid fa-clock me-2"></i> ¡Gracias! Tu comentario ha sido enviado y quedará en revisión.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($status_success)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4 p-3" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> ¡Tu reseña ha sido publicada con éxito!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4 p-3" role="alert">
                <i class="fa-solid fa-circle-xmark me-2"></i> <?= htmlspecialchars($error_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="mb-5 text-center text-md-start">
            <span class="badge badge-soft mb-2">Selección de la comunidad</span>
            <h2 class="fw-extrabold text-dark">Destinos Recomendados</h2>
        </div>

        <div class="row g-4">
            <?php if ($destacados): ?>
                <?php foreach ($destacados as $sitio): 
                    $imagen_src = !empty($sitio['storage_url']) ? e($sitio['storage_url']) : "https://picsum.photos/id/".((int)$sitio['id'] + 10)."/400/400";
                ?>
                    <div class="col-md-6 col-lg-3">
                        <div 
                            class="card card-turismo sitio-card"
                            id="card-sitio-<?= (int)$sitio['id'] ?>"
                            data-id="<?= (int)$sitio['id'] ?>"
                            data-name="<?= e($sitio['name']) ?>"
                            data-description="<?= e($sitio['description']) ?>"
                            data-city="<?= e($sitio['city_name'] ?? '') ?>"
                            data-department="<?= e($sitio['department_name'] ?? '') ?>"
                            data-address="<?= e($sitio['address_text'] ?? '') ?>"
                            data-rating="<?= (float)$sitio['average_rating'] ?>"
                            data-reviews="<?= (int)$sitio['rating_count'] ?>"
                            data-cost="<?= $sitio['entry_cost'] ?>"
                            data-currency="<?= e($sitio['currency_code']) ?>"
                        >
                            <!-- Imagen e incrustaciones -->
                            <div class="card-img-bg" style="background: url('<?= $imagen_src ?>') center/cover no-repeat;"></div>
                            <div class="card-gradient-overlay"></div>
                            
                            <span class="premium-badge-top">
                                <i class="fa-solid fa-star me-1"></i><?= number_format((float)$sitio['average_rating'], 1) ?>
                            </span>
                            <span class="premium-price-top">
                                <?= $sitio['entry_cost'] !== null && $sitio['entry_cost'] > 0 ? '$' . number_format((float)$sitio['entry_cost'], 0, ',', '.') : 'Gratis' ?>
                            </span>

                            <!-- Contenido Inferior -->
                            <div class="card-content-floating">
                                <span class="badge bg-light text-dark mb-2 px-2 py-1 small fw-bold" style="font-size:0.65rem;">
                                    <i class="fa-solid fa-location-dot me-1 text-orange"></i><?= e($sitio['city_name'] ?? 'Municipio') ?>
                                </span>
                                <h4 class="h5 fw-bold text-white text-truncate mb-1"><?= e($sitio['name']) ?></h4>
                                <p class="text-white-50 small mb-0 text-truncate-2" style="font-size:0.8rem; opacity:0.85;"><?= e(mb_strimwidth((string)$sitio['description'], 0, 85, '...')) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- SECCIÓN OTROS DESTINOS -->
<?php if (!empty($mas_sitios)): ?>
<section class="py-5 bg-light border-top border-light">
    <div class="container">
        <div class="mb-5">
            <span class="badge badge-soft mb-2">Más por descubrir</span>
            <h3 class="fw-bold text-dark h4">Otros rincones increíbles</h3>
        </div>
        
        <div class="row g-4">
            <?php foreach ($mas_sitios as $sitio): 
                $imagen_src_mas = !empty($sitio['storage_url']) ? e($sitio['storage_url']) : "https://picsum.photos/id/".((int)$sitio['id'] + 15)."/400/400";
            ?>
                <div class="col-md-6 col-lg-3">
                    <div 
                        class="card card-turismo sitio-card"
                        id="card-sitio-<?= (int)$sitio['id'] ?>"
                        data-id="<?= (int)$sitio['id'] ?>"
                        data-name="<?= e($sitio['name']) ?>"
                        data-description="<?= e($sitio['description']) ?>"
                        data-city="<?= e($sitio['city_name'] ?? '') ?>"
                        data-department="<?= e($sitio['department_name'] ?? '') ?>"
                        data-address="<?= e($sitio['address_text'] ?? '') ?>"
                        data-rating="<?= (float)$sitio['average_rating'] ?>"
                        data-reviews="<?= (int)$sitio['rating_count'] ?>"
                        data-cost="<?= $sitio['entry_cost'] ?>"
                        data-currency="<?= e($sitio['currency_code']) ?>"
                    >
                        <div class="card-img-bg" style="background: url('<?= $imagen_src_mas ?>') center/cover no-repeat;"></div>
                        <div class="card-gradient-overlay"></div>
                        
                        <span class="premium-badge-top"><i class="fa-solid fa-star me-1"></i><?= number_format((float)$sitio['average_rating'], 1) ?></span>
                        <span class="premium-price-top"><?= $sitio['entry_cost'] !== null && $sitio['entry_cost'] > 0 ? '$' . number_format((float)$sitio['entry_cost'], 0, ',', '.') : 'Gratis' ?></span>

                        <div class="card-content-floating">
                            <span class="badge bg-light text-dark mb-2 px-2 py-1 small fw-bold" style="font-size:0.65rem;"><i class="fa-solid fa-location-dot me-1 text-orange"></i><?= e($sitio['city_name'] ?? 'Municipio') ?></span>
                            <h4 class="h5 fw-bold text-white text-truncate mb-1"><?= e($sitio['name']) ?></h4>
                            <p class="text-white-50 small mb-0 text-truncate-2" style="font-size:0.8rem;"><?= e(mb_strimwidth((string)$sitio['description'], 0, 85, '...')) ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- MODAL DE INFORMACIÓN (MODERNO E HIGIENIZADO) -->
<div class="modal fade" id="modalSitio" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content overflow-hidden shadow-lg">
      <div class="modal-header border-0 pb-0 px-4 pt-4">
        <h4 class="modal-title fw-extrabold text-dark" id="modalTitle">Información del sitio</h4>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <div class="row g-4">
            
            <div class="col-md-6 border-end border-light">
                <p id="modalUbicacion" class="text-orange fw-bold small mb-1"></p>
                <p id="modalDireccion" class="text-muted small mb-4"></p>
                <div class="p-3 bg-light rounded-4 mb-4">
                    <h6 class="fw-bold text-secondary mb-2 small" style="letter-spacing: 0.05em; text-transform:uppercase;">Descripción breve</h6>
                    <p id="modalDescripcion" class="text-dark small lh-base mb-0" style="text-align:justify;"></p>
                </div>
                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-4 border">
                  <span class="small text-secondary"><strong>Entrada:</strong> <span id="modalPrecio" class="ms-1 badge bg-dark px-2 py-1"></span></span>
                  <span class="small text-secondary"><strong>Score:</strong> ⭐ <span id="modalRating" class="fw-bold text-dark"></span> (<span id="modalCount"></span> opiniones)</span>
                </div>
            </div>

            <div class="col-md-6 d-flex flex-column justify-content-between">
                <div>
                    <h5 class="fw-bold text-dark h6 mb-3"><i class="fa-solid fa-feather text-orange me-2"></i>Opiniones</h5>
                    <div class="comments-container mb-2" id="modalCommentsList"></div>
                </div>

                <div class="border-top pt-3">
                    <?php if (usuario_autenticado()): ?>
                        <form action="" method="POST" id="formComentar" class="p-2 rounded-3 bg-light border">
                            <input type="hidden" name="action" value="guardar_comentario">
                            <input type="hidden" name="place_id" id="formCommentPlaceId" value="">

                            <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                                <label class="small fw-semibold text-secondary">Calificar destino:</label>
                                <div class="rating-select">
                                    <input type="radio" id="star5" name="rating_value" value="5" required/><label for="star5" title="5 estrellas"><i class="fa-solid fa-star"></i></label>
                                    <input type="radio" id="star4" name="rating_value" value="4" /><label for="star4" title="4 estrellas"><i class="fa-solid fa-star"></i></label>
                                    <input type="radio" id="star3" name="rating_value" value="3" /><label for="star3" title="3 estrellas"><i class="fa-solid fa-star"></i></label>
                                    <input type="radio" id="star2" name="rating_value" value="2" /><label for="star2" title="2 estrellas"><i class="fa-solid fa-star"></i></label>
                                    <input type="radio" id="star1" name="rating_value" value="1" /><label for="star1" title="1 estrella"><i class="fa-solid fa-star"></i></label>
                                </div>
                            </div>

                            <div class="mb-2">
                                <textarea class="form-control form-control-sm border-0 shadow-none ps-2" name="comment_text" rows="2" placeholder="Escribe tu experiencia personal aquí..." required></textarea>
                            </div>

                            <button type="submit" class="btn btn-orange btn-sm w-100 py-2">
                                <i class="fa-solid fa-paper-plane me-1"></i> Publicar Reseña
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-secondary text-center small mb-0 p-3 rounded-4 border-0">
                            <i class="fa-solid fa-lock text-muted me-1"></i> Debes <a href="<?= e(url('login.php')) ?>" class="fw-bold text-orange text-decoration-none">iniciar sesión</a> para comentar.
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
    window.turSegInfoPlaces = <?= $placesJson ?: '[]' ?>;

    document.addEventListener('DOMContentLoaded', function() {
        
        const coordenadasReales = {
            1: [4.60155, -74.07212], 2: [4.53611, -74.09500], 3: [4.59805, -74.07604],
            4: [4.64861, -74.06250], 5: [4.69742, -74.14111], 6: [4.65481, -74.07849],
            7: [4.60555, -74.05537], 8: [4.65750, -74.09340], 9: [4.60222, -74.06833],
            10: [4.56381, -74.11674], 11: [4.59611, -74.07472], 12: [4.61583, -74.06833]
        };

        const defaultLat = 4.60971;
        const defaultLng = -74.08175;
        const map = L.map('mapa', { zoomControl: false }).setView([defaultLat, defaultLng], 12);
        
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        const markersGroup = new L.featureGroup();
        const marcadoresSitios = {};

        window.turSegInfoPlaces.forEach((sitio) => {
            let lat = sitio.lat ? parseFloat(sitio.lat) : (sitio.geo_point?.coordinates?.[1] || coordenadasReales[sitio.id]?.[0] || null);
            let lng = sitio.lng ? parseFloat(sitio.lng) : (sitio.geo_point?.coordinates?.[0] || coordenadasReales[sitio.id]?.[1] || null);

            if (lat !== null && lng !== null) {
                const marker = L.marker([lat, lng]);

                let precioTxt = 'Gratis';
                if (sitio.entry_cost && parseFloat(sitio.entry_cost) > 0) {
                    precioTxt = `$${parseFloat(sitio.entry_cost).toLocaleString('co-CO')} ${sitio.currency_code}`;
                }

                const popupContenido = `
                    <div style="min-width: 170px; font-family: 'Poppins', sans-serif;">
                        <div class="map-popup-title">${sitio.name}</div>
                        <div class="map-popup-info">
                            <div class="mb-1">⭐ <b>${parseFloat(sitio.average_rating).toFixed(1)}</b> (${parseInt(sitio.rating_count)})</div>
                            <div>💵 <b>Precio:</b> ${precioTxt}</div>
                        </div>
                        <button class="btn btn-orange btn-sm w-100 py-1" style="font-size: 0.75rem; border-radius: 6px;" onclick="document.getElementById('card-sitio-${sitio.id}').click();">
                            Ver detalles
                        </button>
                    </div>
                `;

                marker.bindPopup(popupContenido);
                markersGroup.addLayer(marker);
                marcadoresSitios[sitio.id] = marker;
            }
        });

        map.addLayer(markersGroup);
        if (markersGroup.getLayers().length > 0) {
            map.fitBounds(markersGroup.getBounds().pad(0.1));
        }

        // --- BARRA FLUÍDA FLOTANTE ---
        const inputBuscar = document.getElementById('buscarSitio');
        const listaResultados = document.getElementById('resultadosBusqueda');
        const btnLimpiar = document.getElementById('limpiarBusqueda');

        inputBuscar.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
            listaResultados.innerHTML = '';

            if (query === '') {
                listaResultados.classList.add('d-none');
                btnLimpiar.classList.add('d-none');
                return;
            }

            btnLimpiar.classList.remove('d-none');

            const sitiosFiltrados = window.turSegInfoPlaces.filter(sitio => {
                const nombre = (sitio.name || '').toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                const ciudad = (sitio.city_name || '').toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                const depto = (sitio.department_name || '').toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                return nombre.includes(query) || ciudad.includes(query) || depto.includes(query);
            });

            if (sitiosFiltrados.length === 0) {
                listaResultados.innerHTML = `<div class="list-group-item text-muted small py-3 text-center border-0">No hay resultados</div>`;
                listaResultados.classList.remove('d-none');
                return;
            }

            sitiosFiltrados.slice(0, 5).forEach(sitio => {
                const botonItem = document.createElement('button');
                botonItem.type = 'button';
                botonItem.className = 'list-group-item list-group-item-action text-start small py-2 px-3 d-flex justify-content-between align-items-center border-0 border-bottom';
                botonItem.innerHTML = `
                    <div>
                        <strong class="text-dark d-block">${sitio.name}</strong>
                        <span class="text-muted" style="font-size:0.75rem;"><i class="fa-solid fa-location-dot me-1"></i>${sitio.city_name || 'Municipio'}</span>
                    </div>
                    <span class="badge" style="background: rgba(255,111,0,0.1); color: #ff6f00;">⭐ ${parseFloat(sitio.average_rating).toFixed(1)}</span>
                `;

                botonItem.addEventListener('click', function() {
                    inputBuscar.value = sitio.name;
                    listaResultados.classList.add('d-none');
                    
                    const marcador = marcadoresSitios[sitio.id];
                    if (marcador) {
                        map.setView(marcador.getLatLng(), 15);
                        marcador.openPopup();
                        
                        const tarjeta = document.getElementById('card-sitio-' + sitio.id);
                        if (tarjeta) {
                            tarjeta.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            tarjeta.style.outline = "3px solid var(--orange-primary)";
                            setTimeout(() => { tarjeta.style.outline = "none"; }, 2000);
                        }
                    }
                });

                listaResultados.appendChild(botonItem);
            });

            listaResultados.classList.remove('d-none');
        });

        btnLimpiar.addEventListener('click', function() {
            inputBuscar.value = '';
            listaResultados.innerHTML = '';
            listaResultados.classList.add('d-none');
            this.classList.add('d-none');
            if (markersGroup.getLayers().length > 0) {
                map.fitBounds(markersGroup.getBounds().pad(0.1));
            }
        });

        document.addEventListener('click', function(e) {
            if (!inputBuscar.contains(e.target) && !listaResultados.contains(e.target)) {
                listaResultados.classList.add('d-none');
            }
        });

        // --- MANEJO DE MODAL ---
        function cargarDatosModalYMostrar(cardElement) {
            const id = cardElement.getAttribute('data-id');
            const name = cardElement.getAttribute('data-name');
            const description = cardElement.getAttribute('data-description');
            const city = cardElement.getAttribute('data-city');
            const dept = cardElement.getAttribute('data-department');
            const address = cardElement.getAttribute('data-address');
            const rating = parseFloat(cardElement.getAttribute('data-rating')).toFixed(1);
            const reviews = cardElement.getAttribute('data-reviews');
            const cost = cardElement.getAttribute('data-cost');
            const currency = cardElement.getAttribute('data-currency');

            let priceText = 'Gratis';
            if (cost && parseFloat(cost) > 0) {
                priceText = `$${parseFloat(cost).toLocaleString('co-CO')} ${currency}`;
            }

            document.getElementById('modalTitle').innerText = name;
            document.getElementById('modalUbicacion').innerHTML = `<i class="fa-solid fa-map-pin me-1"></i>${dept} — ${city}`;
            document.getElementById('modalDireccion').innerHTML = `<i class="fa-solid fa-location-arrow me-1"></i>Dirección: ${address || 'No especificada'}`;
            document.getElementById('modalDescripcion').innerText = description;
            document.getElementById('modalPrecio').innerText = priceText;
            document.getElementById('modalRating').innerText = rating;
            document.getElementById('modalCount').innerText = reviews;

            const hiddenInput = document.getElementById('formCommentPlaceId');
            if (hiddenInput) { hiddenInput.value = id; }

            const commentsContainer = document.getElementById('modalCommentsList');
            commentsContainer.innerHTML = '<div class="text-center py-4 text-muted small"><i class="fa-solid fa-circle-notch fa-spin me-2 text-orange"></i>Buscando opiniones...</div>';

            fetch(`index.php?action=obtener_comentarios&place_id=${id}`)
                .then(response => response.json())
                .then(comentarios => {
                    commentsContainer.innerHTML = '';
                    
                    if (!comentarios || comentarios.length === 0) {
                        commentsContainer.innerHTML = '<div class="text-center py-4 text-muted small"><i class="fa-regular fa-comment-dots d-block mb-2 fs-3 text-black-50"></i>Sin opiniones. ¡Escribe la primera!</div>';
                        return;
                    }

                    comentarios.forEach(com => {
                        const dateObj = new Date(com.created_at);
                        const dateStr = dateObj.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' });
                        
                        const userRating = parseInt(com.rating || 0);
                        let starsHtml = '';
                        for (let i = 1; i <= 5; i++) {
                            starsHtml += `<i class="fa-solid fa-star ${i <= userRating ? 'text-warning' : 'text-black-50'} opacity-50" style="font-size:0.7rem; margin-right:1px;"></i>`;
                        }

                        const commentItem = document.createElement('div');
                        commentItem.className = 'p-3 rounded-4 bg-light mb-2 small border';
                        commentItem.innerHTML = `
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="text-dark"><i class="fa-solid fa-user-astronaut text-orange me-1"></i>${com.user_name}</strong>
                                <span class="text-muted text-uppercase fw-bold" style="font-size: 0.65rem;">${dateStr}</span>
                            </div>
                            <div class="mb-2">${starsHtml}</div>
                            <p class="mb-0 text-secondary lh-sm" style="font-size:0.85rem;">${com.body}</p>
                        `;
                        commentsContainer.appendChild(commentItem);
                    });
                })
                .catch(err => {
                    console.error("Error cargando comentarios:", err);
                    commentsContainer.innerHTML = '<div class="text-center text-danger small py-2">Error de sincronización.</div>';
                });

            const bootstrapModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSitio'));
            bootstrapModal.show();
        }

        const cards = document.querySelectorAll('.sitio-card');
        cards.forEach(card => {
            card.addEventListener('click', function() {
                cargarDatosModalYMostrar(this);
            });
        });

        const urlParams = new URLSearchParams(window.location.search);
        const openPlaceId = urlParams.get('open_place');
        if (openPlaceId) {
            const targetCard = document.getElementById('card-sitio-' + openPlaceId);
            if (targetCard) {
                cargarDatosModalYMostrar(targetCard);
            }
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>