<?php
// รองรับทั้งกรณี db.php อยู่ใน api/ หรืออยู่ที่ root CRUD/
$dbPath = __DIR__ . '/../db.php';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/../../db.php';
require_once $dbPath;

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
  json_err("METHOD_NOT_ALLOWED", "delete_only", 405);
}

function has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
  );
  $st->execute([$table, $col]);
  return ((int)$st->fetchColumn()) > 0;
}

function starts_with_str(string $s, string $prefix): bool {
  return $prefix === '' || strncmp($s, $prefix, strlen($prefix)) === 0;
}

$id = $_GET['question_id'] ?? null;
if (!$id || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR", "invalid_question_id", 400);
}

try {
  $qid = (int)$id;

  // ตรวจสอบว่ามีจริง + ดึงรูป (ถ้ามีคอลัมน์)
  $has_example_image = has_column($pdo, 'questions', 'example_image');
  $has_image_url = has_column($pdo, 'questions', 'image_url');

  if ($has_example_image || $has_image_url) {
    $selectCols = ['question_id'];
    if ($has_example_image) $selectCols[] = 'example_image';
    if ($has_image_url) $selectCols[] = 'image_url';
    $check = $dbh->prepare("SELECT " . implode(',', $selectCols) . " FROM questions WHERE question_id = ?");
    $check->execute([$qid]);
    $row = $check->fetch();
    if (!$row) json_err("NOT_FOUND", "question_not_found", 404);

    // ลบไฟล์จริง (จำกัดให้ลบเฉพาะ uploads/question_images/)
    $imgCandidates = [];
    if ($has_image_url) $imgCandidates[] = $row['image_url'] ?? null;
    if ($has_example_image) $imgCandidates[] = $row['example_image'] ?? null;

    foreach ($imgCandidates as $imgPath) {
      if (!empty($imgPath) && is_string($imgPath) && starts_with_str($imgPath, 'uploads/question_images/')) {
        $rootDir = dirname(__DIR__, 2); // CRUD/
        $absPath = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imgPath);

        $uploadsDir = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, 'uploads/question_images');
        $uploadsReal = realpath($uploadsDir);
        $absReal = realpath($absPath);

        if ($uploadsReal && $absReal && starts_with_str($absReal, $uploadsReal) && is_file($absReal)) {
          @unlink($absReal);
        }
      }
    }
  } else {
    $check = $dbh->prepare("SELECT question_id FROM questions WHERE question_id = ?");
    $check->execute([$qid]);
    if (!$check->fetch()) json_err("NOT_FOUND", "question_not_found", 404);
  }

  // ลบ record (จะ CASCADE ไปยัง pivot ต่าง ๆ ตาม FK ที่มีอยู่)
  $st = $dbh->prepare("DELETE FROM questions WHERE question_id = ?");
  $st->execute([$qid]);

  json_ok([
    "question_id" => $qid,
    "deleted" => true
  ]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
