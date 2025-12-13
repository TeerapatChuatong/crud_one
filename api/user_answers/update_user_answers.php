<?php
// crud/api/user_answers/update_user_answers.php
require_once __DIR__ . '/../db.php';
require_admin();

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$id = $input['user_answer_id'] ?? null;
if (!$id) {
  json_err("VALIDATION_ERROR", "ต้องระบุ user_answer_id");
}

$fields = [];
$params = [':id' => $id];

// อัปเดตเฉพาะฟิลด์ที่ส่งมา
foreach ([
  'user_id',
  'disease_question_id',
  'choice_id',
  'risk_score',
  'total_score',
  'answered_at',
] as $col) {
  if (array_key_exists($col, $input)) {
    $fields[] = "$col = :$col";
    $params[":$col"] = $input[$col];
  }
}

if (!$fields) {
  json_err("VALIDATION_ERROR", "ไม่มีฟิลด์ให้แก้ไข");
}

$sql = "UPDATE user_answers
        SET " . implode(', ', $fields) . "
        WHERE user_answer_id = :id";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  json_ok([
    'user_answer_id' => (int)$id,
    'affected_rows'  => $stmt->rowCount(),
    'message'        => 'updated',
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
