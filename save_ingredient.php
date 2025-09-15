<?php
/**
 * save_ingredient.php
 *
 * ★ UPDATED (2025-08-24):
 *   - เปลี่ยนการอัปโหลดรูปให้ "ตั้งชื่อไฟล์ตาม id" เสมอ:
 *       uploads/ingredients/ingredients_{ingredient_id}.{ext}
 *     และเก็บใน DB เป็น relative path:
 *       ingredients/ingredients_{ingredient_id}.{ext}
 *   - รองรับทั้ง INSERT (2 จังหวะ: insert → ได้ id → ย้ายไฟล์/อัปเดต path) และ UPDATE
 *   - แยก helper ฟังก์ชันสำหรับตรวจ MIME, ลบไฟล์เก่าของ id เดิม, เซฟไฟล์ตาม id,
 *     และ insert สู่ตาราง media (ถ้ามี)
 *   - คอมเมนต์ละเอียด พร้อมการ bind_param แบบปลอดภัย
 *
 * ฟีเจอร์เดิม:
 *   - รับจากฟอร์ม ingredient_form.php
 *   - Soft delete ด้วย POST + CSRF
 *   - เก็บ path เป็น "relative" เพื่อความสวยงาม
 */

require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
csrf_verify();

/* ────────────────────────────────────────────────────────────
 * Helpers: โครงสร้างสคีมา / ยูทิลรูปภาพ
 * ──────────────────────────────────────────────────────────── */

/** มีคอลัมน์นี้ในตารางไหม (กันสคีมาแตกต่างกัน) */
function column_exists(mysqli $conn, string $table, string $column): bool {
  $q="SELECT COUNT(*) c
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  $s=$conn->prepare($q);
  $s->bind_param('ss',$table,$column);
  $s->execute();
  return (int)($s->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}

/** แม็พ MIME → นามสกุลไฟล์ที่อนุญาต */
function _image_ext(string $mime): ?string {
  static $ok = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
  ];
  return $ok[$mime] ?? null;
}

/** ลบไฟล์เก่าของ id เดียวกัน (กันเหลือหลายสกุล/ค้างเก่า) เช่น ingredients_15.* */
function _purge_old_named_files(string $dirAbs, string $prefix, int $id): void {
  foreach (glob($dirAbs . '/' . $prefix . $id . '.*') as $old) {
    @unlink($old);
  }
}

/**
 * ★ UPDATED: เซฟอัปโหลดเป็นชื่อ "ตาม id"
 * - ย้ายจาก tmp → uploads/{dirRel}/{prefix}{id}.{ext}
 * - คืนข้อมูลเมตา (file_rel, mime, ขนาด ฯลฯ) เพื่อนำไป UPDATE DB / insert media
 */
function save_upload_named_by_id(array $file, int $id, string $dirRel, string $prefix): ?array {
  if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;

  $tmp  = $file['tmp_name'];
  if (!is_uploaded_file($tmp)) return null; // safety

  $mime = mime_content_type($tmp) ?: '';
  $ext  = _image_ext($mime);
  if (!$ext) return null;

  // จำกัดขนาดไฟล์ (ปรับได้ตามนโยบายของโปรเจค)
  if (($file['size'] ?? 0) > 3 * 1024 * 1024) return null; // ≤ 3MB

  $dirAbs = __DIR__ . '/uploads/' . trim($dirRel, '/');
  if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);

  // ลบไฟล์เก่าที่ชื่อชนิดเดียวกันของ id นี้ก่อน
  _purge_old_named_files($dirAbs, $prefix, $id);

  $destAbs = $dirAbs . '/' . $prefix . $id . '.' . $ext;
  if (!move_uploaded_file($tmp, $destAbs)) return null;

  [$w,$h] = @getimagesize($destAbs) ?: [null,null];
  $bytes  = (int)@filesize($destAbs);
  $hash   = @sha1_file($destAbs) ?: null;

  return [
    'file_rel' => trim($dirRel, '/') . '/' . $prefix . $id . '.' . $ext, // e.g. ingredients/ingredients_15.png
    'file_abs' => $destAbs,
    'mime'     => $mime,
    'width'    => $w,
    'height'   => $h,
    'bytes'    => $bytes,
    'hash'     => $hash,
  ];
}

/**
 * ★ UPDATED: แทรก metadata ลงตาราง media ถ้ามีตารางนี้อยู่
 * - เก็บ file_path เป็น "relative" เช่น ingredients/ingredients_15.png
 * - คืน media_id (หรือ null ถ้าไม่มีตาราง media)
 */
function insert_media_if_available(mysqli $conn, array $meta, ?string $alt=null): ?int {
  $q="SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media'";
  $c=$conn->query($q)->fetch_assoc();
  if ((int)($c['c'] ?? 0)===0) return null;

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
 * Soft delete (POST only)
 * ──────────────────────────────────────────────────────────── */
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id>0 && column_exists($conn,'ingredients','deleted_at')) {
    $s=$conn->prepare("UPDATE ingredients SET deleted_at=NOW() WHERE ingredient_id=? LIMIT 1");
    $s->bind_param('i',$id);
    $s->execute();
  }
  header('Location: '.BASE_PATH.'/manage_ingredients.php');
  exit;
}

/* ────────────────────────────────────────────────────────────
 * Insert / Update
 * ──────────────────────────────────────────────────────────── */
$ingredientId = (int)($_POST['ingredient_id'] ?? 0);
$name         = trim($_POST['name'] ?? '');
$categoryReq  = trim($_POST['category'] ?? 'default'); // ถ้ามีฟิลด์ category ในฟอร์ม

// ไม่มีชื่อ → กลับ
if ($name === '') {
  header('Location: ' . BASE_PATH . '/manage_ingredients.php');
  exit;
}

// ตรวจสอบคอลัมน์ที่มีจริงใน DB (รองรับสคีมาต่างกันได้)
$nameCol     = column_exists($conn, 'ingredients', 'name') ? 'name' : 'ingredient_name';
$imageCol    = column_exists($conn, 'ingredients', 'image_path') ? 'image_path'
            : (column_exists($conn, 'ingredients', 'image_url') ? 'image_url' : null);
$hasMediaCol = column_exists($conn, 'ingredients', 'media_id');
$hasCategory = column_exists($conn, 'ingredients', 'category');

/* ── กรณี UPDATE ─────────────────────────────────────────── */
if ($ingredientId > 0) {
  // 1) อัปเดตข้อมูลพื้นฐาน (ชื่อ + category ถ้ามี)
  $set  = [$nameCol . '=?'];
  $vals = [$name];
  $types= 's';

  if ($hasCategory) {
    $set[]  = 'category=?';
    $vals[] = $categoryReq;
    $types .= 's';
  }

  $sql = "UPDATE ingredients SET ".implode(', ', $set)." WHERE ingredient_id=? LIMIT 1";
  $vals[] = $ingredientId;
  $types .= 'i';

  $st = $conn->prepare($sql);
  $st->bind_param($types, ...$vals);
  $st->execute();

  // 2) ถ้ามีไฟล์รูป → เซฟตาม id แล้ว UPDATE path (+media)
  if (!empty($_FILES['image']['name'])) {
    $named = save_upload_named_by_id($_FILES['image'], $ingredientId, 'ingredients', 'ingredients_');
    if ($named) {
      $set=[]; $vals=[]; $types='';
      if ($imageCol) {
        $set[]   = "$imageCol=?";
        $vals[]  = $named['file_rel'];  // เก็บ relative path
        $types  .= 's';
      }
      if ($hasMediaCol) {
        $mid = insert_media_if_available($conn, $named, $name);
        if ($mid) {
          $set[]  = "media_id=?";
          $vals[] = $mid;
          $types .= 'i';
        }
      }
      if ($set) {
        $sql="UPDATE ingredients SET ".implode(', ', $set)." WHERE ingredient_id=? LIMIT 1";
        $vals[]=$ingredientId; $types.='i';
        $st2=$conn->prepare($sql);
        $st2->bind_param($types, ...$vals);
        $st2->execute();
      }
    }
  }

  header('Location: ' . BASE_PATH . '/manage_ingredients.php');
  exit;
}

/* ── กรณี INSERT (2 จังหวะ) ──────────────────────────────── */
// 1) INSERT ขั้นแรก (ยังไม่มีรูป) เพื่อให้ได้ ingredient_id
$cols = [$nameCol];
$vals = [$name];
$qs   = ['?'];
$types= 's';

if ($hasCategory) {
  $cols[] = 'category';
  $vals[] = $categoryReq;
  $qs[]   = '?';
  $types .= 's';
}

$sql = "INSERT INTO ingredients (".implode(',', $cols).") VALUES (".implode(',', $qs).")";
$st  = $conn->prepare($sql);
$st->bind_param($types, ...$vals);
$st->execute();
$ingredientId = (int)$st->insert_id;

// 2) ถ้ามีไฟล์ → เซฟเป็นชื่อ ingredients_{id}.ext แล้ว UPDATE path (+media)
if (!empty($_FILES['image']['name'])) {
  $named = save_upload_named_by_id($_FILES['image'], $ingredientId, 'ingredients', 'ingredients_');
  if ($named) {
    $set=[]; $vals=[]; $types='';
    if ($imageCol) {
      $set[]   = "$imageCol=?";
      $vals[]  = $named['file_rel']; // เก็บ relative path
      $types  .= 's';
    }
    if ($hasMediaCol) {
      $mid = insert_media_if_available($conn, $named, $name);
      if ($mid) {
        $set[]  = "media_id=?";
        $vals[] = $mid;
        $types .= 'i';
      }
    }
    if ($set) {
      $sql="UPDATE ingredients SET ".implode(', ', $set)." WHERE ingredient_id=? LIMIT 1";
      $vals[]=$ingredientId; $types.='i';
      $st2=$conn->prepare($sql);
      $st2->bind_param($types, ...$vals);
      $st2->execute();
    }
  }
}

header('Location: ' . BASE_PATH . '/manage_ingredients.php');
exit;
