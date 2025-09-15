<?php
// tools/seed_admin.php
require_once __DIR__ . '/../includes/db.php';

// === ปรับได้ตามต้องการ ===
$name  = '';
$email = '';
$pass  = '';

// ตรวจว่ามีผู้ใช้อีเมลนี้หรือยัง
$chk = $conn->prepare("SELECT user_id FROM user WHERE email=? LIMIT 1");
$chk->bind_param('s', $email);
$chk->execute();
$exists = $chk->get_result()->fetch_assoc();

if ($exists) {
  echo "User already exists: {$email}\n";
  exit;
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

// helper เช็คคอลัมน์
function column_exists(mysqli $conn, string $table, string $col): bool {
  $s = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $s->bind_param('ss',$table,$col); $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}

// เตรียมคอลัมน์ที่จะใส่ ขึ้นกับสคีมาที่มีจริง
$cols   = ['profile_name','email','password'];
$params = [$name, $email, $hash];
$types  = 'sss';

if (column_exists($conn, 'user', 'is_verified')) { $cols[]='is_verified'; $params[] = 1; $types.='i'; }
if (column_exists($conn, 'user', 'is_admin'))    { $cols[]='is_admin';    $params[] = 1; $types.='i'; }
if (column_exists($conn, 'user', 'is_banned'))   { $cols[]='is_banned';   $params[] = 0; $types.='i'; }
if (column_exists($conn, 'user', 'created_at'))  { $cols[]='created_at';  $params[] = date('Y-m-d H:i:s'); $types.='s'; }

$placeholders = rtrim(str_repeat('?,', count($cols)), ',');
$sql = "INSERT INTO user (" . implode(',', $cols) . ") VALUES ({$placeholders})";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

echo "Created admin: {$email} / {$pass}\n";
