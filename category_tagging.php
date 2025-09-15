<?php
/**
 * category_tagging.php
 * - ลากวางเพื่อจัดลำดับหมวด (order_index) และย้ายเป็นเมนูย่อย (parent_id)
 * - ต้องมีคอลัมน์: category (category_id, category_name, parent_id NULL, order_index INT)
 */
require_once __DIR__ . '/includes/check_auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/csrf.php';

$cats = $conn->query("SELECT category_id,category_name,parent_id,order_index FROM category ORDER BY COALESCE(parent_id,0), order_index, category_name");
$tree=[]; while($c=$cats->fetch_assoc()) $tree[$c['parent_id']][]=$c;
function render_list($tree,$pid=null){
  if(empty($tree[$pid])) return;
  echo '<ul class="list-group mb-2" data-parent="'.(int)$pid.'">';
  foreach($tree[$pid] as $n){
    echo '<li class="list-group-item" draggable="true" data-id="'.$n['category_id'].'">
            <span class="handle me-2">⠿</span>'.e($n['category_name']).'
            <a class="btn btn-sm btn-outline-secondary ms-2" href="#" onclick="makeChild('.$n['category_id'].');return false;">ทำเป็นเมนูย่อย</a>
          ';
    render_list($tree,$n['category_id']);
    echo '</li>';
  }
  echo '</ul>';
}
?>
<style>
.list-group-item{border-radius:10px;margin-bottom:.35rem;}
.handle{cursor:grab;color:#b39b8f}
.drag-over{border:2px dashed #b39b8f}
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h2 class="mb-0">จัดหมวดหมู่ (ลาก-วาง)</h2>
  <button class="btn btn-success" id="saveBtn">บันทึกการจัดเรียง</button>
</div>
<?= csrf_field() ?>
<div id="catTree"><?php render_list($tree); ?></div>

<script>
let dragged=null;
document.querySelectorAll('[draggable="true"]').forEach(el=>{
  el.addEventListener('dragstart',e=>{dragged=el; el.style.opacity=.5;});
  el.addEventListener('dragend',e=>{dragged=null; el.style.opacity=1;});
});
document.querySelectorAll('.list-group, .list-group-item').forEach(el=>{
  el.addEventListener('dragover',e=>{e.preventDefault(); el.classList.add('drag-over');});
  el.addEventListener('dragleave',()=>el.classList.remove('drag-over'));
  el.addEventListener('drop',e=>{
    e.preventDefault(); el.classList.remove('drag-over');
    if(!dragged || dragged===el) return;
    if(el.classList.contains('list-group-item')) el.after(dragged);
    else el.appendChild(dragged);
  });
});

function buildPayload(){
  const payload=[];
  document.querySelectorAll('#catTree > ul > li, #catTree ul li').forEach(li=>{
    const id=+li.dataset.id;
    const parent=li.parentElement?.dataset.parent || null;
    const order=[...li.parentElement.children].indexOf(li);
    payload.push({id, parent: parent?+parent:null, order});
  });
  return payload;
}
document.getElementById('saveBtn').addEventListener('click',async ()=>{
  const res=await fetch('<?= BASE_PATH ?>/save_category_order.php',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ _token: document.querySelector('input[name="_token"]').value, items: buildPayload() })
  });
  if(res.ok) alert('บันทึกสำเร็จ');
});

function makeChild(id){
  // สร้าง UL ลูก ให้ li ถัดไปเข้าไปอยู่ภายใน
  const li=document.querySelector('li[data-id="'+id+'"]'); if(!li) return;
  if(!li.querySelector('ul')){
    const ul=document.createElement('ul'); ul.className='list-group my-2'; ul.dataset.parent=id;
    li.appendChild(ul);
  }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
