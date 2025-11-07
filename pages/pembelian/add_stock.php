<?php
// pages/pembelian/add_stock.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_operasional','staff_keuangan']);

$_active    = 'pembelian';
$page_title = 'Tambah Stok Produk';

/* ---------- Helpers kecil ---------- */
function find_or_create_supplier2(string $name): int {
  $name = trim($name);
  if ($name === '') throw new Exception('Nama supplier kosong');
  $r = fetch("SELECT id FROM suppliers WHERE LOWER(name)=LOWER(?) LIMIT 1", [$name]);
  if ($r) return (int)$r['id'];
  query("INSERT INTO suppliers (name, created_at) VALUES (?, NOW())", [$name]);
  return (int) lastId();
}
function ensure_default_warehouse2(): int {
  if (!table_exists('warehouses')) return 0;
  $r = fetch("SELECT id FROM warehouses ORDER BY id LIMIT 1");
  if ($r) return (int)$r['id'];
  query("INSERT INTO warehouses (name,address,created_at) VALUES (?,?,NOW())", ['Gudang Utama','Gudang default']);
  return (int) lastId();
}

/* ---------- Data untuk pilihan ---------- */
$products = table_exists('products')
  ? fetchAll("SELECT id, name FROM products ORDER BY name ASC")
  : [];

/* ---------- One-time token (idempotency) ---------- */
if (empty($_SESSION['once_token'])) {
  $_SESSION['once_token'] = bin2hex(random_bytes(16));
}
$once_token = $_SESSION['once_token'];

$err = [];
if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!check_csrf($_POST['csrf']??'')) $err[]='Sesi kadaluarsa. Muat ulang.';
  // Token sekali-pakai: harus sama lalu langsung dihanguskan
  $once_in = $_POST['once'] ?? '';
  if (!$once_in || !hash_equals($_SESSION['once_token'] ?? '', $once_in)) {
    $err[] = 'Form sudah diproses (duplikat).';
  } else {
    unset($_SESSION['once_token']); // hanguskan segera
  }

  $tgl            = trim($_POST['tgl_pembelian'] ?? date('Y-m-d'));
  $supplier_text  = trim($_POST['supplier_pt'] ?? '');
  $product_id     = (int)($_POST['product_id'] ?? 0);
  $qty            = (int)($_POST['qty'] ?? 0);
  $unit_price     = (float)($_POST['harga_satuan'] ?? 0);
  $warehouse_id   = (int)($_POST['warehouse_id'] ?? 0);
  $invoice_no     = trim($_POST['invoice_no'] ?? '');
  $payment_status = in_array($_POST['payment_status'] ?? 'unpaid',['paid','unpaid']) ? $_POST['payment_status'] : 'unpaid';
  $payment_method = trim($_POST['payment_method'] ?? '') ?: null;

  if ($supplier_text==='') $err[]='Supplier wajib diisi.';
  if ($product_id<=0)     $err[]='Pilih produk.';
  if ($qty<=0)            $err[]='Qty harus > 0.';
  if ($unit_price<0)      $err[]='Harga satuan tidak valid.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$tgl)) $err[]='Tanggal tidak valid.';
  if ($warehouse_id<=0) $warehouse_id = ensure_default_warehouse2();

  if (!$err){
    begin();
    try{
      $supplier_id = find_or_create_supplier2($supplier_text);
      $subtotal    = $qty * $unit_price;
      $tax         = 0.00;
      $total       = $subtotal + $tax;

      /* ---------- DETEKSI DUPLIKAT (5 detik terakhir) ---------- */
      // Kalau user kebetulan double-click, record identik dalam 5 detik akan terdeteksi
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
      ", [$tgl,$supplier_id,$total,$product_id,$qty,$unit_price]);
      if ($dup) {
        rollback();
        redirect('pages/pembelian/index.php?msg=Transaksi+identik+sudah+tercatat+#'.$dup['id']);
      }

      /* ---------- purchases (dinamis) ---------- */
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
      $fields = implode(',',array_map(fn($c)=>"`$c`",$cols));
      $marks  = implode(',',array_fill(0,count($cols),'?'));
      query("INSERT INTO purchases ($fields) VALUES ($marks)", $vals);
      $purchase_id=(int)lastId();

      /* ---------- purchase_items ---------- */
      $piCols=['purchase_id','product_id','qty','unit_price']; $piVals=[$purchase_id,$product_id,$qty,$unit_price];
      if (column_exists('purchase_items','subtotal')) { $piCols[]='subtotal'; $piVals[]=$subtotal; }
      $fields=implode(',',array_map(fn($c)=>"`$c`",$piCols)); $marks=implode(',',array_fill(0,count($piCols),'?'));
      query("INSERT INTO purchase_items ($fields) VALUES ($marks)", $piVals);

     

      /* ---------- transaksi_keuangan (pengeluaran) ---------- */
      if (table_exists('transaksi_keuangan')) {
        $tCols=['tipe','tgl_transaksi','nominal','keterangan'];
        $tVals=['pengeluaran',$tgl,$total,"Pembelian #$purchase_id - $supplier_text"];
        if (column_exists('transaksi_keuangan','method'))     { $tCols[]='method';     $tVals[]=$payment_method; }
        if (column_exists('transaksi_keuangan','created_by')) { $tCols[]='created_by'; $tVals[]=$_SESSION['user']['user_id'] ?? null; }
        $f=implode(',',array_map(fn($c)=>"`$c`",$tCols)); $m=implode(',',array_fill(0,count($tCols),'?'));
        query("INSERT INTO transaksi_keuangan ($f) VALUES ($m)", $tVals);
      }

      commit();
      redirect('pages/pembelian/index.php?msg=Stok+ditambahkan');
    } catch(Exception $ex){
      rollback();
      $err[]='Gagal menyimpan: '.e($ex->getMessage());
    }
  }
}

/* Regenerate token untuk render form berikutnya */
if (empty($_SESSION['once_token'])) {
  $_SESSION['once_token'] = bin2hex(random_bytes(16));
}
$once_token = $_SESSION['once_token'];

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:820px">
  <h2>Tambah Stok Produk</h2>

  <?php if($err): ?>
    <div class="alert error" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;padding:10px;border-radius:10px;margin-bottom:10px">
      <?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" onsubmit="return lockSubmit(this)">
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

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Supplier (PT)</label>
        <input type="text" name="supplier_pt" required value="<?= e($_POST['supplier_pt'] ?? '') ?>">
      </div>
      <div>
        <label>Gudang</label>
        <select name="warehouse_id">
          <?php $whs = table_exists('warehouses') ? fetchAll("SELECT id,name FROM warehouses ORDER BY name") : [];
          foreach($whs as $w){ $sel=((int)($_POST['warehouse_id']??0)===(int)$w['id'])?'selected':''; ?>
            <option value="<?= (int)$w['id'] ?>" <?= $sel ?>><?= e($w['name']) ?></option>
          <?php } ?>
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Produk</label>
        <select name="product_id" required>
          <option value="">-- Pilih Produk --</option>
          <?php $cur=(int)($_POST['product_id']??0);
          foreach($products as $p){
            $sel=($cur===(int)$p['id'])?'selected':'';
            echo '<option value="'.(int)$p['id'].'" '.$sel.'>'.e($p['name']).'</option>';
          } ?>
        </select>
      </div>
      <div>
        <label>Qty (gulungan)</label>
        <input type="number" name="qty" min="1" required value="<?= e($_POST['qty'] ?? 1) ?>">
      </div>
      <div>
        <label>Harga Satuan</label>
        <input type="number" name="harga_satuan" step="0.01" min="0" required value="<?= e($_POST['harga_satuan'] ?? 0) ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
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

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit" id="submitBtn"><i class="fas fa-save"></i> Tambah Stok</button>
      <a class="btn outline" href="<?= asset_url('pages/pembelian/index.php') ?>"><i class="fas fa-arrow-left"></i> Batal</a>
    </div>
  </form>
</div>

<script>
function lockSubmit(form){
  const btn = document.getElementById('submitBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Memproses...'; }
  // cegah re-submit via back/forward cache
  if (window.history && window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
  }
  return true;
}
</script>

<?php include __DIR__ . '/../_partials/layout_end.php';
