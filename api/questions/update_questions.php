<?php
require_once __DIR__ . '/../db.php';

// รองรับ PATCH/POST (บาง client ส่ง POST)
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['PATCH', 'POST'], true)) {
  json_err('METHOD_NOT_ALLOWED', 'patch_only', 405);
}

function has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
  );
  $st->execute([$table, $col]);
  return ((int)$st->fetchColumn()) > 0;
}

try {
  require_admin();

  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    json_err('BAD_JSON', 'invalid_json', 400);
  }

  $question_id = isset($data['question_id']) ? (int)$data['question_id'] : 0;
  $disease_id = isset($data['disease_id']) ? (int)$data['disease_id'] : 0;

  if ($question_id <= 0) json_err('VALIDATION_ERROR', 'question_id_required', 400);
  if ($disease_id <= 0) json_err('VALIDATION_ERROR', 'disease_id_required', 400);

  $has_is_active = has_column($pdo, 'questions', 'is_active');

  // --- build update fields dynamically ---
  $set = [];
  $params = [':question_id' => $question_id];

  if (array_key_exists('question_text', $data)) {
    $text = trim((string)$data['question_text']);
    if ($text === '') json_err('VALIDATION_ERROR', 'question_text_required', 400);
    $set[] = "question_text = :question_text";
    $params[':question_text'] = $text;
  }
  if (array_key_exists('question_type', $data)) {
    $type = trim((string)$data['question_type']);
    if ($type === '') json_err('VALIDATION_ERROR', 'question_type_required', 400);
    $set[] = "question_type = :question_type";
    $params[':question_type'] = $type;
  }
  if (array_key_exists('max_score', $data)) {
    $ms = (int)$data['max_score'];
    if ($ms <= 0) json_err('VALIDATION_ERROR', 'max_score_required', 400);
    $set[] = "max_score = :max_score";
    $params[':max_score'] = $ms;
  }

  // sort_order (ทั้งใน questions และ pivot)
  $pivotSort = null;
  if (array_key_exists('sort_order', $data) || array_key_exists('order_no', $data)) {
    $pivotSort = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : (int)$data['order_no'];
    if ($pivotSort < 0) $pivotSort = 0;
    $set[] = "sort_order = :sort_order";
    $params[':sort_order'] = $pivotSort;
  }

  if ($has_is_active && array_key_exists('is_active', $data)) {
    $ia = (int)$data['is_active'];
    $ia = ($ia === 1) ? 1 : 0;
    $set[] = "is_active = :is_active";
    $params[':is_active'] = $ia;
  }

  $pdo->beginTransaction();

  // ensure exists
  $chk = $pdo->prepare("SELECT question_id FROM questions WHERE question_id = :id");
  $chk->execute([':id' => $question_id]);
  if (!$chk->fetchColumn()) {
    $pdo->rollBack();
    json_err('NOT_FOUND', 'question_not_found', 404);
  }

  if (count($set) > 0) {
    $sql = "UPDATE questions SET " . implode(', ', $set) . " WHERE question_id = :question_id";
    $upd = $pdo->prepare($sql);
    $upd->execute($params);
  }

  // update pivot disease_questions (ย้ายโรค / เปลี่ยนลำดับ)
  $pdo->prepare("DELETE FROM disease_questions WHERE question_id = :qid")
      ->execute([':qid' => $question_id]);

  $ins = $pdo->prepare(
    "INSERT INTO disease_questions (disease_id, question_id, sort_order) VALUES (:disease_id, :question_id, :sort_order)"
  );
  $ins->execute([
    ':disease_id' => $disease_id,
    ':question_id' => $question_id,
    ':sort_order' => $pivotSort ?? 0,
  ]);

  $pdo->commit();

  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_err('DB_ERROR', $e->getMessage(), 500);
}
