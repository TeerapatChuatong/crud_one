<?php
require_once __DIR__ . '/../db.php';

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

$risk_level_id = $_GET['risk_level_id'] ?? ($_GET['id'] ?? '');
$disease_id    = $_GET['disease_id'] ?? '';
$level_code    = strtolower(trim($_GET['level_code'] ?? ''));
$score_raw     = $_GET['score'] ?? '';

$allowedLevels = ['low','medium','high'];

try {

  // 1) read by risk_level_id
  if ($risk_level_id !== '') {
    if (!ctype_digit((string)$risk_level_id)) json_err("VALIDATION_ERROR","invalid_risk_level_id",400);

    $st = $dbh->prepare("
      SELECT
        risk_level_id AS id,
        risk_level_id,
        disease_id,
        level_code,
        min_score,
        days,
        times
      FROM disease_risk_levels
      WHERE risk_level_id=?
      LIMIT 1
    ");
    $st->execute([(int)$risk_level_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err("NOT_FOUND","not_found",404);

    $row['level_name_th'] = level_name_th($row['level_code']);
    json_ok($row);
  }

  // 2) evaluate by disease_id + score  ✅ (อันนี้สำคัญ)
  if ($disease_id !== '' && $score_raw !== '') {
    if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
    if (!is_numeric($score_raw)) json_err("VALIDATION_ERROR","invalid_score",400);

    $score = (float)$score_raw;

    // เลือกระดับที่ "min_score <= score" ที่ดีที่สุด (min_score มากสุด)
    $st = $dbh->prepare("
      SELECT
        risk_level_id AS id,
        risk_level_id,
        disease_id,
        level_code,
        min_score,
        days,
        times
      FROM disease_risk_levels
      WHERE disease_id=? AND min_score <= ?
      ORDER BY
        min_score DESC,
        FIELD(level_code,'high','medium','low') DESC
      LIMIT 1
    ");
    $st->execute([(int)$disease_id, $score]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    // ถ้าคะแนนต่ำกว่าทุก min_score (เช่น score=0 แต่ low min_score=1) -> fallback เอาต่ำสุดให้สักอัน
    if (!$row) {
      $st2 = $dbh->prepare("
        SELECT
          risk_level_id AS id,
          risk_level_id,
          disease_id,
          level_code,
          min_score,
          days,
          times
        FROM disease_risk_levels
        WHERE disease_id=?
        ORDER BY
          min_score ASC,
          FIELD(level_code,'low','medium','high') ASC
        LIMIT 1
      ");
      $st2->execute([(int)$disease_id]);
      $row = $st2->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) json_err("NOT_FOUND","risk_levels_not_found_for_disease",404);

    $row['level_name_th'] = level_name_th($row['level_code']);
    json_ok($row);
  }

  // 3) filter by disease_id / level_code (optional)
  $where = [];
  $params = [];

  if ($disease_id !== '') {
    if (!ctype_digit((string)$disease_id)) json_err("VALIDATION_ERROR","invalid_disease_id",400);
    $where[] = "disease_id=?";
    $params[] = (int)$disease_id;
  }
  if ($level_code !== '') {
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
      days,
      times
    FROM disease_risk_levels
  ";
  if ($where) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY disease_id ASC, min_score ASC, FIELD(level_code,'low','medium','high') ASC";

  $st = $dbh->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$r) {
    $r['level_name_th'] = level_name_th($r['level_code']);
  }

  json_ok($rows);

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
