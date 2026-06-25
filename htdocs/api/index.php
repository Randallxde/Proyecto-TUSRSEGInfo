<?php
require_once __DIR__ . '/_bootstrap.php';

if (api_method() !== 'GET') {
    api_error('Método no permitido.', 405, [
        'allowed' => ['GET']
    ]);
}

api_ok('API TurSegInfo activa', [
    'endpoints' => [
        [
            'path' => '/api/auth.php',
            'methods' => ['GET', 'POST'],
            'description' => 'Autenticación y cierre de sesión.'
        ],
        [
            'path' => '/api/places.php',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE'],
            'description' => 'CRUD de sitios turísticos.'
        ],
        [
            'path' => '/api/comments.php',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE'],
            'description' => 'CRUD de comentarios.'
        ],
    ]
]);
