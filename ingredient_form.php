<?php
/**
 * ingredient_form.php
 * ฟอร์มเพิ่ม/แก้ไขวัตถุดิบ
 * - รองรับสคีมาชื่อคอลัมน์ต่างกัน (name|ingredient_name, image_path|image_url)
 * - แสดงภาพด้วย media_url() (มี placeholder ให้อัตโนมัติถ้าไฟล์หาย)
 * - Soft delete safe: โหลดเฉพาะรายการที่ยังไม่ถูกลบ
 */
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/media.php';   // <-- ใช้ media_url()

// ---- helpers ตรวจสคีมา ----
function column_exists(mysqli $conn, string $table, string $column): bool {
  $q = "SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  $s = $conn->prepare($q); $s->bind_param('ss',$table,$column); $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}
function table_exists(mysqli $conn, string $table): bool {
  $q = "SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=?";
  $s = $conn->prepare($q); $s->bind_param('s',$table); $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}

$nameCol   = column_exists($conn,'ingredients','name') ? 'name' : 'ingredient_name';
$imageCol  = column_exists($conn,'ingredients','image_path') ? 'image_path'
           : (column_exists($conn,'ingredients','image_url') ? 'image_url' : null);
$hasDelCol = column_exists($conn,'ingredients','deleted_at');
$hasMedia  = table_exists($conn,'media') && column_exists($conn,'ingredients','media_id');

$isEdit = isset($_GET['id']);
$row = ['ingredient_id'=>0,'name'=>'','img'=>null];

if ($isEdit) {
  $id = (int)$_GET['id'];
  $imgExpr = $hasMedia
    ? "COALESCE(m.file_path,".($imageCol ? "i.$imageCol" : "NULL").")"
    : ($imageCol ? "i.$imageCol" : "NULL");

  $sql = "SELECT i.ingredient_id, i.$nameCol AS name, $imgExpr AS img
          FROM ingredients i ".
          ($hasMedia ? "LEFT JOIN media m ON m.media_id=i.media_id AND (m.deleted_at IS NULL OR m.deleted_at IS NULL) " : "").
         "WHERE i.ingredient_id=? ".($hasDelCol ? "AND i.deleted_at IS NULL " : "")."LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param('i',$id);
  $st->execute();
  $rs = $st->get_result();
  if ($rs && $rs->num_rows===1) $row = $rs->fetch_assoc();
  else echo '<div class="alert alert-danger">ไม่พบวัตถุดิบ</div>';
}
?>
<style>
.form-control{ border-radius:12px; border-color:#e9dfda; }
.cb-card{ border-radius:16px; border-color:#efe7e3; }
.cb-thumb-lg{ width:220px; height:160px; object-fit:cover; border-radius:14px; border:1px solid #efe7e3; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h2 class="mb-0"><?= $isEdit ? 'แก้ไขวัตถุดิบ' : 'เพิ่มวัตถุดิบ' ?></h2>
  <a href="<?= BASE_PATH ?>/manage_ingredients.php" class="btn btn-outline-secondary">← กลับรายการ</a>
</div>

<?php require_once __DIR__.'/includes/csrf.php'; ?>

<form action="<?= BASE_PATH ?>/save_ingredient.php" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
  <?= csrf_field() ?>
  <?php if ($isEdit): ?>
    <input type="hidden" name="ingredient_id" value="<?= (int)$row['ingredient_id'] ?>">
  <?php endif; ?>

  <div class="card mb-4 cb-card">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">ชื่อวัตถุดิบ</label>
        <input type="text" class="form-control" name="name" value="<?= e($row['name'] ?? '') ?>" required>
      </div>

      <div class="mb-2">
        <label class="form-label d-block mb-2">ภาพ</label>
        <img id="preview" class="cb-thumb-lg mb-2"
             src="<?= e(media_url($row['img'] ?? null,'ingredient')) ?>"
             alt="<?= e($row['name'] ?? 'Ingredient') ?>">
        <input type="file" class="form-control" name="image" accept="image/*">
        <div class="form-text">รองรับ JPG/PNG/WebP ขนาดไม่เกิน ~2MB</div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-success px-4" type="submit">บันทึก</button>
    <a class="btn btn-outline-secondary" href="<?= BASE_PATH ?>/manage_ingredients.php">ยกเลิก</a>
  </div>
</form>

<script>
// preview รูปที่เลือก
document.querySelector('input[name="image"]')?.addEventListener('change', (e)=>{
  const f = e.target.files?.[0]; if(!f) return;
  const url = URL.createObjectURL(f);
  const img = document.getElementById('preview'); img.src = url;
});

// Bootstrap client validation
(()=>{for(const f of document.querySelectorAll('.needs-validation')){
  f.addEventListener('submit',e=>{ if(!f.checkValidity()){e.preventDefault();e.stopPropagation();} f.classList.add('was-validated');},false);
}})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
