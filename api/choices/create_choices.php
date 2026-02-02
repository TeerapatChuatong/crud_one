<?php
// CRUD/api/choices/create_choices.php
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
  if (is_multipart()) {
    return [$_POST, $_FILES];
  }
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
    if (!mkdir($uploadDirAbs, 0777, true)) {
      json_err('ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้', 500);
    }
  }

  $orig = $file['name'] ?? 'upload';
  $ext = pathinfo($orig, PATHINFO_EXTENSION);
  $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
  $ext = $ext ? ('.' . strtolower($ext)) : '';
  $filename = 'choice_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . $ext;
  $destAbs = $uploadDirAbs . '/' . $filename;
  if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
    json_err('อัปโหลดรูปไม่สำเร็จ', 500);
  }

  return 'uploads/' . $subdir . '/' . $filename;
}

function base_url() {
  $https = $_SERVER['HTTPS'] ?? '';
  $scheme = (!empty($https) && strtolower($https) !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

  // script: /crud/api/choices/create_choices.php -> base: /crud
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $basePath = rtrim(dirname(dirname($script)), '/'); // /crud/api
  if (substr($basePath, -4) === '/api') $basePath = substr($basePath, 0, -4); // /crud

  return $scheme . '://' . $host . $basePath;
}

function to_public_url($url) {
  if (!is_string($url) || $url === '') return $url;
  if (preg_match('#^https?://#i', $url)) return $url;

  $https = $_SERVER['HTTPS'] ?? '';
  $scheme = (!empty($https) && strtolower($https) !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

  // ถ้าเป็น absolute path บน host เดียวกัน
  if (substr($url, 0, 1) === '/') {
    return $scheme . '://' . $host . $url;
  }

  // relative เช่น uploads/...
  return rtrim(base_url(), '/') . '/' . ltrim($url, '/');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err('Method not allowed', 405);
}

[$data, $files] = read_body_any();

$question_id  = isset($data['question_id']) ? (int)$data['question_id'] : 0;
$choice_label = trim((string)($data['choice_label'] ?? ''));

// ✅ รองรับทั้ง choice_text และ choices_text (คำแนะนำ)
$has_choice_text = array_key_exists('choice_text', $data) || array_key_exists('choices_text', $data);
$choice_text_raw = $data['choice_text'] ?? ($data['choices_text'] ?? null);
$choice_text = $has_choice_text ? trim((string)$choice_text_raw) : null;
if ($choice_text === '') $choice_text = null;

$image_url = $data['image_url'] ?? null;
if (is_string($image_url)) $image_url = trim($image_url);
if ($image_url === '') $image_url = null;

$uploadPath = null;
if (!empty($files['image_file'])) {
  $uploadPath = save_uploaded_image($files['image_file'], 'choice_images');
}
if ($uploadPath) {
  $image_url = $uploadPath; // เก็บเป็น relative ใน DB
}

if ($question_id <= 0) json_err('question_id ไม่ถูกต้อง');
if ($choice_label === '') json_err('choice_label ห้ามว่าง');

try {
  global $dbh;

  $sql = "INSERT INTO choices (question_id, choice_label, choice_text, image_url)
          VALUES (:qid, :lbl, :txt, :img)";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([
    ':qid' => $question_id,
    ':lbl' => $choice_label,
    ':txt' => $choice_text,
    ':img' => $image_url,
  ]);

  $choice_id = (int)$dbh->lastInsertId();

  // ✅ ตอบกลับเป็น public URL เพื่อให้ frontend แสดงรูปได้
  $public_img = $image_url ? to_public_url($image_url) : null;

  json_ok([
    'choice_id'   => $choice_id,
    'question_id' => $question_id,
    'choice_label'=> $choice_label,

    // ให้ frontend ใช้ได้ทั้ง 2 ชื่อ
    'choice_text'  => $choice_text,
    'choices_text' => $choice_text,

    'image_url' => $public_img,
    'imageUrl'  => $public_img,
  ]);
} catch (Throwable $e) {
  json_err('บันทึกไม่สำเร็จ', 500, ['detail' => $e->getMessage()]);
}
