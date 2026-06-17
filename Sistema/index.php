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
        // CORREGIDO: Se busca tanto por 'approved' como por 'aprobado' para máxima compatibilidad con la BD
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
            try {
                $pdo = db(); 

                // Verificamos si ya existía una calificación previa
                $stmtCheck = $pdo->prepare("SELECT rating FROM place_ratings WHERE place_id = ? AND user_id = ?");
                $stmtCheck->execute([$place_id, $user_id]);
                $oldRating = $stmtCheck->fetchColumn();

                // 1. Insertar el comentario guardándolo en estado 'pendiente' y moderation_status 'pending' para el panel
                $stmtComment = $pdo->prepare("INSERT INTO place_comments (place_id, user_id, body, status, moderation_status, created_at) VALUES (?, ?, ?, 'pendiente', 'pending', NOW())");
                $stmtComment->execute([$place_id, $user_id, $comment_text]);

                // 2. Insertar o actualizar la calificación
                if ($oldRating !== false) {
                    $stmtRating = $pdo->prepare("UPDATE place_ratings SET rating = ?, created_at = NOW() WHERE place_id = ? AND user_id = ?");
                    $stmtRating->execute([$rating_value, $place_id, $user_id]);
                } else {
                    $stmtRating = $pdo->prepare("INSERT INTO place_ratings (place_id, user_id, rating, created_at) VALUES (?, ?, ?, NOW())");
                    $stmtRating->execute([$rating_value, $place_id, $user_id]);
                }

                // Sincronizar estadísticas globales del sitio
                if ($oldRating !== false) {
                    $diferencia = $rating_value - (int)$oldRating;
                    $stmtUpdate = $pdo->prepare("UPDATE places SET rating_sum = rating_sum + ?, average_rating = (rating_sum) / (rating_count) WHERE id = ?");
                    $stmtUpdate->execute([$diferencia, $place_id]);
                } else {
                    $stmtUpdate = $pdo->prepare("UPDATE places SET rating_sum = rating_sum + ?, rating_count = rating_count + 1, average_rating = (rating_sum) / (rating_count) WHERE id = ?");
                    $stmtUpdate->execute([$rating_value, $place_id]);
                }
                
                // Redirección avisando que está en espera de aprobación
                header("Location: index.php?msg=pending&open_place=" . $place_id . "#destacados");
                exit;

            } catch (Exception $e) {
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
    }
    .btn-orange {
        background-color: var(--orange-primary);
        color: white;
        border: none;
        transition: all 0.3s ease;
    }
    .btn-orange:hover {
        background-color: var(--orange-hover);
        color: white;
        transform: translateY(-2px);
    }
    .text-orange {
        color: var(--orange-primary);
    }
    .badge-soft {
        background-color: rgba(255, 111, 0, 0.1);
        color: var(--orange-primary);
        font-weight: 600;
        padding: 0.5rem 1rem;
        border-radius: 50px;
    }
    .card-turismo {
        border: none;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        background: #ffffff;
    }
    .card-turismo:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 30px rgba(0, 0, 0, 0.12) !important;
    }
    .card-placeholder-img {
        height: 160px;
        background: linear-gradient(135deg, #ff9e40 0%, #ff6f00 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
    }
    .card-placeholder-img i {
        font-size: 3rem;
        color: rgba(255, 255, 255, 0.8);
    }
    .price-badge {
        position: absolute;
        bottom: 12px;
        right: 12px;
        background: rgba(255, 255, 255, 0.95);
        color: #212529;
        padding: 0.4rem 0.8rem;
        font-weight: 700;
        border-radius: 8px;
        font-size: 0.85rem;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    }
    .rating-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        background: rgba(33, 37, 41, 0.85);
        backdrop-filter: blur(4px);
        color: #ffc107;
        padding: 0.3rem 0.6rem;
        font-weight: 600;
        border-radius: 6px;
        font-size: 0.80rem;
    }
    .rating-select {
        display: flex;
        flex-direction: row-reverse;
        justify-content: flex-end;
        gap: 4px;
    }
    .rating-select input {
        display: none;
    }
    .rating-select label {
        font-size: 1.5rem;
        color: #ddd;
        cursor: pointer;
        transition: color 0.2s ease;
    }
    .rating-select input:checked ~ label,
    .rating-select label:hover,
    .rating-select label:hover ~ label {
        color: #ffc107;
    }
    .comments-container {
        max-height: 220px;
        overflow-y: auto;
        padding-right: 5px;
    }
    .comments-container::-webkit-scrollbar {
        width: 5px;
    }
    .comments-container::-webkit-scrollbar-thumb {
        background: #e0e0e0;
        border-radius: 10px;
    }
    .leaflet-popup-content-wrapper {
        border-radius: 12px;
        padding: 4px;
    }
    .map-popup-title {
        font-weight: 700;
        color: #212529;
        margin-bottom: 4px;
        font-size: 0.95rem;
    }
    .map-popup-info {
        font-size: 0.8rem;
        color: #6c757d;
        margin-bottom: 8px;
    }
</style>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

<section class="hero-section bg-light position-relative overflow-hidden">
    <div class="container py-5">
        <div class="row align-items-center g-5 py-4">
            <div class="col-lg-6">
                <span class="badge badge-soft mb-3">Turismo inteligente en Colombia</span>
                <h1 class="display-5 fw-bold mb-3 lh-base">
                    Mapeo, seguridad y <span class="text-orange">experiencias</span> turísticas en un solo lugar.
                </h1>
                <p class="lead text-muted mb-4">Plataforma diseñada para turistas que quieran explorar Colombia de forma segura y confiable.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="#destacados" class="btn btn-orange btn-lg px-4 shadow-sm"><i class="fa-solid fa-mountain-sun me-2"></i>Ver sitios</a>
                    <?php if (!usuario_autenticado()): ?>
                        <a href="<?= e(url('register.php')) ?>" class="btn btn-outline-dark btn-lg px-4">Crear cuenta</a>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-4 mt-5 text-muted small flex-wrap bg-white p-3 rounded-3 shadow-sm d-inline-flex">
                    <div><i class="fa-solid fa-user-group text-orange me-1"></i><strong><?= (int)$conteos['usuarios'] ?></strong> usuarios</div>
                    <div class="vr"></div>
                    <div><i class="fa-solid fa-location-dot text-orange me-1"></i><strong><?= (int)$conteos['sitios'] ?></strong> sitios</div>
                    <div class="vr"></div>
                    <div><i class="fa-solid fa-comment text-orange me-1"></i><strong><?= (int)$conteos['comentarios'] ?></strong> comentarios</div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="hero-card shadow-lg rounded-4 overflow-hidden border-0 bg-white p-3">
                    <div class="hero-card-top d-flex justify-content-between align-items-center mb-3 px-2">
                        <span class="fw-bold"><i class="fa-solid fa-map text-orange me-2"></i>Mapa Interactivo</span>
                        <span class="badge bg-light text-dark border">Colombia</span>
                    </div>
                    <div id="mapa" class="mapa-home rounded-3" style="height: 380px; background: #e9ecef;"></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="destacados" class="py-5 bg-white">
    <div class="container py-3">

        <?php if (!empty($status_pending)): ?>
            <div class="alert alert-warning alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4" role="alert">
                <i class="fa-solid fa-clock me-2"></i> ¡Gracias! Tu comentario ha sido enviado y quedará visible tan pronto como un administrador lo apruebe.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($status_success)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> ¡Tu comentario y calificación han sido publicados con éxito!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4" role="alert">
                <i class="fa-solid fa-circle-xmark me-2"></i> <?= htmlspecialchars($error_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-end mb-5 flex-wrap gap-2">
            <div>
                <span class="badge badge-soft mb-2">Destinos Destacados</span>
                <h2 class="h3 fw-bold mb-0">Quizás te interesen</h2>
            </div>
        </div>

        <div class="row g-4">
            <?php if ($destacados): ?>
                <?php foreach ($destacados as $sitio): 
                    // ARREGLO DE IMÁGENES: Si storage_url está vacío o no se encuentra, usamos una imagen descriptiva de respaldo basada en el ID
                    $imagen_src = !empty($sitio['storage_url']) ? e($sitio['storage_url']) : "https://picsum.photos/id/".((int)$sitio['id'] + 10)."/400/250";
                ?>
                    <div class="col-md-6 col-lg-3">
                        <div 
                            class="card card-turismo h-100 shadow-sm sitio-card"
                            id="card-sitio-<?= (int)$sitio['id'] ?>"
                            style="cursor:pointer;"
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
                            <div class="card-placeholder-img" style="background: url('<?= $imagen_src ?>') center/cover no-repeat;">
                                <?php if (empty($sitio['storage_url'])): ?>
                                    <div class="w-100 h-100 style-overlay" style="background: rgba(0,0,0,0.25); position:absolute; top:0; left:0;"></div>
                                <?php endif; ?>
                                <span class="rating-badge">
                                    <i class="fa-solid fa-star me-1"></i>
                                    <?= number_format((float)$sitio['average_rating'], 1) ?>
                                </span>
                                <span class="price-badge">
                                    <?= $sitio['entry_cost'] !== null && $sitio['entry_cost'] > 0
                                        ? '$' . number_format((float)$sitio['entry_cost'], 0, ',', '.') . ' ' . e($sitio['currency_code'])
                                        : 'Gratis'
                                    ?>
                                </span>
                            </div>

                            <div class="card-body d-flex flex-column p-4">
                                <span class="text-uppercase text-orange fw-bold mb-1" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-map-pin me-1"></i><?= e($sitio['department_name'] ?? 'Colombia') ?>
                                </span>
                                <h5 class="card-title fw-bold text-dark mb-2 text-truncate"><?= e($sitio['name']) ?></h5>
                                <p class="text-muted small mb-3"><i class="fa-solid fa-location-dot me-1"></i><?= e($sitio['city_name'] ?? 'Municipio') ?></p>
                                <p class="card-text text-muted flex-grow-1 small lh-base"><?= e(mb_strimwidth((string)$sitio['description'], 0, 95, '...')) ?></p>
                                <div class="border-top pt-3 mt-2 d-flex justify-content-between align-items-center">
                                    <span class="small text-muted"><i class="fa-solid fa-comments me-1"></i><?= (int)$sitio['rating_count'] ?> opiniones</span>
                                    <span class="text-orange small fw-bold">Ver más <i class="fa-solid fa-arrow-right ms-1"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!empty($mas_sitios)): ?>
<section id="mas-destinos" class="py-5 bg-light border-top">
    <div class="container py-3">
        <div class="mb-4">
            <span class="badge badge-soft mb-2">Explora más</span>
            <h3 class="fw-bold mb-0">Otros destinos disponibles</h3>
        </div>
        <div class="row g-4">
            <?php foreach ($mas_sitios as $sitio): 
                $imagen_src_mas = !empty($sitio['storage_url']) ? e($sitio['storage_url']) : "https://picsum.photos/id/".((int)$sitio['id'] + 15)."/400/250";
            ?>
                <div class="col-md-6 col-lg-3">
                    <div 
                        class="card card-turismo h-100 shadow-sm sitio-card"
                        id="card-sitio-<?= (int)$sitio['id'] ?>"
                        style="cursor:pointer;"
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
                        <div class="card-placeholder-img" style="background: url('<?= $imagen_src_mas ?>') center/cover no-repeat;">
                            <?php if (empty($sitio['storage_url'])): ?>
                                <div class="w-100 h-100 style-overlay" style="background: rgba(0,0,0,0.25); position:absolute; top:0; left:0;"></div>
                            <?php endif; ?>
                            <span class="rating-badge"><i class="fa-solid fa-star me-1"></i><?= number_format((float)$sitio['average_rating'], 1) ?></span>
                            <span class="price-badge"><?= $sitio['entry_cost'] !== null && $sitio['entry_cost'] > 0 ? '$' . number_format((float)$sitio['entry_cost'], 0, ',', '.') . ' ' . e($sitio['currency_code']) : 'Gratis' ?></span>
                        </div>
                        <div class="card-body d-flex flex-column p-4">
                            <span class="text-uppercase text-orange fw-bold mb-1" style="font-size: 0.75rem;"><i class="fa-solid fa-map-pin me-1"></i><?= e($sitio['department_name'] ?? 'Colombia') ?></span>
                            <h5 class="card-title fw-bold text-dark mb-2 text-truncate"><?= e($sitio['name']) ?></h5>
                            <p class="text-muted small mb-3"><i class="fa-solid fa-location-dot me-1"></i><?= e($sitio['city_name'] ?? 'Municipio') ?></p>
                            <p class="card-text text-muted flex-grow-1 small lh-base"><?= e(mb_strimwidth((string)$sitio['description'], 0, 95, '...')) ?></p>
                            <div class="border-top pt-3 mt-2 d-flex justify-content-between align-items-center">
                                <span class="small text-muted"><i class="fa-solid fa-comments me-1"></i><?= (int)$sitio['rating_count'] ?> opiniones</span>
                                <span class="text-orange small fw-bold">Ver más <i class="fa-solid fa-arrow-right ms-1"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<div class="modal fade" id="modalSitio" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content rounded-4 border-0 shadow-lg">
      <div class="modal-header border-bottom-0 pb-0 px-4 pt-4">
        <h4 class="modal-title fw-bold text-dark" id="modalTitle">Información del sitio</h4>
      </div>
      <div class="modal-body p-4">
        <div class="row g-4">
            
            <div class="col-md-6 border-end">
                <p id="modalUbicacion" class="text-orange fw-bold small mb-2"></p>
                <p id="modalDireccion" class="text-muted small mb-3"></p>
                <div class="p-3 bg-light rounded-3 mb-3">
                    <h6 class="fw-bold text-secondary mb-1" style="font-size:0.85rem;">Sobre este lugar</h6>
                    <p id="modalDescripcion" class="text-dark small lh-base mb-0" style="text-align:justify;"></p>
                </div>
                <div class="d-flex justify-content-between align-items-center bg-white border p-2 px-3 rounded-3">
                  <span class="small text-muted"><strong>Precio:</strong> <span id="modalPrecio" class="ms-1 badge bg-dark"></span></span>
                  <span class="small text-muted"><strong>Global:</strong> ⭐ <span id="modalRating" class="fw-bold text-dark"></span> (<span id="modalCount"></span> opiniones)</span>
                </div>
            </div>

            <div class="col-md-6 d-flex flex-column justify-content-between">
                <div>
                    <h5 class="fw-bold text-dark h6 mb-3"><i class="fa-solid fa-comments text-orange me-2"></i>Comentarios de la Comunidad</h5>
                    <div class="comments-container mb-3" id="modalCommentsList"></div>
                </div>

                <div class="border-top pt-3">
                    <?php if (usuario_autenticado()): ?>
                        <form action="" method="POST" id="formComentar">
                            <input type="hidden" name="action" value="guardar_comentario">
                            <input type="hidden" name="place_id" id="formCommentPlaceId" value="">

                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary mb-1">Tu calificación *</label>
                                <div class="rating-select">
                                    <input type="radio" id="star5" name="rating_value" value="5" required/><label for="star5" title="5 estrellas"><i class="fa-solid fa-star"></i></label>
                                    <input type="radio" id="star4" name="rating_value" value="4" /><label for="star4" title="4 estrellas"><i class="fa-solid fa-star"></i></label>
                                    <input type="radio" id="star3" name="rating_value" value="3" /><label for="star3" title="3 estrellas"><i class="fa-solid fa-star"></i></label>
                                    <input type="radio" id="star2" name="rating_value" value="2" /><label for="star2" title="2 estrellas"><i class="fa-solid fa-star"></i></label>
                                    <input type="radio" id="star1" name="rating_value" value="1" /><label for="star1" title="1 estrella"><i class="fa-solid fa-star"></i></label>
                                </div>
                            </div>

                            <div class="mb-2">
                                <textarea class="form-control form-control-sm bg-light" name="comment_text" rows="2" placeholder="Comparte tu experiencia..." required></textarea>
                            </div>

                            <button type="submit" class="btn btn-orange btn-sm w-100">
                                <i class="fa-solid fa-paper-plane me-1"></i> Enviar Reseña
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-secondary text-center small mb-0 p-2 rounded-3">
                            <i class="fa-solid fa-lock me-1"></i> Debes <a href="<?= e(url('login.php')) ?>" class="fw-bold text-orange text-decoration-none">iniciar sesión</a> para comentar.
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
        const map = L.map('mapa').setView([defaultLat, defaultLng], 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        const markersGroup = new L.featureGroup();

        window.turSegInfoPlaces.forEach((sitio) => {
            // 🟢 NUEVA CORRECCIÓN: Si existen 'sitio.lat' y 'sitio.lng' directas en el objeto, las mapea de inmediato usando parseFloat(). De lo contrario, cae en el respaldo geo_point/coordenadasReales.
            let lat = sitio.lat ? parseFloat(sitio.lat) : (sitio.geo_point?.coordinates?.[1] || coordenadasReales[sitio.id]?.[0] || null);
            let lng = sitio.lng ? parseFloat(sitio.lng) : (sitio.geo_point?.coordinates?.[0] || coordenadasReales[sitio.id]?.[1] || null);

            if (lat !== null && lng !== null) {
                const marker = L.marker([lat, lng]);

                let precioTxt = 'Gratis';
                if (sitio.entry_cost && parseFloat(sitio.entry_cost) > 0) {
                    precioTxt = `$${parseFloat(sitio.entry_cost).toLocaleString('co-CO')} ${sitio.currency_code}`;
                }

                const popupContenido = `
                    <div style="min-width: 160px;">
                        <div class="map-popup-title">${sitio.name}</div>
                        <div class="map-popup-info">
                            <div>⭐ <b>${parseFloat(sitio.average_rating).toFixed(1)}</b> (${parseInt(sitio.rating_count)} opiniones)</div>
                            <div>💵 <b>Precio:</b> ${precioTxt}</div>
                        </div>
                        <button class="btn btn-orange btn-xs w-100 py-1" style="font-size: 0.75rem; border-radius: 4px;" onclick="document.getElementById('card-sitio-${sitio.id}').click();">
                            <i class="fa-solid fa-eye me-1"></i> Ver detalles
                        </button>
                    </div>
                `;

                marker.bindPopup(popupContenido);
                markersGroup.addLayer(marker);
            }
        });

        map.addLayer(markersGroup);
        if (markersGroup.getLayers().length > 0) {
            map.fitBounds(markersGroup.getBounds().pad(0.1));
        }

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
            commentsContainer.innerHTML = '<div class="text-center py-2 text-muted small"><i class="fa-solid fa-circle-notch fa-spin me-1"></i>Cargando opiniones...</div>';

            fetch(`index.php?action=obtener_comentarios&place_id=${id}`)
                .then(response => response.json())
                .then(comentarios => {
                    commentsContainer.innerHTML = '';
                    
                    if (!comentarios || comentarios.length === 0) {
                        commentsContainer.innerHTML = '<div class="text-center py-3 text-muted small"><i class="fa-regular fa-comments me-1"></i>Aún no hay opiniones de este lugar. ¡Sé el primero!</div>';
                        return;
                    }

                    comentarios.forEach(com => {
                        const dateObj = new Date(com.created_at);
                        const dateStr = dateObj.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' });
                        
                        const userRating = parseInt(com.rating || 0);
                        let starsHtml = '';
                        for (let i = 1; i <= 5; i++) {
                            starsHtml += `<i class="fa-solid fa-star ${i <= userRating ? 'text-warning' : 'text-muted'} small"></i>`;
                        }

                        const commentItem = document.createElement('div');
                        commentItem.className = 'p-2 rounded-3 bg-light border-0 mb-2 small shadow-sm';
                        commentItem.innerHTML = `
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="text-dark"><i class="fa-solid fa-user text-orange me-1" style="font-size:0.8rem;"></i>${com.user_name}</strong>
                                <span class="text-muted" style="font-size: 0.7rem;">${dateStr}</span>
                            </div>
                            <div class="mb-1" style="font-size:0.75rem;">${starsHtml}</div>
                            <p class="mb-0 text-secondary lh-sm" style="font-size:0.82rem;">${com.body}</p>
                        `;
                        commentsContainer.appendChild(commentItem);
                    });
                })
                .catch(err => {
                    console.error("Error cargando comentarios:", err);
                    commentsContainer.innerHTML = '<div class="text-center text-danger small py-2"><i class="fa-solid fa-circle-xmark me-1"></i>No se pudieron cargar las opiniones.</div>';
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
