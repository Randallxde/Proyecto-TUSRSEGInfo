<?php
require_once __DIR__ . '/_bootstrap.php';

$method = api_method();
$data = api_request_data();

if ($method === 'GET') {
    $usuario = api_current_user();
    if (!$usuario) {
        api_ok('No hay sesión activa.', ['authenticated' => false]);
    }
    api_ok('Sesión activa.', [
        'authenticated' => true,
        'user' => [
            'id' => (int)$usuario['id'],
            'email' => $usuario['email'] ?? null,
            'status' => $usuario['status'] ?? null,
            'role' => $usuario['rol'] ?? null,
            'display_name' => $usuario['display_name'] ?? null,
            'username' => $usuario['username'] ?? null,
        ]
    ]);
}

if ($method === 'POST') {
    $action = strtolower((string)($data['action'] ?? 'login'));

    if ($action === 'logout') {
        session_destroy();
        api_ok('Sesión cerrada correctamente.', ['authenticated' => false]);
    }

    $email = api_string_or_null($data['email'] ?? null);
    $password = (string)($data['password'] ?? '');

    if (!$email || !validar_email($email)) {
        api_error('Debes enviar un correo válido.', 422);
    }

    if ($password === '') {
        api_error('Debes enviar la contraseña.', 422);
    }

    $usuario = api_fetch_user_by_email($email);
    if (!$usuario) {
        api_error('Correo o contraseña incorrectos.', 401);
    }

    if (($usuario['status'] ?? '') === 'suspended') {
        api_error('La cuenta está suspendida.', 403);
    }

    if (!password_verify($password, (string)$usuario['password_hash'])) {
        api_error('Correo o contraseña incorrectos.', 401);
    }

    login_user($usuario);

    api_ok('Autenticación exitosa.', [
        'authenticated' => true,
        'user' => api_user_summary($usuario),
        'requires_verification' => (($usuario['status'] ?? '') === 'pending'),
    ]);
}

api_error('Método no permitido.', 405, [
    'allowed' => ['GET', 'POST']
]);
