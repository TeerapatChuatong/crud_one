<?php
// api/care_logs/create_care_logs.php
require_once __DIR__ . '/../db.php';

// ต้องล็อกอินก่อน
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

// ดึง user_id จาก session
$currentUserId = (string)($_SESSION['user_id'] ?? '');
if ($currentUserId === '') {
  json_err("UNAUTHORIZED", "no_user_in_session", 401);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

// ===== รับค่าจาก body =====
$tree_id       = trim($body['tree_id']       ?? '');
$care_type     = trim($body['care_type']     ?? '');
$care_date     = trim($body['care_date']     ?? '');
$product_name  = trim($body['product_name']  ?? '');
$amount        = $body['amount']             ?? null;
$unit          = trim($body['unit']          ?? '');
$area          = trim($body['area']          ?? '');
$note          = trim($body['note']          ?? '');

$allowed_types = ['fertilizer','pesticide','watering','pruning','other'];

// ===== ตรวจข้อมูลจำเป็น =====
if ($tree_id === '') {
  json_err("VALIDATION_ERROR", "tree_id_required", 400);
}

if ($care_type === '' || !in_array($care_type, $allowed_types, true)) {
  json_err("VALIDATION_ERROR", "invalid_care_type", 400);
}

if ($care_date === '') {
  json_err("VALIDATION_ERROR", "care_date_required", 400);
}

// amount เป็น optional แต่ถ้าส่งมาก็ต้องเป็นตัวเลข
if ($amount !== null && $amount !== '' && !is_numeric($amount)) {
  json_err("VALIDATION_ERROR", "invalid_amount", 400);
}
$amount = ($amount === '' ? null : ($amount === null ? null : (float)$amount));

// product_name / unit / area / note ว่างได้ → เก็บเป็น NULL
$product_name = ($product_name === '' ? null : $product_name);
$unit         = ($unit === ''         ? null : $unit);
$area         = ($area === ''         ? null : $area);
$note         = ($note === ''         ? null : $note);

try {
  // log_id เป็น AUTO_INCREMENT → ไม่ต้องส่ง
  $sql = "INSERT INTO care_logs (
            user_id,
            tree_id,
            care_type,
            care_date,
            product_name,
            amount,
            unit,
            area,
            note
          ) VALUES (?,?,?,?,?,?,?,?,?)";

  $st = $dbh->prepare($sql);
  $st->execute([
    $currentUserId,
    $tree_id,
    $care_type,
    $care_date,
    $product_name,
    $amount,
    $unit,
    $area,
    $note,
  ]);

  $log_id = (int)$dbh->lastInsertId();

  json_ok([
    "log_id"       => $log_id,
    "user_id"      => $currentUserId,
    "tree_id"      => $tree_id,
    "care_type"    => $care_type,
    "care_date"    => $care_date,
    "product_name" => $product_name,
    "amount"       => $amount,
    "unit"         => $unit,
    "area"         => $area,
    "note"         => $note,
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
