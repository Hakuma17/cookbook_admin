<?php
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';

$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'ban';
if ($id <= 0) { header('Location: ' . BASE_PATH . '/manage_users.php'); exit; }

if ($action === 'ban') {
  $stmt = $conn->prepare("UPDATE user SET is_banned=1 WHERE user_id=? AND deleted_at IS NULL LIMIT 1");
  $stmt->bind_param('i', $id); $stmt->execute();
} elseif ($action === 'unban') {
  $stmt = $conn->prepare("UPDATE user SET is_banned=0 WHERE user_id=? AND deleted_at IS NULL LIMIT 1");
  $stmt->bind_param('i', $id); $stmt->execute();
} elseif ($action === 'delete') {
  // soft delete
  $stmt = $conn->prepare("UPDATE user SET deleted_at=NOW() WHERE user_id=? LIMIT 1");
  $stmt->bind_param('i', $id); $stmt->execute();
}
header('Location: ' . BASE_PATH . '/manage_users.php'); exit;
