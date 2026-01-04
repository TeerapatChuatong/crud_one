<?php
// api/care_reminders/delete_care_reminders.php
require_once __DIR__ . '/../db.php';

$authFile = __DIR__ . '/../auth/require_auth.php';
if (file_exists($authFile)) require_once $authFile;
if (function_exists('require_auth')) { require_auth(); }
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED","delete_only",405);
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$is_admin = function_exists('is_admin') ? is_admin() : false;

$reminder_id = $_GET['reminder_id'] ?? '';
if ($reminder_id === '' || !ctype_digit((string)$reminder_id)) {
  json_err("VALIDATION_ERROR","invalid_reminder_id",400);
}
$reminder_id = (int)$reminder_id;

try {
  $st = $dbh->prepare("SELECT user_id FROM care_reminders WHERE reminder_id=?");
  $st->execute([$reminder_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_err("NOT_FOUND","not_found",404);

  if (!$is_admin && (int)$row['user_id'] !== $currentUserId) {
    json_err("FORBIDDEN","cannot_delete_other_user",403);
  }

  $st2 = $dbh->prepare("DELETE FROM care_reminders WHERE reminder_id=?");
  $st2->execute([$reminder_id]);

  json_ok(true);
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
