<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

$treatment_id  = $_GET['treatment_id']  ?? null;
$disease_id    = $_GET['disease_id']    ?? null;
$risk_level_id = $_GET['risk_level_id'] ?? null;

try {
  if ($treatment_id !== null && $treatment_id !== '') {
    if (!ctype_digit((string)$treatment_id)) {
      json_err("VALIDATION_ERROR","invalid_treatment_id",400);
    }
    $st = $dbh->prepare("SELECT * FROM treatments WHERE treatment_id=?");
    $st->execute([(int)$treatment_id]);
    $row = $st->fetch();
    if (!$row) json_err("NOT_FOUND","not_found",404);
    json_ok($row);
  } else {
    $where = [];
    $params = [];

    if ($disease_id !== null && $disease_id !== '') {
      if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
      $where[] = "disease_id=?";
      $params[] = (int)$disease_id;
    }
    if ($risk_level_id !== null && $risk_level_id !== '') {
      if (!ctype_digit((string)$risk_level_id)) json_err("VALIDATION_ERROR","invalid_risk_level_id",400);
      $where[] = "risk_level_id=?";
      $params[] = (int)$risk_level_id;
    }

    $sql = "SELECT * FROM treatments";
    if ($where) {
      $sql .= " WHERE ".implode(" AND ",$where);
    }
    $sql .= " ORDER BY treatment_id ASC";

    $st = $dbh->prepare($sql);
    $st->execute($params);
    json_ok($st->fetchAll());
  }
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
