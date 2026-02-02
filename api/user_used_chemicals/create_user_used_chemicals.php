<?php
// CRUD/api/user_used_chemicals/create_user_used_chemicals.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

$authFile = __DIR__ . '/../auth/require_auth.php';
if (file_exists($authFile)) require_once $authFile;
if (function_exists('require_auth')) { require_auth(); }
if (function_exists('require_login')) { require_login(); }

// fallback helpers
if (!function_exists('json_ok')) {
  function json_ok($data = [], $message = 'ok') {
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('json_err')) {
  function json_err($code = 'ERROR', $message = 'error', $http = 400, $extra = []) {
    http_response_code($http);
    echo json_encode(['success' => false, 'code' => $code, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];
if (empty($input) && !empty($_POST)) $input = $_POST;

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$is_admin = function_exists('is_admin') ? is_admin() : false;

$user_id = $currentUserId;
if ($is_admin && isset($input['user_id'])) {
  $user_id = (int)$input['user_id'];
}
if ($user_id <= 0) json_err('UNAUTHORIZED', 'login_required', 401);

$chemical_id = isset($input['chemical_id']) ? (int)$input['chemical_id'] : 0;
if ($chemical_id <= 0) json_err('VALIDATION_ERROR', 'chemical_id_required', 400);

$source = isset($input['source']) ? trim((string)$input['source']) : null;
if ($source === '') $source = null;
if ($source !== null && mb_strlen($source, 'UTF-8') > 50) {
  $source = mb_substr($source, 0, 50, 'UTF-8');
}

$excludeProvided = array_key_exists('exclude_from_recommendation', $input);
$exclude_from_recommendation = null;
if ($excludeProvided) {
  $exclude_from_recommendation = (int)$input['exclude_from_recommendation'];
  $exclude_from_recommendation = ($exclude_from_recommendation === 1) ? 1 : 0;
}

try {
  if (!isset($dbh)) throw new Exception('DB connection not found');

  // validate chemical exists
  $st = $dbh->prepare("SELECT chemical_id FROM chemicals WHERE chemical_id=? LIMIT 1");
  $st->execute([$chemical_id]);
  if (!$st->fetchColumn()) json_err('NOT_FOUND', 'chemical_not_found', 404);

  // NOTE:
  // - แถวนี้ใช้เก็บ "ประวัติสารที่ผู้ใช้เคยใช้" (ไม่ใช่ event รายครั้ง)
  // - ถ้าไม่ส่ง exclude_from_recommendation จะ "ไม่เปลี่ยน" ค่าเดิมเมื่อมีอยู่แล้ว
  if ($excludeProvided) {
    $sql = "
      INSERT INTO user_used_chemicals
        (user_id, chemical_id, exclude_from_recommendation, source, first_used_at, last_used_at, times_used)
      VALUES
        (:user_id, :chemical_id, :ex, :source, NOW(), NOW(), 1)
      ON DUPLICATE KEY UPDATE
        times_used = times_used + 1,
        last_used_at = NOW(),
        source = COALESCE(VALUES(source), source),
        exclude_from_recommendation = :ex
    ";
    $params = [
      ':user_id' => $user_id,
      ':chemical_id' => $chemical_id,
      ':ex' => $exclude_from_recommendation,
      ':source' => $source,
    ];
  } else {
    $sql = "
      INSERT INTO user_used_chemicals
        (user_id, chemical_id, exclude_from_recommendation, source, first_used_at, last_used_at, times_used)
      VALUES
        (:user_id, :chemical_id, 0, :source, NOW(), NOW(), 1)
      ON DUPLICATE KEY UPDATE
        times_used = times_used + 1,
        last_used_at = NOW(),
        source = COALESCE(VALUES(source), source)
        -- ❗ไม่แตะ exclude_from_recommendation
    ";
    $params = [
      ':user_id' => $user_id,
      ':chemical_id' => $chemical_id,
      ':source' => $source,
    ];
  }

  $stmt = $dbh->prepare($sql);
  $stmt->execute($params);

  $st = $dbh->prepare("
    SELECT uuc.*,
           c.trade_name AS chemical_name
    FROM user_used_chemicals uuc
    JOIN chemicals c ON c.chemical_id = uuc.chemical_id
    WHERE uuc.user_id=? AND uuc.chemical_id=?
    LIMIT 1
  ");
  $st->execute([$user_id, $chemical_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  json_ok($row, 'saved');

} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500, ['error' => $e->getMessage()]);
}
