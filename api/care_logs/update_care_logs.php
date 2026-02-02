<?php
// api/care_logs/update_care_logs.php (patched v2)
require_once __DIR__ . '/../db.php';

// Auth (รองรับทั้งแบบ return array และแบบ global $AUTH_USER_*)
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
if ($currentUserId <= 0) json_err('UNAUTHORIZED', 'unauthorized', 401);
$isAdmin = in_array($currentUserRole, ['admin', 'super_admin'], true);

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH'], true)) {
  json_err('METHOD_NOT_ALLOWED', 'post_or_patch_only', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST ?? [];

$log_id = isset($body['log_id']) ? (int)$body['log_id'] : 0;
if ($log_id <= 0) json_err('VALIDATION_ERROR', 'invalid_log_id', 422);

// ----------------------------
// Helpers
// ----------------------------
$int_or_null = function ($v, $field) {
  if ($v === null || $v === '') return null;
  if (!ctype_digit((string)$v)) json_err('VALIDATION_ERROR', "invalid_{$field}", 422);
  return (int)$v;
};

$str_or_null = function ($v) {
  if ($v === null) return null;
  $v = trim((string)$v);
  return ($v === '') ? null : $v;
};

$enum_or_null = function ($v, $field, array $allowed) {
  if ($v === null || $v === '') return null;
  $v = strtoupper(trim((string)$v));
  if (!in_array($v, $allowed, true)) json_err('VALIDATION_ERROR', "invalid_{$field}", 422);
  return $v;
};

$normalize_bool_or_null = function ($v) {
  if ($v === null) return null;
  return ($v == 1 || $v === true || $v === '1') ? 1 : 0;
};

$normalize_care_type_db = function ($careType) {
  $careType = strtolower(trim((string)$careType));
  if ($careType === 'spray') $careType = 'pesticide';
  return $careType;
};

$care_type_for_client = function ($careTypeDb) {
  if ($careTypeDb === 'pesticide') return 'spray';
  return $careTypeDb;
};

try {
  // owner check
  $st = $dbh->prepare('SELECT * FROM care_logs WHERE log_id=?');
  $st->execute([$log_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_err('NOT_FOUND', 'log_not_found', 404);

  if (!$isAdmin && (int)$row['user_id'] !== $currentUserId) {
    json_err('FORBIDDEN', 'not_owner', 403);
  }

  $sets = [];
  $params = [];

  // tree_id (optional)
  $treeIdRef = (int)$row['tree_id'];
  if (array_key_exists('tree_id', $body)) {
    $newTreeId = (int)($body['tree_id'] ?? 0);
    if ($newTreeId <= 0) json_err('VALIDATION_ERROR', 'invalid_tree_id', 422);

    if (!$isAdmin) {
      $stT = $dbh->prepare('SELECT 1 FROM orange_trees WHERE tree_id=? AND user_id=?');
      $stT->execute([$newTreeId, $currentUserId]);
      if (!$stT->fetchColumn()) json_err('FORBIDDEN', 'tree_not_belong_to_user', 403);
    }

    $treeIdRef = $newTreeId;
    $sets[] = 'tree_id=?';
    $params[] = $newTreeId;
  }

  // care_type
  if (array_key_exists('care_type', $body)) {
    $ctDb = $normalize_care_type_db($body['care_type']);
    $allowedDbTypes = ['fertilizer','pesticide','watering','pruning','other'];
    if ($ctDb === '' || !in_array($ctDb, $allowedDbTypes, true)) json_err('VALIDATION_ERROR', 'care_type_invalid', 422);
    $sets[] = 'care_type=?';
    $params[] = $ctDb;
  }

  // care_date
  if (array_key_exists('care_date', $body)) {
    $raw = trim((string)($body['care_date'] ?? ''));
    if ($raw === '') json_err('VALIDATION_ERROR', 'invalid_care_date', 422);
    $ts = strtotime($raw);
    if ($ts === false) json_err('VALIDATION_ERROR', 'invalid_care_date_format', 422);
    $sets[] = 'care_date=?';
    $params[] = date('Y-m-d', $ts);
  }

  // is_reminder / is_done
  if (array_key_exists('is_reminder', $body)) {
    $v = $normalize_bool_or_null($body['is_reminder']);
    if ($v !== null) { $sets[] = 'is_reminder=?'; $params[] = $v; }
  }
  if (array_key_exists('is_done', $body)) {
    $v = $normalize_bool_or_null($body['is_done']);
    if ($v !== null) { $sets[] = 'is_done=?'; $params[] = $v; }
  }

  // diagnosis_history_id (ต้องเป็นของ user และ tree ตรงกัน)
  if (array_key_exists('diagnosis_history_id', $body)) {
    $dh = $int_or_null($body['diagnosis_history_id'], 'diagnosis_history_id');
    if ($dh !== null) {
      $stH = $dbh->prepare('SELECT user_id, tree_id FROM diagnosis_history WHERE diagnosis_history_id=?');
      $stH->execute([$dh]);
      $h = $stH->fetch(PDO::FETCH_ASSOC);
      if (!$h) json_err('VALIDATION_ERROR', 'diagnosis_history_not_found', 422);
      if (!$isAdmin && (int)$h['user_id'] !== $currentUserId) json_err('FORBIDDEN', 'history_not_belong_to_user', 403);
      if ((int)$h['tree_id'] !== $treeIdRef) json_err('VALIDATION_ERROR', 'history_tree_id_mismatch', 422);
    }
    $sets[] = 'diagnosis_history_id=?';
    $params[] = $dh;
  }

  // chemical_id + moa snapshot
  $chemicalKeyExists = array_key_exists('chemical_id', $body);
  $newChemicalId = null;
  $chemSnapshot = null;

  if ($chemicalKeyExists) {
    $newChemicalId = $int_or_null($body['chemical_id'], 'chemical_id');

    if ($newChemicalId !== null) {
      $stC = $dbh->prepare('SELECT user_id, product_name, moa_system, moa_group_code, moa_member_no FROM user_chemicals WHERE chemical_id=?');
      $stC->execute([$newChemicalId]);
      $chem = $stC->fetch(PDO::FETCH_ASSOC);
      if (!$chem) json_err('VALIDATION_ERROR', 'chemical_not_found', 422);
      if (!$isAdmin && (int)$chem['user_id'] !== $currentUserId) json_err('FORBIDDEN', 'chemical_not_belong_to_user', 403);
      $chemSnapshot = $chem;
    }

    $sets[] = 'chemical_id=?';
    $params[] = $newChemicalId;

    // ถ้า set chemical เป็น null แล้ว client ไม่ส่ง moa_* มา -> เคลียร์ moa_* ด้วย (กันค่าค้าง)
    if ($newChemicalId === null &&
        !array_key_exists('moa_system', $body) &&
        !array_key_exists('moa_group_code', $body) &&
        !array_key_exists('moa_member_no', $body)) {
      $sets[] = 'moa_system=?'; $params[] = null;
      $sets[] = 'moa_group_code=?'; $params[] = null;
      $sets[] = 'moa_member_no=?'; $params[] = null;
    }
  }

  // moa_* update (ถ้า chemical_id ส่งมา: ให้ใช้ค่า snapshot เป็นหลัก, แต่ถ้า snapshot ว่างให้ใช้ค่าจาก body)
  $wantMoaSystem = null;
  $wantMoaGroup = null;
  $wantMoaMember = null;

  $hasMoaSystemKey = array_key_exists('moa_system', $body);
  $hasMoaGroupKey  = array_key_exists('moa_group_code', $body);
  $hasMoaMemberKey = array_key_exists('moa_member_no', $body);

  if ($chemicalKeyExists) {
    // chemical ถูกอัปเดต
    $snapSys = ($chemSnapshot && !empty($chemSnapshot['moa_system'])) ? $chemSnapshot['moa_system'] : null;
    $snapGrp = ($chemSnapshot && !empty($chemSnapshot['moa_group_code'])) ? $chemSnapshot['moa_group_code'] : null;
    $snapMem = ($chemSnapshot && $chemSnapshot['moa_member_no'] !== null) ? (int)$chemSnapshot['moa_member_no'] : null;

    if ($newChemicalId !== null) {
      // มี chemical ใหม่ -> ตั้งค่า moa ตาม snapshot; ถ้า snapshot ว่างและ body ส่งมา ก็ใช้ body
      $wantMoaSystem = $snapSys;
      if ($wantMoaSystem === null && $hasMoaSystemKey) $wantMoaSystem = $enum_or_null($body['moa_system'], 'moa_system', ['FRAC','IRAC']);

      $wantMoaGroup = $snapGrp;
      if ($wantMoaGroup === null && $hasMoaGroupKey) {
        $g = $str_or_null($body['moa_group_code']);
        if ($g !== null && mb_strlen($g, 'UTF-8') > 10) json_err('VALIDATION_ERROR', 'moa_group_code_too_long', 422);
        $wantMoaGroup = $g;
      }

      $wantMoaMember = $snapMem;
      if ($wantMoaMember === null && $hasMoaMemberKey) $wantMoaMember = $int_or_null($body['moa_member_no'], 'moa_member_no');

      $sets[] = 'moa_system=?'; $params[] = $wantMoaSystem;
      $sets[] = 'moa_group_code=?'; $params[] = $wantMoaGroup;
      $sets[] = 'moa_member_no=?'; $params[] = $wantMoaMember;

      // ถ้า client ไม่ส่ง product_name มา และ chemical มี product_name -> ตั้งค่า product_name ให้เป็นค่าเริ่มต้น
      if (!array_key_exists('product_name', $body) && !empty($chemSnapshot['product_name'])) {
        $sets[] = 'product_name=?';
        $params[] = $chemSnapshot['product_name'];
      }
    }
    // กรณี chemical_id = null จะถูกจัดการด้านบนแล้ว (เคลียร์ moa_* หากไม่ส่ง moa)
  } else {
    // chemical ไม่ได้อัปเดต แต่ moa ถูกอัปเดต
    if ($hasMoaSystemKey) {
      $sets[] = 'moa_system=?';
      $params[] = $enum_or_null($body['moa_system'], 'moa_system', ['FRAC','IRAC']);
    }
    if ($hasMoaGroupKey) {
      $g = $str_or_null($body['moa_group_code']);
      if ($g !== null && mb_strlen($g, 'UTF-8') > 10) json_err('VALIDATION_ERROR', 'moa_group_code_too_long', 422);
      $sets[] = 'moa_group_code=?';
      $params[] = $g;
    }
    if ($hasMoaMemberKey) {
      $sets[] = 'moa_member_no=?';
      $params[] = $int_or_null($body['moa_member_no'], 'moa_member_no');
    }
  }

  // product fields
  if (array_key_exists('product_name', $body)) {
    $sets[] = 'product_name=?';
    $params[] = $str_or_null($body['product_name']);
  }
  if (array_key_exists('amount', $body)) {
    $sets[] = 'amount=?';
    $params[] = ($body['amount'] === '' ? null : $body['amount']);
  }
  if (array_key_exists('unit', $body)) {
    $sets[] = 'unit=?';
    $params[] = $str_or_null($body['unit']);
  }
  if (array_key_exists('area', $body)) {
    $sets[] = 'area=?';
    $params[] = $str_or_null($body['area']);
  }
  if (array_key_exists('note', $body)) {
    $sets[] = 'note=?';
    $params[] = $str_or_null($body['note']);
  }

  if (!$sets) {
    json_ok(['log_id' => $log_id, 'message' => 'no_change']);
  }

  $params[] = $log_id;
  $sql = 'UPDATE care_logs SET ' . implode(', ', $sets) . ' WHERE log_id=?';
  $stU = $dbh->prepare($sql);
  $stU->execute($params);

  // return full row
  $stR = $dbh->prepare('SELECT * FROM care_logs WHERE log_id=?');
  $stR->execute([$log_id]);
  $out = $stR->fetch(PDO::FETCH_ASSOC);

  $rawFlag = isset($_GET['raw']) && $_GET['raw'] === '1';
  if ($out && !$rawFlag && isset($out['care_type'])) {
    $out['care_type'] = $care_type_for_client($out['care_type']);
  }

  json_ok($out ?: ['log_id' => $log_id, 'message' => 'updated']);

} catch (Throwable $e) {
  json_err('DB_ERROR', 'db_error', 500);
}
