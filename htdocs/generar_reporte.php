<?php
// generar_reporte.php
require_once __DIR__ . '/includes/functions.php';
require_admin();

$tipo = $_GET['tipo'] ?? 'usuarios';
$tiposPermitidos = ['usuarios', 'sitios', 'comentarios'];
if (!in_array($tipo, $tiposPermitidos, true)) {
    $tipo = 'usuarios';
}

$pdo = db();

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="reporte_' . $tipo . '_' . date('Ymd') . '.pdf"');

function pdf_escapar($txt): string
{
    $txt = (string)$txt;
    $txt = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $txt);
    $convertido = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $txt);
    return ($convertido !== false) ? $convertido : preg_replace('/[^\x20-\x7E]/', '', $txt);
}

function pdf_texto(string $f, float $size, int $x, int $y, string $txt): string
{
    return sprintf("BT /%s %g Tf 0 g 1 0 0 1 %d %d Tm (%s) Tj ET\n", $f, $size, $x, $y, pdf_escapar($txt));
}

function pdf_texto_color(string $f, float $size, int $x, int $y, string $txt, int $r, int $g, int $b): string
{
    $rc = $r / 255;
    $gc = $g / 255;
    $bc = $b / 255;
    return sprintf("BT /%s %g Tf %.3f %.3f %.3f rg 1 0 0 1 %d %d Tm (%s) Tj ET\n", $f, $size, $rc, $gc, $bc, $x, $y, pdf_escapar($txt));
}

function pdf_rect(int $x, int $y, int $w, int $h, int $r, int $g, int $b): string
{
    $rc = $r / 255;
    $gc = $g / 255;
    $bc = $b / 255;
    return sprintf("%.3f %.3f %.3f rg %d %d %d %d re f\n", $rc, $gc, $bc, $x, $y, $w, $h);
}

function pdf_linea(int $x1, int $y1, int $x2, int $y2, int $r = 226, int $g = 232, int $b = 240): string
{
    $rc = $r / 255;
    $gc = $g / 255;
    $bc = $b / 255;
    return sprintf("%.3f %.3f %.3f RG 1 w %d %d m %d %d l S\n", $rc, $gc, $bc, $x1, $y1, $x2, $y2);
}

$stream = "";

// Encabezado
$stream .= pdf_rect(40, 715, 532, 60, 248, 250, 252);
$stream .= pdf_rect(40, 715, 6, 60, 249, 115, 22);
$stream .= pdf_rect(515, 723, 42, 42, 249, 115, 22);
$stream .= pdf_rect(523, 731, 26, 26, 30, 41, 59);
$stream .= pdf_texto_color("F2", 14, 531, 739, "T", 255, 255, 255);
$stream .= pdf_texto_color("F2", 16, 60, 750, "TURSEG INFO - SISTEMA GENERAL", 15, 23, 42);
$stream .= pdf_texto("F1", 10, 60, 734, "Reporte Oficial Automatizado de Auditoria | Filtro: " . strtoupper($tipo));
$stream .= pdf_texto("F1", 9, 60, 722, "Fecha de emision: " . date('d/m/Y H:i:s') . " | Estado: Valido");

if ($tipo === 'sitios') {
    $stream .= pdf_rect(40, 670, 532, 22, 30, 41, 59);
    $stream .= pdf_texto_color("F2", 9, 45, 677, "ID", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 80, 677, "NOMBRE DEL SITIO TURISTICO", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 270, 677, "UBICACION / CIUDAD", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 410, 677, "SUBIDO POR", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 515, 677, "ESTADO", 255, 255, 255);

    $sitios = $pdo->query("
        SELECT p.id, p.name, p.status, c.city_name, u.email
        FROM places p
        LEFT JOIN cities c ON c.id = p.city_id
        LEFT JOIN users u ON u.id = p.creator_user_id
        ORDER BY p.id ASC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);

    $y = 645;
    foreach ($sitios as $s) {
        if ($y < 80) {
            break;
        }

        $creador = !empty($s['email']) ? explode('@', $s['email'])[0] : 'Sistema';
        $ciudad = !empty($s['city_name']) ? $s['city_name'] : 'Colombia';

        $stream .= pdf_texto("F2", 9, 45, $y, "#" . $s['id']);
        $stream .= pdf_texto("F1", 9, 80, $y, substr((string)$s['name'], 0, 32));
        $stream .= pdf_texto("F1", 9, 270, $y, substr($ciudad, 0, 22));
        $stream .= pdf_texto_color("F1", 9, 410, $y, substr($creador, 0, 16), 22, 163, 74);
        $stream .= pdf_texto("F1", 9, 515, $y, (string)$s['status']);
        $stream .= pdf_linea(40, $y - 6, 572, $y - 6);
        $y -= 22;
    }

} elseif ($tipo === 'comentarios') {
    $stream .= pdf_rect(40, 670, 532, 22, 30, 41, 59);
    $stream .= pdf_texto_color("F2", 9, 45, 677, "ID", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 80, 677, "AUTOR", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 180, 677, "RESEÑA / COMENTARIO COMPLETO", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 505, 677, "MODERACION", 255, 255, 255);

    $comentarios = $pdo->query("
        SELECT co.id, co.body, co.moderation_status, u.email
        FROM place_comments co
        LEFT JOIN users u ON u.id = co.user_id
        ORDER BY co.id DESC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);

    $y = 645;
    foreach ($comentarios as $c) {
        if ($y < 80) {
            break;
        }

        $autor = !empty($c['email']) ? explode('@', $c['email'])[0] : 'Anonimo';
        $estadoMod = !empty($c['moderation_status']) ? $c['moderation_status'] : 'pending';

        $comentarioLimpio = str_replace(["\r", "\n"], " ", (string)$c['body']);
        $lineas = explode("\n", wordwrap($comentarioLimpio, 62, "\n", true));

        $totalLineas = count($lineas);
        $altoBloqueTexto = $totalLineas * 12;
        $altoRegistroTotal = max(24, $altoBloqueTexto + 12);

        if (($y - $altoRegistroTotal) < 60) {
            break;
        }

        $stream .= pdf_texto("F2", 9, 45, $y, "#" . $c['id']);
        $stream .= pdf_texto("F1", 9, 80, $y, substr($autor, 0, 15));
        $stream .= pdf_texto("F1", 9, 505, $y, $estadoMod);

        $lineaY = $y;
        foreach ($lineas as $lineaTexto) {
            $stream .= pdf_texto("F1", 8, 180, $lineaY, trim($lineaTexto));
            $lineaY -= 12;
        }

        $y_separador = $y - $altoBloqueTexto - 4;
        $stream .= pdf_linea(40, $y_separador, 572, $y_separador);
        $y = $y_separador - 14;
    }

} else {
    $stream .= pdf_rect(40, 670, 532, 22, 30, 41, 59);
    $stream .= pdf_texto_color("F2", 9, 45, 677, "ID", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 90, 677, "CORREO ELECTRONICO INSTITUCIONAL / CUENTA", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 340, 677, "ROL PRIVILEGIO", 255, 255, 255);
    $stream .= pdf_texto_color("F2", 9, 460, 677, "ESTADO ACTUAL", 255, 255, 255);

    $usuarios = $pdo->query("SELECT u.id, u.email, u.role_id, u.status FROM users u ORDER BY u.id ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

    $y = 645;
    foreach ($usuarios as $u) {
        if ($y < 80) {
            break;
        }

        $rolStr = ((int)$u['role_id'] === 1) ? 'Administrador' : 'Turista';

        $stream .= pdf_texto("F1", 9, 45, $y, (string)$u['id']);
        $stream .= pdf_texto("F1", 9, 90, $y, (string)$u['email']);
        $stream .= pdf_texto("F1", 9, 340, $y, $rolStr);
        $stream .= pdf_texto("F1", 9, 460, $y, (string)$u['status']);

        $stream .= pdf_linea(40, $y - 6, 572, $y - 6);
        $y -= 22;
    }
}

$stream .= pdf_linea(40, 55, 572, 55, 203, 213, 225);
$stream .= pdf_texto("F1", 8, 40, 42, "Documento interno de control emitido de manera digital. Protegido bajo las politicas de TURSEG Colombia.");
$stream .= pdf_texto_color("F2", 8, 490, 42, "PAGINA 1 DE 1", 100, 116, 139);

$objects = [];
$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
$objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
$objects[3] = "<< /Type /Page /Parent 2 0 R /Resources 4 0 R /MediaBox [0 0 612 792] /Contents 5 0 R >>";
$objects[4] = "<< /Type /Resources /Font << /F1 6 0 R /F2 7 0 R >> /ProcSet [/PDF /Text] >>";
$objects[5] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
$objects[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
$objects[7] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

$pdf = "%PDF-1.4\n";
$offsets = [0 => 0];
for ($i = 1; $i <= 7; $i++) {
    $offsets[$i] = strlen($pdf);
    $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
}
$xrefPos = strlen($pdf);
$pdf .= "xref\n0 8\n";
$pdf .= "0000000000 65535 f \n";
for ($i = 1; $i <= 7; $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
}
$pdf .= "trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";

echo $pdf;
