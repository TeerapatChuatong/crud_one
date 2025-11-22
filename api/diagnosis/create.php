<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/../db.php';

$body = json_decode(file_get_contents("php://input"), true) ?: [];

$sample_id   = isset($body['sample_id']) ? (int)$body['sample_id'] : null;
$disease_id  = isset($body['disease_id']) ? (int)$body['disease_id'] : null;
$probability = isset($body['probability']) ? (float)$body['probability'] : 0.0;

if (!$sample_id || !$disease_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_fields']);
    exit;
}

try {
    $dbh->beginTransaction();

    // 1. บันทึกผลลงตาราง Diagnosis
    $stmt = $dbh->prepare("
        INSERT INTO Diagnosis (sample_id, disease_id, probability, diagnosed_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$sample_id, $disease_id, $probability]);
    $diag_id = $dbh->lastInsertId();

    // 2. อัปเดตสถานะในตาราง Submission เป็น 'completed'
    $stmtUpd = $dbh->prepare("UPDATE Submission SET status = 'completed' WHERE sample_id = ?");
    $stmtUpd->execute([$sample_id]);

    $dbh->commit();
    echo json_encode(['status' => 'ok', 'diagnosis_id' => $diag_id]);

} catch (Throwable $e) {
    $dbh->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>