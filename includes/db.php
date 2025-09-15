<?php
/**
 * includes/db.php
 * ------------------------------------------------------------
 * - เชื่อมต่อฐานข้อมูล MySQL/MariaDB (MySQLi)
 * - ตั้ง charset เป็น utf8mb4
 * - เริ่ม session
 * - กำหนด BASE_PATH ใช้ลิงก์ในโปรเจกต์
 * ------------------------------------------------------------
 */

// ---------- [ปรับให้ตรงเครื่องคุณ] ----------
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'cookbookweb_db';

// กำหนด BASE_PATH จาก Document Root -> โปรเจกต์นี้
// ตัวอย่าง: http://localhost/cookbook_admin => '/cookbook_admin'
if (!defined('BASE_PATH')) {
  define('BASE_PATH', '/cookbook_admin');
}

// ---------- [เชื่อมต่อฐานข้อมูล] ----------
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_error) {
  // ขึ้น prod อย่าโชว์รายละเอียด connection error
  die('Database connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// ---------- [เริ่ม Session] ----------
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ---------- [Timezone] ----------
date_default_timezone_set('Asia/Bangkok');

// ---------- [Dev Tips] ----------
// ini_set('display_errors', 1);
// error_reporting(E_ALL);
