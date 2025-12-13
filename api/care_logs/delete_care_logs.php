<?php
require_once __DIR__ . '/../auth/require_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    json_err("METHOD_NOT_ALLOWED", "delete_only", 405);
}

$log_id = isset($_GET['log_id']) ? (int)$_GET['log_id'] : 0;
if ($log_id <= 0) {
    // รองรับ body JSON ด้วยเผื่อกรณี Flutter ส่งแบบนั้น
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (isset($body['log_id']) && is_numeric($body['log_id'])) {
        $log_id = (int)$body['log_id'];
    }
}

if ($log_id <= 0) {
    json_err("VALIDATION_ERROR", "invalid_log_id", 422);
}

try {
    $userId  = (int)$AUTH_USER_ID;
    $isAdmin = in_array($AUTH_USER_ROLE, ['admin', 'super_admin'], true);

    $chk = $dbh->prepare("SELECT user_id FROM care_logs WHERE log_id = ?");
    $chk->execute([$log_id]);
    $row = $chk->fetch();

    if (!$row) {
        json_err("NOT_FOUND", "log_not_found", 404);
    }

    if (!$isAdmin && (int)$row['user_id'] !== $userId) {
        json_err("FORBIDDEN", "not_owner", 403);
    }

    $del = $dbh->prepare("DELETE FROM care_logs WHERE log_id = ?");
    $del->execute([$log_id]);

    json_ok(["log_id" => $log_id, "message" => "deleted"]);

} catch (Throwable $e) {
    json_err("DB_ERROR", "db_error", 500);
}
