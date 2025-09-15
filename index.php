<?php
/**
 * index.php — Admin Dashboard (2025-08-24)
 * ------------------------------------------------------------
 * 🧩 ไฮไลท์
 * - นับจำนวนรวม + เทียบสัปดาห์ล่าสุด (7 วัน) กับสัปดาห์ก่อนหน้า
 * - แจ้งเตือนคุณภาพข้อมูล: วัตถุดิบไม่มีภาพ/ยังไม่เข้ากลุ่ม/สูตรไม่มีหมวด (ถ้ามี)
 * - รายการล่าสุด: Recipes / Reviews (ปรับตามคอลัมน์ที่มีจริง)
 * - Sparkline (SVG) ไม่พึ่ง JS
 * - RBAC: แสดงเฉพาะเมนูที่มีสิทธิ์
 * - ป้องกัน soft-delete (columns: deleted_at) และสคีมาไม่แน่นอน
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/media.php';          // ใช้ตรวจรูป (ingredients)
if (!defined('BASE_PATH')) define('BASE_PATH', '');
require_once __DIR__ . '/includes/audit.php';
audit_log('view','dashboard', null, ['section'=>'overview']); // บันทึกการเปิดหน้าแดชบอร์ด    // กันกรณี header ยังไม่ define

/* ---------- helpers: ตรวจสคีมา/ยูทิล ---------- */
// เช็กว่าคอลัมน์มีไหม
function col_exists(mysqli $c, string $t, string $col): bool {
  $s=$c->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $s->bind_param('ss',$t,$col); $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c']??0)>0;
}
// เช็กว่าตารางมีไหม
function tbl_exists(mysqli $c, string $t): bool {
  $s=$c->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $s->bind_param('s',$t); $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c']??0)>0;
}
// คืนชื่อคอลัมน์แรกที่เจอจากชุด candidates
function first_col(mysqli $c, string $t, array $cands): ?string {
  foreach($cands as $x){ if(col_exists($c,$t,$x)) return $x; }
  return null;
}
// นับแถว (คำนึง deleted_at ถ้ามี)
function count_active(mysqli $c, string $t): int {
  if(!tbl_exists($c,$t)) return 0;
  $soft = col_exists($c,$t,'deleted_at') ? " WHERE deleted_at IS NULL" : "";
  $sql = "SELECT COUNT(*) c FROM `$t`".$soft;
  $r=$c->query($sql); return (int)($r?$r->fetch_assoc()['c']??0:0);
}
// นับแถวที่ “สร้างในช่วง N วันล่าสุด” (หา created_x* อัตโนมัติ)
function count_new_since(mysqli $c, string $t, int $days=7): int {
  if(!tbl_exists($c,$t)) return 0;
  $dtCol = first_col($c,$t,['created_at','createdAt','create_time','created','submitted_at','reg_date']);
  if(!$dtCol) return 0;
  $soft = col_exists($c,$t,'deleted_at') ? " AND deleted_at IS NULL" : "";
  $sql  = "SELECT COUNT(*) c FROM `$t` WHERE `$dtCol` >= DATE_SUB(NOW(), INTERVAL ? DAY)".$soft;
  $s=$c->prepare($sql); $s->bind_param('i',$days); $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c']??0);
}
// นับแถวใน “ช่วง N–2N วันก่อน” เพื่อเทียบสัปดาห์
function count_prev_window(mysqli $c, string $t, int $days=7): int {
  if(!tbl_exists($c,$t)) return 0;
  $dtCol = first_col($c,$t,['created_at','createdAt','create_time','created','submitted_at','reg_date']);
  if(!$dtCol) return 0;
  $soft = col_exists($c,$t,'deleted_at') ? " AND deleted_at IS NULL" : "";
  $sql  = "SELECT COUNT(*) c FROM `$t` 
           WHERE `$dtCol` >= DATE_SUB(NOW(), INTERVAL ? DAY)
             AND `$dtCol` <  DATE_SUB(NOW(), INTERVAL ? DAY)".$soft;
  $s=$c->prepare($sql); $a=$days*2; $b=$days; $s->bind_param('ii',$a,$b); $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c']??0);
}
// % การเปลี่ยนแปลง (ปลอดภัย)
function pct_change(int $now, int $prev): string {
  if($prev<=0) return $now>0?'+∞%':'+0%';
  $pct = ($now-$prev)*100.0/$prev;
  return sprintf('%+0.1f%%',$pct);
}

/* ---------- สถิติหลัก ---------- */
$recipesAll = count_active($conn,'recipe');
$usersAll   = count_active($conn,'user');
$ingsAll    = count_active($conn,'ingredients');
$reviewsAll = count_active($conn,'review');

$recNew  = count_new_since($conn,'recipe',7);
$recPrev = count_prev_window($conn,'recipe',7);
$usrNew  = count_new_since($conn,'user',7);
$usrPrev = count_prev_window($conn,'user',7);
$ingNew  = count_new_since($conn,'ingredients',7);
$ingPrev = count_prev_window($conn,'ingredients',7);
$revNew  = count_new_since($conn,'review',7);
$revPrev = count_prev_window($conn,'review',7);

/* ---------- แจ้งเตือนคุณภาพข้อมูล (เร็วและพอใช้) ---------- */
// วัตถุดิบรูปหาย: ดึงมาชุดเล็กแล้วเช็กไฟล์จริง (ไวกว่า count ทั้ง DB)
function ingredients_missing_images(mysqli $c, int $sample=300): int {
  if(!tbl_exists($c,'ingredients')) return 0;
  $imgCol = first_col($c,'ingredients',['image_path','image_url']);
  $media  = col_exists($c,'ingredients','media_id');
  $soft   = col_exists($c,'ingredients','deleted_at') ? " WHERE i.deleted_at IS NULL" : "";
  $expr   = $media
          ? "COALESCE(m.file_path,".($imgCol?"i.$imgCol":"NULL").")"
          : ($imgCol?"i.$imgCol":"NULL");
  $sql="SELECT $expr img FROM ingredients i ".
       ($media?"LEFT JOIN media m ON m.media_id=i.media_id ":"").
       $soft." LIMIT ?";
  $s=$c->prepare($sql); $s->bind_param('i',$sample); $s->execute(); $r=$s->get_result();
  $miss=0; while($row=$r->fetch_assoc()){ if(!media_exists($row['img']??null,'ingredient')) $miss++; }
  return $miss;
}
// วัตถุดิบยังไม่เข้ากลุ่ม (newcatagory ว่าง)
function ingredients_ungrouped(mysqli $c): int {
  if(!tbl_exists($c,'ingredients')) return 0;
  $soft = col_exists($c,'ingredients','deleted_at') ? " AND deleted_at IS NULL" : "";
  $sql="SELECT COUNT(*) c FROM ingredients i WHERE (i.newcatagory IS NULL OR TRIM(i.newcatagory)='')".$soft;
  $r=$c->query($sql); return (int)($r?$r->fetch_assoc()['c']??0:0);
}
// สูตรที่ยังไม่มีหมวด (ถ้ามีตาราง mapping)
function recipes_without_category(mysqli $c): int {
  if(!tbl_exists($c,'recipe')) return 0;
  if(!tbl_exists($c,'category_recipe')) return 0;
  $soft = col_exists($c,'recipe','deleted_at') ? "WHERE r.deleted_at IS NULL" : "";
  $sql = "SELECT COUNT(*) c FROM recipe r 
          LEFT JOIN category_recipe cr ON cr.recipe_id=r.recipe_id
          $soft AND cr.recipe_id IS NULL";
  $r=$c->query($sql); return (int)($r?$r->fetch_assoc()['c']??0:0);
}

$ingMissing  = ingredients_missing_images($conn, 400);
$ingNoGroup  = ingredients_ungrouped($conn);
$recNoCate   = recipes_without_category($conn);

/* ---------- สปาร์คไลน์ (6 จุดย้อนหลัง) ---------- */
// ดึงจำนวน NEW ต่อเดือน ย้อนหลัง 6 เดือน (ถ้ามี created_at)
function monthly_series(mysqli $c, string $t, int $months=6): array {
  $dtCol = first_col($c,$t,['created_at','createdAt','create_time','created','submitted_at','reg_date']);
  if(!$dtCol || !tbl_exists($c,$t)) return array_fill(0,$months,0);
  $soft = col_exists($c,$t,'deleted_at') ? " AND deleted_at IS NULL" : "";
  $sql="SELECT DATE_FORMAT(`$dtCol`,'%Y-%m') ym, COUNT(*) c 
        FROM `$t` 
        WHERE `$dtCol` >= DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL ? MONTH) $soft
        GROUP BY DATE_FORMAT(`$dtCol`,'%Y-%m')";
  $s=$c->prepare($sql); $s->bind_param('i',$months); $s->execute();
  $map=[]; $res=$s->get_result(); while($row=$res->fetch_assoc()){ $map[$row['ym']]=(int)$row['c']; }
  // จัดเรียงตามเดือนล่าสุด → เก่าสุด
  $out=[];
  for($i=$months-1;$i>=0;$i--){
    $ym=date('Y-m',strtotime("-$i month"));
    $out[]=$map[$ym]??0;
  }
  return $out;
}
$seriesRecipes = monthly_series($conn,'recipe',6);
$seriesUsers   = monthly_series($conn,'user',6);

/* ---------- รายการล่าสุด ---------- */
function latest_rows(mysqli $c, string $t, int $limit=8, array $nameCandidates=['name','title','recipe_name']): array {
  if(!tbl_exists($c,$t)) return [];
  $idCol   = first_col($c,$t,[$t.'_id','id']);
  $nameCol = first_col($c,$t,$nameCandidates) ?? $idCol;
  $dtCol   = first_col($c,$t,['created_at','createdAt','create_time','created','submitted_at']);
  $soft    = col_exists($c,$t,'deleted_at') ? " WHERE deleted_at IS NULL" : "";
  $order   = $dtCol ? "`$dtCol` DESC" : "`$idCol` DESC";
  $sql     = "SELECT `$idCol` id, `$nameCol` name".($dtCol?", `$dtCol` created_at":"")." FROM `$t` $soft ORDER BY $order LIMIT ?";
  $s=$c->prepare($sql); $s->bind_param('i',$limit); $s->execute();
  $rows=[]; $r=$s->get_result(); while($x=$r->fetch_assoc()) $rows[]=$x;
  return $rows;
}
$latestRecipes = latest_rows($conn,'recipe',8,['recipe_name','name','title']);
$latestReviews = latest_rows($conn,'review',8,['title','headline','summary']);

/* ---------- UX text ---------- */
$name = $_SESSION['profile_name'] ?? 'แอดมิน';
$hour=(int)date('G');
$greet=($hour<11?'สวัสดีตอนเช้า':($hour<16?'ยินดีต้อนรับ':($hour<20?'สวัสดีตอนเย็น':'ราตรีสวัสดิ์')));
?>
<style>
/* ===== Dashboard-only tweaks ===== */
.cb-hero{ display:flex; align-items:center; gap:1rem; margin-bottom:1.25rem; }
.cb-avatar{ width:54px;height:54px;border-radius:14px;background:#F5E9E3;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:24px; }
.cb-hero h1{ margin:0; font-weight:700; letter-spacing:.2px; }
.cb-hero .sub{ color:var(--muted); margin-top:.25rem; }

.cb-stat{ border:1px solid var(--border); border-radius: var(--radius); background: var(--card); box-shadow:0 10px 28px rgba(123,75,58,.08); overflow:hidden; }
.cb-stat .hd{ padding:.8rem 1rem; font-weight:600; color:#fff; display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
.cb-stat .bd{ padding:1.1rem 1rem 1.15rem; display:flex; align-items:flex-end; justify-content:space-between; gap:.5rem; }
.cb-stat .val{ font-size:2rem; font-weight:700; line-height:1; }
.cb-stat .delta{ font-weight:700; padding:.25rem .5rem; border-radius:999px; background:#fff; color:#1d6b3c; }
.cb-stat .delta.neg{ color:#8b1e1e; }

.bg-blue{background:linear-gradient(135deg,#2F76FF,#1561F3);}
.bg-green{background:linear-gradient(135deg,#1E8F5D,#17724B);}
.bg-cyan{background:linear-gradient(135deg,#10C0E8,#00A7CE);}
.bg-amber{background:linear-gradient(135deg,#FFC225,#F6A500);}

.spark{ height:32px; width:120px; }
.spark path{ fill:none; stroke:currentColor; stroke-width:2; opacity:.85; }
.spark .axis{ stroke:currentColor; opacity:.15; }

.cb-alert{ border:1px solid var(--border); border-radius:14px; padding:1rem; background:#fff; }
.cb-alert .row+.row{ margin-top:.35rem; }
.cb-pill{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border:1px solid var(--border); border-radius:999px; background:#FFF7F2; font-weight:600; }

.cb-list{ border:1px solid var(--border); border-radius:14px; background:#fff; }
.cb-list .hd{ padding:.75rem 1rem; border-bottom:1px solid var(--border); font-weight:700; }
.cb-list .it{ padding:.7rem 1rem; display:flex; align-items:center; justify-content:space-between; }
.cb-list .it:not(:last-child){ border-bottom:1px solid var(--border); }
.cb-list .meta{ color:var(--muted); font-size:.9rem; }
.cb-quick .card{ border:1px solid var(--border); border-radius:14px; transition:transform .12s ease, box-shadow .12s ease; }
.cb-quick .card:hover{ transform:translateY(-2px); box-shadow:0 8px 18px rgba(123,75,58,.12); }
.cb-ico{ width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#F5E9E3;border:1px solid var(--border);font-size:20px; }
.cb-link{ color:var(--primary); text-decoration:none; font-weight:600; }
.cb-link:hover{ color:var(--primary-600); }
</style>

<div class="cb-hero">
  <div class="cb-avatar">
    <i class="bi bi-person-badge fs-1"></i>
  </div>
  <div>
    <h1 class="text-gradient"><?= $greet ?>, <?= htmlspecialchars($name) ?></h1>
    <div class="sub">แดชบอร์ดการจัดการคอนเทนต์แอป Cooking Guide</div>
  </div>
</div>

<!-- ===== Stats (รวม + delta + sparkline) ===== -->
<div class="row g-3">
  <!-- Recipes -->
  <div class="col-12 col-md-6 col-lg-3">
    <div class="cb-stat">
      <div class="hd bg-blue">
        <span>Recipes</span>
        <span class="delta <?= ($recNew<$recPrev)?'neg':'' ?>"><?= htmlspecialchars(pct_change($recNew,$recPrev)) ?></span>
      </div>
      <div class="bd">
        <div class="val"><?= number_format($recipesAll) ?></div>
        <!-- sparkline: ใช้ series 6 จุด -->
        <?php
          $mx = max(1,max($seriesRecipes));
          $pts=[]; for($i=0;$i<count($seriesRecipes);$i++){ $x=($i/(count($seriesRecipes)-1))*118+1; $y=30 - ($seriesRecipes[$i]/$mx)*28; $pts[] = ($i===0?'M':'L').round($x,1).','.round($y,1); }
        ?>
        <svg class="spark" viewBox="0 0 120 32">
          <path class="axis" d="M1,30 L119,30" />
          <path d="<?= implode(' ',$pts) ?>" />
        </svg>
      </div>
    </div>
  </div>

  <!-- Users -->
  <div class="col-12 col-md-6 col-lg-3">
    <div class="cb-stat">
      <div class="hd bg-green">
        <span>Users</span>
        <span class="delta <?= ($usrNew<$usrPrev)?'neg':'' ?>"><?= htmlspecialchars(pct_change($usrNew,$usrPrev)) ?></span>
      </div>
      <div class="bd">
        <div class="val"><?= number_format($usersAll) ?></div>
        <?php
          $mx=max(1,max($seriesUsers)); $pts=[];
          for($i=0;$i<count($seriesUsers);$i++){ $x=($i/(count($seriesUsers)-1))*118+1; $y=30-($seriesUsers[$i]/$mx)*28; $pts[]=($i===0?'M':'L').round($x,1).','.round($y,1); }
        ?>
        <svg class="spark" viewBox="0 0 120 32">
          <path class="axis" d="M1,30 L119,30" />
          <path d="<?= implode(' ',$pts) ?>" />
        </svg>
      </div>
    </div>
  </div>

  <!-- Ingredients -->
  <div class="col-12 col-md-6 col-lg-3">
    <div class="cb-stat">
      <div class="hd bg-cyan">
        <span>Ingredients</span>
        <span class="delta"><?= $ingNew>0?('+'.number_format($ingNew)):'+0' ?></span>
      </div>
      <div class="bd">
        <div class="val"><?= number_format($ingsAll) ?></div>
        <svg class="spark" viewBox="0 0 120 32"><path class="axis" d="M1,30 L119,30" /><path d="M1,29 L119,29"/></svg>
      </div>
    </div>
  </div>

  <!-- Reviews -->
  <div class="col-12 col-md-6 col-lg-3">
    <div class="cb-stat">
      <div class="hd bg-amber">
        <span>Reviews</span>
        <span class="delta <?= ($revNew<$revPrev)?'neg':'' ?>"><?= htmlspecialchars(pct_change($revNew,$revPrev)) ?></span>
      </div>
      <div class="bd">
        <div class="val"><?= number_format($reviewsAll) ?></div>
        <svg class="spark" viewBox="0 0 120 32"><path class="axis" d="M1,30 L119,30" /><path d="M1,29 L119,29"/></svg>
      </div>
    </div>
  </div>
</div>

<!-- ===== Data Quality Alerts ===== -->
<div class="row g-3 mt-3">
  <div class="col-12 col-lg-6">
    <div class="cb-alert">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <strong>คุณภาพข้อมูล</strong>
        <a class="cb-link" href="<?= BASE_PATH ?>/manage_ingredients.php?sort=missing_image&img=missing">ดูวัตถุดิบ</a>
      </div>
      <div class="row">
        <div class="col-12 col-md-6">
          <div class="cb-pill">🖼️ รูปวัตถุดิบหาย (ตัวอย่าง): <b><?= number_format($ingMissing) ?></b></div>
        </div>
        <div class="col-12 col-md-6 mt-2 mt-md-0">
          <div class="cb-pill">🏷️ วัตถุดิบยังไม่เข้ากลุ่ม: <b><?= number_format($ingNoGroup) ?></b></div>
        </div>
      </div>
      <div class="row mt-2">
        <div class="col-12">
          <div class="cb-pill">📚 สูตรยังไม่ถูกจัดหมวด: <b><?= number_format($recNoCate) ?></b></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Search -->
  <div class="col-12 col-lg-6">
    <div class="cb-alert">
      <strong>ค้นหาเร็ว</strong>
      <form class="row g-2 mt-1" action="<?= BASE_PATH ?>/manage_recipes.php" method="get">
        <div class="col-12 col-sm-8">
          <input class="form-control" type="text" name="q" placeholder="ค้นหาเมนูอาหาร / วัตถุดิบ / ผู้ใช้">
        </div>
        <div class="col-6 col-sm-2 d-grid">
          <button class="btn btn-primary">ค้นหา</button>
        </div>
        <div class="col-6 col-sm-2 d-grid">
          <a class="btn btn-outline-secondary" href="<?= BASE_PATH ?>/manage_ingredients.php">วัตถุดิบ</a>
        </div>
      </form>
      <div class="text-muted small mt-2">* ไปยังหน้าจัดการและคงค่าค้นหาให้อัตโนมัติ</div>
    </div>
  </div>
</div>

<!-- ===== Latest Activity ===== -->
<div class="row g-3 mt-3">
  <div class="col-12 col-lg-6">
    <div class="cb-list">
      <div class="hd">🆕 Recipes ล่าสุด</div>
      <?php if($latestRecipes): foreach($latestRecipes as $it): ?>
        <div class="it">
          <div>
            <div class="fw-semibold"><?= htmlspecialchars($it['name']??('#'.$it['id'])) ?></div>
            <div class="meta"><?= !empty($it['created_at']) ? date('Y-m-d H:i', strtotime($it['created_at'])) : '—' ?></div>
          </div>
          <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_PATH ?>/recipe_form.php?id=<?= (int)$it['id'] ?>">เปิด</a>
        </div>
      <?php endforeach; else: ?>
        <div class="it"><span class="text-muted">ยังไม่มีข้อมูล</span></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="cb-list">
      <div class="hd">⭐ Reviews ล่าสุด</div>
      <?php if($latestReviews): foreach($latestReviews as $it): ?>
        <div class="it">
          <div>
            <div class="fw-semibold"><?= htmlspecialchars($it['name']??('Review #'.$it['id'])) ?></div>
            <div class="meta"><?= !empty($it['created_at']) ? date('Y-m-d H:i', strtotime($it['created_at'])) : '—' ?></div>
          </div>
          <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_PATH ?>/manage_reviews.php?focus=<?= (int)$it['id'] ?>">เปิด</a>
        </div>
      <?php endforeach; else: ?>
        <div class="it"><span class="text-muted">ยังไม่มีข้อมูล</span></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ===== Quick Actions (ตามสิทธิ์) ===== -->
<div class="row g-3 mt-4 cb-quick">
  <?php if (can_any(['view_recipes','edit_recipes'])): ?>
    <div class="col-12 col-sm-6 col-lg-3">
      <a class="card p-3 h-100" href="<?= BASE_PATH ?>/manage_recipes.php">
        <div class="d-flex align-items-center gap-3">
          <div class="cb-ico">
            <i class="bi bi-book"></i>
          </div>
          <div>
            <div class="fw-semibold">จัดการ Recipes</div>
            <div class="text-muted small">เพิ่ม/แก้ไขเมนูอาหาร</div>
          </div>
        </div>
      </a>
    </div>
  <?php endif; ?>

  <?php if (can_any(['view_ingredients','edit_ingredients'])): ?>
    <div class="col-12 col-sm-6 col-lg-3">
      <a class="card p-3 h-100" href="<?= BASE_PATH ?>/manage_ingredients.php">
        <div class="d-flex align-items-center gap-3">
          <div class="cb-ico">
            <i class="bi bi-carrot"></i>
          </div>
          <div>
            <div class="fw-semibold">จัดการ Ingredients</div>
            <div class="text-muted small">ปรับภาพ/ชื่อวัตถุดิบ</div>
          </div>
        </div>
      </a>
    </div>
  <?php endif; ?>

  <?php if (can_any(['view_users','edit_users'])): ?>
    <div class="col-12 col-sm-6 col-lg-3">
      <a class="card p-3 h-100" href="<?= BASE_PATH ?>/manage_users.php">
        <div class="d-flex align-items-center gap-3">
          <div class="cb-ico">
            <i class="bi bi-people"></i>
          </div>
          <div>
            <div class="fw-semibold">จัดการ Users</div>
            <div class="text-muted small">แบน/ยกเลิกแบน, ตรวจสอบ</div>
          </div>
        </div>
      </a>
    </div>
  <?php endif; ?>

  <?php if (can('moderate_reviews')): ?>
    <div class="col-12 col-sm-6 col-lg-3">
      <a class="card p-3 h-100" href="<?= BASE_PATH ?>/manage_reviews.php">
        <div class="d-flex align-items-center gap-3">
          <div class="cb-ico">
            <i class="bi bi-star"></i>
          </div>
          <div>
            <div class="fw-semibold">จัดการ Reviews</div>
            <div class="text-muted small">อนุมัติ/ปฏิเสธรีวิว</div>
          </div>
        </div>
      </a>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
