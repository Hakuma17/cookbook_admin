<?php
// includes/helpers.php
if (session_status() === PHP_SESSION_NONE) session_start();

/** escape สั้น ๆ */
function e(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/** flash ข้อความ (Bootstrap alert) */
function flash(string $msg, string $type='success'): void { $_SESSION['__flash'] = [$msg, $type]; }

/** แสดงแล้วล้าง */
function flush_flash(): void {
  if (!empty($_SESSION['__flash'])) {
    [$m, $t] = $_SESSION['__flash']; unset($_SESSION['__flash']);
    echo '<div class="alert alert-'.e($t).' shadow-sm mb-3" role="alert">'.e($m).'</div>';
  }
}

/** ทางลัด: ตั้ง flash แล้ว redirect */
function redirect_with_flash(string $url, string $msg, string $type='success'): never {
  flash($msg, $type);
  header("Location: {$url}");
  exit;
}
