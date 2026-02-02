<?php
// CRUD/api/user_orchard_answers/update_user_orchard_answers.php
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("Method not allowed", 405);
}

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$question_id   = isset($input['question_id']) ? (int)$input['question_id'] : 0;
$choice_id     = array_key_exists('choice_id', $input) ? ($input['choice_id'] === null ? null : (int)$input['choice_id']) : null;
$answer_text   = array_key_exists('answer_text', $input) ? trim((string)$input['answer_text']) : null;
$numeric_value = array_key_exists('numeric_value', $input) ? ($input['numeric_value'] === null ? null : (float)$input['numeric_value']) : null;

if ($question_id <= 0) {
  json_err("question_id is required", 400);
}

$user_id = 0;
if (isset($auth_user_id) && (int)$auth_user_id > 0) $user_id = (int)$auth_user_id;
if (isset($GLOBALS['auth_user_id']) && (int)$GLOBALS['auth_user_id'] > 0) $user_id = (int)$GLOBALS['auth_user_id'];
if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) $user_id = (int)$_SESSION['user_id'];
if ($user_id <= 0) json_err("Unauthorized", 401);

try {
  if (!isset($pdo)) json_err("DB connection not found", 500);

  $sql = "
    UPDATE user_orchard_answers
    SET
      choice_id = :choice_id,
      answer_text = :answer_text,
      numeric_value = :numeric_value,
      updated_at = NOW()
    WHERE user_id = :user_id AND question_id = :question_id
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':choice_id' => $choice_id,
    ':answer_text' => ($answer_text === '' ? null : $answer_text),
    ':numeric_value' => $numeric_value,
    ':user_id' => $user_id,
    ':question_id' => $question_id,
  ]);

  json_ok([
    'user_id' => $user_id,
    'question_id' => $question_id,
    'choice_id' => $choice_id,
    'answer_text' => ($answer_text === '' ? null : $answer_text),
    'numeric_value' => $numeric_value,
  ], "updated");
} catch (Throwable $e) {
  json_err("DB error", 500, ['error' => $e->getMessage()]);
}
