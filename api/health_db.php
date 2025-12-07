<?php
header('Content-Type: application/json; charset=utf-8');

$env = [
  'DB_HOST' => getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: null,
  'DB_PORT' => getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: null,
  'DB_NAME' => getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: null,
  'DB_USER' => getenv('DB_USER') ?: getenv('MYSQLUSER') ?: null,
  // อย่าแสดงรหัสผ่าน
];

$res = ['env'=>$env, 'can_connect'=>false, 'error'=>null];

try {
  $pdo = new PDO(
    "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'],
    getenv('DB_PASS') ?: getenv('MYSQLPASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
  $pdo->query('SELECT 1');
  $res['can_connect'] = true;
} catch (Throwable $e) {
  $res['error'] = $e->getMessage();
}

echo json_encode($res);