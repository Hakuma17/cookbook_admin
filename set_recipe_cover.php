<?php
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
csrf_verify();

$rid=(int)($_POST['recipe_id']??0);
$mid=(int)($_POST['media_id']??0);
if($rid>0 && $mid>0){
  $st=$conn->prepare("UPDATE recipe SET media_id=? WHERE recipe_id=?");
  $st->bind_param('ii',$mid,$rid); $st->execute();
}
header('Location: '.BASE_PATH.'/media_library.php?for=recipe&id='.$rid); exit;
