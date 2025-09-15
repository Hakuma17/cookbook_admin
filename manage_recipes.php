<?php
/**
 * manage_recipes.php
 * - ฟิลเตอร์สถานะ draft/review/published/archived + ค้นหาชื่อ
 * - แสดงปก (media_url) + ปุ่ม Quick Actions
 *
 * ★★★ UPDATED (2025-08-24):
 *   - ตัวเลือก "เรียงลำดับ" แบบเดียวกับแอป: latest / popular / trending / recommended
 *   - ฟิลเตอร์ "ภาพปก": with (มีภาพ) / missing (ยังไม่มีภาพ)
 *   - ฟิลเตอร์ "หมวด" (ถ้ามีตาราง category)
 *   - เพิ่ม review_count และทำ favorite_count ให้ใช้งานได้แม้ไม่มีคอลัมน์ใน recipe
 *   - UI ใหม่: หัวตารางเข้ม + sticky, ชิปคอนทราสต์สูงขึ้น, ปุ่มการจัดการแบบเรียบร้อย (Primary + Dropdown ⋯)
 */
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/csrf.php';

function column_exists(mysqli $c,$t,$col){
  $s=$c->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $s->bind_param('ss',$t,$col);$s->execute();
  return (int)($s->get_result()->fetch_assoc()['c']??0)>0;
}
function table_exists(mysqli $c,$t){
  $s=$c->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $s->bind_param('s',$t);$s->execute();
  return (int)($s->get_result()->fetch_assoc()['c']??0)>0;
}
/** รูปปก: เลือกจาก media.file_path ก่อน ตามด้วย image_path/image_url */
function image_expr(mysqli $c): string {
  $hasMedia = column_exists($c,'recipe','media_id') && column_exists($c,'media','file_path');
  $hasImg   = column_exists($c,'recipe','image_path')? 'image_path' : (column_exists($c,'recipe','image_url')?'image_url':null);
  if ($hasMedia) return "COALESCE(m.file_path,".($hasImg?"r.$hasImg":"NULL").")";
  return $hasImg ? "r.$hasImg" : "NULL";
}

/* ── Query params ─────────────────────────────────────────── */
$status = $_GET['status'] ?? '';
$q      = trim($_GET['q'] ?? '');

/* ★★★ NEW: sort ตามมือถือ */
$sort   = $_GET['sort'] ?? 'latest';
$sort   = in_array($sort, ['latest','popular','trending','recommended'], true) ? $sort : 'latest';

/* ★★★ NEW: ฟิลเตอร์ภาพปก (with / missing) */
$img    = $_GET['img'] ?? ''; // '', 'with', 'missing'

/* ★★★ NEW: ฟิลเตอร์หมวดหมู่ (ถ้ามีตาราง category) */
$catId  = isset($_GET['cat_id']) && $_GET['cat_id']!=='' ? (int)$_GET['cat_id'] : null;
$hasCat = table_exists($conn,'category') && column_exists($conn,'category','category_id');

/* ── WHERE ───────────────────────────────────────────────── */
$parts=[]; $types=''; $params=[];
if (column_exists($conn,'recipe','deleted_at')) $parts[]="r.deleted_at IS NULL";
if ($status !== '' && in_array($status,['draft','review','published','archived'],true)) { $parts[]="r.status=?"; $types.='s'; $params[]=$status; }
if ($q!==''){ $parts[]="r.name LIKE CONCAT('%', ?, '%')"; $types.='s'; $params[]=$q; }
if ($hasCat && $catId !== null) {
  $parts[] = "EXISTS (SELECT 1 FROM category_recipe cr WHERE cr.recipe_id=r.recipe_id AND cr.category_id=?)";
  $types  .= 'i'; $params[]=$catId;
}

$imgExpr = image_expr($conn);
/* ★★★ NEW: เงื่อนไขมี/ไม่มีภาพ */
if ($img==='with')   { $parts[] = "($imgExpr IS NOT NULL AND $imgExpr <> '')"; }
if ($img==='missing'){ $parts[] = "($imgExpr IS NULL OR $imgExpr = '')"; }

$where = $parts ? 'WHERE '.implode(' AND ',$parts) : '';

/* ★★★ NEW: favorite_count / review_count ให้ใช้ได้เสมอ */
$favExpr    = column_exists($conn,'recipe','favorite_count')
              ? 'COALESCE(r.favorite_count,(SELECT COUNT(*) FROM favorites f WHERE f.recipe_id=r.recipe_id))'
              : '(SELECT COUNT(*) FROM favorites f WHERE f.recipe_id=r.recipe_id)';
$reviewExpr = '(SELECT COUNT(*) FROM review v WHERE v.recipe_id=r.recipe_id)';

$imgSelect  = "$imgExpr AS cover";

/* ★★★ NEW: ORDER BY white-list ให้ตรงกับแอป */
$orderTrail = match ($sort) {
  'popular'     => 'favorite_count DESC',
  'trending'    => 'r.created_at DESC, favorite_count DESC',
  'recommended' => 'r.average_rating DESC, review_count DESC',
  default       => 'r.created_at DESC',
};

$sql = "SELECT
          r.recipe_id,
          r.name,
          r.nServings,
          r.average_rating,
          $favExpr    AS favorite_count,
          $reviewExpr AS review_count,
          r.status,
          r.slug,
          r.published_at,
          $imgSelect
        FROM recipe r
        LEFT JOIN media m ON m.media_id=r.media_id
        $where
        ORDER BY $orderTrail, r.recipe_id DESC
        LIMIT 300";
$st=$conn->prepare($sql);
if ($types) $st->bind_param($types,...$params);
$st->execute(); $rows=$st->get_result();

/* ★★★ NEW: เตรียมรายการหมวดหมู่ (ถ้ามี) */
$cats = [];
if ($hasCat) {
  $resCat = $conn->query("SELECT category_id, category_name FROM category ORDER BY category_name ASC");
  while($c=$resCat->fetch_assoc()){ $cats[]=$c; }
}

/* ★★★ NEW: helper ทำลิงก์คง query string เดิม */
function link_with($arr){
  $qs = array_merge($_GET, $arr);
  return BASE_PATH.'/manage_recipes.php?'.http_build_query($qs);
}
?>
<style>
/* ── THEME ใกล้มือถือ + เพิ่มคอนทราสต์ ───────────────────── */
:root{
  --cb-bg:#f8efe9;
  --cb-card:#ffffff;
  --cb-border:#e3d6cf;
  --cb-border-strong:#c9b8b0;
  --cb-head:#e7d3c9;            /* ★★★ NEW: header เข้มขึ้น */
  --cb-head-text:#3a241c;       /* ★★★ NEW: header text */
  --cb-chip:#f1e7e1;
  --cb-chip-active:#d9b9ac;     /* ★★★ NEW: chip active fill */
  --cb-chip-text:#3d2a23;
  --cb-shadow:0 6px 18px rgba(58,36,28,.08);
}
body{background:var(--cb-bg);}
.cb-thumb{width:92px;height:68px;object-fit:cover;border-radius:12px;border:1px solid var(--cb-border);}
.cb-card{border-radius:16px;border-color:var(--cb-border);box-shadow:var(--cb-shadow);}
.table-responsive{position:relative;}
.table thead th{
  background: var(--cb-head) !important;   /* ★★★ NEW */
  color: var(--cb-head-text);
  border-color: var(--cb-border-strong);
  position: sticky; top: 0; z-index: 2;    /* ★★★ NEW: sticky header */
  box-shadow: 0 1px 0 rgba(0,0,0,.03);
}
.table td,.table th{border-color:var(--cb-border);}
.status-pill{border-radius:999px;padding:.15rem .6rem;font-size:.85rem;}
/* ★★★ NEW: chips มีคอนทราสต์ขึ้น */
.cb-chips .chip{
  display:inline-flex;align-items:center;gap:.4rem;
  padding:.45rem .9rem;border:1px solid var(--cb-border-strong);border-radius:999px;
  background:var(--cb-card);cursor:pointer;text-decoration:none;color:var(--cb-chip-text);
  box-shadow: var(--cb-shadow);
}
.cb-chips .chip.active{
  background:var(--cb-chip-active);
  border-color: var(--cb-border-strong);
}
.cb-chips .chip:hover{background:var(--cb-chip);}
/* ★★★ NEW: action bar ให้เป็นกลุ่มเรียบร้อย */
.btn{border-radius:999px}
.action-bar .btn-group form{display:inline-block;margin:0;}
.action-bar .btn-group .btn{border-radius:0;}       /* กลืนขอบกันในกลุ่ม */
.action-bar .btn-group .btn:first-child{border-top-left-radius:999px;border-bottom-left-radius:999px;}
.action-bar .btn-group .btn:last-child{border-top-right-radius:999px;border-bottom-right-radius:999px;}
.action-bar .btn-toolbar{gap:.35rem;}
.dropdown-menu form{width:100%}
.dropdown-menu form .dropdown-item{width:100%;text-align:left;display:block;}
/* ปรับขนาดในจอแคบ */
@media (max-width: 1200px){
  .action-bar .btn-group.btn-group-sm .btn{padding:.3rem .55rem;font-size:.83rem;}
}
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h2 class="mb-0">สูตรอาหาร</h2>
  <a class="btn btn-primary" href="<?= BASE_PATH ?>/recipe_form.php">+ เพิ่มสูตรใหม่</a>
</div>

<form class="row g-2 align-items-center mb-2" method="get">
  <div class="col-auto">
    <select class="form-select" name="status" onchange="this.form.submit()">
      <option value="">ทุกสถานะ</option>
      <?php foreach (['draft'=>'ร่าง','review'=>'รอตรวจ','published'=>'เผยแพร่','archived'=>'เก็บถาวร'] as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if($hasCat): ?>
  <!-- ★★★ NEW: หมวดหมู่ -->
  <div class="col-auto">
    <select class="form-select" name="cat_id" onchange="this.form.submit()">
      <option value="">ทุกหมวดหมู่</option>
      <?php foreach($cats as $c): ?>
        <option value="<?= (int)$c['category_id'] ?>" <?= ($catId===(int)$c['category_id'])?'selected':'' ?>>
          <?= e($c['category_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <!-- ★★★ NEW: ภาพปก -->
  <div class="col-auto">
    <select class="form-select" name="img" onchange="this.form.submit()">
      <option value="">ภาพ: ทั้งหมด</option>
      <option value="with"    <?= $img==='with'?'selected':'' ?>>มีภาพปก</option>
      <option value="missing" <?= $img==='missing'?'selected':'' ?>>ยังไม่มีภาพ</option>
    </select>
  </div>

  <div class="col-sm-8 col-md-5">
    <input class="form-control" type="text" name="q" placeholder="ค้นหาชื่อสูตร" value="<?= e($q) ?>">
  </div>
  <div class="col-auto"><button class="btn btn-outline-secondary">ค้นหา</button></div>
  <?php if($q!==''||$status!==''||$img!==''||$catId!==null):?>
    <div class="col-auto"><a class="btn btn-outline-secondary" href="<?= BASE_PATH ?>/manage_recipes.php">ล้าง</a></div>
  <?php endif;?>
  <input type="hidden" name="sort" value="<?= e($sort) ?>">
</form>

<!-- ★★★ NEW: แถวชิปเรียงลำดับสไตล์มือถือ (คอนทราสต์สูงขึ้น) -->
<div class="cb-chips mb-3">
  <?php
    $chips = [
      'latest'      => 'ล่าสุด',
      'popular'     => 'ยอดนิยม',
      'trending'    => 'มาแรง',
      'recommended' => 'แนะนำ',
    ];
  ?>
  <?php foreach($chips as $key=>$label): ?>
    <a class="chip me-2 <?= $sort===$key?'active':'' ?>" href="<?= e(link_with(['sort'=>$key])) ?>">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
  <span class="ms-2" style="color:#6b3f32;">
    • รวมแสดง: <strong><?= (int)$rows->num_rows ?></strong> รายการ
  </span>
</div>

<div class="table-responsive">
  <table class="table align-middle bg-white table-hover cb-card">
    <thead><tr>
      <th style="width:80px">ID</th>
      <th style="width:110px">ภาพ</th>
      <th>ชื่อสูตร</th>
      <th class="text-center" style="width:90px">เสิร์ฟ</th>
      <th class="text-center" style="width:110px">เรตติ้ง</th>
      <th class="text-center" style="width:120px">ถูกใจ</th>
      <th class="text-center" style="width:110px">รีวิว</th>
      <th class="text-center" style="width:140px">สถานะ</th>
      <th class="text-end" style="width:280px">การจัดการ</th> <!-- กดง่ายขึ้นแต่กะทัดรัด -->
    </tr></thead>
    <tbody>
    <?php while($r=$rows->fetch_assoc()): ?>
      <tr>
        <td class="fw-semibold"><?= (int)$r['recipe_id'] ?></td>
        <td>
          <?php $cover = $r['cover'] ? media_url($r['cover'],'recipe') : (BASE_PATH.'/uploads/recipes/default_recipe.png'); ?>
          <img class="cb-thumb" src="<?= e($cover) ?>" alt="">
        </td>
        <td class="fw-semibold">
          <div><?= e($r['name']) ?></div>
          <small class="text-muted"><?= e($r['slug'] ?? '') ?></small>
        </td>
        <td class="text-center"><?= (int)$r['nServings'] ?></td>
        <td class="text-center">⭐ <?= number_format((float)$r['average_rating'],1) ?></td>
        <td class="text-center">💖 <?= (int)($r['favorite_count'] ?? 0) ?></td>
        <td class="text-center">📝 <?= (int)($r['review_count'] ?? 0) ?></td>
        <td class="text-center">
          <?php
            $map=['draft'=>'secondary','review'=>'warning','published'=>'success','archived'=>'dark'];
            $label=['draft'=>'ร่าง','review'=>'รอตรวจ','published'=>'เผยแพร่','archived'=>'เก็บถาวร'];
            $b=$map[$r['status']]??'secondary';
          ?>
          <span class="badge text-bg-<?= $b ?> status-pill"><?= $label[$r['status']]??$r['status'] ?></span>
        </td>

        <!-- ★★★ NEW: ปุ่มจัดระเบียบ — Primary 2 ปุ่ม + เมนู ⋯ -->
        <td class="text-end">
          <div class="btn-toolbar justify-content-end action-bar" role="toolbar" aria-label="Actions">
            <div class="btn-group btn-group-sm me-2" role="group" aria-label="Primary">
              <a class="btn btn-warning" href="<?= BASE_PATH ?>/recipe_form.php?id=<?= (int)$r['recipe_id'] ?>">แก้ไข</a>

              <?php if($r['status']!=='published'): ?>
                <form action="<?= BASE_PATH ?>/recipe_actions.php" method="post" class="m-0 p-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="publish">
                  <input type="hidden" name="id" value="<?= (int)$r['recipe_id'] ?>">
                  <button class="btn btn-success">Publish</button>
                </form>
              <?php else: ?>
                <form action="<?= BASE_PATH ?>/recipe_actions.php" method="post" class="m-0 p-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="unpublish">
                  <input type="hidden" name="id" value="<?= (int)$r['recipe_id'] ?>">
                  <button class="btn btn-outline-secondary">Unpublish</button>
                </form>
              <?php endif; ?>
            </div>

            <!-- More -->
            <div class="btn-group btn-group-sm" role="group">
              <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions">
                ⋯
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li>
                  <a class="dropdown-item" href="<?= BASE_PATH ?>/media_library.php?for=recipe&id=<?= (int)$r['recipe_id'] ?>">
                    เปลี่ยนรูปปก
                  </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form action="<?= BASE_PATH ?>/recipe_actions.php" method="post" class="m-0 p-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="id" value="<?= (int)$r['recipe_id'] ?>">
                    <button class="dropdown-item">Archive</button>
                  </form>
                </li>
                <li>
                  <form action="<?= BASE_PATH ?>/recipe_actions.php" method="post" class="m-0 p-0" onsubmit="return confirm('ซ่อนสูตรนี้ (soft delete)?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['recipe_id'] ?>">
                    <button class="dropdown-item text-danger">ซ่อน</button>
                  </form>
                </li>
              </ul>
            </div>
          </div>
        </td>
      </tr>
    <?php endwhile; if($rows->num_rows===0): ?>
      <tr><td colspan="9" class="text-center py-4 text-muted">ไม่พบทุกรายการ</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
