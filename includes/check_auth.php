<?php
/**
 * includes/check_auth.php
 * - ใช้ include ที่บนสุดของทุกหน้าแอดมิน (ยกเว้น login/logout)
 * - ถ้าไม่ล็อกอิน => redirect ไปหน้า login พร้อม ?next=...
 */
require_once __DIR__ . '/db.php'; // เริ่ม session + BASE_PATH

if (!empty($_SESSION['admin_loggedin'])) {
  return; // ผ่าน!
}

$reqUri   = $_SERVER['REQUEST_URI'] ?? '';
$loginUrl = BASE_PATH . '/auth/login.php?next=' . urlencode($reqUri);
header("Location: {$loginUrl}");
exit;
