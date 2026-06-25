<?php
require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$data = api_request_data();
$pdo = api_db();

if ($method === 'GET') {
    $id = api_int_or_null($data['id'] ?? null);
    $placeId = api_int_or_null($data['place_id'] ?? null);
    $admin = api_current_user() && es_admin();

    $where = [];
    $params = [];

    if ($id) {
        $where[] = 'c.id = :id';
        $params['id'] = $id;
    }
    if ($placeId) {
        $where[] = 'c.place_id = :place_id';
        $params['place_id'] = $placeId;
    }

    if (!$admin) {
        $where[] = "c.moderation_status IN ('approved', 'pending')";
        $where[] = "c.deleted_at IS NULL";
    }

    $sql = "SELECT c.id, c.place_id, c.user_id, c.status, c.parent_comment_id, c.body,
                   c.moderation_status, c.created_at, c.updated_at,
                   COALESCE(p.display_name, p.username, 'Turista') AS user_name,
                   COALESCE(r.rating, 0) AS rating
            FROM place_comments c
            LEFT JOIN user_profiles p ON c.user_id = p.user_id
            LEFT JOIN place_ratings r ON r.place_id = c.place_id AND r.user_id = c.user_id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.created_at DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map('api_comment_row', $rows);
    api_ok('Listado de comentarios.', ['comments' => $items, 'count' => count($items)]);
}

if ($method === 'POST') {
    $usuario = api_require_login();

    $place_id = api_int_or_null($data['place_id'] ?? null);
    $body = api_string_or_null($data['body'] ?? null);
    $parent_comment_id = api_int_or_null($data['parent_comment_id'] ?? null);

    if (!$place_id) {
        api_error('Debes enviar el place_id.', 422);
    }
    if (!$body) {
        api_error('El comentario no puede estar vacío.', 422);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM places WHERE id = :id');
    $stmt->execute(['id' => $place_id]);
    if ((int)$stmt->fetchColumn() === 0) {
        api_error('El sitio no existe.', 404);
    }

    $stmt = $pdo->prepare("INSERT INTO place_comments
        (place_id, user_id, status, parent_comment_id, body, moderation_status, created_at, updated_at)
        VALUES
        (:place_id, :user_id, 'pendiente', :parent_comment_id, :body, 'pending', NOW(), NOW())");
    $stmt->bindValue(':place_id', $place_id, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', (int)$usuario['id'], PDO::PARAM_INT);
    if ($parent_comment_id === null) {
        $stmt->bindValue(':parent_comment_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':parent_comment_id', $parent_comment_id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':body', $body, PDO::PARAM_STR);
    $stmt->execute();

    $newId = (int)$pdo->lastInsertId();
    api_ok('Comentario creado correctamente.', [
        'id' => $newId
    ], 201);
}

if ($method === 'PUT') {
    $usuario = api_require_login();

    $id = api_int_or_null($data['id'] ?? null);
    if (!$id) {
        api_error('Debes enviar el id del comentario.', 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM place_comments WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        api_error('El comentario no existe.', 404);
    }

    if (!es_admin() && (int)($comment['user_id'] ?? 0) !== (int)$usuario['id']) {
        api_error('Solo el autor o un administrador pueden actualizar este comentario.', 403);
    }

    $updates = [];
    $params = ['id' => $id];

    if (array_key_exists('body', $data)) {
        $body = api_string_or_null($data['body'] ?? null);
        if ($body !== null) {
            $updates[] = 'body = :body';
            $params['body'] = $body;
        }
    }

    if (es_admin() && array_key_exists('moderation_status', $data)) {
        $moderation = api_string_or_null($data['moderation_status'] ?? null);
        if (in_array($moderation, ['pending', 'approved', 'hidden', 'removed'], true)) {
            $updates[] = 'moderation_status = :moderation_status';
            $params['moderation_status'] = $moderation;
        }
    }

    if (!$updates) {
        api_error('No hay datos para actualizar.', 422);
    }

    $updates[] = 'updated_at = NOW()';
    $sql = 'UPDATE place_comments SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        if ($value === null) {
            $stmt->bindValue(':' . $key, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':' . $key, (string)$value);
        }
    }
    $stmt->execute();

    api_ok('Comentario actualizado correctamente.', ['id' => $id]);
}

if ($method === 'DELETE') {
    $usuario = api_require_login();

    $id = api_int_or_null($data['id'] ?? null);
    if (!$id) {
        api_error('Debes enviar el id del comentario.', 422);
    }

    $stmt = $pdo->prepare('SELECT user_id FROM place_comments WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $ownerId = $stmt->fetchColumn();

    if ($ownerId === false) {
        api_error('El comentario no existe.', 404);
    }

    if (!es_admin() && (int)$ownerId !== (int)$usuario['id']) {
        api_error('Solo el autor o un administrador pueden eliminar este comentario.', 403);
    }

    $stmt = $pdo->prepare("UPDATE place_comments SET moderation_status = 'removed', deleted_at = NOW(), updated_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $id]);

    api_ok('Comentario eliminado correctamente.', ['id' => $id]);
}

api_error('Método no permitido.', 405, [
    'allowed' => ['GET', 'POST', 'PUT', 'DELETE']
]);
