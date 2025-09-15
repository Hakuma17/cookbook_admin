<?php
/**
 * auth/login.php (themed)
 * - โทนมินิมอลให้เข้ากับแอป: เบจ/น้ำตาล, มุมมน, เงานุ่ม
 * - แสดงโลโก้แอป, ปุ่มโชว์/ซ่อนรหัสผ่าน, กันกดซ้ำ
 * - ใช้ BASE_PATH + CSRF
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!empty($_SESSION['admin_loggedin'])) {
  header('Location: ' . BASE_PATH . '/index.php'); exit;
}

$error    = $_GET['error'] ?? null;
$oldEmail = $_GET['email'] ?? '';
$next     = $_GET['next']  ?? '';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>เข้าสู่ระบบ • Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <!-- ฟอนต์แนวเดียวกับแอป (ไทยอ่านง่าย) -->
  <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg: #FFF7F2;          /* เบจอุ่น ๆ */
      --card: #FFFFFF;
      --primary: #7B4B3A;     /* น้ำตาลโทนอุ่น (ปุ่ม/ไฮไลต์) */
      --primary-600: #6E4233;
      --muted: #8C7A70;
      --border: #EADFD9;
      --radius: 18px;
    }
    *{ font-family: 'Kanit', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    body{ background: var(--bg); }

    .auth-wrap{ min-height: 100vh; display:flex; align-items:center; }
    .auth-card{
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: 0 10px 28px rgba(123,75,58,.10);
      overflow: hidden;
      background: var(--card);
    }
    .brand{
      display:flex; align-items:center; gap:.65rem; justify-content:center;
    }
    .brand-logo{
      width:56px; height:56px; border-radius: 16px; object-fit: cover;
      background: #F5E9E3; display:inline-block;
    }
    .brand-title{ font-weight:700; letter-spacing:.2px; }
    .form-control{
      border-radius: 14px; border-color: var(--border);
    }
    .btn-primary{
      background: var(--primary); border-color: var(--primary);
      border-radius: 14px; font-weight:600;
    }
    .btn-primary:hover{ background: var(--primary-600); border-color: var(--primary-600); }
    .muted{ color: var(--muted); }
    .input-group .btn-outline-secondary{
      border-color: var(--border); border-radius: 12px;
    }
    .help-note{ font-size:.9rem; color: var(--muted); }
    .alert{ border-radius: 12px; }
    .link-muted{ color: var(--muted); text-decoration: underline; text-underline-offset: 2px; }
    .link-muted:hover{ color: var(--primary); }
  </style>
</head>
<body>

<div class="container auth-wrap">
  <div class="row justify-content-center w-100">
    <div class="col-sm-10 col-md-7 col-lg-5 col-xxl-4">
      <div class="auth-card p-4 p-md-5">
        <!-- Brand -->
        <div class="brand mb-3 text-center">
          <!-- โลโก้: วางไฟล์ไว้ที่ /assets/img/app-logo.png -->
          <img src="<?= BASE_PATH ?>/assets/img/app-logo.png" alt="App" class="brand-logo"
               onerror="this.replaceWith(Object.assign(document.createElement('div'),{textContent:'🥗',className:'brand-logo d-flex align-items-center justify-content-center'}));">
          <div class="brand-title fs-3">Cooking Guide • Admin</div>
        </div>

        <p class="text-center muted mb-4">ลงชื่อเข้าใช้เพื่อจัดการคอนเทนต์ในแอป</p>

        <?php if ($error): ?>
          <div class="alert alert-danger" role="alert" aria-live="polite">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <form action="login_process.php" method="post" class="needs-validation" novalidate id="loginForm">
          <?= csrf_field() ?>
          <?php if (!empty($next)): ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label for="email" class="form-label">
              <i class="bi bi-envelope me-1"></i>อีเมล
            </label>
            <div class="input-group">
              <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-person text-muted"></i>
              </span>
              <input type="email" class="form-control border-start-0" id="email" name="email"
                value="<?= htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8') ?>" required autofocus>
            </div>
            <div class="invalid-feedback">กรุณากรอกอีเมลให้ถูกต้อง</div>
          </div>

          <div class="mb-3">
            <label for="password" class="form-label">
              <i class="bi bi-lock me-1"></i>รหัสผ่าน
            </label>
            <div class="input-group">
              <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-key text-muted"></i>
              </span>
              <input type="password" class="form-control border-start-0" id="password" name="password" required>
              <button class="btn btn-outline-secondary border-start-0" type="button" id="togglePwd" aria-label="แสดง/ซ่อนรหัสผ่าน">
                <i class="bi bi-eye" id="toggleIcon"></i>
              </button>
            </div>
            <div class="invalid-feedback">กรุณากรอกรหัสผ่าน</div>
          </div>

          <div class="d-grid mb-2">
            <button type="submit" class="btn btn-primary py-2" id="submitBtn">เข้าสู่ระบบ</button>
          </div>
          <div class="text-center">
            <small class="muted">กลับไปที่ <a class="link-muted" href="<?= BASE_PATH ?>/index.php">Dashboard</a></small>
          </div>
        </form>
      </div>

      <p class="text-center help-note mt-3 mb-0">
        <strong>หมายเหตุ:</strong> บัญชีต้องยืนยันอีเมลแล้ว จึงจะสามารถเข้าสู่ระบบได้
      </p>
    </div>
  </div>
</div>

<script>
  // โชว์/ซ่อนรหัสผ่าน
  document.getElementById('togglePwd').addEventListener('click', function(){
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');
    
    if (passwordInput.type === 'password') {
      passwordInput.type = 'text';
      toggleIcon.className = 'bi bi-eye-slash';
    } else {
      passwordInput.type = 'password';
      toggleIcon.className = 'bi bi-eye';
    }
  });

  // กันกดซ้ำ + client validation เบื้องต้น
  const form = document.getElementById('loginForm');
  const submitBtn = document.getElementById('submitBtn');
  form.addEventListener('submit', function(e){
    if (!form.checkValidity()) {
      e.preventDefault(); e.stopPropagation();
      form.classList.add('was-validated');
      return;
    }
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>กำลังเข้าสู่ระบบ...';
  });

  // Add focus effects to input groups
  document.querySelectorAll('.input-group').forEach(group => {
    const input = group.querySelector('input');
    const addon = group.querySelector('.input-group-text');
    
    input.addEventListener('focus', function() {
      addon.style.borderColor = 'var(--primary)';
      addon.style.color = 'var(--primary)';
    });
    
    input.addEventListener('blur', function() {
      addon.style.borderColor = '';
      addon.style.color = '';
    });
  });
</script>
</body>
</html>
