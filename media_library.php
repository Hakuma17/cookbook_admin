<?php
/**
 * media_library.php
 * - อัปโหลดหลายไฟล์ (drag&drop / multi-select)
 * - ค้นตามชื่อ/ชนิด/ขนาด
 * - โหมดเลือกเป็นปกสูตร: /media_library.php?for=recipe&id=123
 */
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/csrf.php';

$for = $_GET['for'] ?? '';
$refId = (int)($_GET['id'] ?? 0);

// search
$q = trim($_GET['q'] ?? '');
$mime = trim($_GET['mime'] ?? '');
$maxkb = (int)($_GET['maxkb'] ?? 0);

$parts=[]; $types=''; $params=[];
if($q!==''){ $parts[]="file_path LIKE CONCAT('%', ?, '%')"; $types.='s'; $params[]=$q; }
if($mime!==''){ $parts[]="mime LIKE CONCAT(?, '%')"; $types.='s'; $params[]=$mime; }
if($maxkb>0){ $parts[]="bytes <= ?"; $types.='i'; $params[]=$maxkb*1024; }
$parts[]="deleted_at IS NULL";
$where='WHERE '.implode(' AND ',$parts);

$sql="SELECT media_id,file_path,mime,bytes,width,height,created_at FROM media $where ORDER BY media_id DESC LIMIT 300";
$st=$conn->prepare($sql); if($types) $st->bind_param($types,...$params); $st->execute();
$rows=$st->get_result();
?>
<style>
.card-media{border-radius:14px;border-color:#efe7e3;}
.media-thumb{width:100%;height:160px;object-fit:cover;border-top-left-radius:14px;border-top-right-radius:14px;}
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h2 class="mb-0">Media Library</h2>
  <a href="<?= BASE_PATH ?>/manage_recipes.php" class="btn btn-outline-secondary">← กลับ</a>
</div>

<div class="card p-3 mb-3 card-media">
  <form action="<?= BASE_PATH ?>/upload_media.php" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <?php if($for==='recipe' && $refId>0): ?>
      <input type="hidden" name="for" value="recipe">
      <input type="hidden" name="id" value="<?= $refId ?>">
    <?php endif; ?>
    <div class="row g-2 align-items-center">
      <div class="col-md-6"><input class="form-control" type="file" name="files[]" accept="image/*" multiple required></div>
      <div class="col-auto"><button class="btn btn-primary">อัปโหลด</button></div>
      <div class="col-auto text-muted">ลากวางไฟล์ลงที่ปุ่มได้</div>
    </div>
  </form>
</div>

<form class="row g-2 align-items-end mb-3" method="get">
  <?php if($for==='recipe' && $refId>0): ?>
    <input type="hidden" name="for" value="recipe"><input type="hidden" name="id" value="<?= $refId ?>">
  <?php endif; ?>
  <div class="col-md-4"><label class="form-label">ชื่อไฟล์</label><input class="form-control" name="q" value="<?= e($q) ?>"></div>
  <div class="col-md-3"><label class="form-label">ชนิด (image/jpeg, image/png...)</label><input class="form-control" name="mime" value="<?= e($mime) ?>"></div>
  <div class="col-md-2"><label class="form-label">ขนาดสูงสุด (KB)</label><input class="form-control" type="number" name="maxkb" value="<?= $maxkb ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">ค้นหา</button></div>
</form>

<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">
<?php while($m=$rows->fetch_assoc()): ?>
  <div class="col">
    <div class="card card-media h-100">
      <img class="media-thumb" src="<?= e(media_url($m['file_path'])) ?>" alt="">
      <div class="card-body">
        <div class="small text-muted"><?= e($m['mime']) ?> • <?= number_format($m['bytes']/1024,0) ?> KB</div>
        <div class="d-flex gap-2 mt-2">
          <?php if($for==='recipe' && $refId>0): ?>
            <form action="<?= BASE_PATH ?>/set_recipe_cover.php" method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="recipe_id" value="<?= $refId ?>">
              <input type="hidden" name="media_id" value="<?= (int)$m['media_id'] ?>">
              <button class="btn btn-sm btn-primary">ตั้งเป็นรูปปก</button>
            </form>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(media_url($m['file_path'])) ?>">เปิด</a>
        </div>
      </div>
    </div>
  </div>
<?php endwhile; if($rows->num_rows===0): ?>
  <div class="text-muted">ไม่พบไฟล์</div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
