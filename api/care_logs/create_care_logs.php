<?php
// api/care_logs/create_care_logs.php (patched)
require_once __DIR__ . '/../db.php';

// ----------------------------
// Auth (รองรับทั้งแบบ return array และแบบ global $AUTH_USER_*)
// ----------------------------
$authFile = __DIR__ . '/../auth/require_auth.php';
$authUser = null;
if (file_exists($authFile)) {
  $tmp = require $authFile;
  if (is_array($tmp)) $authUser = $tmp;
}
if (function_exists('require_auth')) { require_auth(); }

$currentUserId = 0;
$currentUserRole = 'user';
if (isset($AUTH_USER_ID)) $currentUserId = (int)$AUTH_USER_ID;
if (isset($AUTH_USER_ROLE)) $currentUserRole = (string)$AUTH_USER_ROLE;
if (is_array($authUser) && !empty($authUser['id'])) {
  $currentUserId = (int)$authUser['id'];
  if (!empty($authUser['role'])) $currentUserRole = (string)$authUser['role'];
}
if ($currentUserId <= 0 && isset($_SESSION['user_id'])) {
  $currentUserId = (int)$_SESSION['user_id'];
}

if ($currentUserId <= 0) {
  json_err('UNAUTHORIZED', 'unauthorized', 401);
}
$isAdmin = in_array($currentUserRole, ['admin', 'super_admin'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err('METHOD_NOT_ALLOWED', 'post_only', 405);
}

$DEBUG = (getenv('APP_DEBUG') === '1' || getenv('DEBUG') === '1');
$dbg = function ($msg) use ($DEBUG) { if ($DEBUG) error_log($msg); };

// ----------------------------
// Helpers
// ----------------------------
$read_body = function () {
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) $body = [];
  return $body;
};

$int_or_null = function ($v, $field) {
  if ($v === null || $v === '') return null;
  if (!ctype_digit((string)$v)) json_err('VALIDATION_ERROR', "invalid_{$field}", 422);
  return (int)$v;
};

$enum_or_null = function ($v, $field, array $allowed) {
  if ($v === null || $v === '') return null;
  $v = strtoupper(trim((string)$v));
  if (!in_array($v, $allowed, true)) json_err('VALIDATION_ERROR', "invalid_{$field}", 422);
  return $v;
};

$str_or_null = function ($v, $field, $maxLen) {
  if ($v === null) return null;
  $v = trim((string)$v);
  if ($v === '') return null;
  if (mb_strlen($v, 'UTF-8') > $maxLen) json_err('VALIDATION_ERROR', "{$field}_too_long", 422);
  return $v;
};

$normalize_bool = function ($v) {
  return ($v == 1 || $v === true || $v === '1') ? 1 : 0;
};

$normalize_care_type_db = function ($careType) {
  $careType = strtolower(trim((string)$careType));
  if ($careType === 'spray') $careType = 'pesticide';
  return $careType;
};

$care_type_for_client = function ($careTypeDb) {
  // backward-compat: ฝั่งแอปเดิมใช้คำว่า spray
  if ($careTypeDb === 'pesticide') return 'spray';
  return $careTypeDb;
};

try {
  $body = $read_body();

  $treeId = (int)($body['tree_id'] ?? 0);
  $careTypeRaw = (string)($body['care_type'] ?? '');
  $careTypeDb = $normalize_care_type_db($careTypeRaw);
  $careDateRaw = trim((string)($body['care_date'] ?? ''));

  $diagnosis_history_id = $int_or_null($body['diagnosis_history_id'] ?? null, 'diagnosis_history_id');
  $chemical_id = $int_or_null($body['chemical_id'] ?? null, 'chemical_id');

  $moa_system = $enum_or_null($body['moa_system'] ?? null, 'moa_system', ['FRAC','IRAC']);
  $moa_group_code = $str_or_null($body['moa_group_code'] ?? null, 'moa_group_code', 10);
  $moa_member_no = $int_or_null($body['moa_member_no'] ?? null, 'moa_member_no');

  $isReminder = $normalize_bool($body['is_reminder'] ?? 0);
  $isDone = $normalize_bool($body['is_done'] ?? 0);
  $note = trim((string)($body['note'] ?? ''));

  /* =============== validation =============== */
  if ($treeId <= 0) {
    json_err('VALIDATION_ERROR', 'invalid_tree_id', 422);
  }

  // ✅ กันยิง tree คนอื่น
  if (!$isAdmin) {
    $st = $dbh->prepare('SELECT 1 FROM orange_trees WHERE tree_id=? AND user_id=?');
    $st->execute([$treeId, $currentUserId]);
    if (!$st->fetchColumn()) {
      json_err('FORBIDDEN', 'tree_not_belong_to_user', 403);
    }
  }

  // care_type รองรับตาม enum ใน DB (+ spray เป็น alias ของ pesticide)
  $allowedDbTypes = ['fertilizer','pesticide','watering','pruning','other'];
  if ($careTypeDb === '' || !in_array($careTypeDb, $allowedDbTypes, true)) {
    json_err('VALIDATION_ERROR', "care_type_invalid", 422);
  }

  if ($careDateRaw === '') {
    json_err('VALIDATION_ERROR', 'invalid_care_date', 422);
  }
  $ts = strtotime($careDateRaw);
  if ($ts === false) {
    json_err('VALIDATION_ERROR', 'invalid_care_date_format', 422);
  }
  $careDate = date('Y-m-d', $ts);

  // ✅ ตรวจ diagnosis_history_id ถ้ามี (ต้องเป็นของ user และ tree ตรงกัน)
  if ($diagnosis_history_id !== null) {
    $st = $dbh->prepare('SELECT user_id, tree_id FROM diagnosis_history WHERE diagnosis_history_id=?');
    $st->execute([$diagnosis_history_id]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (!$h) json_err('VALIDATION_ERROR', 'diagnosis_history_not_found', 422);
    if (!$isAdmin && (int)$h['user_id'] !== $currentUserId) {
      json_err('FORBIDDEN', 'history_not_belong_to_user', 403);
    }
    if ((int)$h['tree_id'] !== $treeId) {
      json_err('VALIDATION_ERROR', 'history_tree_id_mismatch', 422);
    }
  }

  // ✅ ตรวจ chemical_id ถ้ามี (ต้องเป็นของ user ยกเว้น admin) + snapshot MOA
  $chemicalProductName = null;
  if ($chemical_id !== null) {
    $st = $dbh->prepare('SELECT user_id, product_name, moa_system, moa_group_code, moa_member_no FROM user_chemicals WHERE chemical_id=?');
    $st->execute([$chemical_id]);
    $chem = $st->fetch(PDO::FETCH_ASSOC);
    if (!$chem) json_err('VALIDATION_ERROR', 'chemical_not_found', 422);
    if (!$isAdmin && (int)$chem['user_id'] !== $currentUserId) {
      json_err('FORBIDDEN', 'chemical_not_belong_to_user', 403);
    }

    $chemicalProductName = $chem['product_name'] ?? null;

    // snapshot MOA from chemical if present
    if (!empty($chem['moa_system'])) $moa_system = $chem['moa_system'];
    if (!empty($chem['moa_group_code'])) $moa_group_code = $chem['moa_group_code'];
    if ($chem['moa_member_no'] !== null) $moa_member_no = (int)$chem['moa_member_no'];
  }

  // ----------------------------
  // Parse note -> product_name, amount, unit, area (คง logic เดิม)
  // ----------------------------
  $productName = null;
  $amount = null;
  $unit = null;
  $area = null;

  if ($note !== '') {
    $lines = explode("\n", $note);
    $cleanNote = [];

    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') continue;

      if (preg_match('/^พื้นที่ที่ใส่ปุ๋ย\s*:?.*$/u', $line)) {
        if (preg_match('/^พื้นที่ที่ใส่ปุ๋ย\s*:?.*\s*(.+)$/u', $line, $m)) $area = trim($m[1]);
      } elseif (preg_match('/^ปริมาณปุ๋ยที่ใช้\s*:?.*$/u', $line)) {
        if (preg_match('/^ปริมาณปุ๋ยที่ใช้\s*:?.*\s*(.+)$/u', $line, $m)) {
          $amountText = trim($m[1]);
          if (preg_match('/^([\d.]+)\s*(.*)$/u', $amountText, $m2)) {
            $amount = (float)$m2[1];
            $unit = trim($m2[2]);
          } else {
            $amount = $amountText;
          }
        }
      } elseif (preg_match('/^ปริมาณยาที่ใช้\s*:?.*$/u', $line)) {
        if (preg_match('/^ปริมาณยาที่ใช้\s*:?.*\s*(.+)$/u', $line, $m)) {
          $amountText = trim($m[1]);
          if (preg_match('/^([\d.]+)\s*(.*)$/u', $amountText, $m2)) {
            $amount = (float)$m2[1];
            $unit = trim($m2[2]);
          } else {
            $amount = $amountText;
          }
        }
      } elseif (preg_match('/^จำนวนต้นที่พ่น\s*:?.*$/u', $line)) {
        if (preg_match('/^จำนวนต้นที่พ่น\s*:?.*\s*(.+)$/u', $line, $m)) $area = trim($m[1]);
      } else {
        $cleanNote[] = $line;
      }
    }

    if (!empty($cleanNote)) {
      $productName = implode("\n", $cleanNote);
    }
  }

  // ถ้ามี chemical แต่ยังไม่มี productName ให้ใช้ชื่อผลิตภัณฑ์จาก chemical เป็นค่าเริ่มต้น
  if ($productName === null && $chemicalProductName) {
    $productName = $chemicalProductName;
  }

  // ----------------------------
  // INSERT
  // ----------------------------
  $sql = "
    INSERT INTO care_logs
      (user_id, tree_id,
       diagnosis_history_id,
       chemical_id, moa_system, moa_group_code, moa_member_no,
       care_type, care_date, is_reminder, is_done,
       product_name, amount, unit, area, note)
    VALUES
      (:user_id, :tree_id,
       :diagnosis_history_id,
       :chemical_id, :moa_system, :moa_group_code, :moa_member_no,
       :care_type, :care_date, :is_reminder, :is_done,
       :product_name, :amount, :unit, :area, :note)
  ";

  $stmt = $dbh->prepare($sql);
  $stmt->execute([
    ':user_id' => $currentUserId,
    ':tree_id' => $treeId,
    ':diagnosis_history_id' => $diagnosis_history_id,
    ':chemical_id' => $chemical_id,
    ':moa_system' => $moa_system,
    ':moa_group_code' => $moa_group_code,
    ':moa_member_no' => $moa_member_no,
    ':care_type' => $careTypeDb,
    ':care_date' => $careDate,
    ':is_reminder' => $isReminder,
    ':is_done' => $isDone,
    ':product_name' => $productName,
    ':amount' => ($amount === '' ? null : $amount),
    ':unit' => ($unit === '' ? null : $unit),
    ':area' => ($area === '' ? null : $area),
    ':note' => ($note === '' ? null : $note),
  ]);

  $newId = (int)$dbh->lastInsertId();

  // ส่งกลับเป็นแถวเต็ม (สม่ำเสมอกับ read/search)
  $st = $dbh->prepare("SELECT * FROM care_logs WHERE log_id=?");
  $st->execute([$newId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $rawFlag = isset($_GET['raw']) && $_GET['raw'] === '1';
    if (!$rawFlag) $row['care_type'] = $care_type_for_client($row['care_type']);
  }
  json_ok($row ?: ['log_id' => $newId]);

} catch (Throwable $e) {
  $dbg('CREATE_CARE_LOGS_ERROR: ' . $e->getMessage());
  json_err('DB_ERROR', 'db_error', 500);
}
