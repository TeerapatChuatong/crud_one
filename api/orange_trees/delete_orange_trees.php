<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth/require_auth.php";

$method = $_SERVER["REQUEST_METHOD"] ?? "";
if ($method !== "DELETE" && $method !== "POST") {
  json_err("METHOD_NOT_ALLOWED", "Use DELETE or POST", 405);
}

$session_uid = (int)($_SESSION["user_id"] ?? 0);
if ($session_uid <= 0) json_err("UNAUTHORIZED", "Please login", 401);

$isAdmin = function_exists("is_admin") ? (bool)is_admin() : false;

$tree_id = isset($_GET["tree_id"]) ? (int)$_GET["tree_id"] : 0;

if ($tree_id <= 0) {
  $raw = file_get_contents("php://input");
  $body = json_decode($raw, true);
  if (!is_array($body)) $body = $_POST ?? [];
  $tree_id = isset($body["tree_id"]) ? (int)$body["tree_id"] : 0;
}

if ($tree_id <= 0) json_err("INVALID_INPUT", "tree_id is required", 400);

try {
  $stmt0 = $dbh->prepare("SELECT user_id FROM orange_trees WHERE tree_id = :tree_id");
  $stmt0->execute([":tree_id" => $tree_id]);
  $owner = (int)($stmt0->fetchColumn() ?? 0);

  if ($owner <= 0) json_err("NOT_FOUND", "Tree not found", 404);
  if (!$isAdmin && $owner !== $session_uid) json_err("FORBIDDEN", "Not allowed", 403);

  $stmt = $dbh->prepare("DELETE FROM orange_trees WHERE tree_id = :tree_id");
  $stmt->execute([":tree_id" => $tree_id]);

  json_ok(true);
} catch (Exception $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
