<?php
/**
 * require_auth.php
 *
 * ใช้สำหรับ endpoint ฝั่ง Flutter
 * - รองรับ token ที่ส่งมาใน header: Authorization: Bearer <token>
 * - ถ้ามี token → ใช้ token เป็น session_id แล้วค่อย include db.php
 * - แล้วเรียก require_login() เพื่อเช็คว่ามี user_id ใน session จริงไหม
 * - ถ้าผ่าน → จะได้ตัวแปร $AUTH_USER_ID, $AUTH_USER_ROLE ให้ใช้ในไฟล์นั้น ๆ
 */

// 1) ดึง token จาก Authorization header (ถ้ามี)
$rawAuth  = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token    = null;

if ($rawAuth) {
    if (preg_match('/Bearer\s+(\S+)/i', $rawAuth, $m)) {
        $token = $m[1];
    }
}

// 2) ถ้ามี token และยังไม่ได้ start session -> ใช้ token เป็น session id
//    **สำคัญ**: ต้องทำก่อน require db.php (ซึ่งมี session_start())
if ($token && session_status() === PHP_SESSION_NONE) {
    // กัน token แปลก ๆ ยาวเกินไป (ไม่บังคับมาก แค่กันหลุด)
    $token = substr($token, 0, 128);
    session_id($token);
}

// 3) include db.php (จะ session_start() ตามปกติ)
require_once __DIR__ . '/../db.php';

// 4) บังคับให้ต้องล็อกอิน (ใช้ session เดิม หรือจาก token)
require_login();

// 5) ตั้งตัวแปรให้ไฟล์อื่นใช้ง่าย ๆ
$AUTH_USER_ID   = $_SESSION['user_id'] ?? null;
$AUTH_USER_ROLE = $_SESSION['role']    ?? 'user';
