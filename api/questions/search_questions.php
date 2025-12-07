<?php
// api/questions/search_questions.php
require_once __DIR__ . '/../db.php';

/**
 * คิดว่า endpoint นี้ใช้ในหน้า Admin เท่านั้น
 * ถ้าอยากให้ user ปกติเรียกได้ด้วย ให้เปลี่ยนเป็น require_login()
 */
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

// keyword ที่ใช้ค้นหาในข้อความคำถาม
$q = trim($_GET['q'] ?? '');

// ถ้าส่ง disease_id มาด้วย → filter เฉพาะคำถามที่ผูกกับโรคนั้น
$disease_id = trim($_GET['disease_id'] ?? '');  // optional

try {
  $sql = "SELECT q.*
          FROM questions q";
  $params = [];

  // ถ้ามี disease_id → join กับ disease_questions
  if ($disease_id !== '') {
    $sql .= " INNER JOIN disease_questions dq
                ON dq.question_id = q.question_id
              WHERE dq.disease_id = ?";
    $params[] = $disease_id;

    if ($q !== '') {
      $sql .= " AND q.question_text LIKE ?";
      $params[] = "%{$q}%";
    }
  } else {
    // ไม่มี disease_id → ค้นหาจากทุกคำถาม
    if ($q !== '') {
      $sql .= " WHERE q.question_text LIKE ?";
      $params[] = "%{$q}%";
    }
  }

  // เรียงให้สวย: ตาม sort_order ก่อน แล้วค่อยตาม id
  $sql .= " ORDER BY q.sort_order ASC, q.question_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  json_ok($rows);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
