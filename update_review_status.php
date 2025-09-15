<?php
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit.php';
csrf_verify();

function column_exists(mysqli $conn, string $table, string $column): bool {
	$s=$conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
	if(!$s) return false; $s->bind_param('ss',$table,$column); $s->execute(); $r=$s->get_result()->fetch_assoc(); return (int)($r['c']??0)>0;
}

// recalc average rating after changes
function recalc_recipe_rating(mysqli $conn, int $recipeId): void {
	if ($recipeId<=0) return;
	$hasStatus   = column_exists($conn,'review','status');
	$hasDeleted  = column_exists($conn,'review','deleted_at');
	$cond = '';
	if ($hasStatus)  $cond .= " AND status='approved'";
	if ($hasDeleted) $cond .= " AND deleted_at IS NULL";
	$st=$conn->prepare("SELECT AVG(rating) avg_rating, COUNT(*) n FROM review WHERE recipe_id=? $cond");
	$st->bind_param('i',$recipeId); $st->execute(); $row=$st->get_result()->fetch_assoc();
	$avg=(float)($row['avg_rating']??0); $n=(int)($row['n']??0);
	$u=$conn->prepare("UPDATE recipe SET average_rating=?, nReviewer=? WHERE recipe_id=?");
	$u->bind_param('dii',$avg,$n,$recipeId); $u->execute();
}

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);
$back   = BASE_PATH . '/manage_reviews.php';
if ($id<=0 || $action==='') { header('Location: '.$back); exit; }

// fetch recipe id for rating recalculation
$rid = 0; $s=$conn->prepare("SELECT recipe_id FROM review WHERE review_id=?");
$s->bind_param('i',$id); $s->execute(); if($row=$s->get_result()->fetch_assoc()) $rid=(int)$row['recipe_id'];

$hasStatus  = column_exists($conn,'review','status');
$hasDeleted = column_exists($conn,'review','deleted_at');

if ($action==='approve' && $hasStatus) {
	$st=$conn->prepare("UPDATE review SET status='approved' WHERE review_id=?");
	$st->bind_param('i',$id); $st->execute(); flash('อนุมัติรีวิวแล้ว'); audit_log('approve','review',$id);
} elseif ($action==='reject' && $hasStatus) {
	$st=$conn->prepare("UPDATE review SET status='rejected' WHERE review_id=?");
	$st->bind_param('i',$id); $st->execute(); flash('ปฏิเสธรีวิวแล้ว','warning'); audit_log('reject','review',$id);
} elseif ($action==='delete') {
	if ($hasDeleted) { $st=$conn->prepare("UPDATE review SET deleted_at=NOW() WHERE review_id=?"); }
	else { $st=$conn->prepare("DELETE FROM review WHERE review_id=?"); }
	$st->bind_param('i',$id); $st->execute(); flash('ลบรีวิวแล้ว','secondary'); audit_log('delete','review',$id);
}

if ($rid>0) recalc_recipe_rating($conn,$rid);
header('Location: '.$back); exit;
