<!DOCTYPE html>
<html>

<head>

    <title>Usuarios</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

</head>

<body>

<div class="container mt-5">

    <h1>Lista Usuarios</h1>

    <hr>

    <table class="table table-bordered">

        <thead class="table-dark">

            <tr>

                <th>ID</th>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Acciones</th>

            </tr>

        </thead>

        <tbody>

            <?php if(!empty($usuarios)): ?>

                <?php foreach($usuarios as $usuario): ?>

                    <tr>

                        <td><?= $usuario['id'] ?></td>

                        <td><?= $usuario['nombre'] ?></td>

                        <td><?= $usuario['correo'] ?></td>

                        <td><?= $usuario['rol'] ?></td>

                        <td>

                            <a href="<?= base_url('/editar/'.$usuario['id']) ?>"
                               class="btn btn-warning">

                                Editar

                            </a>

                            <a href="<?= base_url('/eliminar/'.$usuario['id']) ?>"
                               class="btn btn-danger"
                               onclick="return confirm('¿Eliminar usuario?')">

                                Eliminar

                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>

                    <td colspan="5">

                        No hay usuarios registrados

                    </td>

                </tr>

            <?php endif; ?>

        </tbody>

    </table>

</div>

</body>
</html>