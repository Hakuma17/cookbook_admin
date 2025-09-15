<?php
/**
 * recipe_form.php (Merged)
 * ------------------------------------------------------------
 * ฟอร์มสำหรับเพิ่มและแก้ไขสูตรอาหารที่รวมฟีเจอร์ทั้งหมด:
 * - ฟิลด์: name, nServings, prep_time, status, published_at, slug
 * - ระบบจัดการรูปภาพ: รองรับ Media Library และแสดงภาพตัวอย่าง
 * - การจัดการหมวดหมู่ (Categories)
 * - ป้องกันการดึงสูตรที่ถูกลบ (soft delete)
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/media.php'; // สมมติว่าไฟล์นี้มีฟังก์ชัน e() และ media_url()

// --- Helper Functions ---
function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return (int)($res['c'] ?? 0) > 0;
}

function get_image_expression(mysqli $conn): string {
    $hasMediaId = column_exists($conn, 'recipe', 'media_id');
    $hasImagePath = column_exists($conn, 'recipe', 'image_path');

    if ($hasMediaId) {
        return "COALESCE(m.file_path, " . ($hasImagePath ? "r.image_path" : "NULL") . ")";
    }
    return $hasImagePath ? "r.image_path" : "NULL";
}

// ตรวจว่ามีตารางไหม
function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return (int)($res['c'] ?? 0) > 0;
}

// คืนชื่อคอลัมน์แรกที่มีจริงจากตัวเลือก
function first_col(mysqli $conn, string $table, array $cands): ?string {
    foreach ($cands as $c) if (column_exists($conn, $table, $c)) return $c; return null;
}

// --- Initialization ---
$isEdit = isset($_GET['id']);
$rec = [
    'recipe_id' => 0,
    'name' => '',
    'nServings' => 1,
    'prep_time' => 0,
    'status' => 'draft',
    'slug' => '',
    'published_at' => null,
    'cover' => null
];

// --- Fetch Data for Edit Mode ---
if ($isEdit) {
    $id = (int)$_GET['id'];
    $hasDelCol = column_exists($conn, 'recipe', 'deleted_at');
    
    $sql = "SELECT r.*, " . get_image_expression($conn) . " AS cover
            FROM recipe r
            LEFT JOIN media m ON m.media_id = r.media_id
            WHERE r.recipe_id = ? " . ($hasDelCol ? "AND r.deleted_at IS NULL" : "") . "
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $rec = $row;
    } else {
        echo '<div class="alert alert-danger">ไม่พบสูตรอาหารที่ต้องการแก้ไข</div>';
    }
}

// --- Fetch Categories ---
$cats = $conn->query("SELECT category_id, category_name FROM category ORDER BY category_name");
$selectedCats = [];
if ($isEdit && $rec['recipe_id']) {
    $stmt = $conn->prepare("SELECT category_id FROM category_recipe WHERE recipe_id=?");
    $stmt->bind_param('i', $rec['recipe_id']);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($c = $rs->fetch_assoc()) {
        $selectedCats[] = (int)$c['category_id'];
    }
}

// --- Fetch Ingredients master list ---
$ingNameCol = column_exists($conn,'ingredients','name') ? 'name' : 'ingredient_name';
$ingsList = $conn->query("SELECT ingredient_id, $ingNameCol AS name FROM ingredients " .
                         (column_exists($conn,'ingredients','deleted_at')?"WHERE deleted_at IS NULL ":"") .
                         "ORDER BY name LIMIT 1000");

// --- Fetch Recipe Ingredients (for edit) ---
$recipeIngs = [];
if ($isEdit && $rec['recipe_id'] && table_exists($conn,'recipe_ingredient')) {
    $orderCol = first_col($conn,'recipe_ingredient',['order_index','sort_order','position','step_no','seq']);
    $orderSql = $orderCol ? ("ORDER BY ".$orderCol) : '';
    $st = $conn->prepare("SELECT * FROM recipe_ingredient WHERE recipe_id=? $orderSql");
    $st->bind_param('i', $rec['recipe_id']);
    $st->execute();
    $rs = $st->get_result();
    while ($r = $rs->fetch_assoc()) $recipeIngs[] = $r;
}

// --- Fetch Steps (for edit) ---
$recipeSteps = [];
if ($isEdit && $rec['recipe_id']) {
    if (table_exists($conn,'recipe_step')) {
        $txtCol = first_col($conn,'recipe_step',['step_text','description','content','instruction','instructions','note','details']);
        $ordCol = first_col($conn,'recipe_step',['order_index','step_no','position','sort_order','seq']);
        if ($txtCol) {
            $sql = "SELECT $txtCol AS t" . ($ordCol?", $ordCol AS o":"") . " FROM recipe_step WHERE recipe_id=? " . ($ordCol?"ORDER BY $ordCol":"");
            $st = $conn->prepare($sql); $st->bind_param('i', $rec['recipe_id']); $st->execute();
            $rs=$st->get_result(); while($x=$rs->fetch_assoc()) $recipeSteps[] = $x['t'];
        }
    } else {
        $instCol = first_col($conn,'recipe',['instructions','method','directions','steps_text']);
        if ($instCol && !empty($rec[$instCol])) {
            $recipeSteps = preg_split("/\r?\n/", (string)$rec[$instCol]);
        }
    }
}
?>

<style>
    .form-control, .form-select { border-radius: 12px; border-color: #e9dfda; }
    .cb-card { border-radius: 16px; border-color: #efe7e3; }
    .cb-soft { background: #fbf5f2; }
    .cb-thumb-lg { width: 220px; height: 160px; object-fit: cover; border-radius: 14px; border: 1px solid #efe7e3; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="mb-0"><?= $isEdit ? 'แก้ไขสูตร' : 'เพิ่มสูตรใหม่' ?></h2>
    <a href="<?= BASE_PATH ?>/manage_recipes.php" class="btn btn-outline-secondary">← กลับรายการ</a>
</div>

<?php require_once __DIR__ . '/includes/csrf.php'; ?>

<form action="<?= BASE_PATH ?>/save_recipe.php" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="recipe_id" value="<?= (int)$rec['recipe_id'] ?>">
    <?php endif; ?>

    <div class="card mb-3 cb-card">
        <div class="card-header bg-white fw-semibold">ข้อมูลพื้นฐาน</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">ชื่อสูตร</label>
                <input type="text" class="form-control" name="name" required value="<?= e($rec['name']) ?>">
            </div>

            <div class="row g-3">
                <div class="col-sm-4">
                    <label class="form-label">จำนวนเสิร์ฟ</label>
                    <input class="form-control" type="number" min="1" required name="nServings" value="<?= (int)$rec['nServings'] ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label">เวลาเตรียม (นาที)</label>
                    <input class="form-control" type="number" min="0" name="prep_time" value="<?= (int)$rec['prep_time'] ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label">สถานะ</label>
                    <select class="form-select" name="status">
                        <?php foreach (['draft' => 'ร่าง', 'review' => 'รอตรวจ', 'published' => 'เผยแพร่', 'archived' => 'เก็บถาวร'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($rec['status'] === $k) ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-sm-6">
                    <label class="form-label">เวลาเผยแพร่ (ว่าง=ทันที)</label>
                    <input class="form-control" type="datetime-local" name="published_at"
                           value="<?= $rec['published_at'] ? date('Y-m-d\TH:i', strtotime($rec['published_at'])) : '' ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Slug (เว้นว่าง=ให้ระบบสร้าง)</label>
                    <input class="form-control" type="text" name="slug" value="<?= e($rec['slug'] ?? '') ?>">
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label d-block">ภาพปก</label>
                <img id="coverPreview" class="cb-thumb-lg mb-2" src="<?= e(media_url($rec['cover'], 'recipe')) ?>" alt="ภาพปกตัวอย่าง">
                <input type="file" class="form-control" name="image" accept="image/*">
                <div class="form-text">
                    รองรับ JPG/PNG/WebP ขนาดไม่เกิน ~3MB 
                    <?php if($isEdit && $rec['recipe_id']): ?>
                        | <a href="<?= BASE_PATH ?>/media_library.php?for=recipe&id=<?= (int)$rec['recipe_id'] ?>">หรือเลือกจาก Media Library</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3 cb-card">
        <div class="card-header bg-white fw-semibold">หมวดหมู่</div>
        <div class="card-body">
            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2">
                <?php while ($c = $cats->fetch_assoc()): $cid = (int)$c['category_id']; ?>
                    <div class="col">
                        <label class="form-check cb-soft p-2 rounded-3 border">
                            <input class="form-check-input me-2" type="checkbox" name="categories[]" value="<?= $cid ?>" <?= in_array($cid, $selectedCats, true) ? 'checked' : '' ?>>
                            <?= e($c['category_name']) ?>
                        </label>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

    <div class="card mb-4 cb-card">
        <div class="card-header bg-white fw-semibold">วัตถุดิบ</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="width:40%">ส่วนผสม</th>
                            <th style="width:15%">ปริมาณ</th>
                            <th style="width:15%">หน่วย</th>
                            <th>หมายเหตุ</th>
                            <th style="width:60px"></th>
                        </tr>
                    </thead>
                    <tbody id="ingRows">
                        <?php if (!empty($recipeIngs)): ?>
                            <?php
                              $qtyCol  = first_col($conn,'recipe_ingredient',['qty','quantity','amount','amount_text']);
                              $unitCol = first_col($conn,'recipe_ingredient',['unit','unit_name']);
                              $noteCol = first_col($conn,'recipe_ingredient',['note','remarks','comment']);
                              foreach ($recipeIngs as $r):
                                $ingId = (int)($r['ingredient_id'] ?? 0);
                                $qty   = $qtyCol  ? trim((string)($r[$qtyCol]  ?? '')) : '';
                                $unit  = $unitCol ? trim((string)($r[$unitCol] ?? '')) : '';
                                $note  = $noteCol ? trim((string)($r[$noteCol] ?? '')) : '';
                            ?>
                            <tr>
                                <td>
                                    <select name="ing_id[]" class="form-select" required>
                                        <option value="">— เลือกวัตถุดิบ —</option>
                                        <?php $ingsList->data_seek(0); while($i=$ingsList->fetch_assoc()): ?>
                                            <option value="<?= (int)$i['ingredient_id'] ?>" <?= $ingId===(int)$i['ingredient_id']?'selected':'' ?>><?= e($i['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </td>
                                <td><input class="form-control" name="qty[]" value="<?= e($qty) ?>" placeholder="เช่น 2"></td>
                                <td><input class="form-control" name="unit[]" value="<?= e($unit) ?>" placeholder="เช่น ช้อนชา"></td>
                                <td><input class="form-control" name="note[]" value="<?= e($note) ?>" placeholder="หมายเหตุเพิ่มเติม"></td>
                                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">ลบ</button></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <select name="ing_id[]" class="form-select">
                                        <option value="">— เลือกวัตถุดิบ —</option>
                                        <?php while($i=$ingsList->fetch_assoc()): ?>
                                            <option value="<?= (int)$i['ingredient_id'] ?>"><?= e($i['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </td>
                                <td><input class="form-control" name="qty[]" placeholder="เช่น 2"></td>
                                <td><input class="form-control" name="unit[]" placeholder="เช่น ช้อนชา"></td>
                                <td><input class="form-control" name="note[]" placeholder="หมายเหตุเพิ่มเติม"></td>
                                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">ลบ</button></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-outline-primary" id="addIng">+ เพิ่มวัตถุดิบ</button>
        </div>
    </div>

    <div class="card mb-4 cb-card">
        <div class="card-header bg-white fw-semibold">ขั้นตอนการทำ</div>
        <div class="card-body">
            <div id="stepList">
                <?php if (!empty($recipeSteps)): $n=0; foreach($recipeSteps as $t): $n++; ?>
                    <div class="input-group mb-2 step-item">
                        <span class="input-group-text">ขั้นที่ <?= $n ?></span>
                        <textarea class="form-control" name="step_text[]" rows="2" placeholder="อธิบายขั้นตอน..."><?= e($t) ?></textarea>
                        <button type="button" class="btn btn-outline-danger" onclick="removeStep(this)">ลบ</button>
                    </div>
                <?php endforeach; else: ?>
                    <div class="input-group mb-2 step-item">
                        <span class="input-group-text">ขั้นที่ 1</span>
                        <textarea class="form-control" name="step_text[]" rows="2" placeholder="อธิบายขั้นตอน..."></textarea>
                        <button type="button" class="btn btn-outline-danger" onclick="removeStep(this)">ลบ</button>
                    </div>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-outline-primary" id="addStep">+ เพิ่มขั้นตอน</button>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-success px-4" type="submit">บันทึก</button>
        <a class="btn btn-outline-secondary" href="<?= BASE_PATH ?>/manage_recipes.php">ยกเลิก</a>
    </div>
</form>

<script>
    // Live preview for image upload
    document.querySelector('input[name="image"]')?.addEventListener('change', e => {
        const file = e.target.files?.[0];
        if (!file) return;
        document.getElementById('coverPreview').src = URL.createObjectURL(file);
    });

    // Dynamic Ingredients
    document.getElementById('addIng')?.addEventListener('click', () => {
        const tbody = document.getElementById('ingRows');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <select name="ing_id[]" class="form-select">
                    <option value="">— เลือกวัตถุดิบ —</option>
                    ${[...document.querySelectorAll('#ingRows select:first-of-type option')].map(o=>`<option value="${o.value}">${o.textContent}</option>`).join('')}
                </select>
            </td>
            <td><input class="form-control" name="qty[]" placeholder="เช่น 2"></td>
            <td><input class="form-control" name="unit[]" placeholder="เช่น ช้อนชา"></td>
            <td><input class="form-control" name="note[]" placeholder="หมายเหตุเพิ่มเติม"></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">ลบ</button></td>
        `;
        tbody.appendChild(tr);
    });
    function removeRow(btn){ const tr=btn.closest('tr'); tr?.parentNode?.removeChild(tr); }

    // Dynamic Steps
    document.getElementById('addStep')?.addEventListener('click', () => {
        const wrap = document.getElementById('stepList');
        const idx = wrap.querySelectorAll('.step-item').length + 1;
        const div = document.createElement('div');
        div.className = 'input-group mb-2 step-item';
        div.innerHTML = `
            <span class="input-group-text">ขั้นที่ ${idx}</span>
            <textarea class="form-control" name="step_text[]" rows="2" placeholder="อธิบายขั้นตอน..."></textarea>
            <button type="button" class="btn btn-outline-danger" onclick="removeStep(this)">ลบ</button>
        `;
        wrap.appendChild(div);
    });
    function removeStep(btn){ const item=btn.closest('.step-item'); item?.parentNode?.removeChild(item); // re-number
        document.querySelectorAll('#stepList .input-group-text').forEach((el,i)=> el.textContent='ขั้นที่ '+(i+1)); }

    // Bootstrap client-side validation
    (() => {
        const forms = document.querySelectorAll('.needs-validation');
        Array.from(forms).forEach(form => {
            form.addEventListener('submit', e => {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>