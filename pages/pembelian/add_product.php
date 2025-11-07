<?php
// pages/pembelian/add_product.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional','staff_keuangan']);

$_active    = 'pembelian';
$page_title = 'Tambah Produk Baru (Sekaligus Pembelian)';

/* ===== Helpers ===== */
function find_or_create_supplier(string $name): int {
  $name = trim($name);
  if ($name === '') throw new Exception('Nama supplier kosong');
  $r = fetch("SELECT id FROM suppliers WHERE LOWER(name)=LOWER(?) LIMIT 1", [$name]);
  if ($r) return (int)$r['id'];
  query("INSERT INTO suppliers (name, created_at) VALUES (?, NOW())", [$name]);
  return (int) lastId();
}
function ensure_default_warehouse(): int {
  if (!table_exists('warehouses')) return 0;
  $r = fetch("SELECT id FROM warehouses ORDER BY id LIMIT 1");
  if ($r) return (int)$r['id'];
  query("INSERT INTO warehouses (name,address,created_at) VALUES (?,?,NOW())", ['Gudang Utama','Gudang default']);
  return (int) lastId();
}
function upsert_product_new(string $name, ?string $sku, ?string $photo_relpath, string $unit='gulung', float $cost_price=0.0, float $selling=0.0): int {
  $name = trim($name);
  if ($name === '') throw new Exception('Nama produk kosong');
  $r = fetch("SELECT id FROM products WHERE LOWER(name)=LOWER(?) LIMIT 1", [$name]);
  if ($r) {
    $set=[]; $vals=[];
    if ($sku !== null && column_exists('products','sku')) { $set[]='sku=?'; $vals[]=$sku; }
    if (column_exists('products','unit'))                { $set[]='unit=?'; $vals[]=$unit; }
    if (column_exists('products','cost_price'))          { $set[]='cost_price=?'; $vals[]=$cost_price; }
    if (column_exists('products','selling_price'))       { $set[]='selling_price=?'; $vals[]=$selling; }
    if ($photo_relpath && column_exists('products','image')) { $set[]='image=?'; $vals[]=$photo_relpath; }
    if ($set) {
      if (column_exists('products','updated_at')) $set[]='updated_at=NOW()';
      $vals[]=(int)$r['id'];
      query("UPDATE products SET ".implode(',', $set)." WHERE id=?", $vals);
    }
    return (int)$r['id'];
  }
  $cols=['name']; $vals=[$name];
  if ($sku !== null && column_exists('products','sku')) { $cols[]='sku'; $vals[]=$sku; }
  if (column_exists('products','description')) { $cols[]='description'; $vals[]=''; }
  if (column_exists('products','unit'))        { $cols[]='unit';        $vals[]=$unit; }
  if (column_exists('products','cost_price'))  { $cols[]='cost_price';  $vals[]=$cost_price; }
  if (column_exists('products','selling_price')){ $cols[]='selling_price'; $vals[]=$selling; }
  if ($photo_relpath && column_exists('products','image')) { $cols[]='image'; $vals[]=$photo_relpath; }
  if (column_exists('products','created_at'))  { $cols[]='created_at';  $vals[]=date('Y-m-d H:i:s'); }
  if (column_exists('products','updated_at'))  { $cols[]='updated_at';  $vals[]=date('Y-m-d H:i:s'); }
  $fields = implode(',', array_map(fn($c)=>"`$c`",$cols));
  $marks  = implode(',', array_fill(0,count($cols),'?'));
  query("INSERT INTO products ($fields) VALUES ($marks)", $vals);
  return (int) lastId();
}

/* ===== One-time token (idempotency) ===== */
if (empty($_SESSION['once_token'])) {
  $_SESSION['once_token'] = bin2hex(random_bytes(16));
}
$once_token = $_SESSION['once_token'];

/* ===== Handle POST ===== */
$err=[];
if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!check_csrf($_POST['csrf']??'')) $err[]='Sesi kadaluarsa. Muat ulang.';

  $once_in = $_POST['once'] ?? '';
  if (!$once_in || !hash_equals($_SESSION['once_token'] ?? '', $once_in)) {
    $err[] = 'Form sudah diproses (duplikat).';
  } else {
    unset($_SESSION['once_token']); // hanguskan
  }

  $tgl            = trim($_POST['tgl_pembelian'] ?? date('Y-m-d'));
  $supplier_text  = trim($_POST['supplier_pt'] ?? '');
  $nama_produk    = trim($_POST['nama_produk'] ?? '');
  $sku            = trim($_POST['sku'] ?? '');
  $qty            = (int)($_POST['qty'] ?? 0);
  $harga_satuan   = (float)($_POST['harga_satuan'] ?? 0);
  $harga_jual     = (float)($_POST['harga_jual'] ?? 0);
  $warehouse_id   = (int)($_POST['warehouse_id'] ?? 0);
  $invoice_no     = trim($_POST['invoice_no'] ?? '');
  $payment_status = in_array($_POST['payment_status'] ?? 'unpaid',['paid','unpaid']) ? $_POST['payment_status'] : 'unpaid';
  $payment_method = trim($_POST['payment_method'] ?? '') ?: null;

  if ($supplier_text==='') $err[]='Supplier wajib diisi.';
  if ($nama_produk==='')  $err[]='Nama produk wajib diisi.';
  if ($qty<=0)            $err[]='Qty harus > 0.';
  if ($harga_satuan<0)    $err[]='Harga satuan tidak valid.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$tgl)) $err[]='Tanggal tidak valid.';
  if ($warehouse_id<=0) $warehouse_id = ensure_default_warehouse();

  // upload foto opsional
  $photo=null;
  if (isset($_FILES['product_photo']) && $_FILES['product_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    [$ok,$msg] = upload_image($_FILES['product_photo'], STOK_DIR);
    if (!$ok) $err[]='Upload foto gagal: '.$msg;
    else {
      $p = str_replace('\\','/',$msg);
      $app = str_replace('\\','/', APP_DIR . '/');
      if (strpos($p,$app)===0) $p = substr($p,strlen($app));
      if (preg_match('/^[A-Za-z]:\\//',$p) && ($pos=strpos($p,'uploads/'))!==false) $p = substr($p,$pos);
      $photo = ltrim($p,'/');
    }
  }

  if (!$err){
    begin();
    try{
      $supplier_id = find_or_create_supplier($supplier_text);
      $product_id  = upsert_product_new($nama_produk, ($sku===''?null:$sku), $photo, 'gulung', $harga_satuan, $harga_jual);

      $subtotal = $qty * $harga_satuan; $tax=0.00; $total=$subtotal + $tax;

      /* ----- Deteksi duplikat 5 detik terakhir ----- */
      $dup = fetch("
        SELECT p.id
        FROM purchases p
        JOIN purchase_items i ON i.purchase_id=p.id
        WHERE p.date = ?
          AND p.supplier_id = ?
          AND p.total = ?
          AND i.product_id = ?
          AND i.qty = ?
          AND i.unit_price = ?
          AND p.created_at >= (NOW() - INTERVAL 5 SECOND)
        ORDER BY p.id DESC LIMIT 1
      ", [$tgl,$supplier_id,$total,$product_id,$qty,$harga_satuan]);
      if ($dup) {
        rollback();
        redirect('pages/pembelian/index.php?msg=Transaksi+identik+sudah+tercatat+#'.$dup['id']);
      }

      /* ----- purchases ----- */
      $cols=['supplier_id','date']; $vals=[$supplier_id,$tgl];
      if (column_exists('purchases','warehouse_id'))    { $cols[]='warehouse_id'; $vals[]=$warehouse_id; }
      if (column_exists('purchases','invoice_no'))      { $cols[]='invoice_no';   $vals[]=$invoice_no ?: null; }
      if (column_exists('purchases','subtotal'))        { $cols[]='subtotal';     $vals[]=$subtotal; }
      if (column_exists('purchases','tax'))             { $cols[]='tax';          $vals[]=$tax; }
      if (column_exists('purchases','total'))           { $cols[]='total';        $vals[]=$total; }
      if (column_exists('purchases','status'))          { $cols[]='status';       $vals[]='received'; }
      if (column_exists('purchases','payment_status'))  { $cols[]='payment_status'; $vals[]=$payment_status; }
      if (column_exists('purchases','payment_method'))  { $cols[]='payment_method'; $vals[]=$payment_method; }
      if (column_exists('purchases','total_paid'))      { $cols[]='total_paid';   $vals[] = ($payment_status==='paid' ? $total : 0); }
      if (column_exists('purchases','created_by'))      { $cols[]='created_by';   $vals[] = ($_SESSION['user']['user_id'] ?? null); }
      if (column_exists('purchases','created_at'))      { $cols[]='created_at';   $vals[]=date('Y-m-d H:i:s'); }
      $fields=implode(',',array_map(fn($c)=>"`$c`",$cols)); $marks=implode(',',array_fill(0,count($cols),'?'));
      query("INSERT INTO purchases ($fields) VALUES ($marks)", $vals);
      $purchase_id=(int)lastId();

      /* ----- purchase_items ----- */
      $piCols=['purchase_id','product_id','qty','unit_price']; $piVals=[$purchase_id,$product_id,$qty,$harga_satuan];
      if (column_exists('purchase_items','subtotal')) { $piCols[]='subtotal'; $piVals[]=$subtotal; }
      $fields=implode(',',array_map(fn($c)=>"`$c`",$piCols)); $marks=implode(',',array_fill(0,count($piCols),'?'));
      query("INSERT INTO purchase_items ($fields) VALUES ($marks)", $piVals);

      

      /* ----- transaksi_keuangan (pengeluaran) ----- */
      if (table_exists('transaksi_keuangan')) {
        $tCols=['tipe','tgl_transaksi','nominal','keterangan'];
        $tVals=['pengeluaran',$tgl,$total,"Pembelian #$purchase_id - $supplier_text"];
        if (column_exists('transaksi_keuangan','method'))     { $tCols[]='method';     $tVals[]=$payment_method; }
        if (column_exists('transaksi_keuangan','created_by')) { $tCols[]='created_by'; $tVals[]=$_SESSION['user']['user_id'] ?? null; }
        $f=implode(',',array_map(fn($c)=>"`$c`",$tCols)); $m=implode(',',array_fill(0,count($tCols),'?'));
        query("INSERT INTO transaksi_keuangan ($f) VALUES ($m)", $tVals);
      }

      commit();
      redirect('pages/pembelian/index.php?msg=Produk+baru+dibuat+dan+stok+ditambahkan');
    } catch(Exception $ex){
      rollback();
      $err[]='Gagal menyimpan: '.e($ex->getMessage());
    }
  }
}

/* regenerate once token untuk render form berikutnya */
if (empty($_SESSION['once_token'])) {
  $_SESSION['once_token'] = bin2hex(random_bytes(16));
}
$once_token = $_SESSION['once_token'];

/* Ambil daftar gudang untuk select */
$whs = table_exists('warehouses') ? fetchAll("SELECT id,name FROM warehouses ORDER BY name") : [];

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:940px">
  <h2>Tambah Produk Baru (Sekaligus Pembelian)</h2>

  <?php if($err): ?>
    <div class="alert error" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;padding:10px;border-radius:10px;margin-bottom:10px">
      <?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" onsubmit="return lockSubmit(this)">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="once" value="<?= e($once_token) ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label>Tanggal</label>
        <input type="date" name="tgl_pembelian" required value="<?= e($_POST['tgl_pembelian'] ?? date('Y-m-d')) ?>">
      </div>
      <div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Supplier (PT)</label>
        <input type="text" name="supplier_pt" required value="<?= e($_POST['supplier_pt'] ?? '') ?>">
      </div>
      <div>
        <label>Status Pembayaran</label>
        <?php $ps=$_POST['payment_status']??'unpaid'; ?>
        <select name="payment_status">
          <option value="unpaid" <?= $ps==='unpaid'?'selected':'' ?>>Unpaid</option>
          <option value="paid"   <?= $ps==='paid'  ?'selected':'' ?>>Paid</option>
        </select>
      </div>
      <div>
        <label>Metode Pembayaran</label>
        <?php $pm=$_POST['payment_method']??''; ?>
        <select name="payment_method">
          <option value="" <?= $pm===''?'selected':''?>>(kosong)</option>
          <option value="cash" <?= $pm==='cash'?'selected':'' ?>>Tunai</option>
          <option value="transfer" <?= $pm==='transfer'?'selected':'' ?>>Transfer</option>
          <option value="kredit" <?= $pm==='kredit'?'selected':'' ?>>Kredit</option>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Nama Produk</label>
        <input type="text" name="nama_produk" required value="<?= e($_POST['nama_produk'] ?? '') ?>">
        <div class="muted" style="font-size:12px">Contoh: Kain Batik Floral</div>
      </div>
      <div>
        <label>SKU (opsional)</label>
        <input type="text" name="sku" value="<?= e($_POST['sku'] ?? '') ?>">
      </div>
      <div>
        <label>Gudang</label>
        <select name="warehouse_id">
          <?php
            $curWh = (int)($_POST['warehouse_id'] ?? 0);
            foreach($whs as $w){
              $sel = ($curWh === (int)$w['id']) ? 'selected' : '';
              echo '<option value="'.(int)$w['id'].'" '.$sel.'>'.e($w['name']).'</option>';
            }
          ?>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Qty (gulungan)</label>
        <input type="number" name="qty" min="1" required value="<?= e($_POST['qty'] ?? 1) ?>">
      </div>
      <div>
        <label>Harga Pokok (per gulung)</label>
        <input type="number" name="harga_satuan" step="0.01" min="0" required value="<?= e($_POST['harga_satuan'] ?? 0) ?>">
      </div>
      <div>
        <label>Harga Jual (opsional)</label>
        <input type="number" name="harga_jual" step="0.01" min="0" value="<?= e($_POST['harga_jual'] ?? 0) ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Foto Produk (opsional)</label>
        <input type="file" name="product_photo" accept="image/png,image/jpeg,image/webp">
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit" id="submitBtn"><i class="fas fa-save"></i> Simpan</button>
      <a class="btn outline" href="<?= asset_url('pages/pembelian/index.php') ?>"><i class="fas fa-arrow-left"></i> Batal</a>
    </div>
  </form>
</div>

<script>
function lockSubmit(form){
  const btn = document.getElementById('submitBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Memproses...'; }
  if (window.history && window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
  }
  return true;
}
</script>

<?php include __DIR__ . '/../_partials/layout_end.php';
