<?php
require_once __DIR__ . '/../config.php';
session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'dean') die('Forbidden');
$project_id = intval($_GET['project_id'] ?? 0);
if (!$project_id) die('Missing project_id');

// Render report HTML into buffer
$html = '';
ob_start();
include __DIR__ . '/generate_report.php';
$html = ob_get_clean();

// Try Dompdf from the Composer dependencies stored in php/vendor.
if (file_exists(__DIR__ . '/../php/vendor/autoload.php')) {
    require_once __DIR__ . '/../php/vendor/autoload.php';
    try {
        if (class_exists('Dompdf\\Dompdf')) {
            $dompdfClass = 'Dompdf\\Dompdf';
            $dompdf = new $dompdfClass();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdf = $dompdf->output();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="report.pdf"');
            echo $pdf; exit;
        }
    } catch (Exception $e) {
        // continue to wkhtmltopdf fallback
    }
}

// Fallback to wkhtmltopdf if available
$tmpHtml = sys_get_temp_dir() . '/cap_report_'.uniqid().'.html';
$tmpPdf = sys_get_temp_dir() . '/cap_report_'.uniqid().'.pdf';
file_put_contents($tmpHtml,$html);
$cmd = "wkhtmltopdf --enable-local-file-access " . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf);
exec($cmd, $out, $rc);
if ($rc === 0 && file_exists($tmpPdf)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="report.pdf"');
    readfile($tmpPdf);
    @unlink($tmpHtml); @unlink($tmpPdf);
    exit;
}

// Final fallback: show HTML and instruct user
header('Content-Type: text/html');
echo "<h3>Server-side PDF generation not available.</h3><p>Install Dompdf via Composer or wkhtmltopdf on the server, or use the Print button.</p>";
echo "<p><a href=\"generate_report.php?project_id={$project_id}\">Open Report HTML</a></p>";
@unlink($tmpHtml);
