<?php
// รองรับทั้งกรณี db.php อยู่ใน api/ หรืออยู่ที่ root CRUD/
$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

$authPath = __DIR__ . '/../auth/require_auth.php';
if (!file_exists($authPath)) $authPath = __DIR__ . '/../../auth/require_auth.php';
require_once $authPath;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

function has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
  );
  $st->execute([$table, $col]);
  return ((int)$st->fetchColumn()) > 0;
}

try {
  $disease_id = $_GET['disease_id'] ?? null;

  // ✅ รองรับ schema รูป: image_url (ใหม่) + example_image (เก่า)
  $has_image_url = has_column($pdo, 'questions', 'image_url');
  $imageUrlSelect = $has_image_url ? 'qn.image_url' : 'NULL AS image_url';

  $has_example_image = has_column($pdo, 'questions', 'example_image');
  $exampleSelect = $has_example_image ? 'qn.example_image' : 'NULL AS example_image';

  $sql = "
    SELECT
      dq.disease_question_id AS id,
      dq.disease_question_id,
      dq.disease_id,
      dq.question_id,
      dq.sort_order,
      d.disease_th,
      d.disease_en,
      qn.question_text,
      {$imageUrlSelect},
      {$exampleSelect},
      qn.question_type,
      qn.max_score,
      qn.sort_order AS question_sort_order
    FROM disease_questions dq
    LEFT JOIN diseases d ON d.disease_id = dq.disease_id
    INNER JOIN questions qn ON qn.question_id = dq.question_id
  ";

  $where  = [];
  $params = [];

  if ($disease_id !== null && $disease_id !== '' && $disease_id !== 'all') {
    if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
    $where[]  = "dq.disease_id = ?";
    $params[] = (int)$disease_id;
  }

  if ($where) $sql .= " WHERE " . implode(" AND ", $where);

  $sql .= " ORDER BY dq.sort_order ASC, qn.sort_order ASC, dq.disease_question_id ASC";

  $st = $pdo->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
