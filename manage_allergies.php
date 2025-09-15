<?php
/**
 * manage_allergies.php?user_id=XX
 * - แสดงรายการ ingredients ทั้งหมด + เช็กบ็อกซ์ว่า user แพ้หรือไม่
 * - บันทึกไปที่ตาราง allergyinfo(user_id, ingredient_id) หรือชื่อใกล้เคียง
 */
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/csrf.php';

$uid=(int)($_GET['user_id']??0);
if($uid<=0){ echo '<div class="alert alert-danger">ต้องระบุ user_id</div>'; require_once __DIR__.'/includes/footer.php'; exit; }

function column_exists(mysqli $c,$t,$col){$s=$c->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$s->bind_param('ss',$t,$col);$s->execute();return (int)($s->get_result()->fetch_assoc()['c']??0)>0;}
$nameCol = column_exists($conn,'ingredients','name')?'name':'ingredient_name';

$all = $conn->query("SELECT ingredient_id,$nameCol AS name FROM ingredients WHERE ".(column_exists($conn,'ingredients','deleted_at')?'deleted_at IS NULL':'1=1')." ORDER BY name");
$sel = []; $s=$conn->prepare("SELECT ingredient_id FROM allergyinfo WHERE user_id=?"); $s->bind_param('i',$uid); $s->execute(); $rs=$s->get_result(); while($x=$rs->fetch_assoc()) $sel[]=(int)$x['ingredient_id'];
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h2 class="mb-0">ภูมิแพ้อาหาร — ผู้ใช้ #<?= $uid ?></h2>
  <a href="<?= BASE_PATH ?>/manage_users.php" class="btn btn-outline-secondary">← กลับผู้ใช้</a>
</div>

<form action="<?= BASE_PATH ?>/save_allergies.php" method="post" class="card p-3">
  <?= csrf_field() ?>
  <input type="hidden" name="user_id" value="<?= $uid ?>">
  <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2">
    <?php while($r=$all->fetch_assoc()): $id=(int)$r['ingredient_id']; ?>
    <div class="col">
      <label class="form-check cb-soft p-2 rounded-3 border">
        <input class="form-check-input me-2" type="checkbox" name="ing[]" value="<?= $id ?>" <?= in_array($id,$sel,true)?'checked':'' ?>>
        <?= e($r['name']) ?>
      </label>
    </div>
    <?php endwhile; ?>
  </div>
  <div class="mt-3"><button class="btn btn-success">บันทึก</button></div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
