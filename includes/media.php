<?php
/**
 * includes/media.php — แปลงค่าจาก DB → URL ที่เสิร์ฟได้ + ตรวจว่าไฟล์รูป "มีจริง" ไหม
 * จุดสำคัญ: ถ้าใช้รูป default_* ให้ exists=false เสมอ (ถือว่า "ยังไม่มีภาพจริง")
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

/* ========== API หลัก (เรียกใช้ทั่วระบบ) ========== */

/** คืน URL ที่ใช้โชว์ (จะเป็น default_* ถ้าไฟล์ไม่เจอ) */
function media_url(?string $stored, string $type='generic'): string {
  return media_probe($stored, $type)['url'];
}

/**
 * ตรวจเส้นทางรูปให้ละเอียด + บอกสถานะไฟล์
 * return: ['exists'=>bool, 'url'=>string, 'fs'=>?string, 'is_default'=>bool]
 *  - exists=true  : มี "ไฟล์จริง" ของรายการนั้น
 *  - exists=false : ไม่มีไฟล์จริง (แม้จะแสดง default)
 */
function media_probe(?string $stored, string $type='generic'): array {
  $stored = trim((string)$stored);

  [$dirWeb, $dirFs] = media_dir_for($type);
  $defaultUrl = media_default($type);    // URL ของ default_* หรือ SVG data-uri
  $defaultFs  = media_default_fs($type); // path ของ default_* ถ้ามี (SVG = null)

  // 1) ค่าว่าง → ใช้ default และถือว่าไม่มีภาพจริง
  if ($stored === '') {
    return ['exists'=>false, 'fs'=>$defaultFs, 'url'=>$defaultUrl, 'is_default'=>true];
  }

  // 2) เป็น URL ภายนอก → ถือว่ามีภาพจริง
  if (preg_match('~^https?://~i', $stored)) {
    return ['exists'=>true, 'fs'=>null, 'url'=>$stored, 'is_default'=>false];
  }

  // ปรับ \ → /
  $stored = str_replace('\\', '/', $stored);

  // 3) เริ่มด้วย uploads/...
  if (str_starts_with($stored, 'uploads/')) {
    $fs = base_path().'/'.$stored;
    if (is_file($fs)) return ['exists'=>true, 'fs'=>$fs, 'url'=>base_url().'/'.$stored, 'is_default'=>false];
    return ['exists'=>false, 'fs'=>$fs, 'url'=>$defaultUrl, 'is_default'=>true];
  }

  // 4) เริ่มด้วย ingredients|recipes|users/ → เติม uploads/
  if (preg_match('~^(ingredients|recipes|users)/~', $stored)) {
    $rel = 'uploads/'.ltrim($stored,'/');
    $fs  = base_path().'/'.$rel;
    if (is_file($fs)) return ['exists'=>true, 'fs'=>$fs, 'url'=>base_url().'/'.$rel, 'is_default'=>false];
    return ['exists'=>false, 'fs'=>$fs, 'url'=>$defaultUrl, 'is_default'=>true];
  }

  // 5) เป็นชื่อไฟล์ดิบ (ไม่มีสแลช) → ลองหาในโฟลเดอร์ชนิดนั้น
  if (!str_contains($stored,'/') && preg_match('~\.(jpe?g|png|webp)$~i', $stored)) {
    $fs = $dirFs.'/'.$stored;
    if (is_file($fs)) return ['exists'=>true, 'fs'=>$fs, 'url'=>$dirWeb.'/'.$stored, 'is_default'=>false];
    // auto-fix: ingredient_ → ingredients_
    if (stripos($stored,'ingredient_')===0) {
      $alt = 'ingredients_'.substr($stored, strlen('ingredient_'));
      if (is_file($dirFs.'/'.$alt)) return ['exists'=>true, 'fs'=>$dirFs.'/'.$alt, 'url'=>$dirWeb.'/'.$alt, 'is_default'=>false];
    }
    return ['exists'=>false, 'fs'=>$fs, 'url'=>$defaultUrl, 'is_default'=>true];
  }

  // 6) เป็นพาธ Windows เต็ม → พยายามตัดส่วน /uploads/
  if (preg_match('~^[a-z]:/|^//~i', $stored)) {
    $pos = stripos($stored, '/uploads/');
    if ($pos !== false) {
      $rel = ltrim(substr($stored, $pos+1), '/'); // ให้เหลือ uploads/...
      $fs  = base_path().'/'.$rel;
      if (is_file($fs)) return ['exists'=>true, 'fs'=>$fs, 'url'=>base_url().'/'.$rel, 'is_default'=>false];
    }
  }

  // 7) ไม่เข้าเคสใด → default และถือว่าไม่มีภาพจริง
  return ['exists'=>false, 'fs'=>$defaultFs, 'url'=>$defaultUrl, 'is_default'=>true];
}

/** true เมื่อมี “ไฟล์จริงของรายการนั้น” (ไม่ใช่รูป default) */
function media_exists(?string $stored, string $type='generic'): bool {
  return media_probe($stored, $type)['exists'] === true;
}

/* ========== ส่วน helper/สิ่งแวดล้อม ========== */

/** โฟลเดอร์อัปโหลดของแต่ละชนิด [web, fs] */
function media_dir_for(string $type): array {
  $folder = match ($type) {
    'ingredient' => 'ingredients',
    'user'       => 'users',
    default      => 'recipes',
  };
  return [base_url().'/uploads/'.$folder, base_path().'/uploads/'.$folder];
}

/** URL ของรูป default_* (ถ้าไม่มีไฟล์ → ใช้ SVG data-uri) */
function media_default(string $type='generic'): string {
  [$web, $fs] = media_dir_for($type);
  $file = match ($type) {
    'ingredient' => 'default_ingredients.png',
    'user'       => 'default_user.png',
    default      => 'default_recipe.png',
  };
  return is_file($fs.'/'.$file) ? ($web.'/'.$file) : default_svg_data_uri($type);
}

/** path ของไฟล์ default_* ถ้ามี (ถ้าเป็น SVG จะคืน null) */
function media_default_fs(string $type='generic'): ?string {
  [, $fs] = media_dir_for($type);
  $file = match ($type) {
    'ingredient' => 'default_ingredients.png',
    'user'       => 'default_user.png',
    default      => 'default_recipe.png',
  };
  return is_file($fs.'/'.$file) ? ($fs.'/'.$file) : null;
}

/** สร้าง SVG data-uri ใช้แทน default_* เมื่อไฟล์ไม่มี */
function default_svg_data_uri(string $type): string {
  $label = $type==='ingredient' ? 'ING' : ($type==='user' ? 'USR' : 'IMG');
  $svg = rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="240">
       <rect width="100%" height="100%" fill="#fbf5f2"/>
       <rect x="16" y="16" width="288" height="208" rx="14" fill="#efe7e3"/>
       <text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle"
             font-family="system-ui,Segoe UI,Roboto" font-size="28" fill="#b9a79e">'.$label.'</text>
     </svg>'
  );
  return "data:image/svg+xml;charset=UTF-8,{$svg}";
}

/** base URL/FS ของโปรเจกต์ (ใช้ประกอบ path/url) */
function base_url(): string { return rtrim(BASE_PATH, '/'); }
function base_path(): string { return realpath(__DIR__.'/..'); }
