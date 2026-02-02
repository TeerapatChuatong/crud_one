<?php
$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

require_admin();

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

function get_enum_param(string $key, array $allowed): ?string {
  if (!isset($_GET[$key])) return null;
  $v = trim((string)$_GET[$key]);
  if ($v === '' || !in_array($v, $allowed, true)) {
    json_err('VALIDATION_ERROR', $key . '_invalid', 400);
  }
  return $v;
}

try {
  $q = trim((string)($_GET['q'] ?? ''));
  if ($q === '') {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $has_is_active = has_column($pdo, 'questions', 'is_active');
  $isActiveSelect = $has_is_active ? 'q.is_active' : '1 AS is_active';

  $has_example_image = has_column($pdo, 'questions', 'example_image');
  $exampleSelect = $has_example_image ? 'q.example_image' : 'NULL AS example_image';

  // ✅ ใหม่: image_url
  $has_image_url = has_column($pdo, 'questions', 'image_url');
  $imageUrlSelect = $has_image_url ? 'q.image_url' : 'NULL AS image_url';

  $where = ["q.question_text LIKE :q"];
  $params = [':q' => "%{$q}%"];

  $answer_scope = get_enum_param('answer_scope', ['scan','profile']);
  if ($answer_scope) {
    $where[] = "q.answer_scope = :answer_scope";
    $params[':answer_scope'] = $answer_scope;
  }

  $purpose = get_enum_param('purpose', ['severity','recommendation']);
  if ($purpose) {
    $where[] = "q.purpose = :purpose";
    $params[':purpose'] = $purpose;
  }

  if (isset($_GET['disease_id'])) {
    $did = (int)$_GET['disease_id'];
    if ($did > 0) {
      $where[] = "EXISTS (SELECT 1 FROM disease_questions dq WHERE dq.question_id=q.question_id AND dq.disease_id=:disease_id)";
      $params[':disease_id'] = $did;
    } else {
      $where[] = "NOT EXISTS (SELECT 1 FROM disease_questions dq WHERE dq.question_id=q.question_id)";
    }
  }

  $whereSql = "WHERE " . implode(" AND ", $where);

  $stmt = $pdo->prepare(
    "SELECT
      q.question_id,
      q.question_text,
      {$imageUrlSelect},
      {$exampleSelect},
      q.question_type,
      q.purpose,
      q.answer_scope,
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
    ORDER BY q.question_id ASC"
  );
  $stmt->execute($params);

  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  json_err('DB_ERROR', $e->getMessage(), 500);
}
