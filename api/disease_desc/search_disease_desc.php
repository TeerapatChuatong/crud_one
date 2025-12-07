<?php
// api/disease_desc/search_disease_desc.php
require_once __DIR__ . '/../db.php';
require_admin(); // ส่วนใหญ่ให้ admin แก้ข้อมูลโรค

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

$disease_id = trim($_GET['disease_id'] ?? '');
$q          = trim($_GET['q'] ?? ''); // ค้นใน description/causes/symptoms

try {
  $sql    = "SELECT * FROM disease_desc";
  $where  = [];
  $params = [];

  if ($disease_id !== '') {
    $where[]  = "disease_id = ?";
    $params[] = $disease_id;
  }

  if ($q !== '') {
    $like    = "%{$q}%";
    $where[] = "(description LIKE ? OR causes LIKE ? OR symptoms LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
  }

  if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= " ORDER BY disease_id ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
