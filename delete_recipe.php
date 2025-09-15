<?php
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';

/**
 * ลบสูตร (เน้น soft delete)
 * - ถ้ามีคอลัมน์ deleted_at: UPDATE deleted_at=NOW()
 * - ถ้าไม่มี: fallback เป็น DELETE (กันพังใน dev)
 * - เช็คสิทธิ์ RBAC: ต้องมี permission 'delete_recipes' หรือเป็น superadmin
 */

if (!can('delete_recipes')) {
  flash('คุณไม่มีสิทธิ์ลบสูตรอาหาร', 'danger');
  header('Location: ' . BASE_PATH . '/manage_recipes.php'); exit;
}

// helper เล็ก ๆ
function column_exists(mysqli $conn, string $table, string $column): bool {
  $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS 
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  return (int)($res['c'] ?? 0) > 0;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  flash('พารามิเตอร์ไม่ถูกต้อง', 'danger');
  header('Location: ' . BASE_PATH . '/manage_recipes.php'); exit;
}

if (column_exists($conn, 'recipe', 'deleted_at')) {
  $stmt = $conn->prepare("UPDATE recipe SET deleted_at=NOW() WHERE recipe_id=? LIMIT 1");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  flash('ซ่อนสูตรเรียบร้อยแล้ว');
} else {
  // กันพังถ้าสตีมายังไม่อัปเดต
  $stmt = $conn->prepare("DELETE FROM recipe WHERE recipe_id=? LIMIT 1");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  flash('ลบสูตรเรียบร้อยแล้ว (ลบจริงเนื่องจากสคีมาไม่มี deleted_at)');
}

header('Location: ' . BASE_PATH . '/manage_recipes.php'); 
exit;
