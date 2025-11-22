<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . '/../db.php';

$submission_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$submission_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_id']);
    exit;
}

try {
    // 1. ดึงข้อมูลหลัก Submission + รูปภาพ
    $sqlInfo = "
        SELECT 
            s.sample_id, s.status, s.captured_at,
            ir.image_url
        FROM Submission s
        LEFT JOIN Image_Repository ir ON s.sample_id = ir.sample_id
        WHERE s.sample_id = ?
        LIMIT 1
    ";
    $stmt = $dbh->prepare($sqlInfo);
    $stmt->execute([$submission_id]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$info) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'submission_not_found']);
        exit;
    }

    // 2. ดึงรายการคำถาม-คำตอบ ของรอบนี้
    $sqlAns = "
        SELECT 
            q.question_text,
            a.value_text,
            a.value_bool,
            a.value_number,
            a.score
        FROM symptom_answers a
        JOIN symptom_questions q ON a.question_id = q.question_id
        WHERE a.submission_id = ?
        ORDER BY q.order_index ASC
    ";
    $stmtAns = $dbh->prepare($sqlAns);
    $stmtAns->execute([$submission_id]);
    $answers = $stmtAns->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'ok', 'data' => ['info' => $info, 'answers' => $answers]]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>