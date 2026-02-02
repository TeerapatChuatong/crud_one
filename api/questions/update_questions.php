<?php
require_once __DIR__ . '/../db.php';
require_admin();

/*
  สำคัญมาก:
  - PHP "ไม่ parse" multipart/form-data เมื่อ method เป็น PATCH/PUT
  - ดังนั้นไฟล์นี้รองรับ:
      1) POST  => สำหรับ FormData + ไฟล์ (แนะนำ)
      2) PATCH => สำหรับ JSON (ไม่มีไฟล์)
*/

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['POST', 'PATCH'], true)) {
  json_err("METHOD_NOT_ALLOWED", "post_or_patch_only", 405);
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

function str_field_nullable($v) {
  if ($v === null) return null;
  $s = trim((string)$v);
  return ($s === '') ? null : $s;
}
function str_field_req($v, $name, $minLen = 1) {
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
// Parse input
// --------------------
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$raw = file_get_contents('php://input');

$body = [];
if ($method === 'PATCH') {
  // PATCH: แนะนำให้ส่ง JSON เท่านั้น
  $tmp = json_decode($raw, true);
  if (is_array($tmp)) $body = $tmp;
} else {
  // POST: รองรับ FormData + JSON
  if (stripos($contentType, 'application/json') !== false) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $body = $tmp;
  } else {
    $body = $_POST ?? [];
    if (empty($body)) {
      $tmp = json_decode($raw, true);
      if (is_array($tmp)) $body = $tmp;
    }
  }
}

$question_id    = int_field($body['question_id'] ?? null, "question_id", 1);
$disease_id     = int_field_nullable($body['disease_id'] ?? null, "disease_id", 0);

$question_text  = str_field_nullable($body['question_text'] ?? null);
$question_type  = str_field_nullable($body['question_type'] ?? null);

// ✅ ใหม่: answer_source (manual|chemicals) ตั้งค่าแบบถาวรต่อคำถาม
$answer_source = null;
if (array_key_exists('answer_source', $body)) {
  $answer_source = normalize_answer_source($body['answer_source']);
  // ถ้าเลือก chemicals ให้บังคับ question_type เป็น multi
  if ($answer_source === 'chemicals') $question_type = 'multi';
}

$max_score      = int_field_nullable($body['max_score'] ?? null, "max_score", 0);
$sort_order     = int_field_nullable($body['sort_order'] ?? ($body['order_no'] ?? null), "sort_order", 0);

// รูป: example_image / image_url / file
$image_url = str_field_nullable($body['image_url'] ?? ($body['example_image'] ?? null));

// POST multipart: ถ้ามีไฟล์ ให้อัปโหลดทับ
if ($method === 'POST') {
  $fileUrl = upload_image('example_image_file', 'question_images');
  if ($fileUrl) $image_url = $fileUrl;
}

$allowTypes = ['yes_no','numeric','multi'];
if ($question_type !== null && !in_array($question_type, $allowTypes, true)) {
  json_err("VALIDATION_ERROR", "invalid_question_type", 422);
}

try {
  $pdo->beginTransaction();

  $has_answer_source = has_column($pdo, 'questions', 'answer_source');

  // check exists
  $chk = $pdo->prepare("SELECT question_id FROM questions WHERE question_id = ? LIMIT 1");
  $chk->execute([$question_id]);
  if (!$chk->fetchColumn()) {
    $pdo->rollBack();
    json_err("NOT_FOUND", "question_not_found", 404);
  }

  $setMap = [];
  $vals   = [];

  if ($question_text !== null) { $setMap['question_text'] = $question_text; }
  if ($question_type !== null) { $setMap['question_type'] = $question_type; }
  if ($max_score !== null)     { $setMap['max_score']     = (int)$max_score; }
  if ($sort_order !== null)    { $setMap['sort_order']    = (int)$sort_order; }
  if ($image_url !== null)     { $setMap['image_url']     = ($image_url !== '' ? $image_url : null); }

  // ✅ ใหม่: answer_source (ถ้ามีคอลัมน์ และมีการส่งค่าเข้ามา)
  if ($has_answer_source && $answer_source !== null) {
    $setMap['answer_source'] = $answer_source;

    // ถ้าเป็น chemicals ให้บังคับ question_type เป็น multi เสมอ
    if ($answer_source === 'chemicals') {
      $setMap['question_type'] = 'multi';
    }
  }

  $fields = [];
  foreach ($setMap as $k => $v) {
    $fields[] = "{$k} = ?";
    $vals[] = $v;
  }

  if (!empty($fields)) {
    $vals[] = $question_id;
    $sql = "UPDATE questions SET " . implode(", ", $fields) . " WHERE question_id = ?";
    $up = $pdo->prepare($sql);
    $up->execute($vals);
  }

  // pivot: ถ้าส่ง disease_id มา ให้จัด mapping แบบ 1 โรค/1 คำถาม (ตาม UI dropdown)
  if ($disease_id !== null) {
    // ลบ mapping เดิมทั้งหมด
    $del = $pdo->prepare("DELETE FROM disease_questions WHERE question_id = ?");
    $del->execute([$question_id]);

    // ถ้า disease_id > 0 ให้เพิ่มใหม่
    if ((int)$disease_id > 0) {
      $ins = $pdo->prepare("
        INSERT INTO disease_questions (disease_id, question_id)
        VALUES (?, ?)
      ");
      $ins->execute([(int)$disease_id, $question_id]);
    }
  }

  $outSelect = $has_answer_source ? "answer_source" : "'manual' AS answer_source";
  $out = $pdo->prepare("
    SELECT question_id, question_text, question_type, $outSelect, max_score, sort_order, image_url
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
