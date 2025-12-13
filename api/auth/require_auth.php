<?php
/**
 * require_auth.php
 *
 * ใช้สำหรับ endpoint ที่ต้องล็อกอิน
 * - Flutter: ส่ง token ผ่าน header Authorization: Bearer <token>
 * - React/Web: ใช้ cookie PHPSESSID ตามเดิม
 * 
 * @return array ข้อมูลผู้ใช้ ['id' => ..., 'role' => ...]
 */

function require_auth() {
    // 1) ดึง Authorization header (รองรับหลาย server)
    $rawAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? '');

    if (!$rawAuth && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $rawAuth = $headers['Authorization'];
        }
    }

    $token = null;
    if ($rawAuth && preg_match('/Bearer\s+(\S+)/i', $rawAuth, $m)) {
        $token = $m[1];
    }

    // ✅ Debug
    error_log("=== REQUIRE_AUTH DEBUG ===");
    error_log("Authorization header: " . ($rawAuth ?: 'NONE'));
    error_log("Extracted token: " . ($token ?: 'NONE'));

    // 2) ถ้ามี token → ใช้ token เป็น session_id
    if ($token) {
        // ปิด session เดิม (ถ้ามี) ก่อน
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        // ตั้ง session_id จาก token แล้ว start ใหม่
        $token = substr($token, 0, 128); // กันยาวเกิน
        session_id($token);
        session_start();
        
        error_log("Started session with token: " . session_id());
    } else {
        // ไม่มี token → ใช้ session ปกติ (สำหรับ web)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        error_log("Using regular session: " . session_id());
    }

    // Debug session data
    error_log("Session data: " . print_r($_SESSION, true));

    // 3) ตรวจสอบว่ามี user_id ใน session หรือไม่
    if (empty($_SESSION['user_id'])) {
        error_log("UNAUTHORIZED: No user_id in session");
        error_log("==========================");
        
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'code' => 'UNAUTHORIZED',
            'message' => 'กรุณาเข้าสู่ระบบก่อน'
        ]);
        exit;
    }

    error_log("AUTH SUCCESS: user_id = " . $_SESSION['user_id']);
    error_log("==========================");

    // 4) return ข้อมูลผู้ใช้
    require_once __DIR__ . '/../db.php';
    
    return [
        'id'       => $_SESSION['user_id'] ?? null,
        'role'     => $_SESSION['role']    ?? 'user',
        'username' => $_SESSION['username'] ?? null,
        'email'    => $_SESSION['email']    ?? null,
    ];
}

// เรียกใช้ function และ return ข้อมูล
return require_auth();