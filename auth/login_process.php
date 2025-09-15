<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
csrf_verify();

/** redirect พร้อม error */
function fail_redirect(string $message, string $email = '', string $next = ''): never {
  $q = http_build_query(['error'=>$message, 'email'=>$email, 'next'=>$next]);
  header("Location: " . BASE_PATH . "/auth/login.php?{$q}");
  exit;
}

/** ทำให้ next ปลอดภัย: อนุญาตเฉพาะ path ภายใน BASE_PATH */
function safe_next(?string $next): string {
  $next = (string)($next ?? '');
  if ($next === '') return '';
  $path = parse_url($next, PHP_URL_PATH) ?? '';
  if ($path !== '' && str_starts_with($path, BASE_PATH)) {
    $qs = parse_url($next, PHP_URL_QUERY);
    return $path . ($qs ? "?{$qs}" : '');
  }
  return ''; // invalid -> drop
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail_redirect('Method not allowed.');

$email = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');
$next = safe_next($_POST['next'] ?? '');

if ($email === '' || $password === '') fail_redirect('กรุณากรอกอีเมลและรหัสผ่าน', $email, $next);

/** เส้นทาง 1: RBAC ผ่าน admin_users */
$stmt = $conn->prepare("SELECT admin_id, profile_name, password, is_active FROM admin_users WHERE email=? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$adm = $stmt->get_result()->fetch_assoc();

if ($adm && (int)$adm['is_active'] === 1 && password_verify($password, $adm['password'])) {
  session_regenerate_id(true);
  $_SESSION['admin_loggedin'] = true;
  $_SESSION['admin_mode']     = 'rbac';
  $_SESSION['admin_id']       = (int)$adm['admin_id'];
  $_SESSION['profile_name']   = $adm['profile_name'];
  $_SESSION['admin_email']    = $email;

  // โหลด permissions
  $permSql = "
    SELECT p.slug
    FROM admin_user_role aur
    JOIN role_permission rp ON rp.role_id = aur.role_id
    JOIN permissions p ON p.permission_id = rp.permission_id
    WHERE aur.admin_id = ?
  ";
  $pstmt = $conn->prepare($permSql);
  $pstmt->bind_param('i', $_SESSION['admin_id']);
  $pstmt->execute();
  $perms = [];
  $res = $pstmt->get_result();
  while ($row = $res->fetch_assoc()) $perms[] = $row['slug'];
  $_SESSION['perms'] = array_values(array_unique($perms));

  // superadmin?
  $chk = $conn->prepare("SELECT 1 FROM admin_user_role aur JOIN roles r ON r.role_id=aur.role_id WHERE aur.admin_id=? AND r.slug='superadmin' LIMIT 1");
  $chk->bind_param('i', $_SESSION['admin_id']);
  $chk->execute();
  $_SESSION['is_superadmin'] = $chk->get_result()->num_rows === 1;

  // last_login_at
  $u = $conn->prepare("UPDATE admin_users SET last_login_at=NOW() WHERE admin_id=?");
  $u->bind_param('i', $_SESSION['admin_id']);
  $u->execute();

  $dest = $next ?: (BASE_PATH . '/index.php');
  header("Location: {$dest}");
  exit;
}

/** เส้นทาง 2: user.is_admin (ระยะสั้น) */
$stmt = $conn->prepare("SELECT user_id, profile_name, password, is_verified, is_admin, is_banned FROM user WHERE email=? AND (deleted_at IS NULL OR deleted_at IS NULL) LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$usr = $stmt->get_result()->fetch_assoc();

if ($usr && (int)$usr['is_verified'] === 1 && (int)$usr['is_admin'] === 1 && (int)$usr['is_banned'] === 0 && password_verify($password, $usr['password'])) {
  session_regenerate_id(true);
  $_SESSION['admin_loggedin'] = true;
  $_SESSION['admin_mode']     = 'user_is_admin';
  $_SESSION['user_id']        = (int)$usr['user_id'];
  $_SESSION['profile_name']   = $usr['profile_name'] ?? '';
  $_SESSION['admin_email']    = $email;
  $_SESSION['is_superadmin']  = 1;   // ชั่วคราว
  $_SESSION['perms']          = [];

  $u = $conn->prepare("UPDATE user SET last_login_at=NOW() WHERE user_id=?");
  $u->bind_param('i', $_SESSION['user_id']);
  $u->execute();

  $dest = $next ?: (BASE_PATH . '/index.php');
  header("Location: {$dest}");
  exit;
}

fail_redirect('อีเมล/รหัสผ่านไม่ถูกต้อง หรือบัญชีไม่มีสิทธิ์แอดมิน', $email, $next);
