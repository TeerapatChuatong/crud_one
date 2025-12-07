<?php
// api/disease_images/search_disease_images.php
require_once __DIR__ . '/../db.php';
require_admin(); // ถ้าจะให้ user ดึงรูปก็เปลี่ยนเป็น require_login()

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$disease_id = trim($_GET['disease_id'] ?? '');
$is_default = trim($_GET['is_default'] ?? ''); // "0" / "1"
$q          = trim($_GET['q'] ?? '');          // ค้นใน description

try {
  $sql    = "SELECT * FROM disease_images";
  $where  = [];
  $params = [];

  if ($disease_id !== '') {
    $where[]  = "disease_id = ?";
    $params[] = $disease_id;
  }

  if ($is_default !== '' && ($is_default === '0' || $is_default === '1')) {
    $where[]  = "is_default = ?";
    $params[] = (int)$is_default;
  }

  if ($q !== '') {
    $where[]  = "description LIKE ?";
    $params[] = "%{$q}%";
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY disease_id ASC, is_default DESC, image_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
