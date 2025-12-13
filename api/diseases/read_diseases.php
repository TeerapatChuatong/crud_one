<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

try {
  $st = $dbh->query("SELECT * FROM diseases ORDER BY CAST(disease_id AS UNSIGNED) ASC");
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
