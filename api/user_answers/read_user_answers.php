<?php
// crud/api/user_answers/read_user_answers.php
require_once __DIR__ . '/../db.php';
require_admin();

$user_id             = $_GET['user_id']             ?? null;
$disease_question_id = $_GET['disease_question_id'] ?? null;

$sql = "
  SELECT
    ua.user_answer_id,
    ua.user_id,
    u.username,
    ua.disease_question_id,
    dq.disease_id,
    q.question_id,
    q.question_text,
    ua.choice_id,
    c.choice_label,
    ua.risk_score,
    ua.total_score,
    ua.answered_at
  FROM user_answers ua
  JOIN `user` u           ON ua.user_id = u.id
  JOIN disease_questions dq ON ua.disease_question_id = dq.id
  JOIN questions q        ON dq.question_id = q.question_id
  JOIN choices c          ON ua.choice_id = c.choice_id
";

$conds  = [];
$params = [];

if (!empty($user_id)) {
  $conds[] = "ua.user_id = :user_id";
  $params[':user_id'] = $user_id;
}
if (!empty($disease_question_id)) {
  $conds[] = "ua.disease_question_id = :dqid";
  $params[':dqid'] = $disease_question_id;
}

if ($conds) {
  $sql .= " WHERE " . implode(" AND ", $conds);
}

$sql .= " ORDER BY ua.answered_at DESC, ua.user_answer_id DESC";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  json_ok(['items' => $rows]);
} catch (Throwable $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
