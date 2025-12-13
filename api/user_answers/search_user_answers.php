<?php
// crud/api/user_answers/search_user_answers.php
require_once __DIR__ . '/../db.php';
require_admin();

$q = trim($_GET['q'] ?? '');
if ($q === '') {
  json_err("VALIDATION_ERROR", "ต้องส่งพารามิเตอร์ q");
}

$like = '%' . $q . '%';

$sql = "
  SELECT
    ua.user_answer_id,
    ua.user_id,
    u.username,
    u.email,
    ua.disease_question_id,
    dq.disease_id,
    d.disease_en,
    d.disease_th,
    q.question_id,
    q.question_text,
    ua.choice_id,
    c.choice_label,
    ua.risk_score,
    ua.total_score,
    ua.answered_at
  FROM user_answers ua
  JOIN `user` u              ON ua.user_id = u.id
  JOIN disease_questions dq  ON ua.disease_question_id = dq.id
  JOIN diseases d            ON dq.disease_id = d.disease_id
  JOIN questions q           ON dq.question_id = q.question_id
  JOIN choices c             ON ua.choice_id = c.choice_id
  WHERE
    u.username     LIKE :kw
    OR u.email     LIKE :kw
    OR d.disease_en LIKE :kw
    OR d.disease_th LIKE :kw
    OR q.question_text LIKE :kw
    OR c.choice_label  LIKE :kw
  ORDER BY ua.answered_at DESC, ua.user_answer_id DESC
  LIMIT 100
";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':kw' => $like]);
  $rows = $stmt->fetchAll();

  json_ok(['items' => $rows, 'q' => $q]);
} catch (Throwable $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
