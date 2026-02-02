<?php
// รองรับทั้งกรณี db.php อยู่ใน api/ หรืออยู่ที่ root CRUD/
$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

$authPath = __DIR__ . '/../auth/require_auth.php';
if (!file_exists($authPath)) $authPath = __DIR__ . '/../../auth/require_auth.php';
require_once $authPath; // ✅ ให้อ่านได้เมื่อ login แล้ว

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err('METHOD_NOT_ALLOWED', 'get_only', 405);
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
  $has_is_active = has_column($pdo, 'questions', 'is_active');
  $isActiveSelect = $has_is_active ? 'q.is_active' : '1 AS is_active';

  $has_example_image = has_column($pdo, 'questions', 'example_image');
  $exampleSelect = $has_example_image ? 'q.example_image' : 'NULL AS example_image';

  // ✅ ใหม่: image_url
  $has_image_url = has_column($pdo, 'questions', 'image_url');
  $imageUrlSelect = $has_image_url ? 'q.image_url' : 'NULL AS image_url';

  // ✅ ใหม่: answer_source (manual|chemicals)
  $has_answer_source = has_column($pdo, 'questions', 'answer_source');
  $answerSourceSelect = $has_answer_source ? 'q.answer_source AS answer_source' : "'manual' AS answer_source";

  $where = [];
  $params = [];

  // ✅ filter: disease_id
  // disease_id=5 => เฉพาะคำถามที่ผูกโรค 5
  // disease_id=0 => เฉพาะคำถาม “ทั้งสวน” (ไม่ผูกโรค)
  if (isset($_GET['disease_id'])) {
    $did = (int)$_GET['disease_id'];
    if ($did > 0) {
      $where[] = "EXISTS (
        SELECT 1 FROM disease_questions dq
        WHERE dq.question_id = q.question_id AND dq.disease_id = :disease_id
      )";
      $params[':disease_id'] = $did;
    } else {
      $where[] = "NOT EXISTS (
        SELECT 1 FROM disease_questions dq
        WHERE dq.question_id = q.question_id
      )";
    }
  }

  $whereSql = count($where) ? ("WHERE " . implode(" AND ", $where)) : "";

  $stmt = $pdo->prepare(
    "SELECT
      q.question_id,
      q.question_text,
      {$imageUrlSelect},
      {$exampleSelect},
      q.question_type,
      {$answerSourceSelect},
      q.max_score,
      q.sort_order,
      {$isActiveSelect},
      (SELECT dq.disease_id
         FROM disease_questions dq
        WHERE dq.question_id = q.question_id
        ORDER BY dq.disease_id
        LIMIT 1) AS disease_id,
      (SELECT d.disease_th
         FROM diseases d
        WHERE d.disease_id = (
          SELECT dq2.disease_id
            FROM disease_questions dq2
           WHERE dq2.question_id = q.question_id
           ORDER BY dq2.disease_id
           LIMIT 1
        )) AS disease_name
    FROM questions q
    {$whereSql}
    ORDER BY q.sort_order ASC, q.question_id ASC"
  );
  $stmt->execute($params);

  json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err('DB_ERROR', $e->getMessage(), 500);
}
