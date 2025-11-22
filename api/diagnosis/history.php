<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . '/../db.php';

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

if (!$user_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_user_id']);
    exit;
}

try {
    // Join หลายตารางเพื่อดึงข้อมูลครบ: วันที่, รูปภาพ, ชื่อโรค, ความแม่นยำ
    $sql = "
        SELECT 
            d.diagnosis_id,
            d.probability,
            d.diagnosed_at,
            s.sample_id,
            ds.name_th AS disease_name,
            ds.image AS disease_default_image,
            ir.image_url AS user_upload_image
        FROM Diagnosis d
        JOIN Submission s ON d.sample_id = s.sample_id
        JOIN diseases ds ON d.disease_id = ds.disease_id
        LEFT JOIN Image_Repository ir ON s.sample_id = ir.sample_id
        WHERE s.user_id = ?
        ORDER BY d.diagnosed_at DESC
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'ok', 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>