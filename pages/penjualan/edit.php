<?php
// pages/penjualan/edit.php
require_once __DIR__ . '/../../config/database.php';
require_login();
allow(['direktur','staff_keuangan']);

$_active='penjualan';
$page_title='Edit Penjualan';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('pages/penjualan/index.php?msg=Data+tidak+valid');

$sale = fetch("SELECT * FROM penjualan WHERE id = ? LIMIT 1", [$id]);
if (!$sale) redirect('pages/penjualan/index.php?msg=Data+tidak+ditemukan');

$products = fetchAll("SELECT id,name,sku FROM products ORDER BY name");
$warehouses = fetchAll("SELECT id,name FROM warehouses ORDER BY name");

// stock map
$stockMap = [];
foreach (fetchAll("SELECT product_id, warehouse_id, quantity FROM stock_balances") as $s) {
  $pid=(int)$s['product_id']; $wid=(int)$s['warehouse_id'];
  if (!isset($stockMap[$pid])) $stockMap[$pid]=[];
  $stockMap[$pid][$wid] = (float)$s['quantity'];
}

$err = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf($_POST['csrf'] ?? '')) $err[]='Sesi kadaluarsa.';
  $tgl = trim($_POST['tgl_penjualan'] ?? $sale['tgl_penjualan']);
  $customer = trim($_POST['customer'] ?? $sale['customer']);
  $product_id = (int)($_POST['product_id'] ?? $sale['product_id']);
  $warehouse_id = (int)($_POST['warehouse_id'] ?? $sale['warehouse_id']);
  $qty = (float)($_POST['qty'] ?? $sale['qty']);
  $harga = (float)($_POST['harga_satuan'] ?? $sale['harga_satuan']);
  $payment_status = in_array($_POST['payment_status'] ?? 'unpaid',['paid','pending','unpaid']) ? $_POST['payment_status'] : 'unpaid';
  $payment_method = trim($_POST['payment_method'] ?? '') ?: null;
  $created_by = $_SESSION['user']['user_id'] ?? null;

  if (!$product_id) $err[] = 'Produk wajib.';
  if ($qty <= 0) $err[] = 'Qty harus > 0.';
  if ($warehouse_id <= 0) $err[] = 'Gudang wajib.';

  // check stock: compute diff
  $old_qty = (float)$sale['qty'];
  $diff = $qty - $old_qty;

  // if same product and same warehouse -> check current stock availability
  if ($product_id == (int)$sale['product_id'] && $warehouse_id == (int)$sale['warehouse_id']) {
    $available = $stockMap[$product_id][$warehouse_id] ?? 0;
    // but note: current sale qty already reserved in stock (was deducted before). In DB, stock already reduced by old_qty.
    // We must ensure that available + old_qty (current physical before edit) covers new qty: simpler check by reading stock_balances FOR UPDATE.
    if ($diff > 0) {
      // need additional qty -> verify available in DB
      $sb = fetch("SELECT id,quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? LIMIT 1", [$product_id,$warehouse_id]);
      if (!$sb) $err[] = 'Stok tidak ditemukan di gudang.';
      else if ((float)$sb['quantity'] < $diff) $err[] = 'Stok tidak mencukupi untuk menambah qty.';
    }
  } else {
    // product or warehouse changed: we will return old_qty to old stock and take qty from new stock.
    // check new stock availability
    $sb_new = fetch("SELECT id,quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? LIMIT 1", [$product_id,$warehouse_id]);
    if (!$sb_new) $err[] = 'Stok untuk produk/gudang tujuan tidak ada. Untuk menambah stok gunakan Pembelian.';
    else if ((float)$sb_new['quantity'] < $qty) $err[] = 'Stok untuk produk/gudang tujuan tidak mencukupi.';
  }

  if (!$err) {
    begin();
    try {
      // handle stock adjustments atomically
      if ($product_id == (int)$sale['product_id'] && $warehouse_id == (int)$sale['warehouse_id']) {
        // same row: adjust by diff
        if ($diff !== 0) {
          // lock
          $locked = query("SELECT id,quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? FOR UPDATE", [$product_id,$warehouse_id])->fetch(PDO::FETCH_ASSOC);
          if (!$locked) throw new Exception('Stok tidak ditemukan saat update.');
          if ((float)$locked['quantity'] < $diff) throw new Exception('Stok tidak mencukupi saat verifikasi.');
          query("UPDATE stock_balances SET quantity = quantity - ? , last_updated = NOW() WHERE id = ?", [$diff, $locked['id']]);
          // log
          if (table_exists('stock_movements')) {
            $movement_type = $diff > 0 ? 'sale_out' : 'sale_adjust';
            query("INSERT INTO stock_movements (product_id,warehouse_id,change_qty,movement_type,reference_type,reference_id,note,performed_by,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())",
                  [$product_id,$warehouse_id,-$diff,'sale_edit','sale',$id,"Edit Penjualan #{$id}", $created_by]);
          }
        }
      } else {
        // different product/warehouse: restore old stock, deduct new stock
        // restore old
        $sb_old = query("SELECT id,quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? FOR UPDATE", [$sale['product_id'],$sale['warehouse_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$sb_old) throw new Exception('Baris stok lama tidak ditemukan.');
        query("UPDATE stock_balances SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?", [$old_qty, $sb_old['id']]);
        if (table_exists('stock_movements')) {
          query("INSERT INTO stock_movements (product_id,warehouse_id,change_qty,movement_type,reference_type,reference_id,note,performed_by,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())",
                [$sale['product_id'],$sale['warehouse_id'],$old_qty,'sale_revert','sale',$id,"Edit Penjualan #{$id} (revert)", $created_by]);
        }
        // deduct new
        $sb_new = query("SELECT id,quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? FOR UPDATE", [$product_id,$warehouse_id])->fetch(PDO::FETCH_ASSOC);
        if (!$sb_new) throw new Exception('Stok tujuan tidak ditemukan.');
        if ((float)$sb_new['quantity'] < $qty) throw new Exception('Stok tujuan tidak mencukupi.');
        query("UPDATE stock_balances SET quantity = quantity - ?, last_updated = NOW() WHERE id = ?", [$qty, $sb_new['id']]);
        if (table_exists('stock_movements')) {
          query("INSERT INTO stock_movements (product_id,warehouse_id,change_qty,movement_type,reference_type,reference_id,note,performed_by,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())",
                [$product_id,$warehouse_id,-$qty,'sale_out','sale',$id,"Edit Penjualan #{$id} (apply)", $created_by]);
        }
      }

      // update penjualan header
      $total = $qty * $harga;
      query("UPDATE penjualan SET tgl_penjualan=?, customer=?, product_id=?, warehouse_id=?, qty=?, harga_satuan=?, total_harga=?, payment_status=?, payment_method=?, created_by=? WHERE id = ?",
            [$tgl,$customer,$product_id,$warehouse_id,$qty,$harga,$total,$payment_status,$payment_method,$created_by,$id]);

      // update transaksi_keuangan simple (if exists)
      if (table_exists('transaksi_keuangan')) {
        query("UPDATE transaksi_keuangan SET tipe='pendapatan', tgl_transaksi=?, nominal=?, keterangan=? WHERE keterangan LIKE ?",
              [$tgl,$total,"Penjualan #{$id} ke {$customer}","%Penjualan #{$id}%"]);
      }

      commit();
      redirect('pages/penjualan/index.php?msg=Perubahan+disimpan');
      exit;
    } catch (Exception $e) {
      rollback();
      $err[] = 'Gagal menyimpan perubahan: '.e($e->getMessage());
    }
  }
}

include __DIR__ . '/../_partials/layout_start.php';
?>
<div class="card" style="max-width:860px">
  <h2>Edit Penjualan</h2>

  <?php if ($err): ?>
    <div class="alert error" style="background:#fff0f0;border:1px solid #f3c2c2;color:#7a1f1f;padding:10px;border-radius:10px;margin-bottom:10px">
      <?php foreach($err as $e) echo '<div>• '.e($e).'</div>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" oninput="recalcTotal()">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div><label>Tanggal</label><input type="date" name="tgl_penjualan" required value="<?= e($_POST['tgl_penjualan'] ?? $sale['tgl_penjualan']) ?>"></div>
      <div><label>Customer</label><input type="text" name="customer" required value="<?= e($_POST['customer'] ?? $sale['customer']) ?>"></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-top:12px">
      <div>
        <label>Produk</label>
        <select name="product_id" id="productSelect" required>
          <?php foreach($products as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id']==(int)($sale['product_id']))?'selected':'' ?>><?= e($p['name'].' '.($p['sku']? '('.$p['sku'].')':'')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Gudang</label>
        <select name="warehouse_id" id="warehouseSelect" required>
          <?php foreach($warehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= ((int)$w['id']==(int)($sale['warehouse_id']))?'selected':'' ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Qty</label>
        <input type="number" name="qty" id="qtyInput" min="1" step="1" required value="<?= e($_POST['qty'] ?? $sale['qty']) ?>">
        <div id="stockHint" class="muted" style="font-size:12px;margin-top:6px"></div>
      </div>

      <div>
        <label>Harga Satuan</label>
        <input type="number" name="harga_satuan" id="hargaInput" step="0.01" min="0" required value="<?= e($_POST['harga_satuan'] ?? $sale['harga_satuan']) ?>">
      </div>
    </div>

    <div style="margin-top:12px">
      <label>Status Pembayaran</label>
      <select name="payment_status">
        <option value="paid" <?= ($sale['payment_status']==='paid')?'selected':'' ?>>Paid</option>
        <option value="pending" <?= ($sale['payment_status']==='pending')?'selected':'' ?>>Pending</option>
        <option value="unpaid" <?= ($sale['payment_status']==='unpaid')?'selected':'' ?>>Unpaid</option>
      </select>

      <label style="margin-left:12px">Metode</label>
      <select name="payment_method">
        <option value="">(kosong)</option>
        <option value="cash" <?= ($sale['payment_method']==='cash')?'selected':'' ?>>Tunai</option>
        <option value="transfer" <?= ($sale['payment_method']==='transfer')?'selected':'' ?>>Transfer</option>
      </select>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn" type="submit">Simpan</button>
      <a class="btn outline" href="<?= asset_url('pages/penjualan/index.php') ?>">Batal</a>
    </div>
  </form>
</div>

<script>
  const stockMap = <?= json_encode($stockMap, JSON_HEX_TAG) ?>;
  function recalcTotal(){
    const pid = parseInt(document.getElementById('productSelect').value||'0',10);
    const wid = parseInt(document.getElementById('warehouseSelect').value||'0',10);
    const q = parseFloat(document.getElementById('qtyInput').value||'0');
    const h = parseFloat(document.getElementById('hargaInput').value||'0');
    const total = Math.round(q*h*100)/100;
    document.getElementById('stockHint').innerText = '';
    if (!pid || !wid) {
      document.getElementById('stockHint').innerText = 'Pilih produk & gudang untuk lihat stok';
      return;
    }
    const avail = (stockMap[pid] && stockMap[pid][wid] !== undefined) ? parseFloat(stockMap[pid][wid]) : 0;
    // Note: server-side stock already deducted by old sale. For simplicity show current available.
    document.getElementById('stockHint').innerText = 'Stok saat ini: ' + avail;
    if (q > avail + <?= (float)$sale['qty'] ?>) { // conservative check
      document.getElementById('stockHint').style.color='red';
      document.getElementById('qtyInput').style.borderColor='red';
    } else {
      document.getElementById('stockHint').style.color='';
      document.getElementById('qtyInput').style.borderColor='';
    }
  }
  document.getElementById('productSelect').addEventListener('change', recalcTotal);
  document.getElementById('warehouseSelect').addEventListener('change', recalcTotal);
  document.getElementById('qtyInput').addEventListener('input', recalcTotal);
  document.getElementById('hargaInput').addEventListener('input', recalcTotal);
  recalcTotal();
</script>

<?php include __DIR__ . '/../_partials/layout_end.php'; ?>
