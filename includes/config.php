<?php
// ระบุ base URL ของเว็บแอดมินให้ชัด (แก้ให้ตรงโปรเจกต์คุณ)
define('BASE_URL', '/cookbook_admin'); // ถ้าอยู่ราก htdocs ให้เป็น '' (สตริงว่าง)

// ทางไฟล์ระบบ (ฝั่งเซิร์ฟเวอร์)
define('ROOT_DIR', realpath(__DIR__ . '/..'));                 // .../cookbook_admin
define('UPLOADS_DIR', ROOT_DIR . '/uploads');                  // .../cookbook_admin/uploads
define('ASSETS_URL', BASE_URL . '/assets');                    // /cookbook_admin/assets
define('UPLOADS_URL', BASE_URL . '/uploads');                  // /cookbook_admin/uploads

// escape สั้นๆ
if (!function_exists('e')) {
    function e(string $s=null){ 
        return htmlspecialchars($s ?? '', ENT_QUOTES,'UTF-8'); 
    }
}