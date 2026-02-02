<?php
// crud/api/treatment_advice_mappings/resolve_treatment_advice_text.php
// Resolve placeholders in treatment advice text using the user's orchard-management answers.
// - Label (สิ่งที่ผู้ใช้เลือก) ใช้ choices.choice_label
// - Advice (คำแนะนำต่อคำตอบ) ใช้ choices.choice_text
// รองรับกรณี user_orchard_answers.answer_text เก็บเป็น JSON เช่น ["302"]

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  echo json_encode(['ok' => true]);
  exit;
}

function json_response($ok, $data = null, $message = null, $http = 200) {
  http_response_code($http);
  $out = ['ok' => (bool)$ok];
  if ($message !== null) $out['message'] = $message;
  if ($data !== null) $out['data'] = $data;
  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}

function get_json_input() {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function first_int_from_maybe_json($val) {
  if ($val === null) return null;
  if (is_int($val)) return $val;
  $s = trim((string)$val);
  if ($s === '') return null;

  // JSON array/string
  if ($s[0] === '[' || $s[0] === '"') {
    $j = json_decode($s, true);
    if (is_array($j) && count($j) > 0) {
      $first = $j[0];
      if (is_int($first)) return $first;
      $t = trim((string)$first);
      if ($t !== '' && ctype_digit($t)) return intval($t);
    }
    if (is_string($j)) {
      $t = trim($j);
      if ($t !== '' && ctype_digit($t)) return intval($t);
    }
  }

  // Plain digits
  if (ctype_digit($s)) return intval($s);

  // Extract first number
  if (preg_match('/(\d+)/', $s, $m)) return intval($m[1]);

  return null;
}

// --- DB + auth ---
$pdo = null;
try {
  // file is under crud/api/treatment_advice_mappings/
  require_once __DIR__ . '/../db.php';
  // Some projects use health_db.php
  if (!isset($pdo) && file_exists(__DIR__ . '/../health_db.php')) {
    require_once __DIR__ . '/../health_db.php';
  }
} catch (Throwable $e) {
  json_response(false, null, 'DB include failed: ' . $e->getMessage(), 500);
}

// Optional auth gate
try {
  $auth1 = __DIR__ . '/../auth/require_auth.php';
  if (file_exists($auth1)) require_once $auth1;
} catch (Throwable $e) {
  // ignore (allow explicit user_id in request)
}

if (!isset($pdo)) {
  json_response(false, null, 'Database connection ($pdo) not found.', 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(false, null, 'Method not allowed', 405);
}

$in = get_json_input();
$user_id = isset($in['user_id']) ? intval($in['user_id']) : 0;
$advice_text = isset($in['advice_text']) ? (string)$in['advice_text'] : '';

if ($user_id <= 0) {
  json_response(false, null, 'user_id is required', 400);
}

if (trim($advice_text) === '') {
  json_response(true, ['resolved_text' => $advice_text]);
}

// ✅ Question IDs of orchard-management (disease_id=7) in current DB
// - 64: วิธีการให้น้ำ
// - 70: ความถี่ในการจัดทรงต้น
// - 71: ความถี่ในการตัดแต่งและกำจัดซาก
$water_qid  = isset($in['water_question_id'])  ? intval($in['water_question_id'])  : 64;
$canopy_qid = isset($in['canopy_question_id']) ? intval($in['canopy_question_id']) : 70;
$debris_qid = isset($in['debris_question_id']) ? intval($in['debris_question_id']) : 71;

$qids = array_values(array_unique(array_filter([$water_qid, $canopy_qid, $debris_qid])));

// --- Read latest answers per question ---
$answers_by_qid = [];
try {
  $ph = implode(',', array_fill(0, count($qids), '?'));
  $sql = "SELECT id, question_id, choice_id, answer_text, numeric_value, updated_at
          FROM user_orchard_answers
          WHERE user_id = ? AND question_id IN ($ph)
          ORDER BY updated_at DESC, id DESC";
  $st = $pdo->prepare($sql);
  $params = array_merge([$user_id], $qids);
  $st->execute($params);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $qid = intval($row['question_id']);
    if (!isset($answers_by_qid[$qid])) {
      $answers_by_qid[$qid] = $row; // first row per qid due to ORDER
    }
  }
} catch (Throwable $e) {
  json_response(false, null, 'Query user_orchard_answers failed: ' . $e->getMessage(), 500);
}

// --- Determine choice_ids needed (answer_text may store ["302"]) ---
$choice_ids = [];
foreach ($answers_by_qid as $qid => $row) {
  $cid = isset($row['choice_id']) ? intval($row['choice_id']) : 0;
  if ($cid <= 0) {
    $cid = first_int_from_maybe_json($row['answer_text'] ?? null) ?? 0;
  }
  if ($cid > 0) $choice_ids[] = $cid;
}
$choice_ids = array_values(array_unique($choice_ids));

// --- Load choices (label + advice) ---
$choices_map = []; // choice_id => ['label'=>..., 'advice'=>...]
if (count($choice_ids) > 0) {
  try {
    $ph = implode(',', array_fill(0, count($choice_ids), '?'));
    $sql = "SELECT choice_id, choice_label, choice_text FROM choices WHERE choice_id IN ($ph)";
    $st = $pdo->prepare($sql);
    $st->execute($choice_ids);
    while ($c = $st->fetch(PDO::FETCH_ASSOC)) {
      $id = intval($c['choice_id']);
      $choices_map[$id] = [
        'label'  => (string)($c['choice_label'] ?? ''),
        'advice' => (string)($c['choice_text'] ?? ''),
      ];
    }
  } catch (Throwable $e) {
    // If choices schema differs, fail soft (still can return original text)
    $choices_map = [];
  }
}

function pick_label_and_advice($row, $choices_map) {
  $raw_text = isset($row['answer_text']) ? trim((string)$row['answer_text']) : '';
  $cid = isset($row['choice_id']) ? intval($row['choice_id']) : 0;
  if ($cid <= 0) $cid = first_int_from_maybe_json($raw_text) ?? 0;

  if ($cid > 0 && isset($choices_map[$cid])) {
    $label = trim((string)$choices_map[$cid]['label']);
    $advice = trim((string)$choices_map[$cid]['advice']);
    return [$label !== '' ? $label : $raw_text, $advice];
  }

  // Fallback for free-text answers
  return [$raw_text, ''];
}

$water_label = '';
$water_advice = '';
$canopy_label = '';
$canopy_advice = '';
$debris_label = '';
$debris_advice = '';

if (isset($answers_by_qid[$water_qid])) {
  [$water_label, $water_advice] = pick_label_and_advice($answers_by_qid[$water_qid], $choices_map);
}
if (isset($answers_by_qid[$canopy_qid])) {
  [$canopy_label, $canopy_advice] = pick_label_and_advice($answers_by_qid[$canopy_qid], $choices_map);
}
if (isset($answers_by_qid[$debris_qid])) {
  [$debris_label, $debris_advice] = pick_label_and_advice($answers_by_qid[$debris_qid], $choices_map);
}

// --- Replace placeholders ---
$replacements = [
  // Labels
  '{วิธีให้น้ำ}' => $water_label,
  '{วิธีการให้น้ำ}' => $water_label,
  '{water_method}' => $water_label,

  '{ความถี่จัดการทรงพุ่ม}' => $canopy_label,
  '{ความถี่จัดการทรงต้น}' => $canopy_label,
  '{canopy_freq}' => $canopy_label,

  '{ความถี่กำจัดเศษซาก}' => $debris_label,
  '{ความถี่ตัดแต่ง/กำจัดเศษซาก}' => $debris_label,
  '{debris_freq}' => $debris_label,

  // Advices
  '{คำแนะนำการให้น้ำ}' => $water_advice,
  '{water_advice}' => $water_advice,

  '{คำแนะนำทรงพุ่ม}' => $canopy_advice,
  '{คำแนะนำการจัดทรงต้น}' => $canopy_advice,
  '{canopy_advice}' => $canopy_advice,

  '{คำแนะนำกำจัดเศษซาก}' => $debris_advice,
  '{คำแนะนำการกำจัดเศษซาก}' => $debris_advice,
  '{debris_advice}' => $debris_advice,
];

$resolved = $advice_text;
foreach ($replacements as $k => $v) {
  if ($v === null) $v = '';
  $resolved = str_replace($k, (string)$v, $resolved);
}

json_response(true, ['resolved_text' => $resolved, 'slots' => [
  'water' => ['question_id' => $water_qid, 'label' => $water_label, 'advice' => $water_advice],
  'canopy' => ['question_id' => $canopy_qid, 'label' => $canopy_label, 'advice' => $canopy_advice],
  'debris' => ['question_id' => $debris_qid, 'label' => $debris_label, 'advice' => $debris_advice],
]]);
