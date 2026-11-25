<?php
/**
 * Create / edit event › 6. Program Flow › "Upload program flow".
 *   POST (file)        read an Excel (.xlsx), CSV, Word (.docx) or text file and
 *                      answer with its parts as JSON — the form fills them in
 *                      (they're saved with the event, not here).
 *   GET ?template=1    a blank Excel template (Time, Part, Speaker / in charge, Notes).
 * Admins and Super Admins only (the people who create events).
 */
require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../includes/spreadsheet.php';

require_staff();

if (get_param('template') !== '') {
    send_xlsx('program-flow-template.xlsx', 'Program Flow', ['Time', 'Part', 'Speaker / in charge', 'Notes'], [
        ['8:00 AM', 'Opening Prayer', 'Ms. Ana Cruz', ''],
        ['8:05 AM', 'National Anthem', 'LCUP Chorale', 'Everyone please stand'],
        ['8:10 AM', 'Welcome Remarks', 'Dr. Juan Reyes', ''],
    ]);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$answer = function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $answer(['ok' => false, 'message' => 'Upload a file from the Program Flow section.']);
}
$file = $_FILES['file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $answer(['ok' => false, 'message' => 'Choose a file first.']);
}
if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
    $answer(['ok' => false, 'message' => 'That file is too big. Program flow files must be 5 MB or smaller.']);
}
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    $answer(['ok' => false, 'message' => 'The file could not be uploaded. Please try again.']);
}

$answer(read_program_flow_file($file['tmp_name'], (string) ($file['name'] ?? '')));
