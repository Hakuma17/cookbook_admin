<?php
/**
 * includes/rbac.php
 * ใช้ร่วมกับ session ที่ตั้งใน login_process.php
 * ฟังก์ชัน:
 *  - can($perm): มีสิทธิ์ permission นั้นหรือไม่
 *  - is_superadmin(): เป็น superadmin หรือไม่
 *  - can_any([...]): มีสิทธิ์อย่างน้อยหนึ่งในรายการ
 *  - require_can($perm): ถ้าไม่มีก็ตัดกลับพร้อม flash
 */
if (session_status() === PHP_SESSION_NONE) session_start();

function is_superadmin(): bool {
  return !empty($_SESSION['is_superadmin']);
}

function can(string $perm): bool {
  if (is_superadmin()) return true;
  $perms = $_SESSION['perms'] ?? [];
  return in_array($perm, $perms, true);
}

function can_any(array $perms): bool {
  if (is_superadmin()) return true;
  $owned = $_SESSION['perms'] ?? [];
  foreach ($perms as $p) {
    if (in_array($p, $owned, true)) return true;
  }
  return false;
}

function require_can(string $perm): void {
  if (!can($perm)) {
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/db.php';
    flash('คุณไม่มีสิทธิ์เข้าถึงหน้าดังกล่าว', 'danger');
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
  }
}
