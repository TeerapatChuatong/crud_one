<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . '/../db.php';

// รองรับการกรองสถานะ เช่น ?status=pending
$status = isset($_GET['status']) ? $_GET['status'] : null;

try {
    $sql = "
        SELECT 
            s.sample_id,
            s.status,
            s.captured_at,
            u.username,
            u.email,
            ir.image_url,
            d.probability,
            dis.name_th as diagnosed_disease
        FROM Submission s
        LEFT JOIN user u ON s.user_id = u.id
        LEFT JOIN Image_Repository ir ON s.sample_id = ir.sample_id
        LEFT JOIN Diagnosis d ON s.sample_id = d.sample_id
        LEFT JOIN diseases dis ON d.disease_id = dis.disease_id
        WHERE 1=1
    ";

    $params = [];
    if ($status) {
        $sql .= " AND s.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY s.captured_at DESC";

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'ok', 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>