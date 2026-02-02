<?php
// crud/api/diagnosis_history/update_diagnosis_history.php
// - update ตาม diagnosis_history_id
// - รองรับอัปเดต advice_text และรูปสแกน (image_url / image_base64 / image_file)

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../auth/require_auth.php";

function ensure_upload_dir(string $dir): void {
  if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
  }
}

function save_image_from_base64(string $b64, string $ext = "jpg", string $prefix = "dh"): ?string {
  $b64 = trim($b64);
  if ($b64 === "") return null;

  if (preg_match('/^data:image\/([a-zA-Z0-9]+);base64,/', $b64, $m)) {
    $ext = strtolower($m[1]);
    $b64 = preg_replace('/^data:image\/[a-zA-Z0-9]+;base64,/', '', $b64);
  }

  $bin = base64_decode($b64, true);
  if ($bin === false) return null;

  $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
  if ($ext === "") $ext = "jpg";

  $uploadDir = __DIR__ . "/../../uploads/diagnosis_history";
  ensure_upload_dir($uploadDir);

  $name = $prefix . "_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . "." . $ext;
  $path = $uploadDir . "/" . $name;

  if (@file_put_contents($path, $bin) === false) return null;
  return "uploads/diagnosis_history/" . $name;
}

function save_image_from_file(array $file, string $prefix = "dh"): ?string {
  if (!isset($file["tmp_name"]) || !is_uploaded_file($file["tmp_name"])) return null;
  if (($file["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;

  $ext = pathinfo($file["name"] ?? "", PATHINFO_EXTENSION);
  $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext));
  if ($ext === "") $ext = "jpg";

  $uploadDir = __DIR__ . "/../../uploads/diagnosis_history";
  ensure_upload_dir($uploadDir);

  $name = $prefix . "_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . "." . $ext;
  $path = $uploadDir . "/" . $name;

  if (!@move_uploaded_file($file["tmp_name"], $path)) return null;
  return "uploads/diagnosis_history/" . $name;
}

try {
  $session_user_id = (int)$AUTH_USER_ID;
  $isAdmin = (bool)$AUTH_IS_ADMIN;

  $raw = file_get_contents("php://input");
  $body = [];

  if (!empty($_POST)) {
    $body = $_POST;
  } elseif ($raw) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $body = $tmp;
  }

  if (!isset($body["diagnosis_history_id"]) || !ctype_digit((string)$body["diagnosis_history_id"])) {
    json_err("VALIDATION_ERROR", "diagnosis_history_id_required", 400);
  }
  $dh_id = (int)$body["diagnosis_history_id"];

  // หา record เดิม + owner check
  $stmt = $pdo->prepare("SELECT * FROM diagnosis_history WHERE diagnosis_history_id = ? LIMIT 1");
  $stmt->execute([$dh_id]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$existing) json_err("NOT_FOUND", "not_found", 404);
  if (!$isAdmin && (int)$existing["user_id"] !== $session_user_id) json_err("FORBIDDEN", "not_owner", 403);

  $fields = [];
  $params = [];

  // ✅ user แก้ได้: risk_level_id, total_score, advice_text, image_url, diagnosed_at
  // ✅ admin แก้เพิ่มได้: user_id, tree_id, disease_id

  if ($isAdmin && array_key_exists("user_id", $body)) {
    if (!ctype_digit((string)$body["user_id"])) json_err("VALIDATION_ERROR", "invalid_user_id", 400);
    $fields[] = "user_id = ?";
    $params[] = (int)$body["user_id"];
  }

  if ($isAdmin && array_key_exists("tree_id", $body)) {
    if (!ctype_digit((string)$body["tree_id"])) json_err("VALIDATION_ERROR", "invalid_tree_id", 400);
    $fields[] = "tree_id = ?";
    $params[] = (int)$body["tree_id"];
  }

  if ($isAdmin && array_key_exists("disease_id", $body)) {
    if (!ctype_digit((string)$body["disease_id"])) json_err("VALIDATION_ERROR", "invalid_disease_id", 400);
    $fields[] = "disease_id = ?";
    $params[] = (int)$body["disease_id"];
  }

  if (array_key_exists("risk_level_id", $body)) {
    if ($body["risk_level_id"] === null || $body["risk_level_id"] === "") {
      $fields[] = "risk_level_id = NULL";
    } else {
      if (!ctype_digit((string)$body["risk_level_id"])) json_err("VALIDATION_ERROR","invalid_risk_level_id",400);
      $fields[] = "risk_level_id = ?";
      $params[] = (int)$body["risk_level_id"];
    }
  }

  if (array_key_exists("total_score", $body)) {
    $fields[] = "total_score = ?";
    $params[] = (int)$body["total_score"];
  }

  if (array_key_exists("advice_text", $body)) {
    $fields[] = "advice_text = ?";
    $params[] = (string)$body["advice_text"];
  }

  // รูป: image_file หรือ image_base64 หรือ image_url
  $image_url = array_key_exists("image_url", $body) ? trim((string)$body["image_url"]) : "";
  $image_ext = array_key_exists("image_ext", $body) ? trim((string)$body["image_ext"]) : "jpg";
  $image_base64 = array_key_exists("image_base64", $body) ? (string)$body["image_base64"] : "";

  $saved_img = null;
  if (!empty($_FILES["image_file"])) {
    $saved_img = save_image_from_file($_FILES["image_file"], "dh_{$session_user_id}_{$dh_id}");
  }
  if ($saved_img === null && trim($image_base64) !== "") {
    $saved_img = save_image_from_base64($image_base64, $image_ext, "dh_{$session_user_id}_{$dh_id}");
  }
  if ($saved_img !== null) {
    $image_url = $saved_img;
  }

  if ($image_url !== "") {
    $fields[] = "image_url = ?";
    $params[] = $image_url;
  }

  if (array_key_exists("diagnosed_at", $body)) {
    // ให้ส่ง ISO string ได้ หรือว่างเพื่อ set NOW()
    $v = trim((string)$body["diagnosed_at"]);
    if ($v === "") {
      $fields[] = "diagnosed_at = NOW()";
    } else {
      $fields[] = "diagnosed_at = ?";
      $params[] = $v;
    }
  }

  if (count($fields) === 0) {
    json_err("VALIDATION_ERROR", "no_fields_to_update", 400);
  }

  $params[] = $dh_id;
  $sql = "UPDATE diagnosis_history SET " . implode(", ", $fields) . " WHERE diagnosis_history_id = ?";
  $u = $pdo->prepare($sql);
  $u->execute($params);

  json_ok(["diagnosis_history_id" => $dh_id, "image_url" => $image_url]);

} catch (Throwable $e) {
  json_err("SERVER_ERROR", $e->getMessage(), 500);
}
