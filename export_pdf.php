<?php
require_once __DIR__ . '/includes/security.php';
require_once 'includes/db.php';
med_require_user($conn);
require_once 'includes/functions.php';
require_once 'secret/secret.php';
require_once 'libs/dompdf/autoload.inc.php'; // Or your dompdf path

use Dompdf\Dompdf;

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}
$is_pdf = true;

ob_start(); // Start output buffering
include 'print.php'; // This will generate the full HTML including inline CSS
$html = ob_get_clean(); // Store the buffered output into $html

$dompdf = new Dompdf();
$dompdf->loadHtml($html);

// Optional: Set options for better compatibility
$options = $dompdf->getOptions();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false); // Medical PDF must not fetch remote resources
$dompdf->setOptions($options);

//$dompdf->setPaper('A4', 'portrait');
$dompdf->setPaper('letter', 'portrait'); // or A4
$dompdf->render();
$dompdf->stream("medical_summary.pdf", ["Attachment" => true]);
