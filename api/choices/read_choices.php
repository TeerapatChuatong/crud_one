<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/require_auth.php';

// กันกรณี require_auth.php ไม่มี json_ok/json_err
if (!function_exists('json_ok')) {
  function json_ok($data = []) {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('json_err')) {
  function json_err($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

function base_url() {
  $https = $_SERVER['HTTPS'] ?? '';
  $scheme = (!empty($https) && strtolower($https) !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

  // script: /crud/api/choices/read_choices.php -> base: /crud
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $basePath = rtrim(dirname(dirname($script)), '/'); // /crud/api
  if (substr($basePath, -4) === '/api') $basePath = substr($basePath, 0, -4); // /crud

  return $scheme . '://' . $host . $basePath;
}

function to_public_url($url) {
  if (!is_string($url) || $url === '') return $url;
  if (preg_match('#^https?://#i', $url)) return $url;

  $https = $_SERVER['HTTPS'] ?? '';
  $scheme = (!empty($https) && strtolower($https) !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

  if (substr($url, 0, 1) === '/') {
    return $scheme . '://' . $host . $url;
  }
  return rtrim(base_url(), '/') . '/' . ltrim($url, '/');
}

function decorate_choice_row($row) {
  if (!is_array($row)) return $row;

  // ✅ ให้ frontend ใช้ได้ทั้ง choices_text และ choice_text
  if (!array_key_exists('choices_text', $row)) {
    $row['choices_text'] = $row['choice_text'] ?? null;
  } elseif (($row['choices_text'] === '' || $row['choices_text'] === null) && isset($row['choice_text'])) {
    $row['choices_text'] = $row['choice_text'];
  }

  // ✅ ทำ image_url เป็น URL เต็ม เพื่อให้ React แสดงรูปได้ทันที
  if (!empty($row['image_url']) && is_string($row['image_url'])) {
    $row['image_url'] = to_public_url($row['image_url']);
  }
  if (!array_key_exists('imageUrl', $row)) {
    $row['imageUrl'] = $row['image_url'] ?? null;
  }

  return $row;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

// บังคับล็อกอิน (ตามของเดิม)
$session_uid = (int)($_SESSION["user_id"] ?? 0);
if ($session_uid <= 0) json_err("UNAUTHORIZED", "Please login", 401);

$choice_id           = $_GET['choice_id'] ?? null;
$question_id         = $_GET['question_id'] ?? null;
$disease_question_id = $_GET['disease_question_id'] ?? null;

try {

  // ถ้าเรียกด้วย disease_question_id -> คืน choices + score_value
  if ($disease_question_id !== null && $disease_question_id !== '') {
    if (!ctype_digit((string)$disease_question_id)) {
      json_err("VALIDATION_ERROR","invalid_disease_question_id",400);
    }

    $st = $dbh->prepare("
      SELECT
        dq.disease_question_id,
        dq.question_id,
        c.*,
        COALESCE(s.score_value, 0) AS score_value
      FROM disease_questions dq
      INNER JOIN choices c
        ON c.question_id = dq.question_id
      LEFT JOIN scores s
        ON s.disease_question_id = dq.disease_question_id
       AND s.choice_id = c.choice_id
      WHERE dq.disease_question_id = ?
      ORDER BY c.sort_order ASC, c.choice_id ASC
    ");
    $st->execute([(int)$disease_question_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_map('decorate_choice_row', $rows);
    json_ok($rows);
  }

  // อ่าน choice เดี่ยว
  if ($choice_id !== null && $choice_id !== '') {
    if (!ctype_digit((string)$choice_id)) {
      json_err("VALIDATION_ERROR","invalid_choice_id",400);
    }
    $st = $dbh->prepare("SELECT * FROM choices WHERE choice_id=?");
    $st->execute([(int)$choice_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err("NOT_FOUND","not_found",404);
    $row = decorate_choice_row($row);
    json_ok($row);

  // อ่าน choices ตาม question_id
  } elseif ($question_id !== null && $question_id !== '') {
    if (!ctype_digit((string)$question_id)) {
      json_err("VALIDATION_ERROR","invalid_question_id",400);
    }
    $st = $dbh->prepare("
      SELECT * FROM choices
      WHERE question_id=?
      ORDER BY sort_order ASC, choice_id ASC
    ");
    $st->execute([(int)$question_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_map('decorate_choice_row', $rows);
    json_ok($rows);

  } else {
    $st = $dbh->query("
      SELECT * FROM choices
      ORDER BY question_id ASC, sort_order ASC, choice_id ASC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_map('decorate_choice_row', $rows);
    json_ok($rows);
  }

} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
