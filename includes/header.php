<?php
/**
 * includes/header.php
 * ---------------------------------------------
 * <head> + Navbar (โลโก้/เมนูตาม RBAC/ค้นหาเร็ว/ผู้ใช้) + เปิด .container
 * - ใช้ธีมเดียวกับ style.css (โทนอุ่น)
 * - แสดง active menu อัตโนมัติ
 * - ช็อตคัต: "/" โฟกัสค้นหา, g r/i/u/v/a ไปยังเมนูหลัก
 * ---------------------------------------------
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/rbac.php';

$isLogged   = !empty($_SESSION['admin_loggedin']);
$profile    = $_SESSION['profile_name'] ?? 'Admin';
$initial    = mb_strtoupper(mb_substr($profile, 0, 1, 'UTF-8'));
$here       = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$logoUrl    = BASE_PATH . '/assets/img/app-logo.png';  // โลโก้จากพาธที่ให้มา
$brandTitle = 'Cookbook Admin';

function is_active(string $file, string $here): string {
  return strcasecmp($file, $here) === 0 ? 'active' : '';
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($brandTitle) ?></title>

  <!-- Bootstrap & main theme -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">

  <!-- Favicon / PWA basics -->
  <link rel="icon" type="image/png" href="<?= $logoUrl ?>">
  <link rel="apple-touch-icon" href="<?= $logoUrl ?>">
  <meta name="theme-color" content="#7B4B3A">
</head>
<body>

<!-- Navbar: โทนสว่าง, กลมมน, เงาเบา -->
<nav class="navbar navbar-expand-lg bg-white border-bottom shadow-sm">
  <div class="container-fluid">
    <!-- Brand -->
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE_PATH ?>/index.php" style="font-weight:700;">
      <img src="<?= $logoUrl ?>" alt="logo" width="32" height="32" class="rounded-3 shadow-sm" />
      <span class="text-gradient"><?= htmlspecialchars($brandTitle) ?></span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#cbNavbar" aria-controls="cbNavbar" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="cbNavbar">
      <!-- Left nav -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($isLogged): ?>
          <li class="nav-item">
            <a class="nav-link <?= is_active('index.php', $here) ?>" href="<?= BASE_PATH ?>/index.php">
              <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
          </li>

          <?php if (can_any(['view_recipes','edit_recipes'])): ?>
            <li class="nav-item">
              <a class="nav-link <?= is_active('manage_recipes.php', $here) ?>" href="<?= BASE_PATH ?>/manage_recipes.php">
                <i class="bi bi-book me-1"></i>Recipes
              </a>
            </li>
          <?php endif; ?>

          <?php if (can_any(['view_ingredients','edit_ingredients'])): ?>
            <li class="nav-item">
              <a class="nav-link <?= is_active('manage_ingredients.php', $here) ?>" href="<?= BASE_PATH ?>/manage_ingredients.php">
                <i class="bi bi-carrot me-1"></i>Ingredients
              </a>
            </li>
          <?php endif; ?>

          <?php if (can_any(['view_users','edit_users'])): ?>
            <li class="nav-item">
              <a class="nav-link <?= is_active('manage_users.php', $here) ?>" href="<?= BASE_PATH ?>/manage_users.php">
                <i class="bi bi-people me-1"></i>Users
              </a>
            </li>
          <?php endif; ?>

          <?php if (can('moderate_reviews')): ?>
            <li class="nav-item">
              <a class="nav-link <?= is_active('manage_reviews.php', $here) ?>" href="<?= BASE_PATH ?>/manage_reviews.php">
                <i class="bi bi-star me-1"></i>Reviews
              </a>
            </li>
          <?php endif; ?>

          <?php if (function_exists('can') && can('view_audit')): ?>
            <li class="nav-item">
              <a class="nav-link <?= is_active('manage_audit.php', $here) ?>" href="<?= BASE_PATH ?>/manage_audit.php">
                <i class="bi bi-shield-check me-1"></i>Audit
              </a>
            </li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>

      <!-- Quick search (เริ่มที่ Recipes; ใช้ช็อตคัต "/" เพื่อโฟกัส) -->
      <?php if ($isLogged): ?>
      <form class="d-flex me-3" role="search" onsubmit="return CB.quickSearchSubmit(event)">
        <div class="input-group">
          <span class="input-group-text bg-white border-end-0">
            <i class="bi bi-search text-muted"></i>
          </span>
          <input id="cbQuickSearch" class="form-control border-start-0" type="search" placeholder="ค้นหาเมนู/วัตถุดิบ (กด / เพื่อค้นหา)" aria-label="Search">
        </div>
      </form>
      <?php endif; ?>

      <!-- Right: user dropdown / login -->
      <ul class="navbar-nav mb-2 mb-lg-0">
        <?php if ($isLogged): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <span class="d-inline-flex justify-content-center align-items-center rounded-circle border" style="width:28px;height:28px;background:#F5E9E3;border-color:#E3D6CF;"><?= htmlspecialchars($initial) ?></span>
              <span class="d-none d-sm-inline"><?= htmlspecialchars($profile) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg">
              <li class="dropdown-header small text-muted">
                <i class="bi bi-person-check me-1"></i>ลงชื่อเข้าใช้แล้ว
              </li>
              <li><a class="dropdown-item" href="<?= BASE_PATH ?>/index.php">
                <i class="bi bi-speedometer2 me-2"></i>ไปยัง Dashboard
              </a></li>
              <?php if (function_exists('can') && can('view_audit')): ?>
                <li><a class="dropdown-item" href="<?= BASE_PATH ?>/manage_audit.php">
                  <i class="bi bi-shield-check me-2"></i>ดูบันทึกการใช้งาน
                </a></li>
              <?php endif; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="<?= BASE_PATH ?>/auth/logout.php">
                <i class="bi bi-box-arrow-right me-2"></i>Logout
              </a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_PATH ?>/auth/login.php">Login</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<div class="container mt-4">
<?php flush_flash(); // flash message ด้านบนเนื้อหา ?>

<!-- tiny helpers / shortcuts -->
<script>
  // เนมสเปซเล็ก ๆ กันชนกับโค้ดอื่น
  window.CB = window.CB || {};
  // คีย์ลัด: "/" โฟกัสค้นหา, g r/i/u/v/a ไปหน้าเมนูหลัก
  (function () {
    document.addEventListener('keydown', function (e) {
      if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        const q = document.getElementById('cbQuickSearch');
        if (q) { e.preventDefault(); q.focus(); q.select(); }
      }
      if (e.key.toLowerCase() === 'g') { CB._gPressed = true; return; }
      if (CB._gPressed) {
        const map = { r: 'manage_recipes.php', i: 'manage_ingredients.php', u: 'manage_users.php', v: 'manage_reviews.php', a: 'manage_audit.php' };
        const target = map[e.key.toLowerCase()];
        if (target) { window.location.href = '<?= BASE_PATH ?>/' + target; }
        CB._gPressed = false;
      }
    }, false);
    document.addEventListener('keyup', () => { CB._gPressed = false; });
  })();

  // ค้นหาเร็ว: ส่งคำค้นไปหน้า Recipes ก่อน (กด Enter)
  CB.quickSearchSubmit = function (ev) {
    ev.preventDefault();
    const q = document.getElementById('cbQuickSearch')?.value?.trim() || '';
    if (!q) return false;
    // prefix ช่วยเลือกปลายทาง: @i = ingredients, @u = users, @v = reviews
    let to = 'manage_recipes.php?q=' + encodeURIComponent(q);
    if (q.startsWith('@i ')) to = 'manage_ingredients.php?q=' + encodeURIComponent(q.slice(3));
    if (q.startsWith('@u ')) to = 'manage_users.php?q=' + encodeURIComponent(q.slice(3));
    if (q.startsWith('@v ')) to = 'manage_reviews.php?q=' + encodeURIComponent(q.slice(3));
    window.location.href = '<?= BASE_PATH ?>/' + to;
    return false;
  };
</script>

<!-- Bootstrap bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
