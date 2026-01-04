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

$q = trim((string)($_GET["q"] ?? ""));
$user_id = $session_uid;
if ($isAdmin && isset($_GET["user_id"])) $user_id = (int)$_GET["user_id"];

try {
  $like = "%" . $q . "%";

  $stmt = $dbh->prepare(
    "SELECT tree_id, user_id, tree_name, description, created_at
     FROM orange_trees
     WHERE user_id = :user_id
       AND (:q = '' OR tree_name LIKE :likeq OR description LIKE :likeq)
     ORDER BY tree_id DESC"
  );
  $stmt->execute([
    ":user_id" => $user_id,
    ":q" => $q,
    ":likeq" => $like,
  ]);

  json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
  json_err("DB_ERROR", $e->getMessage(), 500);
}
