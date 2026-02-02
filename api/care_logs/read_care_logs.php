<?php
// api/care_logs/read_care_logs.php (patched)
require_once __DIR__ . '/../db.php';

// Auth (รองรับทั้งแบบ return array และแบบ global $AUTH_USER_*)
$authFile = __DIR__ . '/../auth/require_auth.php';
$authUser = null;
if (file_exists($authFile)) {
  $tmp = require $authFile;
  if (is_array($tmp)) $authUser = $tmp;
}
if (function_exists('require_auth')) { require_auth(); }

$currentUserId = 0;
$currentUserRole = 'user';
if (isset($AUTH_USER_ID)) $currentUserId = (int)$AUTH_USER_ID;
if (isset($AUTH_USER_ROLE)) $currentUserRole = (string)$AUTH_USER_ROLE;
if (is_array($authUser) && !empty($authUser['id'])) {
  $currentUserId = (int)$authUser['id'];
  if (!empty($authUser['role'])) $currentUserRole = (string)$authUser['role'];
}
if ($currentUserId <= 0 && isset($_SESSION['user_id'])) {
  $currentUserId = (int)$_SESSION['user_id'];
}
if ($currentUserId <= 0) json_err('UNAUTHORIZED', 'unauthorized', 401);
$isAdmin = in_array($currentUserRole, ['admin', 'super_admin'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
}

$normalize_care_type_db = function ($careType) {
  $careType = strtolower(trim((string)$careType));
  if ($careType === 'spray') $careType = 'pesticide';
  return $careType;
};

$care_type_for_client = function ($careTypeDb) {
  if ($careTypeDb === 'pesticide') return 'spray';
  return $careTypeDb;
};

try {
  $where = [];
  $params = [];

  if (!$isAdmin) {
    $where[] = 'user_id = ?';
    $params[] = $currentUserId;
  } elseif (isset($_GET['user_id']) && ctype_digit((string)$_GET['user_id'])) {
    $where[] = 'user_id = ?';
    $params[] = (int)$_GET['user_id'];
  }

  if (isset($_GET['tree_id']) && is_numeric($_GET['tree_id'])) {
    $where[] = 'tree_id = ?';
    $params[] = (int)$_GET['tree_id'];
  }

  if (isset($_GET['care_type']) && $_GET['care_type'] !== '') {
    $where[] = 'care_type = ?';
    $params[] = $normalize_care_type_db($_GET['care_type']);
  }

  if (!empty($_GET['from_date'])) {
    $where[] = 'care_date >= ?';
    $params[] = (string)$_GET['from_date'];
  }

  if (!empty($_GET['to_date'])) {
    $where[] = 'care_date <= ?';
    $params[] = (string)$_GET['to_date'];
  }

  if (isset($_GET['is_reminder']) && ($_GET['is_reminder'] === '0' || $_GET['is_reminder'] === '1')) {
    $where[] = 'is_reminder = ?';
    $params[] = (int)$_GET['is_reminder'];
  }

  if (isset($_GET['is_done']) && ($_GET['is_done'] === '0' || $_GET['is_done'] === '1')) {
    $where[] = 'is_done = ?';
    $params[] = (int)$_GET['is_done'];
  }

  $sql = 'SELECT * FROM care_logs';
  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY care_date DESC, log_id DESC';

  $stmt = $dbh->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $rawFlag = isset($_GET['raw']) && $_GET['raw'] === '1';
  if (!$rawFlag) {
    foreach ($rows as &$r) {
      if (isset($r['care_type'])) $r['care_type'] = $care_type_for_client($r['care_type']);
    }
    unset($r);
  }

  json_ok($rows);

} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
