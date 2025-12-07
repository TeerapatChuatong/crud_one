<?php
// api/care_reminders/create_care_reminders.php
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

// รับ JSON body
$body = json_decode(file_get_contents("php://input"), true) ?: [];

// ===== รับค่าจาก body =====
$tree_id     = trim($body['tree_id']     ?? '');
$care_type   = trim($body['care_type']   ?? '');
$title       = trim($body['title']       ?? '');
$description = trim($body['description'] ?? '');
$remind_date = trim($body['remind_date'] ?? '');
$related_log_id = $body['related_log_id'] ?? null;  // optional

$allowed_types = ['fertilizer','pesticide','watering','pruning','other'];

// ===== ตรวจข้อมูลจำเป็น =====
if ($tree_id === '') {
  json_err("VALIDATION_ERROR", "tree_id_required", 400);
}

if ($care_type === '' || !in_array($care_type, $allowed_types, true)) {
  json_err("VALIDATION_ERROR", "invalid_care_type", 400);
}

if ($title === '') {
  json_err("VALIDATION_ERROR", "title_required", 400);
}

if ($remind_date === '') {
  json_err("VALIDATION_ERROR", "remind_date_required", 400);
}

// related_log_id เป็น optional แต่ถ้าส่งมาก็ต้องเป็นตัวเลข
if ($related_log_id !== null && $related_log_id !== '' && !ctype_digit((string)$related_log_id)) {
  json_err("VALIDATION_ERROR", "invalid_related_log_id", 400);
}
$related_log_id = ($related_log_id === '' ? null : $related_log_id);

// description ว่างได้ → เก็บเป็น NULL
$description = ($description === '' ? null : $description);

try {
  // reminder_id เป็น AUTO_INCREMENT อยู่แล้ว ไม่ต้องระบุ
  // is_done ให้เริ่มต้นเป็น 0 (ยังไม่ทำ)
  $sql = "INSERT INTO care_reminders (
            user_id,
            tree_id,
            care_type,
            title,
            description,
            remind_date,
            is_done,
            related_log_id
          ) VALUES (?,?,?,?,?,?,0,?)";

  $st = $dbh->prepare($sql);
  $st->execute([
    $currentUserId,
    $tree_id,
    $care_type,
    $title,
    $description,
    $remind_date,
    $related_log_id,
  ]);

  $reminder_id = (int)$dbh->lastInsertId();

  json_ok([
    "reminder_id"   => $reminder_id,
    "user_id"       => $currentUserId,
    "tree_id"       => $tree_id,
    "care_type"     => $care_type,
    "title"         => $title,
    "description"   => $description,
    "remind_date"   => $remind_date,
    "is_done"       => 0,
    "related_log_id"=> $related_log_id,
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
