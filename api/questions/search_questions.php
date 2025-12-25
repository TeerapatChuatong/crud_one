<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
}

function has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
  );
  $st->execute([$table, $col]);
  return ((int)$st->fetchColumn()) > 0;
}

try {
  $q = trim((string)($_GET['q'] ?? ''));
  if ($q === '') {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $has_is_active = has_column($pdo, 'questions', 'is_active');
  $isActiveSelect = $has_is_active ? 'q.is_active' : '1 AS is_active';

  $stmt = $pdo->prepare(
    "SELECT
      q.question_id,
      q.question_text,
      q.question_type,
      q.max_score,
      q.sort_order,
      {$isActiveSelect},
      (SELECT dq.disease_id FROM disease_questions dq WHERE dq.question_id = q.question_id ORDER BY dq.disease_id LIMIT 1) AS disease_id,
      (SELECT d.disease_th FROM diseases d WHERE d.disease_id = (
        SELECT dq2.disease_id FROM disease_questions dq2 WHERE dq2.question_id = q.question_id ORDER BY dq2.disease_id LIMIT 1
      )) AS disease_name
    FROM questions q
    WHERE q.question_text LIKE :q
    ORDER BY q.question_id ASC"
  );
  $stmt->execute([':q' => "%{$q}%"]);
  $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($data, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  json_err('DB_ERROR', $e->getMessage(), 500);
}
