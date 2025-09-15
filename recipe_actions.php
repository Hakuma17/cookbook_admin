<?php
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
csrf_verify();

$action=$_POST['action']??''; $id=(int)($_POST['id']??0);
if($id<=0) { header('Location: '.BASE_PATH.'/manage_recipes.php'); exit; }

if($action==='publish'){
  $st=$conn->prepare("UPDATE recipe SET status='published', published_at=COALESCE(published_at,NOW()) WHERE recipe_id=?");
  $st->bind_param('i',$id); $st->execute();
}else if($action==='unpublish'){
  $st=$conn->prepare("UPDATE recipe SET status='draft' WHERE recipe_id=?");
  $st->bind_param('i',$id); $st->execute();
}else if($action==='archive'){
  $st=$conn->prepare("UPDATE recipe SET status='archived' WHERE recipe_id=?");
  $st->bind_param('i',$id); $st->execute();
}else if($action==='delete'){
  // soft delete
  if(column_exists($conn,'recipe','deleted_at')){
    $st=$conn->prepare("UPDATE recipe SET deleted_at=NOW() WHERE recipe_id=?"); $st->bind_param('i',$id); $st->execute();
  }else{
    $st=$conn->prepare("DELETE FROM recipe WHERE recipe_id=?"); $st->bind_param('i',$id); $st->execute();
  }
}
header('Location: '.BASE_PATH.'/manage_recipes.php'); exit;

function column_exists(mysqli $c,$t,$col){$s=$c->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$s->bind_param('ss',$t,$col);$s->execute();return (int)($s->get_result()->fetch_assoc()['c']??0)>0;}
