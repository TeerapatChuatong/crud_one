<?php
// crud/api/diagnosis_history/create_diagnosis_history.php
// - upsert ต่อ (user_id, tree_id, disease_id) โดย update record ล่าสุด
// - รองรับบันทึก advice_text และรูปสแกน (image_url / image_base64 / image_file)

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

  // รองรับ data URI
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

  // เก็บแบบ relative (ให้ Flutter ประกอบ URL เอง)
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

  // รองรับ JSON และ multipart (กรณี upload ไฟล์)
  $raw = file_get_contents("php://input");
  $body = [];
  $isJson = false;

  if (!empty($_POST)) {
    $body = $_POST;
  } elseif ($raw) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) {
      $body = $tmp;
      $isJson = true;
    }
  }

  // validation
  if (!isset($body["tree_id"]) || !ctype_digit((string)$body["tree_id"])) {
    json_err("VALIDATION_ERROR", "tree_id_required", 400);
  }
  if (!isset($body["disease_id"]) || !ctype_digit((string)$body["disease_id"])) {
    json_err("VALIDATION_ERROR", "disease_id_required", 400);
  }

  $tree_id = (int)$body["tree_id"];
  $disease_id = (int)$body["disease_id"];
  $risk_level_id = isset($body["risk_level_id"]) && ctype_digit((string)$body["risk_level_id"]) ? (int)$body["risk_level_id"] : null;
  $total_score = isset($body["total_score"]) ? (int)$body["total_score"] : 0;

  // ✅ advice_text (อาจส่งมาเป็น template หรือ resolved ก็ได้)
  $hasAdvice = array_key_exists("advice_text", $body);
  $advice_text = $hasAdvice ? (string)$body["advice_text"] : null;

  // ✅ image_url / image_base64 / image_file
  $image_url = array_key_exists("image_url", $body) ? trim((string)$body["image_url"]) : "";
  $image_ext = array_key_exists("image_ext", $body) ? trim((string)$body["image_ext"]) : "jpg";
  $image_base64 = array_key_exists("image_base64", $body) ? (string)$body["image_base64"] : "";

  $saved_img = null;
  if (!empty($_FILES["image_file"])) {
    $saved_img = save_image_from_file($_FILES["image_file"], "dh_{$session_user_id}_{$tree_id}_{$disease_id}");
  }
  if ($saved_img === null && trim($image_base64) !== "") {
    $saved_img = save_image_from_base64($image_base64, $image_ext, "dh_{$session_user_id}_{$tree_id}_{$disease_id}");
  }
  if ($saved_img !== null) {
    $image_url = $saved_img;
  }

  // 🔎 หา record ล่าสุดของ (user, tree, disease)
  $stmt = $pdo->prepare("
    SELECT diagnosis_history_id
    FROM diagnosis_history
    WHERE user_id = ? AND tree_id = ? AND disease_id = ?
    ORDER BY diagnosed_at DESC, diagnosis_history_id DESC
    LIMIT 1
  ");
  $stmt->execute([$session_user_id, $tree_id, $disease_id]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    // ✅ update record ล่าสุด
    $dh_id = (int)$existing["diagnosis_history_id"];

    $fields = [];
    $params = [];

    if ($risk_level_id !== null) { $fields[] = "risk_level_id = ?"; $params[] = $risk_level_id; }
    $fields[] = "total_score = ?"; $params[] = $total_score;

    if ($image_url !== "") { $fields[] = "image_url = ?"; $params[] = $image_url; }

    if ($hasAdvice) { $fields[] = "advice_text = ?"; $params[] = $advice_text; }

    // อัปเดตเวลาให้เป็นล่าสุดเสมอ (เพื่อให้ history เรียงถูก)
    $fields[] = "diagnosed_at = NOW()";

    $params[] = $dh_id;

    $sql = "UPDATE diagnosis_history SET " . implode(", ", $fields) . " WHERE diagnosis_history_id = ?";
    $u = $pdo->prepare($sql);
    $u->execute($params);

    json_ok(["diagnosis_history_id" => $dh_id, "image_url" => $image_url]);
  }

  // ✅ insert ใหม่
  $sql = "
    INSERT INTO diagnosis_history
      (user_id, tree_id, disease_id, risk_level_id, total_score, image_url, advice_text, diagnosed_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, NOW())
  ";
  $ins = $pdo->prepare($sql);
  $ins->execute([
    $session_user_id,
    $tree_id,
    $disease_id,
    $risk_level_id,
    $total_score,
    $image_url !== "" ? $image_url : null,
    $hasAdvice ? $advice_text : null,
  ]);

  $newId = (int)$pdo->lastInsertId();
  json_ok(["diagnosis_history_id" => $newId, "image_url" => $image_url]);

} catch (Throwable $e) {
  json_err("SERVER_ERROR", $e->getMessage(), 500);
}
