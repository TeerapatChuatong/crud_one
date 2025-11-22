<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/../db.php';

$body = json_decode(file_get_contents("php://input"), true) ?: [];

// 1. รับ User ID (ถ้ามี)
$user_id = isset($body['user_id']) ? (int)$body['user_id'] : null;
// 2. รับ URL รูปภาพ (สมมติว่าอัปโหลดไฟล์เสร็จแล้วส่งมาแค่ URL)
$image_url = trim($body['image_url'] ?? '');
// 3. รับคำตอบ (Array ของ answers)
$answers = $body['answers'] ?? []; // คาดหวังรูปแบบ [{ "question_id": 1, "value_text": "...", "score": 5 }, ...]

if ($image_url === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing_image_url']);
    exit;
}

try {
    // เริ่ม Transaction (ถ้าพังจุดไหนให้ Rollback ทั้งหมด)
    $dbh->beginTransaction();

    // --- Step 1: สร้าง Submission ---
    $stmtSub = $dbh->prepare("INSERT INTO Submission (user_id, status, captured_at) VALUES (?, 'pending', NOW())");
    $stmtSub->execute([$user_id]);
    $submission_id = $dbh->lastInsertId();

    // --- Step 2: บันทึกรูปภาพลง Image_Repository ---
    $stmtImg = $dbh->prepare("INSERT INTO Image_Repository (image_url, sample_id) VALUES (?, ?)");
    $stmtImg->execute([$image_url, $submission_id]);

    // --- Step 3: บันทึกคำตอบลง symptom_answers (ถ้ามี) ---
    if (!empty($answers) && is_array($answers)) {
        $sqlAns = "INSERT INTO symptom_answers 
                   (submission_id, question_id, value_text, value_number, value_bool, score) 
                   VALUES (?, ?, ?, ?, ?, ?)";
        $stmtAns = $dbh->prepare($sqlAns);

        foreach ($answers as $ans) {
            $stmtAns->execute([
                $submission_id,
                $ans['question_id'] ?? null,
                $ans['value_text'] ?? null,
                $ans['value_number'] ?? null,
                isset($ans['value_bool']) ? (int)$ans['value_bool'] : null,
                $ans['score'] ?? 0 // เก็บ score ที่คุณเพิ่งเพิ่ม
            ]);
            
            // (Optionเสริม) ถ้าต้องการบันทึก Log ลง Symptom_records ด้วย ก็ทำตรงนี้ได้
            // $lastAnsId = $dbh->lastInsertId();
            // $dbh->prepare("INSERT INTO Symptom_records ...")->execute([...]);
        }
    }

    // ยืนยันการบันทึกทั้งหมด
    $dbh->commit();

    echo json_encode([
        'status' => 'ok', 
        'message' => 'submission_created',
        'data' => ['submission_id' => $submission_id]
    ]);

} catch (Throwable $e) {
    // ถ้ามี Error ให้ยกเลิกทั้งหมด
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error: ' . $e->getMessage()]);
}
?>