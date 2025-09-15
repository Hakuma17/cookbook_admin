<?php
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit.php';
csrf_verify();

// Determine target context (which folder to save under) – default to recipes
$for  = $_POST['for'] ?? ($_GET['for'] ?? '');
$refId= (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
$folder = match ($for) {
	'ingredient' => 'ingredients',
	'user'       => 'users',
	default      => 'recipes',
};

// Helpers
function column_exists(mysqli $conn, string $table, string $column): bool {
	$s=$conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
	if(!$s) return false; $s->bind_param('ss',$table,$column); $s->execute(); $r=$s->get_result()->fetch_assoc(); return (int)($r['c']??0)>0;
}

function ensure_dir(string $abs): void { if(!is_dir($abs)) { @mkdir($abs, 0775, true); } }

function allowed_ext(string $mime): ?string {
	return match (strtolower($mime)) {
		'image/jpeg', 'image/jpg' => 'jpg',
		'image/png'               => 'png',
		'image/webp'              => 'webp',
		'image/gif'               => 'gif',
		default                   => null,
	};
}

// Storage base
$root = realpath(__DIR__);
$uploadsDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $folder; // fs
ensure_dir($uploadsDir);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$files = $_FILES['files'] ?? null;

$inserted = 0; $skipped = 0; $errors = [];
$insertedIds = [];

if (!$files || !is_array($files['name'])) {
	flash('ไม่พบไฟล์สำหรับอัปโหลด', 'danger');
	header('Location: ' . BASE_PATH . '/media_library.php' . ($for?('?for='.$for.($refId?('&id='.$refId):'')) : ''));
	exit;
}

// Prepare dynamic insert to media table if it exists
$tableMediaExists = (function(mysqli $conn){
	$q = "SELECT COUNT(*) c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media'";
	$c = $conn->query($q)->fetch_assoc();
	return (int)($c['c'] ?? 0) > 0;
})($conn);

$hasAlt   = $tableMediaExists ? column_exists($conn, 'media', 'alt') : false;
$hasOwner = $tableMediaExists ? column_exists($conn, 'media', 'owner_user_id') : false;
$hasHash  = $tableMediaExists ? column_exists($conn, 'media', 'hash_sha1') : false;
$hasCreated= $tableMediaExists ? column_exists($conn, 'media', 'created_at') : false;

$uid = $_SESSION['user_id'] ?? null;

for ($i=0; $i<count($files['name']); $i++) {
	$err  = $files['error'][$i];
	if ($err === UPLOAD_ERR_NO_FILE) { $skipped++; continue; }
	if ($err !== UPLOAD_ERR_OK) { $skipped++; $errors[] = 'อัปโหลดล้มเหลว (error='.$err.')'; continue; }

	$tmp  = $files['tmp_name'][$i];
	$name = $files['name'][$i];
	$mime = $finfo->file($tmp) ?: ($files['type'][$i] ?? '');
	$ext  = allowed_ext($mime);
	if (!$ext) { $skipped++; $errors[] = e($name) . ' ไม่รองรับชนิดไฟล์'; continue; }

	// Read image size
	$sizeInfo = @getimagesize($tmp);
	$w = $sizeInfo[0] ?? null; $h = $sizeInfo[1] ?? null;
	$hash = sha1_file($tmp);

	// Unique filename: media_YYYYmmdd_His_rand.ext
	$base = 'media_' . date('Ymd_His') . '_' . substr($hash,0,8) . '.' . $ext;
	$abs  = $uploadsDir . DIRECTORY_SEPARATOR . $base;
	// In rare collisions, add random suffix
	$tries=0; while (file_exists($abs) && $tries<3) { $tries++; $base = 'media_' . date('Ymd_His') . '_' . substr($hash,0,8) . '_' . mt_rand(10,99) . '.' . $ext; $abs = $uploadsDir . DIRECTORY_SEPARATOR . $base; }

	if (!@move_uploaded_file($tmp, $abs)) {
		// Fallback copy if move failed (sometimes on Windows)
		if (!@copy($tmp, $abs)) { $skipped++; $errors[] = 'บันทึกไฟล์ไม่สำเร็จ: '.e($name); continue; }
	}

	// Build metadata
	$bytes = filesize($abs) ?: 0;
	$rel   = $folder . '/' . $base; // e.g., recipes/media_....jpg

	if ($tableMediaExists) {
		$cols = ['file_path','mime','bytes','width','height'];
		$vals = [$rel, $mime, (int)$bytes, (int)($w??0), (int)($h??0)];
		$types= 'ssiii';
		if ($hasAlt)   { $cols[]='alt';            $vals[] = null; $types.='s'; }
		if ($hasOwner) { $cols[]='owner_user_id';  $vals[] = $uid;  $types.='i'; }
		if ($hasHash)  { $cols[]='hash_sha1';      $vals[] = $hash; $types.='s'; }
		if ($hasCreated){$cols[]='created_at';     $vals[] = date('Y-m-d H:i:s'); $types.='s'; }

		$ph = implode(',', array_fill(0, count($cols), '?'));
		$sql = 'INSERT INTO media (' . implode(',', $cols) . ') VALUES (' . $ph . ')';
		$st = $conn->prepare($sql);
		if ($st) {
			$st->bind_param($types, ...$vals);
			$st->execute();
			$mid = (int)$st->insert_id; $insertedIds[] = $mid;
		}
	}

	$inserted++;
}

// Optional: if context is recipe and only one uploaded, we could auto-select; keep manual for safety.

// Audit & flash
audit_log('upload', 'media', null, [
	'count_uploaded' => $inserted,
	'count_skipped'  => $skipped,
	'context'        => $for,
	'recipe_id'      => $refId,
	'media_ids'      => $insertedIds,
]);

if ($inserted>0) {
	flash("อัปโหลดสำเร็จ {$inserted} ไฟล์" . ($skipped?", ข้าม {$skipped}":''));
} else {
	$msg = $errors ? implode(' | ', $errors) : 'อัปโหลดไม่สำเร็จ';
	flash($msg, 'danger');
}

$to = BASE_PATH . '/media_library.php';
if ($for) { $to .= '?for=' . urlencode($for); if ($refId>0) $to .= '&id='.(int)$refId; }
header('Location: ' . $to);
exit;

