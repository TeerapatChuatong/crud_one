<?php
// crud/api/user_orchard_answers/delete_user_orchard_answers.php

$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

$authPath = __DIR__ . '/../auth/require_auth.php';
if (!file_exists($authPath)) $authPath = __DIR__ . '/../../auth/require_auth.php';
if (file_exists($authPath)) require_once $authPath;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED", "delete_only", 405);
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

$id = $_GET['id'] ?? null;
$question_id = $_GET['question_id'] ?? null;

try {
  $dbh = get_dbh();
  $authUid = current_user_id();

  if ($id !== null && ctype_digit((string)$id)) {
    $sel = $dbh->prepare("SELECT * FROM user_orchard_answers WHERE id = ? LIMIT 1");
    $sel->execute([(int)$id]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err("NOT_FOUND", "not_found", 404);

    if ($authUid !== null && (int)$row['user_id'] !== (int)$authUid) {
      json_err("FORBIDDEN", "forbidden", 403);
    }

    $st = $dbh->prepare("DELETE FROM user_orchard_answers WHERE id = ?");
    $st->execute([(int)$id]);

    json_ok(["id" => (int)$id, "deleted" => true]);
  }

  // delete by (user_id + question_id)
  if ($question_id === null || !ctype_digit((string)$question_id)) {
    json_err("VALIDATION_ERROR", "id_or_question_id_required", 400);
  }

  $user_id = $authUid !== null ? $authUid : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : null);
  if ($user_id === null || $user_id <= 0) {
    json_err("VALIDATION_ERROR", "user_id_required", 400);
  }

  $sel = $dbh->prepare("
    SELECT id FROM user_orchard_answers
    WHERE user_id = ? AND question_id = ?
    LIMIT 1
  ");
  $sel->execute([(int)$user_id, (int)$question_id]);
  $row = $sel->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_err("NOT_FOUND", "not_found", 404);

  $st = $dbh->prepare("DELETE FROM user_orchard_answers WHERE user_id = ? AND question_id = ?");
  $st->execute([(int)$user_id, (int)$question_id]);

  json_ok([
    "user_id" => (int)$user_id,
    "question_id" => (int)$question_id,
    "deleted" => true
  ]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
