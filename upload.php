<?php
/**
 * Webistzu Payment Screenshot Upload Handler
 * Saves screenshots to ./img/trans/ with metadata JSON sidecar
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ── Only allow POST ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

/* ── Upload directory ── */
$uploadDir = __DIR__ . '/img/trans/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Cannot create upload directory']);
        exit;
    }
}

/* ── Validate file present ── */
if (empty($_FILES['screenshot']) || $_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['screenshot']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg  = match($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds size limit',
        UPLOAD_ERR_NO_FILE   => 'No file selected',
        UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded',
        default              => 'Upload error (code ' . $code . ')',
    };
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$file = $_FILES['screenshot'];

/* ── Size limit: 10 MB ── */
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'File exceeds 10 MB limit']);
    exit;
}

/* ── MIME type validation (read from file, not header) ── */
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$finfo   = finfo_open(FILEINFO_MIME_TYPE);
$mime    = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    echo json_encode(['success' => false, 'error' => 'Invalid file type — only JPEG, PNG, WEBP, GIF allowed']);
    exit;
}

/* ── Sanitise metadata from POST ── */
function clean(string $val, int $max = 60): string {
    return substr(preg_replace('/[^a-zA-Z0-9\-_. @]/', '_', trim($val)), 0, $max);
}

$invoice = clean($_POST['invoice'] ?? 'UNKNOWN', 40);
$utr     = clean($_POST['utr']     ?? 'UNKNOWN', 30);
$name    = clean($_POST['name']    ?? 'CLIENT',  30);

/* ── Build descriptive filename ── */
$extMap  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$ext     = $extMap[$mime];
$ts      = date('Ymd_His');
$slug    = "{$invoice}_{$utr}_{$name}_{$ts}";
$fname   = $slug . '.' . $ext;
$dest    = $uploadDir . $fname;

/* ── Move file ── */
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save file to disk']);
    exit;
}

/* ── Write JSON metadata sidecar ── */
$meta = [
    'filename'   => $fname,
    'saved_at'   => date('Y-m-d H:i:s'),
    'invoice'    => $_POST['invoice']  ?? '',
    'utr'        => $_POST['utr']      ?? '',
    'client'     => ['name'    => $_POST['name']    ?? '',
                     'email'   => $_POST['email']   ?? '',
                     'phone'   => $_POST['phone']   ?? ''],
    'service'    => $_POST['service']  ?? '',
    'amount_inr' => $_POST['amount']   ?? '',
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];
$jsonFile = $uploadDir . $slug . '.json';
file_put_contents($jsonFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

/* ── Success ── */
echo json_encode([
    'success'  => true,
    'filename' => $fname,
    'path'     => './img/trans/' . $fname,
    'saved_at' => $meta['saved_at'],
]);
