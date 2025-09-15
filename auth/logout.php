<?php
/**
 * auth/logout.php
 * - ล้าง session + ทำลาย cookie
 * - redirect กลับหน้า login โดยอิง BASE_PATH
 */
require_once __DIR__ . '/../includes/db.php'; // ให้มี BASE_PATH + session

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ล้างตัวแปรทั้งหมด
$_SESSION = [];

// ทำลาย session ฝั่งเซิร์ฟเวอร์ + ลบคุกกี้
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params['path'], $params['domain'],
    $params['secure'], $params['httponly']
  );
}
session_destroy();

// กลับหน้า Login
header('Location: ' . BASE_PATH . '/auth/login.php');
exit;
