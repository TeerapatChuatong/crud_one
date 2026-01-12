<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED","get_only",405);
}

function level_name_th($code) {
  $code = strtolower(trim((string)$code));
  if ($code === 'high') return 'มาก';
  if ($code === 'medium') return 'ปานกลาง';
  if ($code === 'low') return 'น้อย';
  return $code;
}

$allowedLevels = ['low','medium','high'];

$risk_level_id = $_GET['risk_level_id'] ?? ($_GET['id'] ?? '');
$disease_id    = $_GET['disease_id'] ?? '';
$level_code    = $_GET['level_code'] ?? ($_GET['code'] ?? '');
$score         = $_GET['score'] ?? '';

try {
  // 1) Read one by id
  if ($risk_level_id !== '') {
    if (!ctype_digit((string)$risk_level_id)) json_err("VALIDATION_ERROR","invalid_risk_level_id",400);

    $st = $dbh->prepare("
      SELECT
        risk_level_id AS id,
        risk_level_id,
        disease_id,
        level_code,
        min_score,
        COALESCE(days,0)  AS days,
        COALESCE(times,0) AS times
      FROM disease_risk_levels
      WHERE risk_level_id=?
      LIMIT 1
    ");
    $st->execute([(int)$risk_level_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err("NOT_FOUND","risk_level_not_found",404);

    $row['level_name_th'] = level_name_th($row['level_code']);
    // aliases (เผื่อฝั่งแอป/หน้าอื่นเรียกหลายชื่อ)
    $row['code'] = $row['level_code'];
    $row['name'] = $row['level_name_th'];
    $row['level_name'] = $row['level_name_th'];
    $row['risk_level_name'] = $row['level_name_th'];

    json_ok($row);
  }

  // 2) Find best level by score for a disease (optional)
  if ($disease_id !== '' && $score !== '') {
    if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
    if (!is_numeric($score)) json_err("VALIDATION_ERROR","invalid_score",400);

    $st = $dbh->prepare("
      SELECT
        risk_level_id AS id,
        risk_level_id,
        disease_id,
        level_code,
        min_score,
        COALESCE(days,0)  AS days,
        COALESCE(times,0) AS times
      FROM disease_risk_levels
      WHERE disease_id=? AND min_score<=?
      ORDER BY min_score DESC, FIELD(level_code,'low','medium','high') DESC
      LIMIT 1
    ");
    $st->execute([(int)$disease_id, (int)$score]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      // fallback → เอาระดับต่ำสุด
      $st2 = $dbh->prepare("
        SELECT
          risk_level_id AS id,
          risk_level_id,
          disease_id,
          level_code,
          min_score,
          COALESCE(days,0)  AS days,
          COALESCE(times,0) AS times
        FROM disease_risk_levels
        WHERE disease_id=?
        ORDER BY min_score ASC, FIELD(level_code,'low','medium','high') ASC
        LIMIT 1
      ");
      $st2->execute([(int)$disease_id]);
      $row = $st2->fetch(PDO::FETCH_ASSOC);
      if (!$row) json_err("NOT_FOUND","risk_level_not_found",404);
    }

    $row['level_name_th'] = level_name_th($row['level_code']);
    $row['code'] = $row['level_code'];
    $row['name'] = $row['level_name_th'];
    $row['level_name'] = $row['level_name_th'];
    $row['risk_level_name'] = $row['level_name_th'];

    json_ok($row);
  }

  // 3) Read list (by disease_id / level_code optional)
  $where = [];
  $params = [];

  if ($disease_id !== '') {
    if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
    $where[] = "disease_id=?";
    $params[] = (int)$disease_id;
  }
  if ($level_code !== '') {
    $level_code = strtolower(trim((string)$level_code));
    if (!in_array($level_code, $allowedLevels, true)) json_err("VALIDATION_ERROR","invalid_level_code",400);
    $where[] = "level_code=?";
    $params[] = $level_code;
  }

  $sql = "
    SELECT
      risk_level_id AS id,
      risk_level_id,
      disease_id,
      level_code,
      min_score,
      COALESCE(days,0)  AS days,
      COALESCE(times,0) AS times
    FROM disease_risk_levels
  ";
  if ($where) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY disease_id ASC, min_score ASC, FIELD(level_code,'low','medium','high') ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$r) {
    $r['level_name_th'] = level_name_th($r['level_code']);
    $r['code'] = $r['level_code'];
    $r['name'] = $r['level_name_th'];
    $r['level_name'] = $r['level_name_th'];
    $r['risk_level_name'] = $r['level_name_th'];
  }

  json_ok($rows);

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
