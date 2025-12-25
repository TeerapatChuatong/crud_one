<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err(405, "method_not_allowed", "Only GET is allowed");
  exit;
}

$disease_id = isset($_GET['disease_id']) ? (int)$_GET['disease_id'] : 0;
$question_id = isset($_GET['question_id']) ? (int)$_GET['question_id'] : 0;
$disease_question_id = isset($_GET['disease_question_id']) ? (int)$_GET['disease_question_id'] : 0;

try {
  // ถ้าส่ง disease_id + question_id มา → หา disease_question_id ก่อน
  if ($disease_question_id <= 0) {
    if ($disease_id > 0 && $question_id > 0) {
      $st = $pdo->prepare("
        SELECT disease_question_id
        FROM disease_questions
        WHERE disease_id = ? AND question_id = ?
        LIMIT 1
      ");
      $st->execute([$disease_id, $question_id]);
      $dq = $st->fetch(PDO::FETCH_ASSOC);

      if (!$dq) {
        // ไม่มีการผูกโรค-คำถาม → ส่งกลับว่าง (อย่า 500)
        json_ok([]);
        exit;
      }
      $disease_question_id = (int)$dq['disease_question_id'];
    } else {
      // ไม่ส่งพารามิเตอร์มาเลย → คืนทั้งหมด (กันหน้าพัง)
      $st = $pdo->query("
        SELECT
          score_id,
          disease_question_id,
          choice_id,
          score_value,
          score_value AS risk_score
        FROM scores
        ORDER BY disease_question_id, choice_id
      ");
      json_ok($st->fetchAll(PDO::FETCH_ASSOC));
      exit;
    }
  }

  // คืน scores ของคำถามนั้น (สำคัญ: ต้องมี choice_id + score_value/risk_score)
  $st = $pdo->prepare("
    SELECT
      s.score_id,
      s.disease_question_id,
      s.choice_id,
      s.score_value,
      s.score_value AS risk_score
    FROM scores s
    WHERE s.disease_question_id = ?
    ORDER BY s.choice_id
  ");
  $st->execute([$disease_question_id]);

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  json_ok($rows);

} catch (Throwable $e) {
  json_err(500, "db_error", $e->getMessage());
}
