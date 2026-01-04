<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth/require_auth.php"; // ✅ ใช้ Bearer token

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  json_err("METHOD_NOT_ALLOWED", "Use POST", 405);
}

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST ?? [];

$session_uid = (int)($_SESSION["user_id"] ?? 0);
if ($session_uid <= 0) json_err("UNAUTHORIZED", "Please login", 401);

$isAdmin = function_exists("is_admin") ? (bool)is_admin() : false;

$user_id = $session_uid;
if ($isAdmin && isset($body["user_id"])) $user_id = (int)$body["user_id"];

$tree_name = trim((string)($body["tree_name"] ?? ""));
$description = isset($body["description"]) ? trim((string)$body["description"]) : null;
if ($description === "") $description = null;

if ($tree_name === "") json_err("INVALID_INPUT", "tree_name is required", 400);

try {
  $stmt = $dbh->prepare(
    "INSERT INTO orange_trees (user_id, tree_name, description)
     VALUES (:user_id, :tree_name, :description)"
  );
  $stmt->execute([
    ":user_id" => $user_id,
    ":tree_name" => $tree_name,
    ":description" => $description,
  ]);

  $tree_id = (int)$dbh->lastInsertId();

  $stmt2 = $dbh->prepare(
    "SELECT tree_id, user_id, tree_name, description, created_at
     FROM orange_trees
     WHERE tree_id = :tree_id"
  );
  $stmt2->execute([":tree_id" => $tree_id]);
  $row = $stmt2->fetch(PDO::FETCH_ASSOC);

  json_ok($row ?: ["tree_id" => $tree_id]);
} catch (Exception $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
