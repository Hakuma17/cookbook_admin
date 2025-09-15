<?php
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
csrf_verify();

$uid=(int)($_POST['user_id']??0);
if($uid>0){
  $new=bin2hex(random_bytes(4)).rand(10,99); // ตัวอย่าง: 8 ตัว + 2 ตัวเลข
  $hash=password_hash($new,PASSWORD_DEFAULT);
  $st=$conn->prepare("UPDATE user SET password=? WHERE user_id=? AND deleted_at IS NULL");
  $st->bind_param('si',$hash,$uid); $st->execute();
  flash("รหัสผ่านใหม่ของผู้ใช้ #{$uid}: <code>{$new}</code> (คัดลอกเก็บไว้ให้ผู้ใช้ เปลี่ยนได้ภายหลัง)","warning");
}
header('Location: '.BASE_PATH.'/manage_users.php'); exit;
// หมายเหตุ: ถ้า user ถูกลบ (soft delete) แล้ว จะไม่สามารถรีเซ็ตรหัสผ่านได้
// (ป้องกันการรีเซ็ตบัญชีที่ถูกลบไปแล้ว)