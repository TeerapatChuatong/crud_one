<?php
// api/disease_desc/create_disease_desc.php
require_once __DIR__ . '/../db.php';

// ให้เฉพาะ admin แก้ข้อมูลโรค
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

// ----- อ่าน JSON -----
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  json_err("INVALID_JSON", "invalid_json", 400);
}

// ----- ดึงค่าจาก body -----
$disease_id  = trim($data['disease_id']  ?? '');
$description = trim($data['description'] ?? '');
$causes      = trim($data['causes']      ?? '');
$symptoms    = trim($data['symptoms']    ?? '');

// validate เบื้องต้น
if ($disease_id === '') {
  json_err("VALIDATION_ERROR", "disease_id_required", 400);
}

// ถ้า disease_id เป็นเลขล้วน ๆ ก็ตรวจเพิ่มได้
if (!ctype_digit($disease_id)) {
  json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
}

try {
  // INSERT ไม่ต้องระบุ info_id ให้ DB สร้างเอง
  $sql = "
    INSERT INTO disease_desc (
      disease_id,
      description,
      causes,
      symptoms
    )
    VALUES (?, ?, ?, ?)
  ";

  $st = $dbh->prepare($sql);
  $st->execute([
    (int)$disease_id,
    $description !== '' ? $description : null,
    $causes      !== '' ? $causes      : null,
    $symptoms    !== '' ? $symptoms    : null,
  ]);

  $info_id = (int)$dbh->lastInsertId();

  // ดึงข้อมูลแถวที่เพิ่งสร้างส่งกลับ
  $st2 = $dbh->prepare("SELECT * FROM disease_desc WHERE info_id = ?");
  $st2->execute([$info_id]);
  $row = $st2->fetch(PDO::FETCH_ASSOC);

  json_ok($row);

} catch (Throwable $e) {
  // ถ้าอยาก debug จริง ๆ ชั่วคราวให้ใช้ $e->getMessage()
  // json_err("DB_ERROR", $e->getMessage(), 500);
  json_err("DB_ERROR", "db_error", 500);
}
