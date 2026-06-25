<?php

namespace Config;

$routes = Services::routes();

// Páginas públicas
$routes->get('/', 'Auth::index');

// Autenticación
$routes->match(['get', 'post'], 'login', 'Auth::login');
$routes->match(['get', 'post'], 'register', 'Auth::register');
$routes->get('logout', 'Auth::logout');

// Paneles protegidos
$routes->match(['get', 'post'], 'admin', 'Admin::index');
$routes->get('dashboard', 'Dashboard::index');
$routes->get('testdb', 'TestDB::index');