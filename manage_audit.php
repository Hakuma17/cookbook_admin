<?php
// manage_audit.php — หน้าดูบันทึกการใช้งาน
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/rbac.php';

if (!function_exists('can') || !can('view_audit')) {
  echo '<div class="alert alert-danger m-3">คุณไม่มีสิทธิ์เข้าหน้านี้</div>';
  require_once __DIR__ . '/includes/footer.php'; exit;
}

// ฟิลเตอร์อย่างง่าย
$act  = trim($_GET['action'] ?? '');
$obj  = trim($_GET['object_type'] ?? '');
$uid  = (int)($_GET['user_id'] ?? 0);
$range= trim($_GET['range'] ?? '1d'); // 1d/7d/30d/all
$limit= max(10, min(500, (int)($_GET['limit'] ?? 100)));

$since = null;
if ($range==='1d')  $since = date('Y-m-d H:i:s', strtotime('-1 day'));
elseif ($range==='7d') $since = date('Y-m-d H:i:s', strtotime('-7 day'));
elseif ($range==='30d')$since = date('Y-m-d H:i:s', strtotime('-30 day'));

$filters = [];
if ($act!=='') $filters['action']=$act;
if ($obj!=='') $filters['object_type']=$obj;
if ($uid>0)    $filters['user_id']=$uid;
if ($since)    $filters['since']=$since;

$rs = audit_recent($limit, $filters);

// Export CSV
if (isset($_GET['export']) && $_GET['export']=='1' && $rs) {
  audit_log('export','audit',null,['filters'=>$filters,'limit'=>$limit]);
  audit_export_csv($rs);
}
?>
<style>.cb-card{border:1px solid var(--border);border-radius:16px;background:#fff;}</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h2 class="mb-0">บันทึกการใช้งาน (Audit Log)</h2>
  <a class="btn btn-outline-secondary" href="<?= BASE_PATH ?>/index.php">← กลับ Dashboard</a>
</div>

<form class="row g-2 mb-3">
  <div class="col-sm-3"><input class="form-control" name="action" placeholder="action เช่น view/update" value="<?= htmlspecialchars($act) ?>"></div>
  <div class="col-sm-3"><input class="form-control" name="object_type" placeholder="object เช่น recipe" value="<?= htmlspecialchars($obj) ?>"></div>
  <div class="col-sm-2"><input class="form-control" name="user_id" type="number" placeholder="user_id" value="<?= $uid?:'' ?>"></div>
  <div class="col-sm-2">
    <select class="form-select" name="range">
      <?php foreach(['1d'=>'24 ชั่วโมง','7d'=>'7 วัน','30d'=>'30 วัน','all'=>'ทั้งหมด'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $range===$k?'selected':'' ?>>ช่วง: <?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-1"><input class="form-control" name="limit" type="number" value="<?= $limit ?>"></div>
  <div class="col-sm-1 d-grid"><button class="btn btn-primary">กรอง</button></div>
  <div class="col-sm-2 d-grid mt-2 mt-sm-0">
    <a class="btn btn-outline-secondary" href="<?= BASE_PATH ?>/manage_audit.php?<?= http_build_query(array_merge($_GET,['export'=>1])) ?>">ส่งออก CSV</a>
  </div>
</form>

<div class="table-responsive cb-card">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr>
        <th>#</th><th>เวลา</th><th>ผู้ใช้</th><th>สิทธิ์</th>
        <th>Action</th><th>Object</th><th>Obj ID</th><th>Meta</th><th>IP</th><th>UA</th>
      </tr>
    </thead>
    <tbody>
      <?php if($rs && $rs->num_rows): while($r=$rs->fetch_assoc()): ?>
        <tr>
          <td><?= (int)$r['audit_id'] ?></td>
          <td><?= htmlspecialchars($r['created_at']) ?></td>
          <td><?= htmlspecialchars(($r['username']?:'').' (#'.($r['user_id']?:'-').')') ?></td>
          <td><?= htmlspecialchars($r['role'] ?? '-') ?></td>
          <td><span class="badge bg-secondary"><?= htmlspecialchars($r['action']) ?></span></td>
          <td><?= htmlspecialchars($r['object_type'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['object_id'] ?? '-') ?></td>
          <td style="max-width:360px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
            <code><?= htmlspecialchars($r['meta'] ?? '') ?></code>
          </td>
          <td><?= htmlspecialchars($r['ip'] ?? '-') ?></td>
          <td title="<?= htmlspecialchars($r['user_agent'] ?? '-') ?>" style="max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?= htmlspecialchars($r['user_agent'] ?? '-') ?>
          </td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="10" class="text-center text-muted py-4">ยังไม่มีข้อมูล</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
