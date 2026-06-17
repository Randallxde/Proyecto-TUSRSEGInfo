<!DOCTYPE html>
<html>

<head>

<title>Registro</title>
<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/di
st/css/bootstrap.min.css"
rel="stylesheet">

</head>

<body>

<div class="container mt-5">

<h1>Registro Usuarios</h1>
<form action="<?= base_url('/guardar') ?>"
method="post">
<input type="text"
name="nombre"
class="form-control mb-3"
placeholder="Nombre">

<input type="email"
name="correo"
class="form-control mb-3"
placeholder="Correo">

<input type="password"
name="password"
class="form-control mb-3"
placeholder="Password">
<button class="btn btn-success">

Registrar
</button>

</form>

</div>

</body>
</html>