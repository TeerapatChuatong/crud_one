<?php
require_once __DIR__ . '/../db.php';

// ✅ เรียก require_auth.php และรับข้อมูล user
$authUser = require __DIR__ . '/../auth/require_auth.php';

try {
    // ✅ ตรวจสอบว่าได้ข้อมูล user มาหรือไม่
    if (!is_array($authUser) || empty($authUser['id'])) {
        json_err("UNAUTHORIZED", "invalid_auth_user", 401);
    }

    $currentUserId = (int)$authUser['id'];

    /* ================= อ่าน body ================= */
    $raw  = file_get_contents("php://input");
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = [];
    }

    // ดึงค่าจาก body
    $treeId      = (int)($body['tree_id']      ?? 0);
    $careType    = trim((string)($body['care_type']    ?? ''));
    $careDateRaw = trim((string)($body['care_date']    ?? ''));
    $isReminder  = (bool)($body['is_reminder'] ?? false);
    $isDone      = (bool)($body['is_done']     ?? false);
    $note        = trim((string)($body['note'] ?? ''));

    // ✅ Debug Log
    error_log("=== CREATE CARE LOG ===");
    error_log("User ID: $currentUserId");
    error_log("Tree ID: $treeId");
    error_log("Care Type: $careType");
    error_log("Care Date: $careDateRaw");
    error_log("Is Reminder: " . ($isReminder ? 'Yes' : 'No'));
    error_log("Is Done: " . ($isDone ? 'Yes' : 'No'));
    error_log("Note length: " . strlen($note));
    error_log("Note content:\n$note");

    /* =============== validation ง่าย ๆ =============== */
    if ($treeId <= 0) {
        json_err("VALIDATION_ERROR", "invalid_tree_id", 422);
    }

    // care_type ให้ใช้แค่ 2 ค่านี้
    $allowedTypes = ['fertilizer', 'spray'];
    if ($careType === '' || !in_array($careType, $allowedTypes, true)) {
        json_err("VALIDATION_ERROR", "care_type must be 'fertilizer' or 'spray'", 422);
    }

    if ($careDateRaw === '') {
        json_err("VALIDATION_ERROR", "invalid_care_date", 422);
    }

    // แปลงวันที่ให้เป็นรูปแบบ Y-m-d
    $ts = strtotime($careDateRaw);
    if ($ts === false) {
        json_err("VALIDATION_ERROR", "invalid_care_date_format", 422);
    }
    $careDate = date('Y-m-d', $ts);

    // ✅ แยก note ออกเป็นส่วน ๆ เพื่อเก็บใน product_name, amount, unit, area
    $productName = null;
    $amount = null;
    $unit = null;
    $area = null;

    if (!empty($note)) {
        $lines = explode("\n", $note);
        $cleanNote = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // ตรวจหา pattern ต่าง ๆ (รองรับทั้งมีและไม่มี :)
            if (preg_match('/^พื้นที่ที่ใส่ปุ๋ย\s*:?\s*(.+)$/u', $line, $m)) {
                $area = trim($m[1]);
                error_log("✓ Found area: $area");
            } elseif (preg_match('/^ปริมาณปุ๋ยที่ใช้\s*:?\s*(.+)$/u', $line, $m)) {
                $amountText = trim($m[1]);
                // แยก ตัวเลข + หน่วย เช่น "10 กก." -> amount=10, unit=กก.
                if (preg_match('/^([\d.]+)\s*(.*)$/u', $amountText, $m2)) {
                    $amount = (float)$m2[1];
                    $unit = trim($m2[2]);
                    error_log("✓ Found fertilizer amount: $amount, unit: $unit");
                } else {
                    $amount = $amountText;
                    error_log("✓ Found fertilizer amount (text): $amount");
                }
            } elseif (preg_match('/^ปริมาณยาที่ใช้\s*:?\s*(.+)$/u', $line, $m)) {
                $amountText = trim($m[1]);
                if (preg_match('/^([\d.]+)\s*(.*)$/u', $amountText, $m2)) {
                    $amount = (float)$m2[1];
                    $unit = trim($m2[2]);
                    error_log("✓ Found spray amount: $amount, unit: $unit");
                } else {
                    $amount = $amountText;
                    error_log("✓ Found spray amount (text): $amount");
                }
            } elseif (preg_match('/^จำนวนต้นที่พ่น\s*:?\s*(.+)$/u', $line, $m)) {
                $area = trim($m[1]); // ใช้ area เก็บจำนวนต้น
                error_log("✓ Found tree count: $area");
            } else {
                // บรรทัดอื่น ๆ เก็บเป็น product_name หรือ note ทั่วไป
                $cleanNote[] = $line;
                error_log("→ Other line: $line");
            }
        }

        // ถ้ามีข้อความเหลือ ให้เก็บเป็น product_name
        if (!empty($cleanNote)) {
            $productName = implode("\n", $cleanNote);
            error_log("✓ Product name: $productName");
        }
    }

    // ✅ Debug: แสดงค่าสุดท้ายก่อน INSERT
    error_log("--- Final Values ---");
    error_log("product_name: " . ($productName ?? 'NULL'));
    error_log("amount: " . ($amount ?? 'NULL'));
    error_log("unit: " . ($unit ?? 'NULL'));
    error_log("area: " . ($area ?? 'NULL'));

    /* =============== INSERT -> care_logs =============== */
    $sql = "
        INSERT INTO care_logs
            (user_id, tree_id, care_type, care_date, is_reminder, is_done, note, product_name, amount, unit, area)
        VALUES
            (:user_id, :tree_id, :care_type, :care_date, :is_reminder, :is_done, :note, :product_name, :amount, :unit, :area)
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':user_id'      => $currentUserId,
        ':tree_id'      => $treeId,
        ':care_type'    => $careType,
        ':care_date'    => $careDate,
        ':is_reminder'  => $isReminder ? 1 : 0,
        ':is_done'      => $isDone ? 1 : 0,
        ':note'         => $note === '' ? null : $note,
        ':product_name' => $productName,
        ':amount'       => $amount,
        ':unit'         => $unit,
        ':area'         => $area,
    ]);

    $newId = (int)$dbh->lastInsertId();

    error_log("✅ INSERT SUCCESS! Log ID: $newId");
    error_log("=======================\n");

    // ส่งข้อมูลกลับให้ฝั่ง Flutter ใช้ต่อได้เลย
    json_ok([
        'log_id'       => $newId,
        'user_id'      => $currentUserId,
        'tree_id'      => $treeId,
        'care_type'    => $careType,
        'care_date'    => $careDate,
        'is_reminder'  => $isReminder ? 1 : 0,
        'is_done'      => $isDone ? 1 : 0,
        'note'         => $note === '' ? null : $note,
        'product_name' => $productName,
        'amount'       => $amount,
        'unit'         => $unit,
        'area'         => $area,
    ]);

} catch (Throwable $e) {
    error_log("❌ ERROR: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("=======================\n");
    json_err("SERVER_ERROR", "db_error: " . $e->getMessage(), 500);
}