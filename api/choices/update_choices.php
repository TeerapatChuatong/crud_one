<?php
// CRUD/api/choices/update_choices.php
// รองรับทั้ง JSON และ multipart/form-data (อัปโหลดรูปจากเครื่อง)

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

require_once __DIR__ . '/../auth/require_auth.php';
require_admin();

function json_ok($data = []) {
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_err($message, $code = 400, $extra = []) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}

function is_multipart() {
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  return stripos($ct, 'multipart/form-data') !== false;
}

function read_body_any() {
  if (is_multipart()) return [$_POST, $_FILES];
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];
  return [$data, []];
}

function save_uploaded_image($file, $subdir) {
  if (!isset($file) || !is_array($file)) return null;
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

  $projectRoot = dirname(__DIR__, 2);
  $uploadDirAbs = $projectRoot . '/uploads/' . $subdir;
  if (!is_dir($uploadDirAbs)) {
    if (!mkdir($uploadDirAbs, 0777, true)) json_err('สร้างโฟลเดอร์อัปโหลดไม่ได้', 500);
  }

  $orig = $file['name'] ?? 'upload';
  $ext = pathinfo($orig, PATHINFO_EXTENSION);
  $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
  $ext = $ext ? '.' . strtolower($ext) : '';
  $filename = 'choice_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . $ext;

  $destAbs = $uploadDirAbs . '/' . $filename;
  if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
    json_err('อัปโหลดรูปไม่สำเร็จ', 500);
  }

  return 'uploads/' . $subdir . '/' . $filename;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['PATCH', 'POST', 'PUT'], true)) {
  json_err('Method not allowed', 405);
}

[$data, $files] = read_body_any();

$choice_id = isset($data['choice_id']) ? (int)$data['choice_id'] : 0;
if ($choice_id <= 0) json_err('choice_id ไม่ถูกต้อง');

$choice_label = array_key_exists('choice_label', $data) ? trim((string)$data['choice_label']) : null;

// ✅ รองรับทั้ง choice_text และ choices_text (คำแนะนำ)
$has_choice_text = array_key_exists('choice_text', $data) || array_key_exists('choices_text', $data);
$choice_text = null;
if ($has_choice_text) {
  $raw = $data['choice_text'] ?? ($data['choices_text'] ?? null);
  $choice_text = trim((string)$raw);
  if ($choice_text === '') $choice_text = null; // อนุญาตให้ล้างค่า
}

$image_url = array_key_exists('image_url', $data) ? trim((string)$data['image_url']) : null;
if ($image_url === '') $image_url = null;

$uploadPath = null;
if (!empty($files['image_file'])) {
  $uploadPath = save_uploaded_image($files['image_file'], 'choice_images');
}
if ($uploadPath) $image_url = $uploadPath;

try {
  global $dbh;

  $fields = [];
  $params = [];

  if ($choice_label !== null) {
    if ($choice_label === '') json_err('choice_label ห้ามว่าง');
    $fields[] = "choice_label = ?";
    $params[] = $choice_label;
  }

  // ✅ update เมื่อส่ง choice_text หรือ choices_text มา
  if ($has_choice_text) {
    $fields[] = "choice_text = ?";
    $params[] = $choice_text;
  }

  if ($image_url !== null || array_key_exists('image_url', $data) || $uploadPath) {
    $fields[] = "image_url = ?";
    $params[] = $image_url;
  }

  if (!$fields) json_err('nothing_to_update');

  $params[] = $choice_id;

  $sql = "UPDATE choices SET " . implode(', ', $fields) . " WHERE choice_id = ?";
  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok(['choice_id' => $choice_id]);
} catch (Throwable $e) {
  json_err('อัปเดตไม่สำเร็จ', 500, ['detail' => $e->getMessage()]);
}
