<?php
// CRUD/api/care_reminders/create_care_reminders.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

$authFile = __DIR__ . '/../auth/require_auth.php';
if (file_exists($authFile)) require_once $authFile;
if (function_exists('require_auth')) { require_auth(); }
if (function_exists('require_login')) { require_login(); }

// fallback (กันกรณีโปรเจกต์ไม่มีฟังก์ชันเหล่านี้)
if (!function_exists('json_ok')) {
  function json_ok($data = [], $message = 'ok') {
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('json_err')) {
  function json_err($code = 'ERROR', $message = 'error', $http = 400, $extra = []) {
    http_response_code($http);
    echo json_encode(['success' => false, 'code' => $code, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];
if (empty($input) && !empty($_POST)) $input = $_POST;

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$is_admin = function_exists('is_admin') ? is_admin() : false;

$user_id = $currentUserId;
if ($is_admin && isset($input['user_id'])) {
  $user_id = (int)$input['user_id'];
}
if ($user_id <= 0) json_err('UNAUTHORIZED', 'login_required', 401);

$tree_id = isset($input['tree_id']) ? (int)$input['tree_id'] : 0;
if ($tree_id <= 0) json_err('VALIDATION_ERROR', 'tree_id_required', 400);

$diagnosis_history_id = isset($input['diagnosis_history_id']) ? (int)$input['diagnosis_history_id'] : null;
$treatment_id         = isset($input['treatment_id']) ? (int)$input['treatment_id'] : null;
$episode_id           = isset($input['episode_id']) ? (int)$input['episode_id'] : null;

$reminder_type = isset($input['reminder_type']) ? trim((string)$input['reminder_type']) : 'spray';
if ($reminder_type === '') $reminder_type = 'spray';

// ✅ normalize
$reminder_type = strtolower($reminder_type);

$moa_group_id   = array_key_exists('moa_group_id', $input) ? ($input['moa_group_id'] === null ? null : (int)$input['moa_group_id']) : null;
$chemical_id    = array_key_exists('chemical_id', $input) ? ($input['chemical_id'] === null ? null : (int)$input['chemical_id']) : null;
$spray_round_no = array_key_exists('spray_round_no', $input) ? ($input['spray_round_no'] === null ? null : (int)$input['spray_round_no']) : null;

$reminder_date = isset($input['reminder_date']) ? trim((string)$input['reminder_date']) : '';
$dt = DateTime::createFromFormat('Y-m-d', $reminder_date);
if (!$dt || $dt->format('Y-m-d') !== $reminder_date) {
  json_err('VALIDATION_ERROR', 'invalid_reminder_date', 400);
}

$is_done = isset($input['is_done']) ? (int)$input['is_done'] : 0;
if ($is_done !== 0 && $is_done !== 1) $is_done = 0;

$note = isset($input['note']) ? trim((string)$input['note']) : null;
if ($note === '') $note = null;

try {
  if (!isset($dbh)) throw new Exception('DB connection not found');

  // ✅ tree ต้องเป็นของ user (ยกเว้นแอดมิน)
  $st = $dbh->prepare("SELECT tree_id, user_id FROM orange_trees WHERE tree_id=? LIMIT 1");
  $st->execute([$tree_id]);
  $treeRow = $st->fetch(PDO::FETCH_ASSOC);
  if (!$treeRow) json_err('NOT_FOUND', 'tree_not_found', 404);
  if (!$is_admin && (int)$treeRow['user_id'] !== $user_id) {
    json_err('FORBIDDEN', 'tree_not_owned', 403);
  }

  // ✅ diagnosis_history (ถ้ามี)
  if ($diagnosis_history_id !== null && $diagnosis_history_id > 0) {
    $st = $dbh->prepare("SELECT diagnosis_history_id, user_id FROM diagnosis_history WHERE diagnosis_history_id=? LIMIT 1");
    $st->execute([$diagnosis_history_id]);
    $dh = $st->fetch(PDO::FETCH_ASSOC);
    if (!$dh) json_err('NOT_FOUND', 'diagnosis_history_not_found', 404);
    if (!$is_admin && (int)$dh['user_id'] !== $user_id) json_err('FORBIDDEN', 'diagnosis_history_not_owned', 403);
  } else {
    $diagnosis_history_id = null;
  }

  // ✅ treatment (ถ้ามี)
  if ($treatment_id !== null && $treatment_id > 0) {
    $st = $dbh->prepare("SELECT treatment_id FROM treatments WHERE treatment_id=? LIMIT 1");
    $st->execute([$treatment_id]);
    if (!$st->fetchColumn()) json_err('NOT_FOUND', 'treatment_not_found', 404);
  } else {
    $treatment_id = null;
  }

  // ✅ episode (ถ้ามี)
  if ($episode_id !== null && $episode_id > 0) {
    $st = $dbh->prepare("SELECT episode_id, user_id FROM treatment_episodes WHERE episode_id=? LIMIT 1");
    $st->execute([$episode_id]);
    $ep = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ep) json_err('NOT_FOUND', 'episode_not_found', 404);
    if (!$is_admin && (int)$ep['user_id'] !== $user_id) json_err('FORBIDDEN', 'episode_not_owned', 403);
  } else {
    $episode_id = null;
  }

  // ✅ moa_group (ถ้ามี)
  if ($moa_group_id !== null && $moa_group_id > 0) {
    $st = $dbh->prepare("SELECT moa_group_id FROM moa_groups WHERE moa_group_id=? LIMIT 1");
    $st->execute([$moa_group_id]);
    if (!$st->fetchColumn()) json_err('NOT_FOUND', 'moa_group_not_found', 404);
  } else {
    $moa_group_id = null;
  }

  // ✅ chemical (ถ้ามี)
  if ($chemical_id !== null && $chemical_id > 0) {
    $st = $dbh->prepare("SELECT chemical_id FROM chemicals WHERE chemical_id=? LIMIT 1");
    $st->execute([$chemical_id]);
    if (!$st->fetchColumn()) json_err('NOT_FOUND', 'chemical_not_found', 404);
  } else {
    $chemical_id = null;
  }

  // ✅ สำคัญ: กันสถานะการรักษาปนกันระหว่าง “โรคเก่า/โรคใหม่” ในต้นเดียวกัน
  // ถ้า Flutter/Client ไม่ได้ส่ง diagnosis_history_id มา (เป็น NULL)
  // ให้ผูก reminder เข้ากับ diagnosis_history ล่าสุดของต้นนั้นอัตโนมัติ
  // เพื่อไม่ให้การสร้าง reminder ของโรคใหม่ ทำให้โรคเดิมที่ “ทำเสร็จแล้ว” กลับเป็น “ยังไม่เสร็จ”
  if ($diagnosis_history_id === null && ($reminder_type === 'spray' || $treatment_id !== null || $episode_id !== null)) {
    $st = $dbh->prepare(
      "SELECT diagnosis_history_id\n" .
      "FROM diagnosis_history\n" .
      "WHERE user_id=? AND tree_id=?\n" .
      "ORDER BY diagnosed_at DESC, diagnosis_history_id DESC\n" .
      "LIMIT 1"
    );
    $st->execute([$user_id, $tree_id]);
    $latest = $st->fetchColumn();
    if ($latest !== false && $latest !== null && ctype_digit((string)$latest)) {
      $diagnosis_history_id = (int)$latest;
    }
  }

  $sql = "
    INSERT INTO care_reminders
      (user_id, tree_id, diagnosis_history_id, treatment_id,
       reminder_date, is_done, note,
       episode_id, reminder_type, moa_group_id, chemical_id, spray_round_no,
       created_at)
    VALUES
      (:user_id, :tree_id, :diagnosis_history_id, :treatment_id,
       :reminder_date, :is_done, :note,
       :episode_id, :reminder_type, :moa_group_id, :chemical_id, :spray_round_no,
       NOW())
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([
    ':user_id' => $user_id,
    ':tree_id' => $tree_id,
    ':diagnosis_history_id' => $diagnosis_history_id,
    ':treatment_id' => $treatment_id,
    ':reminder_date' => $reminder_date,
    ':is_done' => $is_done,
    ':note' => $note,
    ':episode_id' => $episode_id,
    ':reminder_type' => $reminder_type,
    ':moa_group_id' => $moa_group_id,
    ':chemical_id' => $chemical_id,
    ':spray_round_no' => $spray_round_no,
  ]);

  $newId = (int)$dbh->lastInsertId();
  $st = $dbh->prepare("
    SELECT cr.*,
           c.trade_name AS chemical_name,
           mg.moa_code AS moa_group_code,
           mg.moa_system AS moa_system
    FROM care_reminders cr
    LEFT JOIN chemicals c ON c.chemical_id = cr.chemical_id
    LEFT JOIN moa_groups mg ON mg.moa_group_id = cr.moa_group_id
    WHERE cr.reminder_id=?
    LIMIT 1
  ");
  $st->execute([$newId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  json_ok($row, 'created');

} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500, ['error' => $e->getMessage()]);
}
