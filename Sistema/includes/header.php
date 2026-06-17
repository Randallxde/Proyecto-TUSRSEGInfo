<?php
require_once __DIR__ . '/functions.php';
$flash = get_flash();
$usuario = usuario_actual();
$pageTitle = $pageTitle ?? APP_NOMBRE;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="<?= e(url('assets/css/styles.css')) ?>">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top shadow-sm nav-turseginfo">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url('index.php')) ?>"><i class="fa-solid fa-location-dot me-2 text-orange"></i>TurSeg Info</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuPrincipal"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="menuPrincipal">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-lg-1 align-items-lg-center">
                <li class="nav-item"><a class="nav-link" href="<?= e(url('index.php')) ?>">Inicio</a></li>
                <li class="nav-item"><a class="nav-link" href="#mapa">Mapa</a></li>
                <?php if ($usuario): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url('dashboard.php')) ?>">Perfil</a></li>
                    <?php if (es_admin()): ?><li class="nav-item"><a class="nav-link" href="<?= e(url('admin.php')) ?>">Admin</a></li><?php endif; ?>
                    <li class="nav-item"><span class="nav-link text-light opacity-75">Hola, <?= e($usuario['display_name'] ?? $usuario['email']) ?></span></li>
                    <li class="nav-item"><a class="btn btn-orange ms-lg-2" href="<?= e(url('logout.php')) ?>">Salir</a></li>
                <?php else: ?>
                 <li class="nav-item"><a class="nav-link" href="<?= e(url('login.php')) ?>">Perfil</a></li>
                    <li class="nav-item"><a class="btn btn-orange ms-lg-2" href="<?= e(url('register.php')) ?>">Registrarse</a></li>

                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="pt-5">
<?php if ($flash): ?><div class="container mt-4"><div class="alert alert-<?= e($flash['tipo']) ?> shadow-sm mb-0"><?= e($flash['mensaje']) ?></div></div><?php endif; ?>
