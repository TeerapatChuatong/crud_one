<?php
// api/orange_trees/create_orange_trees.php
require_once __DIR__ . '/../db.php';

// ต้องล็อกอินก่อนถึงจะเพิ่มต้นได้
require_login();

// method guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "post_only", 405);
}

// ดึง user_id จาก session
$currentUserId = (string)($_SESSION['user_id'] ?? '');
if ($currentUserId === '') {
  json_err("UNAUTHORIZED", "no_user_in_session", 401);
}

// รับ JSON body
$body = json_decode(file_get_contents("php://input"), true) ?: [];

$tree_name       = trim($body['tree_name']       ?? '');
$location_in_farm = trim($body['location_in_farm'] ?? '');

if ($tree_name === '') {
  json_err("VALIDATION_ERROR", "tree_name_required", 400);
}

try {
  // 1) ดึง tree_id ล่าสุด (เป็นตัวเลขมากที่สุดในตาราง)
  $st = $dbh->query("SELECT MAX(CAST(tree_id AS UNSIGNED)) AS max_id FROM orange_trees");
  $maxId = $st->fetchColumn();

  // 2) ถ้ายังไม่มีข้อมูลเลย → เริ่มจาก 1, ถ้ามีแล้ว → +1
  if ($maxId === null || $maxId === false) {
    $nextId = 1;
  } else {
    $nextId = (int)$maxId + 1;
  }

  // 3) แปลงเป็น string เพื่อเก็บใน tree_id
  $tree_id = (string)$nextId;

  // 4) บันทึกลงฐานข้อมูล
  $sql = "INSERT INTO orange_trees (tree_id, user_id, tree_name, location_in_farm)
          VALUES (?, ?, ?, ?)";
  $st = $dbh->prepare($sql);
  $st->execute([
    $tree_id,
    $currentUserId,
    $tree_name,
    $location_in_farm !== '' ? $location_in_farm : null,
  ]);

  json_ok([
    "tree_id"          => $tree_id,
    "user_id"          => $currentUserId,
    "tree_name"        => $tree_name,
    "location_in_farm" => $location_in_farm !== '' ? $location_in_farm : null,
  ]);
} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
