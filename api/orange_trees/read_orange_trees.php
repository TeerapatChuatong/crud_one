<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth/require_auth.php";

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
  json_err("METHOD_NOT_ALLOWED", "Use GET", 405);
}

$session_uid = (int)($_SESSION["user_id"] ?? 0);
if ($session_uid <= 0) json_err("UNAUTHORIZED", "Please login", 401);

$isAdmin = function_exists("is_admin") ? (bool)is_admin() : false;

$tree_id = isset($_GET["tree_id"]) ? (int)$_GET["tree_id"] : 0;

// ✅ read one
if ($tree_id > 0) {
  try {
    if ($isAdmin) {
      $stmt = $dbh->prepare(
        "SELECT tree_id, user_id, tree_name, description, created_at
         FROM orange_trees
         WHERE tree_id = :tree_id"
      );
      $stmt->execute([":tree_id" => $tree_id]);
    } else {
      $stmt = $dbh->prepare(
        "SELECT tree_id, user_id, tree_name, description, created_at
         FROM orange_trees
         WHERE tree_id = :tree_id AND user_id = :user_id"
      );
      $stmt->execute([":tree_id" => $tree_id, ":user_id" => $session_uid]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err("NOT_FOUND", "Tree not found", 404);
    json_ok($row);
  } catch (Exception $e) {
    json_err("DB_ERROR", $e->getMessage(), 500);
  }
}

// ✅ read list (default: ของตัวเอง)
$user_id = $session_uid;
if ($isAdmin && isset($_GET["user_id"])) $user_id = (int)$_GET["user_id"];

try {
  $stmt = $dbh->prepare(
    "SELECT tree_id, user_id, tree_name, description, created_at
     FROM orange_trees
     WHERE user_id = :user_id
     ORDER BY tree_id DESC"
  );
  $stmt->execute([":user_id" => $user_id]);

  json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
