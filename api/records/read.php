<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . '/../../db.php';

try {
    $sql = "
        SELECT 
            r.record_id,
            r.created_at,
            u.username,
            q.question_text,
            a.value_text,
            a.value_bool,
            a.score
        FROM Symptom_records r
        LEFT JOIN user u ON r.user_id = u.id
        LEFT JOIN symptom_questions q ON r.question_id = q.question_id
        LEFT JOIN symptom_answers a ON r.answer_id = a.answer_id
        ORDER BY r.created_at DESC 
        LIMIT 50
    ";
    
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['status' => 'ok', 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'db_error']);
}
?>