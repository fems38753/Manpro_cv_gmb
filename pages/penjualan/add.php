<?php
// pages/penjualan/add.php — Multi-Item + invoice dinamis + aman tanpa kolom tanggal
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$_active    = 'penjualan';
$page_title = 'Tambah Penjualan (Multi-Item)';

$err = [];

/* ===== util ===== */
function pick_sales_date_col(): ?string {
  if (column_exists('sales','sale_date')) return 'sale_date';
  if (column_exists('sales','date'))      return 'date';
  if (column_exists('sales','created_at'))return 'created_at';
  return null; // tidak ada kolom tanggal
}
function reqval($k,$d=null){ return trim($_POST[$k] ?? $d); }

/* ===== master data ===== */
$products   = fetchAll("SELECT id, name, ".(column_exists('products','sku')?'sku':'NULL AS sku')." FROM products ORDER BY name");
$warehouses = fetchAll("SELECT id, name FROM warehouses ORDER BY name");

/* stok map product->warehouse->qty */
$stockMap = [];
foreach (fetchAll("SELECT product_id, warehouse_id, quantity FROM stock_balances") as $s) {
  $pid=(int)$s['product_id']; $wid=(int)$s['warehouse_id'];
  $stockMap[$pid][$wid]=(float)$s['quantity'];
}

/* ===== handle POST ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) $err[]='Sesi kadaluarsa. Muat ulang.';

  $sale_date_in   = reqval('sale_date', reqval('tgl_penjualan', date('Y-m-d')));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$sale_date_in)) $sale_date_in = date('Y-m-d');

  $customer_name  = reqval('customer');
  $payment_status = in_array(reqval('payment_status','paid'),['paid','pending','unpaid'],true) ? reqval('payment_status','paid') : 'paid';
  $payment_method = reqval('payment_method') ?: null;
  $note           = reqval('catatan') ?: null;
  $uid            = $_SESSION['user']['user_id'] ?? null;

  $pidArr = $_POST['product_id']   ?? [];
  $widArr = $_POST['warehouse_id'] ?? [];
  $qtyArr = $_POST['qty']          ?? [];
  $prcArr = $_POST['unit_price']   ?? [];

  $items=[]; $subtotal=0.0;

  for ($i=0;$i<count($pidArr);$i++){
    $P=(int)$pidArr[$i]; $W=(int)$widArr[$i]; $Q=(float)($qtyArr[$i]??0); $H=(float)($prcArr[$i]??0);

    if($P<=0 && $W<=0 && $Q<=0 && $H<=0) continue;

    if($P<=0) $err[]="Baris #".($i+1).": produk belum dipilih.";
    if($W<=0) $err[]="Baris #".($i+1).": gudang belum dipilih.";
    if($Q<=0) $err[]="Baris #".($i+1).": qty harus > 0.";
    if($H<0)  $err[]="Baris #".($i+1).": harga tidak valid.";

    $avail = $stockMap[$P][$W] ?? 0;
    if ($Q > $avail) $err[]="Baris #".($i+1).": stok tidak cukup (tersedia ".(float)$avail.").";

    $rowSub = round($Q*$H,2);
    if($P>0 && $W>0 && $Q>0 && $H>=0){
      $items[]=['product_id'=>$P,'warehouse_id'=>$W,'qty'=>$Q,'unit_price'=>$H,'subtotal'=>$rowSub];
      $subtotal += $rowSub;
    }
  }

  if(!$items)         $err[]='Item penjualan kosong.';
  if(!$customer_name) $err[]='Customer wajib diisi.';

  if(!$err){
    begin();
    try{
      $tax=0.00; $total=$subtotal+$tax;

      /* ===== nomor invoice harian: YYMMDD-#### ===== */
      $sale_date_col = pick_sales_date_col(); // bisa null
      $datePart = date('ymd', strtotime($sale_date_in));

      if ($sale_date_col === 'created_at') {
        $seq = (int)(fetch("SELECT COUNT(*) c FROM sales WHERE DATE(created_at)=?", [$sale_date_in])['c'] ?? 0) + 1;
      } elseif ($sale_date_col) {
        $seq = (int)(fetch("SELECT COUNT(*) c FROM sales WHERE `$sale_date_col`=?", [$sale_date_in])['c'] ?? 0) + 1;
      } else {
        // tidak ada kolom tanggal → hitung dari invoice_no pola YYMMDD-####
        $seq = (int)(fetch("SELECT COUNT(*) c FROM sales WHERE invoice_no LIKE ?", [$datePart.'-%'])['c'] ?? 0) + 1;
      }
      $invoice = $datePart.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);

      /* ===== INSERT header sales (dinamis) ===== */
      $cols=['invoice_no','subtotal','tax','total']; $vals=[$invoice,$subtotal,$tax,$total];

      if ($sale_date_col === 'created_at') { $cols[]='created_at'; $vals[]=$sale_date_in.' 00:00:00'; }
      elseif ($sale_date_col)             { $cols[]=$sale_date_col; $vals[]=$sale_date_in; }
      // jika null → tidak ada kolom tanggal yang diinsert

      if (column_exists('sales','customer_name')) { $cols[]='customer_name'; $vals[]=$customer_name ?: null; }
      elseif (column_exists('sales','customer'))  { $cols[]='customer';      $vals[]=$customer_name ?: null; }

      if (column_exists('sales','status'))         { $cols[]='status';         $vals[]='completed'; }
      if (column_exists('sales','payment_status')) { $cols[]='payment_status'; $vals[]=$payment_status; }
      if (column_exists('sales','payment_method')) { $cols[]='payment_method'; $vals[]=$payment_method; }
      if (column_exists('sales','note'))           { $cols[]='note';           $vals[]=$note; }
      if (column_exists('sales','created_by'))     { $cols[]='created_by';     $vals[]=$uid; }

      $fields=implode(',',array_map(fn($c)=>"`$c`",$cols));
      $marks =implode(',',array_fill(0,count($cols),'?'));
      query("INSERT INTO sales ($fields) VALUES ($marks)", $vals);
      $sale_id=(int)lastId();

      /* ===== detail ===== */
     // detail (dinamis: created_at opsional)
$detailCols = ['sale_id','product_id','warehouse_id','qty','unit_price','subtotal'];
$placeholders = '?,?,?,?,?,?';
if (column_exists('sale_items','created_at')) {
  $detailCols[] = 'created_at';
  $placeholders .= ',NOW()';
}
$sqlDetail = "INSERT INTO sale_items (`".implode('`,`',$detailCols)."`) VALUES ($placeholders)";
$stmt = $pdo->prepare($sqlDetail);
foreach ($items as $it) {
  $stmt->execute([$sale_id, $it['product_id'], $it['warehouse_id'], $it['qty'], $it['unit_price'], $it['subtotal']]);
}



      /* ===== transaksi_keuangan (opsional) ===== */
      if (table_exists('transaksi_keuangan')) {
        $tCols=['tipe','tgl_transaksi','nominal','keterangan'];
        $tVals=['pendapatan',$sale_date_in,$total,"Penjualan #$sale_id - $customer_name"];
        if (column_exists('transaksi_keuangan','method'))     { $tCols[]='method';     $tVals[]=$payment_method; }
        if (column_exists('transaksi_keuangan','created_by')) { $tCols[]='created_by'; $tVals[]=$uid; }
        $f=implode(',',array_map(fn($c)=>"`$c`",$tCols)); $m=implode(',',array_fill(0,count($tCols),'?'));
        query("INSERT INTO transaksi_keuangan ($f) VALUES ($m)", $tVals);
      }

      commit();
      redirect('pages/penjualan/index.php?msg=Penjualan+berhasil+disimpan');
    } catch(Exception $ex){
      rollback();
      $err[]='Gagal menyimpan: '.e($ex->getMessage());
    }
  }
}

/* ===== view ===== */
include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:980px">
  <h2>Tambah Penjualan (Multi-Item)</h2>

  <?php if($err): ?>
    <div class="alert error" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;padding:10px;border-radius:10px;margin-bottom:10px">
      <?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" id="saleForm" oninput="recalcTotals()">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
      <div>
        <label>Tanggal</label>
        <input type="date" name="sale_date" required value="<?= e($_POST['sale_date'] ?? date('Y-m-d')) ?>">
      </div>
      <div>
        <label>Toko / PT Pembeli</label>
        <input type="text" name="customer" required value="<?= e($_POST['customer'] ?? '') ?>" placeholder="Toko A / PT ...">
      </div>
      <div>
        <label>Status Pembayaran</label>
        <?php $ps = $_POST['payment_status'] ?? 'paid'; ?>
        <select name="payment_status">
          <option value="paid"   <?= $ps==='paid'?'selected':'' ?>>Paid</option>
          <option value="pending"<?= $ps==='pending'?'selected':'' ?>>Pending</option>
          <option value="unpaid" <?= $ps==='unpaid'?'selected':'' ?>>Unpaid</option>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Metode Pembayaran</label>
        <?php $pm = $_POST['payment_method'] ?? ''; ?>
        <select name="payment_method">
          <option value="" <?= $pm===''?'selected':''?>>(kosong)</option>
          <option value="cash"     <?= $pm==='cash'?'selected':'' ?>>Tunai</option>
          <option value="transfer" <?= $pm==='transfer'?'selected':'' ?>>Transfer</option>
          <option value="credit"   <?= $pm==='credit'?'selected':'' ?>>Kredit</option>
        </select>
      </div>
      <div>
        <label>Catatan</label>
        <input type="text" name="catatan" value="<?= e($_POST['catatan'] ?? '') ?>">
      </div>
    </div>

    <h3 style="margin:16px 0 8px">Item</h3>
    <div class="table-responsive">
      <table class="table" id="itemsTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Produk</th>
            <th>Gudang</th>
            <th>Qty</th>
            <th>Harga</th>
            <th>Subtotal</th>
            <th class="no-print"></th>
          </tr>
        </thead>
        <tbody id="itemBody"></tbody>
        <tfoot>
          <tr>
            <td colspan="5" style="text-align:right;font-weight:600">Subtotal</td>
            <td id="subtotalCell"><?= rupiah(0) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div style="display:flex;gap:10px;align-items:center;margin-top:8px">
      <button class="btn" type="button" onclick="addRow()"><i class="fas fa-plus"></i> Tambah Baris</button>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit"><i class="fas fa-save"></i> Simpan & Cetak Invoice</button>
      <a class="btn outline" href="<?= asset_url('pages/penjualan/index.php') ?>">Batal</a>
    </div>
  </form>
</div>

<script>
const products   = <?= json_encode($products,   JSON_UNESCAPED_UNICODE) ?>;
const warehouses = <?= json_encode($warehouses, JSON_UNESCAPED_UNICODE) ?>;
const stockMap   = <?= json_encode($stockMap,   JSON_UNESCAPED_UNICODE) ?>;

function opt(list, valKey, textFn, sel=0){
  return list.map(o=>{
    const v=o[valKey]; const t=textFn(o);
    return `<option value="${v}" ${+sel===+v?'selected':''}>${t}</option>`;
  }).join('');
}
function newRow(idx){
  return `
  <tr>
    <td>${idx+1}</td>
    <td>
      <select name="product_id[]" class="prod" onchange="refreshHint(this)">
        <option value="">-- pilih produk --</option>
        ${opt(products,'id',o=>o.name+(o.sku? ' ('+o.sku+')':''))}
      </select>
    </td>
    <td>
      <select name="warehouse_id[]" class="wh" onchange="refreshHint(this)">
        <option value="">-- pilih gudang --</option>
        ${opt(warehouses,'id',o=>o.name)}
      </select>
      <div class="muted hint" style="font-size:12px;margin-top:4px"></div>
    </td>
    <td><input type="number" name="qty[]" class="qty" min="1" step="1" value="1" oninput="recalcTotals()"></td>
    <td><input type="number" name="unit_price[]" class="price" step="0.01" min="0" value="0" oninput="recalcTotals()"></td>
    <td class="row-sub">Rp 0</td>
    <td class="no-print"><button type="button" class="icon-btn danger" onclick="delRow(this)"><i class="fas fa-trash"></i></button></td>
  </tr>`;
}
function addRow(){ const tb=document.getElementById('itemBody'); tb.insertAdjacentHTML('beforeend', newRow(tb.children.length)); }
function delRow(btn){ const tr=btn.closest('tr'); tr.parentNode.removeChild(tr); recalcTotals(); renumber(); }
function renumber(){ [...document.querySelectorAll('#itemBody tr td:first-child')].forEach((td,i)=>td.textContent=i+1); }

function refreshHint(el){
  const tr=el.closest('tr');
  const pid=parseInt(tr.querySelector('.prod').value||'0',10);
  const wid=parseInt(tr.querySelector('.wh').value||'0',10);
  const hint=tr.querySelector('.hint');
  let avail=0;
  if(pid && stockMap[pid] && stockMap[pid][wid]!==undefined) avail=parseFloat(stockMap[pid][wid]||0);
  hint.textContent=(pid&&wid)?('Stok: '+new Intl.NumberFormat('id-ID').format(avail)):'Pilih produk dan gudang.';
  // jangan loop tak hingga
}
function recalcTotals(){
  let sub=0;
  document.querySelectorAll('#itemBody tr').forEach(tr=>{
    const q=parseFloat(tr.querySelector('.qty').value||'0');
    const h=parseFloat(tr.querySelector('.price').value||'0');
    const s=Math.round(q*h*100)/100;
    tr.querySelector('.row-sub').textContent='Rp '+new Intl.NumberFormat('id-ID').format(s||0);
    sub+=s;
  });
  document.getElementById('subtotalCell').textContent='Rp '+new Intl.NumberFormat('id-ID').format(sub||0);
}

addRow(); // baris awal
</script>

<?php include __DIR__ . '/../_partials/layout_end.php';
