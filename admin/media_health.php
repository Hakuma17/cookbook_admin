<?php
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/db.php'; // ไฟล์คอนเน็กชัน

$broken = [];

// 1) ingredients
$rs = $conn->query("SELECT ingredient_id id, display_name name, image_url path FROM ingredients WHERE deleted_at IS NULL");
while ($r = $rs->fetch_assoc()) {
  $u = media_url($r['path'],'ingredient');
  if (str_contains($u, '/assets/img/default_')) $broken[] = ['table'=>'ingredients'] + $r;
}

// 2) recipes
$rs = $conn->query("SELECT recipe_id id, name, image_path path FROM recipe"); // ปรับชื่อตารางให้ตรงจริง
while ($r = $rs->fetch_assoc()) {
  $u = media_url($r['path'],'recipe');
  if (str_contains($u, '/assets/img/default_')) $broken[] = ['table'=>'recipe'] + $r;
}
?>
<h1>Media health</h1>
<p>พบรูปหาย: <?= count($broken) ?> รายการ</p>
<table class="table table-sm">
  <thead><tr><th>Table</th><th>ID</th><th>Name</th><th>DB Path</th><th>Action</th></tr></thead>
  <tbody>
    <?php foreach($broken as $b): ?>
      <tr>
        <td><?= e($b['table']) ?></td>
        <td><?= e($b['id']) ?></td>
        <td><?= e($b['name']) ?></td>
        <td><code><?= e($b['path']) ?></code></td>
        <td>
          <?php if($b['table']==='ingredients'): ?>
            <a class="btn btn-sm btn-primary" href="edit_ingredient.php?id=<?= e($b['id']) ?>">แก้รูป</a>
          <?php else: ?>
            <a class="btn btn-sm btn-primary" href="edit_recipe.php?id=<?= e($b['id']) ?>">แก้รูป</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
