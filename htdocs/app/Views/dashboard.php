<!DOCTYPE html>
<html>

<head>

<title>Dashboard</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/di
st/css/bootstrap.min.css"
rel="stylesheet">

</head>

<body>
<div class="d-flex">

<div class="bg-dark text-white p-3"
style="width:250px; height:100vh;">

<h2>Sistema</h2>

<hr>

<ul class="nav flex-column">

<li class="nav-item mb-3">

<a href="<?= base_url('/dashboard')

?>"

class="nav-link text-white">

Inicio

</a>

</li>

<li class="nav-item mb-3">

<a href="<?= base_url('/usuarios')

?>"

class="nav-link text-white">

Usuarios

</a>

</li>

<li class="nav-item">

<a href="<?= base_url('/logout') ?>"
class="nav-link text-danger">
Cerrar Sesion

</a>

</li>

</ul>

</div>

<div class="container p-5">
<h1>

Bienvenido
<?= session('nombre') ?>
</h1>

<h3>

Rol:
<?= session('rol') ?>
</h3>

</div>

</div>

</body>
</html>