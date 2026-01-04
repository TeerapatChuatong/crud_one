<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth/require_auth.php";

$method = $_SERVER["REQUEST_METHOD"] ?? "";
if ($method !== "PATCH" && $method !== "POST") {
  json_err("METHOD_NOT_ALLOWED", "Use PATCH or POST", 405);
}

$session_uid = (int)($_SESSION["user_id"] ?? 0);
if ($session_uid <= 0) json_err("UNAUTHORIZED", "Please login", 401);

$isAdmin = function_exists("is_admin") ? (bool)is_admin() : false;

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST ?? [];

$tree_id = isset($body["tree_id"]) ? (int)$body["tree_id"] : 0;
if ($tree_id <= 0) json_err("INVALID_INPUT", "tree_id is required", 400);

try {
  $stmt0 = $dbh->prepare("SELECT user_id FROM orange_trees WHERE tree_id = :tree_id");
  $stmt0->execute([":tree_id" => $tree_id]);
  $owner = (int)($stmt0->fetchColumn() ?? 0);

  if ($owner <= 0) json_err("NOT_FOUND", "Tree not found", 404);
  if (!$isAdmin && $owner !== $session_uid) json_err("FORBIDDEN", "Not allowed", 403);

  $tree_name = isset($body["tree_name"]) ? trim((string)$body["tree_name"]) : null;
  $description = isset($body["description"]) ? trim((string)$body["description"]) : null;

  $fields = [];
  $params = [":tree_id" => $tree_id];

  if ($tree_name !== null) {
    if ($tree_name === "") json_err("INVALID_INPUT", "tree_name cannot be empty", 400);
    $fields[] = "tree_name = :tree_name";
    $params[":tree_name"] = $tree_name;
  }
  if ($description !== null) {
    if ($description === "") $description = null;
    $fields[] = "description = :description";
    $params[":description"] = $description;
  }

  if (!$fields) json_err("INVALID_INPUT", "No fields to update", 400);

  $sql = "UPDATE orange_trees SET " . implode(", ", $fields) . " WHERE tree_id = :tree_id";
  $stmt = $dbh->prepare($sql);
  $stmt->execute($params);

  $stmt2 = $dbh->prepare(
    "SELECT tree_id, user_id, tree_name, description, created_at
     FROM orange_trees
     WHERE tree_id = :tree_id"
  );
  $stmt2->execute([":tree_id" => $tree_id]);

  json_ok($stmt2->fetch(PDO::FETCH_ASSOC));
} catch (Exception $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
