<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('America/Bogota');
const APP_NOMBRE = 'TurSegInfo';
const APP_DESC = 'Portal turístico profesional';
const EDAD_MINIMA = 14;
const DB_HOST = 'sql105.infinityfree.com';

// ⚠️ REEMPLAZA EL 'XXX' CON EL NOMBRE REAL DE TU BASE DE DATOS EN INFINITYFREE
const DB_NAME = 'if0_42191403_turseginfo_db'; 

const DB_USER = 'if0_42191403';
const DB_PASS = 'D17a2009';
const DB_CHARSET = 'utf8mb4';

// ✅ CORREGIDO: Sin el "http://" duplicado al inicio
const BASE_PATH = 'https://turseg.kesug.com/';