<?php
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit.php';
csrf_verify();

$uid=(int)($_POST['user_id']??0);
$ings = isset($_POST['ing'])? array_map('intval', (array)$_POST['ing']) : [];
if($uid>0){
  $ok = true;
  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("DELETE FROM allergyinfo WHERE user_id=?");
    if (!$stmt) throw new Exception('prepare delete failed');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();

    if($ings){
      $ins=$conn->prepare("INSERT INTO allergyinfo(user_id,ingredient_id) VALUES(?,?)");
      if (!$ins) throw new Exception('prepare insert failed');
      foreach($ings as $iid){ $ins->bind_param('ii',$uid,$iid); $ins->execute(); }
      $ins->close();
    }

    $conn->commit();
  } catch (Throwable $e) {
    $ok = false; $conn->rollback();
  }

  audit_log('update','allergyinfo',$uid,[ 'count'=>count($ings) ]);
  if ($ok) { flash('บันทึกข้อมูลภูมิแพ้เรียบร้อย'); }
  else { flash('เกิดข้อผิดพลาดระหว่างบันทึกข้อมูลภูมิแพ้','danger'); }
}
header('Location: '.BASE_PATH.'/manage_allergies.php?user_id='.$uid); exit;
