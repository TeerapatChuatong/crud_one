<?php
// CRUD/api/user_orchard_answers/read_user_orchard_answers.php
header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . "/../db.php";
$authPath = __DIR__ . "/../auth/require_auth.php";

if (file_exists($dbPath)) require_once $dbPath;
if (file_exists($authPath)) require_once $authPath;

if (!function_exists('json_ok')) {
  function json_ok($data = [], $message = 'ok') {
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('json_err')) {
  function json_err($message = 'error', $http = 400, $extra = []) {
    http_response_code($http);
    echo json_encode(['success' => false, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("Method not allowed", 405);
}

$user_id = 0;
if (isset($auth_user_id) && (int)$auth_user_id > 0) $user_id = (int)$auth_user_id;
if (isset($GLOBALS['auth_user_id']) && (int)$GLOBALS['auth_user_id'] > 0) $user_id = (int)$GLOBALS['auth_user_id'];
if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) $user_id = (int)$_SESSION['user_id'];
if ($user_id <= 0) json_err("Unauthorized", 401);

try {
  if (!isset($pdo)) json_err("DB connection not found", 500);

  // optional filter: ?question_id=...
  $qid = isset($_GET['question_id']) ? (int)$_GET['question_id'] : 0;

  $sql = "
    SELECT
      uoa.user_id,
      uoa.question_id,
      uoa.choice_id,
      uoa.answer_text,
      uoa.numeric_value,
      uoa.source,
      uoa.created_at,
      uoa.updated_at,
      q.question_text,
      q.question_type,
      q.answer_source,
      c.choice_label,
      c.choice_text,
      c.chemical_id
    FROM user_orchard_answers uoa
    JOIN questions q ON q.question_id = uoa.question_id
    LEFT JOIN choices c ON c.choice_id = uoa.choice_id
    WHERE uoa.user_id = :user_id
  ";

  $params = [':user_id' => $user_id];

  if ($qid > 0) {
    $sql .= " AND uoa.question_id = :qid ";
    $params[':qid'] = $qid;
  }

  $sql .= " ORDER BY uoa.updated_at DESC, uoa.question_id ASC ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  json_ok($rows, "ok");
} catch (Throwable $e) {
  json_err("DB error", 500, ['error' => $e->getMessage()]);
}
