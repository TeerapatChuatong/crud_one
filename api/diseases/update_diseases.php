<?php
require_once __DIR__ . '/../db.php';
require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['POST', 'PATCH'], true)) {
  json_err("METHOD_NOT_ALLOWED", "post_or_patch_only", 405);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson = stripos($contentType, 'application/json') !== false;

// ---------- อ่าน body ----------
$body = [];

if ($method === 'PATCH') {
  // PATCH = JSON เท่านั้น
  $body = json_decode(file_get_contents("php://input"), true) ?: [];
} else {
  // POST = ได้ทั้ง JSON และ multipart/form-data
  if ($isJson) {
    $body = json_decode(file_get_contents("php://input"), true) ?: [];
  } else {
    $body = $_POST ?: [];
  }
}

// ---------- validate ----------
$disease_id = trim((string)($body['disease_id'] ?? ''));
if ($disease_id === '') {
  json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
}

// ตรวจสอบว่า disease_id มีอยู่จริง
try {
  $check = $dbh->prepare("SELECT disease_id FROM diseases WHERE disease_id = ?");
  $check->execute([$disease_id]);
  if (!$check->fetch()) {
    json_err("NOT_FOUND", "disease_not_found", 404);
  }
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}

$normNullableText = function ($v) {
  if ($v === null) return null;
  if (!is_string($v)) $v = (string)$v;
  $v = trim($v);
  return $v === '' ? null : $v;
};

// รองรับทั้ง name_th/name_en และ disease_th/disease_en
$th_input = $body['name_th'] ?? $body['disease_th'] ?? null;
$en_input = $body['name_en'] ?? $body['disease_en'] ?? null;

// ---------- อัปโหลดรูป (POST + multipart) ----------
$uploadedPath = null;

if ($method === 'POST' && !$isJson && isset($_FILES['image'])) {
  $f = $_FILES['image'];

  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
      json_err("UPLOAD_ERROR", "upload_failed", 400);
    }

    // จำกัดชนิดไฟล์
    $name = $f['name'] ?? 'image';
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allow = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allow, true)) {
      json_err("UPLOAD_ERROR", "invalid_file_type", 400);
    }

    // จำกัดขนาด (10MB)
    $size = (int)($f['size'] ?? 0);
    if ($size > 10 * 1024 * 1024) {
      json_err("UPLOAD_ERROR", "file_too_large", 400);
    }

    $dir = __DIR__ . '/../../uploads/disease';
    if (!is_dir($dir)) {
      @mkdir($dir, 0777, true);
    }

    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($name, PATHINFO_FILENAME));
    $filename = $safe . '_' . date('Ymd_His') . '.' . $ext;

    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
      json_err("UPLOAD_ERROR", "move_file_failed", 500);
    }

    // เก็บ path ให้เหมือนใน DB ของคุณ
    $uploadedPath = "uploads/disease/" . $filename;

    // ถ้ามีไฟล์ -> บังคับอัปเดต image_url เป็นไฟล์ที่อัปโหลด
    $body['image_url'] = $uploadedPath;
  }
}

// ---------- สร้างชุด UPDATE ----------
$fields = [];
$params = [];

// ชื่อไทย (ถ้ามีส่งมา ต้องไม่ว่าง)
if ($th_input !== null) {
  $disease_th = trim((string)$th_input);
  if ($disease_th === '') json_err("VALIDATION_ERROR", "disease_th_required", 400);
  $fields[] = "disease_th = ?";
  $params[] = $disease_th;
}

// ชื่ออังกฤษ (ถ้ามีส่งมา ต้องไม่ว่าง)
if ($en_input !== null) {
  $disease_en = trim((string)$en_input);
  if ($disease_en === '') json_err("VALIDATION_ERROR", "disease_en_required", 400);
  $fields[] = "disease_en = ?";
  $params[] = $disease_en;
}

if (array_key_exists('causes', $body)) {
  $fields[] = "causes = ?";
  $params[] = $normNullableText($body['causes']);
}

// รองรับทั้ง symptom และ symptoms (แก้ตาม DB)
if (array_key_exists('symptom', $body) || array_key_exists('symptoms', $body)) {
  $fields[] = "symptom = ?";
  $symptom_value = $body['symptom'] ?? $body['symptoms'] ?? null;
  $params[] = $normNullableText($symptom_value);
}

if (array_key_exists('image_url', $body)) {
  $fields[] = "image_url = ?";
  $params[] = $normNullableText($body['image_url']);
}

if (!$fields) {
  json_err("VALIDATION_ERROR", "nothing_to_update", 400);
}

$params[] = $disease_id;

try {
  $sql = "UPDATE diseases SET " . implode(", ", $fields) . " WHERE disease_id = ?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);

  // ส่งค่ากลับ
  $q = $dbh->prepare("SELECT * FROM diseases WHERE disease_id = ? LIMIT 1");
  $q->execute([$disease_id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);

  json_ok($row);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
