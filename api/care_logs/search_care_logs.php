<?php
require_once __DIR__ . '/../auth/require_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

try {
    $userId  = (int)$AUTH_USER_ID;
    $isAdmin = in_array($AUTH_USER_ROLE, ['admin', 'super_admin'], true);

    $q       = trim((string)($_GET['q'] ?? ''));
    $where   = [];
    $params  = [];

    if (!$isAdmin) {
        $where[]  = "user_id = ?";
        $params[] = $userId;
    }

    if ($q !== '') {
        $where[] = "(product_name LIKE ? OR note LIKE ?)";
        $like    = "%{$q}%";
        $params[] = $like;
        $params[] = $like;
    }

    if (isset($_GET['tree_id']) && is_numeric($_GET['tree_id'])) {
        $where[]  = "tree_id = ?";
        $params[] = (int)$_GET['tree_id'];
    }

    if (!empty($_GET['care_type'])) {
        $where[]  = "care_type = ?";
        $params[] = (string)$_GET['care_type'];
    }

    $sql = "SELECT
              log_id, user_id, tree_id, care_type, care_date,
              product_name, amount, unit, area, note, created_at
            FROM care_logs";

    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    $sql .= " ORDER BY care_date DESC, log_id DESC";

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    json_ok($rows);

} catch (Throwable $e) {
    json_err("DB_ERROR", "db_error", 500);
}
