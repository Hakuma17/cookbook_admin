<?php
/**
 * manage_ingredients.php — Admin Ingredients (real-file aware, polished)
 * ----------------------------------------------------------------------
 * ✅ จุดเด่น
 * - Sorting: latest / A–Z / most_used / recently_used / missing_image / ungrouped
 * - Filters: q, category, group(newcatagory), image(with/missing ตรวจไฟล์จริง), usage(used/unused)
 * - KPIs: total, missing-image(เช็กไฟล์จริง), unused, ungrouped
 * - Thumb: ใช้ media_probe() → ได้ url + สถานะไฟล์ (แสดงป้าย “ไม่มีภาพ” บนรูป)
 * - Pagination เป๊ะ: ทำ COUNT จาก subquery ที่เดียวกับผลลัพธ์
 * - Export CSV: ดึงตามเงื่อนไข/เรียงปัจจุบัน (ปุ่มเล็ก ๆ ด้านขวา)
 * - UX เสริม: คลิกภาพเพื่อซูม, ไฮไลท์แถวที่ “ไม่มีภาพ”
 * ----------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/media.php';   // ต้องใช้ media_probe()/media_exists()
require_once __DIR__ . '/includes/csrf.php';

/* ---------- helpers: ตรวจ schema ---------- */
function column_exists(mysqli $conn, string $table, string $column): bool {
  $q="SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  $s=$conn->prepare($q); $s->bind_param('ss',$table,$column); $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}
function table_exists(mysqli $conn, string $table): bool {
  $q="SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?";
  $s=$conn->prepare($q); $s->bind_param('s',$table); $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}

/* ---------- dynamic columns ---------- */
$nameCol   = column_exists($conn,'ingredients','name') ? 'name' : 'ingredient_name';
$dispCol   = column_exists($conn,'ingredients','display_name') ? 'display_name' : $nameCol;
$imageCol  = column_exists($conn,'ingredients','image_path') ? 'image_path'
           : (column_exists($conn,'ingredients','image_url') ? 'image_url' : null);
$hasDelCol = column_exists($conn,'ingredients','deleted_at');
$hasMedia  = table_exists($conn,'media') && column_exists($conn,'ingredients','media_id');
$hasGroup  = column_exists($conn,'ingredients','newcatagory');
$hasCat    = column_exists($conn,'ingredients','category');
$hasMaster = column_exists($conn,'ingredients','master_id');
$recipeHasCreated = column_exists($conn,'recipe','created_at');

/* ---------- inputs ---------- */
$q      = trim($_GET['q'] ?? '');
$cat    = trim($_GET['cat'] ?? '');
$grp    = trim($_GET['grp'] ?? '');
$img    = $_GET['img'] ?? '';            // '', 'with', 'missing' (เช็กไฟล์จริงทีหลัง)
$use    = $_GET['use'] ?? '';            // '', 'used', 'unused'
$sort   = $_GET['sort'] ?? 'latest';
$sort   = in_array($sort, ['latest','az','most_used','recently_used','missing_image','ungrouped'], true) ? $sort : 'latest';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;
$export = ($_GET['export'] ?? '') === 'csv';

/* ---------- expr: ที่อยู่รูปจาก DB (ยังไม่รู้ว่าไฟล์มีจริงไหม) ---------- */
$imgExpr = $hasMedia
  ? "COALESCE(m.file_path,".($imageCol ? "i.$imageCol" : "NULL").")"
  : ($imageCol ? "i.$imageCol" : "NULL");

/* ---------- WHERE จากฟิลเตอร์พื้นฐาน (ยกเว้น with/missing) ---------- */
$parts=[]; $types=''; $params=[];
if ($hasDelCol) $parts[] = "i.deleted_at IS NULL";
if ($q!==''){ $parts[]="(i.$nameCol LIKE CONCAT('%', ?, '%') OR i.$dispCol LIKE CONCAT('%', ?, '%'))"; $types.='ss'; $params[]=$q; $params[]=$q; }
if ($hasCat && $cat!==''){ $parts[]="i.category=?"; $types.='s'; $params[]=$cat; }
if ($hasGroup && $grp!==''){ $parts[]="TRIM(i.newcatagory)=TRIM(?)"; $types.='s'; $params[]=$grp; }
$where = $parts ? ('WHERE '.implode(' AND ',$parts)) : '';

/* ---------- KPI: รวม / ยังไม่มีภาพ(เช็กไฟล์จริง) / ยังไม่ใช้ / ยังไม่เข้ากลุ่ม ---------- */
$totalCount = (function() use($conn,$hasDelCol){
  $sql="SELECT COUNT(*) c FROM ingredients i".($hasDelCol?" WHERE i.deleted_at IS NULL":"");
  return (int)$conn->query($sql)->fetch_assoc()['c'];
})();

$missingImgCount = (function() use($conn,$hasDelCol,$hasMedia,$imageCol){
  $imgExpr = $hasMedia
    ? "COALESCE(m.file_path,".($imageCol ? "i.$imageCol" : "NULL").")"
    : ($imageCol ? "i.$imageCol" : "NULL");
  $sql = "SELECT $imgExpr AS img FROM ingredients i ".
         ($hasMedia ? "LEFT JOIN media m ON m.media_id=i.media_id AND (m.deleted_at IS NULL OR m.deleted_at IS NULL) " : "").
         ($hasDelCol ? "WHERE i.deleted_at IS NULL" : "");
  $res=$conn->query($sql); $c=0;
  while($row=$res->fetch_assoc()){ if(!media_exists($row['img']??null,'ingredient')) $c++; }
  return $c;
})();

$unusedCount = (function() use($conn,$hasDelCol){
  $sql="SELECT COUNT(*) c FROM ingredients i
        LEFT JOIN recipe_ingredient ri ON ri.ingredient_id=i.ingredient_id
        ".($hasDelCol?"WHERE i.deleted_at IS NULL":"WHERE 1")." AND ri.ingredient_id IS NULL";
  return (int)$conn->query($sql)->fetch_assoc()['c'];
})();

$ungroupedCount = (function() use($conn,$hasDelCol){
  $sql="SELECT COUNT(*) c FROM ingredients i ".
       ($hasDelCol?"WHERE i.deleted_at IS NULL AND ":"WHERE ").
       " (i.newcatagory IS NULL OR TRIM(i.newcatagory)='')";
  return (int)$conn->query($sql)->fetch_assoc()['c'];
})();

/* ---------- ตัวเลือกใน select ---------- */
$cats=[]; if($hasCat){ $r=$conn->query("SELECT DISTINCT category FROM ingredients WHERE TRIM(IFNULL(category,''))<>'' ORDER BY category"); while($x=$r->fetch_assoc()) $cats[]=$x['category']; }
$grps=[]; if($hasGroup){ $r=$conn->query("SELECT DISTINCT TRIM(newcatagory) g FROM ingredients WHERE TRIM(IFNULL(newcatagory,''))<>'' ORDER BY g"); while($x=$r->fetch_assoc()) $grps[]=$x['g']; }

/* ---------- ORDER (ระดับ DB; บางแบบจะ fine-tune ใน PHP) ---------- */
$orderTrail = match($sort){
  'az'             => "i.$nameCol ASC",
  'most_used'      => "recipe_count DESC, i.$nameCol ASC",
  'recently_used'  => "last_used_at DESC, recipe_count DESC",
  'missing_image'  => "i.$nameCol ASC", // เดี๋ยวค่อย sort ใหม่ให้ “ไม่มีภาพ” ขึ้นก่อน
  'ungrouped'      => "(CASE WHEN (i.newcatagory IS NULL OR TRIM(i.newcatagory)='') THEN 0 ELSE 1 END) ASC, i.$nameCol ASC",
  default          => "i.ingredient_id DESC"
};

/* ---------- base subquery (ใช้ร่วมกัน: export, count, main list) ---------- */
$baseSql = "
  SELECT
    i.ingredient_id,
    i.$nameCol AS name,
    i.$dispCol AS display_name,
    ".($hasCat?"i.category,":"NULL AS category,")."
    ".($hasGroup?"TRIM(i.newcatagory) AS grp,":"NULL AS grp,")."
    $imgExpr AS img,
    COUNT(DISTINCT ri.recipe_id) AS recipe_count,
    ".($recipeHasCreated?"MAX(r.created_at)":"NULL")." AS last_used_at,
    ".($hasMaster?"i.master_id":"NULL")." AS master_id
  FROM ingredients i
  ".($hasMedia ? "LEFT JOIN media m ON m.media_id=i.media_id AND (m.deleted_at IS NULL OR m.deleted_at IS NULL)" : "")."
  LEFT JOIN recipe_ingredient ri ON ri.ingredient_id=i.ingredient_id
  LEFT JOIN recipe r ON r.recipe_id=ri.recipe_id
  $where
  GROUP BY i.ingredient_id
";

/* used / unused via HAVING */
$having = '';
if ($use==='used')   $having = "HAVING recipe_count > 0";
if ($use==='unused') $having = "HAVING (recipe_count IS NULL OR recipe_count = 0)";

/* ---------- EXPORT CSV (ดึงข้อมูลตามฟิลเตอร์ปัจจุบันทั้งชุด) ---------- */
if ($export) {
  $sqlExp = "$baseSql $having ORDER BY $orderTrail";
  $stExp = $conn->prepare($sqlExp);
  if ($types) $stExp->bind_param($types, ...$params);
  $stExp->execute(); $rsExp = $stExp->get_result();

  // เตรียม rows พร้อมตรวจไฟล์จริง
  $rowsExp=[];
  while($r=$rsExp->fetch_assoc()){
    $p = media_probe($r['img'] ?? null, 'ingredient');
    $rowsExp[] = [
      'id'            => (int)$r['ingredient_id'],
      'name'          => (string)($r['display_name'] ?: $r['name']),
      'category'      => (string)($r['category'] ?? ''),
      'group'         => (string)($r['grp'] ?? ''),
      'recipe_count'  => (int)($r['recipe_count'] ?? 0),
      'img_exists'    => $p['exists'] ? 1 : 0,
      'img_url'       => $p['url'],
      'last_used_at'  => $r['last_used_at'] ? date('Y-m-d H:i', strtotime($r['last_used_at'])) : '',
    ];
  }

  // ส่งออก CSV
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="ingredients_export_'.date('Ymd_His').'.csv"');
  $out = fopen('php://output','w');
  fputcsv($out, array_keys($rowsExp[0] ?? ['id','name','category','group','recipe_count','img_exists','img_url','last_used_at']));
  foreach($rowsExp as $row) fputcsv($out, $row);
  fclose($out);
  exit;
}

/* ---------- COUNT ทั้งหมดหลังฟิลเตอร์ (เพื่อหน้าทั้งหมด/เลขหน้า) ---------- */
$sqlCount = "SELECT COUNT(*) c FROM ($baseSql $having) t";
$stCount = $conn->prepare($sqlCount);
if ($types) $stCount->bind_param($types, ...$params);
$stCount->execute(); $totalFiltered = (int)$stCount->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalFiltered / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

/* ---------- MAIN LIST (มี LIMIT/OFFSET) ---------- */
$sqlList = "$baseSql $having ORDER BY $orderTrail LIMIT ? OFFSET ?";
$st=$conn->prepare($sqlList);
if ($types) { $all = array_merge($params, [$limit, $offset]); $st->bind_param($types.'ii', ...$all); }
else { $st->bind_param('ii', $limit, $offset); }
$st->execute(); $rs=$st->get_result();

/* ---------- post-process: เช็กไฟล์จริง + ฟิลเตอร์ภาพ + sort missing_image ---------- */
$items=[];
while($r=$rs->fetch_assoc()){
  $probe = media_probe($r['img'] ?? null, 'ingredient');  // url + exists
  $r['img_url']    = $probe['url'];
  $r['img_exists'] = $probe['exists'];
  $items[]=$r;
}
if ($img==='with')    $items = array_values(array_filter($items, fn($x)=> $x['img_exists']));
if ($img==='missing') $items = array_values(array_filter($items, fn($x)=> !$x['img_exists']));
if ($sort==='missing_image'){ // ให้ “ไม่มีภาพ” ขึ้นก่อน
  usort($items, function($a,$b){
    $cmp = ($a['img_exists'] <=> $b['img_exists']); // false(ไม่มี) มาก่อน
    if ($cmp!==0) return $cmp;
    return strnatcasecmp($a['display_name']?:$a['name'], $b['display_name']?:$b['name']);
  });
}

/* ---------- helper: build link พร้อม query string เดิม ---------- */
function link_with_params(array $extra): string {
  $qs = array_merge($_GET, $extra);
  return BASE_PATH.'/manage_ingredients.php?'.http_build_query($qs);
}
?>
<style>
/* โทนเดียวกับหน้า recipes admin + ปรับความชัด */
:root{
  --bg:#f8efe9; --card:#fff; --border:#e7ddd7;
  --chip:#f0e5df; --chip-hover:#eadfd8; --chip-on:#6b3f32; --chip-on-bg:#e9d2c9;
  --thead:#e9d3c9; --thead-border:#d9c1b6; --thead-text:#3a2720;
}
body{ background:var(--bg); }
.cb-card{ border-radius:16px; border:1px solid var(--border); }
.cb-thumb{ width:84px;height:64px;object-fit:cover;border-radius:12px;border:1px solid var(--border); cursor:zoom-in; position:relative; }
.table thead th{
  background:linear-gradient(180deg,var(--thead),#f3ddd3);
  color:var(--thead-text); border-color:var(--thead-border);
  font-weight:700; letter-spacing:.1px;
}
.table td,.table th{ border-color:var(--border); }
.btn{ border-radius:999px; }

/* KPI cards */
.cb-kpis .card{ border:1px solid var(--border); border-radius:16px; box-shadow:0 8px 18px rgba(107,63,50,.06); }
.cb-kpis .val{ font-weight:800; font-size:1.4rem; }
.cb-kpis .lbl{ opacity:.8; font-weight:600; }

/* Chips */
.cb-chips .chip{
  display:inline-flex; align-items:center; gap:.35rem;
  padding:.35rem .85rem; border:1px solid var(--border); border-radius:999px;
  background:var(--card); cursor:pointer; color:#3d2a23; text-decoration:none;
}
.cb-chips .chip:hover{ background:var(--chip-hover); }
.cb-chips .chip.active{ background:var(--chip-on-bg); color:var(--chip-on); border-color:var(--chip-on-bg); }

/* Soft badges */
.badge-soft{ border-radius:999px; padding:.3rem .55rem; background:#efe7e3; color:#4c362d; font-weight:600; }
.badge-soft.muted{ background:#f3ece8; color:#7c6a61; }
.badge-soft.warn{ background:#fff1d8; color:#7a5a19; }
.badge-soft.alt{ background:#e1efe8; color:#1f6b4a; }

/* แถวที่ไม่มีภาพ → ไฮไลท์บาง ๆ */
tr.is-missing td{ background-image: linear-gradient(0deg, #fff8f4, #fff); }

/* ป้าย “ไม่มีภาพ” ซ้อนบนรูป */
.thumb-badge{
  position:absolute; left:6px; top:6px; font-size:.72rem; padding:.15rem .4rem;
  background:#ffe5dd; color:#7a3c2b; border-radius:999px; border:1px solid #ffd2c5;
}

.dropdown-menu{ border-radius:14px; }

/* Modal preview */
#imgModal .modal-dialog{ max-width:640px; }
#imgModal img{ width:100%; height:auto; border-radius:14px; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h2 class="mb-0">วัตถุดิบทั้งหมด</h2>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= e(link_with_params(['export'=>'csv'])) ?>">Export CSV</a>
    <a href="<?= BASE_PATH ?>/ingredient_form.php" class="btn btn-primary">+ เพิ่มวัตถุดิบ</a>
  </div>
</div>

<!-- KPIs -->
<div class="row g-3 cb-kpis mb-2">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="val"><?= number_format($totalCount) ?></div><div class="lbl">ทั้งหมด</div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="val"><?= number_format($missingImgCount) ?></div><div class="lbl">ยังไม่มีภาพ</div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="val"><?= number_format($unusedCount) ?></div><div class="lbl">ยังไม่ถูกใช้ในสูตร</div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="val"><?= number_format($ungroupedCount) ?></div><div class="lbl">ยังไม่เข้ากลุ่ม</div></div></div>
</div>

<!-- Filters -->
<form class="row g-2 align-items-center mb-2" method="get">
  <div class="col-sm-7 col-md-5">
    <input class="form-control" type="text" name="q" placeholder="ค้นหาชื่อ/ชื่อแสดง" value="<?= e($q) ?>">
  </div>

  <?php if($cats): ?>
    <div class="col-auto">
      <select class="form-select" name="cat" onchange="this.form.submit()">
        <option value="">ทุกหมวด</option>
        <?php foreach($cats as $c): ?>
          <option value="<?= e($c) ?>" <?= $cat===$c?'selected':'' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>

  <?php if($grps): ?>
    <div class="col-auto">
      <select class="form-select" name="grp" onchange="this.form.submit()">
        <option value="">ทุกกลุ่ม</option>
        <?php foreach($grps as $g): ?>
          <option value="<?= e($g) ?>" <?= $grp===$g?'selected':'' ?>><?= e($g) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>

  <div class="col-auto">
    <select class="form-select" name="img" onchange="this.form.submit()">
      <option value="">ภาพ: ทั้งหมด</option>
      <option value="with"    <?= $img==='with'?'selected':'' ?>>มีภาพ (ไฟล์อยู่จริง)</option>
      <option value="missing" <?= $img==='missing'?'selected':'' ?>>ไม่มีภาพ (ไฟล์หาย/ว่าง)</option>
    </select>
  </div>

  <div class="col-auto">
    <select class="form-select" name="use" onchange="this.form.submit()">
      <option value="">การใช้งาน</option>
      <option value="used"   <?= $use==='used'?'selected':'' ?>>ถูกใช้ในสูตร</option>
      <option value="unused" <?= $use==='unused'?'selected':'' ?>>ยังไม่ถูกใช้</option>
    </select>
  </div>

  <div class="col-auto">
    <select class="form-select" name="limit" onchange="this.form.submit()">
      <?php foreach([25,50,100,200] as $op): ?>
        <option value="<?= $op ?>" <?= $limit===$op?'selected':'' ?>><?= $op ?>/หน้า</option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-auto"><button class="btn btn-outline-secondary">ค้นหา</button></div>
  <?php if($q!==''||$cat!==''||$grp!==''||$img!==''||$use!==''): ?>
    <div class="col-auto"><a class="btn btn-outline-secondary" href="<?= BASE_PATH ?>/manage_ingredients.php">ล้าง</a></div>
  <?php endif; ?>
  <input type="hidden" name="sort" value="<?= e($sort) ?>">
  <input type="hidden" name="page" value="<?= (int)$page ?>">
</form>

<!-- Sort chips -->
<div class="cb-chips mb-3">
  <?php $chips=['latest'=>'ล่าสุด','az'=>'A–Z','most_used'=>'ใช้บ่อย','recently_used'=>'ใช้ล่าสุด','missing_image'=>'ยังไม่มีภาพ','ungrouped'=>'ยังไม่เข้ากลุ่ม']; ?>
  <?php foreach($chips as $k=>$label): ?>
    <a class="chip me-2 <?= $sort===$k?'active':'' ?>" href="<?= e(link_with_params(['sort'=>$k,'page'=>1])) ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="table-responsive">
  <table class="table align-middle bg-white table-hover cb-card">
    <thead>
      <tr>
        <th style="width:78px">ID</th>
        <th style="width:110px">ภาพ</th>
        <th>ชื่อวัตถุดิบ</th>
        <th style="width:180px">หมวด</th>
        <th style="width:190px">กลุ่ม (สำหรับกรองแพ้)</th>
        <th class="text-center" style="width:120px">#สูตร</th>
        <th style="width:160px">ใช้ล่าสุด</th>
        <th class="text-end" style="width:150px">จัดการ</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($items as $r): ?>
        <tr class="<?= $r['img_exists'] ? '' : 'is-missing' ?>">
          <td class="fw-semibold"><?= (int)$r['ingredient_id'] ?></td>

          <td>
            <div class="position-relative d-inline-block">
              <img class="cb-thumb" src="<?= e($r['img_url']) ?>" alt=""
                   data-full="<?= e($r['img_url']) ?>" data-exists="<?= $r['img_exists'] ? 1 : 0 ?>">
              <?php if(!$r['img_exists']): ?><span class="thumb-badge">ไม่มีภาพ</span><?php endif; ?>
            </div>
          </td>

          <td class="fw-semibold">
            <div><?= e($r['display_name'] ?: $r['name']) ?></div>
            <?php if(!empty($r['master_id'])): ?><small class="text-success">ผูก master #<?= (int)$r['master_id'] ?></small><?php endif; ?>
          </td>

          <td>
            <?= !empty($r['category'])
                ? '<span class="badge-soft muted">'.e($r['category']).'</span>'
                : '<span class="badge-soft warn">ยังไม่ระบุ</span>' ?>
          </td>

          <td>
            <?= !empty($r['grp'])
                ? '<span class="badge-soft">'.e($r['grp']).'</span>'
                : '<span class="badge-soft warn">ยังไม่เข้ากลุ่ม</span>' ?>
          </td>

          <td class="text-center"><?= (int)$r['recipe_count'] ?></td>

          <td>
            <?php $lu=$r['last_used_at']??null;
              echo $lu ? '<span class="badge-soft alt">'.e(date('Y-m-d H:i', strtotime($lu))).'</span>' : '<span class="text-muted">—</span>'; ?>
          </td>

          <td class="text-end">
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" type="button">ดำเนินการ</button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= BASE_PATH ?>/ingredient_form.php?id=<?= (int)$r['ingredient_id'] ?>">แก้ไข</a></li>
                <li><a class="dropdown-item" href="<?= BASE_PATH ?>/media_library.php?for=ingredient&id=<?= (int)$r['ingredient_id'] ?>">เปลี่ยนรูป</a></li>
                <?php if($r['img_exists']): ?>
                  <li><a class="dropdown-item" target="_blank" href="<?= e($r['img_url']) ?>">เปิดรูปในแท็บใหม่</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form action="<?= BASE_PATH ?>/save_ingredient.php" method="post"
                        onsubmit="return confirm('ซ่อนวัตถุดิบนี้ (soft delete)?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['ingredient_id'] ?>">
                    <button class="dropdown-item text-danger" type="submit">ซ่อน</button>
                  </form>
                </li>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if(empty($items)): ?>
        <tr><td colspan="8" class="text-center py-4 text-muted">ไม่พบวัตถุดิบตามเงื่อนไข</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<div class="d-flex justify-content-between align-items-center mt-3">
  <div class="text-muted small">
    หน้า <?= (int)$page ?> / <?= (int)$totalPages ?>
    · ทั้งหมดหลังกรอง <?= number_format($totalFiltered) ?> รายการ
  </div>
  <div class="btn-group">
    <a class="btn btn-outline-secondary btn-sm <?= $page<=1?'disabled':'' ?>" href="<?= e(link_with_params(['page'=>max(1,$page-1)])) ?>">← ก่อนหน้า</a>
    <a class="btn btn-outline-secondary btn-sm <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(link_with_params(['page'=>min($totalPages,$page+1)])) ?>">ถัดไป →</a>
  </div>
</div>

<!-- Modal preview -->
<div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-2">
        <img src="" alt="" id="imgModalPic">
      </div>
    </div>
  </div>
</div>

<script>
// คลิก thumb เพื่อซูม (แสดงได้แม้เป็น default แต่จะไม่ซูมถ้าไม่มีรูปจริง)
document.addEventListener('click', (e)=>{
  const img = e.target.closest('.cb-thumb'); if(!img) return;
  if (String(img.dataset.exists) !== '1') return; // ไม่มีรูปจริง → ไม่ต้องซูม
  document.getElementById('imgModalPic').src = img.dataset.full;
  const modal = new bootstrap.Modal(document.getElementById('imgModal'));
  modal.show();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
