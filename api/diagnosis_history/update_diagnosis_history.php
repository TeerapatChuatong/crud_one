<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php'; // ✅ รองรับ Authorization: Bearer <token>

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['PATCH','PUT','POST'], true)) {
  json_err("METHOD_NOT_ALLOWED", "patch_put_post_only", 405);
}

$isAdmin = is_admin();
$session_user_id = (int)($_SESSION['user_id'] ?? 0);

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) $body = $_POST ?? [];

$id = $body['diagnosis_history_id'] ?? $body['id'] ?? null;
if ($id === null || !ctype_digit((string)$id)) {
  json_err("VALIDATION_ERROR", "invalid_diagnosis_history_id", 400);
}

try {
  // โหลดของเดิม + เช็คสิทธิ์
  $chk = $dbh->prepare("SELECT * FROM diagnosis_history WHERE diagnosis_history_id=? LIMIT 1");
  $chk->execute([(int)$id]);
  $existing = $chk->fetch(PDO::FETCH_ASSOC);
  if (!$existing) json_err("NOT_FOUND", "not_found", 404);

  if (!$isAdmin && (int)$existing['user_id'] !== $session_user_id) {
    json_err("FORBIDDEN", "not_owner", 403);
  }

  $fields = [];
  $params = [];

  // ✅ user แก้ได้: risk_level_id, total_score, image_url, diagnosed_at
  // ✅ admin แก้เพิ่มได้: user_id, tree_id, disease_id

  if ($isAdmin && array_key_exists('user_id', $body)) {
    if (!ctype_digit((string)$body['user_id'])) json_err("VALIDATION_ERROR","invalid_user_id",400);
    $fields[] = "user_id = ?";
    $params[] = (int)$body['user_id'];
  }

  if ($isAdmin && array_key_exists('tree_id', $body)) {
    if (!ctype_digit((string)$body['tree_id'])) json_err("VALIDATION_ERROR","invalid_tree_id",400);
    $fields[] = "tree_id = ?";
    $params[] = (int)$body['tree_id'];
  }

  if ($isAdmin && array_key_exists('disease_id', $body)) {
    if (!ctype_digit((string)$body['disease_id'])) json_err("VALIDATION_ERROR","invalid_disease_id",400);
    $fields[] = "disease_id = ?";
    $params[] = (int)$body['disease_id'];
  }

  $newDiseaseId = $isAdmin && array_key_exists('disease_id', $body)
    ? (int)$body['disease_id']
    : (int)$existing['disease_id'];

  if (array_key_exists('risk_level_id', $body)) {
    $rv = $body['risk_level_id'];
    if ($rv === null || $rv === '') {
      $fields[] = "risk_level_id = NULL";
    } else {
      if (!ctype_digit((string)$rv)) json_err("VALIDATION_ERROR","invalid_risk_level_id",400);
      // risk ต้องเป็นของโรคเดียวกัน
      $chkR = $dbh->prepare("SELECT risk_level_id FROM disease_risk_levels WHERE risk_level_id=? AND disease_id=? LIMIT 1");
      $chkR->execute([(int)$rv, $newDiseaseId]);
      if (!$chkR->fetch()) json_err("NOT_FOUND", "risk_level_not_found_for_disease", 404);

      $fields[] = "risk_level_id = ?";
      $params[] = (int)$rv;
    }
  }

  if (array_key_exists('total_score', $body)) {
    if (!is_numeric($body['total_score'])) json_err("VALIDATION_ERROR","invalid_total_score",400);
    $fields[] = "total_score = ?";
    $params[] = (int)$body['total_score'];
  }

  if (array_key_exists('image_url', $body)) {
    $img = trim((string)$body['image_url']);
    $fields[] = "image_url = ?";
    $params[] = ($img === '' ? null : $img);
  }

  if (array_key_exists('diagnosed_at', $body)) {
    $dt = trim((string)$body['diagnosed_at']);
    if ($dt === '') json_err("VALIDATION_ERROR","invalid_diagnosed_at",400);
    $fields[] = "diagnosed_at = ?";
    $params[] = $dt;
  }

  if (!$fields) json_err("VALIDATION_ERROR", "nothing_to_update", 400);

  $params[] = (int)$id;

  $sql = "UPDATE diagnosis_history SET " . implode(", ", $fields) . " WHERE diagnosis_history_id = ?";
  $st = $dbh->prepare($sql);
  $st->execute($params);

  // ส่งข้อมูลล่าสุดกลับ
  $q = $dbh->prepare("
    SELECT
      dh.*,
      d.disease_th, d.disease_en,
      ot.tree_name,
      rl.level_code, rl.min_score, rl.days, rl.times
    FROM diagnosis_history dh
    JOIN diseases d ON d.disease_id = dh.disease_id
    JOIN orange_trees ot ON ot.tree_id = dh.tree_id
    LEFT JOIN disease_risk_levels rl ON rl.risk_level_id = dh.risk_level_id
    WHERE dh.diagnosis_history_id = ?
    LIMIT 1
  ");
  $q->execute([(int)$id]);

  json_ok($q->fetch() ?: ["updated" => true, "diagnosis_history_id" => (int)$id]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
