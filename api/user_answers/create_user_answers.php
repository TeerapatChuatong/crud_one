<?php
// crud/api/user_answers/create_user_answers.php
require_once __DIR__ . '/../db.php';
require_admin(); // ให้เฉพาะ admin ใช้ผ่าน dashboard

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// required
$user_id            = $input['user_id']            ?? null;
$disease_question_id= $input['disease_question_id']?? null;
$choice_id          = $input['choice_id']          ?? null;
$risk_score         = $input['risk_score']         ?? null;

// optional
$total_score        = $input['total_score']        ?? null;
$answered_at        = $input['answered_at']        ?? null; // ถ้าไม่ส่งให้ใช้ default ใน DB

if (!$user_id || !$disease_question_id || !$choice_id || $risk_score === null) {
  json_err("VALIDATION_ERROR", "user_id, disease_question_id, choice_id, risk_score จำเป็นต้องกรอก");
}

// สร้าง SQL ตามว่ามี answered_at หรือไม่
$columns = ['user_id','disease_question_id','choice_id','risk_score','total_score'];
$params  = [
  ':user_id'             => $user_id,
  ':disease_question_id' => $disease_question_id,
  ':choice_id'           => $choice_id,
  ':risk_score'          => $risk_score,
  ':total_score'         => $total_score,
];

if (!empty($answered_at)) {
  $columns[]              = 'answered_at';
  $params[':answered_at'] = $answered_at; // รูปแบบ 'YYYY-mm-dd HH:ii:ss'
}

$sql = "INSERT INTO user_answers (" . implode(',', $columns) . ")
        VALUES (" . implode(',', array_keys($params)) . ")";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $newId = (int)$pdo->lastInsertId();

  json_ok([
    'user_answer_id' => $newId,
    'message'        => 'created',
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
