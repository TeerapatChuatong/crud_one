<?php
// CRUD/api/care_reminders/read_care_reminders.php
require_once __DIR__ . '/../db.php';

$authFile = __DIR__ . '/../auth/require_auth.php';
if (file_exists($authFile)) require_once $authFile;
if (function_exists('require_auth')) { require_auth(); }
if (function_exists('require_login')) { require_login(); }

// fallback helpers
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$is_admin = function_exists('is_admin') ? is_admin() : false;

$reminder_id = $_GET['reminder_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$tree_id = $_GET['tree_id'] ?? '';
$is_done = $_GET['is_done'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

try {
  if (!isset($dbh)) throw new Exception('DB connection not found');

  $select = "
    SELECT cr.*,
           c.trade_name AS chemical_name,
           mg.moa_code AS moa_group_code,
           mg.moa_system AS moa_system
    FROM care_reminders cr
    LEFT JOIN chemicals c ON c.chemical_id = cr.chemical_id
    LEFT JOIN moa_groups mg ON mg.moa_group_id = cr.moa_group_id
  ";

  // read one
  if ($reminder_id !== '') {
    if (!ctype_digit((string)$reminder_id)) json_err('VALIDATION_ERROR', 'invalid_reminder_id', 400);
    $reminder_id = (int)$reminder_id;

    $st = $dbh->prepare($select . " WHERE cr.reminder_id=? LIMIT 1");
    $st->execute([$reminder_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err('NOT_FOUND', 'not_found', 404);

    if (!$is_admin && (int)$row['user_id'] !== $currentUserId) {
      json_err('FORBIDDEN', 'cannot_read_other_user', 403);
    }
    json_ok($row);
  }

  $where = [];
  $params = [];

  if ($is_admin && $user_id !== '') {
    if (!ctype_digit((string)$user_id)) json_err('VALIDATION_ERROR', 'invalid_user_id', 400);
    $where[] = "cr.user_id=?";
    $params[] = (int)$user_id;
  } else {
    $where[] = "cr.user_id=?";
    $params[] = $currentUserId;
  }

  if ($tree_id !== '') {
    if (!ctype_digit((string)$tree_id)) json_err('VALIDATION_ERROR', 'invalid_tree_id', 400);
    $where[] = "cr.tree_id=?";
    $params[] = (int)$tree_id;
  }

  if ($is_done !== '' && ($is_done === '0' || $is_done === '1')) {
    $where[] = "cr.is_done=?";
    $params[] = (int)$is_done;
  }

  if ($date_from !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $date_from);
    if (!$dt || $dt->format('Y-m-d') !== $date_from) json_err('VALIDATION_ERROR', 'invalid_date_from', 400);
    $where[] = "cr.reminder_date >= ?";
    $params[] = $date_from;
  }

  if ($date_to !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $date_to);
    if (!$dt || $dt->format('Y-m-d') !== $date_to) json_err('VALIDATION_ERROR', 'invalid_date_to', 400);
    $where[] = "cr.reminder_date <= ?";
    $params[] = $date_to;
  }

  $sql = $select;
  if ($where) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY cr.reminder_date ASC, cr.reminder_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500, ['error' => $e->getMessage()]);
}
