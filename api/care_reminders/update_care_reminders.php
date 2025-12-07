<?php
// api/care_reminders/update_care_reminders.php
require_once __DIR__ . '/../db.php';

require_login(); // ต้องล็อกอินก่อน

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "patch_only", 405);
}

// อ่าน JSON body
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!is_array($data)) {
  json_err("INVALID_JSON", "invalid_json", 400);
}

// --------- ดึงค่าและ validate เบื้องต้น ----------
$reminder_id   = $data['reminder_id']   ?? null;
$is_done_input = $data['is_done']       ?? null;   // optional
$related_logId = $data['related_log_id'] ?? null;  // optional

if ($reminder_id === null || !ctype_digit((string)$reminder_id)) {
  json_err("VALIDATION_ERROR", "reminder_id_required", 400);
}
$reminder_id = (int)$reminder_id;

$currentUserId = (string)($_SESSION['user_id'] ?? '');
$is_admin      = is_admin();

try {
  // 1) ดึง reminder เดิมมาก่อน
  $st = $dbh->prepare("SELECT * FROM care_reminders WHERE reminder_id = ?");
  $st->execute([$reminder_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_err("NOT_FOUND", "reminder_not_found", 404);
  }

  // 2) ตรวจสิทธิ์: user ทั่วไปแก้ได้เฉพาะของตัวเอง
  if (!$is_admin && (string)$row['user_id'] !== $currentUserId) {
    json_err("FORBIDDEN", "cannot_edit_other_user_reminder", 403);
  }

  // 3) เตรียมค่าที่จะอัปเดต
  $new_is_done = $row['is_done']; // ค่าเดิมใน DB (0/1)

  if ($is_done_input !== null) {
    // แปลง bool/string -> 0/1
    if (is_bool($is_done_input)) {
      $new_is_done = $is_done_input ? 1 : 0;
    } elseif ($is_done_input === 1 || $is_done_input === 0 || $is_done_input === "1" || $is_done_input === "0") {
      $new_is_done = (int)$is_done_input;
    } else {
      json_err("VALIDATION_ERROR", "invalid_is_done", 400);
    }
  }

  // ค่าเริ่มต้นของ related_log_id ใช้ของเดิม
  $new_related_log_id = $row['related_log_id'];

  // 4) ถ้ามีส่ง related_log_id มา → ตรวจว่า care_logs มีจริง และเป็นของ user นี้ (หรืออย่างน้อยมีอยู่)
  if ($related_logId !== null) {
    if (!ctype_digit((string)$related_logId)) {
      json_err("VALIDATION_ERROR", "invalid_related_log_id", 400);
    }
    $related_logId = (int)$related_logId;

    // ตรวจ log ในตาราง care_logs
    $sqlLog = "SELECT * FROM care_logs WHERE log_id = ?";
    $paramsLog = [$related_logId];

    // ถ้าไม่ใช่ admin → จำกัดให้ต้องเป็น log ของ user นี้
    if (!$is_admin) {
      $sqlLog .= " AND user_id = ?";
      $paramsLog[] = $currentUserId;
    }

    $stLog = $dbh->prepare($sqlLog);
    $stLog->execute($paramsLog);
    $log = $stLog->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
      json_err("VALIDATION_ERROR", "related_log_not_found", 400);
    }

    $new_related_log_id = $related_logId;
  }

  // 5) อัปเดตฐานข้อมูล
  $stUpd = $dbh->prepare("
    UPDATE care_reminders
    SET
      is_done       = ?,
      related_log_id = ?
    WHERE reminder_id = ?
  ");
  $stUpd->execute([
    $new_is_done,
    $new_related_log_id,
    $reminder_id,
  ]);

  // ดึงข้อมูลล่าสุดกลับไปให้
  $st2 = $dbh->prepare("SELECT * FROM care_reminders WHERE reminder_id = ?");
  $st2->execute([$reminder_id]);
  $updated = $st2->fetch(PDO::FETCH_ASSOC);

  json_ok($updated);

} catch (Throwable $e) {
  // ถ้าอยาก debug ให้โชว์ error จริง ๆ ชั่วคราว:
  // json_err("DB_ERROR", $e->getMessage(), 500);
  json_err("DB_ERROR", "db_error", 500);
}
