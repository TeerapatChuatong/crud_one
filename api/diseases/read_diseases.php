<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_err("METHOD_NOT_ALLOWED", "get_only", 405);
}

try {
  // พยายามดึงรูปตัวอย่างของโรค (ถ้ามีตาราง disease_images) เพื่อให้ฝั่งแอปแสดงรูปได้
  // - ถ้า query นี้ใช้ไม่ได้ (เช่น ไม่มีตาราง/คอลัมน์) จะ fallback กลับไปแบบเดิม

  try {
    $sql = "SELECT d.*, (
              SELECT di.image_url
              FROM disease_images di
              WHERE di.disease_id = d.disease_id
              ORDER BY di.id ASC
              LIMIT 1
            ) AS disease_image_url
            FROM diseases d
            ORDER BY CAST(d.disease_id AS UNSIGNED) ASC";
    $st = $dbh->query($sql);
    json_ok($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
  } catch (Throwable $e) {
    // ignore and fallback
  }

  try {
    $sql = "SELECT d.*, (
              SELECT di.image_path
              FROM disease_images di
              WHERE di.disease_id = d.disease_id
              ORDER BY di.id ASC
              LIMIT 1
            ) AS disease_image_url
            FROM diseases d
            ORDER BY CAST(d.disease_id AS UNSIGNED) ASC";
    $st = $dbh->query($sql);
    json_ok($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
  } catch (Throwable $e) {
    // ignore and fallback
  }

  $st = $dbh->query("SELECT * FROM diseases ORDER BY CAST(disease_id AS UNSIGNED) ASC");
  json_ok($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  json_err("DB_ERROR","db_error",500);
}
