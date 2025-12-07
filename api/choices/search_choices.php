<?php
// api/choices/search_choices.php
require_once __DIR__ . '/../db.php';

// ให้เฉพาะแอดมินค้นตัวเลือกคำตอบได้
// ถ้าจะให้ user ปกติใช้ได้ด้วย เปลี่ยนเป็น require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

// keyword สำหรับค้นหา label
$q = trim($_GET['q'] ?? '');

// filter ตาม question_id (optional)
$question_id = trim($_GET['question_id'] ?? '');

try {
  $sql = "
    SELECT
      c.choice_id,
      c.question_id,
      c.choice_label,
      c.image_url,
      q.question_text
    FROM choices c
    LEFT JOIN questions q
      ON q.question_id = c.question_id
  ";

  $where  = [];
  $params = [];

  // filter ตาม question_id
  if ($question_id !== '') {
    if (!ctype_digit($question_id)) {
      json_err("VALIDATION_ERROR", "invalid_question_id", 400);
    }
    $where[]  = "c.question_id = ?";
    $params[] = (int)$question_id;
  }

  // filter ตาม keyword ใน choice_label
  if ($q !== '') {
    $where[]  = "c.choice_label LIKE ?";
    $params[] = "%{$q}%";
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  // เรียงตาม question_id ก่อน แล้วค่อย choice_id
  $sql .= " ORDER BY c.question_id ASC, c.choice_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  json_ok($rows);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
