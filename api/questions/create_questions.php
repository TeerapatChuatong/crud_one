<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
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

  $disease_id = isset($data['disease_id']) ? (int)$data['disease_id'] : 0;
  $question_text = trim((string)($data['question_text'] ?? ''));
  $question_type = trim((string)($data['question_type'] ?? ''));

  $max_score = isset($data['max_score']) ? (int)$data['max_score'] : 0;
  $sort_order = isset($data['sort_order']) ? (int)$data['sort_order'] : (int)($data['order_no'] ?? 0);
  $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;

  if ($disease_id <= 0) json_err('VALIDATION_ERROR', 'disease_id_required', 400);
  if ($question_text === '') json_err('VALIDATION_ERROR', 'question_text_required', 400);
  if ($question_type === '') json_err('VALIDATION_ERROR', 'question_type_required', 400);
  if ($max_score <= 0) json_err('VALIDATION_ERROR', 'max_score_required', 400);

  $has_is_active = has_column($pdo, 'questions', 'is_active');

  $pdo->beginTransaction();

  // --- insert questions ---
  $cols = ['question_text', 'question_type', 'max_score', 'sort_order'];
  $vals = [':question_text', ':question_type', ':max_score', ':sort_order'];
  $params = [
    ':question_text' => $question_text,
    ':question_type' => $question_type,
    ':max_score' => $max_score,
    ':sort_order' => $sort_order,
  ];

  if ($has_is_active) {
    $cols[] = 'is_active';
    $vals[] = ':is_active';
    $params[':is_active'] = $is_active;
  }

  $sql = "INSERT INTO questions (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $question_id = (int)$pdo->lastInsertId();

  // --- pivot disease_questions ---
  $stmt2 = $pdo->prepare("INSERT INTO disease_questions (disease_id, question_id, sort_order) VALUES (:disease_id, :question_id, :sort_order)");
  $stmt2->execute([
    ':disease_id' => $disease_id,
    ':question_id' => $question_id,
    ':sort_order' => $sort_order,
  ]);

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'question_id' => $question_id,
    'disease_id' => $disease_id,
    'max_score' => $max_score,
    'sort_order' => $sort_order,
    'is_active' => $has_is_active ? $is_active : 1,
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_err('DB_ERROR', $e->getMessage(), 500);
}
