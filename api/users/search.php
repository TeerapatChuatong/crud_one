<?php
// api/users/search.php

// ---- CORS & JSON ----
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit();
}

// Method guard
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'method_not_allowed']);
  exit();
}

require_once __DIR__ . '/../db.php';
// รองรับทั้ง $pdo/$dbh
if (!isset($dbh) && isset($pdo)) { $dbh = $pdo; }

try {
  $rawBody  = file_get_contents("php://input");
  $jsonBody = json_decode($rawBody, true) ?: [];

  // ===== รับ id =====
  $id = null;
  if (isset($_GET['id']) && $_GET['id'] !== '') {
    $id = trim($_GET['id']);
  } elseif (isset($jsonBody['id']) && $jsonBody['id'] !== '') {
    $id = trim($jsonBody['id']);
  }

  // ===== รับ keyword =====
  $keyword = '';
  if (isset($_GET['keyword']) && $_GET['keyword'] !== '') {
    $keyword = trim($_GET['keyword']);
  } elseif (isset($jsonBody['keyword']) && $jsonBody['keyword'] !== '') {
    $keyword = trim($jsonBody['keyword']);
  }

  // =========================
  // เคส 1: ค้นด้วย id อย่างเดียว
  // =========================
  if ($id !== null && $keyword === '') {
    if (!ctype_digit((string)$id)) {
      http_response_code(400);
      echo json_encode(['status' => 'error', 'message' => 'invalid_id']);
      exit();
    }

    $stmt = $dbh->prepare("
      SELECT id, username, email, role
      FROM user
      WHERE id = :id
    ");
    $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $data = [];
    if ($row) {
      $data[] = [
        'id'       => (int)$row['id'],
        'username' => $row['username'],
        'email'    => $row['email'] ?? null,
        'role'     => $row['role'] ?? null,
      ];
    }

    echo json_encode(['status' => 'ok', 'data' => $data], JSON_UNESCAPED_UNICODE);
    $dbh = null;
    exit();
  }

  // =========================
  // เคส 2: มี keyword → search
  // =========================
  if ($keyword !== '') {
    $isNumeric = ctype_digit($keyword);

    $conds = [];

    // ถ้า keyword เป็นตัวเลขล้วน ให้ลองเทียบ id ตรงด้วย
    if ($isNumeric) {
      $conds[] = "id = :id_eq";
    }

    // ค้นจาก username, email
    $conds[] = "(username LIKE :kw OR email LIKE :kw)";

    $sql = "
      SELECT id, username, email, role
      FROM user
      WHERE " . implode(" OR ", $conds) . "
      ORDER BY id ASC
    ";

    $stmt = $dbh->prepare($sql);

    if ($isNumeric) {
      $stmt->bindValue(':id_eq', (int)$keyword, PDO::PARAM_INT);
    }

    $like = '%' . $keyword . '%';
    $stmt->bindValue(':kw', $like, PDO::PARAM_STR);

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(function ($r) {
      return [
        'id'       => (int)$r['id'],
        'username' => $r['username'],
        'email'    => $r['email'] ?? null,
        'role'     => $r['role'] ?? null,
      ];
    }, $rows);

    echo json_encode(['status' => 'ok', 'data' => $data], JSON_UNESCAPED_UNICODE);
    $dbh = null;
    exit();
  }

  // =========================
  // เคส 3: ไม่ส่ง id / keyword → คืนทั้งหมด
  // =========================
  $stmt = $dbh->prepare("
    SELECT id, username, email, role
    FROM user
    ORDER BY id DESC
  ");
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $data = array_map(function ($r) {
    return [
      'id'       => (int)$r['id'],
      'username' => $r['username'],
      'email'    => $r['email'] ?? null,
      'role'     => $r['role'] ?? null,
    ];
  }, $rows);

  echo json_encode(['status' => 'ok', 'data' => $data], JSON_UNESCAPED_UNICODE);
  $dbh = null;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'status'  => 'error',
    'message' => 'server_error',
    // 'debug' => $e->getMessage(), // ถ้าอยากดู error จริง เปิดบรรทัดนี้ชั่วคราวได้
  ], JSON_UNESCAPED_UNICODE);
  exit();
}
