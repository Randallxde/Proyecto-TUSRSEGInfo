<!DOCTYPE html>
<html>

<head>

    <title>Editar Usuario</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

</head>

<body>

<div class="container mt-5">

    <h1>Editar Usuario</h1>

    <form action="<?= base_url('/actualizar/'.$usuario['id']) ?>"
          method="post">

        <input type="text"
               name="nombre"
               value="<?= $usuario['nombre'] ?>"
               class="form-control mb-3">

        <input type="email"
               name="correo"
               value="<?= $usuario['correo'] ?>"
               class="form-control mb-3">

        <select name="rol"
                class="form-control mb-3">

            <option value="admin"
                <?= ($usuario['rol'] == 'admin') ? 'selected' : '' ?>>

                Admin

            </option>

            <option value="aprendiz"
                <?= ($usuario['rol'] == 'aprendiz') ? 'selected' : '' ?>>

                Aprendiz

            </option>

        </select>

        <button class="btn btn-success">

            Actualizar

        </button>

    </form>

</div>

</body>
</html>