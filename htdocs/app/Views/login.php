<!DOCTYPE html>
<html>

<head>

<title>Login</title>
<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/di
st/css/bootstrap.min.css"
rel="stylesheet">

</head>

<body>
<div class="container mt-5">

<h1>Login</h1>
<form action="<?= base_url('/login') ?>"
method="post">

<input type="email"
name="correo"
class="form-control mb-3"
placeholder="Correo">

<input type="password"
name="password"
class="form-control mb-3"
placeholder="Password">
<button class="btn btn-primary">

Ingresar
</button>

</form>

<br>

<a href="<?= base_url('/registro') ?>">

Registrarse
</a>

</div>

</body>
</html>