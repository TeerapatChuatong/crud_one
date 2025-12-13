<?php
// crud/api/user_answers/delete_user_answers.php
require_once __DIR__ . '/../db.php';
require_admin();

// รับจาก query string หรือ JSON body ก็ได้
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$id = $_GET['user_answer_id'] ?? $input['user_answer_id'] ?? null;

if (!$id) {
  json_err("VALIDATION_ERROR", "ต้องระบุ user_answer_id");
}

$sql = "DELETE FROM user_answers WHERE user_answer_id = :id";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':id' => $id]);

  json_ok([
    'user_answer_id' => (int)$id,
    'deleted'        => $stmt->rowCount() > 0,
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
