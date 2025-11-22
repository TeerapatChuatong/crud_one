<?php
require_once __DIR__ . '/../db.php';

try {
    if (function_exists('require_login')) {
        require_login();
    }

    $stmt = $dbh->prepare("SELECT * FROM diseases ORDER BY disease_id ASC");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_ok($rows);
} catch (Throwable $e) {
    json_err("DB_ERROR", "db_error", 500);
}
?>