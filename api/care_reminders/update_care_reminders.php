<?php
// api/care_reminders/update_care_reminders.php
require_once __DIR__ . '/../db.php';

$authFile = __DIR__ . '/../auth/require_auth.php';
if (file_exists($authFile)) require_once $authFile;
if (function_exists('require_auth')) { require_auth(); }
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "patch_or_post_only", 405);
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$is_admin = function_exists('is_admin') ? is_admin() : false;

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) json_err("INVALID_JSON", "invalid_json", 400);

$reminder_id = $body['reminder_id'] ?? null;
if ($reminder_id === null || !ctype_digit((string)$reminder_id)) {
  json_err("VALIDATION_ERROR", "reminder_id_required", 400);
}
$reminder_id = (int)$reminder_id;

$is_done = $body['is_done'] ?? null;          // optional
$reminder_date = $body['reminder_date'] ?? null; // optional
$note = $body['note'] ?? null;                // optional

try {
  $st = $dbh->prepare("SELECT * FROM care_reminders WHERE reminder_id=?");
  $st->execute([$reminder_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_err("NOT_FOUND","not_found",404);

  if (!$is_admin && (int)$row['user_id'] !== $currentUserId) {
    json_err("FORBIDDEN","cannot_edit_other_user",403);
  }

  $sets = [];
  $params = [];

  if ($is_done !== null) {
    $v = ($is_done == 1 || $is_done === true || $is_done === "1") ? 1 : 0;
    $sets[] = "is_done=?";
    $params[] = $v;
  }

  if ($reminder_date !== null) {
    $reminder_date = trim((string)$reminder_date);
    $dt = DateTime::createFromFormat('Y-m-d', $reminder_date);
    if (!$dt || $dt->format('Y-m-d') !== $reminder_date) {
      json_err("VALIDATION_ERROR", "invalid_reminder_date_format_use_Y-m-d", 400);
    }
    $sets[] = "reminder_date=?";
    $params[] = $reminder_date;
  }

  if ($note !== null) {
    $note = is_string($note) ? trim($note) : $note;
    $note = ($note === '' ? null : $note);
    $sets[] = "note=?";
    $params[] = $note;
  }

  if (!$sets) {
    json_err("VALIDATION_ERROR","no_fields_to_update",400);
  }

  $params[] = $reminder_id;

  $sql = "UPDATE care_reminders SET " . implode(", ", $sets) . " WHERE reminder_id=?";
  $st2 = $dbh->prepare($sql);
  $st2->execute($params);

  $st3 = $dbh->prepare("SELECT * FROM care_reminders WHERE reminder_id=?");
  $st3->execute([$reminder_id]);
  json_ok($st3->fetch(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
