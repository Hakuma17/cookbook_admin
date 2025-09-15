<?php
// includes/audit.php
// ฟังก์ชันบันทึก/อ่าน Log แบบเบา ๆ

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

function audit_can_log(): bool {
  return isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli;
}

function audit_ip(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) {
      $ip = trim(explode(',', $_SERVER[$k])[0]);
      return substr($ip, 0, 45);
    }
  }
  return '';
}

/** บันทึกเหตุการณ์ */
function audit_log(string $action, ?string $objType=null, $objId=null, array $meta=[]): void {
  if (!audit_can_log()) return;
  $conn = $GLOBALS['conn'];

  $userId   = $_SESSION['user_id'] ?? null;
  $username = $_SESSION['profile_name'] ?? null;
  $role     = $_SESSION['role'] ?? null;

  $url      = ($_SERVER['REQUEST_SCHEME'] ?? 'http').'://'.($_SERVER['HTTP_HOST'] ?? '').($_SERVER['REQUEST_URI'] ?? '');
  $ref      = $_SERVER['HTTP_REFERER'] ?? null;
  $ua       = $_SERVER['HTTP_USER_AGENT'] ?? null;
  $method   = $_SERVER['REQUEST_METHOD'] ?? null;
  $ip       = audit_ip();

  $sql = "INSERT INTO audit_log
          (user_id,username,role,action,object_type,object_id,meta,method,url,referrer,ip,user_agent)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
  $stmt = $conn->prepare($sql);
  $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
  $stmt->bind_param(
    'isssssisssss',
    $userId, $username, $role, $action, $objType, $objId,
    $metaJson, $method, $url, $ref, $ip, $ua
  );
  $stmt->execute();
}

/** ดึง log ล่าสุดอย่างเร็ว */
function audit_recent(int $limit=50, array $filters=[]): mysqli_result|false {
  if (!audit_can_log()) return false;
  $conn = $GLOBALS['conn'];
  $w=[]; $t=''; $p=[];
  if (!empty($filters['action'])) { $w[]='action=?'; $t.='s'; $p[]=$filters['action']; }
  if (!empty($filters['object_type'])) { $w[]='object_type=?'; $t.='s'; $p[]=$filters['object_type']; }
  if (!empty($filters['user_id'])) { $w[]='user_id=?'; $t.='i'; $p[]=(int)$filters['user_id']; }
  if (!empty($filters['since'])) { $w[]='created_at>=?'; $t.='s'; $p[]=$filters['since']; }
  $where = $w ? 'WHERE '.implode(' AND ', $w) : '';
  $sql = "SELECT * FROM audit_log $where ORDER BY audit_id DESC LIMIT ?";
  $t.='i'; $p[]=$limit;
  $stmt=$conn->prepare($sql);
  $stmt->bind_param($t, ...$p);
  $stmt->execute();
  return $stmt->get_result();
}

/** ส่งออก CSV อย่างเร็ว */
function audit_export_csv(mysqli_result $rs): void {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="audit_export_'.date('Ymd_His').'.csv"');
  $out = fopen('php://output','w');
  fputcsv($out, ['audit_id','created_at','user_id','username','role','action','object_type','object_id','meta','method','url','ip','user_agent']);
  while($r=$rs->fetch_assoc()){
    fputcsv($out, [
      $r['audit_id'],$r['created_at'],$r['user_id'],$r['username'],$r['role'],
      $r['action'],$r['object_type'],$r['object_id'],$r['meta'],$r['method'],$r['url'],$r['ip'],$r['user_agent']
    ]);
  }
  exit;
}
