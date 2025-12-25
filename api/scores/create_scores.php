<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || empty($body)) $body = $_POST ?? [];

function int_field($v, $name, $min = 0) {
  if ($v === null || $v === '') json_err("VALIDATION_ERROR", "{$name}_required", 422);
  if (is_int($v)) {
    if ($v < $min) json_err("VALIDATION_ERROR", "{$name}_min_{$min}", 422);
    return $v;
  }
  $s = trim((string)$v);
  if (!preg_match('/^\d+$/', $s)) json_err("VALIDATION_ERROR", "{$name}_must_be_int", 422);
  $n = (int)$s;
  if ($n < $min) json_err("VALIDATION_ERROR", "{$name}_min_{$min}", 422);
  return $n;
}

$disease_id = int_field($body['disease_id'] ?? null, "disease_id", 1);
$question_id = int_field($body['question_id'] ?? null, "question_id", 1);
$choice_id = int_field($body['choice_id'] ?? null, "choice_id", 1);

// รองรับชื่อฟิลด์จาก frontend ได้หลายแบบ
$score_value = $body['score_value'] ?? $body['risk_score'] ?? $body['score'] ?? 0;
$score_value = int_field($score_value, "score_value", 0);

try {
  $pdo->beginTransaction();

  // หา disease_question_id + max_score
  $st = $pdo->prepare("
    SELECT dq.disease_question_id, dq.question_id, q.max_score
    FROM disease_questions dq
    JOIN questions q ON q.question_id = dq.question_id
    WHERE dq.disease_id = ? AND dq.question_id = ?
    LIMIT 1
  ");
  $st->execute([$disease_id, $question_id]);
  $dq = $st->fetch(PDO::FETCH_ASSOC);

  if (!$dq) {
    $pdo->rollBack();
    json_err("NOT_FOUND", "disease_question_not_found", 404);
  }

  $disease_question_id = (int)$dq['disease_question_id'];
  $max_score = isset($dq['max_score']) ? (int)$dq['max_score'] : 0;

  // รวมคะแนนทั้งหมด (ยกเว้น choice นี้) แล้ว + คะแนนใหม่
  if ($max_score > 0) {
    $sumSt = $pdo->prepare("
      SELECT COALESCE(SUM(score_value),0) AS total
      FROM scores
      WHERE disease_question_id = ? AND choice_id <> ?
    ");
    $sumSt->execute([$disease_question_id, $choice_id]);
    $base = (int)($sumSt->fetchColumn() ?? 0);

    $newTotal = $base + $score_value;
    if ($newTotal > $max_score) {
      $pdo->rollBack();
      http_response_code(422);
      echo json_encode([
        "ok" => false,
        "error" => "MAX_SCORE_EXCEEDED",
        "message" => "คะแนนรวมของคำตอบทั้งหมด ($newTotal) เกินคะแนนสูงสุดของคำถาม ($max_score)",
        "max_score" => $max_score,
        "total_after" => $newTotal,
        "over_by" => $newTotal - $max_score,
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  // upsert score
  $ins = $pdo->prepare("
    INSERT INTO scores (disease_question_id, choice_id, score_value)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE score_value = VALUES(score_value)
  ");
  $ins->execute([$disease_question_id, $choice_id, $score_value]);

  // ส่งแถวล่าสุดกลับ
  $out = $pdo->prepare("
    SELECT score_id, disease_question_id, choice_id, score_value
    FROM scores
    WHERE disease_question_id = ? AND choice_id = ?
    LIMIT 1
  ");
  $out->execute([$disease_question_id, $choice_id]);

  $pdo->commit();
  json_ok($out->fetch(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_err("DB_ERROR", "db_error", 500);
}
