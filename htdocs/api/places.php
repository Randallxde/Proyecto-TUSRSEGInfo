<?php
require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$data = api_request_data();

if ($method === 'GET') {
    $pdo = api_db();
    $id = api_int_or_null($data['id'] ?? null);

    if ($id) {
        $sql = "SELECT p.id, p.creator_user_id, p.city_id, p.name, p.description,
                       ST_Y(p.geo_point) AS lat, ST_X(p.geo_point) AS lng,
                       p.address_text, p.cover_media_id, p.entry_cost, p.currency_code,
                       p.rating_sum, p.rating_count, p.average_rating,
                       p.moderation_status, p.status, p.created_at, p.updated_at,
                       c.city_name, c.department_name, m.storage_url
                FROM places p
                LEFT JOIN cities c ON c.id = p.city_id
                LEFT JOIN media_assets m ON m.id = p.cover_media_id
                WHERE p.id = :id
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            api_error('El sitio no existe.', 404);
        }
        api_ok('Sitio encontrado.', ['place' => api_place_row($row)]);
    }

    $status = api_string_or_null($data['status'] ?? null);
    $moderation = api_string_or_null($data['moderation_status'] ?? null);

    $where = "WHERE 1=1";
    $params = [];
    if ($status) {
        $where .= " AND p.status = :status";
        $params['status'] = $status;
    } else {
        $where .= " AND p.status = 'active'";
    }

    if ($moderation) {
        $where .= " AND p.moderation_status = :moderation_status";
        $params['moderation_status'] = $moderation;
    } else {
        $where .= " AND p.moderation_status IN ('approved', 'flagged')";
    }

    $sql = "SELECT p.id, p.creator_user_id, p.city_id, p.name, p.description,
                   ST_Y(p.geo_point) AS lat, ST_X(p.geo_point) AS lng,
                   p.address_text, p.cover_media_id, p.entry_cost, p.currency_code,
                   p.rating_sum, p.rating_count, p.average_rating,
                   p.moderation_status, p.status, p.created_at, p.updated_at,
                   c.city_name, c.department_name, m.storage_url
            FROM places p
            LEFT JOIN cities c ON c.id = p.city_id
            LEFT JOIN media_assets m ON m.id = p.cover_media_id
            $where
            ORDER BY p.average_rating DESC, p.rating_count DESC, p.created_at DESC
            LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map('api_place_row', $rows);
    api_ok('Listado de sitios.', ['places' => $items, 'count' => count($items)]);
}

if ($method === 'POST') {
    $usuario = api_require_login();
    $pdo = api_db();

    $name = api_string_or_null($data['name'] ?? null);
    $description = api_string_or_null($data['description'] ?? null);
    $address_text = api_string_or_null($data['address_text'] ?? null);
    $currency_code = api_string_or_null($data['currency_code'] ?? 'COP');
    $moderation_status = api_string_or_null($data['moderation_status'] ?? 'pending') ?? 'pending';
    $status = api_string_or_null($data['status'] ?? 'active') ?? 'active';
    $city_id = api_int_or_null($data['city_id'] ?? null);
    $entry_cost = api_float_or_null($data['entry_cost'] ?? null);
    $cover_media_id = api_int_or_null($data['cover_media_id'] ?? null);
    $lat = api_float_or_null($data['lat'] ?? null);
    $lng = api_float_or_null($data['lng'] ?? null);

    if (!$name) {
        api_error('El nombre del sitio es obligatorio.', 422);
    }

    if ($currency_code && strlen($currency_code) > 3) {
        api_error('currency_code debe tener máximo 3 caracteres.', 422);
    }

    $allowed_status = ['active', 'inactive', 'suspended', 'deleted'];
    if (!in_array($status, $allowed_status, true)) {
        $status = 'active';
    }

    $allowed_moderation = ['pending', 'approved', 'flagged', 'rejected', 'hidden'];
    if (!in_array($moderation_status, $allowed_moderation, true)) {
        $moderation_status = 'pending';
    }

    $fields = [
        'creator_user_id' => (int)$usuario['id'],
        'city_id' => $city_id,
        'name' => $name,
        'description' => $description,
        'address_text' => $address_text,
        'cover_media_id' => $cover_media_id,
        'entry_cost' => $entry_cost,
        'currency_code' => $currency_code,
        'moderation_status' => $moderation_status,
        'status' => $status,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $cols = [];
    $marks = [];
    $params = [];
    foreach ($fields as $key => $value) {
        $cols[] = $key;
        $marks[] = ':' . $key;
        $params[$key] = $value;
    }

    $geoExpr = null;
    if ($lat !== null && $lng !== null) {
        $geoExpr = "ST_GeomFromText(CONCAT('POINT(', :lng, ' ', :lat, ')'))";
        $params['lat'] = $lat;
        $params['lng'] = $lng;
    }

    if ($geoExpr !== null) {
        $cols[] = 'geo_point';
        $marks[] = $geoExpr;
    }

    $sql = "INSERT INTO places (" . implode(', ', $cols) . ")
            VALUES (" . implode(', ', $marks) . ")";
    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        if ($key === 'city_id' || $key === 'cover_media_id') {
            if ($value === null) {
                $stmt->bindValue(':' . $key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':' . $key, (int)$value, PDO::PARAM_INT);
            }
        } elseif ($key === 'entry_cost' || $key === 'lat' || $key === 'lng') {
            if ($value === null) {
                $stmt->bindValue(':' . $key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':' . $key, (string)$value);
            }
        } else {
            if ($value === null) {
                $stmt->bindValue(':' . $key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':' . $key, (string)$value);
            }
        }
    }

    $stmt->execute();
    $newId = (int)$pdo->lastInsertId();
    api_ok('Sitio creado correctamente.', [
        'id' => $newId,
        'place' => api_place_row((array)(api_fetch_place($newId) ?? [])),
    ], 201);
}

if ($method === 'PUT') {
    $usuario = api_require_login();
    $pdo = api_db();

    $id = api_int_or_null($data['id'] ?? null);
    if (!$id) {
        api_error('Debes enviar el id del sitio.', 422);
    }

    $current = api_fetch_place($id);
    if (!$current) {
        api_error('El sitio no existe.', 404);
    }

    if (!es_admin() && (int)($current['creator_user_id'] ?? 0) !== (int)$usuario['id']) {
        api_error('Solo el creador o un administrador pueden actualizar este sitio.', 403);
    }

    $updates = [];
    $params = ['id' => $id];

    $map = [
        'city_id' => 'city_id',
        'name' => 'name',
        'description' => 'description',
        'address_text' => 'address_text',
        'entry_cost' => 'entry_cost',
        'currency_code' => 'currency_code',
        'cover_media_id' => 'cover_media_id',
        'moderation_status' => 'moderation_status',
        'status' => 'status',
    ];

    foreach ($map as $input => $column) {
        if (!array_key_exists($input, $data)) {
            continue;
        }
        $value = $data[$input];
        if ($input === 'city_id' || $input === 'cover_media_id') {
            $value = api_int_or_null($value);
        } elseif ($input === 'entry_cost') {
            $value = api_float_or_null($value);
        } else {
            $value = api_string_or_null($value);
        }

        if ($input === 'status' && !in_array($value, ['active', 'inactive', 'suspended', 'deleted'], true)) {
            continue;
        }
        if ($input === 'moderation_status' && !in_array($value, ['pending', 'approved', 'flagged', 'rejected', 'hidden'], true)) {
            continue;
        }
        if ($input === 'currency_code' && $value !== null && strlen($value) > 3) {
            continue;
        }

        $updates[] = "$column = :$column";
        $params[$column] = $value;
    }

    $lat = array_key_exists('lat', $data) ? api_float_or_null($data['lat']) : null;
    $lng = array_key_exists('lng', $data) ? api_float_or_null($data['lng']) : null;
    if ($lat !== null && $lng !== null) {
        $updates[] = "geo_point = ST_GeomFromText(CONCAT('POINT(', :lng, ' ', :lat, ')'))";
        $params['lat'] = $lat;
        $params['lng'] = $lng;
    }

    if (!$updates) {
        api_error('No hay datos para actualizar.', 422);
    }

    $updates[] = "updated_at = NOW()";

    $sql = "UPDATE places SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        if ($key === 'id' || $key === 'city_id' || $key === 'cover_media_id') {
            if ($value === null) {
                $stmt->bindValue(':' . $key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':' . $key, (int)$value, PDO::PARAM_INT);
            }
        } elseif ($key === 'entry_cost' || $key === 'lat' || $key === 'lng') {
            if ($value === null) {
                $stmt->bindValue(':' . $key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':' . $key, (string)$value);
            }
        } else {
            if ($value === null) {
                $stmt->bindValue(':' . $key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':' . $key, (string)$value);
            }
        }
    }

    $stmt->execute();
    api_ok('Sitio actualizado correctamente.', [
        'place' => api_place_row((array)(api_fetch_place($id) ?? [])),
    ]);
}

if ($method === 'DELETE') {
    $usuario = api_require_login();
    $pdo = api_db();

    $id = api_int_or_null($data['id'] ?? null);
    if (!$id) {
        api_error('Debes enviar el id del sitio.', 422);
    }

    $current = api_fetch_place($id);
    if (!$current) {
        api_error('El sitio no existe.', 404);
    }

    if (!es_admin() && (int)($current['creator_user_id'] ?? 0) !== (int)$usuario['id']) {
        api_error('Solo el creador o un administrador pueden eliminar este sitio.', 403);
    }

    $stmt = $pdo->prepare("UPDATE places SET status = 'deleted', deleted_at = NOW(), updated_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $id]);

    api_ok('Sitio eliminado correctamente.', ['id' => $id]);
}

api_error('Método no permitido.', 405, [
    'allowed' => ['GET', 'POST', 'PUT', 'DELETE']
]);
