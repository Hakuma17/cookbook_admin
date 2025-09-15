<?php
// ใช้ในฟอร์ม:  echo csrf_field();
// ใช้ใน handler: csrf_verify();

if (session_status() === PHP_SESSION_NONE) session_start();

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_field(): string {
  return '<input type="hidden" name="_token" value="' .
         htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(): void {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = $_POST['_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
      http_response_code(419);
      die('CSRF token mismatch');
    }
  }
}
