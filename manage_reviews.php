<?php
/**
 * manage_reviews.php (Merged)
 * ------------------------------------------------------------
 * จัดการรีวิว (เวอร์ชันรวมความสามารถที่ดีที่สุด):
 * - รองรับ Soft Delete (ตั้งค่า deleted_at)
 * - คำนวณ Rating ของสูตรอาหารใหม่ทุกครั้งที่มีการเปลี่ยนแปลง (recalc_recipe_rating)
 * - มีฟิลเตอร์สถานะ (pending/approved/rejected) และคำค้น
 * - มีฟีเจอร์ไฮไลต์คอมเมนต์ที่มีคำหยาบ
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/csrf.php';

// ===== Helper Functions (ส่วนที่ดีที่สุดจากทั้งสองไฟล์) =====

function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return (int)($res['c'] ?? 0) > 0;
}

function profanity_badwords(): array {
    // เพิ่ม/แก้ไขรายการคำหยาบได้ตามต้องการ
    return ['เหี้ย', 'สัส', 'ควาย', 'fuck', 'shit'];
}

function contains_badword(string $text): bool {
    $lowerText = mb_strtolower($text, 'UTF-8');
    foreach (profanity_badwords() as $word) {
        if (mb_strpos($lowerText, $word) !== false) {
            return true;
        }
    }
    return false;
}

// คำนวณคะแนนเฉลี่ยของสูตรอาหารใหม่
function recalc_recipe_rating(mysqli $conn, int $recipeId) {
    if ($recipeId <= 0) return;
    
    $statusCheck = column_exists($conn, 'review', 'status') ? " AND status='approved'" : '';
    $deletedCheck = column_exists($conn, 'review', 'deleted_at') ? " AND deleted_at IS NULL" : '';

    $stmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as num_reviews FROM review WHERE recipe_id=? {$statusCheck} {$deletedCheck}");
    $stmt->bind_param('i', $recipeId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    $avgRating = (float)($result['avg_rating'] ?? 0);
    $numReviews = (int)($result['num_reviews'] ?? 0);

    $updateStmt = $conn->prepare("UPDATE recipe SET average_rating=?, nReviewer=? WHERE recipe_id=?");
    $updateStmt->bind_param('dii', $avgRating, $numReviews, $recipeId);
    $updateStmt->execute();
}


// ===== Action Handler (อนุมัติ/ปฏิเสธ/ลบ) =====
$hasStatus = column_exists($conn, 'review', 'status');
$hasDeletedAt = column_exists($conn, 'review', 'deleted_at');

if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    $recipeIdToUpdate = 0;

    // ดึง recipe_id ก่อนดำเนินการ เพื่อใช้คำนวณ rating ใหม่
    if ($id > 0) {
        $stmt = $conn->prepare("SELECT recipe_id FROM review WHERE review_id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $recipeIdToUpdate = (int)$row['recipe_id'];
        }
    }

    if ($recipeIdToUpdate > 0) {
        if ($action === 'approve' && $hasStatus) {
            $stmt = $conn->prepare("UPDATE review SET status='approved' WHERE review_id=?");
        } elseif ($action === 'reject' && $hasStatus) {
            $stmt = $conn->prepare("UPDATE review SET status='rejected' WHERE review_id=?");
        } elseif ($action === 'delete') {
            if ($hasDeletedAt) { // Soft Delete
                $stmt = $conn->prepare("UPDATE review SET deleted_at=NOW() WHERE review_id=?");
            } else { // Hard Delete
                $stmt = $conn->prepare("DELETE FROM review WHERE review_id=?");
            }
        }
        
        if (isset($stmt)) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            recalc_recipe_rating($conn, $recipeIdToUpdate);
        }
    }
    header('Location: ' . BASE_PATH . '/manage_reviews.php');
    exit;
}

// ===== การกรองข้อมูล (Filters) =====
$status = ($hasStatus && isset($_GET['status'])) ? trim($_GET['status']) : '';
$q = trim($_GET['q'] ?? '');
$offensive = isset($_GET['bad']) ? 1 : 0;

$whereParts = [];
$params = [];
$types = '';

// กรองข้อมูลที่ถูก soft delete ออกเสมอ (ถ้ามีคอลัมน์)
if ($hasDeletedAt) {
    $whereParts[] = "r.deleted_at IS NULL";
}

if ($hasStatus && in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $whereParts[] = "r.status = ?";
    $types .= 's';
    $params[] = $status;
}

if ($q !== '') {
    $whereParts[] = "(rec.name LIKE CONCAT('%', ?, '%') OR COALESCE(u.profile_name, u.email) LIKE CONCAT('%', ?, '%') OR r.comment LIKE CONCAT('%', ?, '%'))";
    $types .= 'sss';
    array_push($params, $q, $q, $q);
}

$whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$sql = "SELECT r.review_id, r.recipe_id, r.user_id, r.rating, r.comment, r.created_at" . ($hasStatus ? ", r.status" : "") . ",
               rec.name AS recipe_name, COALESCE(u.profile_name, u.email) AS user_name
        FROM review r
        LEFT JOIN recipe rec ON rec.recipe_id = r.recipe_id
        LEFT JOIN user u ON u.user_id = r.user_id
        $whereSql
        ORDER BY r.created_at DESC
        LIMIT 300";

$stmt = $conn->prepare($sql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result();
?>

<style>
    .cb-card { border-radius: 16px; border-color: #efe7e3; }
    .table thead th { background: #f5ede9; border-color: #efe7e3; }
    .table td, .table th { border-color: #efe7e3; }
    .form-control, .form-select { border-radius: 12px; border-color: #e9dfda; }
    .badge { border-radius: 999px; }
    .btn { border-radius: 14px; }
    .offensive { background-color: #ffe0e0; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="mb-0">รีวิวจากผู้ใช้</h2>
    <a href="<?= BASE_PATH ?>/index.php" class="btn btn-outline-secondary">← กลับแดชบอร์ด</a>
</div>

<form class="row g-2 align-items-center mb-3" method="get">
    <?php if ($hasStatus): ?>
        <div class="col-auto">
            <select class="form-select" name="status" onchange="this.form.submit()">
                <option value="">สถานะทั้งหมด</option>
                <option value="pending"  <?= $status === 'pending' ? 'selected' : '' ?>>รอตรวจ</option>
                <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>อนุมัติ</option>
                <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>ปฏิเสธ</option>
            </select>
        </div>
    <?php endif; ?>
    <div class="col-sm-6 col-md-5"><input class="form-control" name="q" placeholder="ค้นหา: ชื่อสูตร/ผู้ใช้/คอมเมนต์" value="<?= e($q) ?>"></div>
    <div class="col-auto form-check"><input class="form-check-input" type="checkbox" id="bad" name="bad" value="1" <?= $offensive ? 'checked' : '' ?> onchange="this.form.submit()"><label class="form-check-label" for="bad">ไฮไลต์คำหยาบ</label></div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">ค้นหา</button></div>
    <?php if ($status !== '' || $q !== '' || $offensive): ?>
        <div class="col-auto"><a class="btn btn-outline-secondary" href="<?= BASE_PATH ?>/manage_reviews.php">ล้างตัวกรอง</a></div>
    <?php endif; ?>
</form>

<div class="table-responsive">
    <table class="table align-middle bg-white table-hover cb-card">
        <thead>
            <tr>
                <th style="width:80px">ID</th>
                <th>สูตร</th>
                <th>ผู้ใช้</th>
                <th class="text-center" style="width:110px">เรตติ้ง</th>
                <th>คอมเมนต์</th>
                <?php if ($hasStatus): ?><th class="text-center" style="width:120px">สถานะ</th><?php endif; ?>
                <th class="text-end" style="width:280px">การจัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($r = $rows->fetch_assoc()):
                $isOffensive = $offensive && contains_badword($r['comment'] ?? '');
            ?>
                <tr class="<?= $isOffensive ? 'offensive' : '' ?>">
                    <td class="fw-semibold"><?= (int)$r['review_id'] ?></td>
                    <td><?= e($r['recipe_name'] ?? ('#' . $r['recipe_id'])) ?></td>
                    <td><?= e($r['user_name'] ?? ('#' . $r['user_id'])) ?></td>
                    <td class="text-center">⭐ <?= number_format((float)$r['rating'], 1) ?></td>
                    <td style="max-width:400px">
                        <div class="text-truncate" title="<?= e($r['comment'] ?? '') ?>">
                            <?= e($r['comment'] ?? '') ?>
                        </div>
                    </td>
                    <?php if ($hasStatus): ?>
                        <td class="text-center">
                            <?php if ($r['status'] === 'approved'): ?><span class="badge text-bg-success px-3">อนุมัติ</span>
                            <?php elseif ($r['status'] === 'rejected'): ?><span class="badge text-bg-danger px-3">ปฏิเสธ</span>
                            <?php else: ?><span class="badge text-bg-secondary px-3">รอตรวจ</span><?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="text-end">
                                                <?php if ($hasStatus): ?>
                                                    <form action="<?= BASE_PATH ?>/update_review_status.php" method="post" class="d-inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="id" value="<?= (int)$r['review_id'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button class="btn btn-sm btn-success" onclick="return confirm('อนุมัติรีวิวนี้ใช่ไหม?');">อนุมัติ</button>
                                                    </form>
                                                    <form action="<?= BASE_PATH ?>/update_review_status.php" method="post" class="d-inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="id" value="<?= (int)$r['review_id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button class="btn btn-sm btn-warning" onclick="return confirm('ปฏิเสธรีวิวนี้ใช่ไหม?');">ปฏิเสธ</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form action="<?= BASE_PATH ?>/update_review_status.php" method="post" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= (int)$r['review_id'] ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button class="btn btn-sm btn-outline-danger" onclick="return confirm('ลบรีวิวนี้ใช่ไหม?');">ลบ</button>
                                                </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            <?php if ($rows->num_rows === 0): ?>
                <tr><td colspan="<?= $hasStatus ? 7 : 6 ?>" class="text-center py-4 text-muted">ยังไม่มีรีวิวตามเงื่อนไขนี้</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>