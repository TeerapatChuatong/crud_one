<?php
require_once __DIR__ . '/../db.php';
require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['PATCH', 'PUT', 'POST'], true)) {
  json_err("METHOD_NOT_ALLOWED", "patch_put_post_only", 405);
}

function is_dup_error(Throwable $e): bool {
  if ($e instanceof PDOException) {
    $sqlState = $e->errorInfo[0] ?? '';
    $driver  = $e->errorInfo[1] ?? 0;
    return $sqlState === '23000' || (int)$driver === 1062;
  }
  return false;
}

// Normalize text: trim และ normalize ช่องว่างซ้ำ
function normalizeText($text) {
  if ($text === null || $text === '') return $text;
  // แทนที่ whitespace หลายตัวเป็นช่องว่างเดียว และ trim
  return trim(preg_replace('/\s+/u', ' ', $text));
}

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) $body = $_POST ?? [];

$id = $body['choice_id'] ?? null;
if ($id === null || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR", "invalid_choice_id", 400);
}

// ตรวจสอบว่า choice_id มีอยู่จริง
try {
  $check = $dbh->prepare("SELECT choice_id, question_id, choice_label FROM choices WHERE choice_id = ?");
  $check->execute([(int)$id]);
  $existing = $check->fetch(PDO::FETCH_ASSOC);
  if (!$existing) {
    json_err("NOT_FOUND", "choice_not_found", 404);
  }
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}

$question_id  = array_key_exists('question_id', $body) ? $body['question_id'] : null;
$choice_label = array_key_exists('choice_label', $body) ? normalizeText($body['choice_label']) : null;
$choice_value = array_key_exists('choice_value', $body) ? trim((string)$body['choice_value']) : null;
$image_url    = array_key_exists('image_url', $body) ? trim((string)$body['image_url']) : null;
$sort_order   = array_key_exists('sort_order', $body) ? $body['sort_order'] : null;

$fields = [];
$params = [];

try {
  if ($question_id !== null) {
    if (!ctype_digit((string)$question_id)) {
      json_err("VALIDATION_ERROR", "invalid_question_id", 400);
    }

    $chk = $dbh->prepare("SELECT question_id FROM questions WHERE question_id = ? LIMIT 1");
    $chk->execute([(int)$question_id]);
    if (!$chk->fetch()) {
      json_err("NOT_FOUND", "question_not_found", 404);
    }

    $fields[] = "question_id = ?";
    $params[] = (int)$question_id;
  }

  if ($choice_label !== null) {
    if ($choice_label === '') {
      json_err("VALIDATION_ERROR", "choice_label_required", 400);
    }

    // ตรวจสอบ duplicate choice_label ใน question เดียวกัน (ยกเว้นตัวเอง)
    $target_question_id = $question_id !== null ? (int)$question_id : (int)$existing['question_id'];
    
    // เปรียบเทียบ choice_label หลัง normalize เท่านั้น
    // ถ้าเหมือนกับค่าเดิม (ตัวเอง) -> ไม่ต้องตรวจสอบ duplicate
    $existing_normalized = normalizeText($existing['choice_label']);
    $is_same_as_existing = ($choice_label === $existing_normalized && $target_question_id == $existing['question_id']);
    
    if (!$is_same_as_existing) {
      // ตรวจสอบว่ามี choice_label ซ้ำในคำถามเดียวกันหรือไม่
      $dupCheck = $dbh->prepare("
        SELECT choice_id, choice_label 
        FROM choices 
        WHERE question_id = ? 
          AND choice_id != ?
      ");
      $dupCheck->execute([$target_question_id, (int)$id]);
      
      while ($row = $dupCheck->fetch(PDO::FETCH_ASSOC)) {
        if (normalizeText($row['choice_label']) === $choice_label) {
          json_err("DUPLICATE", "choice_label_exists_in_question", 409);
        }
      }
    }

    $fields[] = "choice_label = ?";
    $params[] = $choice_label;
  }

  if ($choice_value !== null) {
    $fields[] = "choice_value = ?";
    $params[] = ($choice_value === '' ? null : $choice_value);
  }

  if ($image_url !== null) {
    $fields[] = "image_url = ?";
    $params[] = ($image_url === '' ? null : $image_url);
  }

  if ($sort_order !== null) {
    if (!is_numeric($sort_order)) {
      json_err("VALIDATION_ERROR", "invalid_sort_order", 400);
    }
    $fields[] = "sort_order = ?";
    $params[] = (int)$sort_order;
  }

  if (!$fields) {
    json_err("VALIDATION_ERROR", "nothing_to_update", 400);
  }

  $params[] = (int)$id;

  $sql = "UPDATE choices SET " . implode(', ', $fields) . " WHERE choice_id = ?";
  $st  = $dbh->prepare($sql);
  $st->execute($params);

  // ดึงข้อมูลที่อัปเดตแล้วกลับมา
  $q = $dbh->prepare("
    SELECT c.*, q.question_text
    FROM choices c
    LEFT JOIN questions q ON q.question_id = c.question_id
    WHERE c.choice_id = ?
    LIMIT 1
  ");
  $q->execute([(int)$id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);

  json_ok($row);

} catch (Throwable $e) {
  if (is_dup_error($e)) {
    json_err("DUPLICATE", "choice_label_exists_in_question", 409);
  }
  json_err("DB_ERROR", "db_error", 500);
}