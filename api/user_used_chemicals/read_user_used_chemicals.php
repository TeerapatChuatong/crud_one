<?php
// crud/api/user_used_chemicals/read_user_used_chemicals.php

$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

$authPath = __DIR__ . '/../auth/require_auth.php';
if (!file_exists($authPath)) $authPath = __DIR__ . '/../../auth/require_auth.php';
if (file_exists($authPath)) require_once $authPath;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

function get_dbh(): PDO {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  if (isset($GLOBALS['dbh']) && $GLOBALS['dbh'] instanceof PDO) return $GLOBALS['dbh'];
  json_err("SERVER_ERROR", "db_not_initialized", 500);
}

function current_user_id(): ?int {
  if (isset($GLOBALS['auth_user_id']) && is_numeric($GLOBALS['auth_user_id'])) return (int)$GLOBALS['auth_user_id'];
  if (isset($GLOBALS['user_id']) && is_numeric($GLOBALS['user_id'])) return (int)$GLOBALS['user_id'];
  if (isset($GLOBALS['auth_user']) && is_array($GLOBALS['auth_user']) && isset($GLOBALS['auth_user']['user_id']) && is_numeric($GLOBALS['auth_user']['user_id'])) {
    return (int)$GLOBALS['auth_user']['user_id'];
  }
  if (isset($_SESSION) && isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
  return null;
}

try {
  $dbh = get_dbh();

  $authUid = current_user_id();
  $user_id = $authUid !== null ? $authUid : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);
  if ($user_id <= 0) json_err("UNAUTHORIZED", "missing_user", 401);

  $stmt = $dbh->prepare("
    SELECT
      uuc.*,
      c.chemical_name
    FROM user_used_chemicals uuc
    LEFT JOIN chemicals c ON c.chemical_id = uuc.chemical_id
    WHERE uuc.user_id = :uid
    ORDER BY uuc.used_at DESC, uuc.updated_at DESC, uuc.id DESC
  ");
  $stmt->execute([":uid" => $user_id]);

  json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
