<?php
// api/care_reminders/update_care_reminders.php (patched)
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

// ----------------------------
// Helpers
// ----------------------------
$int_or_null = function ($v, $field) {
  if ($v === null || $v === '') return null;
  if (!ctype_digit((string)$v)) json_err("VALIDATION_ERROR", "invalid_{$field}", 400);
  return (int)$v;
};

$enum_or_null = function ($v, $field, array $allowed) {
  if ($v === null || $v === '') return null;
  $v = strtoupper(trim((string)$v));
  if (!in_array($v, $allowed, true)) json_err("VALIDATION_ERROR", "invalid_{$field}", 400);
  return $v;
};

$str_or_null = function ($v, $field, $maxLen) {
  if ($v === null) return null;
  $v = trim((string)$v);
  if ($v === '') return null;
  if (mb_strlen($v, 'UTF-8') > $maxLen) json_err("VALIDATION_ERROR", "{$field}_too_long", 400);
  return $v;
};

$reminder_id = $body['reminder_id'] ?? null;
if ($reminder_id === null || !ctype_digit((string)$reminder_id)) {
  json_err("VALIDATION_ERROR", "reminder_id_required", 400);
}
$reminder_id = (int)$reminder_id;

// optional fields
$is_done = $body['is_done'] ?? null;
$reminder_date = $body['reminder_date'] ?? null;
$note = $body['note'] ?? null;
$treatment_id = array_key_exists('treatment_id', $body) ? $body['treatment_id'] : null;
$diagnosis_history_id = array_key_exists('diagnosis_history_id', $body) ? $body['diagnosis_history_id'] : null;
$chemical_id = array_key_exists('chemical_id', $body) ? $body['chemical_id'] : null;
$moa_system = array_key_exists('moa_system', $body) ? $body['moa_system'] : null;
$moa_group_code = array_key_exists('moa_group_code', $body) ? $body['moa_group_code'] : null;
$moa_member_no = array_key_exists('moa_member_no', $body) ? $body['moa_member_no'] : null;

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

  // is_done
  if ($is_done !== null) {
    $v = ($is_done == 1 || $is_done === true || $is_done === "1") ? 1 : 0;
    $sets[] = "is_done=?";
    $params[] = $v;
  }

  // reminder_date
  if ($reminder_date !== null) {
    $reminder_date = trim((string)$reminder_date);
    $dt = DateTime::createFromFormat('Y-m-d', $reminder_date);
    if (!$dt || $dt->format('Y-m-d') !== $reminder_date) {
      json_err("VALIDATION_ERROR", "invalid_reminder_date_format_use_Y-m-d", 400);
    }
    $sets[] = "reminder_date=?";
    $params[] = $reminder_date;
  }

  // note
  if ($note !== null) {
    $note = is_string($note) ? trim($note) : $note;
    $note = ($note === '' ? null : $note);
    $sets[] = "note=?";
    $params[] = $note;
  }

  // treatment_id
  if (array_key_exists('treatment_id', $body)) {
    $treatment_id = $int_or_null($treatment_id, 'treatment_id');
    if ($treatment_id !== null) {
      $st = $dbh->prepare("SELECT 1 FROM treatments WHERE treatment_id=?");
      $st->execute([$treatment_id]);
      if (!$st->fetchColumn()) json_err("VALIDATION_ERROR", "treatment_not_found", 400);
    }
    $sets[] = "treatment_id=?";
    $params[] = $treatment_id;
  }

  // diagnosis_history_id (must match reminder's tree_id)
  if (array_key_exists('diagnosis_history_id', $body)) {
    $diagnosis_history_id = $int_or_null($diagnosis_history_id, 'diagnosis_history_id');
    if ($diagnosis_history_id !== null) {
      $sql = "SELECT user_id, tree_id FROM diagnosis_history WHERE diagnosis_history_id=?";
      $st = $dbh->prepare($sql);
      $st->execute([$diagnosis_history_id]);
      $h = $st->fetch(PDO::FETCH_ASSOC);
      if (!$h) json_err("VALIDATION_ERROR", "diagnosis_history_not_found", 400);

      if (!$is_admin && (int)$h['user_id'] !== $currentUserId) {
        json_err("FORBIDDEN", "history_not_belong_to_user", 403);
      }
      if ((int)$h['tree_id'] !== (int)$row['tree_id']) {
        json_err("VALIDATION_ERROR", "history_tree_id_mismatch", 400);
      }
    }
    $sets[] = "diagnosis_history_id=?";
    $params[] = $diagnosis_history_id;
  }

  // chemical + MOA snapshot
  $moa_system = $enum_or_null($moa_system, 'moa_system', ['FRAC','IRAC']);
  $moa_group_code = $str_or_null($moa_group_code, 'moa_group_code', 10);
  $moa_member_no = $int_or_null($moa_member_no, 'moa_member_no');

  if (array_key_exists('chemical_id', $body)) {
    $chemical_id = $int_or_null($chemical_id, 'chemical_id');

    if ($chemical_id !== null) {
      $st = $dbh->prepare("SELECT user_id, moa_system, moa_group_code, moa_member_no FROM user_chemicals WHERE chemical_id=?");
      $st->execute([$chemical_id]);
      $chem = $st->fetch(PDO::FETCH_ASSOC);
      if (!$chem) json_err("VALIDATION_ERROR", "chemical_not_found", 400);

      if (!$is_admin && (int)$chem['user_id'] !== $currentUserId) {
        json_err("FORBIDDEN", "chemical_not_belong_to_user", 403);
      }

      // snapshot MOA from chemical if present; otherwise keep provided values (or null)
      $moa_system = !empty($chem['moa_system']) ? $chem['moa_system'] : $moa_system;
      $moa_group_code = !empty($chem['moa_group_code']) ? $chem['moa_group_code'] : $moa_group_code;
      $moa_member_no = ($chem['moa_member_no'] !== null) ? (int)$chem['moa_member_no'] : $moa_member_no;
    }

    $sets[] = "chemical_id=?";
    $params[] = $chemical_id;

    // when chemical_id is updated, also update moa snapshot fields
    $sets[] = "moa_system=?";
    $params[] = $moa_system;
    $sets[] = "moa_group_code=?";
    $params[] = $moa_group_code;
    $sets[] = "moa_member_no=?";
    $params[] = $moa_member_no;

  } else {
    // if chemical_id not being updated, allow updating moa fields explicitly
    if (array_key_exists('moa_system', $body)) {
      $sets[] = "moa_system=?";
      $params[] = $moa_system;
    }
    if (array_key_exists('moa_group_code', $body)) {
      $sets[] = "moa_group_code=?";
      $params[] = $moa_group_code;
    }
    if (array_key_exists('moa_member_no', $body)) {
      $sets[] = "moa_member_no=?";
      $params[] = $moa_member_no;
    }
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
