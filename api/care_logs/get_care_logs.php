<?php
require_once __DIR__ . '/../db.php';

// เรียก require_auth.php และรับข้อมูล user
$authUser = require __DIR__ . '/../auth/require_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

try {
    $userId  = (int)$authUser['id'];
    $isAdmin = in_array($authUser['role'] ?? 'user', ['admin', 'super_admin'], true);

    $where  = [];
    $params = [];

    // ถ้าไม่ใช่ admin ให้เห็นเฉพาะข้อมูลของตัวเอง
    if (!$isAdmin) {
        $where[]  = "user_id = ?";
        $params[] = $userId;
    }

    // filter: tree_id, care_type, date range
    if (isset($_GET['tree_id']) && is_numeric($_GET['tree_id'])) {
        $where[]  = "tree_id = ?";
        $params[] = (int)$_GET['tree_id'];
    }

    if (!empty($_GET['care_type'])) {
        $where[]  = "care_type = ?";
        $params[] = (string)$_GET['care_type'];
    }

    if (!empty($_GET['from_date'])) {
        $where[]  = "care_date >= ?";
        $params[] = (string)$_GET['from_date'];
    }

    if (!empty($_GET['to_date'])) {
        $where[]  = "care_date <= ?";
        $params[] = (string)$_GET['to_date'];
    }

    $sql = "SELECT
              log_id, user_id, tree_id, care_type, care_date,
              is_reminder, is_done,
              product_name, amount, unit, area, note, created_at
            FROM care_logs";

    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    $sql .= " ORDER BY care_date DESC, log_id DESC";

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ ส่งเป็น ok แทน status
    json_ok($rows);

} catch (Throwable $e) {
    error_log("get_care_logs error: " . $e->getMessage());
    json_err("DB_ERROR", "db_error: " . $e->getMessage(), 500);
}