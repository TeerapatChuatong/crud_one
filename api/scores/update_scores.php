<?php
require_once __DIR__ . '/../db.php';

try {
  require_admin();

  $body = json_decode(file_get_contents("php://input"), true) ?: [];

  $disease_question_id = $body['disease_question_id'] ?? null;
  $scores = $body['scores'] ?? null; // array [{choice_id, score_value}]

  // รองรับส่งเดี่ยว
  if ($scores === null && isset($body['choice_id'])) {
    $scores = [[
      "choice_id" => $body["choice_id"],
      "score_value" => $body["score_value"] ?? 0
    ]];
  }

  if (!ctype_digit((string)$disease_question_id) || !is_array($scores)) {
    json_err('VALIDATION_ERROR', 'invalid_input', 400);
  }

  $pdo->beginTransaction();

  $sel = $pdo->prepare("SELECT score_id FROM scores WHERE disease_question_id=? AND choice_id=? LIMIT 1");
  $ins = $pdo->prepare("INSERT INTO scores (disease_question_id, choice_id, score_value) VALUES (?,?,?)");
  $upd = $pdo->prepare("UPDATE scores SET score_value=? WHERE score_id=?");

  $out = [];

  foreach ($scores as $s) {
    $choice_id = $s['choice_id'] ?? null;
    $score_value = $s['score_value'] ?? 0;

    if (!ctype_digit((string)$choice_id)) continue;

    $sel->execute([(int)$disease_question_id, (int)$choice_id]);
    $found = $sel->fetch();

    if ($found) {
      $upd->execute([(int)$score_value, (int)$found['score_id']]);
      $out[] = ["score_id" => (int)$found['score_id'], "choice_id" => (int)$choice_id, "score_value" => (int)$score_value];
    } else {
      $ins->execute([(int)$disease_question_id, (int)$choice_id, (int)$score_value]);
      $out[] = ["score_id" => (int)$pdo->lastInsertId(), "choice_id" => (int)$choice_id, "score_value" => (int)$score_value];
    }
  }

  $pdo->commit();

  json_ok(["updated" => true, "items" => $out]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  json_err('DB_ERROR', 'db_error', 500);
}
