<?php
/**
 * manage_users.php
 * ------------------------------------------------------------
 * รายการผู้ใช้:
 *  - ค้นหาโดยชื่อ/อีเมล
 *  - ปุ่ม "แบน/ยกเลิกแบน" (soft action) + "ลบถาวร" (ระวัง FK)
 *  - UI มินิมอล โทนอุ่น กลมมน ให้ใกล้กับแอป
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';

// --------- รับพารามิเตอร์ค้นหา ---------
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$where = "WHERE deleted_at IS NULL";
$params = [];
$types = '';

if ($q !== '') {
    // เพิ่มเงื่อนไขการค้นหา และตรวจสอบว่ามี deleted_at หรือไม่
    $where = "WHERE (profile_name LIKE CONCAT('%', ?, '%') OR email LIKE CONCAT('%', ?, '%')) AND deleted_at IS NULL";
    $params[] = $q;
    $params[] = $q;
    $types .= 'ss';
}

// --------- เตรียมคิวรี ---------
$sql = "SELECT user_id, profile_name, email, is_verified, is_banned, created_at
        FROM user $where
        ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);

// ---- [ ✨ จุดที่แก้ไข ] ----
// แก้ไขเงื่อนไข: จะ bind_param ก็ต่อเมื่อมีพารามิเตอร์จริงๆ เท่านั้น ($types ไม่ใช่ค่าว่าง)
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>
<style>
/* โทนสี & ความกลมมนเล็ก ๆ แนวมินิมอล */
.cb-chip { border:1px solid #d7c9c2; padding:.35rem .6rem; border-radius:999px; font-size:.9rem; }
.cb-pill { border-radius: 1rem; }
.cb-card { border-radius: 16px; border-color: #efe7e3; }
.cb-soft { background:#fbf5f2; }
.cb-muted { color:#6b4c3b; opacity:.85; }
.table thead th { background:#f5ede9; border-color:#efe7e3; }
.table td, .table th { border-color:#efe7e3; }
.form-control, .form-select { border-radius: 12px; border-color:#e9dfda; }
.btn { border-radius:14px; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h2 class="mb-0">ผู้ใช้ทั้งหมด</h2>
  <a href="<?= BASE_PATH ?>/index.php" class="btn btn-outline-secondary">← กลับแดชบอร์ด</a>
</div>

<form class="row g-2 align-items-center mb-3" method="get">
  <div class="col-sm-8 col-md-6">
    <input class="form-control" type="text" name="q" placeholder="ค้นหา ชื่อผู้ใช้ หรือ อีเมล"
           value="<?= htmlspecialchars($q) ?>">
  </div>
  <div class="col-auto">
    <button class="btn btn-primary" type="submit">ค้นหา</button>
  </div>
  <?php if ($q !== ''): ?>
  <div class="col-auto">
    <a class="btn btn-outline-secondary" href="<?= BASE_PATH ?>/manage_users.php">ล้างการค้นหา</a>
  </div>
  <?php endif; ?>
</form>

<div class="table-responsive">
  <table class="table align-middle table-hover bg-white cb-card">
    <thead>
      <tr>
        <th style="width:80px">ID</th>
        <th>ชื่อผู้ใช้</th>
        <th>อีเมล</th>
        <th class="text-center">ยืนยันอีเมล</th>
        <th class="text-center">สถานะ</th>
        <th style="width:240px" class="text-end">การจัดการ</th>
      </tr>
    </thead>
    <tbody>
      <?php while ($row = $result->fetch_assoc()): ?>
      <tr>
        <td class="fw-semibold"><?= (int)$row['user_id'] ?></td>
        <td><?= htmlspecialchars($row['profile_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
        <td class="text-center">
          <?php if ((int)$row['is_verified'] === 1): ?>
            <span class="badge text-bg-success rounded-pill px-3">ยืนยันแล้ว</span>
          <?php else: ?>
            <span class="badge text-bg-secondary rounded-pill px-3">ยังไม่ยืนยัน</span>
          <?php endif; ?>
        </td>
        <td class="text-center">
          <?php if ((int)$row['is_banned'] === 1): ?>
            <span class="badge text-bg-danger rounded-pill px-3">ถูกแบน</span>
          <?php else: ?>
            <span class="badge text-bg-info rounded-pill px-3">ใช้งานได้</span>
          <?php endif; ?>
        </td>
        <td class="text-end">
          <?php if ((int)$row['is_banned'] === 1): ?>
            <a class="btn btn-sm btn-success"
               href="<?= BASE_PATH ?>/user_action.php?id=<?= (int)$row['user_id'] ?>&action=unban&csrf_token=<?= csrf_token() ?>"
               onclick="return confirm('ยกเลิกแบนผู้ใช้นี้ใช่ไหม?');">ยกเลิกแบน</a>
          <?php else: ?>
            <a class="btn btn-sm btn-warning"
               href="<?= BASE_PATH ?>/user_action.php?id=<?= (int)$row['user_id'] ?>&action=ban&csrf_token=<?= csrf_token() ?>"
               onclick="return confirm('ต้องการแบนผู้ใช้นี้หรือไม่?');">แบน</a>
          <?php endif; ?>

            <a class="btn btn-sm btn-outline-danger"
                href="<?= BASE_PATH ?>/user_action.php?id=<?= (int)$row['user_id'] ?>&action=delete&csrf_token=<?= csrf_token() ?>"
                onclick="return confirm('ซ่อนผู้ใช้นี้ (soft delete)?');">ซ่อน</a>
        </td>
      </tr>
      <?php endwhile; ?>
      <?php if ($result->num_rows === 0): ?>
      <tr><td colspan="6" class="text-center py-4 text-muted">ไม่พบผู้ใช้ที่ตรงกับคำค้น</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>