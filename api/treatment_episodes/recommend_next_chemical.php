<?php
// crud/api/treatment_episodes/recommend_next_chemical.php
// Recommend next chemical (different from the chemical used "this time") based on:
// - risk_level_moa_plan (order of MOA groups)
// - risk_level_moa_chemicals (allowed chemicals per MOA group)
// Compatible with schema in mydbtest2 (35).sql

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

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

function int_param($name, $default = 0) {
  if (!isset($_GET[$name])) return $default;
  $v = $_GET[$name];
  if (is_array($v)) return $default;
  return intval($v);
}

function str_param($name, $default = '') {
  if (!isset($_GET[$name])) return $default;
  $v = $_GET[$name];
  if (is_array($v)) return $default;
  return (string)$v;
}

// --- DB + auth ---
$pdo = null;
try {
  // file is under crud/api/treatment_episodes/
  if (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
  } elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
  }
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
  // allow explicit user_id param
}

if (!isset($pdo)) {
  json_response(false, null, 'Database connection ($pdo) not found.', 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(false, null, 'Method not allowed', 405);
}

$tree_id = int_param('tree_id', 0);
$disease_id = int_param('disease_id', 0);
$risk_level_id = int_param('risk_level_id', 0);
$diagnosis_history_id = int_param('diagnosis_history_id', 0);
$user_id = int_param('user_id', 0);
if ($user_id <= 0 && isset($AUTH_USER_ID)) $user_id = (int)$AUTH_USER_ID;

if ($risk_level_id <= 0) {
  json_response(false, null, 'risk_level_id is required', 400);
}

// ------------------------------------------------------------
// 0) Resolve disease_id + level_code from risk_level_id
//    (and apply special rule for Greening)
// ------------------------------------------------------------
$risk_level_disease_id = 0;
$risk_level_code = '';
try {
  $st = $pdo->prepare(
    "SELECT disease_id, level_code\n" .
    "FROM disease_risk_levels\n" .
    "WHERE risk_level_id = ?\n" .
    "LIMIT 1"
  );
  $st->execute([$risk_level_id]);
  $rl = $st->fetch(PDO::FETCH_ASSOC);
  if ($rl) {
    $risk_level_disease_id = (int)($rl['disease_id'] ?? 0);
    $risk_level_code = strtolower(trim((string)($rl['level_code'] ?? '')));
  }
} catch (Throwable $e) {
  // ignore
}

// If caller didn't send disease_id, infer from risk_level_id
if ($disease_id <= 0 && $risk_level_disease_id > 0) {
  $disease_id = $risk_level_disease_id;
}

// ✅ Business rule (Greening vector-control chemicals):
// - Greening (disease_id=5)
//   - medium: still recommend vector-control chemicals (normal flow)
//   - high ("รุนแรงมาก"): DO NOT recommend any chemical
$skip_recommendation = false;
$skip_reason = null;
$skip_message = null;
if ($disease_id === 5) {
  // treat "high" as "รุนแรงมาก"; also support possible future codes
  $no_recommend_codes = ['high', 'very_high', 'severe', 'very_severe', 'extreme'];
  if (in_array($risk_level_code, $no_recommend_codes, true)) {
    $skip_recommendation = true;
    $skip_reason = 'greening_severity_too_high';
    $skip_message = 'Greening (high severity): no chemical recommendation.';
  }
}

// -----------------------------
// -----------------------------
// 1) Find/create active episode
// -----------------------------
$episode_id = null;
$episode_row = null;
$current_group_id = 0;

try {
  if ($user_id > 0 && $tree_id > 0 && $disease_id > 0) {

    // Prefer the episode that matches diagnosis_history_id (if provided)
    if ($diagnosis_history_id > 0) {
      $st = $pdo->prepare(
        "SELECT * FROM treatment_episodes\n" .
        "WHERE user_id = ? AND tree_id = ? AND disease_id = ? AND status = 'active' AND diagnosis_history_id = ?\n" .
        "ORDER BY episode_id DESC LIMIT 1"
      );
      $st->execute([$user_id, $tree_id, $disease_id, $diagnosis_history_id]);
      $episode_row = $st->fetch(PDO::FETCH_ASSOC);
    }

    // Fallback: latest active episode for this tree+disease
    if (!$episode_row) {
      $st = $pdo->prepare(
        "SELECT * FROM treatment_episodes\n" .
        "WHERE user_id = ? AND tree_id = ? AND disease_id = ? AND status = 'active'\n" .
        "ORDER BY episode_id DESC LIMIT 1"
      );
      $st->execute([$user_id, $tree_id, $disease_id]);
      $episode_row = $st->fetch(PDO::FETCH_ASSOC);
    }

    if ($episode_row && isset($episode_row['episode_id'])) {
      $episode_id = (int)$episode_row['episode_id'];
      $current_group_id = (int)($episode_row['current_moa_group_id'] ?? 0);

      // keep risk_level_id aligned if missing/wrong
      if ((int)($episode_row['risk_level_id'] ?? 0) !== $risk_level_id) {
        $up = $pdo->prepare("UPDATE treatment_episodes SET risk_level_id = ? WHERE episode_id = ?");
        $up->execute([$risk_level_id, $episode_id]);
      }

      // fill diagnosis_history_id if missing/mismatch (when provided)
      if ($diagnosis_history_id > 0) {
        $ep_dh = (int)($episode_row['diagnosis_history_id'] ?? 0);
        if ($ep_dh <= 0 || $ep_dh !== $diagnosis_history_id) {
          $up = $pdo->prepare("UPDATE treatment_episodes SET diagnosis_history_id = ? WHERE episode_id = ?");
          $up->execute([$diagnosis_history_id, $episode_id]);
        }
      }

    } else {
      $ins = $pdo->prepare(
        "INSERT INTO treatment_episodes (user_id, tree_id, disease_id, risk_level_id, diagnosis_history_id, status)\n" .
        "VALUES (?, ?, ?, ?, ?, 'active')"
      );
      $ins->execute([$user_id, $tree_id, $disease_id, $risk_level_id, ($diagnosis_history_id > 0 ? $diagnosis_history_id : null)]);
      $episode_id = (int)$pdo->lastInsertId();
    }
  }
} catch (Throwable $e) {
  // don't hard fail; still can recommend without episode
  $episode_id = $episode_id ?? null;
}

// If Greening is at very high severity, do not recommend any chemical.
// (Still returns ok=true so the app can show other advice instead.)
if ($skip_recommendation) {
  json_response(true, [
    'episode_id' => $episode_id,
    'risk_level_id' => $risk_level_id,
    'excluded_chemical_id' => null,
    'chemical' => null,
    'moa_group' => null,
    'skip_recommendation' => true,
    'reason' => $skip_reason,
  ], $skip_message);
}

// --------------------------------------------
// 2) Determine the chemical to exclude (current)
// --------------------------------------------
$exclude_chemical_id = int_param('exclude_chemical_id', 0);
if ($exclude_chemical_id <= 0) $exclude_chemical_id = int_param('current_chemical_id', 0);

// fallback to current_chemical_id from episode
if ($exclude_chemical_id <= 0 && $episode_row && isset($episode_row['current_chemical_id'])) {
  $exclude_chemical_id = (int)$episode_row['current_chemical_id'];
}

// If not provided, use latest user_used_chemicals
if ($exclude_chemical_id <= 0 && $user_id > 0) {
  try {
    $st = $pdo->prepare(
      "SELECT chemical_id\n" .
      "FROM user_used_chemicals\n" .
      "WHERE user_id = ?\n" .
      "ORDER BY last_used_at DESC, user_used_chemical_id DESC\n" .
      "LIMIT 1"
    );
    $st->execute([$user_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['chemical_id'])) $exclude_chemical_id = (int)$row['chemical_id'];
  } catch (Throwable $e) {
    // ignore
  }
}

// Fallback: if chemicals were still stored in user_orchard_answers (question_id=68 numeric_value=chemical_id)
if ($exclude_chemical_id <= 0 && $user_id > 0) {
  try {
    $st = $pdo->prepare(
      "SELECT numeric_value\n" .
      "FROM user_orchard_answers\n" .
      "WHERE user_id = ? AND question_id = 68 AND numeric_value IS NOT NULL\n" .
      "ORDER BY updated_at DESC, id DESC\n" .
      "LIMIT 1"
    );
    $st->execute([$user_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['numeric_value'])) $exclude_chemical_id = (int)floatval($row['numeric_value']);
  } catch (Throwable $e) {
    // ignore
  }
}

// -------------------------------------------------------
// 3) Determine current MOA group (to start next in rotation)
// -------------------------------------------------------
$current_group_id = int_param('current_moa_group_id', 0);
if ($current_group_id <= 0 && $exclude_chemical_id > 0) {
  try {
    $st = $pdo->prepare("SELECT moa_group_id FROM chemicals WHERE chemical_id = ? LIMIT 1");
    $st->execute([$exclude_chemical_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && isset($r['moa_group_id'])) $current_group_id = (int)$r['moa_group_id'];
  } catch (Throwable $e) {
    // ignore
  }
}

if ($current_group_id <= 0 && $episode_row && isset($episode_row['current_moa_group_id'])) {
  $current_group_id = (int)($episode_row['current_moa_group_id'] ?? 0);
}

// -------------------------------------------------
// 4) Build excluded list (explicit user exclusions)
// -------------------------------------------------
$explicit_excluded = [];
if ($user_id > 0) {
  try {
    $st = $pdo->prepare(
      "SELECT chemical_id, exclude_from_recommendation, source\n" .
      "FROM user_used_chemicals\n" .
      "WHERE user_id = ?"
    );
    $st->execute([$user_id]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $hasExplicit = false;
    foreach ($rows as $r) {
      $src = strtolower(trim((string)($r['source'] ?? '')));
      if ($src !== '' && strpos($src, 'exclude') !== false) {
        $hasExplicit = true;
        break;
      }
    }

    if ($hasExplicit) {
      foreach ($rows as $r) {
        $src = strtolower(trim((string)($r['source'] ?? '')));
        $flag = (int)($r['exclude_from_recommendation'] ?? 0);
        if ($flag === 1 && $src !== '' && strpos($src, 'exclude') !== false) {
          $cid = (int)($r['chemical_id'] ?? 0);
          if ($cid > 0) $explicit_excluded[$cid] = true;
        }
      }
    }
  } catch (Throwable $e) {
    // ignore
  }
}

$excluded_ids = [];
if ($exclude_chemical_id > 0) $excluded_ids[$exclude_chemical_id] = true;
foreach ($explicit_excluded as $cid => $_) $excluded_ids[(int)$cid] = true;

// ------------------------------
// 5) Load MOA group plan in order
// ------------------------------
$plan_groups = [];
try {
  $st = $pdo->prepare(
    "SELECT p.moa_group_id, p.priority, mg.moa_code, mg.group_name\n" .
    "FROM risk_level_moa_plan p\n" .
    "LEFT JOIN moa_groups mg ON mg.moa_group_id = p.moa_group_id\n" .
    "WHERE p.risk_level_id = ?\n" .
    "ORDER BY p.priority ASC, p.plan_id ASC"
  );
  $st->execute([$risk_level_id]);
  $plan_groups = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // ignore, fallback below
}

// Fallback to groups from allowed chemicals
if (count($plan_groups) === 0) {
  try {
    $st = $pdo->prepare(
      "SELECT DISTINCT rmc.moa_group_id, 9999 AS priority, mg.moa_code, mg.group_name\n" .
      "FROM risk_level_moa_chemicals rmc\n" .
      "LEFT JOIN moa_groups mg ON mg.moa_group_id = rmc.moa_group_id\n" .
      "WHERE rmc.risk_level_id = ?\n" .
      "ORDER BY rmc.moa_group_id ASC"
    );
    $st->execute([$risk_level_id]);
    $plan_groups = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    // ignore
  }
}

if (count($plan_groups) === 0) {
  json_response(false, null, 'No MOA plan groups found for this risk_level_id', 404);
}

// Compute ordered group ids, starting AFTER current_group_id (wrap around)
$group_ids = array_map(fn($g) => (int)$g['moa_group_id'], $plan_groups);
$start_idx = 0;
if ($current_group_id > 0) {
  $idx = array_search($current_group_id, $group_ids, true);
  if ($idx !== false) $start_idx = ((int)$idx + 1) % count($group_ids);
}

$ordered_group_ids = [];
for ($i = 0; $i < count($group_ids); $i++) {
  $ordered_group_ids[] = $group_ids[($start_idx + $i) % count($group_ids)];
}

// --------------------------------------
// 6) Pick a chemical from ordered groups
// --------------------------------------
function pick_candidate($pdo, $risk_level_id, $ordered_group_ids, $excluded_ids) {
  foreach ($ordered_group_ids as $gid) {
    $st = $pdo->prepare(
      "SELECT c.chemical_id, c.trade_name, c.active_ingredient, c.target_type, c.moa_group_id, c.notes, c.is_active,\n" .
      "       mg.moa_code, mg.group_name\n" .
      "FROM risk_level_moa_chemicals rmc\n" .
      "JOIN chemicals c ON c.chemical_id = rmc.chemical_id\n" .
      "LEFT JOIN moa_groups mg ON mg.moa_group_id = c.moa_group_id\n" .
      "WHERE rmc.risk_level_id = ? AND rmc.moa_group_id = ?\n" .
      "ORDER BY rmc.priority ASC, rmc.id ASC"
    );
    $st->execute([$risk_level_id, $gid]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $cid = (int)$row['chemical_id'];
      $is_active = isset($row['is_active']) ? (int)$row['is_active'] : 1;
      if ($is_active !== 1) continue;
      if (isset($excluded_ids[$cid])) continue;
      return $row;
    }
  }
  return null;
}

$chemical_row = null;
try {
  $chemical_row = pick_candidate($pdo, $risk_level_id, $ordered_group_ids, $excluded_ids);

  // Fallback: ignore explicit excludes (keep only current chemical exclude)
  if (!$chemical_row && $exclude_chemical_id > 0) {
    $fallback_excluded = [$exclude_chemical_id => true];
    $chemical_row = pick_candidate($pdo, $risk_level_id, $ordered_group_ids, $fallback_excluded);
  }

  // Final fallback: first available even if same as exclude (return but mark message)
  if (!$chemical_row) {
    $chemical_row = pick_candidate($pdo, $risk_level_id, $ordered_group_ids, []);
  }
} catch (Throwable $e) {
  json_response(false, null, 'Failed to recommend chemical: ' . $e->getMessage(), 500);
}

if (!$chemical_row) {
  json_response(false, null, 'No chemical candidate found', 404);
}

$chem = [
  'chemical_id' => (int)$chemical_row['chemical_id'],
  // ✅ Flutter treatment_advice_page.dart prefers chemical_name
  'chemical_name' => (string)($chemical_row['trade_name'] ?? ''),
  'trade_name' => (string)($chemical_row['trade_name'] ?? ''),
  'active_ingredient' => $chemical_row['active_ingredient'] ?? null,
  'target_type' => $chemical_row['target_type'] ?? null,
  'moa_group_id' => isset($chemical_row['moa_group_id']) ? (int)$chemical_row['moa_group_id'] : null,
  'notes' => $chemical_row['notes'] ?? null,
];

$mg = [
  'moa_group_id' => isset($chemical_row['moa_group_id']) ? (int)$chemical_row['moa_group_id'] : null,
  'moa_code' => (string)($chemical_row['moa_code'] ?? ''),
  'group_name' => (string)($chemical_row['group_name'] ?? ''),
];

json_response(true, [
  'episode_id' => $episode_id,
  'risk_level_id' => $risk_level_id,
  'excluded_chemical_id' => $exclude_chemical_id > 0 ? $exclude_chemical_id : null,
  'chemical' => $chem,
  'moa_group' => $mg,
]);
