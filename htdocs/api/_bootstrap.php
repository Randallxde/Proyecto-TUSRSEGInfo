<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-HTTP-Method-Override');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function api_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status = 400, array $extra = []): void
{
    api_response(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra), $status);
}

function api_ok(string $message, array $data = [], int $status = 200): void
{
    api_response([
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ], $status);
}

function api_request_data(): array
{
    $raw = file_get_contents('php://input');
    $data = [];

    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $data = $decoded;
        } else {
            parse_str($raw, $data);
        }
    }

    return array_merge($_GET ?? [], $_POST ?? [], $data);
}

function api_method(): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $override = strtoupper((string)($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_GET['_method'] ?? '')));
    if ($method === 'POST' && in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
        return $override;
    }
    return $method;
}

function api_db(): PDO
{
    return db();
}

function api_current_user(): ?array
{
    if (!usuario_autenticado()) {
        return null;
    }

    return usuario_actual();
}

function api_require_login(): array
{
    $usuario = api_current_user();
    if (!$usuario) {
        api_error('Debes iniciar sesión para realizar esta acción.', 401);
    }
    return $usuario;
}

function api_require_admin(): array
{
    $usuario = api_require_login();
    if (!es_admin()) {
        api_error('Acceso denegado. Solo administradores.', 403);
    }
    return $usuario;
}

function api_bool($value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
}

function api_float_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function api_int_or_null($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value) || ctype_digit((string)$value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
        return (int)$value;
    }
    return null;
}

function api_string_or_null($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function api_place_row(array $row): array
{
    return [
        'id' => (int)($row['id'] ?? 0),
        'creator_user_id' => isset($row['creator_user_id']) ? (int)$row['creator_user_id'] : null,
        'city_id' => isset($row['city_id']) ? (int)$row['city_id'] : null,
        'name' => $row['name'] ?? null,
        'description' => $row['description'] ?? null,
        'address_text' => $row['address_text'] ?? null,
        'entry_cost' => isset($row['entry_cost']) ? (float)$row['entry_cost'] : null,
        'currency_code' => $row['currency_code'] ?? null,
        'rating_sum' => isset($row['rating_sum']) ? (float)$row['rating_sum'] : null,
        'rating_count' => isset($row['rating_count']) ? (int)$row['rating_count'] : null,
        'average_rating' => isset($row['average_rating']) ? (float)$row['average_rating'] : null,
        'moderation_status' => $row['moderation_status'] ?? null,
        'status' => $row['status'] ?? null,
        'city_name' => $row['city_name'] ?? null,
        'department_name' => $row['department_name'] ?? null,
        'storage_url' => $row['storage_url'] ?? null,
        'lat' => isset($row['lat']) ? (float)$row['lat'] : null,
        'lng' => isset($row['lng']) ? (float)$row['lng'] : null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function api_comment_row(array $row): array
{
    return [
        'id' => (int)($row['id'] ?? 0),
        'place_id' => isset($row['place_id']) ? (int)$row['place_id'] : null,
        'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
        'body' => $row['body'] ?? null,
        'status' => $row['status'] ?? null,
        'moderation_status' => $row['moderation_status'] ?? null,
        'user_name' => $row['user_name'] ?? null,
        'rating' => isset($row['rating']) ? (float)$row['rating'] : null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function api_user_summary(array $usuario): array
{
    return [
        'id' => (int)($usuario['id'] ?? 0),
        'email' => $usuario['email'] ?? null,
        'status' => $usuario['status'] ?? null,
        'role_id' => isset($usuario['role_id']) ? (int)$usuario['role_id'] : null,
        'role' => $usuario['nombre_rol'] ?? $usuario['role'] ?? null,
        'display_name' => $usuario['display_name'] ?? null,
        'username' => $usuario['username'] ?? null,
    ];
}

function api_fetch_user_by_email(string $email): ?array
{
    $sql = "SELECT u.id, u.email, u.password_hash, u.status, u.role_id, r.nombre_rol, p.display_name, p.username
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            LEFT JOIN user_profiles p ON p.user_id = u.id
            WHERE u.email = :email
            LIMIT 1";
    $stmt = api_db()->prepare($sql);
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function api_fetch_user_by_id(int $id): ?array
{
    $sql = "SELECT u.id, u.email, u.status, u.role_id, r.nombre_rol, p.display_name, p.username
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            LEFT JOIN user_profiles p ON p.user_id = u.id
            WHERE u.id = :id
            LIMIT 1";
    $stmt = api_db()->prepare($sql);
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function api_fetch_place(int $id): ?array
{
    $sql = "SELECT p.id, p.creator_user_id, p.city_id, p.name, p.description, p.geo_point,
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
    $stmt = api_db()->prepare($sql);
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function api_place_payload_from_row(array $row): array
{
    $payload = api_place_row($row);
    if (!array_key_exists('geo_point', $row)) {
        return $payload;
    }
    return $payload;
}
