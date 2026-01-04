<?php
// api/care_reminders/create_care_reminders.php
require_once __DIR__ . '/../db.php';

$authFile = __DIR__ . '/../auth/require_auth.php';
if (file_exists($authFile)) require_once $authFile;
if (function_exists('require_auth')) { require_auth(); }
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$is_admin = function_exists('is_admin') ? is_admin() : false;

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$tree_id = $body['tree_id'] ?? null;
$diagnosis_history_id = $body['diagnosis_history_id'] ?? null;
$treatment_id = $body['treatment_id'] ?? null;
$reminder_date = trim((string)($body['reminder_date'] ?? ''));
$note = $body['note'] ?? null;
$is_done = $body['is_done'] ?? 0;

if ($tree_id === null || !ctype_digit((string)$tree_id)) {
  json_err("VALIDATION_ERROR", "tree_id_required", 400);
}
$tree_id = (int)$tree_id;

if ($reminder_date === '') {
  json_err("VALIDATION_ERROR", "reminder_date_required", 400);
}
$dt = DateTime::createFromFormat('Y-m-d', $reminder_date);
if (!$dt || $dt->format('Y-m-d') !== $reminder_date) {
  json_err("VALIDATION_ERROR", "invalid_reminder_date_format_use_Y-m-d", 400);
}

if ($diagnosis_history_id !== null && $diagnosis_history_id !== '' && !ctype_digit((string)$diagnosis_history_id)) {
  json_err("VALIDATION_ERROR", "invalid_diagnosis_history_id", 400);
}
$diagnosis_history_id = ($diagnosis_history_id === null || $diagnosis_history_id === '') ? null : (int)$diagnosis_history_id;

if ($treatment_id !== null && $treatment_id !== '' && !ctype_digit((string)$treatment_id)) {
  json_err("VALIDATION_ERROR", "invalid_treatment_id", 400);
}
$treatment_id = ($treatment_id === null || $treatment_id === '') ? null : (int)$treatment_id;

$is_done = ($is_done == 1 || $is_done === true || $is_done === "1") ? 1 : 0;
$note = is_string($note) ? trim($note) : $note;
$note = ($note === '' ? null : $note);

try {
  // ✅ ตรวจว่า tree_id เป็นของ user นี้ (กันยิง tree คนอื่น)
  if (!$is_admin) {
    $st = $dbh->prepare("SELECT 1 FROM orange_trees WHERE tree_id=? AND user_id=?");
    $st->execute([$tree_id, $currentUserId]);
    if (!$st->fetchColumn()) {
      json_err("FORBIDDEN", "tree_not_belong_to_user", 403);
    }
  }

  // ✅ ถ้าส่ง diagnosis_history_id มา ตรวจว่าของ user และ tree ตรงกัน
  if ($diagnosis_history_id !== null) {
    $sql = "SELECT user_id, tree_id FROM diagnosis_history WHERE diagnosis_history_id=?";
    $st = $dbh->prepare($sql);
    $st->execute([$diagnosis_history_id]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (!$h) json_err("VALIDATION_ERROR", "diagnosis_history_not_found", 400);

    if (!$is_admin && (int)$h['user_id'] !== $currentUserId) {
      json_err("FORBIDDEN", "history_not_belong_to_user", 403);
    }
    if ((int)$h['tree_id'] !== $tree_id) {
      json_err("VALIDATION_ERROR", "history_tree_id_mismatch", 400);
    }
  }

  // ✅ ถ้าส่ง treatment_id มา ตรวจว่ามีจริง
  if ($treatment_id !== null) {
    $st = $dbh->prepare("SELECT 1 FROM treatments WHERE treatment_id=?");
    $st->execute([$treatment_id]);
    if (!$st->fetchColumn()) {
      json_err("VALIDATION_ERROR", "treatment_not_found", 400);
    }
  }

  $sql = "INSERT INTO care_reminders
          (user_id, tree_id, diagnosis_history_id, treatment_id, reminder_date, is_done, note)
          VALUES (?,?,?,?,?,?,?)";
  $st = $dbh->prepare($sql);
  $st->execute([
    $currentUserId,
    $tree_id,
    $diagnosis_history_id,
    $treatment_id,
    $reminder_date,
    $is_done,
    $note
  ]);

  $reminder_id = (int)$dbh->lastInsertId();

  $st2 = $dbh->prepare("SELECT * FROM care_reminders WHERE reminder_id=?");
  $st2->execute([$reminder_id]);
  json_ok($st2->fetch(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
