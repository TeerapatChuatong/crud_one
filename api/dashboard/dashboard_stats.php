<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// --- CORS (รองรับ Vite dev server) ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowOrigins = [
  'http://localhost:5173',
  'http://127.0.0.1:5173',
  'http://localhost',
  'http://127.0.0.1',
];
if ($origin && in_array($origin, $allowOrigins, true)) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  exit;
}

function json_ok(array $payload): void {
  echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function json_err(string $code, string $message, int $http = 400): void {
  http_response_code($http);
  echo json_encode(['ok' => false, 'error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// --- Auth (ถ้ามี) ---
$requireAuthPath = __DIR__ . '/../auth/require_auth.php';
if (file_exists($requireAuthPath)) {
  require_once $requireAuthPath;
}
if (function_exists('require_auth')) {
  $authUser = require_auth();
  if (!$authUser) {
    json_err('UNAUTHORIZED', 'กรุณาเข้าสู่ระบบก่อน', 401);
  }
  $role = $authUser['role'] ?? '';
  if (!in_array($role, ['admin', 'super admin'], true)) {
    json_err('FORBIDDEN', 'ไม่มีสิทธิ์เข้าถึง', 403);
  }
}

// --- DB connect (อย่าไปทับ $pdo/$conn ที่มาจาก db.php) ---
$dbPath = __DIR__ . '/../db.php';
$healthDbPath = __DIR__ . '/../health_db.php';

if (file_exists($dbPath)) {
  require_once $dbPath;
}
if (file_exists($healthDbPath)) {
  // กัน health_db.php เผลอ echo/print ออกมา ทำให้ JSON ปนกัน
  ob_start();
  require_once $healthDbPath;
  ob_end_clean();
}

$pdoConn = null;
$mysqliConn = null;

// ✅ พยายามจับตัวแปรที่มีอยู่จริงจากโปรเจกต์
if (isset($pdo) && $pdo instanceof PDO) $pdoConn = $pdo;
elseif (isset($db) && $db instanceof PDO) $pdoConn = $db;
elseif (isset($health_pdo) && $health_pdo instanceof PDO) $pdoConn = $health_pdo;

if (isset($conn) && $conn instanceof mysqli) $mysqliConn = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $mysqliConn = $mysqli;

if (!$pdoConn && !$mysqliConn) {
  json_err('DB_CONNECT_FAILED', 'ไม่พบการเชื่อมต่อฐานข้อมูลจาก db.php', 500);
}

try {
  // ------------------------------
  // PDO path
  // ------------------------------
  if ($pdoConn) {
    /** @var PDO $pdoConn */

    // Totals
    $totals = [
      'users'     => (int)$pdoConn->query("SELECT COUNT(*) FROM `user`")->fetchColumn(),
      'questions' => (int)$pdoConn->query("SELECT COUNT(*) FROM `questions`")->fetchColumn(),
      'answers'   => (int)$pdoConn->query("SELECT COUNT(*) FROM `choices`")->fetchColumn(),
      'diseases'  => (int)$pdoConn->query("SELECT COUNT(*) FROM `diseases`")->fetchColumn(),
      'chemicals' => (int)$pdoConn->query("SELECT COUNT(*) FROM `chemicals`")->fetchColumn(),
    ];

    // Activity last 7 days (diagnosis_history)
    $stmt = $pdoConn->prepare("
      SELECT DATE(diagnosed_at) AS d, COUNT(*) AS c
      FROM diagnosis_history
      WHERE diagnosed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      GROUP BY DATE(diagnosed_at)
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
      $map[$r['d']] = (int)$r['c'];
    }
    $activity = [];
    for ($i = 6; $i >= 0; $i--) {
      $day = date('Y-m-d', strtotime("-{$i} day"));
      $activity[] = ['date' => $day, 'count' => ($map[$day] ?? 0)];
    }

    // Distribution (this month) — ให้ key ตรงกับหน้า AdminHomePage.jsx (count/disease_id/label)
    $month = date('Y-m');
    $stmt = $pdoConn->prepare("
      SELECT dh.disease_id, d.disease_th AS label, COUNT(*) AS cnt
      FROM diagnosis_history dh
      LEFT JOIN diseases d ON d.disease_id = dh.disease_id
      WHERE DATE_FORMAT(dh.diagnosed_at, '%Y-%m') = :m
      GROUP BY dh.disease_id, d.disease_th
      ORDER BY cnt DESC
    ");
    $stmt->execute([':m' => $month]);
    $distRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $distribution = [
      'month' => $month,
      'items' => array_map(static function ($r) {
        return [
          'disease_id' => (int)($r['disease_id'] ?? 0),
          'label'      => (string)($r['label'] ?? ''),
          'count'      => (int)($r['cnt'] ?? 0),
        ];
      }, $distRows),
    ];

    // Recent activity — ให้ key ตรงกับหน้า AdminHomePage.jsx (action/detail/at/status)
    $stmt = $pdoConn->query("
      SELECT dh.diagnosed_at AS at, u.username, ot.tree_name, d.disease_th
      FROM diagnosis_history dh
      LEFT JOIN `user` u ON u.user_id = dh.user_id
      LEFT JOIN orange_trees ot ON ot.tree_id = dh.tree_id
      LEFT JOIN diseases d ON d.disease_id = dh.disease_id
      ORDER BY dh.diagnosed_at DESC
      LIMIT 8
    ");
    $recentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recent = array_map(static function ($r) {
      $user = $r['username'] ?? '';
      $tree = $r['tree_name'] ?? '';
      $dis  = $r['disease_th'] ?? '';

      $detail = trim(
        ($user ? "ผู้ใช้: {$user}" : '') .
        ($tree ? " • ต้น: {$tree}" : '') .
        ($dis  ? " • โรค: {$dis}" : '')
      );

      return [
        'action' => 'วินิจฉัย',
        'detail' => $detail,
        'at'     => $r['at'] ?? null,
        'status' => 'done',
      ];
    }, $recentRows);

    json_ok([
      'totals'              => $totals,
      'activity_last_7_days'=> $activity,
      'distribution_month'  => $distribution,
      'recent_activity'     => $recent,
    ]);
  }

  // ------------------------------
  // mysqli path
  // ------------------------------
  /** @var mysqli $mysqliConn */
  $m = $mysqliConn;

  $scalar = static function (mysqli $m, string $sql): int {
    $res = $m->query($sql);
    if (!$res) throw new RuntimeException($m->error);
    $row = $res->fetch_row();
    return (int)($row[0] ?? 0);
  };

  $totals = [
    'users'     => $scalar($m, "SELECT COUNT(*) FROM `user`"),
    'questions' => $scalar($m, "SELECT COUNT(*) FROM `questions`"),
    'answers'   => $scalar($m, "SELECT COUNT(*) FROM `choices`"),
    'diseases'  => $scalar($m, "SELECT COUNT(*) FROM `diseases`"),
    'chemicals' => $scalar($m, "SELECT COUNT(*) FROM `chemicals`"),
  ];

  $res = $m->query("
    SELECT DATE(diagnosed_at) AS d, COUNT(*) AS c
    FROM diagnosis_history
    WHERE diagnosed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(diagnosed_at)
  ");
  if (!$res) throw new RuntimeException($m->error);

  $map = [];
  while ($r = $res->fetch_assoc()) {
    $map[$r['d']] = (int)$r['c'];
  }

  $activity = [];
  for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} day"));
    $activity[] = ['date' => $day, 'count' => ($map[$day] ?? 0)];
  }

  $month = date('Y-m');
  $stmt = $m->prepare("
    SELECT dh.disease_id, d.disease_th AS label, COUNT(*) AS cnt
    FROM diagnosis_history dh
    LEFT JOIN diseases d ON d.disease_id = dh.disease_id
    WHERE DATE_FORMAT(dh.diagnosed_at, '%Y-%m') = ?
    GROUP BY dh.disease_id, d.disease_th
    ORDER BY cnt DESC
  ");
  if (!$stmt) throw new RuntimeException($m->error);
  $stmt->bind_param('s', $month);
  if (!$stmt->execute()) throw new RuntimeException($stmt->error);
  $distRes = $stmt->get_result();

  $items = [];
  while ($r = $distRes->fetch_assoc()) {
    $items[] = [
      'disease_id' => (int)($r['disease_id'] ?? 0),
      'label'      => (string)($r['label'] ?? ''),
      'count'      => (int)($r['cnt'] ?? 0),
    ];
  }

  $distribution = [
    'month' => $month,
    'items' => $items,
  ];

  $recentRes = $m->query("
    SELECT dh.diagnosed_at AS at, u.username, ot.tree_name, d.disease_th
    FROM diagnosis_history dh
    LEFT JOIN `user` u ON u.user_id = dh.user_id
    LEFT JOIN orange_trees ot ON ot.tree_id = dh.tree_id
    LEFT JOIN diseases d ON d.disease_id = dh.disease_id
    ORDER BY dh.diagnosed_at DESC
    LIMIT 8
  ");
  if (!$recentRes) throw new RuntimeException($m->error);

  $recent = [];
  while ($r = $recentRes->fetch_assoc()) {
    $user = $r['username'] ?? '';
    $tree = $r['tree_name'] ?? '';
    $dis  = $r['disease_th'] ?? '';

    $detail = trim(
      ($user ? "ผู้ใช้: {$user}" : '') .
      ($tree ? " • ต้น: {$tree}" : '') .
      ($dis  ? " • โรค: {$dis}" : '')
    );

    $recent[] = [
      'action' => 'วินิจฉัย',
      'detail' => $detail,
      'at'     => $r['at'] ?? null,
      'status' => 'done',
    ];
  }

  json_ok([
    'totals'               => $totals,
    'activity_last_7_days' => $activity,
    'distribution_month'   => $distribution,
    'recent_activity'      => $recent,
  ]);

} catch (Throwable $e) {
  json_err('SERVER_ERROR', $e->getMessage(), 500);
}
