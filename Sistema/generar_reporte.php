<?php
// generar_reporte.php
require_once __DIR__ . '/includes/functions.php';
require_admin(); // Control de acceso para administradores

$pdo = db();
$tipo = $_GET['tipo'] ?? 'usuarios';

// Configurar cabeceras nativas para forzar la salida como PDF en el navegador
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="reporte_' . $tipo . '_' . date('Ymd') . '.pdf"');

// Construcción del flujo estructurado en formato PDF nativo simplificado (F1 = Helvetica, F2 = Helvetica-Bold)
$stream = "";

// Funciones auxiliares para añadir comandos de texto al flujo binario del PDF
function pdf_texto($f, $size, $x, $y, $txt) {
    return "BT /$f $size Tf 0 g 1 0 0 1 $x $y Tm (" . pdf_escapar($txt) . ") Tj ET\n";
}

function pdf_rect($x, $y, $w, $h, $r, $g, $b) {
    $rc = $r / 255; $gc = $g / 255; $bc = $b / 255;
    return sprintf("%.2f %.2f %.2f rg %.2f %.2f %.2f %.2f re f\n", $rc, $gc, $bc, $x, $y, $w, $h);
}

function pdf_linea($x1, $y1, $x2, $y2) {
    return "$x1 $y1 m $x2 $y2 l S\n";
}

function pdf_escapar($txt) {
    $txt = iconv('UTF-8', 'windows-1252//IGNORE', $txt);
    return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $txt);
}

// --- ENCABEZADO COMÚN DEL REPORTE ---
$stream .= pdf_rect(0, 750, 612, 42, 249, 115, 22); // Franja naranja del panel
$stream .= pdf_texto("F2", 16, 40, 700, APP_NOMBRE . " - Sistema Administrativo");

if ($tipo === 'sitios') {
    $stream .= pdf_texto("F1", 11, 40, 682, "Reporte Detallado: Puntos de Interes y Sitios Turisticos");
    $stream .= pdf_texto("F1", 9, 40, 668, "Fecha de extraccion: " . date('d/m/Y H:i'));
    
    // Encabezados de tabla
    $stream .= pdf_rect(40, 630, 532, 18, 241, 245, 249);
    $stream .= pdf_texto("F2", 9, 45, 635, "ID");
    $stream .= pdf_texto("F2", 9, 85, 635, "Nombre del Lugar");
    $stream .= pdf_texto("F2", 9, 280, 635, "Ciudad");
    $stream .= pdf_texto("F2", 9, 410, 635, "Moderacion");
    $stream .= pdf_texto("F2", 9, 500, 635, "Visibilidad");
    $stream .= pdf_linea(40, 630, 572, 630);
    
    // Datos SQL
    $lugares = $pdo->query("SELECT p.id, p.name, p.status, p.moderation_status, c.city_name FROM places p LEFT JOIN cities c ON c.id = p.city_id ORDER BY p.id ASC LIMIT 25")->fetchAll();
    $y = 612;
    foreach ($lugares as $l) {
        $stream .= pdf_texto("F1", 9, 45, $y, $l['id']);
        $stream .= pdf_texto("F1", 9, 85, $y, substr($l['name'], 0, 30));
        $stream .= pdf_texto("F1", 9, 280, $y, substr($l['city_name'] ?? 'N/A', 0, 20));
        $stream .= pdf_texto("F1", 9, 410, $y, $l['moderation_status']);
        $stream .= pdf_texto("F1", 9, 500, $y, $l['status']);
        $stream .= pdf_linea(40, $y - 4, 572, $y - 4);
        $y -= 18;
    }
} elseif ($tipo === 'comentarios') {
    $stream .= pdf_texto("F1", 11, 40, 682, "Reporte de Auditoria: Comentarios de la Comunidad");
    $stream .= pdf_texto("F1", 9, 40, 668, "Fecha de extraccion: " . date('d/m/Y H:i'));
    
    // Encabezados de tabla
    $stream .= pdf_rect(40, 630, 532, 18, 241, 245, 249);
    $stream .= pdf_texto("F2", 9, 45, 635, "ID");
    $stream .= pdf_texto("F2", 9, 85, 635, "Autor (Email)");
    $stream .= pdf_texto("F2", 9, 230, 635, "Comentario");
    $stream .= pdf_texto("F2", 9, 490, 635, "Estado");
    $stream .= pdf_linea(40, 630, 572, 630);
    
    // Datos SQL
    $comentarios = $pdo->query("SELECT co.id, co.body, co.moderation_status, u.email FROM place_comments co LEFT JOIN users u ON u.id = co.user_id ORDER BY co.id DESC LIMIT 25")->fetchAll();
    $y = 612;
    foreach ($comentarios as $c) {
        $stream .= pdf_texto("F1", 8.5, 45, $y, $c['id']);
        $stream .= pdf_texto("F1", 8.5, 85, $y, substr($c['email'] ?? 'Anonimo', 0, 22));
        $texto_limpio = str_replace(array("\r", "\n"), ' ', $c['body']);
        $stream .= pdf_texto("F1", 8.5, 230, $y, '"' . substr($texto_limpio, 0, 48) . '..."');
        $stream .= pdf_texto("F1", 8.5, 490, $y, $c['moderation_status']);
        $stream .= pdf_linea(40, $y - 4, 572, $y - 4);
        $y -= 18;
    }
} else {
    // Por defecto: Reporte de usuarios
    $stream .= pdf_texto("F1", 11, 40, 682, "Reporte Global de Cuentas y Accesos");
    $stream .= pdf_texto("F1", 9, 40, 668, "Fecha de extraccion: " . date('d/m/Y H:i'));
    
    // Encabezados de tabla
    $stream .= pdf_rect(40, 630, 532, 18, 241, 245, 249);
    $stream .= pdf_texto("F2", 9, 45, 635, "ID");
    $stream .= pdf_texto("F2", 9, 90, 635, "Correo Electronico");
    $stream .= pdf_texto("F2", 9, 320, 635, "Rol");
    $stream .= pdf_texto("F2", 9, 440, 635, "Estado Actual");
    $stream .= pdf_linea(40, 630, 572, 630);
    
    // Datos SQL
    $usuarios = $pdo->query("SELECT u.id, u.email, u.role_id, u.status FROM users u ORDER BY u.id ASC LIMIT 25")->fetchAll();
    $y = 612;
    foreach ($usuarios as $u) {
        $rolStr = ((int)$u['role_id'] === 1) ? 'admin' : 'user';
        $stream .= pdf_texto("F1", 9, 45, $y, $u['id']);
        $stream .= pdf_texto("F1", 9, 90, $y, $u['email']);
        $stream .= pdf_texto("F1", 9, 320, $y, $rolStr);
        $stream .= pdf_texto("F1", 9, 440, $y, $u['status']);
        $stream .= pdf_linea(40, $y - 4, 572, $y - 4);
        $y -= 18;
    }
}

// --- PIE DE PÁGINA ---
$stream .= pdf_linea(40, 50, 572, 50);
$stream .= pdf_texto("F1", 8, 40, 38, "Documento interno generado automaticamente de forma segura desde el panel.");

// Ensamblado estructural binario conforme a las especificaciones PDF 1.4
$len = strlen($stream);
echo "%PDF-1.4\n";
echo "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
echo "2 0 obj\n<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>\nendobj\n";
echo "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R /MediaBox [0 0 612 792] >>\nendobj\n";
echo "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
echo "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";
echo "6 0 obj\n<< /Length $len >>\nstream\n" . $stream . "endstream\nendobj\n";
echo "xref\n0 7\n0000000000 65535 f\n";
echo "trailer\n<< /Size 7 /Root 1 0 R >>\n";
echo "%%EOF";
exit;