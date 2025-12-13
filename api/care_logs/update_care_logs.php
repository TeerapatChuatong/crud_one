<?php
require_once __DIR__ . '/../auth/require_auth.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH'], true)) {
    json_err("METHOD_NOT_ALLOWED", "post_or_patch_only", 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body)) {
    $body = $_POST ?? [];
}

$log_id = isset($body['log_id']) ? (int)$body['log_id'] : 0;
if ($log_id <= 0) {
    json_err("VALIDATION_ERROR", "invalid_log_id", 422);
}

try {
    $userId  = (int)$AUTH_USER_ID;
    $isAdmin = in_array($AUTH_USER_ROLE, ['admin', 'super_admin'], true);

    // ตรวจ owner ก่อน
    $chk = $dbh->prepare("SELECT user_id FROM care_logs WHERE log_id = ?");
    $chk->execute([$log_id]);
    $row = $chk->fetch();

    if (!$row) {
        json_err("NOT_FOUND", "log_not_found", 404);
    }

    if (!$isAdmin && (int)$row['user_id'] !== $userId) {
        json_err("FORBIDDEN", "not_owner", 403);
    }

    // เตรียมฟิลด์อัปเดต
    $fields = [];
    $params = [];

    if (array_key_exists('tree_id', $body) && is_numeric($body['tree_id'])) {
        $fields[] = "tree_id = ?";
        $params[] = (int)$body['tree_id'];
    }

    if (array_key_exists('care_type', $body)) {
        $fields[] = "care_type = ?";
        $params[] = trim((string)$body['care_type']);
    }

    if (array_key_exists('care_date', $body)) {
        $fields[] = "care_date = ?";
        $params[] = trim((string)$body['care_date']);
    }

    if (array_key_exists('product_name', $body)) {
        $fields[] = "product_name = ?";
        $params[] = $body['product_name'] !== '' ? (string)$body['product_name'] : null;
    }

    if (array_key_exists('amount', $body)) {
        $fields[] = "amount = ?";
        $params[] = $body['amount'] !== '' ? $body['amount'] : null;
    }

    if (array_key_exists('unit', $body)) {
        $fields[] = "unit = ?";
        $params[] = $body['unit'] !== '' ? (string)$body['unit'] : null;
    }

    if (array_key_exists('area', $body)) {
        $fields[] = "area = ?";
        $params[] = $body['area'] !== '' ? (string)$body['area'] : null;
    }

    if (array_key_exists('note', $body)) {
        $fields[] = "note = ?";
        $params[] = $body['note'] !== '' ? (string)$body['note'] : null;
    }

    if (empty($fields)) {
        json_ok([
            "log_id"  => $log_id,
            "message" => "no_change",
        ]);
    }

    $params[] = $log_id;

    $sql = "UPDATE care_logs SET " . implode(', ', $fields) . " WHERE log_id = ?";
    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);

    json_ok([
        "log_id"  => $log_id,
        "message" => "updated",
    ]);

} catch (Throwable $e) {
    json_err("DB_ERROR", "db_error", 500);
}
