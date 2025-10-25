<?php
// pages/penjualan/add.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$_active='penjualan';
$page_title='Tambah Penjualan';

$err = [];

/* ensure table exists (safe) */
query("CREATE TABLE IF NOT EXISTS penjualan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tgl_penjualan DATE,
  customer VARCHAR(200),
  product_id INT,
  warehouse_id INT,
  qty DECIMAL(14,3) DEFAULT 0,
  harga_satuan DECIMAL(14,2) DEFAULT 0,
  total_harga DECIMAL(14,2) DEFAULT 0,
  payment_status VARCHAR(20) DEFAULT 'unpaid',
  payment_method VARCHAR(50) DEFAULT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// fetch products + stock balances per warehouse
$products = fetchAll("SELECT id, name, sku FROM products ORDER BY name");
$warehouses = fetchAll("SELECT id,name FROM warehouses ORDER BY name");

/* prepare stock map: product_id => [warehouse_id => qty] */
$stockMap = [];
$rowsSb = fetchAll("SELECT product_id, warehouse_id, quantity FROM stock_balances");
foreach ($rowsSb as $s) {
  $pid = (int)$s['product_id']; $wid = (int)$s['warehouse_id'];
  if (!isset($stockMap[$pid])) $stockMap[$pid] = [];
  $stockMap[$pid][$wid] = (float)$s['quantity'];
}

/* POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) $err[] = 'Sesi kadaluarsa.';
  $tgl = trim($_POST['tgl_penjualan'] ?? date('Y-m-d'));
  $customer = trim($_POST['customer'] ?? '');
  $product_id = (int)($_POST['product_id'] ?? 0);
  $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
  $qty = (float)($_POST['qty'] ?? 0);
  $harga = (float)($_POST['harga_satuan'] ?? 0);
  $payment_status = in_array($_POST['payment_status'] ?? 'unpaid',['paid','pending','unpaid']) ? $_POST['payment_status'] : 'unpaid';
  $payment_method = trim($_POST['payment_method'] ?? '') ?: null;
  $created_by = $_SESSION['user']['user_id'] ?? null;

  if (!$product_id) $err[] = 'Produk wajib dipilih.';
  if ($qty <= 0) $err[] = 'Qty harus > 0.';
  if ($warehouse_id <= 0) $err[] = 'Gudang wajib dipilih.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $err[] = 'Tanggal tidak valid.';

  // check stock availability
  $available = 0.0;
  if (isset($stockMap[$product_id]) && isset($stockMap[$product_id][$warehouse_id])) $available = (float)$stockMap[$product_id][$warehouse_id];
  if ($qty > $available) {
    $err[] = "Stok tidak mencukupi. Stok saat ini: $available";
  }

  if (!$err) {
    begin();
    try {
      $total = $qty * $harga;

      // insert penjualan
      query("INSERT INTO penjualan (tgl_penjualan, customer, product_id, warehouse_id, qty, harga_satuan, total_harga, payment_status, payment_method, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [$tgl, $customer ?: null, $product_id, $warehouse_id, $qty, $harga, $total, $payment_status, $payment_method, $created_by]);
      $sale_id = (int) lastId();

      // deduct stock
      $sb = fetch("SELECT id, quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? LIMIT 1 FOR UPDATE", [$product_id, $warehouse_id]);
      if (!$sb) {
        // no stock row -> cannot sell
        throw new Exception('Stok untuk produk ini di gudang yang dipilih tidak tersedia. Lakukan pembelian terlebih dahulu.');
      }
      if ((float)$sb['quantity'] < $qty) throw new Exception('Stok tidak mencukupi saat verifikasi server.');

      query("UPDATE stock_balances SET quantity = quantity - ?, last_updated = NOW() WHERE id = ?", [$qty, $sb['id']]);

      // stock_movements
      if (table_exists('stock_movements')) {
        query("INSERT INTO stock_movements (product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, performed_by, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
               [$product_id, $warehouse_id, -$qty, 'sale_out', 'sale', $sale_id, "Penjualan #{$sale_id} ke {$customer}", $created_by]);
      }

      // transaksi_keuangan (pendapatan)
      if (function_exists('trx_create')) {
        trx_create([
          'tipe' => 'pendapatan',
          'tgl' => $tgl,
          'nominal' => $total,
          'keterangan' => "Penjualan #{$sale_id} ke {$customer}",
          'method' => $payment_method,
          'created_by' => $created_by
        ]);
      } elseif (table_exists('transaksi_keuangan')) {
        $cols = ['tipe','tgl_transaksi','nominal','keterangan'];
        $vals = ['pendapatan', $tgl, $total, "Penjualan #{$sale_id} ke {$customer}"];
        if (column_exists('transaksi_keuangan','method')) { $cols[]='method'; $vals[]=$payment_method; }
        if (column_exists('transaksi_keuangan','created_by')) { $cols[]='created_by'; $vals[]=$created_by; }
        $sqlt = "INSERT INTO transaksi_keuangan (".implode(',', $cols).") VALUES (".implode(',', array_fill(0,count($vals),'?')).")";
        query($sqlt, $vals);
      }

      commit();
      redirect('pages/penjualan/index.php?msg=Penjualan+berhasil+disimpan');
      exit;
    } catch (Exception $e) {
      rollback();
      $err[] = 'Gagal menyimpan penjualan: '.e($e->getMessage());
    }
  }
}

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:860px">
  <h2>Tambah Penjualan</h2>

  <?php if ($err): ?>
    <div class="alert error" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;padding:10px;border-radius:10px;margin-bottom:10px">
      <?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" id="saleForm" oninput="recalcTotal()">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label>Tanggal</label>
        <input type="date" name="tgl_penjualan" required value="<?= e($_POST['tgl_penjualan'] ?? date('Y-m-d')) ?>">
      </div>
      <div>
        <label>Toko / PT Pembeli</label>
        <input type="text" name="customer" required value="<?= e($_POST['customer'] ?? '') ?>" placeholder="Toko A / PT ...">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Produk</label>
        <select name="product_id" id="productSelect" required>
          <option value="">-- pilih produk --</option>
          <?php foreach($products as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ((int)($_POST['product_id']??0)==(int)$p['id'])?'selected':'' ?>><?= e($p['name'].' '.($p['sku']? '('.$p['sku'].')':'')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Gudang</label>
        <select name="warehouse_id" id="warehouseSelect" required>
          <option value="">-- pilih gudang --</option>
          <?php foreach($warehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= ((int)($_POST['warehouse_id']??0)==(int)$w['id'])?'selected':'' ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Qty (gulungan)</label>
        <input type="number" name="qty" id="qtyInput" min="1" step="1" required value="<?= e($_POST['qty'] ?? 1) ?>">
        <div id="stockHint" class="muted" style="font-size:12px;margin-top:6px"></div>
      </div>

      <div>
        <label>Harga Satuan</label>
        <input type="number" name="harga_satuan" id="hargaInput" step="0.01" min="0" required value="<?= e($_POST['harga_satuan'] ?? 0) ?>">
      </div>
    </div>

    <div style="margin-top:12px;display:flex;gap:12px;align-items:center">
      <div><label>Total</label><div id="totalView" style="font-weight:700"><?= rupiah(0) ?></div></div>

      <div>
        <label>Status Pembayaran</label>
        <select name="payment_status">
          <option value="paid" <?= (($_POST['payment_status'] ?? '')==='paid')?'selected':''?>>Paid</option>
          <option value="pending" <?= (($_POST['payment_status'] ?? '')==='pending')?'selected':''?>>Pending</option>
          <option value="unpaid" <?= (($_POST['payment_status'] ?? 'unpaid')==='unpaid')?'selected':''?>>Unpaid</option>
        </select>
      </div>

      <div>
        <label>Metode Pembayaran</label>
        <select name="payment_method">
          <option value="">(kosong)</option>
          <option value="cash">Tunai</option>
          <option value="transfer">Transfer</option>
          <option value="credit">Kredit</option>
        </select>
      </div>
    </div>

    <div style="margin-top:12px">
      <label>Catatan (opsional)</label>
      <textarea name="catatan" rows="2"><?= e($_POST['catatan'] ?? '') ?></textarea>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit"><i class="fas fa-save"></i> Simpan Penjualan</button>
      <a class="btn outline" href="<?= asset_url('pages/penjualan/index.php') ?>">Batal</a>
    </div>
  </form>
</div>

<script>
  // stockMap: product_id => {warehouse_id: qty}
  const stockMap = <?= json_encode($stockMap, JSON_HEX_TAG) ?>;
  function recalcTotal(){
    const q = parseFloat(document.getElementById('qtyInput').value || '0');
    const h = parseFloat(document.getElementById('hargaInput').value || '0');
    const total = Math.round(q*h*100)/100;
    document.getElementById('totalView').innerText = total ? '<?= e('Rp ') ?>' + (new Intl.NumberFormat('id-ID').format(total)) : 'Rp 0';
    // stock check
    const pid = parseInt(document.getElementById('productSelect').value || '0',10);
    const wid = parseInt(document.getElementById('warehouseSelect').value || '0',10);
    let avail = 0;
    if (pid && stockMap[pid] && stockMap[pid][wid] !== undefined) avail = parseFloat(stockMap[pid][wid]);
    const hint = document.getElementById('stockHint');
    if (!pid || !wid) {
      hint.innerText = 'Pilih produk dan gudang untuk melihat stok.';
      hint.style.color='';
    } else {
      hint.innerText = 'Stok tersedia: ' + new Intl.NumberFormat('id-ID').format(avail);
      if (q > avail) {
        hint.style.color = 'red';
        document.getElementById('qtyInput').style.borderColor = 'red';
      } else {
        hint.style.color = '';
        document.getElementById('qtyInput').style.borderColor = '';
      }
    }
  }
  document.getElementById('productSelect').addEventListener('change', recalcTotal);
  document.getElementById('warehouseSelect').addEventListener('change', recalcTotal);
  document.getElementById('qtyInput').addEventListener('input', recalcTotal);
  document.getElementById('hargaInput').addEventListener('input', recalcTotal);
  // init
  recalcTotal();
</script>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
