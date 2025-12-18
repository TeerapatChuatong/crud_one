<?php
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_err("METHOD_NOT_ALLOWED", "method_not_allowed", 405);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || empty($body)) $body = $_POST ?? [];

if (is_array($body) && count($body) === 1 && is_array(reset($body))) {
  $body = reset($body);
}

if (!function_exists('pick_value')) {
  function pick_value(array $src, array $keys): string {
    foreach ($keys as $k) {
      if (isset($src[$k]) && $src[$k] !== null && $src[$k] !== '') {
        return (string)$src[$k];
      }
    }
    return '';
  }
}

$username = trim(pick_value($body, ['username', 'name', 'user']));
$email    = strtolower(trim(pick_value($body, ['email', 'mail', 'email_address'])));
$password = (string)pick_value($body, ['password', 'pass', 'passwd', 'password1']);

$missing = [];
if ($username === '') $missing[] = 'username';
if ($email === '')    $missing[] = 'email';
if ($password === '') $missing[] = 'password';

if ($missing) json_err("VALIDATION_ERROR", "missing_fields: " . implode(',', $missing), 422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err("VALIDATION_ERROR", "invalid_email", 422);

try {
  $stmt = $dbh->prepare("
    SELECT user_id
    FROM `user`
    WHERE email = :email OR username = :username
    LIMIT 1
  ");
  $stmt->execute([':email' => $email, ':username' => $username]);

  if ($stmt->fetch()) json_err("DUPLICATE", "user_exists", 409);

  $hash = password_hash($password, PASSWORD_DEFAULT);

  $stmt = $dbh->prepare("
    INSERT INTO `user` (username, email, password_hash, role)
    VALUES (:username, :email, :password_hash, 'user')
  ");
  $stmt->execute([
    ':username'      => $username,
    ':email'         => $email,
    ':password_hash' => $hash,
  ]);

  $uid = (int)$dbh->lastInsertId();

  $_SESSION['user_id']  = $uid;
  $_SESSION['role']     = 'user';
  $_SESSION['username'] = $username;
  $_SESSION['email']    = $email;

  $token = session_id();

  json_ok([
    "id"       => $uid,
    "username" => $username,
    "email"    => $email,
    "role"     => "user",
    "token"    => $token,
  ]);

} catch (Throwable $e) {
  json_err("DB_ERROR", "db_error", 500);
}
