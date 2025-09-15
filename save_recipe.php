<?php
/**
 * save_recipe.php (Merged + ID-named image)
 * ------------------------------------------------------------
 * ★ UPDATED (2025-08-24):
 *   - เปลี่ยนการอัปโหลดรูปให้ตั้งชื่อไฟล์ตาม id เสมอ:
 *       uploads/recipes/recipe_{recipe_id}.{ext}
 *     และเก็บใน DB เป็น relative path:
 *       recipes/recipe_{recipe_id}.{ext}
 *   - ใช้ flow 2 จังหวะใน INSERT: insert ฐาน → ได้ id → เซฟรูปตาม id → UPDATE path/media
 *   - แยก helpers: ตรวจ MIME, ลบไฟล์เก่าของ id, เซฟไฟล์ตาม id, insert สู่ media (ถ้ามี)
 *
 * เดิม:
 *   - รับข้อมูลจาก recipe_form.php
 *   - ตรวจ slug ไม่ซ้ำ
 *   - INSERT/UPDATE + status/published_at
 *   - อัปเดตตารางเชื่อม category_recipe
 *   - flash() + redirect
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php'; // flash()
csrf_verify();

/* ────────────────────────────────────────────────────────────
 * Helpers
 * ──────────────────────────────────────────────────────────── */

/** ตรวจว่าคอลัมน์มีอยู่จริงไหม (รองรับสคีมาต่างกัน) */
function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return (int)($res['c'] ?? 0) > 0;
}

/** ตารางมีจริงไหม */
function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return (int)($res['c'] ?? 0) > 0;
}

/** slugify แบบพื้นฐาน */
function slugify(string $text): string {
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    if ($text === '') {
        return bin2hex(random_bytes(3));
    }
    return $text;
}

/** สร้าง slug ที่ไม่ซ้ำ (เติม -1, -2, ...) */
function get_unique_slug(mysqli $conn, string $slug, int $ignoreId = 0): string {
    $baseSlug = $slug;
    $i = 1;
    $stmt = $conn->prepare("SELECT recipe_id FROM recipe WHERE slug = ? AND recipe_id != ? LIMIT 1");
    while (true) {
        $currentSlug = $slug;
        $stmt->bind_param('si', $currentSlug, $ignoreId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $i++;
    }
}

/** ★ NEW: แม็พ MIME → นามสกุลรูปที่อนุญาต */
function _image_ext(string $mime): ?string {
    static $ok = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    return $ok[$mime] ?? null;
}

/** ★ NEW: ลบไฟล์เก่าของ id เดียวกัน (กันเหลือหลายสกุล) เช่น recipe_25.* */
function _purge_old_named_files(string $dirAbs, string $prefix, int $id): void {
    foreach (glob($dirAbs . '/' . $prefix . $id . '.*') as $old) {
        @unlink($old);
    }
}

/**
 * ★ NEW: เซฟไฟล์อัปโหลดเป็นชื่อ "ตาม id"
 * - ใส่ไว้ที่ uploads/{dirRel}/{prefix}{id}.{ext}
 * - คืน meta (file_rel/mime/size/hash/ขนาดภาพ) สำหรับ UPDATE DB หรือ insert media
 */
function save_upload_named_by_id(array $file, int $id, string $dirRel, string $prefix): ?array {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;

    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp)) return null; // safety

    $mime = mime_content_type($tmp) ?: '';
    $ext  = _image_ext($mime);
    if (!$ext) return null;

    // จำกัดขนาดไฟล์ (ปรับได้)
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) return null; // ≤ 3MB

    $dirAbs = __DIR__ . '/uploads/' . trim($dirRel, '/');
    if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);

    // ลบไฟล์เดิมของ id นี้ก่อน (กันไฟล์หลายสกุลค้าง)
    _purge_old_named_files($dirAbs, $prefix, $id);

    $destAbs = $dirAbs . '/' . $prefix . $id . '.' . $ext;
    if (!move_uploaded_file($tmp, $destAbs)) return null;

    [$w, $h] = @getimagesize($destAbs) ?: [null, null];
    $bytes   = (int)@filesize($destAbs);
    $hash    = @sha1_file($destAbs) ?: null;

    return [
        'file_rel' => trim($dirRel, '/') . '/' . $prefix . $id . '.' . $ext, // e.g. recipes/recipe_8.webp
        'file_abs' => $destAbs,
        'mime'     => $mime,
        'width'    => $w,
        'height'   => $h,
        'bytes'    => $bytes,
        'hash'     => $hash,
    ];
}

/**
 * ★ NEW: แทรกข้อมูลลงตาราง media ถ้ามี (และคืน media_id)
 * - เก็บ media.file_path เป็น relative เช่น recipes/recipe_8.webp
 */
function insert_media_if_available(mysqli $conn, array $meta, ?string $alt=null): ?int {
    // มีตาราง media ไหม
    $q = "SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media'";
    $c = $conn->query($q)->fetch_assoc();
    if ((int)($c['c'] ?? 0) === 0) return null;

    $uid = $_SESSION['user_id'] ?? null;
    $stmt = $conn->prepare(
        "INSERT INTO media(file_path,mime,bytes,width,height,alt,owner_user_id,hash_sha1)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param(
        'ssiiisis',
        $meta['file_rel'], $meta['mime'], $meta['bytes'], $meta['width'], $meta['height'],
        $alt, $uid, $meta['hash']
    );
    $stmt->execute();
    return (int)$stmt->insert_id;
}

/* ────────────────────────────────────────────────────────────
 * รับค่า POST
 * ──────────────────────────────────────────────────────────── */
$id           = (int)($_POST['recipe_id'] ?? 0);
$name         = trim($_POST['name'] ?? '');
$nServings    = max(1, (int)($_POST['nServings'] ?? 1));
$prepTime     = max(0, (int)($_POST['prep_time'] ?? 0));
$status       = $_POST['status'] ?? 'draft';
$slug         = trim($_POST['slug'] ?? '');
$published_at = !empty($_POST['published_at'])
                  ? date('Y-m-d H:i:s', strtotime($_POST['published_at']))
                  : null;
$cats         = (isset($_POST['categories']) && is_array($_POST['categories']))
                  ? array_map('intval', $_POST['categories']) : [];

// New arrays from dynamic form
$ingIds = isset($_POST['ing_id']) ? array_map('intval', (array)$_POST['ing_id']) : [];
$qtys   = isset($_POST['qty'])    ? array_map('strval', (array)$_POST['qty'])    : [];
$units  = isset($_POST['unit'])   ? array_map('strval', (array)$_POST['unit'])   : [];
$notes  = isset($_POST['note'])   ? array_map('strval', (array)$_POST['note'])   : [];
$steps  = isset($_POST['step_text']) ? array_map('strval', (array)$_POST['step_text']) : [];

/* ────────────────────────────────────────────────────────────
 * Validate + Slug
 * ──────────────────────────────────────────────────────────── */
if ($name === '') {
    flash('กรุณากรอกชื่อสูตรอาหาร', 'danger');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? BASE_PATH . '/manage_recipes.php'));
    exit;
}

// สร้าง/ตรวจ slug ไม่ให้ซ้ำ
if ($slug === '') $slug = slugify($name);
$slug = get_unique_slug($conn, $slug, $id);

// ตรวจคอลัมน์ที่อาจต่างกันตามสคีมา
$imageColumn   = column_exists($conn, 'recipe', 'image_path') ? 'image_path' : null;
$hasMediaField = column_exists($conn, 'recipe', 'media_id'); // มีคอลัมน์ media_id ในตาราง recipe ไหม

/* ────────────────────────────────────────────────────────────
 * UPDATE
 * ──────────────────────────────────────────────────────────── */
if ($id > 0) {
    // 1) อัปเดตฟิลด์หลักก่อน
    $params = [$name, $nServings, $prepTime, $status, $slug, $published_at];
    $types  = 'siisss';
    $sql    = "UPDATE recipe
               SET name=?, nServings=?, prep_time=?, status=?, slug=?, published_at=?
               WHERE recipe_id=? LIMIT 1";
    $params[] = $id;
    $types   .= 'i';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    // 2) ถ้ามีไฟล์ → เซฟเป็น recipe_{id}.ext แล้ว UPDATE path (+media)
    if (!empty($_FILES['image']['name'])) {
        $named = save_upload_named_by_id($_FILES['image'], $id, 'recipes', 'recipe_');
        if ($named) {
            $set=[]; $vals=[]; $t='';

            if ($imageColumn) {
                $set[] = "$imageColumn=?";
                $vals[]= $named['file_rel']; // relative path
                $t    .= 's';
            }
            if ($hasMediaField) {
                $mid = insert_media_if_available($conn, $named, $name);
                if ($mid) {
                    $set[] = "media_id=?";
                    $vals[]= $mid;
                    $t    .= 'i';
                }
            }

            if ($set) {
                $sql = "UPDATE recipe SET ".implode(',', $set)." WHERE recipe_id=? LIMIT 1";
                $vals[] = $id; $t .= 'i';
                $st2 = $conn->prepare($sql);
                $st2->bind_param($t, ...$vals);
                $st2->execute();
            }
        }
    }

} else {
    /* ────────────────────────────────────────────────────────
     * INSERT (2 จังหวะ: insert ฐาน → ได้ id → เซฟไฟล์/UPDATE)
     * ──────────────────────────────────────────────────────── */
    // 1) INSERT ขั้นแรก (ยังไม่ใส่รูป) เพื่อให้ได้ recipe_id
    $cols = ['name', 'nServings', 'prep_time', 'status', 'slug', 'published_at'];
    $qs   = ['?', '?', '?', '?', '?', '?'];
    $params = [$name, $nServings, $prepTime, $status, $slug, $published_at];
    $types  = 'siisss';

    $sql = "INSERT INTO recipe (".implode(',', $cols).") VALUES (".implode(',', $qs).")";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $id = (int)$stmt->insert_id;

    // 2) ถ้ามีไฟล์ → เซฟชื่อ recipe_{id}.ext แล้ว UPDATE path (+media)
    if (!empty($_FILES['image']['name'])) {
        $named = save_upload_named_by_id($_FILES['image'], $id, 'recipes', 'recipe_');
        if ($named) {
            $set=[]; $vals=[]; $t='';

            if ($imageColumn) {
                $set[] = "$imageColumn=?";
                $vals[]= $named['file_rel']; // relative path
                $t    .= 's';
            }
            if ($hasMediaField) {
                $mid = insert_media_if_available($conn, $named, $name);
                if ($mid) {
                    $set[] = "media_id=?";
                    $vals[]= $mid;
                    $t    .= 'i';
                }
            }

            if ($set) {
                $sql = "UPDATE recipe SET ".implode(',', $set)." WHERE recipe_id=? LIMIT 1";
                $vals[] = $id; $t .= 'i';
                $st2 = $conn->prepare($sql);
                $st2->bind_param($t, ...$vals);
                $st2->execute();
            }
        }
    }
}

/* ────────────────────────────────────────────────────────────
 * อัปเดตหมวดหมู่ในตารางเชื่อม
 * ──────────────────────────────────────────────────────────── */
if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM category_recipe WHERE recipe_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    if (!empty($cats)) {
        $ins = $conn->prepare("INSERT INTO category_recipe(recipe_id, category_id) VALUES(?, ?)");
        foreach ($cats as $cid) {
            $ins->bind_param('ii', $id, $cid);
            $ins->execute();
        }
    }
}

/* ────────────────────────────────────────────────────────────
 * วัตถุดิบ (recipe_ingredient) และ ขั้นตอน (recipe_step/fallback)
 * ──────────────────────────────────────────────────────────── */
if ($id > 0) {
    $conn->begin_transaction();
    try {
        // 1) Ingredients
        if (table_exists($conn, 'recipe_ingredient')) {
            $del = $conn->prepare("DELETE FROM recipe_ingredient WHERE recipe_id=?");
            $del->bind_param('i', $id); $del->execute();

            // ตรวจว่ามีคอลัมน์อะไรบ้าง
            $hasQty  = column_exists($conn,'recipe_ingredient','qty')
                       || column_exists($conn,'recipe_ingredient','quantity')
                       || column_exists($conn,'recipe_ingredient','amount')
                       || column_exists($conn,'recipe_ingredient','amount_text');
            $hasUnit = column_exists($conn,'recipe_ingredient','unit')
                       || column_exists($conn,'recipe_ingredient','unit_name');
            $hasNote = column_exists($conn,'recipe_ingredient','note')
                       || column_exists($conn,'recipe_ingredient','remarks')
                       || column_exists($conn,'recipe_ingredient','comment');
            $ordCol = null; foreach(['order_index','sort_order','position','step_no','seq'] as $c){ if(column_exists($conn,'recipe_ingredient',$c)){ $ordCol=$c; break; } }

            $cols=['recipe_id','ingredient_id']; $types='ii';
            if ($hasQty)  { $cols[]='qty';  $types.='s'; }
            if ($hasUnit) { $cols[]='unit'; $types.='s'; }
            if ($hasNote) { $cols[]='note'; $types.='s'; }
            if ($ordCol)  { $cols[]=$ordCol; $types.='i'; }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $sql = 'INSERT INTO recipe_ingredient('.implode(',', $cols).') VALUES('.$ph.')';
            $ins = $conn->prepare($sql);
            if ($ins) {
                $order=1;
                $n = max(count($ingIds), max(count($qtys), max(count($units), count($notes))));
                for ($i=0; $i<$n; $i++) {
                    $ing = (int)($ingIds[$i] ?? 0);
                    if ($ing <= 0) continue;
                    $qv = trim((string)($qtys[$i]  ?? ''));
                    $uv = trim((string)($units[$i] ?? ''));
                    $nv = trim((string)($notes[$i] ?? ''));
                    $params = [$id, $ing];
                    if ($hasQty)  $params[] = $qv;
                    if ($hasUnit) $params[] = $uv;
                    if ($hasNote) $params[] = $nv;
                    if ($ordCol)  $params[] = $order++;
                    $ins->bind_param($types, ...$params);
                    $ins->execute();
                }
            }
        }

        // 2) Steps
        if (table_exists($conn, 'recipe_step')) {
            $del = $conn->prepare("DELETE FROM recipe_step WHERE recipe_id=?");
            $del->bind_param('i', $id); $del->execute();

            // หา column ชื่อข้อความ และลำดับ
            $txtCol = null; foreach(['step_text','description','content','instruction','instructions','note','details'] as $c){ if(column_exists($conn,'recipe_step',$c)){ $txtCol=$c; break; } }
            $ordCol = null; foreach(['order_index','step_no','position','sort_order','seq'] as $c){ if(column_exists($conn,'recipe_step',$c)){ $ordCol=$c; break; } }

            if ($txtCol) {
                $cols=['recipe_id',$txtCol]; $types='is'; if ($ordCol) { $cols[]=$ordCol; $types.='i'; }
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $sql = 'INSERT INTO recipe_step('.implode(',', $cols).') VALUES('.$ph.')';
                $ins = $conn->prepare($sql);
                if ($ins) {
                    $o=1; foreach ($steps as $t) { $t=trim((string)$t); if($t==='') continue; $params=[$id,$t]; if($ordCol){ $params[]=$o++; } $ins->bind_param($types, ...$params); $ins->execute(); }
                }
            }
        } else {
            // Fallback: เก็บรวมเป็นข้อความบรรทัดในคอลัมน์เดียวของ recipe หากมี
            foreach (['instructions','method','directions','steps_text'] as $col) {
                if (column_exists($conn,'recipe',$col)) {
                    $text = trim(implode("\n", array_filter(array_map('trim',$steps), fn($x)=>$x!=='')));
                    $st = $conn->prepare("UPDATE recipe SET $col=? WHERE recipe_id=?");
                    $st->bind_param('si', $text, $id); $st->execute();
                    break;
                }
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        // ไม่ interrupt ผู้ใช้ แต่ยังคงบันทึกส่วนหลักสำเร็จ
    }
}

/* ────────────────────────────────────────────────────────────
 * Flash + Redirect
 * ──────────────────────────────────────────────────────────── */
flash('บันทึกสูตรอาหารเรียบร้อยแล้ว');
header('Location: ' . BASE_PATH . '/manage_recipes.php');
exit;
