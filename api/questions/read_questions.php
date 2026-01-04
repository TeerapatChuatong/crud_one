<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php'; // ✅ ให้แอปอ่านได้

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
  $has_is_active = has_column($pdo, 'questions', 'is_active');
  $isActiveSelect = $has_is_active ? 'q.is_active' : '1 AS is_active';

  $stmt = $pdo->prepare(
    "SELECT
      q.question_id,
      q.question_text,
      q.question_type,
      q.max_score,
      q.sort_order,
      {$isActiveSelect}
    FROM questions q
    ORDER BY q.sort_order ASC, q.question_id ASC"
  );
  $stmt->execute();
  json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
