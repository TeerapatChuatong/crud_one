<?php
require_once __DIR__ . '/../db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

function has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
  );
  $st->execute([$table, $col]);
  return ((int)$st->fetchColumn()) > 0;
}

function normalize_answer_source($v) {
  $s = strtolower(trim((string)($v ?? 'manual')));
  if ($s === 'chemical') $s = 'chemicals';
  if (!in_array($s, ['manual','chemicals'], true)) $s = 'manual';
  return $s;
}

function str_field($v, $name, $minLen = 1) {
  $s = trim((string)($v ?? ''));
  if (mb_strlen($s) < $minLen) json_err("VALIDATION_ERROR", "{$name}_required", 422);
  return $s;
}
function int_field_nullable($v, $name, $min = 0) {
  if ($v === null || $v === '') return null;
  $s = trim((string)$v);
  if (!preg_match('/^\d+$/', $s)) json_err("VALIDATION_ERROR", "{$name}_must_be_int", 422);
  $n = (int)$s;
  if ($n < $min) json_err("VALIDATION_ERROR", "{$name}_min_{$min}", 422);
  return $n;
}
function int_field($v, $name, $min = 0) {
  $n = int_field_nullable($v, $name, $min);
  if ($n === null) json_err("VALIDATION_ERROR", "{$name}_required", 422);
  return $n;
}
function has_file($key) {
  return isset($_FILES[$key]) && is_array($_FILES[$key]) && ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

function upload_image($fileKey, $subDir) {
  if (!has_file($fileKey)) return null;

  $f = $_FILES[$fileKey];
  if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    json_err("UPLOAD_ERROR", "upload_failed", 400);
  }

  $maxBytes = 6 * 1024 * 1024; // 6MB
  if (($f['size'] ?? 0) > $maxBytes) {
    json_err("UPLOAD_ERROR", "file_too_large", 400);
  }

  $orig = $f['name'] ?? 'file';
  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  $allow = ['jpg','jpeg','png','webp'];
  if (!in_array($ext, $allow, true)) {
    json_err("UPLOAD_ERROR", "invalid_file_type", 400);
  }

  // fs dir: /crud/uploads/question_images
  $fsDir = realpath(__DIR__ . '/../../uploads');
  if ($fsDir === false) {
    $base = __DIR__ . '/../../uploads';
    if (!is_dir($base)) mkdir($base, 0775, true);
    $fsDir = realpath($base);
  }

  $targetDir = rtrim($fsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $subDir;
  if (!is_dir($targetDir)) mkdir($targetDir, 0775, true);

  $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
  $name = $safe . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

  $dest = $targetDir . DIRECTORY_SEPARATOR . $name;
  if (!move_uploaded_file($f['tmp_name'], $dest)) {
    json_err("UPLOAD_ERROR", "move_failed", 500);
  }

  // public path: /crud/uploads/question_images/xxx.jpg
  $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
  $root = preg_replace('~/api/.*$~', '', $script); // -> /crud
  $root = rtrim($root, '/');
  return $root . '/uploads/' . $subDir . '/' . $name;
}

// --------------------
// Parse input (JSON or multipart)
// --------------------
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$raw = file_get_contents('php://input');

$body = [];
if (stripos($contentType, 'application/json') !== false) {
  $body = json_decode($raw, true);
  if (!is_array($body)) $body = [];
} else {
  $body = $_POST ?? [];
  // กันพลาด: client ส่ง JSON แต่ไม่ตั้ง header
  if (empty($body)) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $body = $tmp;
  }
}

// รองรับ key หลายแบบจาก frontend
$disease_id     = int_field_nullable($body['disease_id'] ?? null, "disease_id", 0); // 0/NULL = ไม่ผูกโรค
$question_text  = str_field($body['question_text'] ?? null, "question_text", 1);
$question_type  = str_field($body['question_type'] ?? null, "question_type", 1);
$max_score      = int_field($body['max_score'] ?? 0, "max_score", 0);
$sort_order     = int_field_nullable($body['sort_order'] ?? ($body['order_no'] ?? null), "sort_order", 0);
if ($sort_order === null) $sort_order = 0;

// ✅ ใหม่: answer_source (manual|chemicals) ตั้งค่าแบบถาวรต่อคำถาม
$answer_source = normalize_answer_source($body['answer_source'] ?? 'manual');
if ($answer_source === 'chemicals') {
  // บังคับให้เป็น multi เพราะต้องรองรับเลือกหลายสาร/คะแนน
  $question_type = 'multi';
}

// รูป: รับได้ทั้ง example_image / image_url / file
$image_url = trim((string)($body['image_url'] ?? ($body['example_image'] ?? '')));

// ถ้ามีไฟล์ ให้ upload ทับ image_url
$fileUrl = upload_image('example_image_file', 'question_images');
if (!$fileUrl) {
  // รองรับ key เผื่อ frontend ส่งเป็น image_file
  $fileUrl = upload_image('image_file', 'question_images');
}
if ($fileUrl) $image_url = $fileUrl;

// validate type
$allowTypes = ['yes_no','numeric','multi'];
if (!in_array($question_type, $allowTypes, true)) {
  json_err("VALIDATION_ERROR", "invalid_question_type", 422);
}

try {
  $pdo->beginTransaction();

  $has_answer_source = has_column($pdo, 'questions', 'answer_source');

  if ($has_answer_source) {
    $st = $pdo->prepare("
      INSERT INTO questions (question_text, question_type, answer_source, max_score, sort_order, image_url)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $st->execute([
      $question_text,
      $question_type,
      $answer_source,
      $max_score,
      $sort_order,
      ($image_url !== '' ? $image_url : null)
    ]);
  } else {
    // fallback ถ้ายังไม่มีคอลัมน์ answer_source
    $st = $pdo->prepare("
      INSERT INTO questions (question_text, question_type, max_score, sort_order, image_url)
      VALUES (?, ?, ?, ?, ?)
    ");
    $st->execute([
      $question_text,
      $question_type,
      $max_score,
      $sort_order,
      ($image_url !== '' ? $image_url : null)
    ]);
  }

  $question_id = (int)$pdo->lastInsertId();

  // ถ้า disease_id > 0 ให้ผูก pivot disease_questions
  if ($disease_id !== null && (int)$disease_id > 0) {
    $ins = $pdo->prepare("
      INSERT INTO disease_questions (disease_id, question_id)
      VALUES (?, ?)
    ");
    $ins->execute([(int)$disease_id, $question_id]);
  }

  $answerSelect = $has_answer_source ? "answer_source" : "'manual' AS answer_source";

  $out = $pdo->prepare("
    SELECT question_id, question_text, question_type, $answerSelect, max_score, sort_order, image_url
    FROM questions
    WHERE question_id = ?
    LIMIT 1
  ");
  $out->execute([$question_id]);

  $pdo->commit();
  json_ok($out->fetch(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_err("DB_ERROR", "db_error", 500);
}
