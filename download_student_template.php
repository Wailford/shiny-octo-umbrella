<?php
/**
 * Download Student Bulk Import CSV Template
 * Requires login — staff only.
 */
require_once __DIR__ . '/check_session.php';

$filename = 'student_import_template.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, [
    'Student Name',
    'Parent/Guardian Name',
    'Parent Phone',
    'Parent WhatsApp',
    'Parent Email',
]);

// Example rows
fputcsv($out, ['Ama Mensah', 'Kofi Mensah', '0244123456', '0244123456', 'kofi.mensah@example.com']);
fputcsv($out, ['Yaw Asante', 'Akua Asante', '0554987654', '0554987654', '']);
fputcsv($out, ['Abena Owusu', 'Emmanuel Owusu', '0201234567', '', '']);

fclose($out);
exit;
