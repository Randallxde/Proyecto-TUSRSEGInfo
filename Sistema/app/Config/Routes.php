<?php

namespace Config;

// Crear una instancia de rutas si no viene predefinida en el entorno
$routes = Services::routes();

/*
 * --------------------------------------------------------------------
 * Rutas Públicas y Autenticación
 * --------------------------------------------------------------------
 */
$routes->get('/', 'Auth::index');

// Es mejor separar GET (ver formulario) y POST (procesar datos) para mayor claridad,
// o mantener el match si tu controlador maneja ambos en el mismo método.
$routes->match(['get', 'post'], 'login', 'Auth::login');
$routes->match(['get', 'post'], 'register', 'Auth::register');
$routes->get('logout', 'Auth::logout');

/*
 * --------------------------------------------------------------------
 * Rutas Protegidas (Requieren Inicio de Sesión)
 * --------------------------------------------------------------------
 * Se utiliza un grupo con el filtro 'auth' (debes tener este filtro 
 * configurado en app/Config/Filters.php) para asegurar que nadie sin 
 * sesión activa pueda interactuar con estas URLs.
 */
$routes->group('', ['filter' => 'auth'], function ($routes) {
    
    // Panel de Administración
    $routes->match(['get', 'post'], 'admin', 'Admin::index');
    
    // Panel de Usuario / Dashboard
    $routes->get('dashboard', 'Dashboard::index');
    // Rutas para CRUD de Destinos Turísticos
    $routes->get('places', 'Destinos::index'); // Listar destinos
    
});