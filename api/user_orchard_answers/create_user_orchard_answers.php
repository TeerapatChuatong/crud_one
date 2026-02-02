<?php
// CRUD/api/user_orchard_answers/create_user_orchard_answers.php
header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . "/../db.php";
$authPath = __DIR__ . "/../auth/require_auth.php";

if (file_exists($dbPath)) require_once $dbPath;
if (file_exists($authPath)) require_once $authPath;

// fallback helpers (กันกรณีโปรเจกต์ไม่มีฟังก์ชันเหล่านี้)
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
$source        = array_key_exists('source', $input) ? trim((string)$input['source']) : 'orchard';

if ($question_id <= 0) {
  json_err("question_id is required", 400);
}

// ✅ user_id จาก require_auth.php (ของคุณส่วนใหญ่จะ set ไว้แบบนี้)
$user_id = 0;
if (isset($auth_user_id) && (int)$auth_user_id > 0) $user_id = (int)$auth_user_id;
if (isset($GLOBALS['auth_user_id']) && (int)$GLOBALS['auth_user_id'] > 0) $user_id = (int)$GLOBALS['auth_user_id'];
if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) $user_id = (int)$_SESSION['user_id'];
if ($user_id <= 0) json_err("Unauthorized", 401);

try {
  // $pdo ต้องมาจาก db.php ของคุณ
  if (!isset($pdo)) json_err("DB connection not found", 500);

  // ✅ บังคับ: เก็บเฉพาะ "คำถามจัดการสวนส้ม" (disease_id = 7) เท่านั้น
  // ถ้า question_id ไม่ได้ผูกกับ disease_id=7 ในตาราง disease_questions -> reject
  $chk = $pdo->prepare("SELECT 1 FROM disease_questions WHERE disease_id = 7 AND question_id = :qid LIMIT 1");
  $chk->execute([':qid' => $question_id]);
  if (!$chk->fetchColumn()) {
    json_err("question_not_orchard_management", 400, ['question_id' => $question_id]);
  }


  $sql = "
    INSERT INTO user_orchard_answers
      (user_id, question_id, choice_id, answer_text, numeric_value, source, created_at, updated_at)
    VALUES
      (:user_id, :question_id, :choice_id, :answer_text, :numeric_value, :source, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      choice_id = VALUES(choice_id),
      answer_text = VALUES(answer_text),
      numeric_value = VALUES(numeric_value),
      source = VALUES(source),
      updated_at = NOW()
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':user_id' => $user_id,
    ':question_id' => $question_id,
    ':choice_id' => $choice_id,
    ':answer_text' => ($answer_text === '' ? null : $answer_text),
    ':numeric_value' => $numeric_value,
    ':source' => ($source === '' ? 'orchard' : $source),
  ]);

  json_ok([
    'user_id' => $user_id,
    'question_id' => $question_id,
    'choice_id' => $choice_id,
    'answer_text' => ($answer_text === '' ? null : $answer_text),
    'numeric_value' => $numeric_value,
    'source' => ($source === '' ? 'orchard' : $source),
  ], "saved");
} catch (Throwable $e) {
  json_err("DB error", 500, ['error' => $e->getMessage()]);
}
