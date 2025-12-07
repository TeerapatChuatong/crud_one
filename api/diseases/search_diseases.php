<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$q = trim($_GET['q'] ?? '');

try {
  if ($q !== '') {
    $like = "%$q%";
    $st = $dbh->prepare("
      SELECT *
      FROM diseases
      WHERE disease_th LIKE ? OR disease_en LIKE ?
      ORDER BY disease_id ASC
    ");
    $st->execute([$like, $like]);
  } else {
    $st = $dbh->query("SELECT * FROM diseases ORDER BY disease_id ASC");
  }

  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
