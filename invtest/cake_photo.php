<?php
// Receives a photo for the Cake Builder's "photo on top" (edible print) and stores it.
// The builder page crops the customer's picture to a square JPEG in the browser before sending it,
// so anything that isn't a reasonably sized JPEG is refused here.
//   POST  photo=<file>   ->  {"id": "<32 hex characters>", "url": "..."}  or  {"error": "..."}
require_once __DIR__ . '/cake_options.php';

const CAKE_PHOTO_MAX_BYTES = 3 * 1024 * 1024;
const CAKE_PHOTO_MAX_SIDE = 2048;

function photoReply(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') photoReply(['error' => 'Send the photo with POST.'], 405);

$upload = $_FILES['photo'] ?? null;
if (!is_array($upload) || is_array($upload['error'] ?? null) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_uploaded_file($upload['tmp_name'])) {
    photoReply(['error' => 'The photo could not be uploaded. Please try again.'], 400);
}
if ($upload['size'] <= 0 || $upload['size'] > CAKE_PHOTO_MAX_BYTES) {
    photoReply(['error' => 'The photo is too large.'], 400);
}

// Check what the file really is (never trust its name or the type the browser reports).
$info = @getimagesize($upload['tmp_name']);
if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
    photoReply(['error' => 'The photo must be a JPEG picture.'], 400);
}
if ($info[0] < 16 || $info[1] < 16 || $info[0] > CAKE_PHOTO_MAX_SIDE || $info[1] > CAKE_PHOTO_MAX_SIDE) {
    photoReply(['error' => 'The photo has an unsupported size.'], 400);
}

if (!is_dir(CAKE_PHOTO_DIR) && !mkdir(CAKE_PHOTO_DIR, 0775, true)) {
    photoReply(['error' => 'The photo could not be saved.'], 500);
}
// A random name of our own: the customer's file name is never used.
$id = bin2hex(random_bytes(16));
if (!move_uploaded_file($upload['tmp_name'], CAKE_PHOTO_DIR . '/' . $id . '.jpg')) {
    photoReply(['error' => 'The photo could not be saved.'], 500);
}

photoReply(['id' => $id, 'url' => CAKE_PHOTO_URL . $id . '.jpg']);
