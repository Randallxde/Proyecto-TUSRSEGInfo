<?php
// 1. Iniciar sesión para capturar el usuario logueado (si aplica)
session_start();

// Configuración de la base de datos basada en tu archivo .sql
$host = 'localhost';
$dbname = 'turseginfo_db';
$username = 'root';
$password = ''; // Cambia esto si tienes contraseña en MySQL

try {
    // Conexión segura usando PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// 2. Verificar que los datos del formulario hayan sido enviados por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Capturar y limpiar datos básicos
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $address_text = isset($_POST['address_text']) ? trim($_POST['address_text']) : '';
    $entry_cost = isset($_POST['entry_cost']) ? floatval($_POST['entry_cost']) : 0.00;
    $currency_code = isset($_POST['currency_code']) ? trim($_POST['currency_code']) : 'COP';
    
    // Capturar coordenadas (vienen desde Leaflet en el frontend)
    $latitude = isset($_POST['latitude']) ? trim($_POST['latitude']) : '';
    $longitude = isset($_POST['longitude']) ? trim($_POST['longitude']) : '';
    
    // Identificadores de relaciones basados en tu BD
    // Si tienes un sistema de login usa $_SESSION['user_id'], si no, ponemos 1 para pruebas
    $creator_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1; 
    $city_id = isset($_POST['city_id']) ? intval($_POST['city_id']) : 1; // 1 = Bogotá D.C. según tus datos

    // Validar campos obligatorios antes de insertar
    if (empty($name) || empty($latitude) || empty($longitude)) {
        die("Error: El nombre del sitio y las coordenadas son obligatorios.");
    }

    try {
        // 3. Preparar la consulta SQL utilizando ST_GeomFromText para el geo_point
        // Importante: En MySQL, POINT suele recibir (longitud, latitud) o viceversa dependiendo de la versión, 
        // lo estándar para GIS es POINT(longitud latitud) separado por un espacio.
        $sql = "INSERT INTO places (
                    creator_user_id, 
                    city_id, 
                    name, 
                    description, 
                    geo_point, 
                    address_text, 
                    entry_cost, 
                    currency_code, 
                    moderation_status, 
                    status, 
                    created_at
                ) VALUES (
                    :creator_user_id, 
                    :city_id, 
                    :name, 
                    :description, 
                    ST_GeomFromText(:geo_point_wkt), 
                    :address_text, 
                    :entry_cost, 
                    :currency_code, 
                    'pending', 
                    'active', 
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);

        // Construir la cadena Well-Known Text (WKT) para el punto geográfico
        // Formato: POINT(longitud latitud)
        $geo_point_wkt = "POINT($longitude $latitude)";

        // Vincular los parámetros de forma segura (Evita Inyección SQL)
        $stmt->bindParam(':creator_user_id', $creator_user_id, PDO::PARAM_INT);
        $stmt->bindParam(':city_id', $city_id, PDO::PARAM_INT);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':description', $description, PDO::PARAM_STR);
        $stmt->bindParam(':geo_point_wkt', $geo_point_wkt, PDO::PARAM_STR);
        $stmt->bindParam(':address_text', $address_text, PDO::PARAM_STR);
        $stmt->bindParam(':entry_cost', $entry_cost);
        $stmt->bindParam(':currency_code', $currency_code, PDO::PARAM_STR);

        // Ejecutar la inserción
        if ($stmt->execute()) {
            echo "¡Punto de interés enviado con éxito! Queda en estado 'pendiente' para revisión de moderación.";
            // Aquí puedes redirigir al usuario: header("Location: mapa.php");
        } else {
            echo "Hubo un problema al guardar el sitio.";
        }

    } catch (PDOException $e) {
        echo "Error en la base de datos: " . $e->getMessage();
    }
} else {
    echo "Método de envío no válido.";
}
?>