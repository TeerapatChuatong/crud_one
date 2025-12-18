<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
  json_err("METHOD_NOT_ALLOWED", "patch_only", 405);
}

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$question_id = $body['question_id'] ?? null;
if (!$question_id || !ctype_digit((string)$question_id)) {
  json_err("VALIDATION_ERROR", "invalid_question_id", 400);
}

$disease_id = $body['disease_id'] ?? null;
if ($disease_id === null || $disease_id === '' || !ctype_digit((string)$disease_id)) {
  json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
}

$fields = [];
$params = [];

if (array_key_exists('question_text', $body)) {
  $qt = trim((string)$body['question_text']);
  if ($qt === '') json_err("VALIDATION_ERROR", "question_text_required", 400);
  $fields[] = "question_text=?";
  $params[] = $qt;
}

if (array_key_exists('question_type', $body)) {
  $type = trim((string)$body['question_type']);
  $allowedTypes = ['yes_no', 'numeric', 'multi'];
  if (!in_array($type, $allowedTypes, true)) json_err("VALIDATION_ERROR", "invalid_question_type", 400);
  $fields[] = "question_type=?";
  $params[] = $type;
}

if (array_key_exists('sort_order', $body)) {
  $fields[] = "sort_order=?";
  $params[] = (int)$body['sort_order'];
}

try {
  $dbh->beginTransaction();

  // ต้องมี question จริง
  $check = $dbh->prepare("SELECT question_id FROM questions WHERE question_id=? LIMIT 1");
  $check->execute([(int)$question_id]);
  if (!$check->fetch()) {
    $dbh->rollBack();
    json_err("NOT_FOUND", "question_not_found", 404);
  }

  // update question (ถ้ามี field)
  if ($fields) {
    $params[] = (int)$question_id;
    $sql = "UPDATE questions SET ".implode(", ", $fields)." WHERE question_id=?";
    $st = $dbh->prepare($sql);
    $st->execute($params);
  }

  // ✅ set pivot ให้เหลือโรคเดียวตามที่เลือก
  $del = $dbh->prepare("DELETE FROM disease_questions WHERE question_id=?");
  $del->execute([(int)$question_id]);

  $pivotSort = array_key_exists('sort_order', $body) ? (int)$body['sort_order'] : 0;

  $ins = $dbh->prepare("
    INSERT INTO disease_questions (disease_id, question_id, sort_order)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
  ");
  $ins->execute([(int)$disease_id, (int)$question_id, $pivotSort]);

  $dbh->commit();

  // ส่งข้อมูลล่าสุด
  $st2 = $dbh->prepare("
    SELECT q.*,
      (SELECT GROUP_CONCAT(dq.disease_id ORDER BY dq.disease_id SEPARATOR ',')
       FROM disease_questions dq WHERE dq.question_id=q.question_id) AS disease_ids
    FROM questions q
    WHERE q.question_id=?
    LIMIT 1
  ");
  $st2->execute([(int)$question_id]);
  json_ok($st2->fetch(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
  if ($dbh->inTransaction()) $dbh->rollBack();
  json_err("DB_ERROR", "db_error", 500);
}
